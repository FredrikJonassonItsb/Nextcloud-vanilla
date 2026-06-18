<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Integration\Client;

use OCA\HubsArende\Integration\ServiceAccountAuth;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Thin client onto the **deck** app's OCS API (SAGA R5 — the ärendekort).
 *
 * deck is a SEPARATE NC app, consumed over its OCS API:
 *   - GET    /apps/deck/api/v1.0/boards
 *   - POST   /apps/deck/api/v1.0/boards                                    (title,color)
 *   - GET    /apps/deck/api/v1.0/boards/{boardId}/stacks
 *   - POST   /apps/deck/api/v1.0/boards/{boardId}/stacks                   (title,order)
 *   - POST   /apps/deck/api/v1.0/boards/{boardId}/stacks/{stackId}/cards   (title,type,…,duedate)
 *   - POST   /apps/deck/api/v1.0/boards/{boardId}/labels                   (title,color)
 *   - PUT    /apps/deck/api/v1.0/boards/{boardId}/stacks/{stackId}/cards/{cardId}/assignLabel   (labelId)
 *   - DELETE /apps/deck/api/v1.0/boards/{boardId}/stacks/{stackId}/cards/{cardId}
 *
 * R5 is a 2-step create (POST card → PUT label case:{id} / set due=frist) per the
 * ArendeService TODO[deck]; the returned {boardId, cardId} is stored as a pekare
 * (objekt_typ='deck_card') so the compensation can delete it.
 *
 * Resolution is per-enhet: one board per enhet (title === enhet), with a single
 * "Inkommande" stack. Both are looked up first and created on demand, so the
 * saga is idempotent across cases for the same enhet.
 *
 * GRACEFUL DEGRADATION: deck absent (or any sub-step failing / unauthenticated)
 * ⇒ the affected method NO-OPs and returns null; the SAGA continues unchanged.
 * The service-account credential (Seam A) is supplied by {@see ServiceAccountAuth}
 * and applied in {@see ocsRequest()}.
 */
class DeckClient {
    private const APP_ID = 'deck';
    private const API_BASE = '/apps/deck/api/v1.0';

    /** Default board colour (6-hex, no leading '#') for an auto-created enhet board. */
    private const BOARD_COLOR = '0082c9';

    /** Title of the single intake stack created per board. */
    private const STACK_TITLE = 'Inkommande';

    /** Card type for a plain (non-comment) card. */
    private const CARD_TYPE_PLAIN = 'plain';

    public function __construct(
        private IAppManager $appManager,
        private IClientService $clientService,
        private IURLGenerator $urlGenerator,
        private LoggerInterface $logger,
        private ServiceAccountAuth $serviceAuth,
    ) {
    }

    public function isAvailable(): bool {
        return $this->appManager->isEnabledForUser(self::APP_ID);
    }

