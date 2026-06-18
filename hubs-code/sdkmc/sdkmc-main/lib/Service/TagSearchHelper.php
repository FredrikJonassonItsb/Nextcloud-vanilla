<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Service;

use OCA\Mail\Service\Search\SearchQuery;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Helper for tag-based message search with correct account filtering.
 *
 * Fixes four bugs in mail app's tag search:
 * 1. Bug 1 (TOO MANY): Search returned messages from ALL accounts sharing same Message-ID
 * 2. Bug 2 (TOO FEW): Search used account-specific tag_id instead of semantic imap_label
 * 3. Bug 3 (THREAD): Search only checked latest message, not all messages in thread
 * 4. Bug 4 (OR vs AND): Multiple tags used OR logic instead of AND
 * 5. Bug 5 (NONE): 'none' checked for any tags instead of only assignment tags
 */
class TagSearchHelper {
    /**
     * Process tag search and clear tags from query to make old mail app code dead.
     *
     * Joins the necessary tables to correctly filter tags by:
     * - email_address: Only returns messages from accounts where the tag was applied
     * - imap_label: Uses semantic tag label instead of account-specific tag_id
     *
     * Special handling for 'none':
     * - 'none' means "no assignment tags" (is_assignment_tag = 1)
     * - Can be combined with other tags: tags:none,$label1 = "no assignment tags AND has $label1"
     *
     * @param IQueryBuilder $qb Query builder
     * @param IQueryBuilder $select The select query (same as $qb, passed for consistency with mail app patterns)
     * @param SearchQuery $query Search query object (tags will be cleared after processing)
     */
    public static function processAndClearTags(
        IQueryBuilder $qb,
        IQueryBuilder $select,
        SearchQuery $query,
    ): void {
        $imapLabels = $query->getTags();
        if ($imapLabels === []) {
            return;
        }

        // Separate 'none' from other labels - they're handled differently
        $hasNone = in_array('none', $imapLabels, true);
        $otherLabels = array_values(array_filter($imapLabels, fn ($l) => $l !== 'none'));

        // JOIN to get email from mail_accounts (always needed for tag operations)
        $select->innerJoin('m', 'mail_mailboxes', 'mb', 'm.mailbox_id = mb.id');
        $select->innerJoin('mb', 'mail_accounts', 'a', 'mb.account_id = a.id');

        // Handle 'none' - NOT EXISTS for assignment tags only
        if ($hasNone) {
            $subQb = $qb->getConnection()->getQueryBuilder();
            $subQb->select($subQb->createFunction('1'))
                ->from('mail_messages', 'tm')
                ->innerJoin('tm', 'sdkmc_itsl_message_tag', 'tags_sub', 'tm.message_id = tags_sub.imap_message_id')
                ->innerJoin('tags_sub', 'sdkmc_itsl_tag', 't_sub', 'tags_sub.tag_id = t_sub.id')
                ->where($subQb->expr()->eq('tm.thread_root_id', 'm.thread_root_id'))
                ->andWhere($subQb->expr()->eq('tm.mailbox_id', 'm.mailbox_id'))
                ->andWhere($subQb->expr()->eq('tags_sub.email_address', 'a.email'))
                ->andWhere($subQb->expr()->eq('t_sub.email_address', 'a.email'))
                // Only check for assignment tags (is_assignment_tag = true)
                // Note: Use $qb (parent) for createNamedParameter so it binds correctly when embedded via createFunction
                ->andWhere($subQb->expr()->eq('t_sub.is_assignment_tag', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));

            // NOT EXISTS = no message in thread has assignment tags
            $select->andWhere(
                $qb->createFunction('NOT EXISTS (' . $subQb->getSQL() . ')')
            );
        }

        // Handle other tags (if any) - EXISTS subquery for each to implement AND semantics
        // Each EXISTS checks if ANY message in the thread has that specific tag
        // Tags can be on different messages - thread is returned if ALL tags exist somewhere
        $otherLabels = array_unique($otherLabels);
        foreach ($otherLabels as $label) {
            $subQb = $qb->getConnection()->getQueryBuilder();
            $subQb->select($subQb->createFunction('1'))
                ->from('mail_messages', 'tm')
                ->innerJoin('tm', 'sdkmc_itsl_message_tag', 'tags_sub', 'tm.message_id = tags_sub.imap_message_id')
                ->innerJoin('tags_sub', 'sdkmc_itsl_tag', 't_sub', 'tags_sub.tag_id = t_sub.id')
                ->where($subQb->expr()->eq('tm.thread_root_id', 'm.thread_root_id'))
                ->andWhere($subQb->expr()->eq('tm.mailbox_id', 'm.mailbox_id'))
                ->andWhere($subQb->expr()->eq('tags_sub.email_address', 'a.email'))
                ->andWhere($subQb->expr()->eq('t_sub.email_address', 'a.email'))
                ->andWhere($subQb->expr()->eq('t_sub.imap_label', $qb->createNamedParameter($label)));

            $select->andWhere(
                $qb->createFunction('EXISTS (' . $subQb->getSQL() . ')')
            );
        }

        // Clear tags to make old code in mail app dead
        // The mail app's if (!empty($query->getTags())) will now return false
        $query->setTags([]);
    }
}
