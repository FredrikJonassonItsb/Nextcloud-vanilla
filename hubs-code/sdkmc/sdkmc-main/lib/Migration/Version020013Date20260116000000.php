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
 * Add index on (mailbox_id, thread_root_id) to mail_messages for efficient
 * thread-based tag searches. This index is needed for:
 * - The existing self-join to find latest message in thread
 * - Tag searches that need to find all messages in a thread
 * - NOT EXISTS subqueries for "no tags" searches
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 */
class Version020013Date20260116000000 extends SimpleMigrationStep {
    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('mail_messages')) {
            $table = $schema->getTable('mail_messages');
            if (!$table->hasIndex('mail_msg_mb_thread_root')) {
                $table->addIndex(['mailbox_id', 'thread_root_id'], 'mail_msg_mb_thread_root');
            }
        }

        return $schema;
    }
}
