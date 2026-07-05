<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\BackgroundJob;

use OCA\AgentEngine\Db\CardLink;
use OCA\AgentEngine\Db\CardLinkMapper;
use OCA\AgentEngine\Db\EngineEventMapper;
use OCA\AgentEngine\Db\EnrolledBoardMapper;
use OCA\AgentEngine\Integration\Client\DeckApiClient;
use OCA\AgentEngine\Protocol;
use OCA\AgentEngine\Service\EngineConfig;
use OCA\AgentEngine\Service\MirrorService;
use OCA\AgentEngine\Service\NotificationService;
use OCA\AgentEngine\Service\TakeoverService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * The 2-minute reconciliation sweep — the CORRECTNESS FLOOR (INTERAKTIONSDESIGN
 * constraint 1): intake must be eventually-exact with ALL events off. Missed
 * events degrade to latency, never to a silently ignored card.
 *
 * Passes, in order:
 *  1. Enrolled boards (ETag-cheap): enforce the takeover invariant on every
 *     card — "bot assigned + enrolled + no open link ⇒ take over now;
 *     open link + bot unassigned/card archived ⇒ recall".
 *  2. Mirror catchup per live link: origin comments > origin_cursor are
 *     replayed through the same MirrorService path (idempotency keys make
 *     listener/sweep double delivery collapse).
 *  3. Engine-board reconciliation: release stale claim rows for cards back in
 *     Agent Todo (rework re-queue heal); detect the power-path approve
 *     (human dragged engine card to Agent Done while link is in review).
 *  4. Pre-claim stall detector (>4 h, once per link).
 */
class SweepJob extends TimedJob {
    public function __construct(
        ITimeFactory $time,
        private EnrolledBoardMapper $boardMapper,
        private CardLinkMapper $linkMapper,
        private EngineEventMapper $eventMapper,
        private DeckApiClient $deck,
        private TakeoverService $takeover,
        private MirrorService $mirror,
        private NotificationService $notifications,
        private EngineConfig $config,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(120);
        $this->setTimeSensitivity(self::TIME_SENSITIVE);
    }

    /**
     * @param mixed $argument unused
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    protected function run($argument): void {
        if (!$this->deck->isAvailable()) {
            return;
        }
        try {
            $this->sweepEnrolledBoards();
        } catch (\Throwable $e) {
            $this->logger->error('agent_engine: sweep pass 1 failed', ['app' => 'agent_engine', 'exception' => $e]);
        }
        try {
            $this->mirrorCatchup();
        } catch (\Throwable $e) {
            $this->logger->error('agent_engine: sweep pass 2 failed', ['app' => 'agent_engine', 'exception' => $e]);
        }
        try {
            $this->reconcileEngineBoard();
        } catch (\Throwable $e) {
            $this->logger->error('agent_engine: sweep pass 3 failed', ['app' => 'agent_engine', 'exception' => $e]);
        }
        try {
            $this->stallDetectors();
        } catch (\Throwable $e) {
            $this->logger->error('agent_engine: sweep pass 4 failed', ['app' => 'agent_engine', 'exception' => $e]);
        }
    }

    /** Pass 1 — takeover/recall invariant over every enrolled board (ETag-gated). */
    private function sweepEnrolledBoards(): void {
        foreach ($this->boardMapper->findAllEnabled() as $board) {
            $boardId = $board->getBoardId();
            $result = $this->deck->getStacks($boardId, $board->getEtag());
            if ($result === null) {
                continue; // unreachable board — next sweep retries
            }
            $liveLinks = $this->linkMapper->findLiveByOriginBoard($boardId);
            if ($result['notModified']) {
                // Nothing changed on the board; links may still need recall if
                // the card was DELETED (delete bumps the ETag, so this branch
                // means genuinely unchanged) — skip cheap.
                continue;
            }
            $seenCards = [];
            foreach ($result['stacks'] as $stack) {
                if (!is_array($stack)) {
                    continue;
                }
                $stackId = (int)($stack['id'] ?? 0);
                foreach ((array)($stack['cards'] ?? []) as $card) {
                    if (!is_array($card) || !isset($card['id'])) {
                        continue;
                    }
                    $seenCards[(int)$card['id']] = true;
                    try {
                        $this->takeover->reconcileCard($boardId, $stackId, $card);
                    } catch (\Throwable $e) {
                        $this->logger->warning('agent_engine: sweep reconcile failed for card', [
                            'app' => 'agent_engine', 'boardId' => $boardId,
                            'cardId' => (int)$card['id'], 'exception' => $e->getMessage(),
                        ]);
                    }
                }
            }
            // Origin card vanished (deleted/archived out of the stacks payload)
            // while a link is live ⇒ recall equivalent (§2.7 gesture list).
            foreach ($liveLinks as $link) {
                if (!isset($seenCards[$link->getOriginCard()])) {
                    try {
                        $this->takeover->reconcileCard($boardId, $link->getOriginStack(), [
                            'id' => $link->getOriginCard(),
                            'assignedUsers' => [],
                            'archived' => true,
                        ]);
                    } catch (\Throwable $e) {
                        $this->logger->warning('agent_engine: sweep recall-by-absence failed', [
                            'app' => 'agent_engine', 'linkId' => $link->getId(), 'exception' => $e->getMessage(),
                        ]);
                    }
                }
            }
            $this->boardMapper->saveEtag($board, $result['etag']);
        }
    }

