<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: 2025 ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;
use OCP\WorkflowEngine\Events\RegisterOperationsEvent;

/**
 * @implements IEventListener<Event>
 */
class RegisterOperationsListener implements IEventListener {
    public function __construct(
        private string $appName,
    ) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof RegisterOperationsEvent) {
            return;
        }
        Util::addScript($this->appName, 'loa3');
    }
}
