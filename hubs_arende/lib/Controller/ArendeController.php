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
    public function summary(?string $enhet = null, string $mine = '0'): DataResponse {
        $mineOnly = $mine === '1' || strtolower($mine) === 'true';
        try {
            // Dashboarden (loadArendeSummary) konsumerar dashboard-shapen
            // {arenden, puls, triage, moten, klartIdag} — INTE de rena enhet-counts
            // som summary($enhet) ger. Returnera dashboardSummary() så "Mina ärenden"
            // OCH koppla-väljaren får de faktiska ärende-korten (engine-honest, thin).
            // De aggregerade counts finns kvar i summary($enhet) för ev. andra konsumenter.
            // ?mine=1 ⇒ MEDLEMSBASERAT: endast ärenden där anroparen finns i ledgern.
            $summary = $this->arendeService->dashboardSummary($mineOnly);
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
     *
     * Kontext-nycklar (alla valfria, PII-fria enum-koder — se KONTRAKT):
     *  - skyddsbedomningKvitterad (bool): legacy, endast när skyddsbedömnings-flaggan är AV.
     *  - override            {skal}: A7-override när skyddsbedömning saknas.
     *  - inteInledaVal       {orsak, beslutsfattare}: A9a (förhandsbedömning→avslutat).
     *  - kommuniceringVal    {gjord, skal?}: A9b (utredning→beslut).
     *  - avslutsmotiv        {utfall, kvarstaende?}: A9c (X→avslutat, utom förhandsbedömning).
     *
     * Grinden i transitionera() kastar \InvalidArgumentException när ett obligatoriskt
     * grind-val saknas OCH grind-flaggan är på → 400 {error, grindKravs:true} så att
     * frontend kan öppna rätt grind-dialog och skicka om med rätt kontext-nyckel.
     *
     * @param array<string, mixed>|null $override        A7-override {skal}
     * @param array<string, mixed>|null $inteInledaVal    A9a {orsak, beslutsfattare}
     * @param array<string, mixed>|null $kommuniceringVal A9b {gjord, skal?}
     * @param array<string, mixed>|null $avslutsmotiv     A9c {utfall, kvarstaende?}
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function steg(
        string $hubsCaseId,
        string $nyttSteg,
        bool $skyddsbedomningKvitterad = false,
        ?array $override = null,
        ?array $inteInledaVal = null,
        ?array $kommuniceringVal = null,
        ?array $avslutsmotiv = null,
    ): DataResponse {
        if ($hubsCaseId === '' || $nyttSteg === '') {
            return new DataResponse(['error' => 'hubsCaseId_eller_nyttSteg_saknas'], Http::STATUS_BAD_REQUEST);
        }
        try {
            // Bygg kontext-bunten till transitionera(). Legacy-kvittensen skickas
            // alltid (grinden ignorerar den när flaggan är på); grind-valen skickas
            // bara när de faktiskt levererats så att default-beteendet är oförändrat.
            $kontext = ['skyddsbedomningKvitterad' => $skyddsbedomningKvitterad];
            if ($override !== null) {
                $kontext['override'] = $override;
            }
            if ($inteInledaVal !== null) {
                $kontext['inteInledaVal'] = $inteInledaVal;
            }
            if ($kommuniceringVal !== null) {
                $kontext['kommuniceringVal'] = $kommuniceringVal;
            }
            if ($avslutsmotiv !== null) {
                $kontext['avslutsmotiv'] = $avslutsmotiv;
            }
            $arende = $this->lifecycleService->transitionera($hubsCaseId, $nyttSteg, $kontext);
            return new DataResponse($arende->jsonSerialize(), Http::STATUS_OK);
        } catch (DoesNotExistException) {
            // Covers both a missing case and an unauthorised enhet (existence not leaked).
            return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            // Grind-krav ej uppfyllt (saknat obligatoriskt val medan flaggan är på) ELLER
            // otillåten övergång. grindKravs-flaggan låter frontend öppna rätt grind-dialog.
            return new DataResponse(
                ['error' => $e->getMessage(), 'grindKravs' => true],
                Http::STATUS_BAD_REQUEST,
            );
        } catch (\Throwable $e) {
            $this->logger->error('hubs_arende steg failed', ['exception' => $e, 'hubsCaseId' => $hubsCaseId]);
            return new DataResponse(['error' => 'steg_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * The case's HÄNDELSEJOURNAL (oldest first) — the "Historik & beslut"
     * timeline. Read-only; coordination values only, never PII/innehåll.
     *
     * GET /api/v1/arende/{ref}/historik
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function historik(string $ref): DataResponse {
        if ($ref === '') {
            return new DataResponse(['error' => 'ref_saknas'], Http::STATUS_BAD_REQUEST);
        }
        try {
            return new DataResponse(['handelser' => $this->arendeService->historik($ref)], Http::STATUS_OK);
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            $this->logger->error('hubs_arende historik failed', ['exception' => $e, 'ref' => $ref]);
            return new DataResponse(['error' => 'historik_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * The case's BEVAKNINGAR — a read-only projection of the bevaknings-REGISTER
     * (typ, villkor, status, frist): the first-class watch layer. Koordinations-
     * data without PII. Authz via the service's show()-gate.
     *
     * GET /api/v1/arende/{ref}/bevakningar
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function bevakningar(string $ref): DataResponse {
        if ($ref === '') {
            return new DataResponse(['error' => 'ref_saknas'], Http::STATUS_BAD_REQUEST);
        }
        try {
            return new DataResponse(['bevakningar' => $this->arendeService->bevakningar($ref)], Http::STATUS_OK);
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            $this->logger->error('hubs_arende bevakningar failed', ['exception' => $e, 'ref' => $ref]);
            return new DataResponse(['error' => 'bevakningar_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Skapa en ad hoc-bevakning (den tidigare döda "skapa bevakning"). Manuell
     * klarmarkering (villkor manuell_kvittering). Rubriken får ALDRIG bära PII.
     *
     * POST /api/v1/arende/{ref}/bevakning  {titel, fristDue?, recurringDagar?, lagstadgad?}
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function skapaBevakning(
        string $ref,
        string $titel = '',
        ?string $fristDue = null,
        ?int $recurringDagar = null,
        bool $lagstadgad = false,
    ): DataResponse {
        if ($ref === '' || trim($titel) === '') {
            return new DataResponse(['error' => 'ref_eller_titel_saknas'], Http::STATUS_BAD_REQUEST);
        }
        try {
            $bevakning = $this->arendeService->laggTillBevakning($ref, [
                'titel' => $titel,
                'fristDue' => $fristDue,
                'recurringDagar' => $recurringDagar,
                'lagstadgad' => $lagstadgad,
            ]);
            return new DataResponse(['ok' => true, 'bevakning' => $bevakning], Http::STATUS_OK);
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('hubs_arende skapaBevakning failed', ['exception' => $e, 'ref' => $ref]);
            return new DataResponse(['error' => 'skapa_bevakning_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Klarmarkera (kvittera) en bevakning — villkor manuell_kvittering. Recurring
     * föder en ny cykel; övriga slocknar.
     *
     * POST /api/v1/arende/{ref}/bevakning/{id}/kvittera
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function kvitteraBevakning(string $ref, int $id): DataResponse {
        if ($ref === '' || $id <= 0) {
            return new DataResponse(['error' => 'ref_eller_id_saknas'], Http::STATUS_BAD_REQUEST);
        }
        try {
            $bevakning = $this->arendeService->kvitteraBevakning($ref, $id);
            return new DataResponse(['ok' => true, 'bevakning' => $bevakning], Http::STATUS_OK);
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('hubs_arende kvitteraBevakning failed', ['exception' => $e, 'ref' => $ref]);
            return new DataResponse(['error' => 'kvittera_bevakning_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Avbryt en bevakning (ej längre relevant). Monotont slutläge.
     *
     * DELETE /api/v1/arende/{ref}/bevakning/{id}
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function avbrytBevakning(string $ref, int $id): DataResponse {
        if ($ref === '' || $id <= 0) {
            return new DataResponse(['error' => 'ref_eller_id_saknas'], Http::STATUS_BAD_REQUEST);
        }
        try {
            $bevakning = $this->arendeService->avbrytBevakning($ref, $id);
            return new DataResponse(['ok' => true, 'bevakning' => $bevakning], Http::STATUS_OK);
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('hubs_arende avbrytBevakning failed', ['exception' => $e, 'ref' => $ref]);
            return new DataResponse(['error' => 'avbryt_bevakning_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Sätt delgivningsdatum (FL 33 §) → föder/uppdaterar överklagandebevakningen
     * (3 v → laga kraft).
     *
     * POST /api/v1/arende/{ref}/delgivning  {datum: 'YYYY-MM-DD'}
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function setDelgivning(string $ref, string $datum = ''): DataResponse {
        if ($ref === '' || trim($datum) === '') {
            return new DataResponse(['error' => 'ref_eller_datum_saknas'], Http::STATUS_BAD_REQUEST);
        }
        try {
            $bevakning = $this->arendeService->setDelgivningsdatum($ref, $datum);
            return new DataResponse(['ok' => true, 'bevakning' => $bevakning], Http::STATUS_OK);
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('hubs_arende setDelgivning failed', ['exception' => $e, 'ref' => $ref]);
            return new DataResponse(['error' => 'set_delgivning_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
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
