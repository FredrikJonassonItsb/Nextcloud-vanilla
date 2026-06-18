<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Single point of write into hubs_arende_case.
 *
 * Follows the sdkmc ItslTagMapper QBMapper pattern.
 *
 * @extends QBMapper<Arende>
 */
class ArendeMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'hubs_arende_case', Arende::class);
    }

    /**
     * Find a case by its canonical hubsCaseId (UUID).
     *
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     * @throws Exception
     */
    public function findByCaseId(string $hubsCaseId): Arende {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('hubs_case_id', $qb->createNamedParameter($hubsCaseId, IQueryBuilder::PARAM_STR))
            );

        return $this->findEntity($qb);
    }

    /**
     * Idempotency lookup for the SAGA: find an existing case for an inbound
     * conversationId. Returns null when none exists yet.
     *
     * The conversationId is the provenance anchor; ArendeService keys the
     * idempotency check on it so a double-click never mints two cases.
     *
     * @throws Exception
     */
    public function findByConversationId(string $conversationId): ?Arende {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('conversation_id', $qb->createNamedParameter($conversationId, IQueryBuilder::PARAM_STR))
            )
            ->setMaxResults(1);

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * Find a case by its facksystem dnr (set only after a verified commit).
     *
     * @throws Exception
     */
    public function findByDnr(string $dnr): ?Arende {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('dnr', $qb->createNamedParameter($dnr, IQueryBuilder::PARAM_STR))
            )
            ->setMaxResults(1);

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * All cases owned by an enhet (the ACL boundary for the dashboard aggregate).
     *
     * @return Arende[]
     * @throws Exception
     */
    public function findByEnhet(string $enhet): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('enhet', $qb->createNamedParameter($enhet, IQueryBuilder::PARAM_STR))
            )
            ->orderBy('skapad', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Rows that are DUE for GDPR-gallring of the engine's own coordination-/routing-
     * row (art. 5.1.e lagringsminimering).
     *
     * Selects ONLY rows that have already been committed to the facksystem
     * (provenanceState='registrerad'), are flagged to be purged after that commit
     * (retentionState='gallras_efter_commit'), and whose verkställbar deadline from
     * the verified receipt has arrived (gallrasDatum IS NOT NULL AND gallrasDatum
     * <= :now). The facksystem is the System of Record for verksamhetsdatat and
     * gallrar that itself — the engine purges only its pseudonyma pekar-/routing-rad.
     *
     * All predicates are bound parameters (QBMapper) and the batch is LIMIT:ed so the
     * sweep stays bounded per run; the {@see \OCA\HubsArende\Service\GallringService}
     * is idempotent, so a re-run simply finds fewer rows.
     *
     * @param \DateTime $now   The sweep instant; rows with gallrasDatum at/Before this are due.
     * @param int       $limit Max rows per batch (default 500).
     *
     * @return Arende[]
     * @throws Exception
     */
    public function findGallringsbara(\DateTime $now, int $limit = 500): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('provenance_state', $qb->createNamedParameter('registrerad', IQueryBuilder::PARAM_STR))
            )
            ->andWhere(
                $qb->expr()->eq('retention_state', $qb->createNamedParameter('gallras_efter_commit', IQueryBuilder::PARAM_STR))
            )
            ->andWhere(
                $qb->expr()->isNotNull('gallras_datum')
            )
            ->andWhere(
                $qb->expr()->lte(
                    'gallras_datum',
                    $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE)
                )
            )
            ->orderBy('gallras_datum', 'ASC')
            ->setMaxResults($limit);

        return $this->findEntities($qb);
    }

    /**
     * Recent cases (newest first), capped. Backs the dashboard's "Mina ärenden"
     * summary. The caller (ArendeService) applies object-level authz (enhet) on top;
     * this is a bounded read of the register's coordination rows (no PII).
     *
     * @return Arende[]
     * @throws Exception
     */
    public function findAll(int $limit = 200): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('skapad', 'DESC')
            ->setMaxResults($limit);

        return $this->findEntities($qb);
    }

    /**
     * Find all cases whose conversationId matches a LIKE pattern. Used ONLY by the
     * demo-seeder's --purge to locate its own synthetic rows (conversationId
     * 'demo-%'). Dev/demo tool — not part of the production flow.
     *
     * @return Arende[]
     * @throws Exception
     */
    public function findByConversationIdLike(string $like): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->like('conversation_id', $qb->createNamedParameter($like, IQueryBuilder::PARAM_STR))
            )
            ->orderBy('skapad', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Otilldelade ärenden (status='otilldelat'), newest first, capped. Backs the
     * gruppledare's fördelningsvy ("att fördela"). The caller (ArendeService)
     * applies object-level authz (enhet) on top; this is a bounded read of the
     * register's coordination rows (no PII).
     *
     * Additive read used by {@see \OCA\HubsArende\Service\ArendeService::fordelningSummary()}.
     * It does not touch the saga/commit write paths.
     *
     * @return Arende[]
     * @throws Exception
     */
    public function findOtilldelade(int $limit = 200): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('status', $qb->createNamedParameter('otilldelat', IQueryBuilder::PARAM_STR))
            )
            ->orderBy('skapad', 'ASC')
            ->setMaxResults($limit);

        return $this->findEntities($qb);
    }

    /**
     * Committed cases (provenanceState='registrerad'), newest first, capped. Backs
     * the verified-receipt surface (kvittens-/retention-ytan). Each such row carries
     * a verified facksystem dnr + a retention deadline (gallrasDatum) derived from a
     * verified commit, so it doubles as a receipt row. Caller applies enhet authz.
     *
     * Additive read used by {@see \OCA\HubsArende\Service\ArendeService::treservaReceipts()}.
     * It does not touch the saga/commit write paths.
     *
     * @return Arende[]
     * @throws Exception
     */
    public function findRegistrerade(int $limit = 200): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('provenance_state', $qb->createNamedParameter('registrerad', IQueryBuilder::PARAM_STR))
            )
            ->orderBy('skapad', 'DESC')
            ->setMaxResults($limit);

        return $this->findEntities($qb);
    }

    // ================================================================== //
    //  PII-FRIA AGGREGAT (för admin-statussidan + occ hubs_arende:status)
    //  Returnerar ENBART räknare/grupperingar — aldrig objektRef/dnr/innehåll.
    // ================================================================== //

    /**
     * Total number of cases in the register (no PII — a count).
     *
     * @throws Exception
     */
    public function countAll(): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from($this->getTableName());
        $result = $qb->executeQuery();
        $cnt = (int)$result->fetchOne();
        $result->closeCursor();
        return $cnt;
    }

    /**
     * Row counts grouped by one non-PII column. The column is validated against a
     * fixed allowlist so the GROUP BY can never be attacker-influenced (the only
     * non-bound identifier in the query) — defense in depth even for internal callers.
     *
     * @return array<string,int> value → count
     * @throws Exception
     */
    public function countByColumn(string $column): array {
        $allowed = ['steg', 'status', 'provenance_state', 'retention_state', 'commit_destination', 'arende_typ'];
        if (!in_array($column, $allowed, true)) {
            throw new \InvalidArgumentException('Otillåten kolumn för aggregat: ' . $column);
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select($column)
            ->selectAlias($qb->func()->count('*'), 'cnt')
            ->from($this->getTableName())
            ->groupBy($column);
        $result = $qb->executeQuery();
        $out = [];
        while (($row = $result->fetch()) !== false) {
            $out[(string)$row[$column]] = (int)$row['cnt'];
        }
        $result->closeCursor();
        return $out;
    }

    /**
     * Count of rows currently DUE for gallring (same predicates as
     * {@see findGallringsbara()} but a bare count — no rows materialised).
     *
     * @throws Exception
     */
    public function countGallringsbara(\DateTime $now): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('provenance_state', $qb->createNamedParameter('registrerad', IQueryBuilder::PARAM_STR))
            )
            ->andWhere(
                $qb->expr()->eq('retention_state', $qb->createNamedParameter('gallras_efter_commit', IQueryBuilder::PARAM_STR))
            )
            ->andWhere(
                $qb->expr()->isNotNull('gallras_datum')
            )
            ->andWhere(
                $qb->expr()->lte('gallras_datum', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE))
            );
        $result = $qb->executeQuery();
        $cnt = (int)$result->fetchOne();
        $result->closeCursor();
        return $cnt;
    }
}
