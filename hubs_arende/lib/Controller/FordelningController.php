<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * NEW FILE for the hubs_arende app. Target: lib/Controller/FordelningController.php
 *
 * HUBS-START BACKEND-ADDITION · ÄRENDE-DOMÄN · Target: lib/Controller/FordelningController.php
 *
 * The read-side OCS surface the redesigned hubs_start dashboard binds to for the
 * gruppledare's fördelningsvy and the verified Treserva-kvittens-/retention-yta.
 * Purely additive: it only reads through ArendeService's new additive methods
 * (fordelningSummary / treservaReceipts) and never touches the saga/commit/ACL
 * write paths.
 */

namespace OCA\HubsArende\Controller;

use OCA\HubsArende\AppInfo\Application;
use OCA\HubsArende\Service\ArendeService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;

/**
 * OCS read surface for the hubs_start dashboard's fördelnings- and
 * kvittens-/retention-vyer.
 *
 * Thin transport: it delegates ALL aggregation to {@see ArendeService} (the saga
 * single-writer / engine-honest read mapper) and shapes nothing here beyond
 * forwarding the well-formed result. The service applies object-level authz
 * (enhet) per row, so an unauthorised caller simply sees fewer rows — existence
 * of other enheter's cases is never leaked.
 *
 * GRACEFUL: ArendeService's two methods already return a valid, empty-but-well-
 * formed shape on any error or missing source (dev15 without a live feed), so a
 * missing datakälla degrades to an empty fördelningsvy / receipt list — NEVER a
 * 500, NEVER fabricated PII (OSL 26 kap.).
 *
 * Effective prefix: /ocs/v2.php/apps/hubs_arende/api/v1/...
 *
 * Routes (appended to hubs_arende appinfo/routes.php under 'ocs'):
 *   ['name' => 'Fordelning#fordelningSummary', 'url' => '/api/v1/fordelning-summary', 'verb' => 'GET'],
 *   ['name' => 'Fordelning#treservaReceipts',  'url' => '/api/v1/treserva/receipts',  'verb' => 'GET'],
 */
class FordelningController extends OCSController {
    public function __construct(
        IRequest $request,
        private readonly ArendeService $arendeService,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Gruppledarens fördelningsvy: OTILLDELADE ärenden (status='otilldelat')
     * grupperade för distribution + tunn handläggar-belastning. Engine-honest +
     * pseudonymt — never innehåll (OSL 26 kap.).
     *
     * Returns the shape ssDemo.fetchFordelningSummary declares:
     *   { attFordela: Card[], utredare: [{namn,aktiva,roda,naraTak}], mottagningPagaende:int }
     *
     * GET /api/v1/fordelning-summary
     *
     * @return DataResponse<Http::STATUS_OK, array<string,mixed>, array{}>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function fordelningSummary(): DataResponse {
        // ArendeService::fordelningSummary() is fully self-defending (its own
        // try/catch returns a valid empty shape), so we never need to translate an
        // exception to a 500 here — the dashboard always renders.
        return new DataResponse($this->arendeService->fordelningSummary(), Http::STATUS_OK);
    }

    /**
     * Verifierade Treserva-kvittenser: the committed cases
     * (provenance='registrerad') surfaced as kvittens-rader. Each carries a
     * verified facksystem dnr + a retention deadline (gallrasDatum).
     *
     * Returns the shape ssDemo.fetchReceipts / treserva.listReceipts expect:
     *   [{ id, hubsCaseId, dnr, barnRef, typ, committedAt, gallrasDatum, verifierad, kalla }]
     *
     * GET /api/v1/treserva/receipts
     *
     * @return DataResponse<Http::STATUS_OK, list<array<string,mixed>>, array{}>
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function treservaReceipts(): DataResponse {
        // Self-defending in the service (valid empty list on any error) → never 500.
        return new DataResponse($this->arendeService->treservaReceipts(), Http::STATUS_OK);
    }
}