    /** Pass 2 — origin→engine comment catchup for every live link. */
    private function mirrorCatchup(): void {
        foreach ($this->linkMapper->findAllLive() as $link) {
            $comments = $this->deck->getComments($link->getOriginCard(), 50, 0);
            if ($comments === []) {
                continue;
            }
            // Deck returns newest first — replay oldest-first above the cursor.
            $fresh = [];
            foreach ($comments as $comment) {
                if (!is_array($comment)) {
                    continue;
                }
                $id = (int)($comment['id'] ?? 0);
                if ($id > $link->getOriginCursor()) {
                    $fresh[] = $comment;
                }
            }
            usort($fresh, static fn (array $a, array $b): int => (int)$a['id'] <=> (int)$b['id']);
            foreach ($fresh as $comment) {
                try {
                    $this->mirror->onOriginComment($link, [
                        'id' => (int)$comment['id'],
                        'actorId' => (string)($comment['actorId'] ?? ''),
                        'message' => (string)($comment['message'] ?? ''),
                        'timestamp' => strtotime((string)($comment['creationDateTime'] ?? '')) ?: 0,
                    ]);
                } catch (\Throwable $e) {
                    $this->logger->warning('agent_engine: sweep mirror failed for comment', [
                        'app' => 'agent_engine', 'linkId' => $link->getId(),
                        'commentId' => (int)$comment['id'], 'exception' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /** Pass 3 — engine-board state heal: claim release + power-path verdicts. */
    private function reconcileEngineBoard(): void {
        $boardId = $this->config->engineBoardId();
        if ($boardId <= 0) {
            return;
        }
        $stacks = $this->deck->getStacks($boardId);
        if ($stacks === null) {
            return;
        }
        $cardStacks = [];
        foreach ($stacks['stacks'] as $stack) {
            if (!is_array($stack)) {
                continue;
            }
            foreach ((array)($stack['cards'] ?? []) as $card) {
                if (is_array($card) && isset($card['id'])) {
                    $cardStacks[(int)$card['id']] = (string)($stack['title'] ?? '');
                }
            }
        }

        // 3a. A card back in Agent Todo with a lingering claim row = a rework
        // re-queue or a manual human drag — release the mutex so it can be
        // re-claimed ("agenten kan inte agera på en tyst studs" → we heal).
        foreach ($cardStacks as $cardId => $stackTitle) {
            if ($stackTitle === Protocol::STACK_TODO
                && $this->eventMapper->findByKey('claim:' . $cardId) !== null) {
                $this->eventMapper->deleteKey('claim:' . $cardId);
                $this->logger->info('agent_engine: released stale claim row', [
                    'app' => 'agent_engine', 'cardId' => $cardId,
                ]);
            }
        }

        // 3b. Power-path approve: link in review but the engine card was
        // dragged to Agent Done by a human.
        foreach ($this->linkMapper->findAllLive() as $link) {
            if ($link->getState() !== Protocol::STATE_REVIEW) {
                continue;
            }
            $stackTitle = $cardStacks[$link->getEngineCard()] ?? null;
            if ($stackTitle === Protocol::STACK_DONE) {
                try {
                    $this->mirror->approve($link, $link->getReviewerUid());
                } catch (\Throwable $e) {
                    $this->logger->warning('agent_engine: power-path approve failed', [
                        'app' => 'agent_engine', 'linkId' => $link->getId(), 'exception' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /** Pass 4 — pre-claim stall (>4 h in Agent Todo), once per link (§2.8). */
    private function stallDetectors(): void {
        $threshold = $this->config->preClaimStallHours() * 3600;
        $now = $this->time->getTime();
        foreach ($this->linkMapper->findAllLive() as $link) {
            if ($link->getPhase() !== Protocol::PHASE_TODO || $link->getEngineCard() <= 0) {
                continue;
            }
            $age = $now - $link->getCreatedAt();
            if ($age < $threshold) {
                continue;
            }
            if (!$this->eventMapper->claimKey('stall:' . $link->getId(), 'notify', (int)$link->getId())) {
                continue; // already notified
            }
            $hours = (string)(int)round($age / 3600);
            foreach (array_unique([$link->getRequesterUid(), $link->getOwnerUid()]) as $uid) {
                if ($uid !== '' && !Protocol::isBot($uid)) {
                    $this->notifications->notify($uid, NotificationService::SUBJECT_PRECLAIM_STALL, [
                        'agentCode' => $link->getAgentCode(),
                        'hours' => $hours,
                        'originBoard' => (string)$link->getOriginBoard(),
                        'originCard' => (string)$link->getOriginCard(),
                    ], (string)$link->getOriginCard());
                }
            }
        }
    }
}
