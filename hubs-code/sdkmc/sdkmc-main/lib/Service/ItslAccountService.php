<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Service;

use Exception;
use OCA\Mail\Db\Tag;
use OCA\Mail\Service\AccountService;
use OCA\SdkMc\Db\AccountItslMailbox;
use OCA\SdkMc\Db\AccountItslMailboxMapper;
use OCA\SdkMc\Db\ItslMailbox;
use OCA\SdkMc\Db\ItslMailboxMapper;
use OCA\SdkMc\Db\ItslTag;
use OCA\SdkMc\Db\ItslTagMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Services\IAppConfig;
use OCP\DB\Exception as DBException;
use OCP\Http\Client\IClientService;
use OCP\IUserManager;

/**
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class ItslAccountService {
    /** @var Array<string> */
    private array $messageTypes  = ['sdk', 'fax', 'gruppbox', 'personlig', 'sms'];

    public function __construct(
        private IAppConfig $appConfig,
        private AccountService $accountService,
        private IClientService $clientService,
        private IUserManager $userManager,
        private ItslMailboxMapper $mailboxMapper,
        private AccountItslMailboxMapper $accountMailboxMapper,
        private ConsolidateMailboxesService $consolidateService,
        private MailboxRetentionService $retentionService,
        private ItslTagMapper $itslTagMapper,
    ) {
    }

    /**
     * @return Array<string, Array<string, mixed>>
     */
    public function getAllUsers(): array {
        $users = $this->userManager->searchDisplayName('', null, null);
        $response = [];
        foreach ($users as $user) {
            $response[$user->getUID()] = ['userId' => $user->getUID(), 'displayName' => $user->getDisplayName()];
        }

        return $response;
    }

    /**
     * @return Array<string, Array<string, mixed>>
     */
    public function getAllMailboxes(): array {
        $accounts = [];

        foreach ($this->messageTypes as $messageType) {
            $mailboxes = $this->getMailBoxes($messageType);

            foreach ($mailboxes as &$mailbox) {
                unset($mailbox['password']);
            }
            unset($mailbox); // avoid reference pollution

            $accounts[$messageType] = $mailboxes;
        }

        return $accounts;
    }
    /**
     * @return Array<string, Array<string, mixed>>
     * @throws DBException
     */
    public function getAllAccounts(?string $type = null): array {
        if (!is_null($type) && !in_array($type, $this->messageTypes, true)) {
            throw new Exception('Illegal message type');
        }

        $accounts = [];
        $searchFor = $this->messageTypes;
        if (!is_null($type)) {
            $searchFor = [$type];
        }

        foreach ($searchFor as $messageType) {
            $accounts[$messageType] = [];
            $mailboxEntities = $this->mailboxMapper->findByMessageType($messageType);

            foreach ($mailboxEntities as $mailboxEntity) {
                $accountMailboxes = $this->accountMailboxMapper->findByMailboxId($mailboxEntity->getId());
                // Get user and group associations from AccountItslMailbox table
                $mailbox = $this->convertMailboxToArray($mailboxEntity, $accountMailboxes);

                // Remove password for security
                unset($mailbox['password']);

                $accounts[$messageType][$mailboxEntity->getEmail()] = $mailbox;
            }
        }
        return $accounts;
    }

    /**
     * Add a user to a mailbox (tracking only - actual Mail account created by consolidation)
     *
     * @throws DBException
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
     */
    public function addUserToMailBox(
        string $messageType,
        string $email,
        string $userId,
        bool $scheduleConsolidation = true,
    ): void {
        // Find the mailbox
        try {
            $mailbox = $this->mailboxMapper->findByEmail($email);
            if ($mailbox->getMessageType() !== $messageType) {
                throw new Exception('Mailbox type mismatch');
            }
        } catch (DoesNotExistException $e) {
            throw new Exception('Could not find account');
        } catch (MultipleObjectsReturnedException $e) {
            throw new Exception('Data inconsistency: multiple mailboxes with same email');
        }

        // Check if user is already added
        $existing = $this->accountMailboxMapper->findByMailboxIdAndAccountId($mailbox->getId(), $userId, 'user');
        if (count($existing) > 0) {
            return; // Already added
        }

        // Add user to AccountItslMailbox table
        $accountMailbox = new AccountItslMailbox();
        $accountMailbox->setItslMailboxId($mailbox->getId());
        $accountMailbox->setAccessType('user');
        $accountMailbox->setAccountId($userId);
        $this->accountMailboxMapper->insert($accountMailbox);

        // Schedule consolidation to create the actual Mail account
        if ($scheduleConsolidation) {
            $this->consolidateService->scheduleConsolidationIfNeeded();
        }
    }

    /**
     * @throws DBException
     */
    public function removeUserFromMailBox(
        string $email,
        string $userId,
    ): void {
        try {
            $mailbox = $this->mailboxMapper->findByEmail($email);

            // Check if user has OTHER access paths (via groups)
            $hasGroupAccess = $this->consolidateService->userHasGroupAccess($userId, $mailbox->getId());

            if (!$hasGroupAccess) {
                // No other access paths - remove Mail account
                $accounts = $this->accountService->findByUserIdAndAddress($userId, $email);
                foreach ($accounts as $account) {
                    $this->accountService->delete($userId, $account->getId());
                }
            }
            // else: Keep Mail account, user still has access via groups

            // Always remove direct assignment from AccountItslMailbox
            $accountMailboxes = $this->accountMailboxMapper->findByMailboxIdAndAccountId(
                $mailbox->getId(),
                $userId,
                'user'
            );
            foreach ($accountMailboxes as $accountMailbox) {
                $this->accountMailboxMapper->delete($accountMailbox);
            }
        } catch (DoesNotExistException $e) {
            // Mailbox doesn't exist, nothing to clean up
        } catch (MultipleObjectsReturnedException $e) {
            throw new Exception('Data inconsistency: multiple mailboxes with same email');
        }
    }

    /**
     * @param Array<mixed, mixed> $arguments
     */
    private function makeHttpRequest(string $hostConfig, int $port, string $endpoint, array $arguments): string {
        $client = $this->clientService->newClient();
        $response = $client->post('http://' . $this->appConfig->getAppValueString($hostConfig) . ':' . strval($port) . '/api/' . $endpoint, [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-Api-Token' => $this->appConfig->getAppValueString('sdkmcmwSecretPassword'),
            ],
            'body' => json_encode([
                'args' => $arguments,
                'force_unique_key' => true
            ])
        ]);
        $bodyString = $response->getBody();
        if (!is_string($bodyString)) {
            throw new Exception('Unable to initialize job');
        }
        $body = json_decode($bodyString, true);
        if (!is_array($body) || !array_key_exists('status', $body) || $body['status'] !== 'running') {
            throw new Exception('Unable to initialize job');
        }
        if (!array_key_exists('key', $body) || !is_string($body['key'])) {
            throw new Exception('Unable to initialize job');
        }
        $testUrl = 'http://' . $this->appConfig->getAppValueString($hostConfig) . ':' . strval($port) . '/api/' . $endpoint . '?key=' . $body['key'] . '&wait=true';
        $response = $client->get($testUrl);
        $bodyString = $response->getBody();
        if (!is_string($bodyString)) {
            throw new Exception('Unable to initialize job');
        }
        $body = json_decode($bodyString, true);
        if (!is_array($body) || !array_key_exists('returncode', $body) || $body['returncode'] !== 0) {
            $error = is_array($body) && isset($body['error']) && is_string($body['error']) ? $body['error'] : null;
            $report = is_array($body) && isset($body['report']) && is_string($body['report']) ? $body['report'] : null;
            throw new Exception('Job failed: ' . ($error ?? $report ?? json_encode($body)));
        }
        if (!array_key_exists('report', $body) || !is_string($body['report'])) {
            if (!array_key_exists('error', $body) || !is_string($body['error'])) {
                throw new Exception('Job failed.');
            }
            throw new Exception('Job failed: ' . $body['error']);
        }
        return $body['report'];
    }

    /**
     * @param array{'sdkaddress'?: string, 'number'?: string, 'description'?: string, 'name'?: string, 'canMessageBeSentTo'?: boolean, 'canBeRepliedTo'?: boolean, 'notificationEmail'?: string|null} $settings
     * @throws DBException
     */
    public function addAccount(
        string $messageType,
        string $email,
        string $alias,
        array $settings,
    ): void {
        // Check if alias is already taken for this message type
        try {
            $this->mailboxMapper->findByAliasAndType($alias, $messageType);
            throw new Exception('Alias "' . $alias . '" is already taken for message type "' . $messageType . '"');
        } catch (DoesNotExistException $e) {
            // Good, alias is available
        } catch (MultipleObjectsReturnedException $e) {
            throw new Exception('Data inconsistency: multiple mailboxes with same alias');
        }

        // Check if SDK address is already taken
        if ($messageType === 'sdk' && array_key_exists('sdkaddress', $settings)) {
            try {
                $this->mailboxMapper->findBySdkAddress($settings['sdkaddress']);
                throw new Exception('SDK address "' . $settings['sdkaddress'] . '" is already in use');
            } catch (DoesNotExistException $e) {
                // Good, SDK address is available
            } catch (MultipleObjectsReturnedException $e) {
                throw new Exception('Data inconsistency: multiple mailboxes with same SDK address');
            }
        }

        $password = bin2hex(openssl_random_pseudo_bytes(31));

        $mailbox = new ItslMailbox();
        $mailbox->setEmail($email);
        $mailbox->setAlias($alias);
        $mailbox->setPassword($password);
        $mailbox->setMessageType($messageType);

        switch ($messageType) {
            case 'sdk':
                if (!array_key_exists('sdkaddress', $settings) || trim($settings['sdkaddress']) === '') {
                    throw new Exception('Missing data in the request; could not add the account');
                }
                $mailbox->setSdkAddress($settings['sdkaddress']);
                $mailbox->setName($settings['name'] ?? '');
                $mailbox->setDescription($settings['description'] ?? '');
                $mailbox->setNotificationEmail($settings['notificationEmail'] ?? null);
                break;
            case 'gruppbox':
            case 'personlig':
                if (!array_key_exists('name', $settings) || !array_key_exists('description', $settings) || !array_key_exists('canBeRepliedTo', $settings) || !array_key_exists('canMessageBeSentTo', $settings)) {
                    throw new Exception('Missing data in the request; could not add the account');
                }
                $mailbox->setName($settings['name']);
                $mailbox->setDescription($settings['description']);
                $mailbox->setCanBeRepliedTo($settings['canBeRepliedTo']);
                $mailbox->setCanMessageBeSentTo($settings['canMessageBeSentTo']);
                $mailbox->setNotificationEmail($settings['notificationEmail'] ?? null);
                break;
            case 'sms':
            case 'fax':
                if (!array_key_exists('name', $settings) || !array_key_exists('description', $settings)  || !array_key_exists('number', $settings)) {
                    throw new Exception('Missing data in the request; could not add the account');
                }
                $mailbox->setName($settings['name']);
                $mailbox->setDescription($settings['description']);
                $mailbox->setNumber($settings['number']);
                $mailbox->setNotificationEmail($settings['notificationEmail'] ?? null);
                break;
            default:
                throw new Exception('Illegal message type');
        }

        // DB first: catch duplicates before touching mail servers
        // (add_user is idempotent — it resets the password if the account
        // already exists, so calling it before the insert could silently
        // corrupt an existing account's credentials on a duplicate-email
        // constraint violation.)
        $this->mailboxMapper->insert($mailbox);
        try {
            $this->makeHttpRequest('imapHost', 10124, 'dovecot_messageclient/add_user', [$email, $password]);
        } catch (\Throwable $e) {
            $this->mailboxMapper->delete($mailbox);
            throw $e;
        }
        try {
            $this->makeHttpRequest('smtpInboundHost', 10123, 'postfix_inbound_messageclient/add_user', [$email, $password]);
        } catch (\Throwable $e) {
            $this->makeHttpRequest('imapHost', 10124, 'dovecot_messageclient/del_user', [$email]);
            $this->mailboxMapper->delete($mailbox);
            throw $e;
        }

        // ITSL: Create Important tag for this new email address
        // Tags are scoped by email, not user, so we create them when email addresses are added
        $this->createImportantTagForEmail($email);
    }

    /**
     * Update an existing mailbox account
     *
     * @param array{'name'?: string, 'description'?: string, 'canBeRepliedTo'?: bool, 'canMessageBeSentTo'?: bool, 'notificationEmail'?: string|null} $settings
     * @throws DBException
     */
    public function updateAccount(
        string $messageType,
        string $alias,
        array $settings,
    ): void {
        try {
            $mailbox = $this->mailboxMapper->findByAliasAndType($alias, $messageType);
        } catch (DoesNotExistException $e) {
            throw new Exception('Account not found with the specified alias');
        } catch (MultipleObjectsReturnedException $e) {
            throw new Exception('Data inconsistency: multiple mailboxes with same alias');
        }

        switch ($messageType) {
            case 'sdk':
                // Only notificationEmail can be updated for SDK mailboxes
                $allowedFields = ['notificationEmail'];
                $invalidFields = array_diff(array_keys($settings), $allowedFields);

                if ($invalidFields !== []) {
                    throw new Exception('Invalid fields for SDK mailboxes: ' . implode(', ', $invalidFields));
                }

                if (array_key_exists('notificationEmail', $settings)) {
                    $mailbox->setNotificationEmail($settings['notificationEmail']);
                }
                break;
            case 'gruppbox':
            case 'personlig':
                $allowedFields = ['name', 'description', 'canBeRepliedTo','canMessageBeSentTo', 'notificationEmail'];
                $invalidFields = array_diff(array_keys($settings), $allowedFields);

                if ($invalidFields !== []) {
                    throw new Exception('Invalid fields for ' . $messageType . ' account: ' . implode(', ', $invalidFields));
                }

                if (array_key_exists('name', $settings)) {
                    $mailbox->setName($settings['name']);
                }
                if (array_key_exists('description', $settings)) {
                    $mailbox->setDescription($settings['description']);
                }
                if (array_key_exists('canBeRepliedTo', $settings)) {
                    $mailbox->setCanBeRepliedTo($settings['canBeRepliedTo']);
                }
                if (array_key_exists('canMessageBeSentTo', $settings)) {
                    $mailbox->setCanMessageBeSentTo($settings['canMessageBeSentTo']);
                }
                if (array_key_exists('notificationEmail', $settings)) {
                    $mailbox->setNotificationEmail($settings['notificationEmail']);
                }
                break;
            case 'sms':
            case 'fax':
                // SMS/Fax: Only name, description, and notificationEmail can be updated (number is read-only)
                $allowedFields = ['name', 'description', 'notificationEmail'];
                $invalidFields = array_diff(array_keys($settings), $allowedFields);

                if ($invalidFields !== []) {
                    throw new Exception('Invalid fields for ' . $messageType . ' account: ' . implode(', ', $invalidFields));
                }

                if (array_key_exists('name', $settings)) {
                    $mailbox->setName($settings['name']);
                }
                if (array_key_exists('description', $settings)) {
                    $mailbox->setDescription($settings['description']);
                }
                if (array_key_exists('notificationEmail', $settings)) {
                    $mailbox->setNotificationEmail($settings['notificationEmail']);
                }
                break;
            default:
                throw new Exception('Illegal message type');
        }

        $this->mailboxMapper->update($mailbox);
    }

    /**
     * @throws DBException
     */
    public function removeAccount(string $messageType, string $email): void {
        try {
            $mailbox = $this->mailboxMapper->findByEmail($email);
            if ($mailbox->getMessageType() !== $messageType) {
                throw new Exception('Mailbox type mismatch');
            }
        } catch (DoesNotExistException $e) {
            throw new Exception('Mailbox not found');
        } catch (MultipleObjectsReturnedException $e) {
            throw new Exception('Data inconsistency: multiple mailboxes with same email');
        }

        $this->makeHttpRequest('imapHost', 10124, 'dovecot_messageclient/del_user', [$email]);
        $this->makeHttpRequest('smtpInboundHost', 10123, 'postfix_inbound_messageclient/del_user', [$email]);

        // Delete associated account mailbox entries first
        $this->accountMailboxMapper->deleteByMailboxId($mailbox->getId());

        // Delete the mailbox
        $this->mailboxMapper->delete($mailbox);
    }

    /**
     * @param Array<mixed> $data
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function setMailBoxes(string $messageType, array $data): void {
        if (!in_array($messageType, $this->messageTypes, true)) {
            throw new Exception('Illegal message type');
        }
        // This method is deprecated but kept for backward compatibility
        // Direct database operations should be used instead
        throw new Exception('setMailBoxes is deprecated - use direct entity operations');
    }

    /**
     * Convert ItslMailbox entity to legacy array format
     * @param ItslMailbox $mailbox
     * @param array<AccountItslMailbox> $accountMailboxes
     * @return array{'email': string, 'password': string, 'alias': string, 'sdkaddress'?: string, 'number'?: string, 'description'?: string, 'name'?: string, 'canBeRepliedTo'?: bool, 'canMessageBeSentTo'?: bool, 'users'?: array<string>, 'groups'?: array<string>, 'retentionOverrides'?: array<string, int|null>}
     */
    private function convertMailboxToArray(ItslMailbox $mailbox, array $accountMailboxes): array {
        $data = [
            'email' => $mailbox->getEmail(),
            'password' => $mailbox->getPassword(),
            'alias' => $mailbox->getAlias(),
            'name' => $mailbox->getName(),
            'description' => $mailbox->getDescription(),
            'canBeRepliedTo' => $mailbox->getCanBeRepliedTo(),
            'canMessageBeSentTo' => $mailbox->getCanMessageBeSentTo(),
        ];

        $sdkAddress = $mailbox->getSdkAddress();
        if ($sdkAddress !== null) {
            $data['sdkaddress'] = $sdkAddress;
        }

        $number = $mailbox->getNumber();
        if ($number !== null) {
            $data['number'] = $number;
        }

        $notificationEmail = $mailbox->getNotificationEmail();
        if ($notificationEmail !== null) {
            $data['notificationEmail'] = $notificationEmail;
        }

        // Add users and groups arrays
        $data['users'] = [];
        $data['groups'] = [];

        foreach ($accountMailboxes as $accountMailbox) {
            $accountId = $accountMailbox->getAccountId();
            if ($accountMailbox->getAccessType() === 'user' && $accountId !== null) {
                $data['users'][] = $accountId;
            }

            $groupId = $accountMailbox->getGroupId();
            if ($accountMailbox->getAccessType() === 'group' && $groupId !== null) {
                $data['groups'][] = $groupId;
            }
        }

        // Add retention overrides (only if there are any)
        $retentionOverrides = $this->retentionService->getOverrides($mailbox->getEmail());
        if ($retentionOverrides !== []) {
            $data['retentionOverrides'] = $retentionOverrides;
        }

        return $data;
    }

    /**
     * @return Array<string, array{'email': string, 'password': string, 'alias': string, 'sdkaddress'?: string, 'number'?: string, 'description'?: string, 'name'?: string, 'canBeRepliedTo'?: bool, 'canMessageBeSentTo'?: bool, 'users'?: array<string>, 'groups'?: array<string> }>
     * @throws DBException
     */
    public function getMailBoxes(string $messageType): array {
        if (!in_array($messageType, $this->messageTypes, true)) {
            throw new Exception('Illegal message type');
        }

        $mailboxEntities = $this->mailboxMapper->findByMessageType($messageType);
        $result = [];

        foreach ($mailboxEntities as $mailbox) {
            $accountMailboxes = $this->accountMailboxMapper->findByMailboxId($mailbox->getId());
            $mailboxArray = $this->convertMailboxToArray($mailbox, $accountMailboxes);

            // Use the same key format as before: email for most types, sdkaddress for sdk
            $key = $messageType === 'sdk' && isset($mailboxArray['sdkaddress'])
                ? $mailboxArray['sdkaddress']
                : $mailbox->getEmail();

            $result[$key] = $mailboxArray;
        }

        return $result;
    }

    /**
     * @param string $messageType
     * @param string $email
     * @return array<string> list of group IDs
     * @throws DBException
     */
    public function getGroupsForMailBox(string $messageType, string $email): array {
        try {
            $mailbox = $this->mailboxMapper->findByEmail($email);
            if ($mailbox->getMessageType() !== $messageType) {
                throw new Exception('Mailbox type mismatch');
            }
        } catch (DoesNotExistException $e) {
            throw new Exception('Could not find mailbox');
        } catch (MultipleObjectsReturnedException $e) {
            throw new Exception('Data inconsistency: multiple mailboxes with same email');
        }

        $accountMailboxes = $this->accountMailboxMapper->findByMailboxId($mailbox->getId());
        /** @var array<string> */
        $groups = [];

        foreach ($accountMailboxes as $accountMailbox) {
            $groupId = $accountMailbox->getGroupId();
            if ($accountMailbox->getAccessType() === 'group' && $groupId !== null) {
                $groups[] = $groupId;
            }
        }

        return $groups;
    }

    /**
     * @throws DBException
     */
    public function addGroupToMailBox(string $messageType, string $email, string $groupId): void {
        try {
            $mailbox = $this->mailboxMapper->findByEmail($email);
            if ($mailbox->getMessageType() !== $messageType) {
                throw new Exception('Mailbox type mismatch');
            }
        } catch (DoesNotExistException $e) {
            throw new Exception('Could not find mailbox');
        } catch (MultipleObjectsReturnedException $e) {
            throw new Exception('Data inconsistency: multiple mailboxes with same email');
        }

        // Check if group is already added
        $existing = $this->accountMailboxMapper->findByMailboxIdAndGroupId($mailbox->getId(), $groupId, 'group');
        if (count($existing) > 0) {
            return; // Already added
        }

        // Add new group
        $accountMailbox = new AccountItslMailbox();
        $accountMailbox->setItslMailboxId($mailbox->getId());
        $accountMailbox->setAccessType('group');
        $accountMailbox->setGroupId($groupId);
        $this->accountMailboxMapper->insert($accountMailbox);

        // Schedule consolidation to add Mail accounts for group members
        $this->consolidateService->scheduleConsolidationIfNeeded();
    }

    /**
     * @throws DBException
     */
    public function removeGroupFromMailBox(string $messageType, string $email, string $groupId): void {
        try {
            $mailbox = $this->mailboxMapper->findByEmail($email);
            if ($mailbox->getMessageType() !== $messageType) {
                throw new Exception('Mailbox type mismatch');
            }
        } catch (DoesNotExistException $e) {
            throw new Exception('Could not find mailbox');
        } catch (MultipleObjectsReturnedException $e) {
            throw new Exception('Data inconsistency: multiple mailboxes with same email');
        }

        // Find and delete the group association
        $accountMailboxes = $this->accountMailboxMapper->findByMailboxIdAndGroupId($mailbox->getId(), $groupId, 'group');
        foreach ($accountMailboxes as $accountMailbox) {
            $this->accountMailboxMapper->delete($accountMailbox);
        }

        // Schedule consolidation to remove Mail accounts for group members (if no other access)
        $this->consolidateService->scheduleConsolidationIfNeeded();
    }

    /**
     * Create the Important tag for a new email address.
     *
     * Tags are scoped by email address (not user) to support shared mailboxes.
     * When a new email address is created, we need to create the Important tag for it.
     */
    private function createImportantTagForEmail(string $email): void {
        try {
            // Check if Important tag already exists for this email
            $this->itslTagMapper->getTagByImapLabel(Tag::LABEL_IMPORTANT, $email);
        } catch (DoesNotExistException $e) {
            // Create Important tag
            $tag = new ItslTag();
            $tag->setEmailAddress($email);
            $tag->setImapLabel(Tag::LABEL_IMPORTANT);  // '$label1'
            $tag->setDisplayName('Important');
            $tag->setColor('#FF7A66');  // Same color as mail app
            $tag->setIsDefaultTag(true);
            $this->itslTagMapper->insert($tag);
        }
    }
}
