<?php

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Listener;

use OCA\SdkMc\Db\MessageMetadataMapper;
use OCA\SdkMc\Event\ScheduleEmailSendEvent;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use OCA\Mail\Db\MailAccountMapper;

/**
 * @implements IEventListener<Event>
 */
class ScheduleEmailSendListener implements IEventListener {
    public function __construct(
        private MessageMetadataMapper $mapper,
        private IEventDispatcher $eventDispatcher,
        private MailAccountMapper $mailAccountMapper,
        private string $userId,
    ) {
    }

    public function handle(Event $event): void {
        if (!($event instanceof ScheduleEmailSendEvent)) {
            return;
        }

        $message = &$event->getMessage();

        try {
            $mm = $this->mapper->getByMessage($message->getId());
            $messageType = $mm->getMessageType();
        } catch (DoesNotExistException $e) {
            $messageType = 'unknown';
        }

        $account = $this->mailAccountMapper->findById($message->getAccountId());
        $logLine = 'User %s scheduled draft id %s of type %s in %s to be sent at %s';
        $logParams = [$this->userId, (string)($message->getId()), $messageType, $account->getEmail(), date('Y-m-d H:i:sP', $message->getSendAt() ?? 0)];
        $this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent($logLine, $logParams));
    }
}
