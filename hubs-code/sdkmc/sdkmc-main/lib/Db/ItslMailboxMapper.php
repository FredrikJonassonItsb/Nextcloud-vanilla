<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<ItslMailbox>
 */
class ItslMailboxMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'sdkmc_itsl_mailbox', ItslMailbox::class);
    }

    /**
     * @param int $id
     * @return ItslMailbox
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     * @throws Exception
     */
    public function findById(int $id): ItslMailbox {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
            );

        return $this->findEntity($qb);
    }

    /**
     * @return array<ItslMailbox>
     * @throws Exception
     */
    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName());

        return $this->findEntities($qb);
    }

    /**
     * @param string $messageType
     * @return array<ItslMailbox>
     * @throws Exception
     */
    public function findByMessageType(string $messageType): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('message_type', $qb->createNamedParameter($messageType, IQueryBuilder::PARAM_STR))
            );

        return $this->findEntities($qb);
    }

    /**
     * @param string $email
     * @return ItslMailbox
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     * @throws Exception
     */
    public function findByEmail(string $email): ItslMailbox {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('email', $qb->createNamedParameter($email, IQueryBuilder::PARAM_STR))
            );

        return $this->findEntity($qb);
    }

    /**
     * @param string $alias
     * @param string $messageType
     * @return ItslMailbox
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     * @throws Exception
     */
    public function findByAliasAndType(string $alias, string $messageType): ItslMailbox {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('alias', $qb->createNamedParameter($alias, IQueryBuilder::PARAM_STR)),
                $qb->expr()->eq('message_type', $qb->createNamedParameter($messageType, IQueryBuilder::PARAM_STR))
            );

        return $this->findEntity($qb);
    }

    /**
     * @param string $sdkAddress
     * @return ItslMailbox
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     * @throws Exception
     */
    public function findBySdkAddress(string $sdkAddress): ItslMailbox {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('sdk_address', $qb->createNamedParameter($sdkAddress, IQueryBuilder::PARAM_STR))
            );

        return $this->findEntity($qb);
    }
}
