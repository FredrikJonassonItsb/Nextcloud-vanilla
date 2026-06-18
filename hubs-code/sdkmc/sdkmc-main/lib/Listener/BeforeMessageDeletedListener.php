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
use OCA\Mail\Events\BeforeMessageDeletedEvent;
use OCA\Mail\Db\MessageMapper;
use OCA\Mail\Db\MailboxMapper;

/**
 * @implements IEventListener<Event>
 */
class BeforeMessageDeletedListener implements IEventListener {
    public function __construct(
        private IEventDispatcher $eventDispatcher,
        private MessageMapper $messageMapper,
        private MailboxMapper $mailboxMapper,
    ) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof BeforeMessageDeletedEvent) {
            return;
        }
        $account = &$event->getAccount();
        $folderId = $event->getFolderId();
        $messageUid = $event->getMessageId();
        $mailbox = $this->mailboxMapper->find($account, $folderId);
        $messages = $this->messageMapper->findByUids($mailbox, [$messageUid]);
        if (count($messages) !== 1) {
            return; // this should never happen
        }
        $message = end($messages);
        $messageId = $message->getMessageId();
        $type = $folderId === 'Drafts' ? 'draft' : 'message';

        $this->eventDispatcher->dispatchTyped(
            new CriticalActionPerformedEvent(
                '%s deleted ' . $type . ' with id %s from %s in %s',
                [
                    $account->getUserId(),
                    $messageId,
                    $folderId,
                    $account->getEMailAddress(),
                ]
            )
        );
    }
}
