<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migrate user-mailbox relationships from Mail app database to AccountItslMailbox table
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 */
class Version020005Date20251117160000 extends SimpleMigrationStep {
    public function __construct(
        private IDBConnection $db,
    ) {
    }

    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array $options
     */
    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options) {
    }

    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
        // No schema changes needed
        return null;
    }

    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array $options
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options) {
        $output->info('Migrating user-mailbox relationships from Mail app...');

        // Step 1: Get all Mail app accounts (user_id + email pairs)
        $qb = $this->db->getQueryBuilder();
        $qb->select('user_id', 'email')
            ->from('mail_accounts')
            ->where($qb->expr()->isNotNull('email'));

        $result = $qb->executeQuery();
        $mailAccounts = $result->fetchAll();
        $result->closeCursor();

        $output->info('Found ' . count($mailAccounts) . ' mail accounts');

        // Step 2: Build email -> [user_ids] mapping
        $emailToUsers = [];
        foreach ($mailAccounts as $account) {
            if (!is_array($account) || !isset($account['email'], $account['user_id'])) {
                continue;
            }

            if (!is_string($account['email']) || !is_string($account['user_id'])) {
                continue;
            }

            $email = $account['email'];
            $userId = $account['user_id'];

            if (!isset($emailToUsers[$email])) {
                $emailToUsers[$email] = [];
            }

            if (!in_array($userId, $emailToUsers[$email], true)) {
                $emailToUsers[$email][] = $userId;
            }
        }

        // Step 3: For each mailbox, create AccountItslMailbox entries for matching users
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'email', 'message_type')
            ->from('sdkmc_itsl_mailbox');

        $result = $qb->executeQuery();
        $mailboxes = $result->fetchAll();
        $result->closeCursor();

        $totalCreated = 0;
        $totalSkipped = 0;

        foreach ($mailboxes as $mailbox) {
            if (!is_array($mailbox) || !isset($mailbox['id'], $mailbox['email'], $mailbox['message_type'])) {
                continue;
            }

            if (!is_int($mailbox['id']) && !is_string($mailbox['id'])) {
                continue;
            }
            if (!is_string($mailbox['email']) || !is_string($mailbox['message_type'])) {
                continue;
            }

            $mailboxId = (int)$mailbox['id'];
            $email = $mailbox['email'];
            $messageType = $mailbox['message_type'];

            if (!isset($emailToUsers[$email])) {
                continue; // No users for this mailbox
            }

            foreach ($emailToUsers[$email] as $userId) {
                // Check if entry already exists
                $qb = $this->db->getQueryBuilder();
                $qb->select('id')
                    ->from('sdkmc_account_itsl_mailbox')
                    ->where(
                        $qb->expr()->eq('itsl_mailbox_id', $qb->createNamedParameter($mailboxId, IQueryBuilder::PARAM_INT)),
                        $qb->expr()->eq('access_type', $qb->createNamedParameter('user', IQueryBuilder::PARAM_STR)),
                        $qb->expr()->eq('account_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
                    );

                $result = $qb->executeQuery();
                $exists = $result->fetch();
                $result->closeCursor();

                if ($exists !== false) {
                    $totalSkipped++;
                    continue; // Already exists
                }

                // Create new entry
                $qb = $this->db->getQueryBuilder();
                $qb->insert('sdkmc_account_itsl_mailbox')
                    ->values([
                        'itsl_mailbox_id' => $qb->createNamedParameter($mailboxId, IQueryBuilder::PARAM_INT),
                        'access_type' => $qb->createNamedParameter('user', IQueryBuilder::PARAM_STR),
                        'account_id' => $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR),
                        'group_id' => $qb->createNamedParameter(null, IQueryBuilder::PARAM_STR),
                    ]);
                $qb->executeStatement();

                $totalCreated++;
                $output->info("  Created user association: {$userId} -> {$email} ({$messageType})");
            }
        }

        $output->info("Migration completed. Created: {$totalCreated}, Skipped (already existed): {$totalSkipped}");
    }
}
