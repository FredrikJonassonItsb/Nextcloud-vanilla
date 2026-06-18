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
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add notification_email column to sdkmc_itsl_mailbox table.
 * Create sdkmc_mbox_notif_log table to track sent notifications
 * and prevent duplicate notifications when multiple users share a mailbox.
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 */
class Version020014Date20260123000000 extends SimpleMigrationStep {
    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Add notification_email column to sdkmc_itsl_mailbox
        if ($schema->hasTable('sdkmc_itsl_mailbox')) {
            $table = $schema->getTable('sdkmc_itsl_mailbox');
            if (!$table->hasColumn('notification_email')) {
                $table->addColumn('notification_email', Types::TEXT, [
                    'notnull' => false,
                    'default' => null,
                ]);
            }
        }

        // Create notification log table
        if (!$schema->hasTable('sdkmc_mbox_notif_log')) {
            $table = $schema->createTable('sdkmc_mbox_notif_log');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'length' => 4,
            ]);
            $table->addColumn('recipient', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('message_id', Types::BIGINT, [
                'notnull' => true,
                'length' => 4,
            ]);
            $table->addColumn('sent_at', Types::DATETIME, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            // Index for fast duplicate checking
            $table->addIndex(['recipient', 'message_id'], 'sdkmc_mbn_log_idx');
        }

        return $schema;
    }
}
