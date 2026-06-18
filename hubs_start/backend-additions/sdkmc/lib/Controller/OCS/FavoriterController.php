<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * HUBS-START BACKEND-ADDITION · UPSTREAM-KANDIDAT · Target: lib/Controller/OCS/FavoriterController.php
 *
 * NEW FILE for the sdkmc app. The OCS surface for Kontakter-favoriter — the thin
 * sdkmc resolver layer above the Contacts app (hubs_start/docs/KONTAKTER-FAVORITER.md
 * §3.2). The dashboard's `api.fetchFavoriter()` (see src/services/demo/favoriter.js
 * for the contract it mirrors) talks only to this one action. ONE server-side
 * aggregation pass over the favorite address books, no client fan-out.
 */

namespace OCA\SdkMc\Controller\OCS;

use OCA\SdkMc\Service\FavoriterService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hubs Start — resolved contacts-favorites OCS endpoint.
 *
 * A favorite is a POINTER, not a copy: the favorite vCard carries a stable key
 * (X-HUBS-SDK-REF / X-HUBS-USER-REF) plus a non-authoritative display cache, and
 * {@see FavoriterService} reads the explicit favorite address books over
 * OCP\Contacts\IManager and shapes each pointer into the resolved DTO the
 * FavoritValjare renders. DIGG / user-directory truth is never copied into the
 * favorite (KONTAKTER-FAVORITER §2.1).
 *
 * Returns the favorite DTO shape declared in src/services/demo/favoriter.js:
 *   { id, klass, listor:[…], namn, org?, kanal, sdkRef?, userRef?, adress?, fax?,
 *     owner?, identitet?{badge,verifierad}, narvaro?, resolvedAt, stale, removed,
 *     proveniens }
 *
 * Routes (to be appended to sdkmc appinfo/routes.php under 'ocs'):
 *   ['name' => 'OCS\\Favoriter#index', 'url' => '/api/v1/favoriter', 'verb' => 'GET'],
 */
class FavoriterController extends OCSController {

    public function __construct(
        string $appName,
        IRequest $request,
        private FavoriterService $favoriterService,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Resolved favorite aggregate (personlig ∪ funktions-delad) for the signed-in
     * user — one server-side call.
     *
     * @param ?string $lista Optional scope filter ('personlig' | 'mottagningen@' | …).
     *                       Null/empty = the union of all favorite lists.
     * @return DataResponse<Http::STATUS_OK|Http::STATUS_UNAUTHORIZED, list<array<string, mixed>>, array{}>
     */
    #[NoAdminRequired]
    public function index(?string $lista = null): DataResponse {
        if ($this->userSession->getUser() === null) {
            return new DataResponse([], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $favoriter = $this->favoriterService->getFavoriter($lista !== '' ? $lista : null);
        } catch (Throwable $e) {
            $this->logger->error('[hubs-start] favoriter resolve failed: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            // Never break the composer recipient picker: an empty-but-valid list is
            // the honest fallback (no source / no favorites → no fabricated PII).
            $favoriter = [];
        }

        return new DataResponse($favoriter);
    }
}
