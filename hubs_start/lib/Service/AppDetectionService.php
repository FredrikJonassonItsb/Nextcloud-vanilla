<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsStart\Service;

use OCP\App\IAppManager;

/**
 * Detects which Hubs sibling apps are installed/enabled so the dashboard renders
 * only the widgets/channels that exist in this particular installation (the
 * product ships in different configurations).
 */
class AppDetectionService {

    /** Hubs functional apps we look for. */
    private const HUBS_APPS = ['sdkmc', 'mail', 'spreed', 'calendar'];

    /** Channel id keyed by the app that provides it (securemail rides on mail/sdkmc). */
    private const CHANNEL_BY_APP = [
        'sdkmc' => ['sdk'],
        'mail' => ['secure', 'internal', 'fax', 'sms'],
    ];

    public function __construct(
        private IAppManager $appManager,
    ) {
    }

    /**
     * @return array<string,bool> map appId → enabled
     */
    public function detect(): array {
        $result = [];
        foreach (self::HUBS_APPS as $appId) {
            $result[$appId] = $this->appManager->isEnabledForUser($appId);
        }
        // securemail is a sibling service, not an NC app; presence implied by sdkmc.
        $result['securemail'] = $result['sdkmc'] ?? false;
        return $result;
    }

    /**
     * Channels actually available given installed apps. Used for the honest
     * "dessa kanaler bevakas" coverage declaration in the queue.
     *
     * @return list<string>
     */
    public function channelCoverage(): array {
        $apps = $this->detect();
        $channels = [];
        foreach (self::CHANNEL_BY_APP as $appId => $provided) {
            if (!empty($apps[$appId])) {
                $channels = array_merge($channels, $provided);
            }
        }
        return array_values(array_unique($channels));
    }
}
