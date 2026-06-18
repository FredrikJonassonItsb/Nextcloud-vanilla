<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create sdkmc_itsl_tag and sdkmc_itsl_message_tag tables for email-based tag storage.
 * Migrates existing tags from mail app's user-based schema to email-based schema.
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 */
class Version020008Date20251229000000 extends SimpleMigrationStep {
    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('sdkmc_itsl_tag')) {
            $table = $schema->createTable('sdkmc_itsl_tag');

            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('email_address', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('imap_label', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('display_name', Types::STRING, [
                'notnull' => true,
                'length' => 128,
            ]);
            $table->addColumn('color', Types::STRING, [
                'notnull' => true,
                'length' => 9,
            ]);
            $table->addColumn('is_default_tag', Types::BOOLEAN, [
                'notnull' => false,
                'default' => false,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['email_address', 'imap_label'], 'sdkmc_itsl_tag_email_label');
            $table->addIndex(['email_address'], 'sdkmc_itsl_tag_email');
        }

        if (!$schema->hasTable('sdkmc_itsl_message_tag')) {
            $table = $schema->createTable('sdkmc_itsl_message_tag');

            $table->addColumn('id', Types::INTEGER, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('imap_message_id', Types::STRING, [
                'notnull' => true,
                'length' => 1023,
            ]);
            $table->addColumn('tag_id', Types::INTEGER, [
                'notnull' => true,
            ]);
            $table->addColumn('email_address', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['imap_message_id', 'email_address'], 'sdkmc_itsl_msg_tag_mid_email');
            $table->addIndex(['tag_id'], 'sdkmc_itsl_msg_tag_tid');
        }

        return $schema;
    }

    /**
     * Migrate existing tags from mail app's user-based schema to email-based schema.
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $this->migrateTagsTable($output);
        $this->migrateMessageTagsTable($output);
    }

    /**
     * Migrate tags from oc_mail_tags to sdkmc_itsl_tag.
     * For each tag, find all email addresses associated with the user's mail accounts.
     */
    private function migrateTagsTable(IOutput $output): void {
        $output->info('Migrating tags from mail_tags to sdkmc_itsl_tag...');

        // Get distinct (email, imap_label, display_name, color, is_default_tag) combinations
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct(['ma.email', 't.imap_label', 't.display_name', 't.color', 't.is_default_tag'])
            ->from('mail_tags', 't')
            ->innerJoin('t', 'mail_accounts', 'ma', $qb->expr()->eq('ma.user_id', 't.user_id'));

        $result = $qb->executeQuery();
        $count = 0;

        while (($row = $result->fetch()) !== false) {
            if (!is_array($row)) {
                continue;
            }
            /** @var array{email: string, imap_label: string, display_name: string, color: string, is_default_tag: bool|int} $row */
            $email = $row['email'];
            $imapLabel = $row['imap_label'];
            $displayName = $row['display_name'];
            $color = $row['color'];
            $isDefaultTag = (bool)$row['is_default_tag'];

            // Check if this (email, imap_label) combination already exists
            $checkQb = $this->db->getQueryBuilder();
            $checkQb->select('id')
                ->from('sdkmc_itsl_tag')
                ->where($checkQb->expr()->eq('email_address', $checkQb->createNamedParameter($email)))
                ->andWhere($checkQb->expr()->eq('imap_label', $checkQb->createNamedParameter($imapLabel)));
            $existing = $checkQb->executeQuery()->fetch();

            if ($existing === false) {
                // Insert new tag
                $insertQb = $this->db->getQueryBuilder();
                $insertQb->insert('sdkmc_itsl_tag')
                    ->setValue('email_address', $insertQb->createNamedParameter($email))
                    ->setValue('imap_label', $insertQb->createNamedParameter($imapLabel))
                    ->setValue('display_name', $insertQb->createNamedParameter($displayName))
                    ->setValue('color', $insertQb->createNamedParameter($color))
                    ->setValue('is_default_tag', $insertQb->createNamedParameter($isDefaultTag, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL));
                $insertQb->executeStatement();
                $count++;
            }
        }
        $result->closeCursor();

        $output->info("Migrated $count tags to sdkmc_itsl_tag");
    }

    /**
     * Migrate message tags from oc_mail_message_tags to sdkmc_itsl_message_tag.
     * Join through messages -> mailboxes -> accounts to get the email address.
     */
    private function migrateMessageTagsTable(IOutput $output): void {
        $output->info('Migrating message tags from mail_message_tags to sdkmc_itsl_message_tag...');

        // Get message tags with email addresses by joining through the mail app tables
        // mail_message_tags -> mail_tags (for user_id) -> mail_accounts (for email)
        // Also need to join mail_messages -> mail_mailboxes -> mail_accounts to verify the email
        $qb = $this->db->getQueryBuilder();
        $qb->select(['mt.imap_message_id', 'mt.tag_id', 't.imap_label', 'ma.email'])
            ->from('mail_message_tags', 'mt')
            ->innerJoin('mt', 'mail_tags', 't', $qb->expr()->eq('mt.tag_id', 't.id'))
            ->innerJoin('t', 'mail_accounts', 'ma', $qb->expr()->eq('ma.user_id', 't.user_id'));

        $result = $qb->executeQuery();
        $count = 0;
        $skipped = 0;

        while (($row = $result->fetch()) !== false) {
            if (!is_array($row)) {
                continue;
            }
            /** @var array{imap_message_id: string, email: string, imap_label: string, tag_id: int} $row */
            $imapMessageId = $row['imap_message_id'];
            $email = $row['email'];
            $imapLabel = $row['imap_label'];

            // Find the new tag_id in sdkmc_itsl_tag
            $tagQb = $this->db->getQueryBuilder();
            $tagQb->select('id')
                ->from('sdkmc_itsl_tag')
                ->where($tagQb->expr()->eq('email_address', $tagQb->createNamedParameter($email)))
                ->andWhere($tagQb->expr()->eq('imap_label', $tagQb->createNamedParameter($imapLabel)));
            $tagResult = $tagQb->executeQuery()->fetch();

            if ($tagResult === false || !is_array($tagResult)) {
                // Tag not found in new table - skip this orphaned entry
                $skipped++;
                continue;
            }

            /** @var array{id: int} $tagResult */
            $newTagId = $tagResult['id'];

            // Check if this combination already exists
            $checkQb = $this->db->getQueryBuilder();
            $checkQb->select('id')
                ->from('sdkmc_itsl_message_tag')
                ->where($checkQb->expr()->eq('imap_message_id', $checkQb->createNamedParameter($imapMessageId)))
                ->andWhere($checkQb->expr()->eq('tag_id', $checkQb->createNamedParameter($newTagId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($checkQb->expr()->eq('email_address', $checkQb->createNamedParameter($email)));
            $existing = $checkQb->executeQuery()->fetch();

            if ($existing === false) {
                // Insert new message tag
                $insertQb = $this->db->getQueryBuilder();
                $insertQb->insert('sdkmc_itsl_message_tag')
                    ->setValue('imap_message_id', $insertQb->createNamedParameter($imapMessageId))
                    ->setValue('tag_id', $insertQb->createNamedParameter($newTagId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
                    ->setValue('email_address', $insertQb->createNamedParameter($email));
                $insertQb->executeStatement();
                $count++;
            }
        }
        $result->closeCursor();

        $output->info("Migrated $count message tags to sdkmc_itsl_message_tag (skipped $skipped orphaned entries)");
    }
}
