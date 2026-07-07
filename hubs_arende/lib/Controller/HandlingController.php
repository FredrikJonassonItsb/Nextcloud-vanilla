<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Controller;

use OCA\HubsArende\AppInfo\Application;
use OCA\HubsArende\Service\ArendedataService;
use OCA\HubsArende\Service\ArendeService;
use OCA\HubsArende\Service\HandlingService;
use OCA\HubsArende\Service\MallService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * OCS-yta for HANDLING-FRAN-MALL (fas 1, se hubs_start/docs/ANALYS-HANDLING-
 * FRAN-MALL.md) — skapa en .docx-handling ur en mall i den delade mallmappen,
 * forifylld med arendedata (register + PARTSREGISTER) och skriven till
 * arenderummets groupfolder. Den genererade handlingen ar verksamhetsdata som
 * lever som en FIL i arenderummet; motorn lagrar aldrig innehallet.
 *
 * Tunt transportlager: all affarslogik ligger i tjansterna. AUTHZ-KONSEKVENS:
 * varje endpoint gar FORST genom ArendeService::show($ref) — saknat arende OCH
 * obehorig enhet ger bada DoesNotExistException (→ 404, existens lacker inte),
 * exakt som i PartController. Darefter delegeras till MallService (mall-
 * listning), ArendedataService (utkastet — den authz-grindade arendebilden)
 * respektive HandlingService (fyllning + skrivning + journalforing).
 *
 * PII-doktrin (avsiktligt, se hubs-pii-authorization-principle):
 *  - PII i RESPONSERNA ar avsedd — anroparen ar en behorig handlaggare
 *    innanfor arendets auktorisationsgrans. Invarianten ar ingen lacka OVER
 *    den gransen, inte PII-gomning for den behorige.
 *  - PII i LOGGAR ar forbjuden — personnummer/namn/adress far ALDRIG na
 *    LoggerInterface (och aldrig Handelse.detalj). Catch-blocken nedan loggar
 *    endast ref/mallId/antal falt-NYCKLAR, aldrig faltvarden.
 *  - SKYDDSGRIND (K-NAV-6.1): for barn-part med skydd=sekretessmarkering
 *    eller skyddad_folkbokforing UTELAMNAR utkastet namn-faltet som default
 *    (varning i utkastet, identifiering via personnummer). Fyller anroparen
 *    ANDA ett skydds-varnat falt ar det ett aktivt beslut som HandlingService
 *    journalfor som skyddOverride:true — aldrig sjalva vardet. Grinden
 *    verkstalls i tjanstelagret, inte har.
 *
 * Effective prefix: /ocs/v2.php/apps/hubs_arende/api/v1/...
 */
