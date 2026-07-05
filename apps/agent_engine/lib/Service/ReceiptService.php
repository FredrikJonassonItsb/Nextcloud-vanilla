<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Service;

use OCA\AgentEngine\Db\CardLinkMapper;
use OCA\AgentEngine\Exception\NotEligibleException;
use OCA\AgentEngine\Integration\Client\DeckApiClient;
use OCA\AgentEngine\Protocol;
use Psr\Log\LoggerInterface;

/**
 * Receipts (CONTRACTS §3): validate token ∈ the §2 vocabulary, post the
 * receipt comment (≤900), optionally move the card
 * (move: needs_input|review|done|working), and fan the receipt into the
 * mirror pipeline when the card is link-backed.
 */
class ReceiptService {
    /** move parameter → engine stack title. */
    private const MOVES = [
        'needs_input' => Protocol::STACK_NEEDS_INPUT,
        'review' => Protocol::STACK_REVIEW,
        'done' => Protocol::STACK_DONE,
        'working' => Protocol::STACK_WORKING,
    ];

    public function __construct(
        private DeckApiClient $deck,
        private CardLinkMapper $linkMapper,
        private EngineConfig $config,
        private MirrorService $mirror,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{cardId:int,commentId:int,moved:?string}
     * @throws NotEligibleException invalid token / move / unknown card
     */
    public function post(int $engineCardId, string $token, string $text, ?string $move): array {
        $token = trim($token);
        if (!Protocol::isReceiptToken($token)) {
            throw new NotEligibleException('unknown receipt token');
        }
        if ($move !== null && $move !== '' && !isset(self::MOVES[$move])) {
            throw new NotEligibleException('invalid move target');
        }

        $boardId = $this->config->engineBoardId();
        if ($boardId <= 0) {
            throw new NotEligibleException('engine_board_id not configured');
        }
        $located = $this->deck->findCard($boardId, $engineCardId);
        if ($located === null) {
            throw new NotEligibleException('card not found on engine board');
        }

        // Receipt comment: token first line, detail below, ≤900 (CONTRACTS §2 —
        // longer content belongs in the card description or an attachment).
        $message = $token;
        $text = trim($text);
        if ($text !== '') {
            $message .= "\n" . $text;
        }
        if (mb_strlen($message) > Protocol::COMMENT_MAX) {
            $message = mb_substr($message, 0, Protocol::COMMENT_MAX - 1) . '…';
        }
        $commentId = $this->deck->postComment($engineCardId, $message);

        $movedTo = null;
        if ($move !== null && $move !== '') {
            $targetTitle = self::MOVES[$move];
            $targetId = $this->deck->findStackIdByTitle($boardId, $targetTitle);
            if ($targetId !== null && $targetId !== $located['stackId']) {
                $this->deck->moveCard($boardId, $located['stackId'], $engineCardId, $targetId);
                $movedTo = $targetTitle;
            }
        }

        // Mirror to the origin card when link-backed (manual/!queue engine
        // cards have no link — receipts still work, they just don't mirror).
        $link = $this->linkMapper->findOpenByEngineCard($engineCardId);
        if ($link !== null) {
            try {
                $this->mirror->onEngineReceipt($link, $token, $text);
            } catch (\Throwable $e) {
                $this->logger->warning('agent_engine: receipt mirror failed (sweep will catch up)', [
                    'app' => 'agent_engine', 'cardId' => $engineCardId, 'token' => $token,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return ['cardId' => $engineCardId, 'commentId' => $commentId, 'moved' => $movedTo];
    }
}
