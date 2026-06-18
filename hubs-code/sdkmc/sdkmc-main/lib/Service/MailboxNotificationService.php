<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Service;

use DateTime;
use Exception;
use OCA\Mail\Db\Message;
use OCA\SdkMc\Db\MailboxNotificationLog;
use OCA\SdkMc\Db\MailboxNotificationLogMapper;
use OCP\DB\Exception as DBException;
use OCP\Defaults;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Mail\Headers\AutoSubmitted;
use OCP\Mail\IMailer;
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class MailboxNotificationService {
    public function __construct(
        private IMailer $mailer,
        private MailboxNotificationLogMapper $logMapper,
        private LoggerInterface $logger,
        private IURLGenerator $urlGenerator,
        private IFactory $l10nFactory,
        private Defaults $defaults,
    ) {
    }

    /**
     * Send notification email if not already sent.
     * Uses insert-before-send with a UNIQUE constraint to atomically
     * prevent duplicate notifications across concurrent SyncJob workers.
     *
     * @param string $recipient Email address to send notification to
     * @param Message $message The mail message that triggered the notification
     * @param string $messageType ITSL mailbox message type (e.g. 'sdk', 'personlig', 'gruppbox')
     * @param int $itslMailboxId ID of the ITSL mailbox (for deep link)
     */
    public function sendNotificationIfNeeded(string $recipient, Message $message, string $messageType, int $itslMailboxId): void {
        $imapMessageId = $message->getMessageId();

        // Cannot dedup without a stable Message-ID
        if ($imapMessageId === null || trim($imapMessageId) === '') {
            $this->logger->warning('Skipping mailbox notification: message has no IMAP Message-ID', [
                'recipient' => $recipient,
                'messageType' => $messageType,
            ]);
            return;
        }

        // Insert dedup record FIRST — the UNIQUE index on (recipient, message_id)
        // ensures only one process wins. Losers get a constraint violation.
        $log = new MailboxNotificationLog();
        $log->setRecipient($recipient);
        $log->setMessageId($imapMessageId);
        $log->setSentAt(new DateTime());

        try {
            $this->logMapper->insert($log);
        } catch (DBException $e) {
            if ($e->getReason() === DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                $this->logger->debug('Notification already sent (dedup)', [
                    'recipient' => $recipient,
                    'imapMessageId' => $imapMessageId,
                ]);
                return;
            }
            throw $e;
        }

        // We won the insert race — now send the notification
        try {
            $this->sendNotification($recipient, $messageType, $itslMailboxId, $message);

            $this->logger->info('Mailbox notification sent', [
                'recipient' => $recipient,
                'imapMessageId' => $imapMessageId,
                'messageType' => $messageType,
            ]);
        } catch (Exception $e) {
            $this->logger->error('Failed to send mailbox notification', [
                'recipient' => $recipient,
                'imapMessageId' => $imapMessageId,
                'messageType' => $messageType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send the actual notification email
     *
     * @param string $recipient
     * @param string $messageType ITSL mailbox message type (e.g. 'sdk', 'personlig', 'gruppbox')
     * @param int $itslMailboxId
     * @param Message $message
     * @throws Exception
     */
    private function sendNotification(string $recipient, string $messageType, int $itslMailboxId, Message $message): void {
        $l = $this->l10nFactory->get('sdkmc', $this->l10nFactory->findGenericLanguage());
        $instanceName = $this->defaults->getName();

        // Build translator URL for "Open message" button
        $translatorUrl = $this->urlGenerator->linkToRouteAbsolute(
            'sdkmc.mail_notification.redirect',
            ['itslMailboxId' => $itslMailboxId]
        );
        $translatorUrl .= '?' . http_build_query(['mid' => $message->getId()]);

        $heading = match ($messageType) {
            'sdk' => $l->t('New SDK message'),
            'personlig' => $l->t('New Personal message'),
            'gruppbox' => $l->t('New Group message'),
            'fax' => $l->t('New FAX message'),
            'sms' => $l->t('New SMS message'),
            default => $l->t('New secure message'),
        };

        // Build branded email template
        $template = $this->mailer->createEMailTemplate('sdkmc.MailboxNotification');

        $template->setSubject($l->t('Activity at %s', [$instanceName]));
        $template->addHeader();
        $template->addHeading($heading, $heading);
        $template->addBodyButton(
            $l->t('Open message'),
            $translatorUrl,
            $l->t('Open message') . ': ' . $translatorUrl
        );
        $template->addFooter();

        $mailMessage = $this->mailer->createMessage();
        $mailMessage->setTo([$recipient]);
        $mailMessage->setFrom([Util::getDefaultEmailAddress('no-reply') => $instanceName]);
        $mailMessage->useTemplate($template);
        $mailMessage->setAutoSubmitted(AutoSubmitted::VALUE_AUTO_GENERATED);

        $this->mailer->send($mailMessage);
    }
}
