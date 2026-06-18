<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Migration;

use Closure;
use OCP\AppFramework\Services\IAppConfig;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 */
class Version020004Date20251117150000 extends SimpleMigrationStep {
    public function __construct(
        private IDBConnection $db,
        private IAppConfig $appConfig,
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
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Create sdkmc_itsl_mailbox table
        if (!$schema->hasTable('sdkmc_itsl_mailbox')) {
            $table = $schema->createTable('sdkmc_itsl_mailbox');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'length' => 4,
            ]);
            $table->addColumn('name', Types::STRING, [
                'notnull' => true,
                'length' => 255,
                'default' => '',
            ]);
            $table->addColumn('description', Types::TEXT, [
                'notnull' => true,
                'default' => '',
            ]);
            $table->addColumn('alias', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('email', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('password', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('message_type', Types::STRING, [
                'notnull' => true,
                'length' => 50,
            ]);
            $table->addColumn('can_be_replied_to', Types::SMALLINT, [
                'notnull' => true,
                'default' => 1,
                'length' => 1,
            ]);
            $table->addColumn('can_message_be_sent_to', Types::SMALLINT, [
                'notnull' => true,
                'default' => 1,
                'length' => 1,
            ]);
            $table->addColumn('sdk_address', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);
            $table->addColumn('number', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['email'], 'sdkmc_itsl_mailbox_email');
            $table->addIndex(['message_type'], 'sdkmc_itsl_mailbox_type');
            $table->addUniqueIndex(['email', 'message_type'], 'sdkmc_itsl_mailbox_email_type');
        }

        // Create sdkmc_account_itsl_mailbox table
        if (!$schema->hasTable('sdkmc_account_itsl_mailbox')) {
            $table = $schema->createTable('sdkmc_account_itsl_mailbox');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'length' => 4,
            ]);
            $table->addColumn('itsl_mailbox_id', Types::BIGINT, [
                'notnull' => true,
                'length' => 4,
            ]);
            $table->addColumn('access_type', Types::STRING, [
                'notnull' => true,
                'length' => 20,
            ]);
            $table->addColumn('account_id', Types::STRING, [
                'notnull' => false,
                'length' => 64,
            ]);
            $table->addColumn('group_id', Types::STRING, [
                'notnull' => false,
                'length' => 64,
            ]);

            $table->setPrimaryKey(['id'], 'sdkmc_acct_mbox_pk');
            $table->addIndex(['itsl_mailbox_id'], 'sdkmc_acct_mbox_mbox_id');
            $table->addIndex(['account_id'], 'sdkmc_acct_mbox_acct_id');
            $table->addIndex(['group_id'], 'sdkmc_acct_mbox_grp_id');
        }

        return $schema;
    }

    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array $options
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options) {
        // Migrate data from appConfig to database
        $messageTypes = ['sdk', 'fax', 'gruppbox', 'personlig', 'sms'];

        foreach ($messageTypes as $messageType) {
            $output->info("Migrating $messageType mailboxes...");

            /** @var array<string, array<string, mixed>> $mailboxes */
            $mailboxes = $this->appConfig->getAppValueArray($messageType . 'MailBoxes', [], true);

            foreach ($mailboxes as $mailbox) {
                // Insert mailbox
                $qb = $this->db->getQueryBuilder();

                // Convert booleans to integers (0/1) for database compatibility
                $canBeRepliedTo = (bool)($mailbox['canBeRepliedTo'] ?? true) ? 1 : 0;
                $canMessageBeSentTo = (bool)($mailbox['canMessageBeSentTo'] ?? true) ? 1 : 0;

                $qb->insert('sdkmc_itsl_mailbox')
                    ->values([
                        'name' => $qb->createNamedParameter($mailbox['name'] ?? '', \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR),
                        'description' => $qb->createNamedParameter($mailbox['description'] ?? '', \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR),
                        'alias' => $qb->createNamedParameter($mailbox['alias'] ?? '', \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR),
                        'email' => $qb->createNamedParameter($mailbox['email'] ?? '', \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR),
                        'password' => $qb->createNamedParameter($mailbox['password'] ?? '', \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR),
                        'message_type' => $qb->createNamedParameter($messageType, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR),
                        'can_be_replied_to' => $qb->createNamedParameter($canBeRepliedTo, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
                        'can_message_be_sent_to' => $qb->createNamedParameter($canMessageBeSentTo, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
                        'sdk_address' => $qb->createNamedParameter($mailbox['sdkaddress'] ?? null, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR),
                        'number' => $qb->createNamedParameter($mailbox['number'] ?? null, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR),
                    ]);
                $qb->executeStatement();

                $mailboxId = $qb->getLastInsertId();

                // Migrate users
                if (isset($mailbox['users']) && is_array($mailbox['users'])) {
                    foreach ($mailbox['users'] as $userId) {
                        $qb = $this->db->getQueryBuilder();
                        $qb->insert('sdkmc_account_itsl_mailbox')
                            ->values([
                                'itsl_mailbox_id' => $qb->createNamedParameter($mailboxId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
                                'access_type' => $qb->createNamedParameter('user', \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR),
                                'account_id' => $qb->createNamedParameter($userId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR),
                                'group_id' => $qb->createNamedParameter(null, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR),
                            ]);
                        $qb->executeStatement();
                    }
                }

                // Migrate groups
                if (isset($mailbox['groups']) && is_array($mailbox['groups'])) {
                    foreach ($mailbox['groups'] as $groupId) {
                        $qb = $this->db->getQueryBuilder();
                        $qb->insert('sdkmc_account_itsl_mailbox')
                            ->values([
                                'itsl_mailbox_id' => $qb->createNamedParameter($mailboxId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT),
                                'access_type' => $qb->createNamedParameter('group', \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR),
                                'account_id' => $qb->createNamedParameter(null, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR),
                                'group_id' => $qb->createNamedParameter($groupId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR),
                            ]);
                        $qb->executeStatement();
                    }
                }

                $emailAddress = $mailbox['email'] ?? 'unknown';
                $output->info('  Migrated mailbox: ' . (is_string($emailAddress) ? $emailAddress : 'unknown'));
            }
        }

        $output->info('Migration completed. Original appConfig data preserved as backup.');
    }
}
