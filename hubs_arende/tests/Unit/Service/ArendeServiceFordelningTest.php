<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Tests\Unit\Service;

use OCA\HubsArende\Db\Arende;
use OCA\HubsArende\Db\ArendeMapper;
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
 * Regressionstester för fördelningsvyns läsyta ({@see ArendeService::fordelningSummary()}).
 *
 * B13-klassen: urvalet var tidigare bara otilldelade rader (findOtilldelade) —
 * när alla ärenden är tilldelade var vyn tom trots ett fullt register, och
 * omfördelning saknade underlag. Summaryn ska bära BÅDA zonerna ur EN
 * findAll-läsning, och numeriska uid:n (personnummer-konton) får aldrig läcka
 * som JSON-nummer via PHP:s array-key-koercion.
 */
final class ArendeServiceFordelningTest extends TestCase {
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

        // Positionell harness (trailing collaborators null) ⇒ system-kontext:
        // enhetTillaten släpper igenom, kort-mapparna degraderar graceful.
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
    //  B13 — tilldelade ärenden syns som omfördelningsbara kort
    // ================================================================== //

    public function testFordelningSummaryCarriesTilldeladeAlongsideAttFordela(): void {
        // Registret: 1 otilldelat + 2 tilldelade + 1 tilldelat men avslutat.
        // Före fixen gav detta attFordela=1 och INGENTING om de tilldelade —
        // exakt det tomma Fördela-läget B13 rapporterade.
        $this->arendeMapper->method('findAll')->willReturn([
            $this->makeArende('case-otill-1', 'otilldelat', 'forhandsbedomning', null),
            $this->makeArende('case-till-1', 'tilldelat', 'utredning', '197411040293'),
            $this->makeArende('case-till-2', 'tilldelat', 'beslut', 'anna.ignell'),
            $this->makeArende('case-avslutat-1', 'tilldelat', 'avslutat', 'anna.ignell'),
        ]);
        // Hela vyn ska bäras av EN findAll-läsning — det gamla separata urvalet
        // får inte längre anropas.
        $this->arendeMapper->expects(self::never())->method('findOtilldelade');

        $result = $this->service->fordelningSummary();

        self::assertCount(1, $result['attFordela']);
        self::assertSame('case-otill-1', $result['attFordela'][0]['hubsCaseId']);
        self::assertSame('otilldelat', $result['attFordela'][0]['tilldelning']['status']);

        // Avslutade ärenden är inte omfördelningsbara och ska INTE listas.
        self::assertCount(2, $result['tilldelade']);
        $ids = array_column($result['tilldelade'], 'hubsCaseId');
        self::assertSame(['case-till-1', 'case-till-2'], $ids);
        foreach ($result['tilldelade'] as $kort) {
            self::assertSame('tilldelat', $kort['tilldelning']['status']);
            self::assertIsString($kort['tilldelning']['agareUid']);
        }
        self::assertSame('197411040293', $result['tilldelade'][0]['tilldelning']['agareUid']);

        // Mottagningskön: det otilldelade ärendet står i förhandsbedömning.
        self::assertSame(1, $result['mottagningPagaende']);
    }

    // ================================================================== //
    //  uid-cast — numeriska uid:n får aldrig serialiseras som JSON-nummer
    // ================================================================== //

    public function testFordelningSummarySerialisesNumericUidsAsStrings(): void {
        $this->arendeMapper->method('findAll')->willReturn([
            $this->makeArende('case-till-1', 'tilldelat', 'utredning', '197411040293'),
        ]);

        $result = $this->service->fordelningSummary();

        // PHP koercerar numeriska strängnycklar till int — utan (string)-cast
        // blev utredare-posten '"namn":197411040293' (JSON-nummer) och
        // frontendens strikta strängjämförelser felade.
        self::assertCount(1, $result['utredare']);
        self::assertIsString($result['utredare'][0]['namn']);
        self::assertSame('197411040293', $result['utredare'][0]['namn']);
        self::assertStringContainsString(
            '"namn":"197411040293"',
            json_encode($result['utredare'], JSON_THROW_ON_ERROR),
        );
    }

    // ================================================================== //
    //  Helpers
    // ================================================================== //

    private function makeArende(string $hubsCaseId, string $status, string $steg, ?string $agareUid): Arende {
        $arende = new Arende();
        $arende->setHubsCaseId($hubsCaseId);
        $arende->setStatus($status);
        $arende->setSteg($steg);
        $arende->setAgareUid($agareUid);
        $arende->setEnhet('barn-familj@');
        $arende->setArendeTyp('orosanmalan');
        $arende->setSkapad(new \DateTime('2026-06-10T08:00:00+00:00'));
        return $arende;
    }
}