    /**
     * R5 step 1 — create the ärendekort on the enhet's board.
     *
     * Resolves (and creates on demand) the per-enhet board and its intake stack,
     * then POSTs the card. Any sub-step failing yields a graceful null so the
     * SAGA continues.
     *
     * @param string      $board The board ref (enhet) — title or numeric board id.
     * @param string      $title Card title (the pseudonym hubsCaseId).
     * @param string|null $due   ISO-8601 frist (duedate), or null.
     * @return array{boardId:int,cardId:int}|null The created card pointer, or null (NO-OP).
     */
    public function createCard(string $board, string $title, ?string $due): ?array {
        if (!$this->isAvailable()) {
            $this->noop('createCard', $title);
            return null;
        }

        $boardId = $this->resolveBoardId($board);
        if ($boardId === null) {
            $this->logger->warning('hubs_arende: DeckClient.createCard kunde inte resolva board (graceful)', [
                'app' => 'hubs_arende',
                'boardRef' => $this->safeRef($board),
            ]);
            return null;
        }

        $stackId = $this->resolveStackId($boardId);
        if ($stackId === null) {
            $this->logger->warning('hubs_arende: DeckClient.createCard kunde inte resolva stack (graceful)', [
                'app' => 'hubs_arende',
                'boardId' => $boardId,
            ]);
            return null;
        }

        $payload = [
            'title' => $title,
            'type' => self::CARD_TYPE_PLAIN,
            'order' => 0,
        ];
        if ($due !== null && $due !== '') {
            $payload['duedate'] = $due;
        }
        $response = $this->ocsRequest(
            'POST',
            self::API_BASE . '/boards/' . $boardId . '/stacks/' . $stackId . '/cards',
            $payload,
            $title,
        );

        $cardId = $this->extractId($response);
        if ($cardId === null) {
            $this->logger->warning('hubs_arende: DeckClient.createCard fick inget card-id (graceful)', [
                'app' => 'hubs_arende',
                'boardId' => $boardId,
                'stackId' => $stackId,
            ]);
            return null;
        }

        $this->logger->info('hubs_arende: DeckClient.createCard', [
            'app' => 'hubs_arende',
            'boardId' => $boardId,
            'stackId' => $stackId,
            'cardId' => $cardId,
            // OSL 26 kap: do NOT log the raw card title (may carry PII) —
            // log a non-reversible digest of its shape instead.
            'titleRef' => $this->safeRef($title),
            'due' => $due,
        ]);

        return ['boardId' => $boardId, 'cardId' => $cardId];
    }

    /**
     * R5 step 2 — assign a label to the card.
     *
     * Resolves (creating on demand) the labelId for $label on the board, locates
     * the card's stack, then PUTs assignLabel. Any sub-step failing is swallowed
     * graceful (the card itself already exists; the label is best-effort).
     *
     * @param int    $boardId The board the card lives on.
     * @param int    $cardId  The card to label.
     * @param string $label   The label text (e.g. 'case:{hubsCaseId}').
     * @return bool true if the assignLabel was attempted, false on NO-OP / skip.
     */
    public function addLabel(int $boardId, int $cardId, string $label): bool {
        if (!$this->isAvailable()) {
            $this->noop('addLabel', $label);
            return false;
        }

        $labelId = $this->resolveLabelId($boardId, $label);
        if ($labelId === null) {
            $this->logger->warning('hubs_arende: DeckClient.addLabel kunde inte resolva label (graceful skip)', [
                'app' => 'hubs_arende',
                'boardId' => $boardId,
                'cardId' => $cardId,
                'labelRef' => $this->safeRef($label),
            ]);
            return false;
        }

        $stackId = $this->findStackIdForCard($boardId, $cardId);
        if ($stackId === null) {
            $this->logger->warning('hubs_arende: DeckClient.addLabel hittade ingen stack för kortet (graceful skip)', [
                'app' => 'hubs_arende',
                'boardId' => $boardId,
                'cardId' => $cardId,
            ]);
            return false;
        }

        $this->ocsRequest(
            'PUT',
            self::API_BASE . '/boards/' . $boardId . '/stacks/' . $stackId . '/cards/' . $cardId . '/assignLabel',
            ['labelId' => $labelId],
            $label,
        );

        $this->logger->info('hubs_arende: DeckClient.addLabel', [
            'app' => 'hubs_arende',
            'boardId' => $boardId,
            'stackId' => $stackId,
            'cardId' => $cardId,
            'labelId' => $labelId,
            // OSL 26 kap: do NOT log the raw label (may carry PII) — digest only.
            'labelRef' => $this->safeRef($label),
        ]);

        return true;
    }

    /**
     * R5 compensation — delete the ärendekort.
     *
     * @return bool true if attempted, false on NO-OP / skip.
     */
    public function deleteCard(int $boardId, int $cardId): bool {
        if (!$this->isAvailable()) {
            $this->noop('deleteCard', (string)$cardId);
            return false;
        }

        $stackId = $this->findStackIdForCard($boardId, $cardId);
        if ($stackId === null) {
            $this->logger->warning('hubs_arende: DeckClient.deleteCard hittade ingen stack för kortet (graceful skip)', [
                'app' => 'hubs_arende',
                'boardId' => $boardId,
                'cardId' => $cardId,
            ]);
            return false;
        }

        $this->ocsRequest(
            'DELETE',
            self::API_BASE . '/boards/' . $boardId . '/stacks/' . $stackId . '/cards/' . $cardId,
            null,
            (string)$cardId,
        );

        $this->logger->info('hubs_arende: DeckClient.deleteCard', [
            'app' => 'hubs_arende',
            'boardId' => $boardId,
            'stackId' => $stackId,
            'cardId' => $cardId,
        ]);

        return true;
    }

