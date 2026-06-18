<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Service;

use Exception;
use OCA\SdkMc\Utils\NameCleaner;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\String\UnicodeString;

class ProvisionPersonligAccountsService {
    public function __construct(
        private LoggerInterface $logger,
        private ItslAccountService $accountService,
        private ConsolidateMailboxesService $consolidateService,
        private IGroupManager $groupManager,
    ) {
    }

    public function provisionAccounts(?string $groupId = null): void {
        $allUsers = $this->getUsers($groupId);
        if ($allUsers === null) {
            return;
        }

        // Get all existing personlig mailboxes
        $personligMailboxes = $this->accountService->getMailBoxes('personlig');

        // Build set of users who already have a personlig account
        $provisionedUsers = [];
        foreach ($personligMailboxes as $mailbox) {
            foreach ($mailbox['users'] ?? [] as $userId) {
                $provisionedUsers[$userId] = true;
            }
        }

        // Collect taken aliases (from DB + created this run)
        $takenAliases = [];
        foreach ($personligMailboxes as $mailbox) {
            $takenAliases[$mailbox['alias']] = true;
        }

        $this->logger->info('Found ' . count($personligMailboxes) . ' existing personlig accounts');

        $created = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($allUsers as $userId => $userData) {
            // Skip users who already have a personlig account
            if (isset($provisionedUsers[$userId])) {
                $this->logger->debug('User ' . $userId . ' already has a personlig account, skipping');
                $skipped++;
                continue;
            }

            // Get display name, fallback to userId if not available
            $displayName = isset($userData['displayName']) && is_string($userData['displayName']) ? $userData['displayName'] : $userId;

            // Generate alias from display name
            $cleanName = NameCleaner::cleanName($displayName);
            $alias = (new UnicodeString($cleanName))->ascii()->toString();
            $alias = strtolower($alias);
            $alias = str_replace(' ', '.', $alias);
            $alias = preg_replace('/[^a-z0-9._-]/', '', $alias) ?? '';
            $alias = preg_replace('/\.+/', '.', $alias) ?? $alias;
            $alias = trim($alias, '.-_');

            // Enforce RFC 5321 max 64 chars for local-part
            if (strlen($alias) > 64) {
                $alias = rtrim(substr($alias, 0, 64), '.-_');
            }

            // Empty alias guard — fall back to userId
            if ($alias === '') {
                $alias = preg_replace('/[^a-z0-9._-]/', '', strtolower($userId)) ?? '';
                $alias = trim($alias, '.-_');
                if ($alias === '') {
                    $this->logger->warning('Skipping user ' . $userId . ': cannot generate valid alias');
                    $failed++;
                    continue;
                }
            }

            // Resolve alias collisions by appending a counter suffix
            $baseAlias = $alias;
            $counter = 1;
            while (isset($takenAliases[$alias])) {
                $suffix = '_' . $counter;
                $alias = substr($baseAlias, 0, 64 - strlen($suffix)) . $suffix;
                $counter++;
            }

            try {
                $email = $alias . '@personlig';
                $settings = [
                    'name' => $displayName,
                    'description' => '',
                    'canBeRepliedTo' => true,
                    'canMessageBeSentTo' => false,
                ];

                $this->accountService->addAccount('personlig', $email, $alias, $settings);
                $this->logger->info('Created personlig account for user ' . $userId . ' with email ' . $email);

                $this->accountService->addUserToMailBox('personlig', $email, $userId, scheduleConsolidation: false);
                $this->logger->info('Added user ' . $userId . ' to their personlig mailbox');

                // Track new alias and user to prevent within-run collisions
                $takenAliases[$alias] = true;
                $provisionedUsers[$userId] = true;

                $created++;
            } catch (Exception $e) {
                $this->logger->error('Failed to provision personlig account for user ' . $userId . ': ' . $e->getMessage());
                $failed++;
            }
        }

        if ($created > 0) {
            $this->consolidateService->scheduleConsolidationIfNeeded();
        }

        $this->logger->info('Personlig accounts provisioning completed. Created: ' . $created . ', Skipped: ' . $skipped . ', Failed: ' . $failed);
    }

    /**
     * @return array<string, array<string, mixed>>|null
     */
    private function getUsers(?string $groupId): ?array {
        if ($groupId === null) {
            $this->logger->info('Starting personlig accounts provisioning for all users');
            $allUsers = $this->accountService->getAllUsers();
            $this->logger->info('Found ' . count($allUsers) . ' users in the system');
            return $allUsers;
        }

        $this->logger->info('Starting personlig accounts provisioning for group: ' . $groupId);
        $group = $this->groupManager->get($groupId);
        if ($group === null) {
            $this->logger->error('Group not found: ' . $groupId);
            return null;
        }

        $allUsers = [];
        foreach ($group->getUsers() as $user) {
            $allUsers[$user->getUID()] = ['userId' => $user->getUID(), 'displayName' => $user->getDisplayName()];
        }
        $this->logger->info('Found ' . count($allUsers) . ' users in group ' . $groupId);
        return $allUsers;
    }
}
