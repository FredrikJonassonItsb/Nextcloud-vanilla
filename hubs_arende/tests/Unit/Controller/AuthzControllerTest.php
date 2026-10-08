<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Tests\Unit\Controller;

use OCA\HubsArende\Controller\AuthzController;
use OCA\HubsArende\Service\Brain\AuthzService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * P-A1 — AuthzController (SPEC kap 5.2). Rena enhetstester av CONTROLLER-lagret:
 * gateway-secret-grinden, kropps-valideringen och att svaret bär EXAKT det
 * Node-kontrakt brain-gw byggts mot. Själva authz-beslutet fattas i {@see AuthzService}
 * (mockad här — dess 23 fall täcks separat i AuthzServiceTest); det controllern
 * äger, och som testas här, är transporten runt beslutet.
 *
 * ── DET CONTROLLERN GARANTERAR (och som asserteras) ────────────────────────
 *  - FAIL-CLOSED transport: okonfigurerat ELLER felpresenterat gateway-secret ⇒ 403,
 *    ALDRIG ett beslut (och AuthzService::check() anropas då aldrig).
 *  - Ogiltig kropp (saknat uid/hubs_case_id/funktion) ⇒ 400, aldrig ett beslut.
 *  - Ett BESLUT (allow ELLER deny) ⇒ ALLTID HTTP 200; beslutet ligger i kroppen i
 *    exakt formen {allow, roll, skal, skydd} (gw läser ocs.data — formen får ej ändras).
 *  - $verktyg är audit-only och vidarebefordras ALDRIG till beslutet.
 *
 * OBS server-till-server: check() är #[PublicPage] (ingen NC-session) och verifierar
 * i stället det delade gateway-secretet — därför testas secret-grinden direkt här.
 */
final class AuthzControllerTest extends TestCase {
    private const UID = 'anna.svensson';
    private const CASE = '11111111-2222-4333-8444-555555555555';
    private const FN = 'fn_briefing';
    private const SECRET = 'delad-hemlig-gateway-nyckel';
    private const HEADER = 'X-Hubs-Authz-Secret';

    private IRequest&MockObject $request;
    private AuthzService&MockObject $authzService;
    private IAppConfig&MockObject $appConfig;
    private LoggerInterface&MockObject $logger;
    private AuthzController $controller;

    /** Presenterade request-headers (namn => värde); tomt = ej presenterat. */
    private array $headers = [];
    /** Konfigurerat gateway-secret i app-config ('' = ej provisionerat). */
    private string $konfigureratSecret = '';