    // ================================================================== //
    //  Internal helpers — resolution
    // ================================================================== //

    /**
     * Resolve a board ref to a numeric boardId.
     *
     * A numeric ref is taken verbatim. Otherwise GET /boards is searched for a
     * board whose title === $board; if none exists a board is created with
     * {title:$board, color}. Returns null if the lookup/create yields no id.
     */
    private function resolveBoardId(string $board): ?int {
        if (is_numeric($board)) {
            return (int)$board;
        }

        $existing = $this->ocsRequest('GET', self::API_BASE . '/boards', null, $board);
        $found = $this->findIdByTitle($existing, $board);
        if ($found !== null) {
            return $found;
        }

        $created = $this->ocsRequest('POST', self::API_BASE . '/boards', [
            'title' => $board,
            'color' => self::BOARD_COLOR,
        ], $board);

        return $this->extractId($created);
    }

    /**
     * Resolve the intake stack for a board: take the first existing stack, else
     * create one ({title:'Inkommande', order:0}). Returns null on failure.
     */
    private function resolveStackId(int $boardId): ?int {
        $stacks = $this->ocsRequest('GET', self::API_BASE . '/boards/' . $boardId . '/stacks', null, (string)$boardId);
        $first = $this->firstId($stacks);
        if ($first !== null) {
            return $first;
        }

        $created = $this->ocsRequest('POST', self::API_BASE . '/boards/' . $boardId . '/stacks', [
            'title' => self::STACK_TITLE,
            'order' => 0,
        ], (string)$boardId);

        return $this->extractId($created);
    }

    /**
     * Resolve a labelId for $label on the board: match an existing board label by
     * title, else POST a new one ({title:$label, color}). Returns null on failure.
     */
    private function resolveLabelId(int $boardId, string $label): ?int {
        // The full board payload carries its labels[].
        $boardDetail = $this->ocsRequest('GET', self::API_BASE . '/boards/' . $boardId, null, $label);
        $data = $this->unwrap($boardDetail);
        if (is_array($data) && isset($data['labels']) && is_array($data['labels'])) {
            $existing = $this->findIdByTitle($data['labels'], $label);
            if ($existing !== null) {
                return $existing;
            }
        }

        $created = $this->ocsRequest('POST', self::API_BASE . '/boards/' . $boardId . '/labels', [
            'title' => $label,
            'color' => self::BOARD_COLOR,
        ], $label);

        return $this->extractId($created);
    }

    /**
     * Find which stack on the board contains $cardId (assignLabel / delete need
     * the stackId in the path). GET /stacks returns stacks with an embedded
     * cards[]. Returns null if the card is not located.
     */
    private function findStackIdForCard(int $boardId, int $cardId): ?int {
        $stacks = $this->ocsRequest('GET', self::API_BASE . '/boards/' . $boardId . '/stacks', null, (string)$cardId);
        $list = $this->unwrap($stacks);
        if (!is_array($list)) {
            return null;
        }
        foreach ($list as $stack) {
            if (!is_array($stack)) {
                continue;
            }
            $cards = $stack['cards'] ?? null;
            if (is_array($cards)) {
                foreach ($cards as $card) {
                    if (is_array($card) && isset($card['id']) && (int)$card['id'] === $cardId) {
                        $sid = $stack['id'] ?? null;
                        return is_numeric($sid) ? (int)$sid : null;
                    }
                }
            }
        }
        return null;
    }

    // ================================================================== //
    //  Internal helpers — response parsing
    // ================================================================== //

