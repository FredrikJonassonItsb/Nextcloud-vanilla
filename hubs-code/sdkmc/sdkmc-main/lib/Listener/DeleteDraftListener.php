<?php

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Listener;

use OCA\SdkMc\Db\MessageMetadataMapper;
use OCA\SdkMc\Event\DeleteDraftEvent;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use OCA\Mail\Db\MailAccountMapper;

/**
 * @implements IEventListener<Event>
 */
class DeleteDraftListener implements IEventListener {
    public function __construct(
        private MessageMetadataMapper $mapper,
        private IEventDispatcher $eventDispatcher,
        private MailAccountMapper $mailAccountMapper,
    ) {
    }

    public function handle(Event $event): void {
        if (!($event instanceof DeleteDraftEvent)) {
            return;
        }

        try {
            $account = $this->mailAccountMapper->findById($event->getMessage()->getAccountId());
            $this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent('Draft with draft id %s was deleted by %s for %s', [$event->getMessage()->getId(), $account->getUserId(), $account->getEmail()]));
            $this->mapper->deleteByMessage($event->getMessage()->getId());
        } catch (DoesNotExistException $e) {
            // if we dont have any data stored its better to just not crash
        }
    }
}
