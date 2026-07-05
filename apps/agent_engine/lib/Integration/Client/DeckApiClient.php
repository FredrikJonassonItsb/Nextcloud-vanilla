<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Integration\Client;

use OCA\AgentEngine\Integration\BotServiceAuth;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Thin client onto the deck app's REST + OCS APIs — the SAME integration style
 * as hubs_arende DeckClient: HTTP against Deck's own endpoints as the
 * bot-engine service account. NO direct writes to Deck's DB tables, ever.
 *
 *   REST base:      /apps/deck/api/v1.0            (boards/stacks/cards/labels)
 *   Comments (OCS): /ocs/v2.php/apps/deck/api/v1.0/cards/{id}/comments
 *
 * Unlike hubs_arende's saga (graceful no-op), the engine's correctness paths
 * (claim/takeover/mirror) need failures to SURFACE — so mutating calls throw
 * \RuntimeException on transport/HTTP errors; read calls return null on
 * failure and let the caller decide.
 *
 * PII log discipline: card titles/labels/comments never hit the log verbatim —
 * only safeRef() digests (len + sha256 prefix).
 */
class DeckApiClient {
    private const DECK_APP_ID = 'deck';
    private const API_BASE = '/apps/deck/api/v1.0';
    private const OCS_BASE = '/ocs/v2.php/apps/deck/api/v1.0';

    public function __construct(
        private IAppManager $appManager,
        private IClientService $clientService,
        private IURLGenerator $urlGenerator,
        private LoggerInterface $logger,
        private BotServiceAuth $auth,
    ) {
    }

    public function isAvailable(): bool {
        return $this->appManager->isEnabledForUser(self::DECK_APP_ID);
    }

    // ================================================================== //
    //  Boards & stacks
    // ================================================================== //

    /**
     * All stacks of a board (each embeds cards[]), ETag-aware.
     *
     * @return array{notModified:bool,etag:string,stacks:array<int,array<string,mixed>>}|null
     */
    public function getStacks(int $boardId, string $ifNoneMatch = ''): ?array {
        $headers = [];
        if ($ifNoneMatch !== '') {
            $headers['If-None-Match'] = $ifNoneMatch;
        }
        $res = $this->request('GET', self::API_BASE . '/boards/' . $boardId . '/stacks', null, $headers, [200, 304]);
        if ($res === null) {
            return null;
        }
        if ($res['status'] === 304) {
            return ['notModified' => true, 'etag' => $ifNoneMatch, 'stacks' => []];
        }
        $stacks = $this->unwrap($res['body']);
        return [
            'notModified' => false,
            'etag' => $res['etag'],
            'stacks' => is_array($stacks) ? $stacks : [],
        ];
    }

    /** Full board payload — carries labels[] + acl[]. Null on failure. */
    public function getBoard(int $boardId): ?array {
        $res = $this->request('GET', self::API_BASE . '/boards/' . $boardId, null, [], [200]);
        $data = $res === null ? null : $this->unwrap($res['body']);
        return is_array($data) ? $data : null;
    }

    /**
     * All boards visible to the bot-engine service account (owned + shared).
     * Read call — returns [] on failure and lets the caller degrade.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listBoards(): array {
        $res = $this->request('GET', self::API_BASE . '/boards', null, [], [200]);
        $data = $res === null ? null : $this->unwrap($res['body']);
        return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
    }

    /**
     * Share a board with a USER participant, upserting the permission bits.
     * Deck route: POST /boards/{id}/acl (type 0 = user). Idempotent-ish: Deck
     * rejects a duplicate participant with 400/403, which we treat as "already
     * shared" (the enrollment path re-runs freely). The actor is bot-engine, so
     * bot-engine must already have MANAGE on the board — the engine board's own
     * owner is bot-engine, and human boards grant it manage via this very call
     * (bot-engine is added with manage first). Returns true when the ACL now
     * exists (freshly created OR already present), false on a hard failure.
     */
    public function shareBoardAcl(
        int $boardId,
        string $participant,
        bool $edit = true,
        bool $share = false,
        bool $manage = false,
    ): bool {
        $res = $this->request(
            'POST',
            self::API_BASE . '/boards/' . $boardId . '/acl',
            [
                'type' => 0, // ACL_PERMISSION_TYPE user (Deck's Acl::PERMISSION_TYPE_USER)
                'participant' => $participant,
                'permissionEdit' => $edit,
                'permissionShare' => $share,
                'permissionManage' => $manage,
            ],
            [],
            // 200/201 = created; 400/403 = participant already on the board / no
            // change — both mean the desired ACL exists, so enrollment proceeds.
            [200, 201, 400, 403],
        );
        return $res !== null;
    }

