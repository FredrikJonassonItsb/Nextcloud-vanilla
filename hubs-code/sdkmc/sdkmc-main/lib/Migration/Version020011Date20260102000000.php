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
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add username column to sdkmc_itsl_tag table for direct user lookup on assignment tags.
 * Backfills existing assignment tags by matching sanitized username to IMAP label.
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 */
class Version020011Date20260102000000 extends SimpleMigrationStep {
    public function __construct(
        private IDBConnection $db,
    ) {
    }

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
            if (!$table->hasColumn('username')) {
                $table->addColumn('username', Types::STRING, [
                    'notnull' => false,
                    'length' => 64,
                    'default' => null,
                ]);
            }
            // Add index for efficient lookup by email_address + username
            if (!$table->hasIndex('sdkmc_itsl_tag_email_username')) {
                $table->addIndex(['email_address', 'username'], 'sdkmc_itsl_tag_email_username');
            }
        }

        return $schema;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $updated = $this->backfillUsernameFromImapLabels();
        $output->info("Backfilled username for $updated assignment tags");
    }

    /**
     * Backfill username field for existing assignment tags by matching
     * sanitized username to IMAP label pattern '$assignee_{sanitized}'.
     */
    private function backfillUsernameFromImapLabels(): int {
        // Get all users
        $usersQb = $this->db->getQueryBuilder();
        $usersQb->select('uid')
            ->from('users');
        $usersResult = $usersQb->executeQuery();

        $updated = 0;
        while ($row = $usersResult->fetch()) {
            if (!is_array($row) || !isset($row['uid'])) {
                continue;
            }
            /** @var array{uid: string} $row */
            $uid = $row['uid'];
            $sanitized = $this->sanitizeUsernameForImap($uid);
            $expectedLabel = '$assignee_' . $sanitized;

            // Update all assignment tags with this IMAP label
            $updateQb = $this->db->getQueryBuilder();
            $updateQb->update('sdkmc_itsl_tag')
                ->set('username', $updateQb->createNamedParameter($uid))
                ->where($updateQb->expr()->eq('imap_label', $updateQb->createNamedParameter($expectedLabel)))
                ->andWhere($updateQb->expr()->eq(
                    'is_assignment_tag',
                    $updateQb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)
                ))
                ->andWhere($updateQb->expr()->isNull('username'));

            $updated += $updateQb->executeStatement();
        }
        $usersResult->closeCursor();

        return $updated;
    }

    /**
     * Sanitize username for IMAP atom syntax (same logic as ConsolidateMailboxesService).
     */
    private function sanitizeUsernameForImap(string $username): string {
        // Replace non-alphanumeric with underscore
        $sanitized = preg_replace('/[^a-zA-Z0-9]/', '_', $username) ?? $username;
        // Collapse multiple underscores
        $sanitized = preg_replace('/_+/', '_', $sanitized) ?? $sanitized;
        // Trim underscores from ends
        $sanitized = trim($sanitized, '_');
        // Limit length and lowercase
        return substr(strtolower($sanitized), 0, 55);
    }
}
