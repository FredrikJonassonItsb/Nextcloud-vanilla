<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * HUBS-START BACKEND-ADDITION · KOMPLETT 'ocs'-ROUTEBLOCK FÖR sdkmc
 *
 * DETTA ÄR ÅTERSTÄLLNINGS-KÄLLAN: sdkmc:s appinfo/routes.php lever i apps/ i
 * containern och RENSAS vid container-recreate/`itsl deploy`/`docker restart
 * hubs-php`. Efter en wipe: klistra in HELA 'ocs'-nyckeln nedan i den
 * återställda routes.php (som HUBS-START-ADD-block) — rekonstruera ALDRIG ur
 * spridda snippets.
 *
 * Filen är inte tänkt att inkluderas som den är; den dokumenterar det exakta,
 * kompletta blocket (16 rutter, 2026-07-03). Håll den i synk med varje ny
 * OCS-controller.
 */

return [
    'ocs' => [
        // --- Bas (2026-06-13) -------------------------------------------------
        ['name' => 'OCS\\Summary#summary', 'url' => '/api/v1/summary', 'verb' => 'GET'],
        ['name' => 'OCS\\Summary#receipts', 'url' => '/api/v1/receipts', 'verb' => 'GET'],
        ['name' => 'OCS\\Recipient#search', 'url' => '/api/v1/recipients/search', 'verb' => 'GET'],
        ['name' => 'OCS\\Recipient#classify', 'url' => '/api/v1/recipients/classify', 'verb' => 'GET'],
        ['name' => 'OCS\\SecureMeeting#create', 'url' => '/api/v1/secure-meeting', 'verb' => 'POST'],
        ['name' => 'OCS\\Meeting#today', 'url' => '/api/v1/meetings/today', 'verb' => 'GET'],
        ['name' => 'OCS\\Meeting#lobby', 'url' => '/api/v1/meetings/{token}/lobby', 'verb' => 'GET'],

        // --- Fas 2 (2026-06-17) ----------------------------------------------
        ['name' => 'OCS\\Team#index', 'url' => '/api/v1/team', 'verb' => 'GET'],
        ['name' => 'OCS\\Favoriter#index', 'url' => '/api/v1/favoriter', 'verb' => 'GET'],
        ['name' => 'OCS\\InflodeFeed#summary', 'url' => '/api/v1/inflode-summary', 'verb' => 'GET'],
        ['name' => 'OCS\\InflodeFeed#action', 'url' => '/api/v1/inflode/{action}', 'verb' => 'POST'],

        // --- Fas 2d (2026-06-19) ---------------------------------------------
        ['name' => 'OCS\\NoteToSelf#index', 'url' => '/api/v1/note-to-self', 'verb' => 'GET'],
        ['name' => 'OCS\\NoteToSelf#create', 'url' => '/api/v1/note-to-self', 'verb' => 'POST'],
        ['name' => 'OCS\\ArendeEnrichment#show', 'url' => '/api/v1/arende-enrichment', 'verb' => 'GET'],

        // --- Kort-flikarnas läsytor (2026-07-03) -------------------------------
        // Ärendets meddelanden (case:-taggen, mailbox-ACL) — "Meddelanden"-fliken.
        ['name' => 'OCS\\CaseMessages#index', 'url' => '/api/v1/case-messages', 'verb' => 'GET'],
        // Ärendets bokade möten (dnr-märkning i CalDAV) — "Möten"-fliken.
        ['name' => 'OCS\\Meeting#forCase', 'url' => '/api/v1/arende-meetings', 'verb' => 'GET'],
    ],
];
