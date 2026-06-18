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
 * Add unique index on sdk_address to prevent duplicate SDK addresses.
 * Fixes #242.
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 */
class Version020014Date20260211000000 extends SimpleMigrationStep {
    public function __construct(
        private IDBConnection $db,
    ) {
    }

    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $qb = $this->db->getQueryBuilder();

        $qb->select('sdk_address')
            ->selectAlias($qb->createFunction('COUNT(*)'), 'cnt')
            ->from('sdkmc_itsl_mailbox')
            ->where($qb->expr()->isNotNull('sdk_address'))
            ->groupBy('sdk_address')
            ->having($qb->expr()->gt($qb->createFunction('COUNT(*)'), $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        /** @var list<array{sdk_address: string, cnt: string}> $duplicates */
        $duplicates = $result->fetchAll();
        $result->closeCursor();

        if (count($duplicates) > 0) {
            foreach ($duplicates as $dup) {
                $output->warning('Duplicate SDK address: "' . $dup['sdk_address'] . '" (' . $dup['cnt'] . ' occurrences)');
            }
            throw new RuntimeException(
                'Cannot add unique index — duplicate SDK addresses exist in sdkmc_itsl_mailbox. '
                . 'Please resolve duplicates manually before upgrading. '
                . 'Query: SELECT sdk_address, COUNT(*) FROM *prefix*sdkmc_itsl_mailbox '
                . 'WHERE sdk_address IS NOT NULL GROUP BY sdk_address HAVING COUNT(*) > 1;'
            );
        }
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('sdkmc_itsl_mailbox')) {
            $table = $schema->getTable('sdkmc_itsl_mailbox');

            if (!$table->hasIndex('sdkmc_itsl_mailbox_sdk_addr')) {
                $table->addUniqueIndex(['sdk_address'], 'sdkmc_itsl_mailbox_sdk_addr');
                return $schema;
            }
        }

        return null;
    }
}
