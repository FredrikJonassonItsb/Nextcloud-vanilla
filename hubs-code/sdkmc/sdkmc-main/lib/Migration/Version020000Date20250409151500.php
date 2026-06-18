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
class Version020000Date20250409151500 extends SimpleMigrationStep {
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

        if (!$schema->hasTable('sdkmc_message_receipt')) {
            $table = $schema->createTable('sdkmc_message_receipt');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'length' => 4,
            ]);
            $table->addColumn('message_id', Types::STRING, [
                'notnull' => true,
                'length' => 1023,
            ]);
            $table->addColumn('document_reference', Types::STRING, [
                'notnull' => true,
                'length' => 1023,
            ]);
            $table->addColumn('status', Types::STRING, [
                'notnull' => true,
                'length' => 1023,
            ]);
            $table->addColumn('status_code', Types::STRING, [
                'notnull' => false,
                'length' => 1023,
            ]);
            $table->addColumn('status_reason', Types::STRING, [
                'notnull' => false,
                'length' => 1023,
            ]);
            $table->addColumn('receipt_data', Types::JSON, [
                'notnull' => false,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['message_id'], 'sdkmc_receipt_message_id');
            $table->addUniqueIndex(['document_reference'], 'sdkmc_receipt_doc_ref');
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
