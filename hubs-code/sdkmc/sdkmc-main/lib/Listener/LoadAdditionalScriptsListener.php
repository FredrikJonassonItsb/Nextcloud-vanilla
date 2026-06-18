<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2025 ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Listener;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;
use OCP\AppFramework\Services\IInitialState;
use OCP\AppFramework\Services\IAppConfig;
use OCA\SdkMc\Service\UpgradeLoginService;

/**
 * @implements IEventListener<Event>
 */
class LoadAdditionalScriptsListener implements IEventListener {
    public function __construct(
        private string $appName,
        private IInitialState $initialState,
        private IAppConfig $appConfig,
        private UpgradeLoginService $service,
    ) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof LoadAdditionalScriptsEvent) {
            return;
        }
        $settings = [
            'loa3Tag' => $this->appConfig->getAppValueString('loa3Tag'),
            'loginSecurity' => $this->service->getLoginStrength(),
        ];

        $this->initialState->provideInitialState('loaSettings', $settings);

        Util::addScript($this->appName, 'loa3');
    }
}
