<?php

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Listener;

use Exception;
use OCA\Mail\Contracts\IMailManager;
use OCA\Mail\IMAP\IMAPClientFactory;
use OCA\Mail\Service\AccountService;
use OCA\SdkMc\Event\FetchEmailEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCA\SdkMc\Db\MessageReceiptMapper;
use OCA\SdkMc\Db\MessageThreadMapper;
use OCA\SdkMc\Service\MessageTypeService;
use RuntimeException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Log\Audit\CriticalActionPerformedEvent;

/**
 * @implements IEventListener<Event>
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.ExcessiveParameterList")
 */
class FetchEmailListener implements IEventListener {
    public function __construct(
        private string $userId,
        private AccountService $accountService,
        private IMAPClientFactory $clientFactory,
        private IMailManager $mailManager,
        private MessageReceiptMapper $messageReceiptMapper,
        private MessageThreadMapper $messageThreadMapper,
        private MessageTypeService $messageTypeService,
        private IEventDispatcher $eventDispatcher,
    ) {
    }

    public function handle(Event $event): void {
        if (!($event instanceof FetchEmailEvent)) {
            return;
        }

        $json = &$event->getJson();
        $id = $event->getId();

        $message = $this->mailManager->getMessage($this->userId, $id);
        $mailbox = $this->mailManager->getMailbox($this->userId, $message->getMailboxId());
        $account = $this->accountService->find($this->userId, $mailbox->getAccountId());
        $client = $this->clientFactory->getClient($account);

        try {
            $source = $this->mailManager->getSource(
                $client,
                $account,
                $mailbox->getName(),
                $message->getUid()
            );
        } finally {
            $client->logout();
        }

        if (is_null($source)) {
            throw new Exception('Unable to fetch message');
        }

        $received = $this->messageTypeService->fetchHeader($source, 'Received');

        if (!array_key_exists('flags', $json) || !is_array($json['flags'])) {
            throw new Exception('Missing flags object in json');
        }
        if (!array_key_exists('draft', $json['flags']) || !is_bool($json['flags']['draft'])) {
            throw new Exception('Missing flags.draft object in json');
        }
        $draft = $json['flags']['draft'];

        $from = $message->getFrom()->first();
        $to = $message->getTo()->first();
        $from = is_null($from) ? '' : $from->getEmail() ?? '';
        $to = is_null($to) ? '' : $to->getEmail() ?? '';

        $json['itsl'] = [];
        $json['itsl']['noReply'] = $this->messageTypeService->fetchHeader($source, 'X-NoReply') === '1' ? 1 : 0;
        $json['itsl']['messageDirection'] = (($received !== '' || $draft === true) && ($account->getEMailAddress() === $to || $from === $to)) ? 'incoming' : 'outgoing';
        $messageType = $json['itsl']['messageType'] = $this->messageTypeService->fetchHeader($source, 'X-MessageType') === '' ? $this->messageTypeService->getMessageTypeFromEmail($from, $to) : $this->messageTypeService->fetchHeader($source, 'X-MessageType');
        $json['itsl']['sdk'] = $messageType === 'sdk_message' ? json_decode($this->messageTypeService->fetchHeader($source, 'X-Sdk'), true) : null;

        if ($messageType === 'secure_email') {
            $smsHeader = $this->messageTypeService->fetchHeader($source, 'X-SmsNumber');
            $json['itsl']['smsNumber'] = $smsHeader !== '' ? $smsHeader : null;
            $loaHeader = $this->messageTypeService->fetchHeader($source, 'X-LoaLevel');
            $json['itsl']['loaLevel'] = $loaHeader !== '' ? (int)$loaHeader : 1;
        }

        $verb = $event->getSource() === 'thread' ? 'previewed' : 'opened';
        $type = $draft ? 'Draft' : 'Message';
        $logLine = $type . ' of type %s and with id %s in %s ' . $verb . ' by %s';
        $logParams = [$messageType, $message->getMessageId(), $account->getEmail(), $this->userId];

        if ($messageType === 'sdk_message' && $json['itsl']['sdk'] !== null) {
            $messageThread = null;
            try {
                $messageIdRaw = $message->getMessageId();
                if (is_null($messageIdRaw) || $messageIdRaw === '') {
                    throw new RuntimeException('Message-ID is missing');
                }
                $messageThread = $this->messageThreadMapper->getByMessage($messageIdRaw);
                $logLine = $type . ' of type %s and with id %s (SDK Id %s) in %s ' . $verb . ' by %s';
                $logParams = [$messageType, $message->getMessageId(), $messageThread->getSdkMessageId(), $account->getEmail(), $this->userId];
            } catch (Exception $e) {
                $json['itsl']['receipt'] = null;
            }

            try {
                $json['itsl']['receipt'] = is_null($messageThread) ? null : $this->messageReceiptMapper->getByDocumentReference($messageThread->getSdkMessageId());
            } catch (Exception $e) {
                $json['itsl']['receipt'] = null;
            }
        }

        $this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent($logLine, $logParams));
    }
}
