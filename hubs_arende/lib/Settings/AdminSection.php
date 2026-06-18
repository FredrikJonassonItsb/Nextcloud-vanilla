<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * Admin-sidebar section for the (otherwise headless) ärende-motor, so its status is
 * visible in the UI. Admin-only by virtue of being an admin settings section.
 */
class AdminSection implements IIconSection {
    public function __construct(
        private IURLGenerator $url,
        private IL10N $l,
    ) {
    }

    public function getID(): string {
        return 'hubs_arende';
    }

    public function getName(): string {
        return $this->l->t('Hubs Ärende');
    }

    public function getPriority(): int {
        return 80;
    }

    public function getIcon(): string {
        // Reuse a core icon (the app ships no own admin-section asset).
        return $this->url->imagePath('core', 'actions/info.svg');
    }
}
