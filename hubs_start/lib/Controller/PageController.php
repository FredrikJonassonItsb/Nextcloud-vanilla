<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsStart\Controller;

use OCA\HubsStart\AppInfo\Application;
use OCA\HubsStart\Service\AppDetectionService;
use OCA\HubsStart\Service\PreferencesService;
use OCA\HubsStart\Service\RoleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\IRequest;
use OCP\Util;

/**
 * Renders the Hubs Start SPA and injects the boot state (no extra XHR on first
 * paint). Live data is then pulled from sdkmc's OCS summary endpoint.
 */
class PageController extends Controller {

    public function __construct(
        string $appName,
        IRequest $request,
        private IInitialState $initialState,
        private AppDetectionService $appDetection,
        private RoleService $roleService,
        private PreferencesService $preferences,
        private IConfig $config,
        private ?string $userId,
    ) {
        parent::__construct($appName, $request);
    }

    #[NoCSRFRequired]
    #[NoAdminRequired]
    public function index(): TemplateResponse {
        if ($this->isDemoMode()) {
            // DEMO MODE (stub): the sibling apps aren't installed on this instance,
            // so render the UI from in-memory fixtures (see src/services/demoData.js
            // and DEMO.md). All apps/channels are forced present so every widget shows.
            $boot = [
                'demoMode' => true,
                'apps' => ['sdkmc' => true, 'mail' => true, 'spreed' => true, 'calendar' => true, 'securemail' => true],
                'profile' => 'forvaltare',
                'channelCoverage' => ['sdk', 'secure', 'internal', 'fax', 'sms'],
                // Demo: skip onboarding by default so reloads land on the dashboard
                // (the onboarding flow still exists; flip to false to showcase it).
                'prefs' => ['onboardingSeen' => true, 'keyboardMode' => false],
                'loa' => 'LOA3',
            ];
        } else {
            $boot = [
                'demoMode' => false,
                'apps' => $this->appDetection->detect(),
                'profile' => $this->roleService->getProfile($this->userId),
                'channelCoverage' => $this->appDetection->channelCoverage(),
                'prefs' => $this->preferences->get($this->userId),
                // LOA is refreshed live from sdkmc getSettings; provide a safe default.
                'loa' => 'LOA3',
                // Optional landing persona (app-config 'default_persona'). On an
                // instance where the engine (hubs_arende) owns the ärende data, set
                // this to 'socialsekreterare' so the dashboard lands directly on the
                // engine-backed "Mina ärenden" view. Empty → store's defaultPersonaId.
                'persona' => $this->config->getAppValue(Application::APP_ID, 'default_persona', '') ?: null,
            ];
        }
        $this->initialState->provideInitialState('boot', $boot);

        Util::addScript(Application::APP_ID, Application::APP_ID . '-main');

        $response = new TemplateResponse(Application::APP_ID, 'index');

        // The SPA talks to sibling apps over same-origin OCS/index.php only.
        $csp = new ContentSecurityPolicy();
        $response->setContentSecurityPolicy($csp);

        return $response;
    }

    /**
     * Demo mode shows the UI from fixtures (no sibling-app backend). Resolution:
     *   - app-config hubs_start/demo_mode = '1'  → forced ON
     *   - app-config hubs_start/demo_mode = '0'  → forced OFF
     *   - otherwise: AUTO — ON when the data owner (sdkmc) is not installed.
     */
    private function isDemoMode(): bool {
        $flag = $this->config->getAppValue(Application::APP_ID, 'demo_mode', '');
        if ($flag === '1') {
            return true;
        }
        if ($flag === '0') {
            return false;
        }
        $apps = $this->appDetection->detect();
        return empty($apps['sdkmc']);
    }
}
