<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<EnrolledBoard>
 */
class EnrolledBoardMapper extends QBMapper {
    public function __construct(
        IDBConnection $db,
        private ITimeFactory $timeFactory,
    ) {
        parent::__construct($db, 'agent_engine_boards', EnrolledBoard::class);
    }

    public function findByBoardId(int $boardId): ?EnrolledBoard {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('board_id', $qb->createNamedParameter($boardId, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /** True when the board is enrolled AND enabled — the takeover gate. */
    public function isEnrolled(int $boardId): bool {
        $board = $this->findByBoardId($boardId);
        return $board !== null && $board->getEnabled() === 1;
    }

    /**
     * All enabled enrollments — the sweep's polling set.
     *
     * @return EnrolledBoard[]
     */
    public function findAllEnabled(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('enabled', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
            ->orderBy('board_id', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * All enrollments (admin config surface).
     *
     * @return EnrolledBoard[]
     */
    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from($this->getTableName())->orderBy('board_id', 'ASC');
        return $this->findEntities($qb);
    }

    /** Idempotent enroll/update (the PUT /boards/{id}/enroll upsert). */
    public function upsert(
        int $boardId,
        bool $enabled,
        string $onDone,
        bool $conservative,
        string $piiReviewedBy,
        string $enrolledBy,
    ): EnrolledBoard {
        $now = $this->timeFactory->getTime();
        $board = $this->findByBoardId($boardId);
        if ($board === null) {
            $board = new EnrolledBoard();
            $board->setBoardId($boardId);
            $board->setCreatedAt($now);
        }
        $board->setEnabled($enabled ? 1 : 0);
        $board->setOnDone($onDone);
        $board->setConservative($conservative ? 1 : 0);
        if ($piiReviewedBy !== '') {
            $board->setPiiReviewedBy($piiReviewedBy);
        }
        if ($enrolledBy !== '') {
            $board->setEnrolledBy($enrolledBy);
        }
        $board->setUpdatedAt($now);

        return $board->getId() === null ? $this->insert($board) : $this->update($board);
    }

    /** Persist a fresh sweep ETag for a board. */
    public function saveEtag(EnrolledBoard $board, string $etag): void {
        $board->setEtag($etag);
        $board->setUpdatedAt($this->timeFactory->getTime());
        $this->update($board);
    }
}
