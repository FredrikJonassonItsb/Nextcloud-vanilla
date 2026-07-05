<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Service;

use OCA\AgentEngine\Db\CardLink;
use OCA\AgentEngine\Db\CardLinkMapper;
use OCA\AgentEngine\Db\EngineEventMapper;
use OCA\AgentEngine\Db\EnrolledBoardMapper;
use OCA\AgentEngine\Protocol;
use OCA\AgentEngine\Integration\Client\DeckApiClient;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Two-way sync origin ⇄ engine (INTERAKTIONSDESIGN §2.4) — ALL mirror writes
 * go through this one module (constraint 4): truncation ≤900 + deep link,
 * ⇄ marker, attribution, idempotency key.
 *
 * Loop protection is STRUCTURAL (four independent brakes):
 *  1. Actor filter — events by bots/service uid are ignored (both boards).
 *  2. ⇄ marker — a comment starting with the marker is never re-mirrored.
 *  3. Link state machine — events on non-live links are logged, never acted on.
 *  4. Idempotency keys — (link, source comment id) collapses double delivery.
 *
 * Notismodell: state = LABELS on the card face, detail = ONE status comment
 * edited in place, action = NEW comments with @mention (max 3 classes:
 * ❓ question, ✅ done/review, 🔴 failed).
 */
class MirrorService {
    public const MAX_REWORK_CYCLES = 3;

    public function __construct(
        private DeckApiClient $deck,
        private CardLinkMapper $linkMapper,
        private EngineEventMapper $eventMapper,
        private EnrolledBoardMapper $boardMapper,
        private PiiFirewall $firewall,
        private NotificationService $notifications,
        private EngineConfig $config,
        private PushService $push,
        private IUserManager $userManager,
        private ITimeFactory $timeFactory,
        private LoggerInterface $logger,
    ) {
    }

    // ================================================================== //
    //  engine → origin (driven by receipts)
    // ================================================================== //

    /**
     * Mirror a receipt to the origin card per the §2.4 table.
     * Engine-internal tokens (APPLIED, SKILL-*, STATUS) are never mirrored.
     */
    public function onEngineReceipt(CardLink $link, string $token, string $text): void {
        if (!in_array($link->getState(), [Protocol::STATE_OPEN, Protocol::STATE_REVIEW], true)) {
            $this->logger->info('agent_engine: receipt on non-live link ignored', [
                'app' => 'agent_engine', 'linkId' => $link->getId(), 'state' => $link->getState(),
            ]);
            return;
        }

        switch ($token) {
            case 'AGENT CLAIMED':
                $link->setPhase(Protocol::PHASE_WORKING);
                $link->setClaimedAt($this->timeFactory->getTime());
                $this->linkMapper->save($link);
                $this->statusEdit($link, '🔵 Arbetar — startade ' . $this->clock());
                break;

            case 'AGENT BLOCKED':
                $link->setPhase(Protocol::PHASE_BLOCKED);
                $this->linkMapper->save($link);
                $this->setOriginLabels($link, [Protocol::LABEL_AGENT_FRAGA], []);
                $question = $text !== '' ? $text : 'Agenten behöver mer information.';
                $this->actionComment(
                    $link,
                    '❓ ' . $question . ' Svara i en kommentar här. @' . $link->getRequesterUid(),
                );
                $this->notifications->notify($link->getRequesterUid(), NotificationService::SUBJECT_QUESTION, [
                    'agentCode' => $link->getAgentCode(),
                    'originBoard' => (string)$link->getOriginBoard(),
                    'originCard' => (string)$link->getOriginCard(),
                ], (string)$link->getOriginCard());
                break;

            case 'AGENT HUMAN HOLD':
                // Pointer only — the content stays private per two-channel sharing.
                $link->setPhase(Protocol::PHASE_HOLD);
                $this->linkMapper->save($link);
                $this->statusEdit($link, '🟡 Väntar på ägarens godkännande (behörighetsfråga — hanteras i '
                    . $this->displayName($link->getOwnerUid()) . 's egen session)');
                break;

            case 'AGENT UNBLOCKED':
            case 'AGENT RESUMED':
            case 'AGENT HUMAN ANSWERED':
                $link->setPhase(Protocol::PHASE_WORKING);
                $this->linkMapper->save($link);
                $this->setOriginLabels($link, [], [Protocol::LABEL_AGENT_FRAGA]);
                $quote = $text !== '' ? ' — använde svaret: "' . $this->squeeze($text, 200) . '"' : '';
                $this->statusEdit($link, '🔵 Arbetar igen' . $quote);
                break;

            case 'AGENT DONE':
                $this->onDone($link, $text);
                break;

            case 'AGENT FAILED':
                $this->setOriginLabels($link, [Protocol::LABEL_AGENT_FRAGA], []);
                $this->actionComment(
                    $link,
                    '🔴 Misslyckades: ' . ($text !== '' ? $text : '(inga detaljer)') . '. '
                        . $this->displayName($link->getOwnerUid()) . ' tittar på det. @' . $link->getRequesterUid(),
                );
                foreach (array_unique([$link->getOwnerUid(), $link->getRequesterUid()]) as $uid) {
                    $this->notifications->notify($uid, NotificationService::SUBJECT_FAILED, [
                        'agentCode' => $link->getAgentCode(),
                        'originBoard' => (string)$link->getOriginBoard(),
                        'originCard' => (string)$link->getOriginCard(),
                    ], (string)$link->getOriginCard());
                }
                break;

            case 'AGENT FOLLOW-UP':
                if ($text !== '') {
                    $this->statusEdit($link, '📎 Uppföljning: ' . $this->squeeze($text, 300));
                }
                break;

            default:
                // AGENT APPLIED / AGENT SKILL * / AGENT STATUS — engine-internal
                // config noise, NEVER mirrored (§2.4 table).
                break;
        }
    }

