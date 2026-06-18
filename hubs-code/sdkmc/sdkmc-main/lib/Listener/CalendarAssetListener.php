<?php

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Listener;

use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\Util;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IRequest;
use OCP\AppFramework\Services\IAppConfig;

/**
 * @implements IEventListener<BeforeTemplateRenderedEvent>
 */
class CalendarAssetListener implements IEventListener {
    public function __construct(
        private IAppConfig $appConfig,
        private IRequest $request,
    ) {
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function handle(Event $event): void {
        $uri = $this->request->getPathInfo();

        if ($uri === false || !str_starts_with($uri, '/apps/calendar/')) {
            return;
        }

        if (!$this->appConfig->getAppValueBool('secureMeetingsEnabled', true)) {
            return;
        }

        Util::addScript('sdkmc', 'calendar-sms', 'calendar');
    }
}
