<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Listener;

use OCP\AppFramework\Http\Events\BeforeLoginTemplateRenderedEvent;
use OCP\AppFramework\Services\IAppConfig;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * @implements IEventListener<Event>
 */
class LoginStyleListener implements IEventListener {
    public function __construct(
        private IAppConfig $appConfig,
    ) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof BeforeLoginTemplateRenderedEvent) {
            return;
        }

        if (!$this->appConfig->getAppValueBool('hideDefaultLoginLink', true)) {
            return;
        }

        Util::addStyle('sdkmc', 'login');
    }
}
