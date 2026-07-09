<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Tests\Unit\Brain;

use OCA\HubsArende\Service\Brain\BrainProvisionService;
use OCA\HubsArende\Service\Brain\BrainProvisionUnavailable;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * BrainProvisionService — HTTP-klienten mot provisioner-API:t (kap 3.1/3.2/3.3).
 *
 * KÄRNKONTRAKT som verifieras (kap 3.3):
 *   - provision() kastar ENDAST vid RETRYBART fel (connect/timeout/5xx) och då exakt
 *     {@see BrainProvisionUnavailable}; permanent fel (409/422) RETURNERAS som
 *     {permanent_fel, kod} utan kast; ej konfigurerad ⇒ {noop:true} (graceful no-op).
 *   - Livscykelverben (freeze/thaw/patch/delete/rollback) är best-effort: de sväljer
 *     ALLT (även BrainProvisionUnavailable) och returnerar bool.
 *
 * Wire-kontraktet är verifierat mot den byggda Node-sidan
 * (openbrain-svc/src/provision/routes.js): Bearer-auth, POST-fältet heter `karantan`,
 * PATCH tar `r0_karantan`, DELETE tar `?reason=`.
 */
final class BrainProvisionServiceTest extends TestCase {
    private const CASE = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
    private const BASE = 'http://openbrain-svc:7106';
    private const SECRET = 'PROVISION_KEY_hemlig';

