<?php

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCA\Mail\Events\NewMessagesSynchronized;
use OCP\Activity\IManager;
use OCA\Mail\Db\MailboxMapper;
use OCP\IURLGenerator;
use OCA\Mail\Address;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCA\SdkMc\Service\MessageTypeService;
use RuntimeException;
use OCA\SdkMc\Db\MessageThreadMapper;
use Psr\Log\LoggerInterface;
use Exception;
use OCA\SdkMc\Db\ItslMailboxMapper;
use OCA\SdkMc\Service\MailboxNotificationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception as DBException;

/**
 * @implements IEventListener<Event>
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class NewMessagesSynchronizedListener implements IEventListener {
    public function __construct(
        private IManager $activityManager,
        private MailboxMapper $mapper,
        private IURLGenerator $url,
        private IEventDispatcher $eventDispatcher,
        private MessageTypeService $messageTypeService,
        private MessageThreadMapper $messageThreadMapper,
        private LoggerInterface $logger,
        private ItslMailboxMapper $itslMailboxMapper,
        private MailboxNotificationService $notificationService,
    ) {
    }

    public function handle(Event $event): void {
        if (!($event instanceof NewMessagesSynchronized)) {
            return;
        }

        $account = $event->getAccount();
        $mailbox = $event->getMailbox();

        if (! $mailbox->isInbox()) {
            return;
        }

        foreach ($event->getMessages() as &$message) {
            $sender = $message->getFrom()->first();
            if (!($sender instanceof Address)) {
                continue;
            }
            $sender = $sender->getEmail();
            if (!is_string($sender)) {
                continue;
            }

            $recipient = $message->getTo()->first();
            if (!($recipient instanceof Address)) {
                continue;
            }
            $recipient = $recipient->getEmail();
            if (!is_string($recipient)) {
                continue;
            }

            $messageID = $message->getId();
            $mailboxID = $message->getMailboxId();

            $realUri = $this->url->getAbsoluteURL('/apps/mail/box/' . $mailboxID . '/thread/' . $messageID);
            $activityType = 'messages_' . $this->messageTypeService->getTldFromEmail($recipient);

            $mailbox = $this->mapper->findById($mailboxID);
            $userId = $account->getUserId();

            // Send notification email if configured for this ITSL mailbox
            try {
                $itslMailbox = $this->itslMailboxMapper->findByEmail($recipient);
                $notificationEmail = $itslMailbox->getNotificationEmail();
                if ($notificationEmail !== null && $notificationEmail !== '') {
                    $this->notificationService->sendNotificationIfNeeded(
                        $notificationEmail,
                        $message,
                        $itslMailbox->getMessageType(),
                        $itslMailbox->getId()
                    );
                }
            } catch (DoesNotExistException $e) {
                // Not an ITSL mailbox, no notification needed
            } catch (MultipleObjectsReturnedException|DBException $e) {
                $this->logger->error('Failed to check for notification email', [
                    'recipient' => $recipient,
                    'messageId' => $messageID,
                    'error' => $e->getMessage(),
                ]);
            }

            $activity = $this->activityManager->generateEvent();
            $activity->setApp('mail')
                ->setType($activityType) // lowercase and underscore only
                ->setAuthor('system6ulFJBFr8v') // made up user
                ->setTimestamp($message->getSentAt())
                ->setMessage('Du har fått ett nytt säkert meddelande i HubS')
                ->setLink($realUri)
                ->setAffectedUser($userId)
                ->setSubject(
                    '{announcement}',
                    ['announcement' => [
                        'type' => 'announcement',
                        'id' => '0',
                        'name' => $activityType,
                        'link' => $realUri,
                    ]]
                );
            $this->activityManager->publish($activity);

            $messageType = $this->messageTypeService->getMessageTypeFromEmail($sender, $recipient);
            $logMessage = 'New message of type %s (Message Id: %s) delivered to %s for user %s';
            $logParams = [$messageType, $message->getMessageId(), $recipient, $userId];

            try {
                if ($messageType === 'sdk_message') {
                    $messageIdRaw = $message->getMessageId();
                    if (is_null($messageIdRaw) || $messageIdRaw === '') {
                        throw new RuntimeException('Message-ID is missing');
                    }

                    $messageThread = $this->messageThreadMapper->getByMessage($messageIdRaw);
                    $logMessage = 'New message of type %s (Message Id: %s, SDK Id: %s) delivered to %s for user %s';
                    $logParams = [$messageType, $message->getMessageId(), $messageThread->getSdkMessageId(), $recipient, $userId];
                }
            } catch (Exception $e) {
                $this->logger->error('Exception thrown when trying to collect data for doing audit logging: ' . $e->getMessage());
            }
            $this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent($logMessage, $logParams));
        }
    }
}
