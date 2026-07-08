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
 * Single point of write/read into hubs_arende_bevakning.
 *
 * The bevakning register: several rows per case, each an independent watch with
 * its own villkor. {@see BevakningService} owns the lifecycle (create → utvardera
 * → uppnadd/passerad/avbruten); this mapper is pure persistence, same QBMapper
 * pattern as {@see PekareMapper} / {@see PartMapper}.
 *
 * @extends QBMapper<Bevakning>
 */
class BevakningMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'hubs_arende_bevakning', Bevakning::class);
    }

    /**
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     * @throws Exception
     */
    public function findById(int $id): Bevakning {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);
    }

    /**
     * All bevakningar for a case (newest first), across every status.
     *
     * @return Bevakning[]
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
     * Active bevakningar for a case (the ones the villkorsmotor evaluates and
     * the ones fristDue projects from).
     *
     * @return Bevakning[]
     * @throws Exception
     */
    public function findAktivaByCaseId(string $hubsCaseId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('hubs_case_id', $qb->createNamedParameter($hubsCaseId, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(Bevakning::STATUS_AKTIV, IQueryBuilder::PARAM_STR)))
            ->orderBy('frist_due', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Active + already-passed (larmläge) bevakningar with a deadline on or before
     * $horisont — the BevakningVarselJob's daily sweep. Passerade tas med så att
     * eskaleringen till fördelaren fortsätter tills villkoret faktiskt uppnås.
     *
     * @return Bevakning[]
     * @throws Exception
     */
    public function findMedFristSenast(\DateTime $horisont): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in('status', $qb->createNamedParameter(
                [Bevakning::STATUS_AKTIV, Bevakning::STATUS_PASSERAD],
                IQueryBuilder::PARAM_STR_ARRAY
            )))
            ->andWhere($qb->expr()->isNotNull('frist_due'))
            ->andWhere($qb->expr()->lte('frist_due', $qb->createNamedParameter(
                $horisont->format('Y-m-d'),
                IQueryBuilder::PARAM_STR
            )))
            ->orderBy('frist_due', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * All active bevakningar whose villkor is datum_passerat and whose deadline
     * has fallen due on or before $nu — the job flips these to UPPNADD (a pure
     * date-watch, e.g. överklagande → laga kraft, is achieved by the day passing).
     *
     * @return Bevakning[]
     * @throws Exception
     */
    public function findForfallnaDatumbevakningar(\DateTime $nu): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter(Bevakning::STATUS_AKTIV, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->eq('villkor_typ', $qb->createNamedParameter(Bevakning::VILLKOR_DATUM_PASSERAT, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->isNotNull('frist_due'))
            ->andWhere($qb->expr()->lte('frist_due', $qb->createNamedParameter($nu->format('Y-m-d'), IQueryBuilder::PARAM_STR)));

        return $this->findEntities($qb);
    }

    /**
     * Delete every bevakning for a case. Idempotent — GallringService calls this
     * after tearing down the Deck cards, so no koordinationsdata survives the
     * ärende (K-BEV-2.2). Returns the number of rows deleted.
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
