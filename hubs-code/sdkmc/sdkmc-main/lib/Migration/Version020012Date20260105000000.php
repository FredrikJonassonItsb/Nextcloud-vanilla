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
 * Add deleted_at column to sdkmc_itsl_tag for soft-delete support.
 * Tags with deleted_at set are pending cleanup by DeleteTagsJob.
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 */
class Version020012Date20260105000000 extends SimpleMigrationStep {
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
            if (!$table->hasColumn('deleted_at')) {
                $table->addColumn('deleted_at', Types::DATETIME, [
                    'notnull' => false,
                    'default' => null,
                ]);
            }
            // Index for efficient lookup of pending deletions
            if (!$table->hasIndex('sdkmc_itsl_tag_deleted')) {
                $table->addIndex(['deleted_at'], 'sdkmc_itsl_tag_deleted');
            }
        }

        return $schema;
    }
}
