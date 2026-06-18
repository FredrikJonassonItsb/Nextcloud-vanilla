<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Hubs Start route table.
 *
 * The app is intentionally thin on the backend: the page route renders the
 * Vue SPA, and a small OCS API persists per-user UI preferences (onboarding
 * seen flag, keyboard-mode opt-in). ALL live business data is fetched from
 * sdkmc's OCS endpoints (see backend-additions/sdkmc) — this app never
 * duplicates the status model. See docs/CONTRACTS.md.
 */
return [
    'routes' => [
        // SPA page
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
    ],
    'ocs' => [
        // Per-user UI preferences (read on boot, written by onboarding / settings)
        ['name' => 'preferences#get', 'url' => '/api/v1/preferences', 'verb' => 'GET'],
        ['name' => 'preferences#update', 'url' => '/api/v1/preferences', 'verb' => 'PUT'],
        // ADMIN-ONLY (DEV/DEMO): reset demo-läge config to utgångsläge.
        // POST /ocs/v2.php/apps/hubs_start/api/v1/admin/reseed
        ['name' => 'Admin#reseed', 'url' => '/api/v1/admin/reseed', 'verb' => 'POST'],
    ],
];
