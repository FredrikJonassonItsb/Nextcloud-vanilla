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
 * Mapper for the datadriven ärendetyp-registry (table hubs_arende_typ).
 *
 * The table is keyed by the string `arende_typ_id` rather than an autoincrement
 * int, so lookups go through {@see findByTypId} instead of the int-based
 * QBMapper::find().
 *
 * @extends QBMapper<ArendeTyp>
 */
class ArendeTypMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'hubs_arende_typ', ArendeTyp::class);
    }

    /**
     * @throws DoesNotExistException
     * @throws MultipleObjectsReturnedException
     * @throws Exception
     */
    public function findByTypId(string $arendeTypId): ArendeTyp {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where(
                $qb->expr()->eq('arende_typ_id', $qb->createNamedParameter($arendeTypId, IQueryBuilder::PARAM_STR))
            );

        return $this->findEntity($qb);
    }

    /**
     * @return ArendeTyp[]
     * @throws Exception
     */
    public function findAll(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->orderBy('arende_typ_id', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * True when a config-row already exists for this typ id (used by seeding).
     */
    public function exists(string $arendeTypId): bool {
        try {
            $this->findByTypId($arendeTypId);
            return true;
        } catch (DoesNotExistException) {
            return false;
        }
    }

    /**
     * Set the bevakningsmallar JSON on an existing row by its STRING id.
     *
     * QBMapper::update() keys on an int `id`, which this table lacks (PK is the
     * string arende_typ_id) — so the mallar-backfill ({@see ArendeTypRegistry::
     * synkaBevakningsmallar}) writes through a targeted UPDATE here instead.
     * Returns the number of rows affected.
     *
     * @throws Exception
     */
    public function setBevakningsmallar(string $arendeTypId, ?string $json): int {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('bevakningsmallar', $qb->createNamedParameter($json, IQueryBuilder::PARAM_STR))
            ->where(
                $qb->expr()->eq('arende_typ_id', $qb->createNamedParameter($arendeTypId, IQueryBuilder::PARAM_STR))
            );

        return $qb->executeStatement();
    }
}
