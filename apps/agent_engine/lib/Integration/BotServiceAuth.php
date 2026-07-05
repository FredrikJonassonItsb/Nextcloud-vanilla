<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Integration;

use OCA\AgentEngine\Protocol;
use OCP\AppFramework\Services\IAppConfig;

/**
 * Service credential for the engine's Deck/Talk API calls — the `bot-engine`
 * NC user + app password (CONTRACTS §1, §8: BOT_APP_PASSWORD_ENGINE, written
 * into app-config by occ-provision.sh):
 *
 *   occ config:app:set agent_engine bot_user  --value bot-engine
 *   occ config:app:set agent_engine bot_token --value <app-password>
 *
 * Same seam pattern as hubs_arende ServiceAccountAuth: unconfigured ⇒ null ⇒
 * calls go out unauthenticated, 401, and are surfaced as failures (the engine
 * does NOT degrade silently here — takeover/claim are correctness paths).
 */
class BotServiceAuth {
    public const CONFIG_KEY_USER = 'bot_user';
    public const CONFIG_KEY_TOKEN = 'bot_token';

    public function __construct(
        private IAppConfig $appConfig,
    ) {
    }

    public function authorizationHeader(): ?string {
        $user = trim($this->appConfig->getAppValueString(self::CONFIG_KEY_USER, Protocol::ENGINE_BOT));
        $token = trim($this->appConfig->getAppValueString(self::CONFIG_KEY_TOKEN, ''));
        if ($user === '' || $token === '') {
            return null;
        }
        return 'Basic ' . base64_encode($user . ':' . $token);
    }

    public function isConfigured(): bool {
        return $this->authorizationHeader() !== null;
    }

    /** The uid the engine writes as (actor-filter member on both boards). */
    public function serviceUid(): string {
        return trim($this->appConfig->getAppValueString(self::CONFIG_KEY_USER, Protocol::ENGINE_BOT));
    }
}
