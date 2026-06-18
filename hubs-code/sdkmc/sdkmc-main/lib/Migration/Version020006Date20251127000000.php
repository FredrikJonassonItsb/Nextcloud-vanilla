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
 * Add fields for temporary absence-based mailbox access
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 */
class Version020006Date20251127000000 extends SimpleMigrationStep {
    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('sdkmc_account_itsl_mailbox')) {
            $table = $schema->getTable('sdkmc_account_itsl_mailbox');

            // Add source_user_id field for tracking the absent user
            if (!$table->hasColumn('source_user_id')) {
                $table->addColumn('source_user_id', Types::STRING, [
                    'notnull' => false,
                    'length' => 64,
                    'default' => null,
                ]);
                $output->info('Added source_user_id column to sdkmc_account_itsl_mailbox table');
            }

            // Add end_time field for temporary access expiration
            if (!$table->hasColumn('end_time')) {
                $table->addColumn('end_time', Types::BIGINT, [
                    'notnull' => false,
                    'default' => null,
                ]);
                $output->info('Added end_time column to sdkmc_account_itsl_mailbox table');
            }

            return $schema;
        }

        return null;
    }
}
