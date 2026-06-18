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
class Version020000Date20250505212500 extends SimpleMigrationStep {
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

        if (!$schema->hasTable('sdkmc_sdk_log')) {
            $table = $schema->createTable('sdkmc_sdk_log');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => false,
                'length' => 4,
            ]);
            $table->addColumn('message_type', Types::STRING, [
                'notnull' => false,
                'length' => 254,
            ]);
            $table->addColumn('ap_id', Types::STRING, [
                'notnull' => false,
                'length' => 254,
            ]);
            $table->addColumn('creation_date_time', Types::DATETIME, [
                'notnull' => false,
            ]);
            $table->addColumn('from_client', Types::DATETIME, [
                'notnull' => false,
            ]);
            $table->addColumn('to_client', Types::DATETIME, [
                'notnull' => false,
            ]);
            $table->addColumn('from_ap', Types::DATETIME, [
                'notnull' => false,
            ]);
            $table->addColumn('to_ap', Types::DATETIME, [
                'notnull' => false,
            ]);
            $table->addColumn('sender', Types::STRING, [
                'notnull' => false,
                'length' => 254,
            ]);
            $table->addColumn('sender_attention', Types::STRING, [
                'notnull' => false,
                'length' => 254,
            ]);
            $table->addColumn('recipient', Types::STRING, [
                'notnull' => false,
                'length' => 254,
            ]);
            $table->addColumn('recipient_attention', Types::STRING, [
                'notnull' => false,
                'length' => 254,
            ]);
            $table->addColumn('message_id_as4', Types::STRING, [
                'notnull' => false,
                'length' => 36,
            ]);
            $table->addColumn('message_id', Types::STRING, [
                'notnull' => false,
                'length' => 36,
            ]);
            $table->addColumn('conversation_id', Types::STRING, [
                'notnull' => false,
                'length' => 36,
            ]);
            $table->addColumn('address_book_copy', Types::STRING, [
                'notnull' => false,
                'length' => 36,
            ]);
            $table->addColumn('log_data', Types::JSON, [
                'notnull' => false,
                'length' => 4096,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['message_id'], 'sdkmc_sdk_log_message_id');
        }
        return $schema;
    }

    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array $options
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options) {
    }
}