    private IClientService&MockObject $clientService;
    private IClient&MockObject $client;
    private IAppConfig&MockObject $appConfig;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void {
        parent::setUp();
        $this->clientService = $this->createMock(IClientService::class);
        $this->client = $this->createMock(IClient::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->clientService->method('newClient')->willReturn($this->client);
    }

    /** Bygg en tjänst med angiven konfig (tom = ej konfigurerad). */
    private function service(string $url = self::BASE, string $secret = self::SECRET, string $kommun = 'varberg'): BrainProvisionService {
        $this->appConfig->method('getAppValueString')->willReturnCallback(
            static function (string $key, string $default = '') use ($url, $secret, $kommun): string {
                return match ($key) {
                    BrainProvisionService::CONFIG_KEY_URL => $url,
                    BrainProvisionService::CONFIG_KEY_SECRET => $secret,
                    BrainProvisionService::CONFIG_KEY_KOMMUN => $kommun,
                    default => $default,
                };
            }
        );
        return new BrainProvisionService($this->clientService, $this->appConfig, $this->logger);
    }

    private function svar(int $status, array $body): IResponse&MockObject {
        $response = $this->createMock(IResponse::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn(json_encode($body));
        return $response;
    }

    // ── Graceful no-op ────────────────────────────────────────────────────────

    public function testProvisionNoOpUtanKonfig(): void {
        // Varken URL eller secret ⇒ INGET nätanrop, {noop:true}.
        $this->clientService->expects(self::never())->method('newClient');
        $service = $this->service('', '');

        self::assertSame(['noop' => true], $service->provision(self::CASE, 'orosanmalan'));
        self::assertFalse($service->isConfigured());
    }

    // ── provision(): lyckade vägar ────────────────────────────────────────────

    public function testProvisionSuccess201GerTenantId(): void {
        $this->client->expects(self::once())
            ->method('request')
            ->with('POST', self::BASE . '/provision/tenants', self::callback(function (array $opt): bool {
                // Bearer-auth + POST-fältet heter `karantan` (Node-kontrakt).
                self::assertSame('Bearer ' . self::SECRET, $opt['headers']['Authorization']);
                self::assertSame('orosanmalan', $opt['json']['arende_typ']);
                self::assertSame('varberg', $opt['json']['kommun']);
                self::assertArrayHasKey('karantan', $opt['json']);
                self::assertFalse($opt['json']['karantan']);
                self::assertSame(2, $opt['connect_timeout']);
                self::assertSame(10, $opt['timeout']);
                return true;
            }))
            ->willReturn($this->svar(201, ['tenant_id' => 't-1', 'schema' => 'arende_x', 'status' => 'aktiv', 'r0_karantan' => false]));

        $res = $this->service()->provision(self::CASE, 'orosanmalan');
        self::assertSame('t-1', $res['tenant_id']);
        self::assertSame('arende_x', $res['schema']);
        self::assertFalse($res['idempotent']);
    }

    public function testProvision200GerIdempotent(): void {
        $this->client->method('request')
            ->willReturn($this->svar(200, ['tenant_id' => 't-1', 'schema' => 'arende_x', 'status' => 'aktiv', 'idempotent' => true]));

        $res = $this->service()->provision(self::CASE, 'orosanmalan');
        self::assertSame('t-1', $res['tenant_id']);
        self::assertTrue($res['idempotent']);
    }

    // ── provision(): permanent fel (409/422) RETURNERAS, kastar ALDRIG ─────────

    public function testProvision409PermanentUtanKast(): void {
        $this->client->method('request')->willReturn($this->svar(409, ['error' => 'tenant_status_fryst']));

        $res = $this->service()->provision(self::CASE, 'orosanmalan');
        self::assertTrue($res['permanent_fel']);
        self::assertSame('tenant_status_fryst', $res['kod']);
    }

    public function testProvision422PermanentUtanKast(): void {
        $this->client->method('request')->willReturn($this->svar(422, ['error' => 'ogiltigt_hubs_case_id']));

        $res = $this->service()->provision(self::CASE, 'orosanmalan');
        self::assertTrue($res['permanent_fel']);
        self::assertSame('ogiltigt_hubs_case_id', $res['kod']);
    }

    // ── provision(): retrybart fel (5xx / connect) KASTAR BrainProvisionUnavailable

    public function testProvision5xxKastarUnavailable(): void {
        // 5xx returnerat direkt (http_errors av) ⇒ retrybart.
        $this->client->method('request')->willReturn($this->svar(503, ['error' => 'db_unavailable']));

        $this->expectException(BrainProvisionUnavailable::class);
        $this->service()->provision(self::CASE, 'orosanmalan');
    }

    public function testProvisionConnectFelKastarUnavailable(): void {
        // request() kastar OCH getResponseFromThrowable() saknar svar ⇒ transportfel ⇒ retrybart.
        $this->client->method('request')->willThrowException(new \RuntimeException('connect timeout'));
        $this->client->method('getResponseFromThrowable')->willThrowException(new \RuntimeException('inget svar'));

        $this->expectException(BrainProvisionUnavailable::class);
        $this->service()->provision(self::CASE, 'orosanmalan');
    }

    public function testProvisionHttpFelViaThrowableKlassificeras(): void {
        // request() kastar men throwablen BÄR ett 409-svar ⇒ permanent (getResponseFromThrowable).
        $this->client->method('request')->willThrowException(new \RuntimeException('4xx'));
        $this->client->method('getResponseFromThrowable')->willReturn($this->svar(409, ['error' => 'tenant_status_gallrad']));

        $res = $this->service()->provision(self::CASE, 'orosanmalan');
        self::assertTrue($res['permanent_fel']);
        self::assertSame('tenant_status_gallrad', $res['kod']);
    }

    // ── Livscykelverb: best-effort bool, aldrig kast ──────────────────────────

    public function testFreezeSuccess(): void {
        $this->client->expects(self::once())
            ->method('request')
            ->with('POST', self::BASE . '/provision/tenants/t-1/freeze', self::callback(function (array $opt): bool {
                self::assertSame(self::CASE, $opt['json']['hubs_case_id']);
                self::assertSame('avslut', $opt['json']['orsak']);
                return true;
            }))
            ->willReturn($this->svar(200, ['status' => 'fryst']));

        self::assertTrue($this->service()->freeze('t-1', self::CASE));
    }

    public function testFreezeSvaljerRetrybartFel(): void {
        // Även ett retrybart 5xx får INTE kasta ur ett livscykelverb (best-effort).
        $this->client->method('request')->willReturn($this->svar(503, ['error' => 'db_unavailable']));

        self::assertFalse($this->service()->freeze('t-1', self::CASE));
    }

    public function testRollbackAnroparDeleteMedSagaRollbackReason(): void {
        $this->client->expects(self::once())
            ->method('request')
            ->with('DELETE', self::BASE . '/provision/tenants/t-1?reason=saga_rollback', self::anything())
            ->willReturn($this->svar(200, ['status' => 'gallrad']));

        self::assertTrue($this->service()->rollback('t-1'));
    }

    public function testDelete410RaknasSomOk(): void {
        // Redan gallrad ⇒ 410 är OK för anroparen (idempotent teardown).
        $this->client->method('request')->willReturn($this->svar(410, ['error' => 'redan_gallrad']));

        self::assertTrue($this->service()->rollback('t-1'));
    }

    public function testSetKarantanPatcharR0Karantan(): void {
        $this->client->expects(self::once())
            ->method('request')
            ->with('PATCH', self::BASE . '/provision/tenants/t-1', self::callback(function (array $opt): bool {
                // PATCH-fältet heter r0_karantan (Node-kontrakt), inte karantan.
                self::assertTrue($opt['json']['r0_karantan']);
                self::assertArrayNotHasKey('karantan', $opt['json']);
                return true;
            }))
            ->willReturn($this->svar(200, ['r0_karantan' => true]));

        self::assertTrue($this->service()->setKarantan('t-1', true));
    }

    public function testPatchUtanTillatnaFaltAnroparInteNatet(): void {
        $this->client->expects(self::never())->method('request');
        self::assertFalse($this->service()->patch('t-1', ['skrap' => 'x']));
    }

    public function testLivscykelverbNoOpUtanKonfig(): void {
        $this->clientService->expects(self::never())->method('newClient');
        $service = $this->service('', '');

        self::assertFalse($service->freeze('t-1', self::CASE));
        self::assertFalse($service->thaw('t-1'));
        self::assertFalse($service->rollback('t-1'));
        self::assertFalse($service->setKarantan('t-1', true));
    }
}
