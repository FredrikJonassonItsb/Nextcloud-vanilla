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
use OCA\AgentEngine\Integration\Client\DeckApiClient;
use OCA\AgentEngine\Protocol;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DBException;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * The takeover pipeline (INTERAKTIONSDESIGN §2.3, verbatim order):
 *
 *   1. PII firewall FIRST on the copy path (title + description + attachment
 *      names). Hit ⇒ refusal path: unassign the bot, human-readable ⇄ refusal
 *      comment on the origin card, notification — never a silent drop.
 *   2. Engine card in Agent Todo: verbatim title grammar, full 8-section
 *      template, ## Boundaries = the canonical BOUNDARIES_V1 constant,
 *      assignee = owner from the routing map, duedate copied, label
 *      agent-instructions.
 *   3. Origin card: label hos-agenten + the living ⇄ status comment.
 *   4. Presence check against the ledger heartbeat — honest staleness warning.
 *   5. Persist card_links row (the row is inserted FIRST — the unique
 *      open_key makes the insert the takeover mutex; one winner).
 *   6. HMAC push to the target agent's runner slot.
 *
 * reconcileCard() is the ONE invariant both the in-process listener and the
 * 2-min sweep drive: "bot assigned + enrolled board + no open link ⇒ take
 * over now; open link + bot no longer assigned ⇒ recall".
 */
