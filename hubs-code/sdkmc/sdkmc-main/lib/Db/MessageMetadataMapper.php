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
 * @extends QBMapper<MessageMetadata>
 */
class MessageMetadataMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'sdkmc_message_metadata', MessageMetadata::class);
    }

    /**
     * @param int $id
     * @return MessageMetadata
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     */
    public function get(int $id): MessageMetadata {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
            );

        return $this->findEntity($qb);
    }

    /**
     * @param int $messageId
     * @return MessageMetadata
     * @throws DoesNotExistException
     * @throws Exception
     * @throws MultipleObjectsReturnedException
     */
    public function getByMessage(int $messageId): MessageMetadata {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('message_id', $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_INT))
            );

        return $this->findEntity($qb);
    }

    /**
     * @param MessageMetadata $messageMetadata
     * @return MessageMetadata
     * @throws Exception
     */
    public function insertOrUpdate(Entity $messageMetadata): MessageMetadata {
        if ($messageMetadata->getId() !== null) { // @phpstan-ignore notIdentical.alwaysTrue (Entity::$id is untyped, can be null at runtime)
            return $this->update($messageMetadata);
        }
        try { // @phpstan-ignore deadCode.unreachable (Entity::$id is untyped, can be null at runtime)
            return $this->insert($messageMetadata);
        } catch (Exception $ex) {
            if ($ex->getReason() === Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                $existing = $this->getByMessage($messageMetadata->getMessageId());
                $messageMetadata->setId($existing->getId());
                return $this->update($messageMetadata);
            }
            throw $ex;
        }
    }

    /**
     * @param int $messageId
     * @return MessageMetadata|null
     * @throws Exception
     */
    public function deleteByMessage(int $messageId): ?MessageMetadata {
        try {
            $messageMetadata = $this->getByMessage($messageId);
        } catch (DoesNotExistException|MultipleObjectsReturnedException $e) {
            return null;
        }
        return $this->delete($messageMetadata);
    }
}