    /** Unwrap an OCS v1 envelope ({ocs:{data:…}}) down to its payload, else flat. */
    private function unwrap(?array $response): mixed {
        if ($response === null) {
            return null;
        }
        return $response['ocs']['data'] ?? $response['data'] ?? $response;
    }

    /** Pull a numeric 'id' out of an OCS envelope or flat object. */
    private function extractId(?array $response): ?int {
        $data = $this->unwrap($response);
        if (is_array($data) && isset($data['id']) && is_numeric($data['id'])) {
            return (int)$data['id'];
        }
        return null;
    }

    /**
     * From a list (or OCS-wrapped list) of objects each having {id,title}, return
     * the id of the first whose title === $title (case-sensitive exact match).
     *
     * @param array<int|string,mixed>|null $response
     */
    private function findIdByTitle(?array $response, string $title): ?int {
        $list = $this->unwrap($response);
        if (!is_array($list)) {
            return null;
        }
        foreach ($list as $item) {
            if (is_array($item)
                && isset($item['title'], $item['id'])
                && (string)$item['title'] === $title
                && is_numeric($item['id'])
            ) {
                return (int)$item['id'];
            }
        }
        return null;
    }

    /**
     * Return the id of the first object in a (possibly OCS-wrapped) list.
     *
     * @param array<int|string,mixed>|null $response
     */
    private function firstId(?array $response): ?int {
        $list = $this->unwrap($response);
        if (!is_array($list)) {
            return null;
        }
        foreach ($list as $item) {
            if (is_array($item) && isset($item['id']) && is_numeric($item['id'])) {
                return (int)$item['id'];
            }
        }
        return null;
    }

    /**
     * Internal OCS request against the deck app.
     *
     * The service-account credential (Seam A) is applied via
     * {@see ServiceAccountAuth::authorizationHeader()}; when unconfigured the call
     * goes out unauthenticated, 401s, and is swallowed graceful so the SAGA
     * continues.
     *
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>|null
     */
    private function ocsRequest(string $method, string $path, ?array $body, string $ref): ?array {
        try {
            $client = $this->clientService->newClient();
            $url = $this->urlGenerator->getAbsoluteURL($path);
            $options = [
                'headers' => [
                    'Accept' => 'application/json',
                    'OCS-APIRequest' => 'true',
                ],
                'timeout' => 10,
                'nextcloud' => ['allow_local_address' => true],
            ];
            $auth = $this->serviceAuth->authorizationHeader();
            if ($auth !== null) {
                $options['headers']['Authorization'] = $auth;
            }
            if ($body !== null) {
                $options['json'] = $body;
            }

            $response = $client->request($method, $url, $options);
            $raw = $response->getBody();
            $text = is_string($raw) ? $raw : '';
            $decoded = $text !== '' ? json_decode($text, true) : null;

            return is_array($decoded) ? $decoded : null;
        } catch (\Throwable $e) {
            $this->logger->warning('hubs_arende: DeckClient OCS-anrop misslyckades (graceful)', [
                'app' => 'hubs_arende',
                'method' => $method,
                'path' => $path,
                'ref' => $ref,
                'exception' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function noop(string $method, string $ref): void {
        $this->logger->debug('hubs_arende: DeckClient.' . $method . ' NO-OP (deck ej aktiverad)', [
            'app' => 'hubs_arende',
            'ref' => $ref,
        ]);
    }

    /**
     * Build a non-reversible, PII-safe digest of a free-text value for logging.
     *
     * OSL 2009:400 26 kap / GDPR art. 5.1.c: card titles, labels and room names
     * may carry PII and must never reach the log verbatim. Returns the byte
     * length plus a short SHA-256 prefix so log lines stay correlatable without
     * exposing the raw value. Empty input yields a stable sentinel.
     */
    private function safeRef(string $value): string {
        if ($value === '') {
            return 'len:0';
        }
        return 'len:' . strlen($value) . ':' . substr(hash('sha256', $value), 0, 12);
    }
}
