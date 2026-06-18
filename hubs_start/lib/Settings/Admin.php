<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsStart\Settings;

use OCA\HubsStart\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * Admin settings: map groups to the simplified role profiles and show how to set
 * Hubs Start as the default landing app.
 */
class Admin implements ISettings {

    public function __construct(
        private IConfig $config,
    ) {
    }

    public function getForm(): TemplateResponse {
        // Vanilla-JS handler for the "Återställ demo-data"-knappen. Plain script
        // in js/ — no webpack bundle — so it runs on the server-rendered admin page.
        Util::addScript(Application::APP_ID, Application::APP_ID . '-admin-reseed');

        $parameters = [
            'group_forvaltare' => $this->config->getAppValue(Application::APP_ID, 'group_forvaltare', 'admin'),
            'group_registrator' => $this->config->getAppValue(Application::APP_ID, 'group_registrator', 'hubs-registrator'),
        ];
        return new TemplateResponse(Application::APP_ID, 'admin', $parameters);
    }

    public function getSection(): string {
        return 'hubs_start';
    }

    public function getPriority(): int {
        return 50;
    }
}
