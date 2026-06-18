<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use RuntimeException;

/**
 * Add unique index on (alias, message_type) to prevent duplicate aliases within the same type.
 * Fixes #246.
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 */
class Version020015Date20260215000000 extends SimpleMigrationStep {
    public function __construct(
        private IDBConnection $db,
    ) {
    }

    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $qb = $this->db->getQueryBuilder();

        $qb->select('alias', 'message_type')
            ->selectAlias($qb->createFunction('COUNT(*)'), 'cnt')
            ->from('sdkmc_itsl_mailbox')
            ->groupBy('alias', 'message_type')
            ->having($qb->expr()->gt($qb->createFunction('COUNT(*)'), $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        /** @var list<array{alias: string, message_type: string, cnt: string}> $duplicates */
        $duplicates = $result->fetchAll();
        $result->closeCursor();

        if (count($duplicates) > 0) {
            foreach ($duplicates as $dup) {
                $output->warning('Duplicate alias: "' . $dup['alias'] . '" in type "' . $dup['message_type'] . '" (' . $dup['cnt'] . ' occurrences)');
            }
            throw new RuntimeException(
                'Cannot add unique index — duplicate (alias, message_type) pairs exist in sdkmc_itsl_mailbox. '
                . 'Please resolve duplicates manually before upgrading. '
                . 'Query: SELECT alias, message_type, COUNT(*) FROM *prefix*sdkmc_itsl_mailbox '
                . 'GROUP BY alias, message_type HAVING COUNT(*) > 1;'
            );
        }
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('sdkmc_itsl_mailbox')) {
            $table = $schema->getTable('sdkmc_itsl_mailbox');

            if (!$table->hasIndex('sdkmc_itsl_mailbox_alias_type')) {
                $table->addUniqueIndex(['alias', 'message_type'], 'sdkmc_itsl_mailbox_alias_type');
                return $schema;
            }
        }

        return null;
    }
}
