<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Service;

use OCA\AgentEngine\Db\EnrolledBoardMapper;
use OCA\AgentEngine\Integration\Client\DeckApiClient;
use OCA\AgentEngine\Protocol;
use Psr\Log\LoggerInterface;

/**
 * Self-service board enrollment (INTERAKTIONSDESIGN §2.10). Reproduces the
 * enroll-board.mjs recipe server-side so a human can activate agents on their
 * own Deck board simply by SHARING it with the "Agent Engine" account
 * (bot-engine) — the DeckAclListener turns that share into autoEnroll(), and
 * un-sharing into autoUnenroll().
 *
 * The recipe (idempotent, all as bot-engine via DeckApiClient):
 *   1. bot-engine gets edit + MANAGE on the board (so the engine can operate
 *      it and grant the agent bots access);
 *   2. every agent bot (bot-reb/atlas/ada/marvin) gets edit;
 *   3. the 3 origin labels (hos-agenten / agent-fråga / agent-klar) are
 *      resolve-or-created;
 *   4. the enrollment row is upserted enabled=true, enrolledBy=$byUid.
 *
 * DEFENSIVE by contract: this runs from the Deck event dispatcher, so it MUST
 * NEVER throw — every failure is logged and swallowed. A partial enrollment is
 * safe: the 2-min sweep only acts on boards whose row is enabled, and re-running
 * (via the sweep-visible row or a repeated share) heals any half-done step.
 */
class EnrollmentService {
    /** The 3 origin-board state-machine labels (Protocol / CONTRACTS §2). */
    private const ORIGIN_LABELS = [
        [Protocol::LABEL_HOS_AGENTEN, Protocol::LABEL_HOS_AGENTEN_COLOR],
        [Protocol::LABEL_AGENT_FRAGA, Protocol::LABEL_AGENT_FRAGA_COLOR],
        [Protocol::LABEL_AGENT_KLAR, Protocol::LABEL_AGENT_KLAR_COLOR],
    ];

    public function __construct(
        private DeckApiClient $deck,
        private EnrolledBoardMapper $boardMapper,
        private EngineConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Idempotently ensure the engine can operate on $boardId, then register the
     * enrollment. Safe to call repeatedly. Never throws.
     */
    public function autoEnroll(int $boardId, string $byUid): void {
        try {
            if ($boardId <= 0) {
                return;
            }
            // NEVER enroll the engine board itself — it is the engine's own
            // workspace, not a human origin board (mirrors AdminController).
            if ($boardId === $this->config->engineBoardId()) {
                $this->logger->info('agent_engine: refusing to enroll the engine board', [
                    'app' => Protocol::ENGINE_BOT,
                    'boardId' => $boardId,
                ]);
                return;
            }

            // 1) bot-engine: edit + MANAGE (so it can operate + re-share the board).
            $this->deck->shareBoardAcl($boardId, Protocol::ENGINE_BOT, edit: true, share: true, manage: true);

            // 2) every agent bot: edit (receipts / mirroring on their cards).
            foreach (array_keys(Protocol::IDENTITIES) as $botUid) {
                $this->deck->shareBoardAcl($boardId, $botUid, edit: true);
            }

            // 3) origin labels — resolve-or-create (self-healing).
            foreach (self::ORIGIN_LABELS as [$title, $color]) {
                $this->deck->resolveLabelId($boardId, $title, $color);
            }

            // 4) register / re-enable the enrollment row. pii_reviewed_by is left
            // to the human enrollment policy (§2.11) — self-service records who
            // shared the board as the enroller.
            $this->boardMapper->upsert(
                $boardId,
                true,
                'comment_only',
                false,
                '',
                $byUid,
            );

            $this->logger->info('agent_engine: board auto-enrolled via Deck share', [
                'app' => Protocol::ENGINE_BOT,
                'boardId' => $boardId,
                'by' => $byUid,
            ]);
        } catch (\Throwable $e) {
            // The Deck action that triggered us must never break — the sweep and
            // a repeated share both heal a partial enrollment.
            $this->logger->warning('agent_engine: auto-enroll failed (share again to retry)', [
                'app' => Protocol::ENGINE_BOT,
                'boardId' => $boardId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Disable the enrollment (non-destructive): the takeover gate closes but the
     * row, labels and ACLs are left in place so history and any in-flight links
     * survive. Never throws.
     */
    public function autoUnenroll(int $boardId): void {
        try {
            if ($boardId <= 0) {
                return;
            }
            $existing = $this->boardMapper->findByBoardId($boardId);
            if ($existing === null) {
                return; // never enrolled — nothing to disable
            }
            $this->boardMapper->upsert(
                $boardId,
                false,
                $existing->getOnDone(),
                $existing->getConservative() === 1,
                $existing->getPiiReviewedBy(),
                $existing->getEnrolledBy(),
            );
            $this->logger->info('agent_engine: board auto-unenrolled (disabled) via Deck unshare', [
                'app' => Protocol::ENGINE_BOT,
                'boardId' => $boardId,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('agent_engine: auto-unenroll failed', [
                'app' => Protocol::ENGINE_BOT,
                'boardId' => $boardId,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