    /** AGENT DONE → Agent Review (or completion-wins finish under recall). */
    private function onDone(CardLink $link, string $text): void {
        if ($link->getRecallRequested() === 1) {
            // Completion beats recall (§2.7): the result stands, the mirror says
            // so honestly, no review round — the human keeps or discards.
            $this->moveEngineCard($link, Protocol::STACK_DONE);
            $this->setOriginLabels($link, [Protocol::LABEL_AGENT_KLAR],
                [Protocol::LABEL_HOS_AGENTEN, Protocol::LABEL_AGENT_FRAGA]);
            $this->unassignBotOnOrigin($link);
            $this->statusEdit($link, '✅ Hann klart före återkallandet — resultatet är bevarat. '
                . ($text !== '' ? $this->squeeze($text, 300) : ''));
            $this->linkMapper->transition($link, Protocol::STATE_DONE, Protocol::PHASE_REVIEW);
            $this->releaseClaim($link->getEngineCard());
            return;
        }

        $link->setPhase(Protocol::PHASE_REVIEW);
        $this->linkMapper->transition($link, Protocol::STATE_REVIEW, Protocol::PHASE_REVIEW);
        $this->setOriginLabels($link, [Protocol::LABEL_AGENT_FRAGA], []);
        $summary = $text !== '' ? $this->squeeze($text, 700) : 'Se engine-kortet för resultatet.';
        $this->actionComment(
            $link,
            '✅ Klart för din granskning: ' . $summary
                . ' Svara **ok** för att godkänna, eller skriv vad som ska ändras. @' . $link->getReviewerUid(),
        );
        $this->notifications->notify($link->getReviewerUid(), NotificationService::SUBJECT_REVIEW_READY, [
            'agentCode' => $link->getAgentCode(),
            'originBoard' => (string)$link->getOriginBoard(),
            'originCard' => (string)$link->getOriginCard(),
        ], (string)$link->getOriginCard());
    }

    // ================================================================== //
    //  origin → engine (human comments; verdicts in review)
    // ================================================================== //

