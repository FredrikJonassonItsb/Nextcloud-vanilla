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
 * Single point of write into hubs_arende_signering — signeringslivscykelns
 * persisterade begäran-state (KRAV-SIGNERING-2026-07 fas 1, K-SIGN-22).
 *
 * Skrivs ENBART av {@see \OCA\HubsArende\Service\SigneringService} (portens enda
 * konsument). Raderas MED ärendet via {@see deleteByCaseId()} i destruktions-
 * spegeln ({@see \OCA\HubsArende\Service\GallringService}, K-SIGN-19) — inga
 * signeringsspår överlever koordinationsraden.
 *
 * Follows the same QBMapper pattern as {@see PartMapper} / {@see BevakningMapper}.
 *
 * @extends QBMapper<Signering>
 */
class SigneringMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'hubs_arende_signering', Signering::class);
    }

    /**
     * All signeringsposter for a case (newest first) — statuspanelens läsning.
     *
     * @return Signering[]
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
     * The row for a port-level signRequestId, or null. Refresh/fornya/avbryt/
     * paminn slår upp här; anroparen (SigneringService) kör IDOR-guarden
     * (raden MÅSTE tillhöra det authz-grindade ärendet).
     *
     * @throws Exception
     */
    public function findBySignRequestId(string $signRequestId): ?Signering {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('sign_request_id', $qb->createNamedParameter($signRequestId, IQueryBuilder::PARAM_STR)))
            ->setMaxResults(1);

        $rows = $this->findEntities($qb);
        return $rows[0] ?? null;
    }

    /**
     * Delete every signeringsrad for a case. Idempotent — used by the
     * GallringService destruktionsspegel (K-SIGN-19) so no begäran-state
     * survives a torn-down ärenderum. Returns the number of rows deleted.
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
