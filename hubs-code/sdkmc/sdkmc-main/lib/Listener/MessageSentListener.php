<?php

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Listener;

use OCA\SdkMc\Db\MessageMetadataMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use OCA\Mail\Events\MessageSentEvent;
use OCA\SdkMc\Event\DraftSentEvent;
use OCA\SdkMc\Service\MessageTypeService;

/**
 * @implements IEventListener<Event>
 */
class MessageSentListener implements IEventListener {
    public function __construct(
        private MessageMetadataMapper $mapper,
        private IEventDispatcher $eventDispatcher,
        private MessageTypeService $messageTypeService,
    ) {
    }

    public function handle(Event $event): void {
        if (!($event instanceof MessageSentEvent || $event instanceof DraftSentEvent)) {
            return;
        }

        $message = &$event->getLocalMessage();

        try {
            $mm = $this->mapper->getByMessage($message->getId());
            $messageType = $mm->getMessageType();
        } catch (DoesNotExistException $e) {
            $messageType = 'unknown';
        }

        $account = &$event->getAccount();
        $messageId = $this->messageTypeService->fetchHeader($message->getRaw() ?? '', 'Message-ID');
        $verb = $event instanceof MessageSentEvent ? 'sent' : 'saved';
        $logLine = 'User %s had their Draft Id %s of type %s in %s ' . $verb . ' with message Id %s';
        $logParams = [$account->getUserId(), (string)($message->getId()), $messageType, $account->getEmail(), $messageId];
        $this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent($logLine, $logParams));
    }
}
