<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Tests\Unit\Service;

use OCA\HubsArende\Db\Arende;
use OCA\HubsArende\Db\ArendeMapper;
use OCA\HubsArende\Db\ArendeTyp;
use OCA\HubsArende\Service\ArendeService;
use OCA\HubsArende\Service\ArendeTypRegistry;
use OCA\HubsArende\Service\FacksystemCommitService;
use OCA\HubsArende\Service\SakerhetsskyddGrind;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * M1 — server-side pseudonym validation of objektRef/barnRef.
 *
 * CONTRACT (granskningsrapport M1, "Åtgärd"): buildEntity()/createCase() must run a
 * positive pseudonym validation on objektRef (and the barnRef fallback): require a
 * hash/UUID-shaped value and REJECT anything that looks like PII (a personnummer
 * `\d{6,8}[-+]?\d{4}`, a human name, whitespace, over-long input) with an
 * \InvalidArgumentException → 400. A clean pseudonym (UUID) must pass through.
 *
 * The R0 säkerhetsskydd-grind does NOT mitigate this (it never reads objektRef), so
 * the validation lives on the write path itself — exercised here via createCase().
 *
 * ── RECONCILIATION NOTES ──
 *   * Asserted as: createCase() with a personnummer-shaped objektRef throws
 *     \InvalidArgumentException, and with a UUID objektRef returns an Arende whose
 *     getObjektRef() is that UUID. The report names a helper `validateObjektRef`;
 *     if the implementer puts the check in SakerhetsskyddGrind::evaluate (R0) and
 *     surfaces it as an AvvisadException instead of \InvalidArgumentException, this
 *     test's expectException type must be reconciled — see
 *     {@see testPersonnummerObjektRefIsRejected}. The asserted PII case
 *     ('19850101-1234') and the asserted clean case (UUID) are the stable contract.
 *   * The exact accepted "pseudonym" format is assumed to be a UUID v4. If the
 *     build also accepts an opaque hash (e.g. 64-hex), the clean-path test stays
 *     green; only add cases, do not remove the UUID acceptance.
 *   * The barnRef fallback is asserted to be validated identically (same code path
 *     via `$rad['objektRef'] ?? $rad['barnRef']`).
 */
final class ArendeServiceObjektRefValideringTest extends TestCase {
    /** A clean pseudonym: UUID v4. */
    private const CLEAN_UUID = 'a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d';
    /** PII: a Swedish personnummer (YYYYMMDD-NNNN short form per the report). */
    private const PII_PERSONNUMMER = '19850101-1234';

    private ArendeMapper&MockObject $arendeMapper;
    private ArendeTypRegistry&MockObject $typRegistry;
    private SakerhetsskyddGrind&MockObject $grind;
    private FacksystemCommitService&MockObject $commitService;
    private ISecureRandom&MockObject $secureRandom;
    private ITimeFactory&MockObject $timeFactory;
    private LoggerInterface&MockObject $logger;

    private ArendeService $service;

