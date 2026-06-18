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
class Version020000Date20250217180800 extends SimpleMigrationStep {
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

        if (!$schema->hasTable('sdkmc_message_thread')) {
            $table = $schema->createTable('sdkmc_message_thread');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'length' => 4,
            ]);
            $table->addColumn('message_id', Types::STRING, [
                'notnull' => true,
                'length' => 1023,
            ]);
            $table->addColumn('in_reply_to', Types::STRING, [
                'notnull' => false,
                'length' => 1023,
            ]);
            $table->addColumn('conversation_id', Types::STRING, [
                'notnull' => true,
                'length' => 1023,
            ]);
            $table->addColumn('sdk_message_id', Types::STRING, [
                'notnull' => true,
                'length' => 36,
            ]);
            $table->addColumn('sdk_in_reply_to', Types::STRING, [
                'notnull' => false,
                'length' => 36,
            ]);
            $table->addColumn('sdk_conversation_id', Types::STRING, [
                'notnull' => true,
                'length' => 36,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['sdk_message_id'], 'sdkmc_message_thread_id_sdk');
            $table->addUniqueIndex(['message_id'], 'sdkmc_message_thread_m_id');
            $table->addIndex(['sdk_conversation_id', 'id'], 'sdkmc_message_thread_ci_m_id');
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
