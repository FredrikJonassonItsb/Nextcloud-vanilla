<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Service;

use OCA\AgentEngine\Db\EngineEventMapper;
use OCA\AgentEngine\Exception\NotEligibleException;
use OCA\AgentEngine\Integration\Client\DeckApiClient;
use OCA\AgentEngine\Protocol;
use Psr\Log\LoggerInterface;

/**
 * Server-side queue filtering (CONTRACTS §3) — Deck has no query API, so the
 * engine does the filtering the runner must never do client-side:
 *
 *   eligible  = stack Agent Todo + label agent-instructions + title second
 *               bracket == agentCode (+ no live claim row), oldest first.
 *   resumable = cards in Agent Needs Input / Agent Review for this agent
 *               where a human answer arrived after the last BLOCKED/HOLD
 *               receipt (Nate's resume condition: the answer is on the card).
 */
class QueueService {
    public function __construct(
        private DeckApiClient $deck,
        private EngineConfig $config,
        private EngineEventMapper $eventMapper,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{next:?array<string,mixed>,eligible:array<int,array<string,mixed>>,resumables:array<int,array<string,mixed>>}
     * @throws NotEligibleException when the engine board is not configured/reachable
     */
    public function queue(string $agentCode): array {
        $boardId = $this->config->engineBoardId();
        if ($boardId <= 0) {
            throw new NotEligibleException('engine_board_id not configured');
        }
        $stacks = $this->deck->getStacks($boardId);
        if ($stacks === null) {
            throw new NotEligibleException('engine board unreachable');
        }

        $eligible = [];
        $resumables = [];
        foreach ($stacks['stacks'] as $stack) {
            if (!is_array($stack)) {
                continue;
            }
            $stackTitle = (string)($stack['title'] ?? '');
            foreach ((array)($stack['cards'] ?? []) as $card) {
                if (!is_array($card) || !empty($card['archived'])) {
                    continue;
                }
                $parsed = TitleGrammar::parse((string)($card['title'] ?? ''));
                if (!TitleGrammar::isTaskFor($parsed, $agentCode)) {
                    continue;
                }
                if ($stackTitle === Protocol::STACK_TODO) {
                    if (!$this->hasInstructionsLabel($card)) {
                        continue;
                    }
                    if ($this->eventMapper->findByKey('claim:' . (int)$card['id']) !== null) {
                        // Stale claim row on a Todo card is released by the
                        // sweep; until then the card is not offered twice.
                        continue;
                    }
                    $eligible[] = $this->project($card, $stackTitle, $parsed);
                } elseif ($stackTitle === Protocol::STACK_NEEDS_INPUT) {
                    $resume = $this->resumeState((int)$card['id']);
                    if ($resume !== null) {
                        $resumables[] = $this->project($card, $stackTitle, $parsed) + $resume;
                    }
                }
            }
        }

        // Oldest first (FIFO — no priority model in v1, GAP 11).
        usort($eligible, static function (array $a, array $b): int {
            return [$a['createdAt'], $a['cardId']] <=> [$b['createdAt'], $b['cardId']];
        });
        usort($resumables, static function (array $a, array $b): int {
            return [$a['createdAt'], $a['cardId']] <=> [$b['createdAt'], $b['cardId']];
        });

        return [
            'next' => $eligible[0] ?? null,
            'eligible' => $eligible,
            'resumables' => $resumables,
        ];
    }

    /**
     * BLOCKED/HOLD card resumable? Yes when an answer-shaped comment (human
     * authored, or a mirrored ⇄ origin answer) is newer than the last
     * BLOCKED/HOLD receipt.
     *
     * @return array{resumeReason:string}|null
     */
    private function resumeState(int $cardId): ?array {
        $comments = $this->deck->getComments($cardId, 100, 0);
        $lastBlockTs = null;
        $lastBlockKind = '';
        $lastAnswerTs = null;
        foreach ($comments as $comment) {
            if (!is_array($comment)) {
                continue;
            }
            $message = trim((string)($comment['message'] ?? ''));
            $actor = (string)($comment['actorId'] ?? '');
            $ts = strtotime((string)($comment['creationDateTime'] ?? '')) ?: 0;
            if (str_starts_with($message, 'AGENT BLOCKED') || str_starts_with($message, 'AGENT HUMAN HOLD')) {
                if ($lastBlockTs === null || $ts > $lastBlockTs) {
                    $lastBlockTs = $ts;
                    $lastBlockKind = str_starts_with($message, 'AGENT BLOCKED') ? 'blocked' : 'holding';
                }
                continue;
            }
            $isHumanAnswer = !Protocol::isBot($actor) && !str_starts_with($message, 'AGENT');
            $isMirroredAnswer = str_starts_with($message, trim(Protocol::MIRROR_PREFIX) . ' Från')
                || str_starts_with($message, trim(Protocol::MIRROR_PREFIX) . 'Från');
            if ($isHumanAnswer || $isMirroredAnswer) {
                if ($lastAnswerTs === null || $ts > $lastAnswerTs) {
                    $lastAnswerTs = $ts;
                }
            }
        }
        if ($lastBlockTs !== null && $lastAnswerTs !== null && $lastAnswerTs >= $lastBlockTs) {
            return ['resumeReason' => $lastBlockKind === 'holding' ? 'human_answered' : 'answer_on_card'];
        }
        return null;
    }

    private function hasInstructionsLabel(array $card): bool {
        foreach ((array)($card['labels'] ?? []) as $label) {
            if (is_array($label) && (string)($label['title'] ?? '') === Protocol::LABEL_INSTRUCTIONS) {
                return true;
            }
        }
        return false;
    }

    /**
     * Coordination projection only — the runner rereads the full card after
     * claim; the queue never needs to carry the whole description.
     *
     * @param array{audience:string,type:string,title:string} $parsed
     * @return array<string,mixed>
     */
    private function project(array $card, string $stackTitle, array $parsed): array {
        return [
            'cardId' => (int)$card['id'],
            'title' => (string)($card['title'] ?? ''),
            'taskTitle' => $parsed['title'],
            'stack' => $stackTitle,
            'duedate' => isset($card['duedate']) && $card['duedate'] ? (string)$card['duedate'] : null,
            'createdAt' => (int)($card['createdAt'] ?? 0),
        ];
    }
}
