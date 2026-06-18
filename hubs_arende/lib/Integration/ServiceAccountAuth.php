<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Integration;

use OCP\AppFramework\Services\IAppConfig;

/**
 * Seam A — server-to-server credential for the saga's internal OCS calls.
 *
 * The integration clients (R3–R9: sdkmc-tag / Groupfolder / Deck / Spreed / Calendar)
 * call NEIGHBOUR apps' OCS APIs over HTTP (ExApp-rent: in-process now, ExApp later — so
 * NOT in-process service calls). Those APIs are session-authenticated; an in-process /
 * CLI / cron saga carries no session, so the calls 401 and the clients no-op.
 *
 * This provider supplies a Basic-auth Authorization header for a dedicated SERVICE
 * ACCOUNT (an NC user + app-password). The account itself is provisioned out-of-band
 * (see provision/service-account.md) and the two values live in app-config:
 *   - hubs_arende.sa_user   (service-account uid, e.g. 'hubs-arende-svc')
 *   - hubs_arende.sa_token  (its app-password — stored sensitive)
 *
 * SÄKER DEGRADERING: om credentialen INTE är konfigurerad returnerar {@see authorizationHeader()}
 * null → klienterna skickar ingen Authorization → kallet 401:ar och sväljs graceful, EXAKT som
 * idag. Seam A ändrar alltså inget beteende förrän kontot provisionerats — då (och först då)
 * börjar sagan skapa riktiga rum.
 */
class ServiceAccountAuth {
    public const CONFIG_KEY_USER = 'sa_user';
    public const CONFIG_KEY_TOKEN = 'sa_token';

    public function __construct(
        private IAppConfig $appConfig,
    ) {
    }

    /**
     * The Basic Authorization header value for the service account, or null when the
     * credential is not configured (⇒ clients degrade to the current graceful no-op).
     */
    public function authorizationHeader(): ?string {
        $user = trim($this->appConfig->getAppValueString(self::CONFIG_KEY_USER, ''));
        $token = trim($this->appConfig->getAppValueString(self::CONFIG_KEY_TOKEN, ''));
        if ($user === '' || $token === '') {
            return null;
        }
        return 'Basic ' . base64_encode($user . ':' . $token);
    }

    /** True when a service-account credential is configured (the saga can create real rooms). */
    public function isConfigured(): bool {
        return $this->authorizationHeader() !== null;
    }
}
