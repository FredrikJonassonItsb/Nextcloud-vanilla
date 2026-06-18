<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Tests\Unit\Service;

use OCA\HubsArende\Db\ArendeMapper;
use OCA\HubsArende\Service\ArendeMatchService;
use OCP\AppFramework\Services\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * M5 — anonymity gate (TF 2:18) must be fail-CLOSED: the SSN/orgId match step runs
 * ONLY on a positive allow-signal, never by default.
 *
 * CONTRACT (granskningsrapport M5, "Åtgärd" + explicit unit-test line "$rad med
 * fromSsn+funktionsadress men utan flaggor → matchaPart()=null"): an anonymous
 * sender carrying fromSsn + a funktionsadress but WITHOUT any explicit allow-signal
 * (no partsModell in an ALLOW-list, no joinNyckel ∈ {ssn,personnummer}) must NOT
 * have the person-identity match step run. The cascade must fall through to
 * ingen-träff (no kandidatRef, no auto-coupling).
 *
 * Driven through the public match() (matchaPart() is private). The observable
 * contract is: the result carries NEITHER a 'hubsCaseId' NOR a 'kandidatRef' (the
 * part step was skipped / produced no candidate), and the outcome is the neutral
 * ej_kopplat/nytt default.
 *
 * ── RECONCILIATION NOTES ──
 *   * TODAY this passes for two reasons at once: (a) the gate is fail-open but (b)
 *     registerPartHook() returns null so no candidate is produced anyway. After the
 *     M5 fix the step must be SKIPPED for a missing allow-signal even once the hook
 *     is wired. To make the assertion robust to the hook still being null, the test
 *     asserts the OBSERVABLE no-candidate outcome (no kandidatRef / no hubsCaseId).
 *     If the build exposes the skip more directly (e.g. a 'varfor' of
 *     VARFOR_INGEN_LOST / VARFOR_INGEN_NY rather than VARFOR_PART), the varfor
 *     assertion below pins that; reconcile the constant if renamed.
 *   * Positive control: WITH an explicit allow-signal (partsModell on an ALLOW-list
 *     AND joinNyckel='ssn') the part step is permitted to run. Because
 *     registerPartHook() is still a null stub, the outcome is STILL no candidate —
 *     so we cannot assert a candidate appears. Instead {@see testAllowSignalDoesNot
 *     SkipOnPartsModell} documents the allow-path shape and is tolerant of the
 *     null hook. RECONCILE: once the hook is wired, tighten to assert kandidatRef.
 *   * The ALLOW-list partsModell value is assumed to be 'fysisk_person' (a natural
 *     person whose SSN IS a partsuppgift). If the build names the allow value
 *     differently, adjust {@see ALLOW_PARTSMODELL}.
 */
final class ArendeMatchServiceM5Test extends TestCase {
    /** Assumed ALLOW-list partsModell (natural person, SSN is a partsuppgift). */
    private const ALLOW_PARTSMODELL = 'fysisk_person';

    private ArendeMapper&MockObject $arendeMapper;
    private IAppConfig&MockObject $appConfig;
    private LoggerInterface&MockObject $logger;

    private ArendeMatchService $service;

    protected function setUp(): void {
        parent::setUp();

        $this->arendeMapper = $this->createMock(ArendeMapper::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Default server-side threshold (0.9) — keeps the part step's ceiling below
        // it so it could at most yield 'foreslagen', never silent auto.
        $this->appConfig->method('getAppValueFloat')
            ->willReturnCallback(
                static fn (string $key, float $default = 0.0): float => $default,
            );

        // No conversationId / case-tag / dnr match — force the cascade down to the
        // part step so M5 is the deciding gate.
        $this->arendeMapper->method('findByConversationId')->willReturn(null);
        $this->arendeMapper->method('findByCaseId')
            ->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('none'));
        $this->arendeMapper->method('findByDnr')->willReturn(null);

        $this->service = new ArendeMatchService(
            $this->arendeMapper,
            $this->appConfig,
            $this->logger,
        );
    }

    // ================================================================== //
    //  fromSsn + funktionsadress, NO allow-signal → part step skipped.
    //  (The explicit unit-test the report's M5 "Åtgärd" line asks for.)
    // ================================================================== //

    public function testAnonymousSsnWithoutAllowSignalSkipsPartStep(): void {
        $result = $this->service->match([
            'conversationId' => 'conv-m5-anon',
            'fromSsn' => '19850101-1234',
            'to' => 'orosanmalan@kommun.se', // funktionsadress
            // NB: no partsModell, no joinNyckel, no anonym flag → no allow-signal.
        ]);

        // The person-identity match step must NOT have produced a candidate.
        self::assertArrayNotHasKey(
            'kandidatRef',
            $result,
            'Utan allow-signal får partssteget inte producera en kandidat (TF 2:18).',
        );
        self::assertArrayNotHasKey(
            'hubsCaseId',
            $result,
            'Ingen tyst auto-koppling på personidentitet utan allow-signal.',
        );

        // The cascade falls through to the neutral ingen-träff default.
        self::assertContains(
            $result['arendekoppling'],
            [ArendeMatchService::KOPPLING_NYTT, ArendeMatchService::KOPPLING_EJ_KOPPLAT],
        );
        // RECONCILE: if the build still routes through the part step but returns
        // null, the varfor stays one of the ingen-koder; pin that here.
        self::assertContains(
            $result['varfor'],
            [ArendeMatchService::VARFOR_INGEN_NY, ArendeMatchService::VARFOR_INGEN_LOST],
        );
        self::assertSame(ArendeMatchService::STATUS_INGEN, $result['status']);
        self::assertSame(ArendeMatchService::KONF_INGEN, $result['konfidens']);
    }

    public function testAnonymousOrgIdWithoutAllowSignalSkipsPartStep(): void {
        // Same fail-closed behaviour for an orgId-bearing anonymous inflow.
        $result = $this->service->match([
            'conversationId' => 'conv-m5-anon-org',
            'fromOrgId' => '556677-8899',
            'funktionsadress' => 'bygglov@kommun.se',
        ]);

        self::assertArrayNotHasKey('kandidatRef', $result);
        self::assertArrayNotHasKey('hubsCaseId', $result);
        self::assertSame(ArendeMatchService::STATUS_INGEN, $result['status']);
    }

    public function testExplicitAnonymFlagSkipsPartStep(): void {
        // An explicit anonymitetsskydd flag must keep the step off even if a future
        // build were to (wrongly) default it on.
        $result = $this->service->match([
            'conversationId' => 'conv-m5-flag',
            'fromSsn' => '19850101-1234',
            'to' => 'orosanmalan@kommun.se',
            'anonymitetsskydd' => true,
        ]);

        self::assertArrayNotHasKey('kandidatRef', $result);
        self::assertArrayNotHasKey('hubsCaseId', $result);
    }

    // ================================================================== //
    //  Positive control — an explicit allow-signal permits the part step.
    //  (Tolerant of the still-null registerPartHook: asserts no CRASH and a
    //   well-formed result; tighten to assert kandidatRef once the hook is wired.)
    // ================================================================== //

    public function testAllowSignalDoesNotSkipOnPartsModell(): void {
        $result = $this->service->match([
            'conversationId' => 'conv-m5-allow',
            'fromSsn' => '19850101-1234',
            'to' => 'orosanmalan@kommun.se',
            'partsModell' => self::ALLOW_PARTSMODELL,
            'joinNyckel' => 'ssn',
        ]);

        // With the hook still a null stub the outcome is no candidate either way;
        // the load-bearing assertion is that the allow-path is well-formed and the
        // confidence never silently exceeds the server-side threshold.
        self::assertArrayHasKey('arendekoppling', $result);
        self::assertLessThan(
            $result['troskel'] + 0.0000001,
            // KONF_PART_TAK (0.7) < DEFAULT_TROSKEL (0.9): part step can never auto.
            ArendeMatchService::KONF_PART_TAK,
        );
    }
}
