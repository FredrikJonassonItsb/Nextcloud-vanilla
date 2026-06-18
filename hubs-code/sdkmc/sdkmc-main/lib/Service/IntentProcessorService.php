<?php

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Service;

use OCP\ISession;
use Psr\Log\LoggerInterface;
use OCA\SdkMc\Db\ConversationBankIDAuthMapper;
use OCA\SdkMc\Db\ConversationBankIDAuth;
use OCA\SdkMc\Interface\ISmsService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IUserSession;

class IntentProcessorService {
    private const SMS_KEY        = 'sdkmc_sms_intents';
    private const BANKID_KEY     = 'sdkmc_bankid_intents';
    private const SECUREMAIL_KEY = 'sdkmc_securemail_invite_intents';

    public function __construct(
        private LoggerInterface $logger,
        private ISession $session,
        private ConversationBankIDAuthMapper $conversationBankIdMapper,
        private ISmsService $smsService,
        private IAppConfig $appConfig,
        private IUserSession $userSession,
    ) {
    }

    /**
     * @param array<int, array{uid: string, conversation_id: string|null, attendees: array<int, array{email: string, name: string, role: string, status: string}>}> $events
     */
    public function processEvents(array $events): void {
        foreach ($events as $event) {
            $this->logger->warning('[INTENTS] Processing event', [
                'uid'             => $event['uid'],
                'conversation_id' => $event['conversation_id'],
                'attendee_count'  => \count($event['attendees']),
            ]);

            foreach ($event['attendees'] as $attendee) {
                $this->processSMSIntent($attendee, $event);
                $this->processBankIDIntent($attendee, $event);
                // NOTE: Securemail intents are processed during invitation sending, not during event processing
            }
        }
    }

    /**
     * Pop securemail intent for given email
     *
     * @return array{email: string, ssn: string, ts: string, accountId?: int}|null
     */
    public function popSecuremailIntent(string $email): ?array {
        $intent = $this->popIntent($email, self::SECUREMAIL_KEY);

        if ($intent !== null) {
            $this->logger->warning('[SECUREMAIL] Securemail intent popped', [
                'email'     => $intent['email'] ?? '',
                'ssn'       => $intent['ssn'] ?? '',
                'accountId' => $intent['accountId'] ?? null,
            ]);
        }

        /** @var array{email: string, ssn: string, ts: string, accountId?: int}|null */
        return $intent;
    }

    /**
     * @param array{email: string, name: string, role: string, status: string} $attendee
     * @param array{uid: string, conversation_id: string|null, attendees: array<int, array{email: string, name: string, role: string, status: string}>} $event
     */
    private function processSMSIntent(array $attendee, array $event): void {
        $smsIntent = $this->popSmsIntent($attendee['email']);
        $this->logger->warning('EVENT: ' . json_encode($event, JSON_PRETTY_PRINT));
        if ($smsIntent !== null) {
            $messageTemplate = $this->appConfig->getAppValueString('smsMessageContent');
            $ncUser = $this->userSession->getUser();
            $organizerName = $ncUser?->getDisplayName() ?? $ncUser?->getUID() ?? 'Organizer';

            $replacements = [];
            if (str_contains($messageTemplate, '#ORGANIZER_NAME')) {
                $replacements['#ORGANIZER_NAME'] = $organizerName;
            }
            if (str_contains($messageTemplate, '#ATTENDEE_EMAIL')) {
                $replacements['#ATTENDEE_EMAIL'] = $attendee['email'];
            }

            $message = $replacements === []
                ? $messageTemplate
                : strtr($messageTemplate, $replacements);

            $this->logger->warning("SENDING following message $message");

            $this->smsService->sendSms($smsIntent, $message);
        }
    }

    /**
     * @param array{email: string, name: string, role: string, status: string} $attendee
     * @param array{uid: string, conversation_id: string|null, attendees: array<int, array{email: string, name: string, role: string, status: string}>} $event
     */
    private function processBankIDIntent(array $attendee, array $event): void {
        $bankIdIntent = $this->popBankIdIntent($attendee['email']);
        $this->logger->warning('[BANKID] popBankIdIntent result', [
            'email'    => $attendee['email'],
            'required' => $bankIdIntent !== null,
        ]);

        if ($bankIdIntent !== null && $event['conversation_id'] !== null && $event['conversation_id'] !== '') {
            $this->storeBankIDConversationAccess($event['conversation_id'], $attendee['email'], $bankIdIntent);
            $this->logger->warning('[BANKID] BankID access granted for conversation', [
                'email'           => $attendee['email'],
                'name'            => $attendee['name'],
                'conversation_id' => $event['conversation_id'],
            ]);
        }
    }

