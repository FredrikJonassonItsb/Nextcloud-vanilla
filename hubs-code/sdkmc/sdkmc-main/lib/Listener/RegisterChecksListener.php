<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Micke Nordin <kano@sunet.se>
 * SPDX-FileCopyrightText: 2025 ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Listener;

use OCA\SdkMc\Check\Loa3;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;
use OCP\WorkflowEngine\Events\RegisterChecksEvent;

/**
 * @implements IEventListener<Event>
 */
class RegisterChecksListener implements IEventListener {
    public function __construct(
        private Loa3 $loa3Check,
        private string $appName,
    ) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof RegisterChecksEvent) {
            return;
        }
        $event->registerCheck($this->loa3Check);
        Util::addScript($this->appName, 'loa3');
    }
}
