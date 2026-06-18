<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsStart\Controller;

use OCA\HubsStart\AppInfo\Application;
use OCA\HubsStart\Service\FavoriterSeedService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Admin-only OCS surface for the Hubs Start dashboard.
 *
 * Exposes a single DEV/DEMO action — {@see reseed()} — that resets the
 * dashboard's demo-läge config back to its "utgångsläge" so the demo can be
 * replayed from a clean state. It writes only app-config (no PII, no case data)
 * and is idempotent: re-running just re-sets the same keys.
 *
 * ADMIN-ONLY: the action carries no #[NoAdminRequired], so the framework
 * requires an administrator session (the default for OCSController actions).
 * #[NoCSRFRequired] is set because the vanilla-JS admin button posts with the
 * OCS-APIRequest header rather than a Vue/axios CSRF interceptor.
 *
 * Effective route: POST /ocs/v2.php/apps/hubs_start/api/v1/admin/reseed
 */
class AdminController extends OCSController {

    public function __construct(
        IRequest $request,
        private readonly IConfig $config,
        private readonly LoggerInterface $logger,
        private readonly IUserSession $userSession,
        private readonly FavoriterSeedService $favoriterSeedService,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Reset the dashboard demo-läge to its utgångsläge (DEV/DEMO, ADMIN-ONLY).
     *
     * Sets three app-config values back to the demo baseline:
     *   - hubs_start.demo_mode        = '0'                  (own app)
     *   - hubs_start.default_persona  = 'socialsekreterare'  (own app)
     *   - sdkmc.hubs_start_inflode_demo = '1'                (sdkmc — the data
     *     owner; hubs_start is allowed to write another app's config here so the
     *     reseed is a single click, mirroring the CONTRACTS.md ownership split).
     *
     * Idempotent and PII-free — only config is touched, never any ärende rows.
     * Additionally (DEV/DEMO) it re-seeds the signed-in admin's "Favoriter"
     * address book with the synthetic favorites so the reset button also restores
     * the favorites surface. The seed is idempotent (existing cards are skipped)
     * and graceful (a seed failure never fails the config reset).
     *
     * @return DataResponse<int, array{ok: bool, satt: list<array{app: string, key: string, value: string}>}, array{}>
     */
    #[NoCSRFRequired]
    public function reseed(): DataResponse {
        // [app, key, value] — the demo baseline ("utgångsläge").
        $writes = [
            [Application::APP_ID, 'demo_mode', '0'],
            [Application::APP_ID, 'default_persona', 'socialsekreterare'],
            ['sdkmc', 'hubs_start_inflode_demo', '1'],
        ];

        $satt = [];
        foreach ($writes as [$app, $key, $value]) {
            $this->config->setAppValue($app, $key, $value);
            $satt[] = ['app' => $app, 'key' => $key, 'value' => $value];
        }

        // DEV/DEMO: säkra även favoriterna för den inloggade admin-användaren.
        // Idempotent + graceful — får aldrig fälla config-återställningen.
        $user = $this->userSession->getUser();
        if ($user !== null) {
            try {
                $favoriter = $this->favoriterSeedService->seed($user->getUID());
                $satt[] = ['app' => Application::APP_ID, 'key' => 'favoriter_seedade', 'value' => (string)$favoriter['created']];
                $satt[] = ['app' => Application::APP_ID, 'key' => 'favoriter_hoppade_over', 'value' => (string)$favoriter['skipped']];
            } catch (\Throwable $e) {
                $this->logger->warning('hubs_start admin reseed: favoriter-seed misslyckades (config redan satt): ' . $e->getMessage(), [
                    'exception' => $e,
                ]);
            }
        }

        $this->logger->info('hubs_start admin reseed: demo-läge återställt till utgångsläge', [
            'satt' => $satt,
        ]);

        return new DataResponse(['ok' => true, 'satt' => $satt], Http::STATUS_OK);
    }
}