    /**
     * A comment appeared on a linked origin card.
     *
     * @param array{id:int,actorId:string,message:string,timestamp:int} $comment
     */
    public function onOriginComment(CardLink $link, array $comment): void {
        $actor = $comment['actorId'];
        $message = trim($comment['message']);
        $commentId = $comment['id'];

        // Brake 1 — actor filter: bot/service-authored comments never mirror.
        if (Protocol::isBot($actor)) {
            return;
        }
        // Brake 2 — ⇄ marker: engine-origin content never re-mirrors.
        if (str_starts_with($message, trim(Protocol::MIRROR_PREFIX))) {
            return;
        }
        // Brake 3 — link state machine.
        if (!in_array($link->getState(), [Protocol::STATE_OPEN, Protocol::STATE_REVIEW], true)) {
            $this->logger->info('agent_engine: origin comment on non-live link ignored', [
                'app' => 'agent_engine', 'linkId' => $link->getId(), 'state' => $link->getState(),
            ]);
            return;
        }
        // Brake 4 — idempotency (listener vs sweep double delivery).
        if (!$this->eventMapper->claimKey(
            'm:o2e:' . $link->getId() . ':' . $commentId,
            'mirror',
            (int)$link->getId(),
        )) {
            return;
        }
        if ($commentId > $link->getOriginCursor()) {
            $link->setOriginCursor($commentId);
            $this->linkMapper->save($link);
        }

        // Review verdict parsing (§2.6) — conservative, reviewer-scoped.
        if ($link->getState() === Protocol::STATE_REVIEW && $actor === $link->getReviewerUid()) {
            if ($this->isApproval($message)) {
                $this->approve($link, $actor);
                return;
            }
            $this->rework($link, $message, $actor);
            return;
        }

        // Plain mirror to the engine card — firewall on the copy path FIRST.
        $hit = $this->firewall->scan([$message]);
        if ($hit !== null) {
            $this->writeOriginComment($link, $this->firewall->commentRefusalMessage());
            return;
        }
        $when = $this->clock($comment['timestamp'] ?? null);
        $mirrored = Protocol::MIRROR_PREFIX . 'Från ' . $this->displayName($actor)
            . ' (ursprungskortet, ' . $when . '): "' . $message . '"';
        $this->deck->postComment($link->getEngineCard(), $this->clip($mirrored, $link, forOrigin: false));
    }

    /** Conservative approval parser: exact verb start, ≤80 chars (§2.6). */
    public function isApproval(string $message): bool {
        $m = mb_strtolower(trim($message));
        if (mb_strlen($m) > 80) {
            return false;
        }
        return preg_match('/^(ok|godkänn|godkänt)\b/u', $m) === 1;
    }

    /** Approve: engine card → Agent Done, origin flips to agent-klar. */
    public function approve(CardLink $link, string $byUid): void {
        $this->moveEngineCard($link, Protocol::STACK_DONE);
        $this->setOriginLabels($link, [Protocol::LABEL_AGENT_KLAR],
            [Protocol::LABEL_HOS_AGENTEN, Protocol::LABEL_AGENT_FRAGA]);
        $this->unassignBotOnOrigin($link);
        $this->statusEdit($link, '✅ Klar — godkänd av ' . $this->displayName($byUid) . ' ' . $this->clock());
        $this->linkMapper->transition($link, Protocol::STATE_DONE, Protocol::PHASE_REVIEW);
        $this->releaseClaim($link->getEngineCard());
        $this->eventMapper->claimKey(
            'approve:' . $link->getId(),
            'audit',
            (int)$link->getId(),
            ['by' => $byUid],
        );
        $this->applyOnDone($link);
    }

    /** Rework: feedback → engine card, Review → Agent Todo, max 3 cycles. */
    public function rework(CardLink $link, string $feedback, string $byUid): void {
        $cycle = $link->getReworkCycles() + 1;
        if ($cycle > self::MAX_REWORK_CYCLES) {
            // Parked in Review — "ta det interaktivt" (GAP 2 resolution).
            $this->writeOriginComment($link, 'Kortet har snurrat ' . self::MAX_REWORK_CYCLES
                . ' varv — ta det i din interaktiva session i stället. @' . $link->getOwnerUid());
            return;
        }
        $link->setReworkCycles($cycle);
        $this->linkMapper->save($link);

        $hit = $this->firewall->scan([$feedback]);
        if ($hit !== null) {
            $this->writeOriginComment($link, $this->firewall->commentRefusalMessage());
            return;
        }
        $this->deck->postComment(
            $link->getEngineCard(),
            $this->clip(Protocol::MIRROR_PREFIX . 'REWORK (cykel ' . $cycle . '/' . self::MAX_REWORK_CYCLES
                . ') — från ' . $this->displayName($byUid) . ': "' . $feedback . '"', $link, forOrigin: false),
        );
        $this->moveEngineCard($link, Protocol::STACK_TODO);
        $link->setPhase(Protocol::PHASE_TODO);
        $this->linkMapper->transition($link, Protocol::STATE_OPEN, Protocol::PHASE_TODO);
        $this->releaseClaim($link->getEngineCard());
        $this->statusEdit($link, '🔁 Skickad tillbaka för omarbetning (cykel ' . $cycle . '/'
            . self::MAX_REWORK_CYCLES . ')');
        $this->push->wake($link->getAgentCode());
    }

