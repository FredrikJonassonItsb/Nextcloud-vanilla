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
use OCP\AppFramework\Db\Entity;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<MessageReceipt>
 */
class MessageReceiptMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'sdkmc_message_receipt', MessageReceipt::class);
    }

    /**
     * @param int $id
     * @return MessageReceipt
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     */
    public function get(int $id): MessageReceipt {
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
     * @return MessageReceipt
     * @throws DoesNotExistException
     * @throws Exception
     * @throws MultipleObjectsReturnedException
     */
    public function getByMessage(string $messageId): MessageReceipt {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('message_id', $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_STR))
            );

        return $this->findEntity($qb);
    }

    /**
     * @param string $documentReference
     * @return MessageReceipt
     * @throws DoesNotExistException
     * @throws Exception
     * @throws MultipleObjectsReturnedException
     */
    public function getByDocumentReference(string $documentReference): MessageReceipt {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('document_reference', $qb->createNamedParameter($documentReference, IQueryBuilder::PARAM_STR))
            );

        return $this->findEntity($qb);
    }

    /**
     * @param string $messageId
     * @return MessageReceipt|null
     * @throws Exception
     */
    public function deleteByMessage(string $messageId): ?MessageReceipt {
        try {
            $messageReceipt = $this->getByMessage($messageId);
        } catch (DoesNotExistException|MultipleObjectsReturnedException $e) {
            return null;
        }

        return $this->delete($messageReceipt);
    }

    /**
     * @param MessageReceipt $messageReceipt
     * @return MessageReceipt
     * @throws Exception
     */
    public function insertOrUpdate(Entity $messageReceipt): MessageReceipt {
        if ($messageReceipt->getId() !== null) { // @phpstan-ignore notIdentical.alwaysTrue (Entity::$id is untyped, can be null at runtime)
            return $this->update($messageReceipt);
        }
        try { // @phpstan-ignore deadCode.unreachable (Entity::$id is untyped, can be null at runtime)
            return $this->insert($messageReceipt);
        } catch (Exception $ex) {
            if ($ex->getReason() === Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                try {
                    $existing = $this->getByDocumentReference($messageReceipt->getDocumentReference());
                } catch (DoesNotExistException) {
                    $existing = $this->getByMessage($messageReceipt->getMessageId());
                }
                $messageReceipt->setId($existing->getId());
                return $this->update($messageReceipt);
            }
            throw $ex;
        }
    }
}
