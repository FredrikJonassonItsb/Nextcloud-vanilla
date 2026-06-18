<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsStart\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Hubs Start — Flödesnavet.
 *
 * Thin portal application. It renders a Vue SPA (PageController) and exposes a
 * tiny preferences OCS API. The dashboard widgets that mirror "Att hantera" and
 * "Kvittenser" into the standard Nextcloud dashboard are intentionally NOT
 * registered here — they live in sdkmc (the data owner) to avoid cross-app
 * coupling. See backend-additions/sdkmc.
 *
 * @psalm-suppress UnusedClass
 */
class Application extends App implements IBootstrap {
    public const APP_ID = 'hubs_start';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        // No services need explicit registration: controllers and services are
        // auto-wired by the AppFramework DI container from their constructors.
    }

    public function boot(IBootContext $context): void {
    }
}
