<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<SdkLog>
 */
class SdkLogMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'sdkmc_sdk_log', SdkLog::class);
    }

    /**
     * @param int $id
     * @return SdkLog
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     */
    public function get(int $id): SdkLog {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
            );

        return $this->findEntity($qb);
    }

    /**
     * @param string $messageId
     * @return SdkLog
     * @throws DoesNotExistException
     * @throws Exception
     * @throws MultipleObjectsReturnedException
     */
    public function getByMessage(string $messageId): SdkLog {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('message_id', $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_STR))
            );

        return $this->findEntity($qb);
    }

    /**
     * @return Array<SdkLog>
     * @param int $limit
     * @param int $offset
     */
    public function getAll(?int $limit, ?int $offset, ?string $search = null): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->addOrderBy('id');

        if (!is_null($search) && trim($search) !== '') {
            $searchLike = '%' . $this->db->escapeLikeParameter($search) . '%';
            $expr = $qb->expr();

            $qb->where(
                $expr->orX(
                    $expr->like(
                        $qb->func()->concat($qb->createNamedParameter(''), 'id'),
                        $qb->createNamedParameter($searchLike)
                    ),
                    $expr->like('ap_id', $qb->createNamedParameter($searchLike)),
                    $expr->like(
                        $qb->func()->concat($qb->createNamedParameter(''), 'log_data'),
                        $qb->createNamedParameter($searchLike)
                    )
                )
            );
        }
        if (!is_null($limit)) {
            $qb->setMaxResults($limit);
        }
        if (!is_null($offset)) {
            $qb->setFirstResult($offset);
        }
        return $this->findEntities($qb);
    }

    /**
     * @return int
     */
    public function getCount(?string $search): int {
        $qb = $this->db->getQueryBuilder();

        $qb->selectAlias($qb->func()->count('*'), 'count')
            ->from($this->getTableName());
        if (!is_null($search) && trim($search) !== '') {
            $searchLike = '%' . $this->db->escapeLikeParameter($search) . '%';
            $expr = $qb->expr();

            $qb->where(
                $expr->orX(
                    $expr->like(
                        $qb->func()->concat($qb->createNamedParameter(''), 'id'),
                        $qb->createNamedParameter($searchLike)
                    ),
                    $expr->like('ap_id', $qb->createNamedParameter($searchLike)),
                    $expr->like(
                        $qb->func()->concat($qb->createNamedParameter(''), 'log_data'),
                        $qb->createNamedParameter($searchLike)
                    )
                )
            );
        }

        $stmt = $qb->executeQuery();
        $result = $stmt->fetchOne();
        return is_numeric($result) ? (int)$result : 0;
    }

    /**
     * This method is here due to the one from QBMapper not working properly for some reason
     * @param SdkLog $sdkLog
     * @return SdkLog
     * @throws Exception
     */
    public function insertOrUpdate(Entity $sdkLog): SdkLog {
        try {
            $this->get($sdkLog->getId());
            return $this->update($sdkLog);
        } catch (DoesNotExistException $e) {
            return $this->insert($sdkLog);
        }
    }
}
