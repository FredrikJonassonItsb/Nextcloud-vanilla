<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsStart\Service;

use OCP\IConfig;
use OCP\IGroupManager;

/**
 * Simplified role profiles (phase 1): three fixed profiles resolved from group
 * membership. The full configurable role-profile engine is deferred to phase 2.
 *
 * Profiles:
 *  - 'forvaltare'  — administrators / system managers (extra "Systemhälsa" section)
 *  - 'registrator' — high-volume triage users (keyboard mode hint)
 *  - 'handlaggare' — default base profile
 *
 * Group→profile mapping is admin-configurable via app config keys
 * (hubs_start: group_forvaltare / group_registrator). Sensible defaults apply.
 */
class RoleService {

    public const PROFILE_FORVALTARE = 'forvaltare';
    public const PROFILE_REGISTRATOR = 'registrator';
    public const PROFILE_HANDLAGGARE = 'handlaggare';

    private const DEFAULT_FORVALTARE_GROUP = 'admin';
    private const DEFAULT_REGISTRATOR_GROUP = 'hubs-registrator';

    public function __construct(
        private IGroupManager $groupManager,
        private IConfig $config,
    ) {
    }

    public function getProfile(?string $userId): string {
        if ($userId === null) {
            return self::PROFILE_HANDLAGGARE;
        }

        $forvaltareGroup = $this->config->getAppValue('hubs_start', 'group_forvaltare', self::DEFAULT_FORVALTARE_GROUP);
        $registratorGroup = $this->config->getAppValue('hubs_start', 'group_registrator', self::DEFAULT_REGISTRATOR_GROUP);

        if ($this->groupManager->isInGroup($userId, $forvaltareGroup)) {
            return self::PROFILE_FORVALTARE;
        }
        if ($registratorGroup !== '' && $this->groupManager->isInGroup($userId, $registratorGroup)) {
            return self::PROFILE_REGISTRATOR;
        }
        return self::PROFILE_HANDLAGGARE;
    }
}