    /** Resolve a stack id by exact title on a board. Null when absent. */
    public function findStackIdByTitle(int $boardId, string $title): ?int {
        $stacks = $this->getStacks($boardId);
        foreach ($stacks['stacks'] ?? [] as $stack) {
            if (is_array($stack) && (string)($stack['title'] ?? '') === $title && isset($stack['id'])) {
                return (int)$stack['id'];
            }
        }
        return null;
    }

    /**
     * Locate a card on a board: returns the card payload + its stack.
     *
     * @return array{card:array<string,mixed>,stackId:int,stackTitle:string}|null
     */
    public function findCard(int $boardId, int $cardId): ?array {
        $stacks = $this->getStacks($boardId);
        foreach ($stacks['stacks'] ?? [] as $stack) {
            if (!is_array($stack)) {
                continue;
            }
            foreach ((array)($stack['cards'] ?? []) as $card) {
                if (is_array($card) && (int)($card['id'] ?? 0) === $cardId) {
                    return [
                        'card' => $card,
                        'stackId' => (int)($stack['id'] ?? 0),
                        'stackTitle' => (string)($stack['title'] ?? ''),
                    ];
                }
            }
        }
        return null;
    }

    // ================================================================== //
    //  Cards
    // ================================================================== //

    /**
     * Create a card. Throws on failure (takeover must not half-succeed silently).
     *
     * @return array{cardId:int}
     */
    public function createCard(int $boardId, int $stackId, string $title, string $description, ?string $duedate): array {
        $payload = [
            'title' => $title,
            'type' => 'plain',
            'order' => 999,
            'description' => $description,
        ];
        if ($duedate !== null && $duedate !== '') {
            $payload['duedate'] = $duedate;
        }
        $res = $this->requestOrThrow('POST', self::API_BASE . '/boards/' . $boardId . '/stacks/' . $stackId . '/cards', $payload);
        $id = $this->extractId($res['body']);
        if ($id === null) {
            throw new \RuntimeException('deck createCard returned no id');
        }
        $this->logger->info('agent_engine: Deck createCard', [
            'app' => 'agent_engine',
            'boardId' => $boardId,
            'stackId' => $stackId,
            'cardId' => $id,
            'titleRef' => $this->safeRef($title),
        ]);
        return ['cardId' => $id];
    }

    /**
     * Move a card to another stack (the claim-by-status-move primitive).
     * Deck route: PUT …/boards/{b}/stacks/{stackId}/cards/{cardId}/reorder —
     * the {stackId} in the URL PATH is the TARGET stack (Deck's
     * CardApiController::reorder binds $stackId from the path and passes it as
     * the destination). Verified against Deck 1.15.9 on dev15: putting the
     * SOURCE stack in the path leaves the card where it is. The body carries
     * the same target stackId + order for Deck's internal reorder bookkeeping.
     */
    public function moveCard(int $boardId, int $fromStackId, int $cardId, int $toStackId, int $order = 0): void {
        $this->requestOrThrow(
            'PUT',
            self::API_BASE . '/boards/' . $boardId . '/stacks/' . $toStackId . '/cards/' . $cardId . '/reorder',
            ['order' => $order, 'stackId' => $toStackId],
        );
    }

    /** Archive a card (recall path). */
    public function archiveCard(int $boardId, int $stackId, int $cardId): void {
        $this->requestOrThrow(
            'PUT',
            self::API_BASE . '/boards/' . $boardId . '/stacks/' . $stackId . '/cards/' . $cardId . '/archive',
            null,
        );
    }

