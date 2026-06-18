<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsStart\Service;

use OCA\HubsStart\AppInfo\Application;
use OCP\IConfig;

/**
 * Per-user UI preferences that ARE actually consumed by the SPA (onboarding seen,
 * keyboard mode opt-in). Deliberately minimal — gov-portal shipped a dead
 * settings API; we only persist what the UI reads back.
 */
class PreferencesService {

    private const KEY_ONBOARDING_SEEN = 'onboarding_seen';
    private const KEY_KEYBOARD_MODE = 'keyboard_mode';

    public function __construct(
        private IConfig $config,
    ) {
    }

    /**
     * @return array{onboardingSeen: bool, keyboardMode: bool}
     */
    public function get(?string $userId): array {
        if ($userId === null) {
            return ['onboardingSeen' => false, 'keyboardMode' => false];
        }
        return [
            'onboardingSeen' => $this->config->getUserValue($userId, Application::APP_ID, self::KEY_ONBOARDING_SEEN, '0') === '1',
            'keyboardMode' => $this->config->getUserValue($userId, Application::APP_ID, self::KEY_KEYBOARD_MODE, '0') === '1',
        ];
    }

    /**
     * Apply a partial update and return the full, current preference set.
     *
     * @param array<string,mixed> $partial subset of { onboardingSeen, keyboardMode }
     * @return array{onboardingSeen: bool, keyboardMode: bool}
     */
    public function update(string $userId, array $partial): array {
        if (array_key_exists('onboardingSeen', $partial)) {
            $this->config->setUserValue($userId, Application::APP_ID, self::KEY_ONBOARDING_SEEN, $partial['onboardingSeen'] ? '1' : '0');
        }
        if (array_key_exists('keyboardMode', $partial)) {
            $this->config->setUserValue($userId, Application::APP_ID, self::KEY_KEYBOARD_MODE, $partial['keyboardMode'] ? '1' : '0');
        }
        return $this->get($userId);
    }
}
