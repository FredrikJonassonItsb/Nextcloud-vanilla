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
 * @extends QBMapper<ItslMessageTag>
 */
class ItslMessageTagMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'sdkmc_itsl_message_tag', ItslMessageTag::class);
    }

    /**
     * Tag a message in the DB.
     *
     * @param ItslTag $tag
     * @param string $messageId The IMAP Message-ID
     * @param string $emailAddress
     * @throws Exception
     */
    public function tagMessage(ItslTag $tag, string $messageId, string $emailAddress): void {
        // Check if already tagged
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('imap_message_id', $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_STR)),
                $qb->expr()->eq('tag_id', $qb->createNamedParameter($tag->getId(), IQueryBuilder::PARAM_INT)),
                $qb->expr()->eq('email_address', $qb->createNamedParameter($emailAddress, IQueryBuilder::PARAM_STR))
            );
        $result = $qb->executeQuery();
        $existing = $result->fetch();
        $result->closeCursor();

        if ($existing !== false) {
            // Already tagged
            return;
        }

        // Insert new message tag
        $insertQb = $this->db->getQueryBuilder();
        $insertQb->insert($this->getTableName())
            ->setValue('imap_message_id', $insertQb->createNamedParameter($messageId, IQueryBuilder::PARAM_STR))
            ->setValue('tag_id', $insertQb->createNamedParameter($tag->getId(), IQueryBuilder::PARAM_INT))
            ->setValue('email_address', $insertQb->createNamedParameter($emailAddress, IQueryBuilder::PARAM_STR));
        $insertQb->executeStatement();
    }

    /**
     * Remove a tag from a message.
     *
     * @param ItslTag $tag
     * @param string $messageId The IMAP Message-ID
     * @throws Exception
     */
    public function untagMessage(ItslTag $tag, string $messageId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where(
                $qb->expr()->eq('imap_message_id', $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_STR)),
                $qb->expr()->eq('tag_id', $qb->createNamedParameter($tag->getId(), IQueryBuilder::PARAM_INT))
            );
        $qb->executeStatement();
    }

    /**
     * Get all message IDs that have a specific tag.
     *
     * @param int $tagId
     * @param string $emailAddress
     * @return string[]
     * @throws Exception
     */
    public function getMessagesByTag(int $tagId, string $emailAddress): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('imap_message_id')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('tag_id', $qb->createNamedParameter($tagId, IQueryBuilder::PARAM_INT)),
                $qb->expr()->eq('email_address', $qb->createNamedParameter($emailAddress, IQueryBuilder::PARAM_STR))
            );

        $result = $qb->executeQuery();
        $messageIds = [];
        while (($row = $result->fetch()) !== false) {
            /** @var array{imap_message_id: string} $row */
            $messageIds[] = $row['imap_message_id'];
        }
        $result->closeCursor();

        return $messageIds;
    }

    /**
     * Get message IDs that have a specific tag label from a set of messages.
     *
     * @param string[] $messageIds Array of IMAP message IDs
     * @param string $emailAddress
     * @param string $imapLabel
     * @return string[]
     * @throws Exception
     */
    public function getTaggedMessageIdsForMessages(array $messageIds, string $emailAddress, string $imapLabel): array {
        if (count($messageIds) === 0) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct(['mt.imap_message_id'])
            ->from($this->getTableName(), 'mt')
            ->join('mt', 'sdkmc_itsl_tag', 't', $qb->expr()->eq('t.id', 'mt.tag_id', IQueryBuilder::PARAM_INT))
            ->where(
                $qb->expr()->in('mt.imap_message_id', $qb->createParameter('ids'), IQueryBuilder::PARAM_STR_ARRAY),
                $qb->expr()->eq('t.email_address', $qb->createNamedParameter($emailAddress, IQueryBuilder::PARAM_STR)),
                $qb->expr()->eq('t.imap_label', $qb->createNamedParameter($imapLabel, IQueryBuilder::PARAM_STR))
            );

        $taggedIds = [];
        foreach (array_chunk($messageIds, 1000) as $chunk) {
            $qb->setParameter('ids', $chunk, IQueryBuilder::PARAM_STR_ARRAY);
            $queryResult = $qb->executeQuery();

            while (($row = $queryResult->fetch()) !== false) {
                /** @var array{imap_message_id: string} $row */
                $taggedIds[] = $row['imap_message_id'];
            }
            $queryResult->closeCursor();
        }

        return $taggedIds;
    }

    /**
     * Delete all message-tag associations for a tag.
     * Used during tag deletion cleanup.
     *
     * @param int $tagId
     * @throws Exception
     */
    public function deleteByTagId(int $tagId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('tag_id', $qb->createNamedParameter($tagId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }
}
