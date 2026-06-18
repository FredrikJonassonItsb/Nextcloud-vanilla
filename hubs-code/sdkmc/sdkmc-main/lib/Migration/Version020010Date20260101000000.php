<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Migration;

use Closure;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Clean up incorrectly migrated default tags.
 * Only $label1 (Important) should be a default tag.
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 */
class Version020010Date20260101000000 extends SimpleMigrationStep {
    public function __construct(
        private IDBConnection $db,
    ) {
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $deleted = $this->deleteUnusedNonImportantDefaultTags();
        $output->info("Deleted $deleted unused non-Important default tags");

        $updated = $this->updateUsedNonImportantDefaultTags();
        $output->info("Updated $updated used non-Important default tags to non-default");
    }

    private function deleteUnusedNonImportantDefaultTags(): int {
        // Subquery: tag IDs that have message associations
        $usedTagsQb = $this->db->getQueryBuilder();
        $usedTagsQb->selectDistinct('tag_id')
            ->from('sdkmc_itsl_message_tag');

        // Delete unused non-Important default tags
        $deleteQb = $this->db->getQueryBuilder();
        $deleteQb->delete('sdkmc_itsl_tag')
            ->where($deleteQb->expr()->eq('is_default_tag', $deleteQb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->andWhere($deleteQb->expr()->neq('imap_label', $deleteQb->createNamedParameter('$label1')))
            ->andWhere($deleteQb->expr()->notIn('id', $deleteQb->createFunction('(' . $usedTagsQb->getSQL() . ')')));

        return $deleteQb->executeStatement();
    }

    private function updateUsedNonImportantDefaultTags(): int {
        // Subquery: tag IDs that have message associations
        $usedTagsQb = $this->db->getQueryBuilder();
        $usedTagsQb->selectDistinct('tag_id')
            ->from('sdkmc_itsl_message_tag');

        // Update used non-Important default tags to non-default
        $updateQb = $this->db->getQueryBuilder();
        $updateQb->update('sdkmc_itsl_tag')
            ->set('is_default_tag', $updateQb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
            ->where($updateQb->expr()->eq('is_default_tag', $updateQb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->andWhere($updateQb->expr()->neq('imap_label', $updateQb->createNamedParameter('$label1')))
            ->andWhere($updateQb->expr()->in('id', $updateQb->createFunction('(' . $usedTagsQb->getSQL() . ')')));

        return $updateQb->executeStatement();
    }
}
