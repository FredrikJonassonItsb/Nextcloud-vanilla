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
 * Add SSN hash and visibility fields to conversation_bankid_auth table
 */
class Version020002Date20241015000000 extends SimpleMigrationStep {
    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array $options
     * @return null|ISchemaWrapper
     * @SuppressWarnings(PHPMD)
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $table = $schema->getTable('sdkmc_conv_bank_auth');

        // Add Required SSN column (nullable for optional SSN)
        if (!$table->hasColumn('required_ssn')) {
            $table->addColumn('required_ssn', Types::STRING, [
                'notnull' => false,
                'length' => 64,
                'default' => null,
            ]);
        }
        // Add last used SSN column for full identify reveal
        if (!$table->hasColumn('last_used_ssn')) {
            $table->addColumn('last_used_ssn', Types::STRING, [
                'notnull' => false,
                'length' => 64,
                'default' => null,
            ]);
        }

        // Add visibility columns (all default to true)
        if (!$table->hasColumn('show_first_name')) {
            $table->addColumn('show_first_name', Types::BOOLEAN, [
                'notnull' => false,
                'default' => 1,
            ]);
        }

        if (!$table->hasColumn('show_last_name')) {
            $table->addColumn('show_last_name', Types::BOOLEAN, [
                'notnull' => false,
                'default' => 1,
            ]);
        }

        if (!$table->hasColumn('show_ssn')) {
            $table->addColumn('show_ssn', Types::BOOLEAN, [
                'notnull' => false,
                'default' => 1,
            ]);
        }

        if (!$table->hasColumn('first_name')) {
            $table->addColumn('first_name', Types::STRING, [
                'notnull' => false,
                'length' => 100,
                'default' => null,
            ]);
        }
        if (!$table->hasColumn('last_name')) {
            $table->addColumn('last_name', Types::STRING, [
                'notnull' => false,
                'length' => 100,
                'default' => null,
            ]);
        }

        if (!$table->hasColumn('actor_id')) {
            $table->addColumn('actor_id', Types::STRING, [
                'notnull' => false,
                'length' => 64,
                'default' => null,
            ]);
        }

        if (!$table->hasColumn('account_id')) {
            $table->addColumn('account_id', Types::INTEGER, [
                'notnull' => false,
                'default' => null,
            ]);
        }

        return $schema;
    }
}
