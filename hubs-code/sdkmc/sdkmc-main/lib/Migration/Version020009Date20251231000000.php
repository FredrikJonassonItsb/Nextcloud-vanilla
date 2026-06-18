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
 * Add is_assignment_tag column to sdkmc_itsl_tag table for assignment tag support.
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 */
class Version020009Date20251231000000 extends SimpleMigrationStep {
    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('sdkmc_itsl_tag')) {
            $table = $schema->getTable('sdkmc_itsl_tag');
            if (!$table->hasColumn('is_assignment_tag')) {
                $table->addColumn('is_assignment_tag', Types::BOOLEAN, [
                    'notnull' => false,
                    'default' => false,
                ]);
            }
        }

        return $schema;
    }
}
