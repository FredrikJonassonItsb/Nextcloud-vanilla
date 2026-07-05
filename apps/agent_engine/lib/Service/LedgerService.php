<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Service;

use OCA\AgentEngine\Exception\NotEligibleException;
use OCA\AgentEngine\Integration\Client\DeckApiClient;
use OCA\AgentEngine\Protocol;
use Psr\Log\LoggerInterface;

/**
 * The status ledger (KARTLAGGNING §4.8): ONE `AGENT STATUS` comment per agent
 * on the standing_status card, found-or-created and then UPDATED IN PLACE —
 * never new heartbeat comments. All comments are authored by bot-engine (Deck
 * comment edits are author-only), so the engine can always edit them; the
 * agent is identified by the `Agent: <code>` line inside the body.
 */
class LedgerService {
    /** §4.8 field order, verbatim. */
    private const FIELDS = [
        'agent' => 'Agent',
        'human' => 'Human/operator',
        'runtime' => 'Runtime',
        'automation' => 'Automation',
        'automationState' => 'Automation state',
        'lastHeartbeat' => 'Last heartbeat',
        'lastQueueResult' => 'Last queue result',
        'lastSuccessfulRun' => 'Last successful run',
        'localContext' => 'Local context',
        'optionalSkills' => 'Optional skills',
        'notes' => 'Notes',
    ];

    public function __construct(
        private DeckApiClient $deck,
        private EngineConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Upsert the AGENT STATUS comment for an agent, in place.
     *
     * @param array<string,mixed> $fields body from PUT /ledger/{agentCode}
     * @return array{cardId:int,commentId:int,created:bool}
     * @throws NotEligibleException when the ledger card cannot be resolved
     */
    public function upsert(string $agentCode, array $fields): array {
        $cardId = $this->resolveLedgerCardId();
        $message = $this->render($agentCode, $fields);

        $existing = $this->findStatusComment($cardId, $agentCode);
        if ($existing !== null) {
            $this->deck->updateComment($cardId, $existing['id'], $message);
            return ['cardId' => $cardId, 'commentId' => $existing['id'], 'created' => false];
        }
        $commentId = $this->deck->postComment($cardId, $message);
        return ['cardId' => $cardId, 'commentId' => $commentId, 'created' => true];
    }

    /**
     * Presence check for takeover (INTERAKTIONSDESIGN §2.3 step 4): parse
     * `Last heartbeat:` from the agent's ledger comment.
     *
     * @return array{heartbeat:?int,paused:bool} unix ts (null = never seen)
     */
    public function presence(string $agentCode): array {
        try {
            $cardId = $this->resolveLedgerCardId();
        } catch (NotEligibleException) {
            return ['heartbeat' => null, 'paused' => false];
        }
        $comment = $this->findStatusComment($cardId, $agentCode);
        if ($comment === null) {
            return ['heartbeat' => null, 'paused' => false];
        }
        $heartbeat = null;
        if (preg_match('/^Last heartbeat:\s*(.+)$/mi', $comment['message'], $m) === 1) {
            $ts = strtotime(trim($m[1]));
            $heartbeat = $ts === false ? null : $ts;
        }
        $paused = preg_match('/^Automation state:\s*paused\b/mi', $comment['message']) === 1;
        return ['heartbeat' => $heartbeat, 'paused' => $paused];
    }

    /**
     * Resolve the standing_status card. Config wins (deck-bootstrap writes the
     * id); fallback scans the Standing stack for the [standing_status] title
     * and back-fills the config (self-healing).
     */
    public function resolveLedgerCardId(): int {
        $configured = $this->config->ledgerCardId();
        if ($configured > 0) {
            return $configured;
        }
        $boardId = $this->config->engineBoardId();
        if ($boardId <= 0) {
            throw new NotEligibleException('engine_board_id not configured');
        }
        $stacks = $this->deck->getStacks($boardId);
        foreach ($stacks['stacks'] ?? [] as $stack) {
            foreach ((array)($stack['cards'] ?? []) as $card) {
                $parsed = TitleGrammar::parse((string)($card['title'] ?? ''));
                if ($parsed !== null && $parsed['type'] === 'standing_status') {
                    $cardId = (int)$card['id'];
                    $this->config->setLedgerCardId($cardId);
                    return $cardId;
                }
            }
        }
        throw new NotEligibleException('ledger card not found on engine board');
    }

    /**
     * @return array{id:int,message:string}|null the agent's AGENT STATUS comment
     */
    private function findStatusComment(int $cardId, string $agentCode): ?array {
        // Ledger card has at most one comment per agent + noise → 100 covers it;
        // paginate defensively anyway.
        for ($offset = 0; $offset < 500; $offset += 100) {
            $comments = $this->deck->getComments($cardId, 100, $offset);
            if ($comments === []) {
                return null;
            }
            foreach ($comments as $comment) {
                if (!is_array($comment)) {
                    continue;
                }
                $message = (string)($comment['message'] ?? '');
                if (str_starts_with($message, 'AGENT STATUS')
                    && preg_match('/^Agent:\s*' . preg_quote($agentCode, '/') . '\s*$/mi', $message) === 1
                ) {
                    return ['id' => (int)($comment['id'] ?? 0), 'message' => $message];
                }
            }
            if (count($comments) < 100) {
                return null;
            }
        }
        return null;
    }

    /** Render the §4.8 block, ≤900 chars (CONTRACTS §2 comment budget). */
    private function render(string $agentCode, array $fields): string {
        $defaults = [
            'agent' => $agentCode,
            'human' => $this->config->ownerForAgentCode($agentCode) ?? 'unknown',
            'runtime' => 'Claude',
            'automation' => 'runner-cron',
            'automationState' => 'installed',
            'lastHeartbeat' => gmdate('c'),
            'lastQueueResult' => 'checking',
            'lastSuccessfulRun' => 'unknown',
            'localContext' => 'engine v1; routing map v1',
            'optionalSkills' => 'none',
            'notes' => 'none',
        ];
        $lines = ['AGENT STATUS'];
        foreach (self::FIELDS as $key => $label) {
            $value = $fields[$key] ?? $defaults[$key];
            $value = trim(is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE));
            // The ledger is protocol surface — force single-line field values.
            $value = preg_replace('/\s+/u', ' ', $value) ?? '';
            if ($key === 'agent') {
                $value = $agentCode; // path param is authoritative
            }
            $lines[] = $label . ': ' . $value;
        }
        $message = implode("\n", $lines);
        if (mb_strlen($message) > Protocol::COMMENT_MAX) {
            $message = mb_substr($message, 0, Protocol::COMMENT_MAX - 1) . '…';
        }
        return $message;
    }
}
