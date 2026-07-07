<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Single point of write into hubs_arende_member — the ärenderum's first-class
 * member ledger (which uids belong to a case room, in which role).
 *
 * The SAGA records the mottagningskrets at case birth (R4/R6); {@see ArendeService::tilldela()}
 * records the assignee (+ co-handläggare). Compensations / purge tear the ledger
 * down with {@see deleteByCaseId()}.
 *
 * Follows the same QBMapper pattern as {@see PekareMapper} / {@see ArendeMapper}.
 *
 * @extends QBMapper<Member>
 */
class MemberMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'hubs_arende_member', Member::class);
    }

    /**
     * Record a member of a case room. IDEMPOTENT: the (hubs_case_id, uid, roll)
     * triple is UNIQUE, so a re-run of a SAGA step (or re-adding the same krets
     * member) returns the existing row instead of throwing/duplicating.
     *
     * @throws Exception on any non-unique DB error.
     */
    public function record(string $hubsCaseId, string $uid, string $roll): Member {
        $existing = $this->findOne($hubsCaseId, $uid, $roll);
        if ($existing !== null) {
            return $existing;
        }

        $member = new Member();
        $member->setHubsCaseId($hubsCaseId);
        $member->setUid($uid);
        $member->setRoll($roll);
        $member->setSkapad(new \DateTime());

        try {
            return $this->insert($member);
        } catch (Exception $e) {
            // Lost an idempotency race (the UNIQUE index fired) — return the row
            // the other writer just created rather than propagating the violation.
            if ($e->getReason() === Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                $found = $this->findOne($hubsCaseId, $uid, $roll);
                if ($found !== null) {
                    return $found;
                }
            }
            throw $e;
        }
    }

    /**
     * The single membership row for a (case, uid, roll), or null.
     *
     * @throws Exception
     */
    public function findOne(string $hubsCaseId, string $uid, string $roll): ?Member {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('hubs_case_id', $qb->createNamedParameter($hubsCaseId, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->eq('roll', $qb->createNamedParameter($roll, IQueryBuilder::PARAM_STR)))
            ->setMaxResults(1);

        $rows = $this->findEntities($qb);
        return $rows[0] ?? null;
    }

    /**
     * All members of a case room (newest first), across every role.
     *
     * @return Member[]
     * @throws Exception
     */
    public function findByCaseId(string $hubsCaseId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('hubs_case_id', $qb->createNamedParameter($hubsCaseId, IQueryBuilder::PARAM_STR)))
            ->orderBy('id', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * All members of a case room holding a given role.
     *
     * @return Member[]
     * @throws Exception
     */
    public function findByCaseAndRoll(string $hubsCaseId, string $roll): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('hubs_case_id', $qb->createNamedParameter($hubsCaseId, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->eq('roll', $qb->createNamedParameter($roll, IQueryBuilder::PARAM_STR)))
            ->orderBy('id', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * All distinct case ids where a uid is a member (any role) — the source for
     * the MEDLEMSBASERADE "Mina ärenden" (dashboard mineOnly).
     *
     * @return string[] hubs_case_id-värden
     * @throws Exception
     */
    public function findCaseIdsByUid(string $uid): array {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('hubs_case_id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, IQueryBuilder::PARAM_STR)));

        $result = $qb->executeQuery();
        $ids = [];
        while (($row = $result->fetch()) !== false) {
            $ids[] = (string)$row['hubs_case_id'];
        }
        $result->closeCursor();
        return $ids;
    }

    /**
     * Number of members of a case room (all roles). For the admin/status view.
     *
     * @throws Exception
     */
    public function countByCase(string $hubsCaseId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('hubs_case_id', $qb->createNamedParameter($hubsCaseId, IQueryBuilder::PARAM_STR)));

        $result = $qb->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();
        return $count;
    }

    /**
     * Remove a single membership (e.g. revoke a co-handläggare). Returns rows deleted.
     *
     * @throws Exception
     */
    public function deleteByCaseUidRoll(string $hubsCaseId, string $uid, string $roll): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('hubs_case_id', $qb->createNamedParameter($hubsCaseId, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->eq('roll', $qb->createNamedParameter($roll, IQueryBuilder::PARAM_STR)));

        return $qb->executeStatement();
    }

    /**
     * Delete every membership row for a case. Idempotent — used by the SAGA
     * compensation / demo purge so no orphaned member row survives a torn-down
     * ärenderum. Returns the number of rows deleted.
     *
     * @throws Exception
     */
    public function deleteByCaseId(string $hubsCaseId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('hubs_case_id', $qb->createNamedParameter($hubsCaseId, IQueryBuilder::PARAM_STR)));

        return $qb->executeStatement();
    }
}