    // ================================================================== //
    //  The shared origin write module (constraint 4) + helpers
    // ================================================================== //

    /**
     * Edit the living ⇄ status comment on the origin card in place (detail
     * channel — no notification by design; state = labels, action = new
     * comments). Creates it if missing.
     */
    public function statusEdit(CardLink $link, string $line): void {
        $engineLink = $this->engineCardUrl($link);
        $body = Protocol::MIRROR_PREFIX . 'AE-' . $link->getEngineCard() . ' · ' . $link->getAgentCode()
            . " — " . $line . "\n"
            . 'Körs på Agent Engine-tavlan: ' . $engineLink
            . '. Ta bort boten som tilldelad om du vill ta tillbaka uppgiften.';
        $body = $this->clip($body, $link, forOrigin: true);
        try {
            if ($link->getStatusCommentId() > 0) {
                $this->deck->updateComment($link->getOriginCard(), $link->getStatusCommentId(), $body);
                return;
            }
        } catch (\Throwable $e) {
            $this->logger->info('agent_engine: status comment edit failed — recreating', [
                'app' => 'agent_engine', 'linkId' => $link->getId(), 'exception' => $e->getMessage(),
            ]);
        }
        $id = $this->deck->postComment($link->getOriginCard(), $body);
        $link->setStatusCommentId($id);
        $this->linkMapper->save($link);
    }

    /** New @mention action comment on the origin card (❓ ✅ 🔴 classes only). */
    public function actionComment(CardLink $link, string $text): int {
        return $this->writeOriginComment($link, $text);
    }

    /** One-off ⇄ comment on the origin card (refusals, parking, action classes). */
    public function writeOriginComment(CardLink $link, string $text): int {
        $body = $this->clip(Protocol::MIRROR_PREFIX . $text, $link, forOrigin: true);
        return $this->deck->postComment($link->getOriginCard(), $body);
    }

    /** Origin label state machine writes (resolve-or-create = self-healing). */
    public function setOriginLabels(CardLink $link, array $add, array $remove): void {
        $located = $this->deck->findCard($link->getOriginBoard(), $link->getOriginCard());
        if ($located === null) {
            return;
        }
        $stackId = $located['stackId'];
        $colors = [
            Protocol::LABEL_HOS_AGENTEN => Protocol::LABEL_HOS_AGENTEN_COLOR,
            Protocol::LABEL_AGENT_FRAGA => Protocol::LABEL_AGENT_FRAGA_COLOR,
            Protocol::LABEL_AGENT_KLAR => Protocol::LABEL_AGENT_KLAR_COLOR,
        ];
        $current = [];
        foreach ((array)($located['card']['labels'] ?? []) as $label) {
            if (is_array($label) && isset($label['title'], $label['id'])) {
                $current[(string)$label['title']] = (int)$label['id'];
            }
        }
        foreach ($add as $title) {
            if (isset($current[$title])) {
                continue;
            }
            $labelId = $this->deck->resolveLabelId($link->getOriginBoard(), $title, $colors[$title] ?? '0082c9');
            if ($labelId !== null) {
                try {
                    $this->deck->assignLabel($link->getOriginBoard(), $stackId, $link->getOriginCard(), $labelId);
                } catch (\Throwable $e) {
                    $this->logger->warning('agent_engine: assignLabel failed', [
                        'app' => 'agent_engine', 'linkId' => $link->getId(), 'exception' => $e->getMessage(),
                    ]);
                }
            }
        }
        foreach ($remove as $title) {
            if (isset($current[$title])) {
                $this->deck->removeLabel($link->getOriginBoard(), $stackId, $link->getOriginCard(), $current[$title]);
            }
        }
    }

