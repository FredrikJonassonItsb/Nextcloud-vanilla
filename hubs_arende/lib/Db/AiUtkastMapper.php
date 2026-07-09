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
 * Single point of write/read into hubs_arende_ai_utkast — AI-utkastregistret
 * (HITL-backend, SPEC-BRAIN-PER-ARENDE kap 8.0.4). {@see \OCA\HubsArende\Service\Brain\AiUtkastService}
 * äger livscykeln (skapa → godkann/avvisa); mappern är ren persistens, samma
 * QBMapper-mönster som {@see BevakningMapper} / {@see HandelseMapper}.
 *
 * Raderna gallras MED ärendet (deleteByCaseId anropas av GallringService) — rått
 * AI-innehåll får aldrig överleva ärendet i NC-databasen.
 *
 * @extends QBMapper<AiUtkast>
 */
class AiUtkastMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'hubs_arende_ai_utkast', AiUtkast::class);
    }

    /**
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
     * @throws Exception
     */
    public function findById(int $id): AiUtkast {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);
    }

    /**
     * All AI-utkast för ett ärende (nyast först), oavsett status — HITL-listan.
     *
     * @return AiUtkast[]
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
     * Radera alla AI-utkast för ett ärende. Idempotent — GallringService anropar
     * detta i gallringssvepet så att inget rått AI-innehåll (eller dess provenans)
     * överlever ärendet. Returnerar antal raderade rader.
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
