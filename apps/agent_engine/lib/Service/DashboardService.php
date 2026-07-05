<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Service;

use OCA\AgentEngine\Db\CardLink;
use OCA\AgentEngine\Db\CardLinkMapper;
use OCA\AgentEngine\Integration\Client\DeckApiClient;
use OCA\AgentEngine\Protocol;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Aggregation behind the "Min agent" dashboard widget (INTERAKTIONSDESIGN
 * §2.9). Answers, for ONE logged-in human, "vad väntar på MIG / vad gör min
 * agent" as an overview — never a stream.
 *
 * The engine board is the single source of live phase (which stack a card sits
 * in); card_links carry the authorization edges (owner_uid = whose agent,
 * requester_uid/reviewer_uid = who asked / who reviews). We read the board once
 * (one ETag-less getStacks) and cross-reference the links so a human only ever
 * sees rows that concern THEM — including work they requested of someone else's
 * agent (requester-scoped, the §2.9 must_fix).
 *
 * Every method is defensive: Deck unreachable, no agent, no board — the widget
 * degrades to a single graceful row and NEVER throws. A fatal here would take
 * down the whole dashboard.
 */
class DashboardService {
    /** Four buckets, priority order (INTERAKTIONSDESIGN §2.9). */
    public const BUCKET_WAITING = 'waiting';   // Väntar på dig
    public const BUCKET_WORKING = 'working';   // Arbetar
    public const BUCKET_QUEUED = 'queued';     // I kö
    public const BUCKET_DONE = 'done';         // Klart idag

    /** "Klart idag" horizon — Done cards touched within this window count. */
    private const DONE_RECENT_SECONDS = 86400;

