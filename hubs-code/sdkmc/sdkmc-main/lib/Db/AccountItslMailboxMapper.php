<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<AccountItslMailbox>
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 */
class AccountItslMailboxMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'sdkmc_account_itsl_mailbox', AccountItslMailbox::class);
    }

    /**
     * @param int $mailboxId
     * @return array<AccountItslMailbox>
     * @throws Exception
     */
    public function findByMailboxId(int $mailboxId): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('itsl_mailbox_id', $qb->createNamedParameter($mailboxId, IQueryBuilder::PARAM_INT))
            );

        return $this->findEntities($qb);
    }

    /**
     * @param string $accountId
     * @return array<AccountItslMailbox>
     * @throws Exception
     */
    public function findByAccountId(string $accountId): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_STR))
            );

        return $this->findEntities($qb);
    }

    /**
     * @param string $groupId
     * @return array<AccountItslMailbox>
     * @throws Exception
     */
    public function findByGroupId(string $groupId): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('group_id', $qb->createNamedParameter($groupId, IQueryBuilder::PARAM_STR))
            );

        return $this->findEntities($qb);
    }

    /**
     * @param int $mailboxId
     * @return int Number of deleted rows
     * @throws Exception
     */
    public function deleteByMailboxId(int $mailboxId): int {
        $qb = $this->db->getQueryBuilder();

        $qb->delete($this->getTableName())
            ->where(
                $qb->expr()->eq('itsl_mailbox_id', $qb->createNamedParameter($mailboxId, IQueryBuilder::PARAM_INT))
            );

        return $qb->executeStatement();
    }

    /**
     * @param int $mailboxId
     * @param string $accountId
     * @param string $accessType
     * @return array<AccountItslMailbox>
     * @throws Exception
     */
    public function findByMailboxIdAndAccountId(int $mailboxId, string $accountId, string $accessType): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('itsl_mailbox_id', $qb->createNamedParameter($mailboxId, IQueryBuilder::PARAM_INT)),
                $qb->expr()->eq('account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_STR)),
                $qb->expr()->eq('access_type', $qb->createNamedParameter($accessType, IQueryBuilder::PARAM_STR))
            );

        return $this->findEntities($qb);
    }

    /**
     * @param int $mailboxId
     * @param string $groupId
     * @param string $accessType
     * @return array<AccountItslMailbox>
     * @throws Exception
     */
    public function findByMailboxIdAndGroupId(int $mailboxId, string $groupId, string $accessType): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('itsl_mailbox_id', $qb->createNamedParameter($mailboxId, IQueryBuilder::PARAM_INT)),
                $qb->expr()->eq('group_id', $qb->createNamedParameter($groupId, IQueryBuilder::PARAM_STR)),
                $qb->expr()->eq('access_type', $qb->createNamedParameter($accessType, IQueryBuilder::PARAM_STR))
            );

        return $this->findEntities($qb);
    }

    /**
     * Find all absence-based access entries for a specific source user
     *
     * @param string $sourceUserId
     * @return array<AccountItslMailbox>
     * @throws Exception
     */
    public function findBySourceUserId(string $sourceUserId): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('source_user_id', $qb->createNamedParameter($sourceUserId, IQueryBuilder::PARAM_STR))
            );

        return $this->findEntities($qb);
    }

    /**
     * Find all expired temporary access entries
     *
     * @return array<AccountItslMailbox>
     * @throws Exception
     */
    public function findExpired(): array {
        $qb = $this->db->getQueryBuilder();
        $now = time();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->isNotNull('end_time'),
                $qb->expr()->lt('end_time', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            );

        return $this->findEntities($qb);
    }

    /**
     * Delete all absence-based access entries for a specific source user
     *
     * @param string $sourceUserId
     * @return int Number of deleted rows
     * @throws Exception
     */
    public function deleteBySourceUserId(string $sourceUserId): int {
        $qb = $this->db->getQueryBuilder();

        $qb->delete($this->getTableName())
            ->where(
                $qb->expr()->eq('source_user_id', $qb->createNamedParameter($sourceUserId, IQueryBuilder::PARAM_STR))
            );

        return $qb->executeStatement();
    }

    /**
     * Delete all mailbox assignments for a specific group
     * Used when a group is deleted from Nextcloud
     *
     * @param string $groupId
     * @return int Number of deleted rows
     * @throws Exception
     */
    public function deleteByGroupId(string $groupId): int {
        $qb = $this->db->getQueryBuilder();

        $qb->delete($this->getTableName())
            ->where(
                $qb->expr()->eq('group_id', $qb->createNamedParameter($groupId, IQueryBuilder::PARAM_STR))
            );

        return $qb->executeStatement();
    }

    /**
     * Delete all direct user assignments for a specific user
     * Used when a user is deleted from Nextcloud
     *
     * @param string $accountId
     * @return int Number of deleted rows
     * @throws Exception
     */
    public function deleteByAccountId(string $accountId): int {
        $qb = $this->db->getQueryBuilder();

        $qb->delete($this->getTableName())
            ->where(
                $qb->expr()->eq('account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_STR))
            );

        return $qb->executeStatement();
    }

    /**
     * Delete all absence assignments where user is the replacement
     * Used when a user (who was a replacement for someone absent) is deleted
     *
     * @param string $accountId
     * @return int Number of deleted rows
     * @throws Exception
     */
    public function deleteAbsenceByReplacementUserId(string $accountId): int {
        $qb = $this->db->getQueryBuilder();

        $qb->delete($this->getTableName())
            ->where(
                $qb->expr()->eq('access_type', $qb->createNamedParameter('absence', IQueryBuilder::PARAM_STR)),
                $qb->expr()->eq('account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_STR))
            );

        return $qb->executeStatement();
    }
}
