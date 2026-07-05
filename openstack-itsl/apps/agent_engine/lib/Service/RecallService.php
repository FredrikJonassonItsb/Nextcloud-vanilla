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
use OCA\AgentEngine\Integration\Client\DeckApiClient;
use OCA\AgentEngine\Protocol;
use Psr\Log\LoggerInterface;

/**
 * Recall — symmetric and non-destructive (INTERAKTIONSDESIGN §2.7).
 *
 * Gesture: the human unassigns the bot (equivalents: archive/delete/mark-done
 * on the origin card — all detected by TakeoverService::reconcileCard).
 *
 * Per engine-card state:
 *  - not claimed (phase todo):   archive engine card, clear labels, immediate.
 *  - claimed (phase working):    COOPERATIVE cancel — recall flag + RECALL
 *                                REQUESTED comment; the runner checks the flag
 *                                at checkpoints. Completion beats recall.
 *  - blocked/hold/review:        nothing is running — close immediately.
 *
 * Receipts are never rewritten; partial output is always preserved.
 */
class RecallService {
    public function __construct(
        private DeckApiClient $deck,
        private CardLinkMapper $linkMapper,
        private EngineEventMapper $eventMapper,
        private MirrorService $mirror,
        private NotificationService $notifications,
        private LoggerInterface $logger,
    ) {
    }

    public function recall(CardLink $link, string $byUid): void {
        if (!in_array($link->getState(), [Protocol::STATE_OPEN, Protocol::STATE_REVIEW], true)) {
            return; // already closed — idempotent
        }
        // Idempotency across listener/sweep double delivery.
        if (!$this->eventMapper->claimKey('recall:' . $link->getId(), 'recall', (int)$link->getId(), ['by' => $byUid])) {
            return;
        }

        if ($link->getPhase() === Protocol::PHASE_WORKING) {
            // Cooperative cancel — NEVER kill a live run.
            $link->setRecallRequested(1);
            $this->linkMapper->save($link);
            try {
                $this->deck->postComment(
                    $link->getEngineCard(),
                    'RECALL REQUESTED by ' . ($byUid !== '' ? $byUid : 'human'),
                );
            } catch (\Throwable $e) {
                $this->logger->warning('agent_engine: recall flag comment failed', [
                    'app' => 'agent_engine', 'linkId' => $link->getId(), 'exception' => $e->getMessage(),
                ]);
            }
            $this->mirror->statusEdit($link, '⏹ Återkallande begärt — agenten stoppar vid nästa kontrollpunkt. '
                . 'Hinner körningen klart först vinner resultatet.');
            $this->notifyRecall($link, $byUid);
            return;
        }

        // todo / blocked / hold / review: nothing runs — close now.
        $this->closeNow($link, $byUid);
    }

    /** Immediate close: archive engine card, clear origin labels, state=recalled. */
    private function closeNow(CardLink $link, string $byUid): void {
        if ($link->getEngineCard() > 0) {
            try {
                $located = $this->deck->findCard($link->getEngineBoard(), $link->getEngineCard());
                if ($located !== null) {
                    // Traceable, never destructive: note WHO recalled, then archive.
                    $this->deck->postComment(
                        $link->getEngineCard(),
                        'Recalled by ' . ($byUid !== '' ? $byUid : 'human')
                            . ($link->getPhase() === Protocol::PHASE_TODO ? ' before claim' : ''),
                    );
                    $this->deck->archiveCard($link->getEngineBoard(), $located['stackId'], $link->getEngineCard());
                }
            } catch (\Throwable $e) {
                $this->logger->warning('agent_engine: engine card archive failed during recall', [
                    'app' => 'agent_engine', 'linkId' => $link->getId(), 'exception' => $e->getMessage(),
                ]);
            }
        }
        $this->mirror->setOriginLabels($link, [], [
            Protocol::LABEL_HOS_AGENTEN,
            Protocol::LABEL_AGENT_FRAGA,
            Protocol::LABEL_AGENT_KLAR,
        ]);
        $this->mirror->statusEdit($link, '⏹ Tillbakadragen — agenten rör den inte. '
            . 'Tilldela boten igen om du vill starta om (ny takeover).');
        $this->linkMapper->transition($link, Protocol::STATE_RECALLED);
        $this->mirror->releaseClaim($link->getEngineCard());
        $this->notifyRecall($link, $byUid);
    }

    private function notifyRecall(CardLink $link, string $byUid): void {
        if ($byUid !== '' && !Protocol::isBot($byUid)) {
            $this->notifications->notify($byUid, NotificationService::SUBJECT_RECALLED, [
                'agentCode' => $link->getAgentCode(),
                'originBoard' => (string)$link->getOriginBoard(),
                'originCard' => (string)$link->getOriginCard(),
            ], (string)$link->getOriginCard());
        }
    }
}
