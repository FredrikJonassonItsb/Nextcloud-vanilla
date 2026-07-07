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
 * Single point of write into hubs_arende_part — the PARTSREGISTRET: parterna
 * (barn, vårdnadshavare, m.fl.) i ett ärende, folkbokföringsverifierade via
 * NAVET-porten.
 *
 * PII-DOKTRIN: detta är motorns ENDA sanktionerade PII-tabell (beslut Fredrik
 * 2026-07-06, se hubs_start/docs/ANALYS-HANDLING-FRAN-MALL.md §3.4). Raderna
 * är TRANSIENT arbetsdata — aldrig system-of-record — och gallras alltid med
 * ärendet via {@see deleteByCaseId()}. Personnummer/namn ur denna tabell får
 * ALDRIG skrivas till loggar eller till Händelse.detalj; logga antal /
 * korrelationsId / roll / skydd, aldrig identitet.
 *
 * Upsert-logiken (find-then-insert-or-update) bor i PartService och nycklar på
 * {@see findByCasePnrRoll()}; ingen UNIQUE-hantering görs här.
 *
 * Follows the same QBMapper pattern as {@see MemberMapper} / {@see PekareMapper}.
 *
 * @extends QBMapper<Part>
 */
class PartMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'hubs_arende_part', Part::class);
    }

    /**
     * All parter in a case (newest first), across every role.
     *
     * @return Part[]
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
     * A single part row by its primary key, or null if it does not exist.
     *
     * @throws Exception
     */
    public function findById(int $id): ?Part {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);

        $rows = $this->findEntities($qb);
        return $rows[0] ?? null;
    }

    /**
     * The single part row for a (case, personnummer, roll) triple, or null.
     * This is the upsert key: PartService looks the row up here and decides
     * insert vs. update — the mapper itself does no UNIQUE handling.
     *
     * @throws Exception
     */
    public function findByCasePnrRoll(string $hubsCaseId, string $personnummer, string $roll): ?Part {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('hubs_case_id', $qb->createNamedParameter($hubsCaseId, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->eq('personnummer', $qb->createNamedParameter($personnummer, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->eq('roll', $qb->createNamedParameter($roll, IQueryBuilder::PARAM_STR)))
            ->setMaxResults(1);

        $rows = $this->findEntities($qb);
        return $rows[0] ?? null;
    }

    /**
     * Number of parter in a case (all roles). For the admin/status view —
     * safe to log (it is a count, never an identity).
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
     * Delete every part row for a case. Idempotent — used by the SAGA
     * compensation / GallringService purge so that NO PII row ever survives a
     * torn-down ärenderum: partsregistret är transient arbetsdata och SKALL
     * gallras med ärendet (GDPR art. 5.1e lagringsminimering + K-NAV-4.6).
     * Returns the number of rows deleted.
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
