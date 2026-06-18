<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Drop UNIQUE index on sdk_message_id and replace with regular INDEX.
 * Local delivery creates multiple thread rows for the same SDK message
 * (one per recipient mailbox), so sdk_message_id cannot be unique.
 *
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 */
class Version020018Date20260324000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('sdkmc_message_thread')) {
            $table = $schema->getTable('sdkmc_message_thread');

            if ($table->hasIndex('sdkmc_message_thread_id_sdk')) {
                $table->dropIndex('sdkmc_message_thread_id_sdk');
            }

            if (!$table->hasIndex('sdkmc_message_thread_sdk_mid')) {
                $table->addIndex(['sdk_message_id'], 'sdkmc_message_thread_sdk_mid');
            }
        }

        return $schema;
    }
}
