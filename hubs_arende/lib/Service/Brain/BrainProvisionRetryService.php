<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Service\Brain;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * DURABEL provisionerings-retry-kö (SPEC-BRAIN-PER-ÄRENDE kap 3.3).
 *
 * När R2b:s provisionering fallerar RETRYBART (provisionern onåbar/timeout/5xx) läggs
 * ärendet här i stället för att fälla sagan — "ärendeskapande blockeras ALDRIG av
 * AI-infra" (kap 1.2). {@see \OCA\HubsArende\BackgroundJob\BrainProvisionRetryJob}
 * plockar `pending`-rader vars `nasta_forsok` passerats och kör om det IDEMPOTENTA
 * `POST /provision/tenants`.
 *
 * Tabellen (`oc_hubs_arende_brain_provision`, migration Version000800) är LITEN och
 * normalt nära tom — en rad per ärende som väntar på sin brain. `hubs_case_id` är
 * PRIMÄRNYCKEL ⇒ högst en rad per ärende (idempotent enqueue).
 *
 * Denna tjänst är REN persistens (samma roll som en mapper, men mot en tabell utan
 * eget Entity/Mapper i lib/Db) — livscykeln/backoffen ägs av jobbet. NEVER-SoR:
 * raderna bär ENDAST koordinationsdata (pseudonymt hubsCaseId + ärendetyp-id + räknare),
 * aldrig ärendeinnehåll, och gallras med ärendet.
 */
class BrainProvisionRetryService {
    public const TABLE = 'hubs_arende_brain_provision';

    public const STATUS_PENDING = 'pending';
    public const STATUS_KLAR = 'klar';
    public const STATUS_PERMANENT = 'permanent_fel';

