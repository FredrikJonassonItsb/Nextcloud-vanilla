<?php

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Listener;

use OCA\Mail\Db\LocalMessage;
use OCA\Mail\Db\LocalMessageMapper;
use OCA\Mail\Exception\ServiceException;
use OCA\SdkMc\Db\MessageMetadataMapper;
use OCA\SdkMc\Event\SendEmailEvent;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * @implements IEventListener<Event>
 */
class SendEmailListener implements IEventListener {
    public function __construct(
        private MessageMetadataMapper $mapper,
        private LocalMessageMapper $localMessageMapper,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if (!($event instanceof SendEmailEvent)) {
            return;
        }

        try {
            $mm = $this->mapper->getByMessage($event->getLocalMessage()->getId());
        } catch (DoesNotExistException $e) {
            // No metadata stored - set defaults and continue sending
            $headers = &$event->getHeaders();
            $headers['X-MessageType'] = 'secure_email';
            $headers['X-LoaLevel'] = 1;
            return;
        }

        $headers = &$event->getHeaders();
        $headers['X-Sdk'] = json_encode($mm->getSdkData() ?? []);
        $headers['X-MessageType'] = $mm->getMessageType();
        $headers['X-NoReply'] = $mm->getNoReply();

        if ($mm->getMessageType() === 'secure_email') {
            $smsNumber = $mm->getSmsNumber();
            $loaLevel = $mm->getLoaLevel();
            if ($loaLevel === 2 && ($smsNumber === null || $smsNumber === '')
                && $event->getLocalMessage()->getType() === LocalMessage::TYPE_OUTGOING) {
                $localMessage = $event->getLocalMessage();
                $localMessage->setSendAt(null);
                $localMessage->setStatus(LocalMessage::STATUS_SMPT_SEND_FAIL);
                $this->localMessageMapper->update($localMessage);
                $this->logger->error('[SECURE EMAIL] LOA-2 send rejected: missing smsNumber', [
                    'messageId' => $localMessage->getId(),
                ]);
                throw new ServiceException('Cannot send LOA-2 message without SMS number');
            }
            if ($smsNumber !== null && $smsNumber !== '' && $loaLevel === 2) {
                $headers['X-SmsNumber'] = $smsNumber;
            }
            $headers['X-LoaLevel'] = $loaLevel;

            $this->logger->debug('[SECURE EMAIL] Headers added to message in SendEmailListener:', [
                'messageId' => $event->getLocalMessage()->getId(),
                'loaLevel' => $loaLevel,
                'smsNumber' => $smsNumber !== null && $smsNumber !== '' ? 'present' : 'absent',
                'headers' => [
                    'X-LoaLevel' => $headers['X-LoaLevel'],
                    'X-SmsNumber' => $headers['X-SmsNumber'] ?? 'not set',
                ],
            ]);
        }
    }
}
