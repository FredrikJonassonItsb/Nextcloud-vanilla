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
 * Create sdkmc_mailbox_retention table for per-mailbox folder retention overrides
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 */
class Version020007Date20251221120000 extends SimpleMigrationStep {
    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('sdkmc_mailbox_retention')) {
            $table = $schema->createTable('sdkmc_mailbox_retention');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('email', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('folder', Types::STRING, [
                'notnull' => true,
                'length' => 50,
            ]);
            $table->addColumn('retention_days', Types::INTEGER, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['email'], 'sdkmc_mbox_ret_email');
            $table->addUniqueIndex(['email', 'folder'], 'sdkmc_mbox_ret_email_folder');
        }

        return $schema;
    }
}
