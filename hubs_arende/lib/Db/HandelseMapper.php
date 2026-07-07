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
 * Single point of write into hubs_arende_handelse — ärendets händelsejournal
 * (audit-spåret bakom kortets "Historik & beslut"-tidslinje).
 *
 * Skrivs av motorns mutationspunkter (createCase, transitionera, tilldela,
 * medlemsändringar, verifierad commit, extra rum, koppling). BEST-EFFORT:
 * journal-skrivningen får ALDRIG fälla den mutation den beskriver — anroparen
 * sväljer fel (mönstret ligger i {@see \OCA\HubsArende\Service\ArendeService::loggaHandelse()}).
 *
 * Raderas MED ärendet (gallring/purge) — aktor_uid är personuppgift och den
 * permanenta akten bor i facksystemet (NEVER-SoR).
 *
 * @extends QBMapper<Handelse>
 */
class HandelseMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'hubs_arende_handelse', Handelse::class);
    }

    /**
     * Append a journal row. $detalj is a small array of coordination values
     * (steg-namn, dnr, roll) — the caller must NEVER pass fritext/PII.
     *
     * @param array<string,mixed> $detalj
     * @throws Exception
     */
    public function record(string $hubsCaseId, string $typ, array $detalj = [], string $aktorUid = ''): Handelse {
        $handelse = new Handelse();
        $handelse->setHubsCaseId($hubsCaseId);
        $handelse->setTyp($typ);
        $handelse->setDetalj($detalj === [] ? null : json_encode($detalj));
        $handelse->setAktorUid($aktorUid);
        $handelse->setTid(new \DateTime());

        return $this->insert($handelse);
    }

    /**
     * The case's journal, oldest first (a timeline reads top-down).
     *
     * @return Handelse[]
     * @throws Exception
     */
    public function findByCaseId(string $hubsCaseId, int $limit = 100): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('hubs_case_id', $qb->createNamedParameter($hubsCaseId, IQueryBuilder::PARAM_STR)))
            ->orderBy('id', 'ASC')
            ->setMaxResults($limit);

        return $this->findEntities($qb);
    }

    /**
     * Delete the case's whole journal. Idempotent — used by gallring/purge so
     * no aktor-uid (personal data) survives the coordination row.
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
