<?php

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Listener;

use OCA\SdkMc\Service\MessageTypeService;
use OCA\SdkMc\Event\SerializeMailMessageEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Exception;

/**
 * @implements IEventListener<Event>
 */
class SerializeMailMessageListener implements IEventListener {
    public function __construct(
        private MessageTypeService $messageTypeService,
    ) {
    }

    public function handle(Event $event): void {
        if (!($event instanceof SerializeMailMessageEvent)) {
            return;
        }

        $json = &$event->getJson();
        $message = $event->getMessage();
        $account = $event->getAccount();
        $from = $message->getFrom()->first();
        $to = $message->getTo()->first();
        $from = is_null($from) ? '' : $from->getEmail() ?? '';
        $to = is_null($to) ? '' : $to->getEmail() ?? '';

        if (!array_key_exists('itsl', $json)) {
            throw new Exception('Missing itsl object in json');
        }
        if (!is_array($json['itsl']) || !array_key_exists('received', $json['itsl']) || !is_string($json['itsl']['received'])) {
            throw new Exception('Missing itsl.received object in json');
        }
        if (!array_key_exists('messageType', $json['itsl']) || !is_string($json['itsl']['messageType'])) {
            throw new Exception('Missing itsl.messageType object in json');
        }
        if (!array_key_exists('flags', $json) || !is_array($json['flags'])) {
            throw new Exception('Missing flags object in json');
        }
        if (!array_key_exists('draft', $json['flags']) || !is_bool($json['flags']['draft'])) {
            throw new Exception('Missing flags.draft object in json');
        }
        $messageType = $json['itsl']['messageType'];
        $received = $json['itsl']['received'];
        $draft = $json['flags']['draft'];

        $json['itsl']['messageDirection'] = (($received !== '' || $draft === true) && ($account->getEMailAddress() === $to || $from === $to)) ? 'incoming' : 'outgoing';
        if ($messageType !== '') {
            $json['itsl']['messageType'] = $messageType;
        }

        $json['itsl']['messageType'] = $messageType === '' ? $this->messageTypeService->getMessageTypeFromEmail($from, $to) : $messageType;
        unset($json['itsl']['received']);
    }
}
