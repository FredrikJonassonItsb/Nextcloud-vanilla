<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Controller;

use OCA\HubsArende\AppInfo\Application;
use OCA\HubsArende\Integration\Port\Exception\SigningRequestException;
use OCA\HubsArende\Service\SigneringService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * OCS surface of the SIGNERINGSLIVSCYKELN (KRAV-SIGNERING-2026-07, fas 1) —
 * tvånivåmodellens Godkänn/Signera-flöde mot {@see SigneringService} (den enda
 * konsumenten av SigneringPort; UI:t pratar ALDRIG direkt med porten, M2).
 *
 * Thin transport layer (mönstret från {@see PartController}): validerar/
 * normaliserar indata och delegerar allt till SigneringService, som äger
 * authz-grinden (ArendeService::show — saknat ärende OCH obehörig enhet ger
 * båda DoesNotExistException → 404, existens läcker inte), IDOR-guarden på
 * signRequestId, journalföringen och bevakningskopplingen.
 *
 * PII-doktrin: filnamn i SVAREN är avsedda (anroparen är behörig handläggare
 * inom ärendets behörighetsgräns); loggarna här bär ENDAST ref/signRequestId —
 * aldrig filnamn/hash-innehåll (K-SIGN-15).
 *
 * UI-BRANDREGEL: inga leverantörsnamn i feltexter — alltid "e-underskrift".
 *
 * Effective prefix: /ocs/v2.php/apps/hubs_arende/api/v1/...
 */
class SigneringController extends OCSController {
    public function __construct(
        IRequest $request,
        private readonly SigneringService $signeringService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Signeringsöversikten: nivåmatrisen (K-SIGN-1) + ärendets poster (K-SIGN-5).
     *
     * GET /api/v1/arende/{ref}/signering
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function oversikt(string $ref): DataResponse {
        if ($ref === '') {
            return new DataResponse(['error' => 'ref_saknas'], Http::STATUS_BAD_REQUEST);
        }
        try {
            return new DataResponse($this->signeringService->listForCase($ref), Http::STATUS_OK);
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
        } catch (\Throwable $e) {
            $this->logger->error('hubs_arende signering oversikt failed', ['exception' => $e, 'ref' => $ref]);
            return new DataResponse(['error' => 'signering_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Digitalt godkännande (K-SIGN-2) — journalförs, renderas ALDRIG som
     * underskrift.
     *
     * POST /api/v1/arende/{ref}/signering/godkann  {handlingRef, filename, dokumentHash}
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function godkann(string $ref, string $handlingRef = '', string $filename = '', string $dokumentHash = ''): DataResponse {
        if ($ref === '') {
            return new DataResponse(['error' => 'ref_saknas'], Http::STATUS_BAD_REQUEST);
        }
        try {
            return new DataResponse(
                $this->signeringService->godkann($ref, $handlingRef, $filename, $dokumentHash),
                Http::STATUS_OK,
            );
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Throwable $e) {
            $this->logger->error('hubs_arende signering godkann failed', ['exception' => $e, 'ref' => $ref]);
            return new DataResponse(['error' => 'godkann_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Begär en e-underskrift (AdES, K-SIGN-3/4). Svarar med SigneringDTO
     * (status pending — eller signed direkt i instant-demoläget).
     *
     * POST /api/v1/arende/{ref}/signering/begar
     *   {handlingRef, filename, dokumentHash, signers: [{uid, role}]}
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function begar(
        string $ref,
        string $handlingRef = '',
        string $filename = '',
        string $dokumentHash = '',
        array $signers = [],
    ): DataResponse {
        if ($ref === '') {
            return new DataResponse(['error' => 'ref_saknas'], Http::STATUS_BAD_REQUEST);
        }
        try {
            return new DataResponse(
                $this->signeringService->begar($ref, $handlingRef, $filename, $dokumentHash, $signers),
                Http::STATUS_OK,
            );
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (SigningRequestException $e) {
            // Porten avvisade begäran (journalförd i servicen). UI-brandregeln:
            // neutral text, aldrig leverantörsnamn.
            $this->logger->warning('hubs_arende signering begar avvisad', ['ref' => $ref, 'error' => $e->getMessage()]);
            return new DataResponse(['error' => 'begaran_avvisad'], Http::STATUS_CONFLICT);
        } catch (\Throwable $e) {
            $this->logger->error('hubs_arende signering begar failed', ['exception' => $e, 'ref' => $ref]);
            return new DataResponse(['error' => 'begar_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Polla/uppdatera en begäran (idempotent, K-SIGN-22).
     *
     * POST /api/v1/arende/{ref}/signering/{signRequestId}/refresh
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function refresh(string $ref, string $signRequestId): DataResponse {
        return $this->korPostAtgard($ref, $signRequestId, 'refresh',
            fn (): array => $this->signeringService->refresh($ref, $signRequestId));
    }

    /**
     * Förnya en avvisad/utgången begäran — NY begäran med journalförd kedja
     * (K-SIGN-7).
     *
     * POST /api/v1/arende/{ref}/signering/{signRequestId}/fornya
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function fornya(string $ref, string $signRequestId): DataResponse {
        return $this->korPostAtgard($ref, $signRequestId, 'fornya',
            fn (): array => $this->signeringService->fornya($ref, $signRequestId));
    }

    /**
     * Avbryt en begäran lokalt med journalfört skäl (K-SIGN-7).
     *
     * POST /api/v1/arende/{ref}/signering/{signRequestId}/avbryt  {skal}
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function avbryt(string $ref, string $signRequestId, string $skal = ''): DataResponse {
        return $this->korPostAtgard($ref, $signRequestId, 'avbryt',
            fn (): array => $this->signeringService->avbryt($ref, $signRequestId, $skal));
    }

    /**
     * Manuell påminnelse (v1: journalförs; Talk-utskick är fas 2) (K-SIGN-7).
     *
     * POST /api/v1/arende/{ref}/signering/{signRequestId}/paminn
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function paminn(string $ref, string $signRequestId): DataResponse {
        return $this->korPostAtgard($ref, $signRequestId, 'paminn',
            fn (): array => $this->signeringService->paminn($ref, $signRequestId));
    }

    // ------------------------------------------------------------------ //

    /**
     * Gemensam felmappning för per-begäran-åtgärderna (refresh/fornya/avbryt/
     * paminn): 404 vid saknat/obehörigt ärende ELLER främmande signRequestId
     * (IDOR-guarden i servicen), 400 vid ogiltig åtgärd/indata, 409 vid
     * port-avvisad ny begäran, 500 annars. Loggar aldrig filnamn/innehåll.
     *
     * @param callable(): array<string,mixed> $atgard
     */
    private function korPostAtgard(string $ref, string $signRequestId, string $namn, callable $atgard): DataResponse {
        if ($ref === '' || $signRequestId === '') {
            return new DataResponse(['error' => 'ref_eller_sign_request_id_saknas'], Http::STATUS_BAD_REQUEST);
        }
        try {
            return new DataResponse($atgard(), Http::STATUS_OK);
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (SigningRequestException $e) {
            $this->logger->warning('hubs_arende signering ' . $namn . ' avvisad', [
                'ref' => $ref, 'signRequestId' => $signRequestId, 'error' => $e->getMessage(),
            ]);
            return new DataResponse(['error' => 'begaran_avvisad'], Http::STATUS_CONFLICT);
        } catch (\Throwable $e) {
            $this->logger->error('hubs_arende signering ' . $namn . ' failed', [
                'exception' => $e, 'ref' => $ref, 'signRequestId' => $signRequestId,
            ]);
            return new DataResponse(['error' => $namn . '_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