    protected function setUp(): void {
        parent::setUp();
        $this->request = $this->createMock(IRequest::class);
        $this->authzService = $this->createMock(AuthzService::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // getHeader läser ur den test-styrda header-kartan (getHeader ⇒ string, aldrig null).
        $this->request->method('getHeader')->willReturnCallback(
            fn (string $name): string => $this->headers[$name] ?? '',
        );
        // Endast gateway-secret-nyckeln svarar; övriga app-config-läsningar ger default.
        $this->appConfig->method('getAppValueString')->willReturnCallback(
            fn (string $key, string $default = ''): string
                => $key === AuthzController::CONFIG_KEY_GATEWAY_SECRET ? $this->konfigureratSecret : $default,
        );

        $this->controller = new AuthzController(
            request: $this->request,
            authzService: $this->authzService,
            appConfig: $this->appConfig,
            logger: $this->logger,
        );
    }

    // ================================================================== //
    //  Gateway-secret-grind — fail-closed (403, aldrig ett beslut)
    // ================================================================== //

    public function testOkonfigureratSecretGer403OchLoggarUtanBeslut(): void {
        // Ej provisionerat secret ⇒ ytan stängd helt, oavsett vad gw presenterar.
        $this->konfigureratSecret = '';
        $this->headers[self::HEADER] = self::SECRET;

        $this->authzService->expects($this->never())->method('check');
        $this->logger->expects($this->once())->method('warning');

        $res = $this->controller->check(self::UID, self::CASE, self::FN);

        self::assertSame(Http::STATUS_FORBIDDEN, $res->getStatus());
        self::assertSame([], $res->getData());
    }

    public function testSaknatSecretGer403(): void {
        $this->konfigureratSecret = self::SECRET;
        // Ingen header alls presenterad.
        $this->authzService->expects($this->never())->method('check');

        $res = $this->controller->check(self::UID, self::CASE, self::FN);

        self::assertSame(Http::STATUS_FORBIDDEN, $res->getStatus());
        self::assertSame([], $res->getData());
    }

    public function testFelaktigtSecretGer403(): void {
        $this->konfigureratSecret = self::SECRET;
        $this->headers[self::HEADER] = 'fel-nyckel';
        $this->authzService->expects($this->never())->method('check');

        $res = $this->controller->check(self::UID, self::CASE, self::FN);

        self::assertSame(Http::STATUS_FORBIDDEN, $res->getStatus());
        self::assertSame([], $res->getData());
    }

    // ================================================================== //
    //  Godkänt secret — beslutet släpps igenom (200)
    // ================================================================== //

    public function testRattSecretIHeaderGer200MedBeslut(): void {
        $this->konfigureratSecret = self::SECRET;
        $this->headers[self::HEADER] = self::SECRET;

        $beslut = ['allow' => true, 'roll' => 'handlaggare', 'skal' => 'medlem_handlaggare', 'skydd' => false];
        $this->authzService->expects($this->once())->method('check')
            ->with(self::UID, self::CASE, self::FN)
            ->willReturn($beslut);

        $res = $this->controller->check(self::UID, self::CASE, self::FN);

        self::assertSame(Http::STATUS_OK, $res->getStatus());
        self::assertSame($beslut, $res->getData());
    }

    public function testRattSecretViaAuthorizationBearerGer200(): void {
        // Sekundär presentationsväg: Authorization: Bearer <secret> (X-Hubs-Authz-Secret tom).
        $this->konfigureratSecret = self::SECRET;
        $this->headers['Authorization'] = 'Bearer ' . self::SECRET;

        $this->authzService->method('check')->willReturn(
            ['allow' => true, 'roll' => 'observator', 'skal' => 'medlem_observator', 'skydd' => false],
        );

        $res = $this->controller->check(self::UID, self::CASE, 'fn_lage');

        self::assertSame(Http::STATUS_OK, $res->getStatus());
        self::assertTrue($res->getData()['allow']);
    }

    // ================================================================== //
    //  Kropps-validering — saknat obligatoriskt fält ⇒ 400 (aldrig ett beslut)
    // ================================================================== //

    public function testTomtUidGer400(): void {
        $this->giltigtSecret();
        $this->authzService->expects($this->never())->method('check');

        $res = $this->controller->check('', self::CASE, self::FN);

        self::assertSame(Http::STATUS_BAD_REQUEST, $res->getStatus());
        self::assertSame([], $res->getData());
    }

    public function testTomtHubsCaseIdGer400(): void {
        $this->giltigtSecret();
        $this->authzService->expects($this->never())->method('check');

        $res = $this->controller->check(self::UID, '', self::FN);

        self::assertSame(Http::STATUS_BAD_REQUEST, $res->getStatus());
    }

    public function testTomFunktionGer400(): void {
        $this->giltigtSecret();
        $this->authzService->expects($this->never())->method('check');

        $res = $this->controller->check(self::UID, self::CASE, '');

        self::assertSame(Http::STATUS_BAD_REQUEST, $res->getStatus());
    }

    // ================================================================== //
    //  Node-kontraktet: deny är fortfarande 200 + exakt svarsform
    // ================================================================== //

    public function testDenyBeslutArAlltid200OchExaktNodeKontraktsform(): void {
        $this->giltigtSecret();

        // En R0-karantän-deny: allow=false men det är ett BESLUT ⇒ HTTP 200.
        $beslut = ['allow' => false, 'roll' => null, 'skal' => 'deny_r0_karantan', 'skydd' => true];
        $this->authzService->method('check')->willReturn($beslut);

        $res = $this->controller->check(self::UID, self::CASE, 'fn_lage');

        self::assertSame(Http::STATUS_OK, $res->getStatus());

        $data = $res->getData();
        // Exakt de fyra nycklarna gw läser (ocs.data.{allow,roll,skal,skydd}) — inget mer, inget mindre.
        self::assertSame(['allow', 'roll', 'skal', 'skydd'], array_keys($data));
        self::assertFalse($data['allow']);
        self::assertNull($data['roll']);
        self::assertSame('deny_r0_karantan', $data['skal']);
        self::assertTrue($data['skydd']);
    }

    // ================================================================== //
    //  $verktyg är audit-only — påverkar aldrig beslutet
    // ================================================================== //

    public function testVerktygVidarebefordrasAldrigTillBeslutet(): void {
        $this->giltigtSecret();

        // ->with(uid, case, funktion) bevisar att check() anropas med EXAKT tre argument;
        // $verktyg (fjärde controller-argumentet) når aldrig tjänsten.
        $this->authzService->expects($this->once())->method('check')
            ->with(self::UID, self::CASE, self::FN)
            ->willReturn(['allow' => true, 'roll' => 'handlaggare', 'skal' => 'medlem_handlaggare', 'skydd' => false]);

        $res = $this->controller->check(self::UID, self::CASE, self::FN, 'mcp_nagot_godtyckligt_verktyg');

        self::assertInstanceOf(DataResponse::class, $res);
        self::assertSame(Http::STATUS_OK, $res->getStatus());
    }

    // ------------------------------------------------------------------ //

    /** Provisionera + presentera ett korrekt gateway-secret (grinden öppen). */
    private function giltigtSecret(): void {
        $this->konfigureratSecret = self::SECRET;
        $this->headers[self::HEADER] = self::SECRET;
    }
}
