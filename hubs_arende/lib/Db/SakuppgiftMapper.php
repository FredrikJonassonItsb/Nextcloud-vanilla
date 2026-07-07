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
 * Single point of write into hubs_arende_sakuppgift — dokumentkedjans minne
 * (bekräftade sakuppgifter per ärende, senaste bekräftelsen vinner).
 *
 * UPSERT-semantik på (hubs_case_id, nyckel): UNIQUE-indexet
 * `hubs_arende_sak_uq` gör att en ny bekräftelse av samma fält uppdaterar
 * raden i stället för att duplicera — med race-fång i {@see upsert()} enligt
 * samma mönster som {@see MemberMapper::record()}.
 *
 * PII: värdena kan bära personuppgifter — raderna gallras OVILLKORLIGEN med
 * ärendet via {@see deleteByCaseId()} (GallringService), GDPR art. 5.1.e.
 *
 * @extends QBMapper<Sakuppgift>
 */
class SakuppgiftMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'hubs_arende_sakuppgift', Sakuppgift::class);
    }

    /**
     * Skriv/uppdatera en bekräftad sakuppgift (senaste bekräftelsen vinner).
     *
     * @throws Exception vid annat DB-fel än unik-kollision.
     */
    public function upsert(
        string $hubsCaseId,
        string $nyckel,
        string $varde,
        string $kalla,
        string $ursprung,
        string $bekraftadAv,
        ?\DateTime $nar = null,
    ): Sakuppgift {
        $nar ??= new \DateTime();

        $existing = $this->findByCaseAndNyckel($hubsCaseId, $nyckel);
        if ($existing !== null) {
            $existing->setVarde($varde);
            $existing->setKalla($kalla);
            $existing->setUrsprung($ursprung);
            $existing->setBekraftadAv($bekraftadAv);
            $existing->setBekraftad($nar);
            return $this->update($existing);
        }

        $rad = new Sakuppgift();
        $rad->setHubsCaseId($hubsCaseId);
        $rad->setNyckel($nyckel);
        $rad->setVarde($varde);
        $rad->setKalla($kalla);
        $rad->setUrsprung($ursprung);
        $rad->setBekraftadAv($bekraftadAv);
        $rad->setBekraftad($nar);

        try {
            return $this->insert($rad);
        } catch (Exception $e) {
            // Förlorat idempotens-race (UNIQUE slog) — uppdatera raden den
            // andra skrivaren just skapade i stället för att propagera felet.
            if ($e->getReason() === Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                $found = $this->findByCaseAndNyckel($hubsCaseId, $nyckel);
                if ($found !== null) {
                    $found->setVarde($varde);
                    $found->setKalla($kalla);
                    $found->setUrsprung($ursprung);
                    $found->setBekraftadAv($bekraftadAv);
                    $found->setBekraftad($nar);
                    return $this->update($found);
                }
            }
            throw $e;
        }
    }

    /**
     * Den bekräftade sakuppgiften för (ärende, nyckel), eller null.
     *
     * @throws Exception
     */
    public function findByCaseAndNyckel(string $hubsCaseId, string $nyckel): ?Sakuppgift {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('hubs_case_id', $qb->createNamedParameter($hubsCaseId, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->eq('nyckel', $qb->createNamedParameter($nyckel, IQueryBuilder::PARAM_STR)))
            ->setMaxResults(1);

        $rows = $this->findEntities($qb);
        return $rows[0] ?? null;
    }

    /**
     * Alla bekräftade sakuppgifter för ett ärende.
     *
     * @return Sakuppgift[]
     * @throws Exception
     */
    public function findByCaseId(string $hubsCaseId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('hubs_case_id', $qb->createNamedParameter($hubsCaseId, IQueryBuilder::PARAM_STR)))
            ->orderBy('nyckel', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Radera ärendets alla sakuppgifter. Idempotent — anropas av gallring/
     * purge så inga PII-rester överlever koordinationsraden (GDPR art. 5.1.e).
     *
     * @return int antal raderade rader
     * @throws Exception
     */
    public function deleteByCaseId(string $hubsCaseId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('hubs_case_id', $qb->createNamedParameter($hubsCaseId, IQueryBuilder::PARAM_STR)));

        return $qb->executeStatement();
    }
}
