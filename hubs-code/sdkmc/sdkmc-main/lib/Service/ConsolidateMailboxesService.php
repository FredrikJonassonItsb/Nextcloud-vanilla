<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Service;

use OCA\Mail\Service\AccountService;
use OCA\SdkMc\BackgroundJob\ConsolidateMailboxesJob;
use OCA\SdkMc\Db\AccountItslMailboxMapper;
use OCA\SdkMc\Db\ItslMailbox;
use OCA\SdkMc\Db\ItslMailboxMapper;
use OCA\SdkMc\Db\ItslMessageTagMapper;
use OCA\SdkMc\Db\ItslTagMapper;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use OCA\Mail\Db\MailAccountMapper;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use Exception;

/**
 * Service for consolidating mailbox access based on user and group assignments.
 * Ensures Mail accounts are synced with effective permissions (direct + group membership).
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.ExcessiveParameterList")
 */
class ConsolidateMailboxesService {
    public function __construct(
        private AccountItslMailboxMapper $accountItslMailboxMapper,
        private ItslMailboxMapper $itslMailboxMapper,
        private IGroupManager $groupManager,
        private IUserManager $userManager,
        private AccountService $accountService,
        private BulkSetupService $setupService,
        private IAppConfig $appConfig,
        private BackgroundJobService $backgroundJobService,
        private LoggerInterface $logger,
        private IDBConnection $db,
        private MailAccountMapper $mailAccountMapper,
        private IEventDispatcher $eventDispatcher,
        private ItslTagMapper $tagMapper,
        private ItslMessageTagMapper $messageTagMapper,
    ) {
    }

    /**
     * Main entry point: consolidate all mailboxes
     * Syncs Mail accounts for all mailboxes based on effective access (direct + groups)
     */
    public function consolidateAllMailboxes(): void {
        $this->logger->info('Starting mailbox consolidation');

        // Get all mailboxes
        $mailboxes = $this->itslMailboxMapper->findAll();
        $this->logger->info('Found ' . count($mailboxes) . ' mailboxes to process');

        foreach ($mailboxes as $mailbox) {
            try {
                $this->syncMailboxAccess($mailbox);
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Failed to sync mailbox ' . $mailbox->getEmail() . ': ' . $e->getMessage(),
                    ['exception' => $e]
                );
                // Continue with other mailboxes
            }
        }