    public function __construct(
        private IDBConnection $db,
        private ITimeFactory $time,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Lägg ärendet i retry-kön (IDEMPOTENT): finns redan en rad för hubsCaseId lämnas
     * den ORÖRD (försöksräknaren/backoffen nollställs aldrig av en om-enqueue). Ny rad
     * skapas `pending`, förfaller omedelbart (`nasta_forsok = nu`) så jobbet tar den
     * vid nästa körning.
     */
    public function enqueue(string $hubsCaseId, string $arendeTypId): void {
        $nu = $this->nu();
        try {
            // insertIfNotExist: atomär idempotens på PK utan DB-specifik ON CONFLICT.
            $this->db->insertIfNotExist(
                '*PREFIX*' . self::TABLE,
                [
                    'hubs_case_id' => $hubsCaseId,
                    'status' => self::STATUS_PENDING,
                    'arende_typ' => $arendeTypId,
                    'forsok' => 0,
                    'nasta_forsok' => $nu,
                    'sista_forsok' => null,
                    'skapad' => $nu,
                ],
                ['hubs_case_id'],
            );
        } catch (DBException $e) {
            // Idempotens-kapp: en samtidig insert på samma PK ⇒ redan i kön, ok.
            $this->logger->debug('hubs_arende: brain-retry enqueue redan i kön', [
                'app' => 'hubs_arende',
                'hubsCaseId' => $hubsCaseId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * SAGA-kompensering (kap 3.3): neutralisera en ev. köad retry-rad så att jobbet
     * INTE senare provisionerar en FÖRÄLDRALÖS brain för ett ärende vars register-rad
     * (R2) kompenserades bort. Sätter `status='permanent_fel'`. Idempotent (no-op om
     * rad saknas). Kompletterar jobbets egen findByHubsCaseId-vakt (dubbel spärr).
     */
    public function neutralisera(string $hubsCaseId): void {
        $this->settStatus($hubsCaseId, self::STATUS_PERMANENT);
    }

    /** Markera raden klar (brain provisionerad) — jobbet efter lyckad POST. */
    public function markKlar(string $hubsCaseId): void {
        $this->settStatus($hubsCaseId, self::STATUS_KLAR);
    }

    /** Markera raden permanent misslyckad (409/422 eller föräldralös) + larm-terminal. */
    public function markPermanent(string $hubsCaseId): void {
        $this->settStatus($hubsCaseId, self::STATUS_PERMANENT);
    }

    /**
     * Schemalägg ett nytt försök: höj `forsok`, sätt `sista_forsok=nu` och nästa
     * fönster `nasta_forsok`. Anropas av jobbet efter ett retrybart misslyckande.
     */
    public function schemalaggAterforsok(string $hubsCaseId, int $forsok, int $nastaForsok): void {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->update(self::TABLE)
                ->set('forsok', $qb->createNamedParameter($forsok, IQueryBuilder::PARAM_INT))
                ->set('nasta_forsok', $qb->createNamedParameter($nastaForsok, IQueryBuilder::PARAM_INT))
                ->set('sista_forsok', $qb->createNamedParameter($this->nu(), IQueryBuilder::PARAM_INT))
                ->where($qb->expr()->eq('hubs_case_id', $qb->createNamedParameter($hubsCaseId, IQueryBuilder::PARAM_STR)))
                ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_PENDING, IQueryBuilder::PARAM_STR)));
            $qb->executeStatement();
        } catch (DBException $e) {
            $this->logger->warning('hubs_arende: brain-retry schemaläggning misslyckades', [
                'app' => 'hubs_arende',
                'hubsCaseId' => $hubsCaseId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Förfallna `pending`-rader (nasta_forsok <= nu), äldst först, kapad batch.
     *
     * @return array<int,array{hubs_case_id:string,arende_typ:string,forsok:int}>
     */
    public function claimDue(int $limit = 50): array {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('hubs_case_id', 'arende_typ', 'forsok')
                ->from(self::TABLE)
                ->where($qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_PENDING, IQueryBuilder::PARAM_STR)))
                ->andWhere($qb->expr()->orX(
                    $qb->expr()->isNull('nasta_forsok'),
                    $qb->expr()->lte('nasta_forsok', $qb->createNamedParameter($this->nu(), IQueryBuilder::PARAM_INT)),
                ))
                ->orderBy('nasta_forsok', 'ASC')
                ->setMaxResults($limit);
            $result = $qb->executeQuery();
            $rader = [];
            while (($rad = $result->fetch()) !== false) {
                $rader[] = [
                    'hubs_case_id' => (string)$rad['hubs_case_id'],
                    'arende_typ' => (string)$rad['arende_typ'],
                    'forsok' => (int)$rad['forsok'],
                ];
            }
            $result->closeCursor();
            return $rader;
        } catch (DBException $e) {
            $this->logger->error('hubs_arende: brain-retry claim misslyckades', [
                'app' => 'hubs_arende',
                'exception' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Läs EN körad (för tester/diagnostik). Returnerar assoc-rad eller null.
     *
     * @return array{hubs_case_id:string,status:string,arende_typ:string,forsok:int,
     *               nasta_forsok:?int,sista_forsok:?int,skapad:int}|null
     */
    public function findByCase(string $hubsCaseId): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE)
            ->where($qb->expr()->eq('hubs_case_id', $qb->createNamedParameter($hubsCaseId, IQueryBuilder::PARAM_STR)))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $rad = $result->fetch();
        $result->closeCursor();
        if ($rad === false) {
            return null;
        }
        return [
            'hubs_case_id' => (string)$rad['hubs_case_id'],
            'status' => (string)$rad['status'],
            'arende_typ' => (string)$rad['arende_typ'],
            'forsok' => (int)$rad['forsok'],
            'nasta_forsok' => isset($rad['nasta_forsok']) ? (int)$rad['nasta_forsok'] : null,
            'sista_forsok' => isset($rad['sista_forsok']) ? (int)$rad['sista_forsok'] : null,
            'skapad' => (int)$rad['skapad'],
        ];
    }

    /**
     * Radera köraden för ett ärende (idempotent). Anropas när ärendet gallras så att
     * ingen retry-rad överlever ärendet (NEVER-SoR).
     */
    public function deleteByCase(string $hubsCaseId): void {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->delete(self::TABLE)
                ->where($qb->expr()->eq('hubs_case_id', $qb->createNamedParameter($hubsCaseId, IQueryBuilder::PARAM_STR)));
            $qb->executeStatement();
        } catch (DBException $e) {
            $this->logger->warning('hubs_arende: brain-retry radering misslyckades', [
                'app' => 'hubs_arende',
                'hubsCaseId' => $hubsCaseId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function settStatus(string $hubsCaseId, string $status): void {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->update(self::TABLE)
                ->set('status', $qb->createNamedParameter($status, IQueryBuilder::PARAM_STR))
                ->where($qb->expr()->eq('hubs_case_id', $qb->createNamedParameter($hubsCaseId, IQueryBuilder::PARAM_STR)));
            $qb->executeStatement();
        } catch (DBException $e) {
            $this->logger->warning('hubs_arende: brain-retry status-uppdatering misslyckades', [
                'app' => 'hubs_arende',
                'hubsCaseId' => $hubsCaseId,
                'status' => $status,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    private function nu(): int {
        return $this->time->getTime();
    }
}
