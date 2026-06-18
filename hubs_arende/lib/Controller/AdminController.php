<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Controller;

use OCA\HubsArende\AppInfo\Application;
use OCA\HubsArende\Service\DemoSeedService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Admin-OCS-yta för DEV/DEMO-verktyg i ärende-motorn.
 *
 * ADMIN-ONLY: actionen saknar avsiktligt #[NoAdminRequired], så OCS-ramverket
 * kräver att anroparen är administratör (icke-admin ⇒ 403/OCS_API_REQUEST avvisas).
 * Backar "Återställ demo-data till utgångsläge"-knappen i hubs_start admin-
 * inställningar.
 *
 * Tunt transportlager: all seed-/purge-logik bor i {@see DemoSeedService} (som
 * återanvänder ArendeService/sagans publika metoder — den rörs aldrig härifrån).
 *
 * Effektivt prefix: /ocs/v2.php/apps/hubs_arende/api/v1/admin/...
 */
class AdminController extends OCSController {
    public function __construct(
        IRequest $request,
        private readonly DemoSeedService $demoSeedService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Återställ demo-data till utgångsläge: purge (alla demo-%-rader + pekare) följt
     * av seed (de 10 kurerade syntetiska ärendena). IDEMPOTENT + dev/demo-märkt.
     *
     * ADMIN-ONLY (inget #[NoAdminRequired]). #[NoCSRFRequired] eftersom anropet sker
     * som ett OCS-API-anrop (OCS-API-Request-headern bär CSRF-skyddet).
     *
     * POST /api/v1/admin/seed-demo
     *
     * @return DataResponse {ok, skapade, raderade} vid framgång, annars {ok:false, error}.
     */
    #[NoCSRFRequired]
    public function seedDemo(): DataResponse {
        try {
            $resultat = $this->demoSeedService->reseed();
            return new DataResponse([
                'ok' => true,
                'skapade' => $resultat['skapade'],
                'raderade' => $resultat['raderade'],
            ], Http::STATUS_OK);
        } catch (\Throwable $e) {
            $this->logger->error('hubs_arende admin seedDemo failed', ['exception' => $e]);
            return new DataResponse([
                'ok' => false,
                'error' => 'seed_demo_failed',
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
