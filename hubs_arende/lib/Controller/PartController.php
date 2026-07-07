<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Controller;

use OCA\HubsArende\AppInfo\Application;
use OCA\HubsArende\Service\PartService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * OCS surface of the PARTSREGISTER — the case's parter (klient, vårdnads-
 * havare, umgängespersoner m.fl.) backed by oc_hubs_arende_part, the engine's
 * ONLY sanctioned PII table (beslut 2026-07-06): transient arbetsdata that is
 * gallrad with the case and is NEVER the system of record.
 *
 * Thin transport layer: it validates/normalises input and delegates all
 * business logic to PartService, which owns the authz gate (case resolution
 * via ArendeService::show — missing case AND unauthorised enhet both surface
 * as DoesNotExistException → 404, existence never leaked), the fail-closed
 * skydd handling, the NAVET-uppslag through the folkbokföringsport and the
 * journalföring. No coordination state is computed here.
 *
 * PII-doktrin (avsiktligt, se hubs-pii-authorization-principle):
 *  - PII in the RESPONSES is intended — the caller is an authorised
 *    handläggare inside the case's authorization boundary. The invariant is
 *    no leakage ACROSS that boundary, not PII-hiding from the authorised.
 *  - PII in LOGS is forbidden — personnummer/namn must never reach
 *    LoggerInterface (nor Händelse.detalj). Catch blocks below log only
 *    ref/id/roll/ändamål, never identity.
 *  - Every mutation (laggTill/uppslag/uppdatera/taBort) is journalförd by
 *    PartService in the case's händelsejournal — with counts/roll/skydd/
 *    korrelationsId, never identity.
 *
 * Effective prefix: /ocs/v2.php/apps/hubs_arende/api/v1/...
 */
