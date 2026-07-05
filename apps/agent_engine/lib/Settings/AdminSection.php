<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Settings;

use OCA\AgentEngine\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * Admin-settings section for the (otherwise headless) Agent Engine, so the
 * routing map, enrolled boards and engine health are visible in the UI.
 * Admin-only by virtue of being an admin settings section.
 */
class AdminSection implements IIconSection {
    public function __construct(
        private IURLGenerator $url,
        private IL10N $l,
    ) {
    }

    public function getID(): string {
        return 'agent-engine';
    }

    public function getName(): string {
        return $this->l->t('Agent Engine');
    }

    public function getPriority(): int {
        return 80;
    }

    public function getIcon(): string {
        return $this->url->imagePath(Application::APP_ID, 'app.svg');
    }
}
