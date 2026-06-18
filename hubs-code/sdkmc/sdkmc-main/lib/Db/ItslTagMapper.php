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
 * @extends QBMapper<ItslTag>
 */
class ItslTagMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'sdkmc_itsl_tag', ItslTag::class);
    }

    /**
     * @param int $id
     * @return ItslTag
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     * @throws Exception
     */
    public function findById(int $id): ItslTag {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT))
            );

        return $this->findEntity($qb);
    }

    /**
     * @param string $imapLabel
     * @param string $emailAddress
     * @return ItslTag
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     * @throws Exception
     */
    public function getTagByImapLabel(string $imapLabel, string $emailAddress): ItslTag {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('imap_label', $qb->createNamedParameter($imapLabel, IQueryBuilder::PARAM_STR)),
                $qb->expr()->eq('email_address', $qb->createNamedParameter($emailAddress, IQueryBuilder::PARAM_STR))
            )
            ->andWhere($qb->expr()->isNull('deleted_at'));

        return $this->findEntity($qb);
    }

    /**
     * @param string $emailAddress
     * @return ItslTag[]
     * @throws Exception
     */
    public function getAllTagsForMailbox(string $emailAddress): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('email_address', $qb->createNamedParameter($emailAddress, IQueryBuilder::PARAM_STR))
            )
            ->andWhere($qb->expr()->isNull('deleted_at'));

        return $this->findEntities($qb);
    }

    /**
     * Get a tag by IMAP label, or create it if it doesn't exist.
     *
     * @param string $emailAddress
     * @param string $imapLabel
     * @param string $displayName
     * @param string $color
     * @param bool $isDefault
     * @return ItslTag
     *
     * @SuppressWarnings("PHPMD.BooleanArgumentFlag")
     */
    public function getOrCreateTag(
        string $emailAddress,
        string $imapLabel,
        string $displayName,
        string $color,
        bool $isDefault = false,
    ): ItslTag {
        try {
            return $this->getTagByImapLabel($imapLabel, $emailAddress);
        } catch (DoesNotExistException $e) {
            $tag = new ItslTag();
            $tag->setEmailAddress($emailAddress);
            $tag->setImapLabel($imapLabel);
            $tag->setDisplayName($displayName);
            $tag->setColor($color);
            $tag->setIsDefaultTag($isDefault);
            return $this->insert($tag);
        }
    }

    /**
     * Get all assignment tags for a mailbox.
     *
     * @param string $emailAddress
     * @return ItslTag[]
     * @throws Exception
     */
    public function getAssignmentTagsForMailbox(string $emailAddress): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('email_address', $qb->createNamedParameter($emailAddress, IQueryBuilder::PARAM_STR)),
                $qb->expr()->eq(
                    'is_assignment_tag',
                    $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL),
                    IQueryBuilder::PARAM_BOOL
                )
            )
            ->andWhere($qb->expr()->isNull('deleted_at'));

        return $this->findEntities($qb);
    }

    /**
     * Get an assignment tag by username.
     *
     * @param string $emailAddress Mailbox email
     * @param string $username User ID
     * @return ItslTag|null
     */
    public function getAssignmentTagByUsername(string $emailAddress, string $username): ?ItslTag {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('email_address', $qb->createNamedParameter($emailAddress, IQueryBuilder::PARAM_STR)),
                $qb->expr()->eq('username', $qb->createNamedParameter($username, IQueryBuilder::PARAM_STR)),
                $qb->expr()->eq(
                    'is_assignment_tag',
                    $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL),
                    IQueryBuilder::PARAM_BOOL
                )
            )
            ->andWhere($qb->expr()->isNull('deleted_at'));

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Get ANY tag by username (regardless of is_assignment_tag).
     * Used for finding converted assignment tags that need reconversion.
     *
     * @param string $emailAddress Mailbox email
     * @param string $username User ID
     * @return ItslTag|null
     */
    public function getTagByUsername(string $emailAddress, string $username): ?ItslTag {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('email_address', $qb->createNamedParameter($emailAddress, IQueryBuilder::PARAM_STR)),
                $qb->expr()->eq('username', $qb->createNamedParameter($username, IQueryBuilder::PARAM_STR))
            );
        // Note: NO is_assignment_tag filter - finds both active and converted tags

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Get a tag by IMAP label including soft-deleted tags.
     * Used by collision detection in createTag() and findUniqueImapLabel().
     *
     * @param string $imapLabel
     * @param string $emailAddress
     * @return ItslTag
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     * @throws Exception
     */
    public function getTagByImapLabelIncludingDeleted(string $imapLabel, string $emailAddress): ItslTag {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('imap_label', $qb->createNamedParameter($imapLabel, IQueryBuilder::PARAM_STR)),
                $qb->expr()->eq('email_address', $qb->createNamedParameter($emailAddress, IQueryBuilder::PARAM_STR))
            );

        return $this->findEntity($qb);
    }

    /**
     * Check if a tag with the given IMAP label exists for this email address.
     * Includes soft-deleted tags to prevent unique constraint violations.
     *
     * @param string $emailAddress
     * @param string $imapLabel
     * @return bool
     */
    public function tagExistsByLabel(string $emailAddress, string $imapLabel): bool {
        try {
            $this->getTagByImapLabelIncludingDeleted($imapLabel, $emailAddress);
            return true;
        } catch (DoesNotExistException $e) {
            return false;
        }
    }

    /**
     * Get or create an assignment tag for a user.
     * Uses username for lookup (not IMAP label) to preserve existing labels.
     * Updates display_name if it has changed (handles user display name changes).
     *
     * @param string $emailAddress Mailbox email
     * @param string $imapLabel IMAP label (e.g., '$assignee_username')
     * @param string $displayName User's display name
     * @param string $color Deterministic color
     * @param string $username User ID for direct lookup
     * @return ItslTag
     */
    public function getOrCreateAssignmentTag(
        string $emailAddress,
        string $imapLabel,
        string $displayName,
        string $color,
        string $username,
    ): ItslTag {
        // Step 1: Try to find active assignment tag by username (preserves existing IMAP labels)
        $tag = $this->getAssignmentTagByUsername($emailAddress, $username);
        if ($tag !== null) {
            // Update display_name if changed (handles user display name changes)
            if ($tag->getDisplayName() !== $displayName) {
                $tag->setDisplayName($displayName);
                return $this->update($tag);
            }
            return $tag;
        }

        // Step 2: Check for converted (non-assignment) tag with this username
        $existingTag = $this->getTagByUsername($emailAddress, $username);
        if ($existingTag !== null) {
            // Reconvert to assignment tag - user regained access
            $existingTag->setIsAssignmentTag(true);
            $existingTag->setDisplayName($displayName);
            return $this->update($existingTag);
        }

        // Step 3: Not found - create new tag with collision handling
        $labelToUse = $this->findUniqueImapLabel($emailAddress, $imapLabel);

        $tag = new ItslTag();
        $tag->setEmailAddress($emailAddress);
        $tag->setImapLabel($labelToUse);
        $tag->setDisplayName($displayName);
        $tag->setColor($color);
        $tag->setIsAssignmentTag(true);
        $tag->setUsername($username);
        return $this->insert($tag);
    }

    /**
     * Find a unique IMAP label by appending counter if needed.
     * Handles collision with do-while loop until unique label found.
     *
     * @param string $emailAddress
     * @param string $baseLabel The preferred IMAP label
     * @return string A unique IMAP label
     */
    public function findUniqueImapLabel(string $emailAddress, string $baseLabel): string {
        $counter = 0;
        do {
            $labelToTry = $counter === 0 ? $baseLabel : $baseLabel . '_' . $counter;
            // Truncate to 64 chars if needed (IMAP limit)
            if (strlen($labelToTry) > 64) {
                $suffix = '_' . $counter;
                $labelToTry = substr($baseLabel, 0, 64 - strlen($suffix)) . $suffix;
            }
            $exists = $this->tagExistsByLabel($emailAddress, $labelToTry);
            $counter++;
        } while ($exists);

        return $labelToTry;
    }

    /**
     * Get all tags for a set of messages.
     *
     * @param string[] $messageIds Array of IMAP message IDs
     * @param string $emailAddress
     * @return array<string, ItslTag[]> Map of message ID to array of tags
     * @throws Exception
     */
    public function getAllTagsForMessages(array $messageIds, string $emailAddress): array {
        if (count($messageIds) === 0) {
            return [];
        }

        $tags = [];
        $qb = $this->db->getQueryBuilder();
        $tagsQuery = $qb->selectDistinct(['t.*', 'mt.imap_message_id'])
            ->from($this->getTableName(), 't')
            ->join('t', 'sdkmc_itsl_message_tag', 'mt', $qb->expr()->eq('t.id', 'mt.tag_id', IQueryBuilder::PARAM_INT))
            ->where(
                $qb->expr()->in('mt.imap_message_id', $qb->createParameter('ids'), IQueryBuilder::PARAM_STR_ARRAY),
                $qb->expr()->eq('t.email_address', $qb->createNamedParameter($emailAddress, IQueryBuilder::PARAM_STR))
            )
            ->andWhere($qb->expr()->isNull('t.deleted_at'));

        foreach (array_chunk($messageIds, 1000) as $chunk) {
            $tagsQuery->setParameter('ids', $chunk, IQueryBuilder::PARAM_STR_ARRAY);
            $queryResult = $tagsQuery->executeQuery();

            while (($row = $queryResult->fetch()) !== false) {
                if (!is_array($row)) {
                    continue;
                }
                /** @var array{imap_message_id: string, id: int, email_address: string, imap_label: string, display_name: string, color: string, is_default_tag: bool, is_assignment_tag: bool, username: string|null} $row */
                $messageId = $row['imap_message_id'];
                if (!isset($tags[$messageId])) {
                    $tags[$messageId] = [];
                }

                // Construct a Tag instance but omit the joined column
                $tags[$messageId][] = ItslTag::fromRow([
                    'id' => $row['id'],
                    'email_address' => $row['email_address'],
                    'imap_label' => $row['imap_label'],
                    'display_name' => $row['display_name'],
                    'color' => $row['color'],
                    'is_default_tag' => $row['is_default_tag'],
                    'is_assignment_tag' => $row['is_assignment_tag'],
                    'username' => $row['username'],
                ]);
            }
            $queryResult->closeCursor();
        }

        return $tags;
    }

    /**
     * Get tags pending deletion (soft-deleted but not yet cleaned up).
     *
     * @param int $limit Maximum number of tags to return
     * @return ItslTag[]
     * @throws Exception
     */
    public function getTagsPendingDeletion(int $limit = 100): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->isNotNull('deleted_at'))
            ->orderBy('deleted_at', 'ASC')
            ->setMaxResults($limit);
        return $this->findEntities($qb);
    }
}
