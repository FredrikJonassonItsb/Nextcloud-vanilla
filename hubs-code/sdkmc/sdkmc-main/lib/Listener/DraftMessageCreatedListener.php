<?php

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Listener;

use OCP\Log\Audit\CriticalActionPerformedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCA\Mail\Events\DraftMessageCreatedEvent;

/**
 * @implements IEventListener<Event>
 */
class DraftMessageCreatedListener implements IEventListener {
    public function __construct(
        private IEventDispatcher $eventDispatcher,
    ) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof DraftMessageCreatedEvent) {
            return;
        }
        $draft = $event->getDraft();
        if (is_null($draft)) {
            return;
        }
        $this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent('Draft %s is now known as database id %s', [ $draft->getMessageId(), $draft->getId() ]));
    }
}
