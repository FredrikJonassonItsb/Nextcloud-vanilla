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
 * Single point of write into hubs_arende_pekare.
 *
 * The SAGA's R3–R9 forward steps record one pekare per external side effect
 * (case_tag, groupfolder, deck_card, talk_room, calendar, conversation); the
 * compensations resolve the external id back through {@see findByCaseId()} and
 * tear it down with {@see deleteByCaseAndTyp()}.
 *
 * Follows the same QBMapper pattern as {@see ArendeMapper} (sdkmc ItslTagMapper).
 *
 * @extends QBMapper<Pekare>
 */
class PekareMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'hubs_arende_pekare', Pekare::class);
    }

    /**
     * Record a pointer from a case to an external object.
     *
     * Convenience factory used by the SAGA forward steps so callers do not need
     * to construct the entity inline.
     *
     * @param string      $hubsCaseId Canonical case UUID.
     * @param string      $objektTyp  deck_card|talk_room|groupfolder|calendar|case_tag|conversation
     * @param string      $objektId   The external object's native id.
     * @param string|null $riktning   Optional relation hint.
     *
     * @throws Exception
     */
    public function record(string $hubsCaseId, string $objektTyp, string $objektId, ?string $riktning = null): Pekare {
        $pekare = new Pekare();
        $pekare->setHubsCaseId($hubsCaseId);
        $pekare->setObjektTyp($objektTyp);
        $pekare->setObjektId($objektId);
        $pekare->setRiktning($riktning);

        return $this->insert($pekare);
    }

    /**
     * All pointers for a case (newest first), across every objekt_typ.
     *
     * @return Pekare[]
     * @throws Exception
     */
    public function findByCaseId(string $hubsCaseId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('hubs_case_id', $qb->createNamedParameter($hubsCaseId, IQueryBuilder::PARAM_STR))
            )
            ->orderBy('id', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * All pointers of one objekt_typ for a case. Lets a compensation resolve the
     * external id it must tear down (e.g. the deck_card id, the talk_room token).
     *
     * @return Pekare[]
     * @throws Exception
     */
    public function findByCaseAndTyp(string $hubsCaseId, string $objektTyp): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('hubs_case_id', $qb->createNamedParameter($hubsCaseId, IQueryBuilder::PARAM_STR))
            )
            ->andWhere(
                $qb->expr()->eq('objekt_typ', $qb->createNamedParameter($objektTyp, IQueryBuilder::PARAM_STR))
            )
            ->orderBy('id', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * REVERSE lookup: all pointers of one objekt_typ with a given external id —
     * resolves an external object back to its case(s). Used by the team-resource
     * provider (teamId → hubsCaseId) where only the external id is known.
     *
     * @return Pekare[]
     * @throws Exception
     */
    public function findByTypAndObjektId(string $objektTyp, string $objektId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('objekt_typ', $qb->createNamedParameter($objektTyp, IQueryBuilder::PARAM_STR))
            )
            ->andWhere(
                $qb->expr()->eq('objekt_id', $qb->createNamedParameter($objektId, IQueryBuilder::PARAM_STR))
            )
            ->orderBy('id', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Delete every pointer of one objekt_typ for a case. Idempotent — used by the
     * SAGA compensations after the external object has been torn down so no
     * orphaned pekare row survives. Returns the number of rows deleted.
     *
     * @throws Exception
     */
    public function deleteByCaseAndTyp(string $hubsCaseId, string $objektTyp): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where(
                $qb->expr()->eq('hubs_case_id', $qb->createNamedParameter($hubsCaseId, IQueryBuilder::PARAM_STR))
            )
            ->andWhere(
                $qb->expr()->eq('objekt_typ', $qb->createNamedParameter($objektTyp, IQueryBuilder::PARAM_STR))
            );

        return $qb->executeStatement();
    }
}