    /** Partial card update (duedate copy). GET-merge is the caller's concern. */
    public function updateCard(int $boardId, int $stackId, int $cardId, array $fields): void {
        $this->requestOrThrow(
            'PUT',
            self::API_BASE . '/boards/' . $boardId . '/stacks/' . $stackId . '/cards/' . $cardId,
            $fields,
        );
    }

    public function assignUser(int $boardId, int $stackId, int $cardId, string $userId): void {
        $this->requestOrThrow(
            'PUT',
            self::API_BASE . '/boards/' . $boardId . '/stacks/' . $stackId . '/cards/' . $cardId . '/assignUser',
            ['userId' => $userId],
        );
    }

    public function unassignUser(int $boardId, int $stackId, int $cardId, string $userId): void {
        $this->requestOrThrow(
            'PUT',
            self::API_BASE . '/boards/' . $boardId . '/stacks/' . $stackId . '/cards/' . $cardId . '/unassignUser',
            ['userId' => $userId],
        );
    }

    /** Attachment names only (PII firewall input). Empty array on failure. */
    public function getAttachmentNames(int $boardId, int $stackId, int $cardId): array {
        $res = $this->request(
            'GET',
            self::API_BASE . '/boards/' . $boardId . '/stacks/' . $stackId . '/cards/' . $cardId . '/attachments',
            null,
            [],
            [200],
        );
        $list = $res === null ? null : $this->unwrap($res['body']);
        $names = [];
        foreach (is_array($list) ? $list : [] as $att) {
            if (is_array($att)) {
                $names[] = (string)($att['data'] ?? ($att['extendedData']['info']['filename'] ?? ''));
            }
        }
        return array_values(array_filter($names, static fn (string $n): bool => $n !== ''));
    }

    // ================================================================== //
    //  Labels — resolve-or-create idiom (self-healing, INTERAKTIONSDESIGN §2.5)
    // ================================================================== //

