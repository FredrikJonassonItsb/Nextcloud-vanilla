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
 * Add sms_number and loa_level columns to sdkmc_message_metadata table for LOA-2 support
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 */
class Version020006Date20251215000000 extends SimpleMigrationStep {
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

        $table = $schema->getTable('sdkmc_message_metadata');

        // Add sms_number column for LOA-2 SMS verification
        $table->addColumn('sms_number', Types::STRING, [
            'notnull' => false,
            'length' => 32,
            'default' => null,
        ]);

        // Add loa_level column to track Level of Assurance (1=LOA-1, 2=LOA-2, 3=LOA-3)
        $table->addColumn('loa_level', Types::SMALLINT, [
            'notnull' => true,
            'length' => 1,
            'default' => 1,
        ]);

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