        $this->logger->info('Mailbox consolidation completed successfully');
    }

    /**
     * Calculate who SHOULD have access to a mailbox (direct users + group members)
     *
     * @param int $mailboxId
     * @return array<string> Array of user IDs who should have access
     */
    public function calculateEffectiveUsers(int $mailboxId): array {
        $effectiveUsers = [];

        // Get all assignments for this mailbox
        $assignments = $this->accountItslMailboxMapper->findByMailboxId($mailboxId);

        foreach ($assignments as $assignment) {
            $accountId = $assignment->getAccountId();
            $groupId = $assignment->getGroupId();

            if ($assignment->getAccessType() === 'user' && $accountId !== null) {
                $effectiveUsers[$accountId] = true;
            } elseif ($assignment->getAccessType() === 'absence' && $accountId !== null) {
                $endTime = $assignment->getEndTime();
                if ($endTime === null || $endTime > time()) {
                    $effectiveUsers[$accountId] = true;
                }
            } elseif ($assignment->getAccessType() === 'group' && $groupId !== null) {
                $group = $this->groupManager->get($groupId);
                if ($group === null) {
                    $this->logger->warning(
                        'Group ' . $groupId . ' not found, skipping'
                    );
                    continue;
                }

                $members = $group->getUsers();
                foreach ($members as $user) {
                    $effectiveUsers[$user->getUID()] = true;
                }
            }
        }

        return array_keys($effectiveUsers);
    }

    /**
     * Get users who currently have Mail accounts for this mailbox
     *
     * @param ItslMailbox $mailbox
     * @return array<string> Array of user IDs with existing accounts
     */
    public function getCurrentMailAccountUsers(ItslMailbox $mailbox): array {
        $email = $mailbox->getEmail();

        // Query mail_accounts table directly to find ALL users with Mail accounts for this email
        // This ensures we detect users who were removed from assignments but still have accounts
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('user_id')
            ->from('mail_accounts')
            ->where($qb->expr()->eq('email', $qb->createNamedParameter($email)));

        $result = $qb->executeQuery();
        $userIds = [];
        while ($row = $result->fetch()) {
            if (!is_array($row) || !isset($row['user_id']) || !is_string($row['user_id'])) {
                continue;
            }

            $userId = $row['user_id'];
            // Verify user still exists in Nextcloud
            if ($this->userManager->get($userId) === null) {
                $this->logger->debug(
                    "User {$userId} has Mail account for {$email} but user doesn't exist - skipping"
                );
                continue;
            }

            $userIds[] = $userId;
        }
        $result->closeCursor();

        $this->logger->debug('Found ' . count($userIds) . " users with Mail accounts for {$email}");
        return $userIds;
    }

    /**
     * Sync a single mailbox: add missing accounts and remove extra ones
     *
     * @param ItslMailbox $mailbox
     */
    public function syncMailboxAccess(ItslMailbox $mailbox): void {
        $email = $mailbox->getEmail();
        $this->logger->debug('Syncing mailbox: ' . $email);

        // Calculate who should have access
        $shouldHaveAccess = $this->calculateEffectiveUsers($mailbox->getId());
        $this->logger->debug('Users who should have access: ' . count($shouldHaveAccess));

        // Sync assignment tags for users with access
        $this->syncAssignmentTags($mailbox, $shouldHaveAccess);

        // Get who currently has access
        $currentlyHaveAccess = $this->getCurrentMailAccountUsers($mailbox);
        $this->logger->debug('Users who currently have access: ' . count($currentlyHaveAccess));

        // Add missing accounts
        $toAdd = array_diff($shouldHaveAccess, $currentlyHaveAccess);
        foreach ($toAdd as $userId) {
            /** @phpstan-ignore cast.useless (PHP converts numeric string array keys to int at runtime) */
            $userId = (string)$userId;
            try {
                $this->createMailAccount($mailbox, $userId);
                $this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent('ConsolidateMailboxesJob: Added Mail account for user [%s] to mailbox [%s]', [$userId, $email]));
                $this->logger->info("Added Mail account for user {$userId} to mailbox {$email}");
            } catch (\Throwable $e) {
                $this->logger->error(
                    "Failed to add Mail account for user {$userId} to mailbox {$email}: " . $e->getMessage(),
                    ['exception' => $e]
                );
            }
        }

        // Remove extra accounts
        $toRemove = array_diff($currentlyHaveAccess, $shouldHaveAccess);
        foreach ($toRemove as $userId) {
            /** @phpstan-ignore cast.useless (PHP converts numeric string array keys to int at runtime) */
            $userId = (string)$userId;
            try {
                $this->deleteMailAccount($mailbox, $userId);
                $this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent('ConsolidateMailboxesJob: Removed Mail account for user [%s] from mailbox [%s]', [$userId, $email]));
                $this->logger->info("Removed Mail account for user {$userId} from mailbox {$email}");
            } catch (\Throwable $e) {
                $this->logger->error(
                    "Failed to remove Mail account for user {$userId} from mailbox {$email}: " . $e->getMessage(),
                    ['exception' => $e]
                );
            }
        }

        $this->logger->debug(
            "Mailbox {$email} synced: added " . count($toAdd) . ', removed ' . count($toRemove)
        );
    }

    /**
     * Create a Mail account for a user
     *
     * @param ItslMailbox $mailbox
     * @param string $userId
     */
    private function createMailAccount(ItslMailbox $mailbox, string $userId): void {
        $emailAddress = $mailbox->getEmail();
        $password = $mailbox->getPassword();
        $messageType = $mailbox->getMessageType();

        $accountName = $messageType === 'sdk' && $mailbox->getSdkAddress() !== null
            ? $mailbox->getSdkAddress()
            : $mailbox->getName();

        if ($accountName === null || $accountName === '') {
            throw new Exception('Could not find account name');
        }

        $imapHost = $this->appConfig->getAppValueString('imapHost');
        $imapPort = $this->appConfig->getAppValueInt('imapPort');
        $imapSslMode = 'none';
        $smtpHost = $this->appConfig->getAppValueString('smtpHost');
        $smtpPort = $this->appConfig->getAppValueInt('smtpPort');
        $smtpSslMode = 'none';
        $authMethod = 'password';

        $account = $this->setupService->createNewAccount(
            $accountName,
            $emailAddress,
            $imapHost,
            $imapPort,
            $imapSslMode,
            $emailAddress, // IMAP user
            $password,
            $smtpHost,
            $smtpPort,
            $smtpSslMode,
            $emailAddress, // SMTP user
            $password,
            $userId,
            $authMethod
        );
        $mailAccount = $this->mailAccountMapper->findById($account->getId());

        // Copy signature from an existing account for the same email
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('signature', 'signature_above_quote')
                ->from('mail_accounts')
                ->where($qb->expr()->eq('email', $qb->createNamedParameter($emailAddress)))
                ->andWhere($qb->expr()->isNotNull('signature'))
                ->andWhere($qb->expr()->neq('signature', $qb->createNamedParameter('')))
                ->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($mailAccount->getId())))
                ->setMaxResults(1);
            $result = $qb->executeQuery();
            /** @var array{signature: string, signature_above_quote: mixed}|false $donor */
            $donor = $result->fetch();
            $result->closeCursor();
            if ($donor !== false) {
                $mailAccount->setSignature($donor['signature']);
                $mailAccount->setSignatureAboveQuote(
                    in_array($donor['signature_above_quote'], [true, 1, '1', 't'], true)
                );
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to copy signature for {email}: {message}', [
                'email' => $emailAddress,
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }

        $mailAccount->setSieveEnabled(true);
        $mailAccount->setSieveHost($imapHost);
        $mailAccount->setSievePort(4190);
        $mailAccount->setSieveUser(null);
        $mailAccount->setSievePassword(null);
        $mailAccount->setSieveSslMode('none');
        $this->mailAccountMapper->save($mailAccount);
    }

    /**
     * Delete Mail account(s) for a user
     *
     * @param ItslMailbox $mailbox
     * @param string $userId
     */
    private function deleteMailAccount(ItslMailbox $mailbox, string $userId): void {
        $email = $mailbox->getEmail();
        $accounts = $this->accountService->findByUserIdAndAddress($userId, $email);

        foreach ($accounts as $account) {
            $this->accountService->delete($userId, $account->getId());
        }
    }

    /**
     * Check if a user has access to a mailbox via group membership
     * (used when removing direct user assignments)
     *
     * @param string $userId
     * @param int $mailboxId
     * @return bool
     */
    public function userHasGroupAccess(string $userId, int $mailboxId): bool {
        $assignments = $this->accountItslMailboxMapper->findByMailboxId($mailboxId);

        foreach ($assignments as $assignment) {
            $groupId = $assignment->getGroupId();
            if ($assignment->getAccessType() === 'group' && $groupId !== null) {
                $group = $this->groupManager->get($groupId);
                if ($group !== null) {
                    $user = $this->getUserObject($userId);
                    if ($user !== null && $group->inGroup($user)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Get user object from user ID
     *
     * @param string $userId
     * @return \OCP\IUser|null
     */
    private function getUserObject(string $userId): ?\OCP\IUser {
        return $this->userManager->get($userId);
    }

    /**
     * Schedule a consolidation job and execute it immediately in the background
     */
    public function scheduleConsolidationIfNeeded(): void {
        $this->backgroundJobService->executeNow(
            ConsolidateMailboxesJob::class,
            null,
            true
        );
    }

    /**
     * Sync assignment tags for users with mailbox access.
     * Creates tags for new users, updates display names, cleans up orphans.
     *
     * @param ItslMailbox $mailbox
     * @param array<string> $effectiveUsers Users who should have access
     */
    private function syncAssignmentTags(ItslMailbox $mailbox, array $effectiveUsers): void {
        $email = $mailbox->getEmail();

        // Create/update tags for users who have access
        foreach ($effectiveUsers as $userId) {
            /** @phpstan-ignore cast.useless (PHP converts numeric string array keys to int at runtime) */
            $userId = (string)$userId;
            $user = $this->userManager->get($userId);
            if ($user === null) {
                continue;
            }

            $displayName = $user->getDisplayName();
            if ($displayName === '') {
                $displayName = $userId;
            }
            $imapLabel = '$assignee_' . $this->sanitizeUsernameForImap($userId);
            $color = $this->generateDeterministicColor($userId);

            try {
                $this->tagMapper->getOrCreateAssignmentTag($email, $imapLabel, $displayName, $color, $userId);
            } catch (\Throwable $e) {
                $this->logger->error(
                    "Failed to create/update assignment tag for user {$userId} in mailbox {$email}: " . $e->getMessage(),
                    ['exception' => $e]
                );
            }
        }

        // Cleanup orphaned assignment tags
        $this->cleanupOrphanedAssignmentTags($mailbox, $effectiveUsers);
    }

    /**
     * Remove assignment tags for users who no longer have access,
     * but only if no messages are tagged with them.
     *
     * @param ItslMailbox $mailbox
     * @param array<string> $effectiveUsers Current users with access
     */
    private function cleanupOrphanedAssignmentTags(ItslMailbox $mailbox, array $effectiveUsers): void {
        $email = $mailbox->getEmail();

        try {
            $assignmentTags = $this->tagMapper->getAssignmentTagsForMailbox($email);
        } catch (\Throwable $e) {
            $this->logger->error(
                "Failed to get assignment tags for mailbox {$email}: " . $e->getMessage(),
                ['exception' => $e]
            );
            return;
        }

        // Build set of current userIds for fast lookup
        $currentUserIds = [];
        foreach ($effectiveUsers as $userId) {
            /** @phpstan-ignore cast.useless (PHP converts numeric string array keys to int at runtime) */
            $userId = (string)$userId;
            $currentUserIds[$userId] = true;
        }

        foreach ($assignmentTags as $tag) {
            $imapLabel = $tag->getImapLabel();
            $tagUsername = $tag->getUsername();

            // Skip if user still has access (check by username)
            if ($tagUsername !== null && isset($currentUserIds[$tagUsername])) {
                continue;
            }

            // Check if any messages are tagged
            try {
                $messageIds = $this->messageTagMapper->getMessagesByTag($tag->getId(), $email);

                if (count($messageIds) > 0) {
                    // Convert to normal tag - user lost access but messages are tagged
                    // KEEP username so tag can be reconverted when user regains access
                    $tag->setIsAssignmentTag(false);
                    // DO NOT set username to null - it's the stable identifier for reconversion
                    $this->tagMapper->update($tag);
                    $this->logger->info(
                        "Converted assignment tag {$imapLabel} for {$email} to normal tag - "
                        . count($messageIds) . ' messages still tagged'
                    );
                    continue;
                }

                // Safe to delete - no messages use this tag
                $this->tagMapper->delete($tag);
                $this->logger->info("Deleted orphaned assignment tag {$imapLabel} for {$email}");
            } catch (\Throwable $e) {
                $this->logger->error(
                    "Failed to cleanup assignment tag {$imapLabel} for {$email}: " . $e->getMessage(),
                    ['exception' => $e]
                );
            }
        }
    }

    /**
     * Sanitize username for IMAP atom syntax.
     * RFC 3501: cannot contain ( ) { SPACE CTL % * " \ ]
     *
     * @param string $username
     * @return string Max 55 chars (64 - 9 for '$assignee_' prefix)
     */
    private function sanitizeUsernameForImap(string $username): string {
        // Replace non-alphanumeric with underscore
        $sanitized = preg_replace('/[^a-zA-Z0-9]/', '_', $username) ?? $username;
        // Collapse multiple underscores
        $sanitized = preg_replace('/_+/', '_', $sanitized) ?? $sanitized;
        // Trim underscores from ends
        $sanitized = trim($sanitized, '_');
        // Limit length (64 max - 9 for prefix = 55)
        return substr(strtolower($sanitized), 0, 55);
    }

    /**
     * Generate deterministic color based on username hash.
     * Follows ITSL contrast strategy from TagModal.vue.
     *
     * @param string $username
     * @return string Hex color like '#d77000'
     */
    private function generateDeterministicColor(string $username): string {
        // Use hash for determinism
        $hash = crc32($username);

        // Ensure positive value for consistent modulo operations
        $hash = abs($hash);

        $strategy = $hash % 4;

        if ($strategy === 0) {
            // Dark color: all channels low (00-77 = 0-119)
            $r = (($hash >> 8) & 0xFF) % 120;
            $g = (($hash >> 16) & 0xFF) % 120;
            $b = ($hash & 0xFF) % 120;
            return sprintf('#%02x%02x%02x', $r, $g, $b);
        }

        // Saturated: one dominant (CC-FF), others low (00-55)
        $dominant = (($hash >> 4) & 0xFF) % 3;
        $high = 0xCC + ((($hash >> 12) & 0xFF) % 0x34);  // CC-FF
        $low1 = (($hash >> 20) & 0xFF) % 0x56;           // 00-55
        $low2 = ($hash & 0xFF) % 0x56;                   // 00-55

        if ($dominant === 0) {
            return sprintf('#%02x%02x%02x', $high, $low1, $low2);
        }

        if ($dominant === 1) {
            return sprintf('#%02x%02x%02x', $low1, $high, $low2);
        }

        return sprintf('#%02x%02x%02x', $low1, $low2, $high);
    }
}
