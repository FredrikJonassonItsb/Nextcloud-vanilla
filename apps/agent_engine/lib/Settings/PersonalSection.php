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
 * Personal-settings section "Min agent" — where a user sees their agent
 * connection and activates/deactivates their own Deck boards (§2.9, §2.10).
 */
class PersonalSection implements IIconSection {
    public function __construct(
        private IURLGenerator $url,
        private IL10N $l,
    ) {
    }

    public function getID(): string {
        return 'agent-engine';
    }

    public function getName(): string {
        return $this->l->t('Min agent');
    }

    public function getPriority(): int {
        return 75;
    }

    public function getIcon(): string {
        return $this->url->imagePath(Application::APP_ID, 'app.svg');
    }
}
