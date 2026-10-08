<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Controller;

use OCA\HubsArende\AppInfo\Application;
use OCA\HubsArende\Service\Brain\AuthzService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * P-A1 — OCS-authz-endpoint (SPEC kap 5.2). Server-till-server-ytan som brain-gw
 * (Node) anropar för varje MCP-/funktionsanrop:
 *
 *   POST /ocs/v2.php/apps/hubs_arende/api/v1/authz/check
 *        {uid, hubs_case_id, funktion} → {allow, roll, skal, skydd}
 *
 * ── PLACERING (kärnintegration) ────────────────────────────────────────────
 * Controllern bor i det PLATTA Controller-namespacet (som alla andra controllers
 * i appen), inte i en Brain-undermapp: NC:s rutt-namn 'Authz#check' resolvar per
 * konvention till OCA\HubsArende\Controller\AuthzController. Rutten registreras i
 * appinfo/routes.php under 'ocs'. AuthzService ligger kvar i Service\Brain
 * (autowiras per typ, oberoende av namespace).
 *
 * ── VARFÖR PublicPage + egen gateway-secret ────────────────────────────────
 * Anropet kommer server-till-server från brain-gw UTAN NC-session (ingen inloggad
 * användare). #[NoAdminRequired] kräver en session och skulle 401:a varje anrop.
 * Vi använder därför #[PublicPage] + #[NoCSRFRequired] och verifierar i stället ett
 * DEDIKERAT gateway-secret (Seam A-mönstret i OMVÄND riktning: gw är klient, vi är
 * server). Fail-closed: okonfigurerat secret ELLER felaktigt presenterat secret ⇒
 * 403, aldrig ett beslut.
 *
 *   app-config `hubs_arende.authz_gateway_secret` (sensitive) — det delade
 *   hemligheten, seedad out-of-band via deploy-secretloopen (aldrig-skriv-över).
 *   Presenteras av gw i header `X-Hubs-Authz-Secret` (eller `Authorization: Bearer`).
 *
 * ── SVARSKONTRAKT ──────────────────────────────────────────────────────────
 * ALLTID HTTP 200 för ett BESLUT (beslutet i kroppen — OCS-envelope, gw läser
 * ocs.data). Transportfel: 403 (fel/okonfigurerat secret), 400 (ogiltig kropp).
 * Alla andra utfall = 200 med allow:false. Beslutet {allow, roll, skal, skydd}
 * fattas i {@see AuthzService::check()} (fail-closed, kastar aldrig).
 *
 * Effective prefix: /ocs/v2.php/apps/hubs_arende/api/v1/...
 */
class AuthzController extends OCSController {
    /** app-config-nyckeln för det delade gateway-secretet (sensitive). */
    public const CONFIG_KEY_GATEWAY_SECRET = 'authz_gateway_secret';
    /** Primär header som gw presenterar secretet i. */
    private const HEADER_SECRET = 'X-Hubs-Authz-Secret';

    public function __construct(
        IRequest $request,
        private readonly AuthzService $authzService,
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Authz-beslut för brain-gw.
     *
     * POST /api/v1/authz/check
     *
     * @param string $uid          Principalen som prövas.
     * @param string $hubs_case_id Ärendets kanoniska nyckel.
     * @param string $funktion     fn_* / ask / read_full / capture (valideras i tjänsten).
     * @param string $verktyg      MCP-verktygsnamn (endast audit; påverkar ej beslutet).
     *
     * @return DataResponse<int, array{allow:bool, roll:?string, skal:string, skydd:bool}|array<empty>, array{}>
     */
    #[PublicPage]
    #[NoCSRFRequired]
    public function check(
        string $uid = '',
        string $hubs_case_id = '',
        string $funktion = '',
        string $verktyg = '',
    ): DataResponse {
        // Gateway-secret-verifiering FÖRST (fail-closed).
        if (!$this->arGatewayKonto()) {
            return new DataResponse([], Http::STATUS_FORBIDDEN);
        }
        // Ogiltig kropp: saknat/tomt obligatoriskt fält ⇒ 400 (aldrig ett beslut).
        if ($uid === '' || $hubs_case_id === '' || $funktion === '') {
            return new DataResponse([], Http::STATUS_BAD_REQUEST);
        }

        // $verktyg är endast audit-spårbarhet i gw — det ska aldrig påverka beslutet
        // (klassen härleds ur $funktion). Vi läser det men vidarebefordrar det inte.
        unset($verktyg);

        // Beslutet fattas fail-closed i tjänsten (kastar aldrig) ⇒ alltid 200.
        $beslut = $this->authzService->check($uid, $hubs_case_id, $funktion);
        return new DataResponse($beslut, Http::STATUS_OK);
    }

    /**
     * Fail-closed verifiering av det delade gateway-secretet. Konstant-tidsjämförelse
     * (hash_equals) mot app-config-värdet. Okonfigurerat secret ('') ⇒ alltid false
     * (ingen default-öppning); saknad/felaktig header ⇒ false.
     */
    private function arGatewayKonto(): bool {
        $konfigurerat = trim($this->appConfig->getAppValueString(self::CONFIG_KEY_GATEWAY_SECRET, ''));
        if ($konfigurerat === '') {
            // Ej provisionerat ⇒ stäng ytan helt (fail-closed) och logga en gång.
            $this->logger->warning('hubs_arende authz/check: gateway-secret ej konfigurerat — nekar (fail-closed)', [
                'app' => 'hubs_arende',
            ]);
            return false;
        }

        $presenterat = $this->presenteratSecret();
        if ($presenterat === '') {
            return false;
        }
        return hash_equals($konfigurerat, $presenterat);
    }

    /**
     * Läs det presenterade secretet: primärt ur X-Hubs-Authz-Secret, sekundärt ur
     * ett Authorization: Bearer-värde. Returnerar '' när inget presenteras.
     */
    private function presenteratSecret(): string {
        $header = trim($this->request->getHeader(self::HEADER_SECRET));
        if ($header !== '') {
            return $header;
        }
        $auth = trim($this->request->getHeader('Authorization'));
        if (stripos($auth, 'Bearer ') === 0) {
            return trim(substr($auth, 7));
        }
        return '';
    }
}
