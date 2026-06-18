<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Service;

use Exception;
use OCP\Mail\IMailer;
use Psr\Log\LoggerInterface;
use OCP\AppFramework\Services\IAppConfig;
use OCA\Mail\Service\AccountService;
use OCA\Mail\Contracts\IMailTransmission;
use OCA\Mail\Service\Attachment\AttachmentService;
use OCA\Mail\Db\LocalMessage;
use OCA\Mail\Db\Recipient;

class CustomInvitationMailerService {
    public function __construct(
        private IMailer $mailer,
        private LoggerInterface $logger,
        private IAppConfig $appConfig,
        private IcsParserService $icsParser,
        private IntentProcessorService $intentProcessor,
        private ?string $userId,
        private AccountService $accountService,
        private IMailTransmission $mailTransmission,
        private AttachmentService $attachmentService,
    ) {
    }

    /**
     * Send custom invitation email with modified ICS
     */
    public function sendCustomInvitation(
        string $recipient,
        string $organizer,
        string $originalIcs,
        string $invitationLink,
        ?string $acceptLink = null,
        ?string $declineLink = null,
    ): void {
        $originalRecipient = $recipient;
        $isSecuremail = false;
        $intent = $this->intentProcessor->popSecuremailIntent($recipient);

        if ($intent !== null) {
            $cleanSsn = $this->cleanSsn($intent['ssn']);
            $recipient = $recipient . '.' . $cleanSsn . '.securemail';
            $isSecuremail = true;

            $this->logger->warning(
                '[SECUREMAIL] Applied securemail format to recipient',
                [
                    'original' => $originalRecipient,
                    'modified' => $recipient,
                    'ssn' => $cleanSsn
                ]
            );
        }

        // Extract event details from ORIGINAL ICS before modification
        $eventData = $this->icsParser->extractEventData($originalIcs);
        $dateAndTime = $this->icsParser->extractDateTimeFromIcs($originalIcs);

        $subject = $this->appConfig->getAppValueString('eventInvitationSubject', '');
        $customBody = $this->appConfig->getAppValueString('eventInvitationBody', '');
        $customOrganizer = $this->appConfig->getAppValueString('eventInvitationOrganizer', '');

        // Replace all variables in the body
        $body = $this->replaceVariables($customBody, [
            '#INVITATION_LINK' => $invitationLink,
            '#TITLE' => $eventData['title'],
            '#DESCRIPTION' => $eventData['description'],
            '#DATE_AND_TIME' => $dateAndTime,
            '#ACCEPT_LINK' => $acceptLink ?? '',
            '#DECLINE_LINK' => $declineLink ?? '',
        ]);

        // Modify the ICS file using the parser service
        $modifiedIcs = $customOrganizer === '' ? $originalIcs : $this->icsParser->replaceOrganizer($originalIcs, $customOrganizer);
        $modifiedIcs = $this->icsParser->modifyIcsForRecipient($modifiedIcs, $customOrganizer === '' ? $organizer : $customOrganizer, $recipient, $invitationLink);

        if ($isSecuremail) {
            $this->sendSecuremailInvitation(
                $recipient,
                $originalRecipient,
                $intent,
                $subject,
                $body,
                $modifiedIcs
            );
            return;
        }

        $this->sendRegularInvitation($recipient, $subject, $body, $modifiedIcs, $isSecuremail);
    }

    /**
     * Send invitation via securemail using Mail app's transmission service
     * @param array{email: string, ssn: string, ts: string, accountId?: int} $intent
     */
    private function sendSecuremailInvitation(
        string $recipient,
        string $originalRecipient,
        array $intent,
        string $subject,
        string $body,
        string $modifiedIcs,
    ): void {
        $accountId = $intent['accountId'] ?? null;

        if ($accountId === null || $this->userId === null) {
            $this->logger->error('[SECUREMAIL] No accountId provided in intent', [
                'email' => $originalRecipient,
            ]);
            throw new Exception('No account specified for securemail invitation');
        }

        $attachment = null;
        try {
            // Find the account
            $account = $this->accountService->find($this->userId, $accountId);

            // Create LocalMessage for Mail app's transmission service
            $localMessage = new LocalMessage();
            $localMessage->setId(-1); // transactional mail id
            $localMessage->setType(LocalMessage::TYPE_OUTGOING);
            $localMessage->setAccountId($accountId);
            $localMessage->setSubject($subject);
            $localMessage->setBodyHtml($body);
            $localMessage->setBodyPlain(strip_tags($body));
            $localMessage->setHtml(true);

            // Create proper recipient objects
            $toRecipient = new Recipient();
            $toRecipient->setEmail($recipient);
            $toRecipient->setLabel($recipient);
            $toRecipient->setType(Recipient::TYPE_TO);

            $localMessage->setRecipients([$toRecipient]);

            $attachment = $this->attachmentService->addFileFromString(
                $this->userId,
                'event.ics',
                'text/calendar; method=REQUEST; charset=UTF-8',
                $modifiedIcs
            );
            $localMessage->setAttachments([$attachment]);

            $this->mailTransmission->sendMessage($account, $localMessage);
            $this->logger->warning('[SECUREMAIL] Sent securemail invitation via MailTransmission', [
                'recipient' => $recipient,
                'accountId' => $accountId,
                'from' => $account->getEmail(),
                'attachmentId' => $attachment->getId(),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('[SECUREMAIL] Failed to send securemail invitation', [
                'recipient' => $recipient,
                'accountId' => $accountId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        } finally {
            if ($attachment !== null) {
                $this->attachmentService->deleteAttachment($this->userId, $attachment->getId());
            }
        }
    }

    /**
     * Send regular invitation using standard mailer
     */
    private function sendRegularInvitation(
        string $recipient,
        string $subject,
        string $body,
        string $modifiedIcs,
        bool $isSecuremail,
    ): void {
        $message = $this->mailer->createMessage();
        $message->setTo([$recipient]);
        $message->setSubject($subject);
        $message->setPlainBody(strip_tags($body));
        $message->setHtmlBody($body);

        // Attach modified ICS file
        $message->attachInline(
            $modifiedIcs,
            'event.ics',
            'text/calendar; method=REQUEST; charset=UTF-8'
        );

        try {
            $this->mailer->send($message);

            $this->logger->warning(
                'Sent custom invitation email',
                [
                    'app' => 'sdkmc',
                    'recipient' => $recipient,
                    'subject' => $subject,
                    'securemail' => $isSecuremail
                ]
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to send custom invitation',
                [
                    'app' => 'sdkmc',
                    'recipient' => $recipient,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]
            );
            throw $e;
        }
    }

    /**
     * Replace variables in template with provided values
     * @param array<string, string> $variables
     */
    private function replaceVariables(string $template, array $variables): string {
        foreach ($variables as $key => $value) {
            $template = str_replace($key, $value, $template);
        }
        return $template;
    }

    /**
     * Clean SSN by removing dashes and spaces
     */
    private function cleanSsn(string $ssn): string {
        return preg_replace('/[\s\-]/', '', $ssn) ?? '';
    }
}
