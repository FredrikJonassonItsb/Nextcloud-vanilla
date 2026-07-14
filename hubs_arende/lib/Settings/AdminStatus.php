<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Settings;

use OCA\HubsArende\Service\StatusService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Settings\ISettings;

/**
 * Admin status panel for the ärende-motor. Renders a PII-free, read-only snapshot
 * (counts + config + the datadrivna typ-registret) — the headless engine made visible.
 */
class AdminStatus implements ISettings {
    public function __construct(
        private StatusService $statusService,
        private IAppConfig $appConfig,
    ) {
    }

    public function getForm(): TemplateResponse {
        // renderAs '' (blank) → the settings framework embeds the fragment itself.
        return new TemplateResponse(
            'hubs_arende',
            'admin-status',
            [
                'status' => $this->statusService->status(),
                // AI-upptäckbarhet: brain-hälsningens läge + om bot-posting är konfigurerad.
                'brainGreeting' => trim($this->appConfig->getAppValueString('brain_greeting', 'alla')),
                'botConfigured' => trim($this->appConfig->getAppValueString('talk_bot_id', '0')) !== '0'
                    && trim($this->appConfig->getAppValueString('talk_bot_secret', '')) !== '',
            ],
            '',
        );
    }

    public function getSection(): string {
        return 'hubs_arende';
    }

    public function getPriority(): int {
        return 50;
    }
}
