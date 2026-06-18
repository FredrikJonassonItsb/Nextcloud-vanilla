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
 * @extends QBMapper<MessageThread>
 */
class MessageThreadMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'sdkmc_message_thread', MessageThread::class);
    }

    /**
     * @param string $sdkConversationId
     * @return MessageThread
     * @throws DoesNotExistException
     * @throws Exception
     * @throws MultipleObjectsReturnedException
     */
    public function getLatestBySdkConversation(string $sdkConversationId): MessageThread {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('sdk_conversation_id', $qb->createNamedParameter($sdkConversationId, IQueryBuilder::PARAM_STR))
            )->setMaxResults(1)
            ->addOrderBy('id', 'desc');

        return $this->findEntity($qb);
    }

    /**
     * @param string $messageId
     * @return MessageThread
     * @throws DoesNotExistException
     * @throws Exception
     * @throws MultipleObjectsReturnedException
     */
    public function getByMessage(string $messageId): MessageThread {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('message_id', $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_STR))
            );

        return $this->findEntity($qb);
    }

    /**
     * Look up the thread row for a given SDK message that belongs to the
     * recipient's mailbox. Uses a join through mail_messages, mail_mailboxes,
     * mail_accounts and sdkmc_itsl_mailbox to disambiguate local delivery
     * (same sdk_message_id, different recipient mailboxes).
     *
     * Falls back to a simple sdk_message_id lookup when the join returns no
     * rows (e.g. recipient has no mail account, or mail_messages row was
     * cleaned up by retention).
     *
     * @param string $sdkMessageId
     * @param string $recipientAddress
     * @return MessageThread
     * @throws DoesNotExistException
     * @throws Exception
     */
    public function getBySdkMessageForRecipient(string $sdkMessageId, string $recipientAddress): MessageThread {
        $qb = $this->db->getQueryBuilder();

        $qb->select('smt.*')
            ->from($this->getTableName(), 'smt')
            ->innerJoin('smt', 'mail_messages', 'mm', $qb->expr()->eq('smt.message_id', 'mm.message_id'))
            ->innerJoin('mm', 'mail_mailboxes', 'mb', $qb->expr()->eq('mm.mailbox_id', 'mb.id'))
            ->innerJoin('mb', 'mail_accounts', 'ma', $qb->expr()->eq('mb.account_id', 'ma.id'))
            ->innerJoin('ma', 'sdkmc_itsl_mailbox', 'sim', $qb->expr()->eq('ma.email', 'sim.email'))
            ->where($qb->expr()->eq('smt.sdk_message_id', $qb->createNamedParameter($sdkMessageId, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->eq('sim.sdk_address', $qb->createNamedParameter($recipientAddress, IQueryBuilder::PARAM_STR)))
            ->orderBy('smt.id', 'DESC')
            ->setMaxResults(1);

        $entities = $this->findEntities($qb);

        if (count($entities) > 0) {
            return $entities[0];
        }

        // Fallback: simple lookup by sdk_message_id (no recipient filter)
        $qb2 = $this->db->getQueryBuilder();

        $qb2->select('*')
            ->from($this->getTableName())
            ->where($qb2->expr()->eq('sdk_message_id', $qb2->createNamedParameter($sdkMessageId, IQueryBuilder::PARAM_STR)))
            ->orderBy('id', 'DESC')
            ->setMaxResults(1);

        $fallbackEntities = $this->findEntities($qb2);

        if (count($fallbackEntities) > 0) {
            return $fallbackEntities[0];
        }

        throw new DoesNotExistException('MessageThread not found for sdk_message_id ' . $sdkMessageId);
    }

    /**
     * @param MessageThread $messageThread
     * @return MessageThread
     * @throws Exception
     */
    public function insertOrUpdate(Entity $messageThread): MessageThread {
        if ($messageThread->getId() !== null) { // @phpstan-ignore notIdentical.alwaysTrue (Entity::$id is untyped, can be null at runtime)
            return $this->update($messageThread);
        }
        try { // @phpstan-ignore deadCode.unreachable (Entity::$id is untyped, can be null at runtime)
            return $this->insert($messageThread);
        } catch (Exception $ex) {
            if ($ex->getReason() === Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                $existing = $this->getByMessage($messageThread->getMessageId());
                $messageThread->setId($existing->getId());
                return $this->update($messageThread);
            }
            throw $ex;
        }
    }
}
