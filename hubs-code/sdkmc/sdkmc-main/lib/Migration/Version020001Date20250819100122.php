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
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 */
class Version020001Date20250819100122 extends SimpleMigrationStep {
    /**
     * @param IOutput $output
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array $options
     */
    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
    }

    /**
     * @param IOutput $output
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $tableName = 'sdkmc_conv_bank_auth';

        if (!$schema->hasTable($tableName)) {
            $table = $schema->createTable($tableName);

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => false,
                'length' => 4,
            ]);

            $table->addColumn('conversation_id', Types::STRING, [
                'notnull' => false,
                'length' => 32,
            ]);

            $table->addColumn('email', Types::STRING, [
                'notnull' => false,
                'length' => 190,
            ]);

            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);

            $table->addIndex(['conversation_id', 'email'], 'sdkmc_cba_conv_email_idx');
        }

        return $schema;
    }

    /**
     * @param IOutput $output
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array $options
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
    }
}
