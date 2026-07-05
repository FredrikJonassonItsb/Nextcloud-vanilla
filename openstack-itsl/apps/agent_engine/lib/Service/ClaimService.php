<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Service;

use OCA\AgentEngine\Db\CardLinkMapper;
use OCA\AgentEngine\Db\EngineEventMapper;
use OCA\AgentEngine\Exception\ClaimConflictException;
use OCA\AgentEngine\Exception\NotEligibleException;
use OCA\AgentEngine\Integration\Client\DeckApiClient;
use OCA\AgentEngine\Protocol;
use OCP\DB\Exception as DBException;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Atomic claim (CONTRACTS §3): verify stack=Agent Todo + label + title code =
 * the caller's agent code → move to Agent Working → post AGENT CLAIMED →
 * 200 {cardId, reread}. Otherwise 409 {claimedBy} / 422.
 *
 * MUTEX: the claim takes its own row — event_key 'claim:<engineCardId>' in
 * oc_agent_engine_events — inside ONE transaction, and holds the row lock
 * across the Deck ops. The unique index makes the INSERT the lock
 * acquisition: this is Nextcloud's documented equivalent of
 * SELECT … FOR UPDATE on a dedicated claim row (OCP\IDBConnection deprecated
 * insertIfNotExist in favour of exactly this pattern; deadlock-free). Two
 * concurrent claims ⇒ exactly one winner; the loser's insert collides on the
 * unique key and is answered 409 {claimedBy}. The row is released (deleted)
 * only when the card legitimately returns to Agent Todo (rework/recall/sweep).
 */
class ClaimService {
    public function __construct(
        private IDBConnection $db,
        private DeckApiClient $deck,
        private EngineEventMapper $eventMapper,
        private CardLinkMapper $linkMapper,
        private EngineConfig $config,
        private MirrorService $mirror,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{cardId:int,reread:array<string,mixed>}
     * @throws ClaimConflictException 409 {claimedBy}
     * @throws NotEligibleException   422 (wrong stack/label/title/agent)
     */
    public function claim(int $engineCardId, string $agentCode): array {
        $boardId = $this->config->engineBoardId();
        if ($boardId <= 0) {
            throw new NotEligibleException('engine_board_id not configured');
        }

        // ---- Eligibility (protocol preconditions, CONTRACTS §3) -----------
        $located = $this->deck->findCard($boardId, $engineCardId);
        if ($located === null) {
            throw new NotEligibleException('card not found on engine board');
        }
        if ($located['stackTitle'] !== Protocol::STACK_TODO) {
            throw new NotEligibleException('card is not in Agent Todo');
        }
        $hasLabel = false;
        foreach ((array)($located['card']['labels'] ?? []) as $label) {
            if (is_array($label) && (string)($label['title'] ?? '') === Protocol::LABEL_INSTRUCTIONS) {
                $hasLabel = true;
                break;
            }
        }
        if (!$hasLabel) {
            throw new NotEligibleException('card lacks the agent-instructions label');
        }
        $parsed = TitleGrammar::parse((string)($located['card']['title'] ?? ''));
        if (!TitleGrammar::isTaskFor($parsed, $agentCode)) {
            throw new NotEligibleException('title grammar does not address this agent');
        }

        // ---- The mutex + Deck ops in ONE transaction -----------------------
        $this->db->beginTransaction();
        try {
            try {
                // Lock acquisition: unique-key insert = FOR UPDATE-equivalent
                // on the claim's own row; held until commit/rollback.
                $this->eventMapper->insertKey('claim:' . $engineCardId, 'claim', 0, [
                    'agentCode' => $agentCode,
                ]);
            } catch (DBException $e) {
                if ($e->getReason() === DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION
                    || $e->getReason() === DBException::REASON_CONSTRAINT_VIOLATION) {
                    $this->db->rollBack();
                    throw new ClaimConflictException($this->claimedBy($engineCardId));
                }
                throw $e;
            }

            // Winner: Deck ops while holding the claim row.
            $working = $this->deck->findStackIdByTitle($boardId, Protocol::STACK_WORKING);
            if ($working === null) {
                throw new NotEligibleException('Agent Working stack missing on engine board');
            }
            $this->deck->moveCard($boardId, $located['stackId'], $engineCardId, $working);
            $this->deck->postComment($engineCardId, 'AGENT CLAIMED');

            $this->db->commit();
        } catch (ClaimConflictException $e) {
            throw $e;
        } catch (\Throwable $e) {
            try {
                $this->db->rollBack();
            } catch (\Throwable) {
                // rollback failure is secondary — the original error wins
            }
            if ($e instanceof NotEligibleException) {
                throw $e;
            }
            $this->logger->error('agent_engine: claim failed after mutex acquisition', [
                'app' => 'agent_engine', 'cardId' => $engineCardId, 'exception' => $e->getMessage(),
            ]);
            throw new \RuntimeException('claim failed: ' . $e->getMessage(), 0, $e);
        }

        // ---- Post-commit: mirror + reread (outside the lock) ---------------
        $link = $this->linkMapper->findOpenByEngineCard($engineCardId);
        if ($link !== null) {
            try {
                $this->mirror->onEngineReceipt($link, 'AGENT CLAIMED', '');
            } catch (\Throwable $e) {
                $this->logger->warning('agent_engine: claim mirror failed (sweep will heal labels)', [
                    'app' => 'agent_engine', 'cardId' => $engineCardId, 'exception' => $e->getMessage(),
                ]);
            }
        }

        $reread = $this->deck->findCard($boardId, $engineCardId);
        return [
            'cardId' => $engineCardId,
            'reread' => $reread['card'] ?? $located['card'],
        ];
    }

    /** Who holds the claim row (for the 409 body). */
    private function claimedBy(int $engineCardId): string {
        $row = $this->eventMapper->findByKey('claim:' . $engineCardId);
        if ($row === null) {
            return 'unknown';
        }
        $payload = json_decode((string)$row->getPayload(), true);
        return is_array($payload) ? (string)($payload['agentCode'] ?? 'unknown') : 'unknown';
    }
}