    public function __construct(
        private CardLinkMapper $linkMapper,
        private DeckApiClient $deck,
        private EngineConfig $config,
        private LedgerService $ledger,
        private IURLGenerator $urlGenerator,
        private ITimeFactory $timeFactory,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Build the per-person overview.
     *
     * @return array{
     *   hasAgent: bool,
     *   agentCodes: string[],
     *   presence: array{online:bool,stale:bool,paused:bool,heartbeat:?int,label:string},
     *   rows: array<int,array{bucket:string,marker:string,title:string,subtitle:string,link:string,sinceId:string,ts:int}>,
     *   boardReachable: bool,
     *   boardUrl: string
     * }
     */
    public function overview(string $userId, int $limit = 7): array {
        $boardId = $this->config->engineBoardId();
        $boardUrl = $this->boardUrl($boardId);
        $agentCodes = $this->config->agentCodesForOwner($userId);

        if ($agentCodes === []) {
            return [
                'hasAgent' => false,
                'agentCodes' => [],
                'presence' => $this->emptyPresence(),
                'rows' => [],
                'boardReachable' => true,
                'boardUrl' => $boardUrl,
            ];
        }

        // Presence uses the FIRST owned agent as the header signal (people own
        // one agent in practice; the multi-agent case still surfaces every
        // agent's cards in the rows below).
        $presence = $this->presenceFor($agentCodes[0]);

        // The card_links this human is entitled to see (their agent's work OR
        // work they requested of another's agent). Keyed by engine card id so a
        // live board card can look up its authorization edge in O(1).
        $linksByEngineCard = [];
        try {
            foreach ($this->linkMapper->findForOwnerOrRequester($userId) as $link) {
                $linksByEngineCard[$link->getEngineCard()] = $link;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('agent_engine: dashboard link lookup failed', [
                'app' => Protocol::ENGINE_BOT, 'exception' => $e->getMessage(),
            ]);
        }

        $stacks = $boardId > 0 ? $this->deck->getStacks($boardId) : null;
        if ($stacks === null) {
            // Deck down / board not configured — one graceful row, never throw.
            return [
                'hasAgent' => true,
                'agentCodes' => $agentCodes,
                'presence' => $presence,
                'rows' => [],
                'boardReachable' => false,
                'boardUrl' => $boardUrl,
            ];
        }

        $ownedCodes = array_fill_keys($agentCodes, true);
        $now = $this->timeFactory->getTime();
        $rows = [];

        foreach ($stacks['stacks'] as $stack) {
            if (!is_array($stack)) {
                continue;
            }
            $stackTitle = (string)($stack['title'] ?? '');
            $bucket = $this->bucketForStack($stackTitle);
            if ($bucket === null) {
                continue;
            }
            foreach ((array)($stack['cards'] ?? []) as $card) {
                if (!is_array($card) || !empty($card['archived'])) {
                    continue;
                }
                $row = $this->rowForCard($userId, $card, $bucket, $ownedCodes, $linksByEngineCard, $boardId, $now);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
        }

        // Sort: bucket priority (waiting first), then most-recently-touched.
        usort($rows, static function (array $a, array $b): int {
            $rank = [self::BUCKET_WAITING => 0, self::BUCKET_WORKING => 1, self::BUCKET_QUEUED => 2, self::BUCKET_DONE => 3];
            return [$rank[$a['bucket']], -$a['ts']] <=> [$rank[$b['bucket']], -$b['ts']];
        });

        return [
            'hasAgent' => true,
            'agentCodes' => $agentCodes,
            'presence' => $presence,
            'rows' => array_slice($rows, 0, max(1, $limit)),
            'boardReachable' => true,
            'boardUrl' => $boardUrl,
        ];
    }

    /**
     * Project one live engine card into a widget row, or null if it does not
     * concern this human / bucket.
     *
     * @param array<string,mixed> $card
     * @param array<string,true> $ownedCodes
     * @param array<int,CardLink> $linksByEngineCard
     * @return array{bucket:string,marker:string,title:string,subtitle:string,link:string,sinceId:string,ts:int}|null
     */
    private function rowForCard(
        string $userId,
        array $card,
        string $bucket,
        array $ownedCodes,
        array $linksByEngineCard,
        int $boardId,
        int $now,
    ): ?array {
        $parsed = TitleGrammar::parse((string)($card['title'] ?? ''));
        if ($parsed === null || $parsed['type'] !== 'task') {
            return null; // standing cards etc. are never "my agent"'s work
        }
        $cardId = (int)($card['id'] ?? 0);
        $agentCode = $parsed['audience'];
        $link = $linksByEngineCard[$cardId] ?? null;

        // Authorization + bucket-specific gating:
        if ($bucket === self::BUCKET_WAITING) {
            // "Väntar på dig" is authorization-scoped: only surface a
            // needs-input/review card when THIS human is the requester or
            // reviewer — the one whose answer/approval it waits on. This is the
            // requester-scoped overview (§2.9): it includes work I asked of
            // ANOTHER person's agent, not just my own agent's cards. Requires a
            // link (the structured edge); a card with no link is not
            // attributable to a person.
            if ($link === null) {
                return null;
            }
            if ($link->getRequesterUid() !== $userId && $link->getReviewerUid() !== $userId) {
                return null;
            }
        } else {
            // Working / queued / done are "my agent"'s cards — gate on the owned
            // agent code (the mapper filter already narrowed links to me).
            if (!isset($ownedCodes[$agentCode])) {
                return null;
            }
            if ($bucket === self::BUCKET_DONE) {
                $touched = $this->cardTouchedAt($card, $link);
                if ($touched > 0 && ($now - $touched) > self::DONE_RECENT_SECONDS) {
                    return null; // only TODAY's done cards
                }
            }
        }

        $ts = $this->cardTouchedAt($card, $link);
        $taskTitle = $parsed['title'] !== '' ? $parsed['title'] : (string)($card['title'] ?? '');
        $meta = self::BUCKETS[$bucket];

        return [
            'bucket' => $bucket,
            'marker' => $meta['marker'],
            'title' => $meta['marker'] . ' ' . $this->trimTitle($taskTitle),
            'subtitle' => $meta['label'] . $this->relativeAge($ts, $now),
            'link' => $this->cardUrl($boardId, $cardId),
            'sinceId' => (string)$ts,
            'ts' => $ts,
        ];
    }

    /** Per-bucket UI metadata (Swedish user-facing; markers per §2.9). */
    private const BUCKETS = [
        self::BUCKET_WAITING => ['marker' => '⏳', 'label' => 'Väntar på dig'],
        self::BUCKET_WORKING => ['marker' => '▶', 'label' => 'Arbetar'],
        self::BUCKET_QUEUED => ['marker' => '☰', 'label' => 'I kö'],
        self::BUCKET_DONE => ['marker' => '✓', 'label' => 'Klart idag'],
    ];

    private function bucketForStack(string $stackTitle): ?string {
        return match ($stackTitle) {
            Protocol::STACK_NEEDS_INPUT, Protocol::STACK_REVIEW => self::BUCKET_WAITING,
            Protocol::STACK_WORKING => self::BUCKET_WORKING,
            Protocol::STACK_TODO => self::BUCKET_QUEUED,
            Protocol::STACK_DONE => self::BUCKET_DONE,
            default => null,
        };
    }

    /**
     * Presence dot/label from the ledger heartbeat (INTERAKTIONSDESIGN §2.9):
     * green online / yellow stale / grey paused.
     *
     * @return array{online:bool,stale:bool,paused:bool,heartbeat:?int,label:string}
     */
    private function presenceFor(string $agentCode): array {
        try {
            $p = $this->ledger->presence($agentCode);
        } catch (\Throwable) {
            return $this->emptyPresence();
        }
        $heartbeat = $p['heartbeat'] ?? null;
        $paused = (bool)($p['paused'] ?? false);
        $staleAfter = $this->config->heartbeatStaleMinutes() * 60;
        $now = $this->timeFactory->getTime();
        $stale = $heartbeat === null || ($now - $heartbeat) > $staleAfter;
        $online = !$paused && !$stale;
        if ($paused) {
            $label = 'pausad';
        } elseif ($online) {
            $label = 'online';
        } else {
            $label = 'inaktiv';
        }
        return [
            'online' => $online,
            'stale' => $stale && !$paused,
            'paused' => $paused,
            'heartbeat' => $heartbeat,
            'label' => $label,
        ];
    }

    /** @return array{online:bool,stale:bool,paused:bool,heartbeat:?int,label:string} */
    private function emptyPresence(): array {
        return ['online' => false, 'stale' => true, 'paused' => false, 'heartbeat' => null, 'label' => 'okänd'];
    }

    /**
     * Best-effort "last touched" timestamp for ordering + the done horizon.
     * Deck cards carry `lastModified` (unix) and `createdAt`; our link row
     * carries updated_at. Take the newest signal available.
     *
     * @param array<string,mixed> $card
     */
    private function cardTouchedAt(array $card, ?CardLink $link): int {
        $candidates = [
            (int)($card['lastModified'] ?? 0),
            (int)($card['createdAt'] ?? 0),
            $link !== null ? $link->getUpdatedAt() : 0,
        ];
        return max($candidates);
    }

    /** Relative age suffix for a subtitle, e.g. " · 3 h" (empty when unknown). */
    private function relativeAge(int $ts, int $now): string {
        if ($ts <= 0) {
            return '';
        }
        $delta = max(0, $now - $ts);
        if ($delta < 3600) {
            $mins = (int)floor($delta / 60);
            return ' · ' . max(1, $mins) . ' min';
        }
        if ($delta < 86400) {
            return ' · ' . (int)floor($delta / 3600) . ' h';
        }
        return ' · ' . (int)floor($delta / 86400) . ' d';
    }

    private function trimTitle(string $title): string {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
        if (mb_strlen($title) > 80) {
            return mb_substr($title, 0, 79) . '…';
        }
        return $title;
    }

    /** Deep-link to a specific Deck card (same shape the Notifier uses). */
    private function cardUrl(int $boardId, int $cardId): string {
        if ($boardId <= 0 || $cardId <= 0) {
            return $this->boardUrl($boardId);
        }
        return $this->urlGenerator->getAbsoluteURL('/apps/deck/board/' . $boardId . '/card/' . $cardId);
    }

    private function boardUrl(int $boardId): string {
        if ($boardId > 0) {
            return $this->urlGenerator->getAbsoluteURL('/apps/deck/board/' . $boardId);
        }
        return $this->urlGenerator->getAbsoluteURL('/apps/deck/');
    }

    /** The Agent Engine board URL — cheap (no Deck/DB read), for getUrl/buttons. */
    public function engineBoardUrl(): string {
        return $this->boardUrl($this->config->engineBoardId());
    }
}
