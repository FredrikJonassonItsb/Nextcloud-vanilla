<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Mapper for per-mailbox folder retention overrides.
 *
 * @extends QBMapper<MailboxRetention>
 */
class MailboxRetentionMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'sdkmc_mailbox_retention', MailboxRetention::class);
    }

    /**
     * Get all retention overrides for a mailbox.
     *
     * @param string $email
     * @return array<MailboxRetention>
     */
    public function findByEmail(string $email): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('email', $qb->createNamedParameter($email)));
        return $this->findEntities($qb);
    }

    /**
     * Get a specific folder override.
     *
     * @param string $email
     * @param string $folder
     * @return MailboxRetention|null
     */
    public function findByEmailAndFolder(string $email, string $folder): ?MailboxRetention {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('email', $qb->createNamedParameter($email)))
            ->andWhere($qb->expr()->eq('folder', $qb->createNamedParameter($folder)));
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * Delete all retention overrides for a mailbox.
     *
     * @param string $email
     */
    public function deleteByEmail(string $email): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('email', $qb->createNamedParameter($email)));
        $qb->executeStatement();
    }

    /**
     * Set retention for a folder (upsert).
     *
     * @param string $email
     * @param string $folder
     * @param int $days
     */
    public function setRetention(string $email, string $folder, int $days): void {
        $existing = $this->findByEmailAndFolder($email, $folder);
        if ($existing !== null) {
            $existing->setRetentionDays($days);
            $this->update($existing);
            return;
        }
        $entity = new MailboxRetention();
        $entity->setEmail($email);
        $entity->setFolder($folder);
        $entity->setRetentionDays($days);
        $this->insert($entity);
    }

    /**
     * Remove retention override for a folder (return to inherit).
     *
     * @param string $email
     * @param string $folder
     */
    public function removeRetention(string $email, string $folder): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('email', $qb->createNamedParameter($email)))
            ->andWhere($qb->expr()->eq('folder', $qb->createNamedParameter($folder)));
        $qb->executeStatement();
    }
}
