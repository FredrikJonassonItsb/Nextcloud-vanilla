<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Controller;

use OCA\HubsArende\AppInfo\Application;
use OCA\HubsArende\Db\Arende;
use OCA\HubsArende\Service\ArendeLifecycleService;
use OCA\HubsArende\Service\ArendeService;
use OCA\HubsArende\Exception\AvvisadException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * OCS surface of the standalone ärende-motor.
 *
 * Thin transport layer: it validates/normalises input and delegates all
 * business logic to ArendeService (the saga single-writer), which owns the
 * verified commit path (existence check, payload enrichment, idempotency and
 * the provenance/retention flip). No coordination state is computed here.
 *
 * Effective prefix: /ocs/v2.php/apps/hubs_arende/api/v1/...
 */
class ArendeController extends OCSController {
    public function __construct(
        IRequest $request,
        private readonly ArendeService $arendeService,
        private readonly ArendeLifecycleService $lifecycleService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Create a new case. Runs the SAGA (säkerhetsskydd-grind first, then
     * R1–R10 with compensation). Idempotent on conversationId.
     *
     * POST /api/v1/arende
     *
     * @param array<string, mixed> $rad inbound triage row
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function createCase(array $rad = []): DataResponse {
        try {
            $arende = $this->arendeService->createCase($rad);
            return new DataResponse($arende->jsonSerialize(), Http::STATUS_CREATED);
        } catch (AvvisadException $e) {
            // Säkerhetsskydd-grind rejected the row: no case/tag/room was created.
            // Surface the avvisningskvitto verbatim so the caller can show it.
            return new DataResponse(
                [
                    'avvisad' => true,
                    'reason' => $e->getMessage(),
                    'kvitto' => $e->getKvitto(),
                    'retroaktiv' => $e->isRetroaktiv(),
                ],
                Http::STATUS_FORBIDDEN,
            );
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('hubs_arende createCase failed', ['exception' => $e]);
            return new DataResponse(['error' => 'create_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Open a case by hubsCaseId or dnr — an O(1) register lookup.
     *
     * GET /api/v1/arende/{ref}
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function show(string $ref): DataResponse {
        if ($ref === '') {
            return new DataResponse(['error' => 'ref_saknas'], Http::STATUS_BAD_REQUEST);
        }
        try {
            $arende = $this->arendeService->show($ref);
            // Full dashboard card (collapsed fields + empty heavy flik-fält) for the
            // frontend's lazy-load on card expand — engine-honest + thin.
            return new DataResponse($this->arendeService->mapToFullCard($arende), Http::STATUS_OK);
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('hubs_arende show failed', ['exception' => $e, 'ref' => $ref]);
            return new DataResponse(['error' => 'show_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Dashboard aggregate over the caller's authorised enheter. Returns counts
     * and frist colours only — never innehåll (OSL 26 kap.).
     *
     * GET /api/v1/arende-summary
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function summary(?string $enhet = null): DataResponse {
        try {
            // Dashboarden (loadArendeSummary) konsumerar dashboard-shapen
            // {arenden, puls, triage, moten, klartIdag} — INTE de rena enhet-counts
            // som summary($enhet) ger. Returnera dashboardSummary() så "Mina ärenden"
            // OCH koppla-väljaren får de faktiska ärende-korten (engine-honest, thin).
            // De aggregerade counts finns kvar i summary($enhet) för ev. andra konsumenter.
            $summary = $this->arendeService->dashboardSummary();
            if ($enhet !== null && $enhet !== '') {
                $summary['counts'] = $this->arendeService->summary($enhet);
            }
            return new DataResponse($summary, Http::STATUS_OK);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('hubs_arende summary failed', ['exception' => $e]);
            return new DataResponse(['error' => 'summary_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Assign a case to a handläggare (sets agareUid + status=tilldelat and
     * rewrites ACL). Delegates to ArendeService::tilldela().
     *
     * POST /api/v1/arende/{ref}/tilldela
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function tilldela(string $ref, string $uid): DataResponse {
        if ($ref === '' || $uid === '') {
            return new DataResponse(['error' => 'ref_eller_uid_saknas'], Http::STATUS_BAD_REQUEST);
        }
        try {
            $this->arendeService->tilldela($ref, $uid);
            return new DataResponse(['ok' => true, 'ref' => $ref, 'uid' => $uid], Http::STATUS_OK);
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('hubs_arende tilldela failed', ['exception' => $e, 'ref' => $ref]);
            return new DataResponse(['error' => 'tilldela_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Commit a case to the facksystem. Delegates to ArendeService::commit(),
     * which resolves the case (existence check), enriches the payload with
     * routing state, selects the port and — on a verified receipt — flips
     * provenance/retention in the register. Returns the verified receipt
     * {ok, dnr, committedAt, gallrasDatum, verifierad}.
     *
     * POST /api/v1/treserva/commit
     *
     * @param array<string, mixed> $payload
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function commit(string $hubsCaseId, array $payload = []): DataResponse {
        if ($hubsCaseId === '') {
            return new DataResponse(['error' => 'hubsCaseId_saknas'], Http::STATUS_BAD_REQUEST);
        }
        try {
            // hubsCaseId is the public wire name; ArendeService::commit() takes a
            // ref and resolves the entity via show() (which throws
            // DoesNotExistException on a missing/unauthorised case → 404 below).
            // Routing through the service restores existence check, payload
            // enrichment, idempotency and the verified provenance/retention flip.
            $receipt = $this->arendeService->commit($hubsCaseId, $payload);
            return new DataResponse($receipt, Http::STATUS_OK);
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('hubs_arende commit failed', ['exception' => $e, 'hubsCaseId' => $hubsCaseId]);
            return new DataResponse(['error' => 'commit_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Transition a case to a new lifecycle step. Delegates to
     * ArendeLifecycleService::transitionera(), which reuses ArendeService::show()
     * for the authz + existence gate, validates the move against the canonical
     * transition graph (illegal move → 400) and persists the new step. Returns the
     * updated case (unchanged on an idempotent same-step no-op).
     *
     * POST /api/v1/arende/{hubsCaseId}/steg
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function steg(string $hubsCaseId, string $nyttSteg, bool $skyddsbedomningKvitterad = false): DataResponse {
        if ($hubsCaseId === '' || $nyttSteg === '') {
            return new DataResponse(['error' => 'hubsCaseId_eller_nyttSteg_saknas'], Http::STATUS_BAD_REQUEST);
        }
        try {
            // ORO-1: vidarebefordra ev. skyddsbedömnings-kvittens som kontext. Plikt-
            // grinden i transitionera() kräver detta för förhandsbedömning→utredning på
            // en pliktGrind-typ (orosanmälan). Frontend sätter true när handläggaren
            // fattat beslutet (verifierad commit av skyddsbedömningen = kvittering).
            $arende = $this->lifecycleService->transitionera(
                $hubsCaseId,
                $nyttSteg,
                ['skyddsbedomningKvitterad' => $skyddsbedomningKvitterad],
            );
            return new DataResponse($arende->jsonSerialize(), Http::STATUS_OK);
        } catch (DoesNotExistException) {
            // Covers both a missing case and an unauthorised enhet (existence not leaked).
            return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('hubs_arende steg failed', ['exception' => $e, 'hubsCaseId' => $hubsCaseId]);
            return new DataResponse(['error' => 'steg_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * The ärenderum's first-class members (uid + roll). For the dashboard's
     * "rummets användare". Read-only.
     *
     * GET /api/v1/arende/{ref}/medlemmar
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function medlemmar(string $ref): DataResponse {
        if ($ref === '') {
            return new DataResponse(['error' => 'ref_saknas'], Http::STATUS_BAD_REQUEST);
        }
        try {
            return new DataResponse(['medlemmar' => $this->arendeService->medlemmar($ref)], Http::STATUS_OK);
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            $this->logger->error('hubs_arende medlemmar failed', ['exception' => $e, 'ref' => $ref]);
            return new DataResponse(['error' => 'medlemmar_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Add a member (co-handläggare/observatör) to the ärenderum — additive, so the
     * room can have several concurrent users.
     *
     * POST /api/v1/arende/{ref}/medlem  {uid, roll?}
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function laggTillMedlem(string $ref, string $uid, string $roll = 'co_handlaggare'): DataResponse {
        if ($ref === '' || $uid === '') {
            return new DataResponse(['error' => 'ref_eller_uid_saknas'], Http::STATUS_BAD_REQUEST);
        }
        try {
            $this->arendeService->laggTillMedlem($ref, $uid, $roll);
            return new DataResponse(['ok' => true, 'ref' => $ref, 'uid' => $uid, 'roll' => $roll], Http::STATUS_OK);
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('hubs_arende laggTillMedlem failed', ['exception' => $e, 'ref' => $ref]);
            return new DataResponse(['error' => 'medlem_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Remove a member (revoke a co-handläggare/observatör) from the ärenderum.
     *
     * DELETE /api/v1/arende/{ref}/medlem  {uid, roll?}
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function taBortMedlem(string $ref, string $uid, string $roll = 'co_handlaggare'): DataResponse {
        if ($ref === '' || $uid === '') {
            return new DataResponse(['error' => 'ref_eller_uid_saknas'], Http::STATUS_BAD_REQUEST);
        }
        try {
            $this->arendeService->taBortMedlem($ref, $uid, $roll);
            return new DataResponse(['ok' => true, 'ref' => $ref, 'uid' => $uid, 'roll' => $roll], Http::STATUS_OK);
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('hubs_arende taBortMedlem failed', ['exception' => $e, 'ref' => $ref]);
            return new DataResponse(['error' => 'medlem_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * 1:n — spawn an ADDITIONAL talkrum in the same ärenderum (same hubs_case_id).
     * The default saga creates exactly one (R6); this is the explicit path for more.
     *
     * POST /api/v1/arende/{ref}/talkrum  {namn?}
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function laggTillTalkrum(string $ref, ?string $namn = null): DataResponse {
        if ($ref === '') {
            return new DataResponse(['error' => 'ref_saknas'], Http::STATUS_BAD_REQUEST);
        }
        try {
            $token = $this->arendeService->laggTillTalkrum($ref, $namn);
            return new DataResponse(['ok' => $token !== null, 'talkToken' => $token], Http::STATUS_OK);
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            $this->logger->error('hubs_arende laggTillTalkrum failed', ['exception' => $e, 'ref' => $ref]);
            return new DataResponse(['error' => 'talkrum_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * 1:n — spawn an ADDITIONAL groupfolder in the same ärenderum (same hubs_case_id).
     *
     * POST /api/v1/arende/{ref}/groupfolder  {namn?}
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function laggTillGroupfolder(string $ref, ?string $namn = null): DataResponse {
        if ($ref === '') {
            return new DataResponse(['error' => 'ref_saknas'], Http::STATUS_BAD_REQUEST);
        }
        try {
            $folderId = $this->arendeService->laggTillGroupfolder($ref, $namn);
            return new DataResponse(['ok' => $folderId !== null, 'folderId' => $folderId], Http::STATUS_OK);
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            $this->logger->error('hubs_arende laggTillGroupfolder failed', ['exception' => $e, 'ref' => $ref]);
            return new DataResponse(['error' => 'groupfolder_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