    /** Resolve a label id by exact title, creating it with $color if missing. */
    public function resolveLabelId(int $boardId, string $title, string $color): ?int {
        $board = $this->getBoard($boardId);
        foreach ((array)($board['labels'] ?? []) as $label) {
            if (is_array($label) && (string)($label['title'] ?? '') === $title && isset($label['id'])) {
                return (int)$label['id'];
            }
        }
        try {
            $res = $this->requestOrThrow('POST', self::API_BASE . '/boards/' . $boardId . '/labels', [
                'title' => $title,
                'color' => $color,
            ]);
            return $this->extractId($res['body']);
        } catch (\RuntimeException $e) {
            $this->logger->warning('agent_engine: label create failed', [
                'app' => 'agent_engine',
                'boardId' => $boardId,
                'labelRef' => $this->safeRef($title),
                'exception' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public function assignLabel(int $boardId, int $stackId, int $cardId, int $labelId): void {
        $this->requestOrThrow(
            'PUT',
            self::API_BASE . '/boards/' . $boardId . '/stacks/' . $stackId . '/cards/' . $cardId . '/assignLabel',
            ['labelId' => $labelId],
        );
    }

    public function removeLabel(int $boardId, int $stackId, int $cardId, int $labelId): void {
        // Label may already be gone — treat 400/404 as success (idempotent remove).
        $this->request(
            'PUT',
            self::API_BASE . '/boards/' . $boardId . '/stacks/' . $stackId . '/cards/' . $cardId . '/removeLabel',
            ['labelId' => $labelId],
            [],
            [200, 400, 403, 404],
        );
    }

    // ================================================================== //
    //  Comments (OCS) — the receipts/mirror channel
    // ================================================================== //

    /**
     * Comments on a card, newest-first as Deck returns them.
     *
     * @return array<int,array<string,mixed>> [] on failure
     */
    public function getComments(int $cardId, int $limit = 50, int $offset = 0): array {
        $res = $this->request(
            'GET',
            self::OCS_BASE . '/cards/' . $cardId . '/comments?limit=' . $limit . '&offset=' . $offset,
            null,
            [],
            [200],
        );
        $data = $res === null ? null : $this->unwrap($res['body']);
        return is_array($data) ? $data : [];
    }

    /**
     * Post a comment (≤1000 chars server-side; callers truncate to 900).
     *
     * @return int the new comment id
     */
    public function postComment(int $cardId, string $message, ?int $parentId = null): int {
        $payload = ['message' => $message];
        if ($parentId !== null) {
            $payload['parentId'] = $parentId;
        }
        $res = $this->requestOrThrow('POST', self::OCS_BASE . '/cards/' . $cardId . '/comments', $payload);
        $id = $this->extractId($res['body']);
        if ($id === null) {
            throw new \RuntimeException('deck postComment returned no id');
        }
        return $id;
    }

    /** Update a comment in place — author-only server-side (we always author as bot-engine). */
    public function updateComment(int $cardId, int $commentId, string $message): void {
        $this->requestOrThrow('PUT', self::OCS_BASE . '/cards/' . $cardId . '/comments/' . $commentId, [
            'message' => $message,
        ]);
    }

    // ================================================================== //
    //  Transport
    // ================================================================== //

    /**
     * @param array<string,mixed>|null $body
     * @param array<string,string> $headers
     * @param int[] $okStatuses
     * @return array{status:int,etag:string,body:array<string,mixed>|null}|null null on transport error or unexpected status
     */
    private function request(string $method, string $path, ?array $body, array $headers = [], array $okStatuses = [200]): ?array {
        try {
            $client = $this->clientService->newClient();
            $url = $this->urlGenerator->getAbsoluteURL($path);
            $options = [
                'headers' => array_merge([
                    'Accept' => 'application/json',
                    'OCS-APIRequest' => 'true',
                ], $headers),
                'timeout' => 15,
                'nextcloud' => ['allow_local_address' => true],
                // We inspect status codes ourselves.
                'http_errors' => false,
            ];
            $auth = $this->auth->authorizationHeader();
            if ($auth !== null) {
                $options['headers']['Authorization'] = $auth;
            }
            if ($body !== null) {
                $options['json'] = $body;
            }

            $response = $client->request($method, $url, $options);
            $status = $response->getStatusCode();
            if (!in_array($status, $okStatuses, true)) {
                $this->logger->warning('agent_engine: Deck call unexpected status', [
                    'app' => 'agent_engine',
                    'method' => $method,
                    'path' => $path,
                    'status' => $status,
                ]);
                return null;
            }
            $etag = (string)($response->getHeader('ETag') ?? '');
            $raw = $response->getBody();
            $text = is_string($raw) ? $raw : '';
            $decoded = $text !== '' ? json_decode($text, true) : null;
            return [
                'status' => $status,
                'etag' => $etag,
                'body' => is_array($decoded) ? $decoded : null,
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('agent_engine: Deck call failed', [
                'app' => 'agent_engine',
                'method' => $method,
                'path' => $path,
                'exception' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array{status:int,etag:string,body:array<string,mixed>|null}
     * @throws \RuntimeException when the call fails — mutations must surface
     */
    private function requestOrThrow(string $method, string $path, ?array $body): array {
        $res = $this->request($method, $path, $body, [], [200, 201]);
        if ($res === null) {
            throw new \RuntimeException('deck API call failed: ' . $method . ' ' . $path);
        }
        return $res;
    }

    /** Unwrap an OCS envelope ({ocs:{data:…}}) down to its payload, else flat. */
    private function unwrap(?array $response): mixed {
        if ($response === null) {
            return null;
        }
        return $response['ocs']['data'] ?? $response['data'] ?? $response;
    }

    private function extractId(?array $response): ?int {
        $data = $this->unwrap($response);
        if (is_array($data) && isset($data['id']) && is_numeric($data['id'])) {
            return (int)$data['id'];
        }
        return null;
    }

    /** PII-safe log digest (hubs_arende safeRef discipline). */
    private function safeRef(string $value): string {
        if ($value === '') {
            return 'len:0';
        }
        return 'len:' . strlen($value) . ':' . substr(hash('sha256', $value), 0, 12);
    }
}