class TakeoverService {
    public function __construct(
        private DeckApiClient $deck,
        private CardLinkMapper $linkMapper,
        private EnrolledBoardMapper $boardMapper,
        private EngineEventMapper $eventMapper,
        private PiiFirewall $firewall,
        private MirrorService $mirror,
        private RecallService $recall,
        private LedgerService $ledger,
        private NotificationService $notifications,
        private EngineConfig $config,
        private PushService $push,
        private IUserManager $userManager,
        private ITimeFactory $timeFactory,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Enforce the takeover invariant for one card. Idempotent per
     * (origin_card); safe to call from listener AND sweep for the same event.
     *
     * @param array<string,mixed> $card the Deck card payload (must carry id,
     *        title; assignedUsers/duedate/description used when present)
     * @param string $actorUid the acting user when known ('' from the sweep)
     */
    /**
     * Listener entrypoint: re-fetch the authoritative card before reconciling.
     * Deck's CardUpdatedEvent payload does NOT carry assignedUsers (it is a
     * lazy relation), so the in-process event card alone can never reveal an
     * assignment — the latency path must read the card fresh (getStacks embeds
     * assignedUsers, the same source the sweep trusts). Without this the
     * takeover only ever fires from the 2-min sweep. Idempotent per origin_card.
     */
    public function reconcileCardById(int $boardId, int $cardId, string $actorUid = ''): void {
        if ($boardId <= 0 || $cardId <= 0 || $boardId === $this->config->engineBoardId()) {
            return;
        }
        $located = $this->deck->findCard($boardId, $cardId);
        if ($located === null) {
            return; // not readable right now — the sweep replays the invariant
        }
        $this->reconcileCard($boardId, (int)$located['stackId'], $located['card'], $actorUid);
    }

    public function reconcileCard(int $boardId, int $stackId, array $card, string $actorUid = ''): void {
        $cardId = (int)($card['id'] ?? 0);
        if ($cardId <= 0 || $boardId === $this->config->engineBoardId()) {
            return; // engine board cards are never takeover subjects
        }

        $assignedBots = $this->assignedBots($card);
        $link = $this->linkMapper->findOpenByOriginCard($cardId);

        if ($link !== null) {
            // Recall detection: the linked bot is no longer assigned, or the
            // origin card was archived/done (§2.7 equivalent gestures).
            $archived = (bool)($card['archived'] ?? false);
            $doneAt = $card['done'] ?? null;
            if (!in_array($link->getBotUid(), $assignedBots, true) || $archived || !empty($doneAt)) {
                $this->recall->recall($link, $actorUid !== '' ? $actorUid : $link->getRequesterUid());
                return;
            }
            // Duedate copy origin → engine (pre-Done).
            $this->copyDuedate($link, $card);
            // Second bot assigned on top of an open link: first wins, unassign
            // the second + tell the assigner (§2.8).
            foreach ($assignedBots as $bot) {
                if ($bot !== $link->getBotUid()) {
                    try {
                        $this->deck->unassignUser($boardId, $stackId, $cardId, $bot);
                    } catch (\Throwable $e) {
                        $this->logger->warning('agent_engine: could not unassign second bot', [
                            'app' => 'agent_engine', 'cardId' => $cardId, 'exception' => $e->getMessage(),
                        ]);
                    }
                    if ($actorUid !== '' && !Protocol::isBot($actorUid)) {
                        $this->notifications->notify($actorUid, NotificationService::SUBJECT_NOT_ENROLLED, [
                            'agentCode' => $link->getAgentCode(),
                            'originBoard' => (string)$boardId,
                            'originCard' => (string)$cardId,
                        ], (string)$cardId);
                    }
                }
            }
            return;
        }

        if ($assignedBots === []) {
            return;
        }

        // Bot assigned, no open link → the takeover gate.
        if (!$this->boardMapper->isEnrolled($boardId)) {
            if ($actorUid !== '' && !Protocol::isBot($actorUid)
                && $this->eventMapper->claimKey('notenrolled:' . $boardId . ':' . $cardId, 'notify')) {
                $this->notifications->notify($actorUid, NotificationService::SUBJECT_NOT_ENROLLED, [
                    'agentCode' => Protocol::agentCodeForBot($assignedBots[0]) ?? '',
                    'originBoard' => (string)$boardId,
                    'originCard' => (string)$cardId,
                ], (string)$cardId);
            }
            return;
        }

        $this->takeover($boardId, $stackId, $card, $assignedBots[0], $actorUid);
    }

    /**
     * Run the §2.3 pipeline for one (card, bot).
     */
    public function takeover(int $boardId, int $stackId, array $card, string $botUid, string $actorUid): void {
        $cardId = (int)($card['id'] ?? 0);
        $agentCode = Protocol::agentCodeForBot($botUid);
        if ($agentCode === null) {
            $this->logger->warning('agent_engine: assigned bot has no agent code — skipping', [
                'app' => 'agent_engine', 'cardId' => $cardId, 'bot' => $botUid,
            ]);
            return;
        }
        $ownerUid = $this->config->ownerForAgentCode($agentCode) ?? '';
        $requesterUid = $actorUid !== '' && !Protocol::isBot($actorUid)
            ? $actorUid
            : $this->fallbackRequester($card);

        $title = (string)($card['title'] ?? '');
        $description = (string)($card['description'] ?? '');
        $attachmentNames = $this->deck->getAttachmentNames($boardId, $stackId, $cardId);

        // ---- Step 1: PII firewall FIRST — on the copy path. ---------------
        $hit = $this->firewall->scan(array_merge([$title, $description], $attachmentNames));
        if ($hit !== null) {
            $this->refuse($boardId, $stackId, $cardId, $botUid, $agentCode, $ownerUid, $requesterUid, $hit);
            return;
        }

        // ---- Step 5 first as MUTEX: insert the open link row. -------------
        $link = new CardLink();
        $link->setOriginBoard($boardId);
        $link->setOriginStack($stackId);
        $link->setOriginCard($cardId);
        $link->setEngineBoard($this->config->engineBoardId());
        $link->setAgentCode($agentCode);
        $link->setBotUid($botUid);
        $link->setOwnerUid($ownerUid);
        $link->setRequesterUid($requesterUid);
        $link->setReviewerUid($requesterUid); // GAP 2: requestern granskar
        $link->setPhase(Protocol::PHASE_TODO);
        try {
            $link = $this->linkMapper->insertOpen($link);
        } catch (DBException $e) {
            if ($e->getReason() === DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION
                || $e->getReason() === DBException::REASON_CONSTRAINT_VIOLATION) {
                return; // another takeover (listener vs sweep) won — fine
            }
            throw $e;
        }

        try {
            // ---- Step 2: engine card, full 8-section template. -------------
            $engineBoardId = $this->config->engineBoardId();
            $todoStackId = $this->deck->findStackIdByTitle($engineBoardId, Protocol::STACK_TODO);
            if ($engineBoardId <= 0 || $todoStackId === null) {
                throw new \RuntimeException('engine board/stack not resolvable');
            }
            $engineTitle = TitleGrammar::buildTask($agentCode, $title);
            $engineDescription = $this->buildTemplate($link, $title, $description, $card);
            $created = $this->deck->createCard(
                $engineBoardId,
                $todoStackId,
                $engineTitle,
                $engineDescription,
                isset($card['duedate']) && $card['duedate'] ? (string)$card['duedate'] : null,
            );
            $engineCardId = $created['cardId'];
            $link->setEngineCard($engineCardId);
            $this->linkMapper->save($link);

            // Label + owner assignment on the engine card (Nate: assignee = owner human).
            $labelId = $this->deck->resolveLabelId($engineBoardId, Protocol::LABEL_INSTRUCTIONS, Protocol::LABEL_INSTRUCTIONS_COLOR);
            if ($labelId !== null) {
                $this->deck->assignLabel($engineBoardId, $todoStackId, $engineCardId, $labelId);
            }
            if ($ownerUid !== '') {
                try {
                    $this->deck->assignUser($engineBoardId, $todoStackId, $engineCardId, $ownerUid);
                } catch (\Throwable $e) {
                    $this->logger->info('agent_engine: owner assign on engine card failed (uid unverified?)', [
                        'app' => 'agent_engine', 'linkId' => $link->getId(), 'exception' => $e->getMessage(),
                    ]);
                }
            }

            // ---- Step 3: origin card label + living status comment. --------
            $this->mirror->setOriginLabels($link, [Protocol::LABEL_HOS_AGENTEN], []);
            $this->mirror->statusEdit($link, $agentCode . ' har tagit uppgiften. Status och frågor kommer här.');

            // ---- Step 4: presence check against the ledger heartbeat. ------
            $this->presenceCheck($link);

            $this->eventMapper->claimKey('takeover:' . $boardId . ':' . $cardId . ':' . $link->getId(), 'takeover', (int)$link->getId(), [
                'agentCode' => $agentCode,
            ]);

            // ---- Step 6: HMAC push to the runner. ---------------------------
            $this->push->wake($agentCode);
        } catch (\Throwable $e) {
            // Compensation: never leave a half-takeover holding the unique
            // open_key — the next sweep must be able to retry cleanly.
            $this->logger->error('agent_engine: takeover failed — compensating link row', [
                'app' => 'agent_engine', 'cardId' => $cardId, 'exception' => $e->getMessage(),
            ]);
            $this->linkMapper->deleteById((int)$link->getId());
            throw new \RuntimeException('takeover failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /** The refusal path (§2.3 step 1) — unassign, ⇄ refusal, notify, audit. */
    private function refuse(
        int $boardId,
        int $stackId,
        int $cardId,
        string $botUid,
        string $agentCode,
        string $ownerUid,
        string $requesterUid,
        string $patternId,
    ): void {
        // Once per (card, content-generation): idempotency so sweep repeats
        // don't spam — the bot is unassigned so the invariant won't re-fire
        // unless a human re-assigns after editing.
        if (!$this->eventMapper->claimKey('refuse:' . $boardId . ':' . $cardId, 'refusal', 0, ['patternId' => $patternId])) {
            return;
        }
        try {
            $this->deck->unassignUser($boardId, $stackId, $cardId, $botUid);
        } catch (\Throwable $e) {
            $this->logger->warning('agent_engine: refusal unassign failed', [
                'app' => 'agent_engine', 'cardId' => $cardId, 'exception' => $e->getMessage(),
            ]);
        }
        try {
            $this->deck->postComment($cardId, Protocol::MIRROR_PREFIX . $this->firewall->refusalMessage());
        } catch (\Throwable $e) {
            $this->logger->warning('agent_engine: refusal comment failed', [
                'app' => 'agent_engine', 'cardId' => $cardId, 'exception' => $e->getMessage(),
            ]);
        }
        if ($requesterUid !== '' && !Protocol::isBot($requesterUid)) {
            $this->notifications->notify($requesterUid, NotificationService::SUBJECT_REFUSED, [
                'agentCode' => $agentCode,
                'originBoard' => (string)$boardId,
                'originCard' => (string)$cardId,
            ], (string)$cardId);
        }

        // Audit row with state='refused' (CONTRACTS §3 state enum) — open_key
        // stays NULL so a future clean re-assign can open a real link.
        $link = new CardLink();
        $link->setOriginBoard($boardId);
        $link->setOriginStack($stackId);
        $link->setOriginCard($cardId);
        $link->setEngineBoard($this->config->engineBoardId());
        $link->setAgentCode($agentCode);
        $link->setBotUid($botUid);
        $link->setOwnerUid($ownerUid);
        $link->setRequesterUid($requesterUid);
        $link->setReviewerUid($requesterUid);
        $link->setState(Protocol::STATE_REFUSED);
        $link->setOpenKey(null);
        $now = $this->timeFactory->getTime();
        $link->setCreatedAt($now);
        $link->setUpdatedAt($now);
        $this->linkMapper->insert($link);
    }

    /** §2.3 step 4 — honest presence warning in the takeover receipt itself. */
    private function presenceCheck(CardLink $link): void {
        try {
            $presence = $this->ledger->presence($link->getAgentCode());
        } catch (\Throwable) {
            return;
        }
        $staleAfter = $this->config->heartbeatStaleMinutes() * 60;
        $now = $this->timeFactory->getTime();
        $stale = $presence['paused']
            || $presence['heartbeat'] === null
            || ($now - $presence['heartbeat']) > $staleAfter;
        if (!$stale) {
            return;
        }
        $hours = $presence['heartbeat'] === null
            ? null
            : (int)round(($now - $presence['heartbeat']) / 3600);
        $warning = $presence['paused']
            ? 'Obs: agenten är pausad — kortet väntar. ' . $link->getOwnerUid() . ' har notifierats.'
            : ($hours === null
                ? 'Obs: agenten har aldrig rapporterat en körning — kortet väntar. ' . $link->getOwnerUid() . ' har notifierats.'
                : 'Obs: agenten har inte kört på ' . $hours . ' h — kortet väntar. ' . $link->getOwnerUid() . ' har notifierats.');
        $this->mirror->statusEdit($link, $link->getAgentCode() . ' har tagit uppgiften. ' . $warning);
        $this->notifications->notify($link->getOwnerUid(), NotificationService::SUBJECT_PRESENCE_STALE, [
            'agentCode' => $link->getAgentCode(),
            'originBoard' => (string)$link->getOriginBoard(),
            'originCard' => (string)$link->getOriginCard(),
        ], (string)$link->getOriginCard());
    }

    /**
     * The mechanically synthesized 8-section template (§2.3 step 2, verbatim
     * field semantics). Origin content is DATA (Context/Sources) — authority
     * comes only from the canonical Boundaries constant.
     */
    private function buildTemplate(CardLink $link, string $originTitle, string $originDescription, array $card): string {
        $requester = $link->getRequesterUid();
        $requesterName = $requester !== '' ? $this->displayName($requester) : 'okänd';
        $originUrl = '/apps/deck/board/' . $link->getOriginBoard() . '/card/' . $link->getOriginCard();

        $acceptance = 'Requester accepts via review (this card ends in Agent Review).';
        // If the origin description carries a markdown checklist, it doubles
        // as the acceptance criteria (data, not authority).
        if (preg_match('/^\s*[-*]\s*\[[ xX]?\]/m', $originDescription) === 1) {
            $acceptance = "See the checklist under ## Context (copied verbatim from the origin card).";
        }

        $sections = [
            '## Requester',
            $requesterName . ' (' . ($requester !== '' ? $requester : 'unknown') . ')',
            '',
            '## Desired outcome',
            $originTitle !== '' ? $originTitle : '(no title)',
            '',
            '## Context',
            $originDescription !== '' ? $originDescription : '(origin card has no description)',
            '',
            '## Sources',
            'Origin card: ' . $originUrl,
            '',
            '## Do',
            'Achieve the desired outcome. If the card does not contain enough to proceed, '
                . 'ask ONE specific question via AGENT BLOCKED — do not guess.',
            '',
            '## Acceptance criteria',
            $acceptance,
            '',
            '## Output & handoff',
            'Summarize on this card; the summary is mirrored to the origin card. '
                . 'Artifacts as attachments/files, linked. Reviewer: ' . $requesterName . '.',
            '',
            Protocol::BOUNDARIES_V1,
        ];
        return implode("\n", $sections);
    }

    /** Card assignees that are known bots. @return string[] */
    private function assignedBots(array $card): array {
        $bots = [];
        foreach ((array)($card['assignedUsers'] ?? []) as $assignee) {
            $uid = is_array($assignee)
                ? (string)($assignee['participant']['uid'] ?? ($assignee['uid'] ?? ''))
                : (string)$assignee;
            if ($uid !== '' && $uid !== Protocol::ENGINE_BOT && Protocol::isBot($uid)) {
                $bots[] = $uid;
            }
        }
        return array_values(array_unique($bots));
    }

    private function copyDuedate(CardLink $link, array $originCard): void {
        if ($link->getEngineCard() <= 0 || !array_key_exists('duedate', $originCard)) {
            return;
        }
        try {
            $engine = $this->deck->findCard($link->getEngineBoard(), $link->getEngineCard());
            if ($engine === null) {
                return;
            }
            $originDue = $originCard['duedate'] ? (string)$originCard['duedate'] : null;
            $engineDue = ($engine['card']['duedate'] ?? null) ? (string)$engine['card']['duedate'] : null;
            if ($originDue === $engineDue) {
                return;
            }
            $this->deck->updateCard($link->getEngineBoard(), $engine['stackId'], $link->getEngineCard(), [
                'title' => (string)($engine['card']['title'] ?? ''),
                'type' => 'plain',
                'owner' => (string)($engine['card']['owner']['uid'] ?? ($engine['card']['owner'] ?? Protocol::ENGINE_BOT)),
                'description' => (string)($engine['card']['description'] ?? ''),
                'duedate' => $originDue,
            ]);
        } catch (\Throwable $e) {
            $this->logger->info('agent_engine: duedate copy failed (cosmetic)', [
                'app' => 'agent_engine', 'linkId' => $link->getId(), 'exception' => $e->getMessage(),
            ]);
        }
    }

    private function displayName(string $uid): string {
        $user = $this->userManager->get($uid);
        return $user !== null ? $user->getDisplayName() : $uid;
    }

    /**
     * Requester when the acting user is unknown (sweep-driven takeover):
     * last human editor, else the card owner. Shape-tolerant across Deck
     * versions (owner may be a uid string or a user object).
     */
    private function fallbackRequester(array $card): string {
        foreach (['lastEditor', 'owner'] as $key) {
            $value = $card[$key] ?? null;
            if (is_string($value) && $value !== '' && !Protocol::isBot($value)) {
                return $value;
            }
            if (is_array($value)) {
                $uid = (string)($value['uid'] ?? ($value['primaryKey'] ?? ''));
                if ($uid !== '' && !Protocol::isBot($uid)) {
                    return $uid;
                }
            }
        }
        return '';
    }
}
