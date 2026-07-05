<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * OCS routes for agent_engine — the exact endpoint table from CONTRACTS §3.
 *
 * Effective prefix: /ocs/v2.php/apps/agent_engine/api/v1/...
 *
 * "bot" endpoints authenticate as bot users via app passwords (Basic auth);
 * admin endpoints require an admin session (no NoAdminRequired attribute).
 */
return [
    'ocs' => [
        // POST /api/v1/claim/{engineCardId} — atomic claim, one winner (bot)
        [
            'name' => 'Claim#claim',
            'url' => '/api/v1/claim/{engineCardId}',
            'verb' => 'POST',
        ],
        // GET /api/v1/queue/{agentCode} — next eligible card + resumables (bot)
        [
            'name' => 'Queue#queue',
            'url' => '/api/v1/queue/{agentCode}',
            'verb' => 'GET',
        ],
        // PUT /api/v1/ledger/{agentCode} — upsert AGENT STATUS in place (bot)
        [
            'name' => 'Ledger#update',
            'url' => '/api/v1/ledger/{agentCode}',
            'verb' => 'PUT',
        ],
        // POST /api/v1/receipt/{engineCardId} — receipt comment + optional move (bot)
        [
            'name' => 'Receipt#post',
            'url' => '/api/v1/receipt/{engineCardId}',
            'verb' => 'POST',
        ],
        // POST /api/v1/origin-note/{engineCardId} — the ONLY LLM→human-board path (bot)
        [
            'name' => 'OriginNote#post',
            'url' => '/api/v1/origin-note/{engineCardId}',
            'verb' => 'POST',
        ],
        // GET /api/v1/takeover/config — enrollment administration (admin)
        [
            'name' => 'Admin#config',
            'url' => '/api/v1/takeover/config',
            'verb' => 'GET',
        ],
        // PUT /api/v1/boards/{boardId}/enroll — enroll/update a board (admin)
        [
            'name' => 'Admin#enroll',
            'url' => '/api/v1/boards/{boardId}/enroll',
            'verb' => 'PUT',
        ],
        // POST /api/v1/push-test — fan-out test to the runner listener (admin)
        [
            'name' => 'Admin#pushTest',
            'url' => '/api/v1/push-test',
            'verb' => 'POST',
        ],
    ],
];