    /** Move the engine card to a named stack. */
    public function moveEngineCard(CardLink $link, string $stackTitle): void {
        $located = $this->deck->findCard($link->getEngineBoard(), $link->getEngineCard());
        $target = $this->deck->findStackIdByTitle($link->getEngineBoard(), $stackTitle);
        if ($located === null || $target === null) {
            $this->logger->warning('agent_engine: moveEngineCard could not resolve card/stack', [
                'app' => 'agent_engine', 'linkId' => $link->getId(), 'stack' => $stackTitle,
            ]);
            return;
        }
        if ($located['stackId'] !== $target) {
            $this->deck->moveCard($link->getEngineBoard(), $located['stackId'], $link->getEngineCard(), $target);
        }
    }

    public function unassignBotOnOrigin(CardLink $link): void {
        $located = $this->deck->findCard($link->getOriginBoard(), $link->getOriginCard());
        if ($located === null) {
            return;
        }
        $assigned = false;
        foreach ((array)($located['card']['assignedUsers'] ?? []) as $assignee) {
            $uid = is_array($assignee)
                ? (string)($assignee['participant']['uid'] ?? ($assignee['uid'] ?? ''))
                : (string)$assignee;
            if ($uid === $link->getBotUid()) {
                $assigned = true;
                break;
            }
        }
        if ($assigned) {
            try {
                $this->deck->unassignUser($link->getOriginBoard(), $located['stackId'], $link->getOriginCard(), $link->getBotUid());
            } catch (\Throwable $e) {
                $this->logger->warning('agent_engine: unassign bot failed', [
                    'app' => 'agent_engine', 'linkId' => $link->getId(), 'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    /** Release the claim mutex row so the card can be re-claimed (rework/recall). */
    public function releaseClaim(int $engineCardId): void {
        $this->eventMapper->deleteKey('claim:' . $engineCardId);
    }

    /**
     * Per-board on_done action (§2.4 "Godkänd" row): comment_only = no-op
     * (default), move_to_stack:<id> = move the ORIGIN card to the board's
     * configured "Klart" column. Cosmetic — failures are swallowed.
     */
    private function applyOnDone(CardLink $link): void {
        try {
            $board = $this->boardMapper->findByBoardId($link->getOriginBoard());
            $onDone = $board?->getOnDone() ?? 'comment_only';
            if (!str_starts_with($onDone, 'move_to_stack:')) {
                return;
            }
            $target = (int)substr($onDone, strlen('move_to_stack:'));
            $located = $this->deck->findCard($link->getOriginBoard(), $link->getOriginCard());
            if ($target > 0 && $located !== null && $located['stackId'] !== $target) {
                $this->deck->moveCard($link->getOriginBoard(), $located['stackId'], $link->getOriginCard(), $target);
            }
        } catch (\Throwable $e) {
            $this->logger->info('agent_engine: on_done action failed (cosmetic)', [
                'app' => 'agent_engine', 'linkId' => $link->getId(), 'exception' => $e->getMessage(),
            ]);
        }
    }

    // ------------------------------------------------------------------ //

    /**
     * The single truncation/marking gate: ⇄ enforced upstream, ≤900 chars,
     * deep link appended when truncated (constraint 4).
     */
    private function clip(string $text, CardLink $link, bool $forOrigin): string {
        $text = trim($text);
        if (mb_strlen($text) <= Protocol::COMMENT_MAX) {
            return $text;
        }
        $suffix = ' … ' . ($forOrigin ? $this->engineCardUrl($link) : $this->originCardUrl($link));
        $budget = Protocol::COMMENT_MAX - mb_strlen($suffix);
        return mb_substr($text, 0, max(1, $budget)) . $suffix;
    }

    private function engineCardUrl(CardLink $link): string {
        return '/apps/deck/board/' . $link->getEngineBoard() . '/card/' . $link->getEngineCard();
    }

    private function originCardUrl(CardLink $link): string {
        return '/apps/deck/board/' . $link->getOriginBoard() . '/card/' . $link->getOriginCard();
    }

    private function displayName(string $uid): string {
        $user = $this->userManager->get($uid);
        return $user !== null ? $user->getDisplayName() : $uid;
    }

    private function clock(?int $ts = null): string {
        $dt = new \DateTime('@' . ($ts ?? $this->timeFactory->getTime()));
        $dt->setTimezone(new \DateTimeZone('Europe/Stockholm'));
        return $dt->format('H:i');
    }

    private function squeeze(string $text, int $max): string {
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? '';
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }
}