class PartController extends OCSController {
    public function __construct(
        IRequest $request,
        private readonly PartService $partService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * List the case's parter (partsregistret). Read-only. PII in the response
     * is intended for the authorised handläggare (authz gate in PartService).
     *
     * GET /api/v1/arende/{ref}/parter
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function parter(string $ref): DataResponse {
        if ($ref === '') {
            return new DataResponse(['error' => 'ref_saknas'], Http::STATUS_BAD_REQUEST);
        }
        try {
            return new DataResponse(['ok' => true, 'parter' => $this->partService->parter($ref)], Http::STATUS_OK);
        } catch (DoesNotExistException) {
            // Covers both a missing case and an unauthorised enhet (existence not leaked).
            return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            $this->logger->error('hubs_arende parter failed', ['exception' => $e, 'ref' => $ref]);
            return new DataResponse(['error' => 'parter_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Add a part MANUALLY (no NAVET-uppslag) — e.g. an anmälare, a kontakt-
     * person or a person without a svenskt personnummer. skydd is MANDATORY
     * and fail-closed: PartService rejects a missing/unknown value with
     * InvalidArgumentException (→ 400), it is never defaulted to "ingen".
     * Journalförd (counts/roll/skydd, never identity).
     *
     * POST /api/v1/arende/{ref}/part  {roll, skydd, namn?, personnummer?, adress?, kontakt?}
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function laggTill(
        string $ref,
        string $roll,
        string $skydd,
        string $namn = '',
        ?string $personnummer = null,
        ?string $adress = null,
        ?string $kontakt = null,
    ): DataResponse {
        if ($ref === '' || $roll === '' || $skydd === '') {
            // skydd är obligatoriskt (fail-closed) — en tom sträng avvisas här,
            // ett okänt värde avvisas i PartService. Aldrig default "ingen".
            return new DataResponse(['error' => 'ref_roll_eller_skydd_saknas'], Http::STATUS_BAD_REQUEST);
        }
        try {
            $part = $this->partService->laggTill($ref, [
                'roll' => $roll,
                'skydd' => $skydd,
                'namn' => $namn,
                'personnummer' => $personnummer,
                'adress' => $adress,
                'kontakt' => $kontakt,
            ]);
            return new DataResponse(['ok' => true, 'part' => $part], Http::STATUS_OK);
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            // PII-doktrin: logga aldrig personnummer/namn — endast ref/roll.
            $this->logger->error('hubs_arende laggTill part failed', ['exception' => $e, 'ref' => $ref, 'roll' => $roll]);
            return new DataResponse(['error' => 'part_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * NAVET-UPPSLAG — resolve a person via folkbokföringsporten (Skatteverket
     * Navet) and register the result as a part. PartService owns the fail-
     * closed skydd gate (a personpost without a known skydd value is rejected,
     * never defaulted) and the skyddad_folkbokföring rule (verklig adress is
     * never stored; only särskild postadress may be). With
     * inkluderaVardnadshavare=true the guardians (relationer typ V/VF) are
     * looked up and registered too. Ändamålsprövningen (why the lookup is
     * made) is mandatory and journalförd — counts/roll/skydd/ändamål, never
     * identity. The personnummer itself is never logged.
     *
     * POST /api/v1/arende/{ref}/part/uppslag  {personnummer, roll, andamal, inkluderaVardnadshavare?}
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function uppslag(
        string $ref,
        string $personnummer,
        string $roll,
        string $andamal,
        bool $inkluderaVardnadshavare = false,
    ): DataResponse {
        if ($ref === '' || $personnummer === '' || $roll === '' || $andamal === '') {
            return new DataResponse(['error' => 'ref_personnummer_roll_eller_andamal_saknas'], Http::STATUS_BAD_REQUEST);
        }
        try {
            $resultat = $this->partService->uppslag($ref, $personnummer, $roll, $andamal, $inkluderaVardnadshavare);
            return new DataResponse(
                [
                    'ok' => true,
                    'part' => $resultat['part'] ?? null,
                    'vardnadshavare' => $resultat['vardnadshavare'] ?? [],
                    'relationer' => $resultat['relationer'] ?? [],
                ],
                Http::STATUS_OK,
            );
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            // PII-doktrin: personnumret loggas ALDRIG — endast ref/roll/ändamål.
            $this->logger->error('hubs_arende uppslag failed', ['exception' => $e, 'ref' => $ref, 'roll' => $roll, 'andamal' => $andamal]);
            return new DataResponse(['error' => 'uppslag_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Refresh an existing part against folkbokföringen (re-uppslag on the
     * stored personnummer) — e.g. after a flytt, namnbyte or a changed skydds-
     * nivå. Same fail-closed skydd gate and skyddad_folkbokföring adress rule
     * as uppslag(). Ändamålet is mandatory and journalförd.
     *
     * POST /api/v1/arende/{ref}/part/{id}/uppdatera  {andamal}
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function uppdatera(string $ref, int $id, string $andamal): DataResponse {
        if ($ref === '' || $andamal === '') {
            return new DataResponse(['error' => 'ref_eller_andamal_saknas'], Http::STATUS_BAD_REQUEST);
        }
        try {
            $part = $this->partService->uppdateraFranNavet($ref, $id, $andamal);
            return new DataResponse(['ok' => true, 'part' => $part], Http::STATUS_OK);
        } catch (DoesNotExistException) {
            // Covers a missing case, an unauthorised enhet AND a part id that
            // does not belong to this case (ownership gate in PartService).
            return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('hubs_arende uppdatera part failed', ['exception' => $e, 'ref' => $ref, 'id' => $id, 'andamal' => $andamal]);
            return new DataResponse(['error' => 'uppdatera_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Remove a part from the partsregister. The PII row is deleted (transient
     * arbetsdata — the partsregister is never SoR); the removal itself is
     * journalförd as a coordination event (roll/korrelationsId, never
     * identity).
     *
     * DELETE /api/v1/arende/{ref}/part/{id}
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function taBort(string $ref, int $id): DataResponse {
        if ($ref === '') {
            return new DataResponse(['error' => 'ref_saknas'], Http::STATUS_BAD_REQUEST);
        }
        try {
            $this->partService->taBort($ref, $id);
            return new DataResponse(['ok' => true, 'ref' => $ref, 'id' => $id], Http::STATUS_OK);
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('hubs_arende taBort part failed', ['exception' => $e, 'ref' => $ref, 'id' => $id]);
            return new DataResponse(['error' => 'ta_bort_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
