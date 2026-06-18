<?php

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Listener;

use OCA\SdkMc\Db\MessageMetadataMapper;
use OCA\SdkMc\Event\SerializeLocalMessageEvent;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * @implements IEventListener<Event>
 */
class SerializeLocalMessageListener implements IEventListener {
    public function __construct(
        private MessageMetadataMapper $mapper,
    ) {
    }

    public function handle(Event $event): void {
        if (!($event instanceof SerializeLocalMessageEvent)) {
            return;
        }

        try {
            $mm = $this->mapper->getByMessage($event->getMessage()->getId());
        } catch (DoesNotExistException $e) {
            return; // if we dont have any data stored its better to just not crash
        }

        $json = &$event->getJson();
        $json['itsl'] = [];
        $json['itsl']['sdk'] = $mm->getSdkData();
        $json['itsl']['messageType'] = $mm->getMessageType();
        $json['itsl']['noReply'] = $mm->getNoReply();
        $json['itsl']['smsNumber'] = $mm->getSmsNumber();
        $json['itsl']['loaLevel'] = $mm->getLoaLevel();
        $json['itsl']['messageDirection'] = 'outgoing';
    }
}