    protected function setUp(): void {
        parent::setUp();

        $this->arendeMapper = $this->createMock(ArendeMapper::class);
        $this->typRegistry = $this->createMock(ArendeTypRegistry::class);
        $this->grind = $this->createMock(SakerhetsskyddGrind::class);
        $this->commitService = $this->createMock(FacksystemCommitService::class);
        $this->secureRandom = $this->createMock(ISecureRandom::class);
        $this->timeFactory = $this->createMock(ITimeFactory::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->timeFactory->method('getDateTime')
            ->willReturnCallback(static fn (): \DateTime => new \DateTime('2026-06-17T08:00:00+00:00'));
        $this->secureRandom->method('generate')
            ->willReturn("\x01\x23\x45\x67\x89\xab\xcd\xef\x01\x23\x45\x67\x89\xab\xcd\xef");

        // Clean grind result so we reach buildEntity (R2). The objektRef check is
        // the thing under test, not R0.
        $this->grind->method('evaluate')->willReturn([
            'avvisad' => false,
            'reason' => SakerhetsskyddGrind::REASON_OK,
            'retroaktiv' => false,
            'indikator' => SakerhetsskyddGrind::IND_NONE,
            'kvitto' => [],
        ]);
        $this->arendeMapper->method('findByConversationId')->willReturn(null);
        $this->arendeMapper->method('insert')->willReturnArgument(0);
        $this->arendeMapper->method('update')->willReturnArgument(0);

        $this->service = new ArendeService(
            $this->arendeMapper,
            $this->typRegistry,
            $this->grind,
            $this->commitService,
            $this->secureRandom,
            $this->timeFactory,
            $this->logger,
        );
    }

    // ================================================================== //
    //  PII (personnummer) in objektRef → InvalidArgumentException, NO insert.
    // ================================================================== //

    public function testPersonnummerObjektRefIsRejected(): void {
        $this->typRegistry->method('get')->willReturn($this->makeTyp());

        // A PII objektRef must NEVER be persisted.
        $this->arendeMapper->expects(self::never())->method('insert');

        // RECONCILE: if the check is hoisted into R0 the thrown type becomes
        // AvvisadException — swap the expected type then.
        $this->expectException(\InvalidArgumentException::class);

        $this->service->createCase([
            'conversationId' => 'conv-pii-1',
            'arendeTyp' => 'orosanmalan',
            'enhet' => 'barn-familj@',
            'objektRef' => self::PII_PERSONNUMMER,
        ]);
    }

    public function testPersonnummerInBarnRefFallbackIsRejected(): void {
        // The barnRef fallback feeds the same objektRef column → same validation.
        $this->typRegistry->method('get')->willReturn($this->makeTyp());
        $this->arendeMapper->expects(self::never())->method('insert');

        $this->expectException(\InvalidArgumentException::class);

        $this->service->createCase([
            'conversationId' => 'conv-pii-2',
            'arendeTyp' => 'orosanmalan',
            'enhet' => 'barn-familj@',
            'barnRef' => self::PII_PERSONNUMMER,
        ]);
    }

    public function testPlainNameObjektRefIsRejected(): void {
        // A human name is also PII and must be rejected by positive validation.
        $this->typRegistry->method('get')->willReturn($this->makeTyp());
        $this->arendeMapper->expects(self::never())->method('insert');

        $this->expectException(\InvalidArgumentException::class);

        $this->service->createCase([
            'conversationId' => 'conv-pii-3',
            'arendeTyp' => 'orosanmalan',
            'enhet' => 'barn-familj@',
            'objektRef' => 'Anna Andersson',
        ]);
    }

    // ================================================================== //
    //  Clean pseudonym (UUID) in objektRef → passes, persisted verbatim.
    // ================================================================== //

    public function testUuidObjektRefIsAccepted(): void {
        $this->typRegistry->method('get')->willReturn($this->makeTyp());

        $result = $this->service->createCase([
            'conversationId' => 'conv-clean-1',
            'arendeTyp' => 'orosanmalan',
            'enhet' => 'barn-familj@',
            'objektRef' => self::CLEAN_UUID,
        ]);

        self::assertInstanceOf(Arende::class, $result);
        self::assertSame(self::CLEAN_UUID, $result->getObjektRef());
    }

    public function testAbsentObjektRefIsAccepted(): void {
        // No objektRef at all is valid (the column is nullable) — the validation
        // must not reject a missing pseudonym.
        $this->typRegistry->method('get')->willReturn($this->makeTyp());

        $result = $this->service->createCase([
            'conversationId' => 'conv-clean-2',
            'arendeTyp' => 'orosanmalan',
            'enhet' => 'barn-familj@',
        ]);

        self::assertInstanceOf(Arende::class, $result);
        self::assertNull($result->getObjektRef());
    }

    // ================================================================== //
    //  Helpers
    // ================================================================== //

    private function makeTyp(): ArendeTyp {
        $typ = new ArendeTyp();
        $typ->setArendeTypId('orosanmalan');
        $typ->setDisplayName('orosanmalan');
        $typ->setCommitDestination('facksystem');
        $typ->setDefaultEnhet('barn-familj@');
        $typ->setFristPolicy(json_encode(['typ' => 'ingen']));
        return $typ;
    }
}
