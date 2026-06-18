<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Db;

use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<ConversationBankIDAuth>
 */
class ConversationBankIDAuthMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'sdkmc_conv_bank_auth', ConversationBankIDAuth::class);
    }

    /**
     * @param ConversationBankIDAuth $bankIDAccess
     * @return ConversationBankIDAuth
     */
    public function insert(Entity $bankIDAccess): ConversationBankIDAuth {
        /** @var ConversationBankIDAuth $result */
        $result = parent::insert($bankIDAccess);
        return $result;
    }

    /**
     * Find auth requirement by conversation and email
     *
     * @param string $conversationId
     * @param string $email
     * @return ConversationBankIDAuth
     */
    public function findByConversationAndEmail(string $conversationId, string $email): ?ConversationBankIDAuth {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq(
                    'conversation_id',
                    $qb->createNamedParameter($conversationId, IQueryBuilder::PARAM_STR)
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    'email',
                    $qb->createNamedParameter(strtolower($email), IQueryBuilder::PARAM_STR)
                )
            );

        try {
            return $this->findEntity($qb);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Find auth requirement for a token and actorId
     *
     * @param string $conversationId
     * @param string $actorId
     * @return ConversationBankIDAuth
     */
    public function findByTokenAndActorId(string $conversationId, string $actorId): ?ConversationBankIDAuth {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq(
                    'conversation_id',
                    $qb->createNamedParameter($conversationId, IQueryBuilder::PARAM_STR)
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    'actor_id',
                    $qb->createNamedParameter(strtolower($actorId), IQueryBuilder::PARAM_STR)
                )
            );

        try {
            return $this->findEntity($qb);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Check if user needs BankID auth for this conversation
     *
     * @param string $conversationId
     * @param string $email
     * @return bool
     */
    public function requiresBankIdAuth(string $conversationId, string $email): bool {
        return $this->findByConversationAndEmail($conversationId, $email) !== null;
    }

    /**
     * TODO attach to required calendar events
     * Delete all auth requirements for a conversation --
     *
     * @param string $conversationId
     * @return int Number of deleted records
     */
    public function deleteByConversation(string $conversationId): int {
        $qb = $this->db->getQueryBuilder();

        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('conversation_id', $qb->createNamedParameter($conversationId, IQueryBuilder::PARAM_STR)));

        return $qb->executeStatement();
    }

    /**
     * Update the first and last name for a conversation participant
     *
     * @param string $conversationId
     * @param string $email
     * @param string $firstName
     * @param string $lastName
     * @return int Number of updated records
     */
    public function updateDataFromProvider(string $conversationId, string $email, string $firstName, string $lastName, string $lastUsedSsn): int {
        $qb = $this->db->getQueryBuilder();

        $qb->update($this->getTableName())
            ->set('first_name', $qb->createNamedParameter($firstName, IQueryBuilder::PARAM_STR))
            ->set('last_name', $qb->createNamedParameter($lastName, IQueryBuilder::PARAM_STR))
            ->set('last_used_ssn', $qb->createNamedParameter($lastUsedSsn, IQueryBuilder::PARAM_STR))
            ->where(
                $qb->expr()->eq('conversation_id', $qb->createNamedParameter($conversationId, IQueryBuilder::PARAM_STR))
            )
            ->andWhere(
                $qb->expr()->eq('email', $qb->createNamedParameter(strtolower($email), IQueryBuilder::PARAM_STR))
            );

        return $qb->executeStatement();
    }
    /**
     * Update the first and last name for a conversation participant
     *
     * @param string $conversationId
     * @param string $email
     * @return int Number of updated records
     */
    public function updateActorId(string $conversationId, string $email): int {
        $qb = $this->db->getQueryBuilder();

        $qb->update($this->getTableName())
            ->set('actor_id', $qb->createNamedParameter(hash('sha256', $email), IQueryBuilder::PARAM_STR))
            ->where(
                $qb->expr()->eq('conversation_id', $qb->createNamedParameter($conversationId, IQueryBuilder::PARAM_STR))
            )
            ->andWhere(
                $qb->expr()->eq('email', $qb->createNamedParameter(strtolower($email), IQueryBuilder::PARAM_STR))
            );

        return $qb->executeStatement();
    }
}