    /**
     * @param array{email: string, phone?: string, ssn_number?: string, show_first_name?: bool, show_last_name?: bool, show_ssn?: bool} $bankIdIntent
     */
    private function storeBankIDConversationAccess(string $conversationId, string $email, array $bankIdIntent): void {
        try {
            $bankIDAccess = new ConversationBankIDAuth();
            $bankIDAccess->setConversationId($conversationId);
            $bankIDAccess->setEmail($this->normalizeEmail($email));
            $bankIDAccess->setCreatedAt(date('Y-m-d H:i:s'));

            // set SSN hash if provided
            if (isset($bankIdIntent['ssn_number'])) {
                $ssnNumber = trim($bankIdIntent['ssn_number']);
                if ($ssnNumber !== '') {
                    $bankIDAccess->setRequiredSsn($ssnNumber);
                }
            }

            // set visibility options with defaults
            $bankIDAccess->setShowFirstName($bankIdIntent['show_first_name'] ?? true);
            $bankIDAccess->setShowLastName($bankIdIntent['show_last_name'] ?? true);
            $bankIDAccess->setShowSsn($bankIdIntent['show_ssn'] ?? true);

            $this->conversationBankIdMapper->insert($bankIDAccess);

            $this->logger->warning('[BANKID] Stored conversation access using mapper', [
                'conversation_id' => $conversationId,
                'email'           => $email,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('[BANKID] Failed to store conversation access: ' . $e->getMessage(), [
                'conversation_id' => $conversationId,
                'email'           => $email,
                'exception'       => $e,
            ]);
        }
    }

    private function popSmsIntent(string $email): ?string {
        $item = $this->popIntent($email, self::SMS_KEY);
        if ($item !== null && isset($item['phone']) && is_string($item['phone'])) {
            return $item['phone'];
        }
        return null;
    }

    /**
     * @return array{email: string, phone?: string, ssn_number?: string, show_first_name?: bool, show_last_name?: bool, show_ssn?: bool}|null
     */
    private function popBankIdIntent(string $email): ?array {
        $intent = $this->popIntent($email, self::BANKID_KEY);
        /** @var array{email: string, phone?: string, ssn_number?: string, show_first_name?: bool, show_last_name?: bool, show_ssn?: bool}|null */
        return $intent;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function popIntent(string $email, string $sessionKey): ?array {
        $email = $this->normalizeEmail($email);
        if ($email === '') {
            return null;
        }

        $raw = $this->session->get($sessionKey);
        $rawString = is_string($raw) ? $raw : '[]';
        $decoded = json_decode($rawString, true);
        $list = is_array($decoded) ? $decoded : [];

        $found   = null;
        $newList = [];

        foreach ($list as $item) {
            if (!is_array($item)) {
                continue;
            }

            $itemEmail = isset($item['email']) ? (is_string($item['email']) ? strtolower($item['email']) : '') : '';
            if ($found === null && $itemEmail === $email) {
                /** @var array<string, mixed> $typedItem */
                $typedItem = $item;
                $found = $this->validateIntentItem($typedItem, $sessionKey);
                continue;
            }
            $newList[] = $item;
        }

        if ($found !== null) {
            $this->session->set($sessionKey, json_encode($newList));
        }

        return $found;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function validateIntentItem(array $item, string $sessionKey): array {
        $validatedItem = [
            'email' => is_string($item['email'] ?? null) ? $item['email'] : '',
        ];

        // SMS-specific fields
        if ($sessionKey === self::SMS_KEY) {
            if (isset($item['phone']) && is_string($item['phone'])) {
                $validatedItem['phone'] = $item['phone'];
            }
        }

        // BankID-specific fields
        if ($sessionKey === self::BANKID_KEY) {
            // optional SSN
            if (isset($item['ssn_number']) && is_string($item['ssn_number'])) {
                $validatedItem['ssn_number'] = $item['ssn_number'];
            }

            // visibility options with defaults
            $validatedItem['show_first_name'] = isset($item['show_first_name']) && is_bool($item['show_first_name'])
                ? $item['show_first_name']
                : true;

            $validatedItem['show_last_name'] = isset($item['show_last_name']) && is_bool($item['show_last_name'])
                ? $item['show_last_name']
                : true;

            $validatedItem['show_ssn'] = isset($item['show_ssn']) && is_bool($item['show_ssn'])
                ? $item['show_ssn']
                : true;
        }

        // securemail-specific fields
        if ($sessionKey === self::SECUREMAIL_KEY) {
            if (isset($item['ssn']) && is_string($item['ssn'])) {
                $validatedItem['ssn'] = $item['ssn'];
            }

            if (isset($item['ts']) && is_string($item['ts'])) {
                $validatedItem['ts'] = $item['ts'];
            }

            if (isset($item['accountId']) && is_int($item['accountId'])) {
                $validatedItem['accountId'] = $item['accountId'];
            }
        }

        return $validatedItem;
    }

    private function normalizeEmail(string $email): string {
        $result = preg_replace('~^mailto:~i', '', $email);
        return strtolower(trim($result !== null ? $result : ''));
    }
}