class HandlingController extends OCSController {
    public function __construct(
        IRequest $request,
        private readonly ArendeService $arendeService,
        private readonly MallService $mallService,
        private readonly ArendedataService $arendedataService,
        private readonly HandlingService $handlingService,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Lista tillgangliga mallar i den delade mallmappen. Read-only och fri
     * fran PII — men authz-grindad ANDA (show($ref) forst) sa att mallistan
     * bara nas av den som far se arendet; existens lacker aldrig.
     *
     * "tillganglig" speglar MallService::isAvailable() — ar mallmappen inte
     * natt (t.ex. groupfolders av) svarar vi arligt med tillganglig=false och
     * tom lista i stallet for att gissa.
     *
     * GET /api/v1/arende/{ref}/handling/mallar
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function mallar(string $ref): DataResponse {
        if ($ref === '') {
            return new DataResponse(['error' => 'ref_saknas'], Http::STATUS_BAD_REQUEST);
        }
        try {
            // Authz-konsekvens: samma grind som alla arende-ytor — obehorig
            // enhet och saknat arende ar odelbara utifran (→ 404).
            $this->arendeService->show($ref);
            return new DataResponse(
                [
                    'ok' => true,
                    'tillganglig' => $this->mallService->isAvailable(),
                    'mallar' => $this->mallService->listMallar(),
                ],
                Http::STATUS_OK,
            );
        } catch (DoesNotExistException) {
            // Tacker bade saknat arende och obehorig enhet (existens lacker inte).
            return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            // Mallmapp/integrationsfel — arligt 500, aldrig detaljer i svaret.
            $this->logger->error('hubs_arende handling mallar failed', ['exception' => $e, 'ref' => $ref]);
            return new DataResponse(['error' => 'handling_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        } catch (\Throwable $e) {
            $this->logger->error('hubs_arende handling mallar failed', ['exception' => $e, 'ref' => $ref]);
            return new DataResponse(['error' => 'mallar_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * UTKAST — den forifyllda arendebilden (register + partsregister) som
     * dialogen visar innan handlaggaren skapar handlingen. PII i svaret ar
     * avsedd for den behorige handlaggaren; ArendedataService ager bade
     * aggregeringen och SKYDDSGRINDEN (skydds-varnade namn-falt kommer TOMMA
     * med varningsflagga — se klassdocblocken). Datum-platshallare fylls
     * medvetet inte (66 forekomster med olika betydelser — arlighet fore
     * tackning); oersatta platshallare lamnas at handlaggaren i redigeraren.
     *
     * GET /api/v1/arende/{ref}/handling/utkast
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function utkast(string $ref, ?string $mallId = null): DataResponse {
        if ($ref === '') {
            return new DataResponse(['error' => 'ref_saknas'], Http::STATUS_BAD_REQUEST);
        }
        try {
            // Authz-konsekvens: show($ref) forst — sedan bygger datakalle-
            // lagret utkastet (som sjalvt aterupprepar grinden, dubbelt haller).
            // S4: med mallId filtreras utkastet till mallens FAKTISKA falt
            // (malldefinitionen); utan mallId returneras hela faltkartan.
            $this->arendeService->show($ref);
            $utkast = $this->arendedataService->byggUtkast($ref, $mallId);
            return new DataResponse(['ok' => true] + $utkast, Http::STATUS_OK);
        } catch (DoesNotExistException) {
            return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            // PII-doktrin: utkastet innehaller identitet — loggen far ALDRIG
            // gora det. Endast ref loggas.
            $this->logger->error('hubs_arende handling utkast failed', ['exception' => $e, 'ref' => $ref]);
            return new DataResponse(['error' => 'handling_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        } catch (\Throwable $e) {
            $this->logger->error('hubs_arende handling utkast failed', ['exception' => $e, 'ref' => $ref]);
            return new DataResponse(['error' => 'utkast_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * SKAPA HANDLING — fyll mallen med falten och skriv den fardiga .docx:en
     * till arenderummets groupfolder. $falt ar handlaggarens (ev. justerade)
     * varden per generisk faltnyckel (arendeRef/enhet/handlaggare/barnNamn/
     * barnPnr, fas 1); HandlingService ager fyllningen, skrivningen och
     * journalforingen (mallnamn/antal — aldrig faltvarden i journalen).
     *
     * Skyddsgrinden: har anroparen aktivt fyllt ett skydds-varnat falt (t.ex.
     * barnNamn trots sekretessmarkering) journalfors det som
     * skyddOverride:true av HandlingService — aldrig sjalva vardet.
     *
     * POST /api/v1/arende/{ref}/handling  {mallId, falt?}
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function skapa(string $ref, string $mallId, array $falt = []): DataResponse {
        if ($ref === '' || $mallId === '') {
            return new DataResponse(['error' => 'ref_eller_mall_saknas'], Http::STATUS_BAD_REQUEST);
        }
        try {
            // Authz-konsekvens: show($ref) forst, sedan generera (som sjalv
            // aterupprepar grinden innan nagot skrivs till rummet).
            $this->arendeService->show($ref);
            $resultat = $this->handlingService->generera($ref, $mallId, $falt);
            return new DataResponse(
                [
                    'ok' => true,
                    'filnamn' => $resultat['filnamn'] ?? '',
                    'antalErsatta' => $resultat['antalErsatta'] ?? 0,
                ],
                Http::STATUS_OK,
            );
        } catch (DoesNotExistException) {
            // Tacker saknat arende, obehorig enhet OCH okand mall (om
            // HandlingService valjer att signalera saknad mall sa).
            return new DataResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\RuntimeException $e) {
            // Fyllning/skrivning misslyckades (mallmapp, groupfolder, docx).
            // PII-doktrin: $falt innehaller identitet — logga ALDRIG vardena,
            // endast ref/mallId/antal falt-nycklar.
            $this->logger->error('hubs_arende handling skapa failed', [
                'exception' => $e,
                'ref' => $ref,
                'mallId' => $mallId,
                'antalFalt' => count($falt),
            ]);
            return new DataResponse(['error' => 'handling_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        } catch (\Throwable $e) {
            $this->logger->error('hubs_arende handling skapa failed', [
                'exception' => $e,
                'ref' => $ref,
                'mallId' => $mallId,
                'antalFalt' => count($falt),
            ]);
            return new DataResponse(['error' => 'skapa_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
