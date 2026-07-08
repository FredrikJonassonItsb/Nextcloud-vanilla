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
use OCA\HubsArende\Db\Bevakning;
use OCA\HubsArende\Db\BevakningMapper;
use OCA\HubsArende\Service\ArendeTypRegistry;
use OCA\HubsArende\Service\BevakningService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Villkorsmotorn — bevakningens kärnkontrakt: "en bevakning nollställs när det
 * bevakade uppnås". Se hubs_start/docs/KRAVSTALLNING-BEVAKNINGAR.md (A1–A10).
 *
 * Rena enhetstester: BevakningMapper/ArendeMapper/ArendeTypRegistry mockas,
 * entiteterna är riktiga. Deck/Pekare/Handelse lämnas null (best-effort,
 * gracefully hoppade). Assertionerna görs direkt på de muterade Bevakning-
 * objekten (markUppnadd/avslut muterar in place innan update()).
 */
final class BevakningServiceTest extends TestCase {
    private const CASE_ID = 'caseid-bev-00000001';

    private BevakningMapper&MockObject $bevakningMapper;
    private ArendeMapper&MockObject $arendeMapper;
    private ArendeTypRegistry&MockObject $typRegistry;
    private ITimeFactory&MockObject $timeFactory;
    private LoggerInterface&MockObject $logger;

    private BevakningService $service;
    private \DateTime $nu;
    /** @var Bevakning[] Rader som insert() "sparat" (för recurring/skapande-assertions). */
    private array $insatta = [];

    protected function setUp(): void {
        parent::setUp();
        $this->nu = new \DateTime('2026-07-08 09:00:00');

        $this->bevakningMapper = $this->createMock(BevakningMapper::class);
        $this->arendeMapper = $this->createMock(ArendeMapper::class);
        $this->typRegistry = $this->createMock(ArendeTypRegistry::class);
        $this->timeFactory = $this->createMock(ITimeFactory::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->timeFactory->method('getDateTime')->willReturnCallback(fn (): \DateTime => clone $this->nu);

        // insert() tilldelar id och lagrar för assertions.
        $nextId = 1000;
        $this->bevakningMapper->method('insert')->willReturnCallback(function (Bevakning $b) use (&$nextId): Bevakning {
            if ($b->getId() === null) {
                $b->setId($nextId++);
            }
            $this->insatta[] = $b;
            return $b;
        });
        $this->bevakningMapper->method('update')->willReturnArgument(0);
        // Registret finns; frist-projektionen läser + skriver det.
        $this->arendeMapper->method('findByCaseId')->willReturnCallback(fn (): Arende => $this->makeArende('utredning'));
        $this->arendeMapper->method('update')->willReturnArgument(0);

        $this->service = new BevakningService(
            $this->bevakningMapper,
            $this->arendeMapper,
            $this->typRegistry,
            $this->timeFactory,
            $this->logger,
        );
    }

    // ================================================================== //
    //  A1/A2 — steg_uppnatt släcker RÄTT bevakning (nollställningen).
    // ================================================================== //

    public function testStegUppnattSlackerMalbevakningMenLamnarOvriga(): void {
        // 14d-förhandsbedömningen pekar på 'utredning'; 4mån-utredningen på 'beslut'.
        $forhand = $this->makeBevakning(Bevakning::VILLKOR_STEG_UPPNATT, 'utredning', lagstadgad: true, frist: '2026-07-20');
        $utredning = $this->makeBevakning(Bevakning::VILLKOR_STEG_UPPNATT, 'beslut', lagstadgad: true, frist: '2026-11-01');
        $this->stubbaAktiva([$forhand, $utredning]);

        $this->service->utvardera(self::CASE_ID, 'steg', ['nyttSteg' => 'utredning']);

        self::assertSame(Bevakning::STATUS_UPPNADD, $forhand->getStatus(), '14d-målet släcks när utredning inleds');
        self::assertSame(Bevakning::STATUS_AKTIV, $utredning->getStatus(), '4mån-utredningen (mål beslut) är orörd');
        self::assertFalse($forhand->getForsenad(), 'frist i framtiden ⇒ ej försenad');
    }

    // ================================================================== //
    //  Komplettering kopplad → komplettering_kopplad-bevakning uppnås.
    // ================================================================== //

    public function testKompletteringKoppladSlackerBevakning(): void {
        $b = $this->makeBevakning(Bevakning::VILLKOR_KOMPLETTERING_KOPPLAD, null, frist: '2026-07-30');
        $this->stubbaAktiva([$b]);

        $this->service->utvardera(self::CASE_ID, 'komplettering', ['antal' => 1]);

        self::assertSame(Bevakning::STATUS_UPPNADD, $b->getStatus());
    }

    public function testStegHandelseRorInteKompletteringsbevakning(): void {
        $b = $this->makeBevakning(Bevakning::VILLKOR_KOMPLETTERING_KOPPLAD, null);
        $this->stubbaAktiva([$b]);

        $this->service->utvardera(self::CASE_ID, 'steg', ['nyttSteg' => 'utredning']);

        self::assertSame(Bevakning::STATUS_AKTIV, $b->getStatus(), 'fel händelsetyp ⇒ ingen träff');
    }

    // ================================================================== //
    //  passerad = LARMLÄGE: sen träff ⇒ uppnadd + forsenad (K-BEV-3.4).
    // ================================================================== //

    public function testSenTraffMarkerasForsenad(): void {
        $b = $this->makeBevakning(Bevakning::VILLKOR_STEG_UPPNATT, 'utredning', frist: '2026-07-01'); // i det förflutna
        $this->stubbaAktiva([$b]);

        $this->service->utvardera(self::CASE_ID, 'steg', ['nyttSteg' => 'utredning']);

        self::assertSame(Bevakning::STATUS_UPPNADD, $b->getStatus());
        self::assertTrue($b->getForsenad(), 'villkoret uppnått EFTER passerad frist ⇒ försenad');
    }

    // ================================================================== //
    //  Avslut ⇒ alla aktiva bevakningar avbryts.
    // ================================================================== //

    public function testAvslutAvbryterAllaAktiva(): void {
        $a = $this->makeBevakning(Bevakning::VILLKOR_STEG_UPPNATT, 'beslut', frist: '2026-11-01');
        $b = $this->makeBevakning(Bevakning::VILLKOR_MANUELL_KVITTERING, 'utredning', frist: null);
        $this->stubbaAktiva([$a, $b]);

        $this->service->utvardera(self::CASE_ID, 'avslut');

        self::assertSame(Bevakning::STATUS_AVBRUTEN, $a->getStatus());
        self::assertSame(Bevakning::STATUS_AVBRUTEN, $b->getStatus());
    }

    // ================================================================== //
    //  GAP-044 ägarskifte: commit ⇒ commit-villkor uppnås, lagstadgade
    //  speglingar avbryts, interna behålls.
    // ================================================================== //

    public function testAgarskifteUppnarCommitAvbryterLagstadgadeBevararInterna(): void {
        $commit = $this->makeBevakning(Bevakning::VILLKOR_COMMIT_REGISTRERAD, null, lagstadgad: true, frist: '2026-08-01');
        $lagfrist = $this->makeBevakning(Bevakning::VILLKOR_STEG_UPPNATT, 'beslut', lagstadgad: true, frist: '2026-11-01');
        $intern = $this->makeBevakning(Bevakning::VILLKOR_MANUELL_KVITTERING, 'utredning', lagstadgad: false, frist: null);
        // bevakasIFacksystem itererar findAktivaByCaseId TVÅ gånger (commit-utvärdering
        // + lagstadgad-avbrytning) — returnera de kvarvarande aktiva vid varje anrop.
        $this->bevakningMapper->method('findAktivaByCaseId')->willReturnOnConsecutiveCalls(
            [$commit, $lagfrist, $intern],   // utvardera('commit')
            [$lagfrist, $intern],            // avbryt lagstadgade (commit nu uppnadd)
        );
        $this->bevakningMapper->method('findByCaseId')->willReturn([$commit, $lagfrist, $intern]);

        $this->service->bevakasIFacksystem(self::CASE_ID, 'DNR-2026-42');

        self::assertSame(Bevakning::STATUS_UPPNADD, $commit->getStatus(), 'commit-villkoret uppnås');
        self::assertSame(Bevakning::STATUS_AVBRUTEN, $lagfrist->getStatus(), 'lagstadgad spegling avbryts (facksystemet äger)');
        self::assertSame(Bevakning::STATUS_AKTIV, $intern->getStatus(), 'intern/manuell behålls');
    }

    // ================================================================== //
    //  Delgivningsdatum ⇒ överklagandebevakning (3 v → laga kraft).
    // ================================================================== //

    public function testSetDelgivningsdatumFoderOverklagandebevakning(): void {
        $this->bevakningMapper->method('findAktivaByCaseId')->willReturn([]); // ingen befintlig
        $this->bevakningMapper->method('findByCaseId')->willReturn([]);

        $b = $this->service->setDelgivningsdatum(self::CASE_ID, '2026-07-08', 'handlaggare1');

        self::assertSame('overklagande', $b->getTyp());
        self::assertSame(Bevakning::VILLKOR_DATUM_PASSERAT, $b->getVillkorTyp());
        self::assertSame(Bevakning::ANKARE_DELGIVNING, $b->getAnkare());
        self::assertTrue($b->getLagstadgad());
        self::assertSame('2026-07-29', $b->getFristDue()?->format('Y-m-d'), '3 veckor efter delgivning');
    }

    public function testSetDelgivningsdatumAvvisarOgiltigtDatum(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->setDelgivningsdatum(self::CASE_ID, 'inte-ett-datum', 'handlaggare1');
    }

    // ================================================================== //
    //  Kvittering av manuell bevakning + recurring föder ny cykel.
    // ================================================================== //

    public function testKvitteraRecurringFoderNyCykel(): void {
        $b = $this->makeBevakning(Bevakning::VILLKOR_MANUELL_KVITTERING, 'uppfoljning', frist: '2026-07-08', recurring: 180);
        $b->setId(500);
        $this->bevakningMapper->method('findById')->with(500)->willReturn($b);
        $this->bevakningMapper->method('findByCaseId')->willReturn([$b]);

        $this->service->kvittera(self::CASE_ID, 500, 'handlaggare1');

        self::assertSame(Bevakning::STATUS_UPPNADD, $b->getStatus(), 'kvitterad ⇒ uppnadd');
        // Recurring: exakt en NY aktiv bevakning föddes med cykel-frist (nu + 180 d).
        $nya = array_filter($this->insatta, fn (Bevakning $x): bool => $x->getStatus() === Bevakning::STATUS_AKTIV);
        self::assertCount(1, $nya, 'recurring föder en ny aktiv post');
        $ny = array_values($nya)[0];
        self::assertSame(Bevakning::ANKARE_CYKEL, $ny->getAnkare());
        self::assertSame('2027-01-04', $ny->getFristDue()?->format('Y-m-d'), 'nu (2026-07-08) + 180 dagar');
    }

    public function testKvitteraFelCaseKastar(): void {
        $b = $this->makeBevakning(Bevakning::VILLKOR_MANUELL_KVITTERING, 'utredning');
        $b->setId(501);
        $b->setHubsCaseId('ett-annat-arende');
        $this->bevakningMapper->method('findById')->with(501)->willReturn($b);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->kvittera(self::CASE_ID, 501, 'handlaggare1');
    }

    // ================================================================== //
    //  fristDue-projektionen = tidigaste aktiva bevaknings frist.
    // ================================================================== //

    public function testProjicieraFristSatterTidigasteAktiva(): void {
        $tidig = $this->makeBevakning(Bevakning::VILLKOR_STEG_UPPNATT, 'utredning', frist: '2026-07-20');
        $sen = $this->makeBevakning(Bevakning::VILLKOR_STEG_UPPNATT, 'beslut', frist: '2026-11-01');
        $uppnadd = $this->makeBevakning(Bevakning::VILLKOR_MANUELL_KVITTERING, null, frist: '2026-07-10');
        $uppnadd->setStatus(Bevakning::STATUS_UPPNADD); // uppnådda räknas inte
        $this->bevakningMapper->method('findByCaseId')->willReturn([$tidig, $sen, $uppnadd]);

        $arende = $this->makeArende('utredning');
        $this->arendeMapper = $this->createMock(ArendeMapper::class);
        $this->arendeMapper->method('findByCaseId')->willReturn($arende);
        $sparad = null;
        $this->arendeMapper->method('update')->willReturnCallback(function (Arende $a) use (&$sparad): Arende {
            $sparad = $a;
            return $a;
        });
        $svc = new BevakningService(
            $this->bevakningMapper, $this->arendeMapper, $this->typRegistry, $this->timeFactory, $this->logger,
        );

        $svc->projicieraFrist(self::CASE_ID);

        self::assertNotNull($sparad);
        self::assertSame('2026-07-20', $sparad->getFristDue()?->format('Y-m-d'), 'min av AKTIVA fristar');
    }

    // ================================================================== //
    //  skapaStandardForFodelse instansierar typens fodelse-mallar.
    // ================================================================== //

    public function testSkapaStandardForFodelseInstansierarFodelseMallar(): void {
        $typ = new ArendeTyp();
        $typ->setArendeTypId('orosanmalan');
        $typ->setDisplayName('Orosanmälan');
        $typ->setCommitDestination('facksystem');
        $typ->setBevakningsmallar(json_encode([
            ['typ' => 'forhandsbedomning_14d', 'titel' => 'Förhandsbedömning', 'villkorTyp' => 'steg_uppnatt',
             'villkorArg' => 'utredning', 'ankare' => 'inkom_datum', 'ankareDagar' => 14, 'lagstadgad' => true, 'vidSteg' => 'fodelse'],
            ['typ' => 'utredning_4man', 'titel' => 'Utredning', 'villkorTyp' => 'steg_uppnatt',
             'villkorArg' => 'beslut', 'ankare' => 'steg_datum', 'ankareDagar' => 120, 'lagstadgad' => true, 'vidSteg' => 'utredning'],
        ]));
        $this->bevakningMapper->method('findByCaseId')->willReturn([]);

        $this->service->skapaStandardForFodelse(self::CASE_ID, $typ, ['inkomDatum' => '2026-07-08']);

        // ENDAST fodelse-mallen instansieras (inte utrednings-mallen).
        self::assertCount(1, $this->insatta, 'endast vidSteg=fodelse instansieras vid födelse');
        $b = $this->insatta[0];
        self::assertSame('forhandsbedomning_14d', $b->getTyp());
        self::assertSame(Bevakning::VILLKOR_STEG_UPPNATT, $b->getVillkorTyp());
        self::assertSame('utredning', $b->getVillkorArg());
        self::assertTrue($b->getLagstadgad());
        self::assertSame('2026-07-22', $b->getFristDue()?->format('Y-m-d'), 'inkom (2026-07-08) + 14 dagar');
    }

    // ================================================================== //
    //  Helpers
    // ================================================================== //

    /** @param Bevakning[] $aktiva */
    private function stubbaAktiva(array $aktiva): void {
        $this->bevakningMapper->method('findAktivaByCaseId')->willReturn($aktiva);
        $this->bevakningMapper->method('findByCaseId')->willReturn($aktiva);
    }

    private function makeBevakning(
        string $villkorTyp,
        ?string $villkorArg,
        bool $lagstadgad = false,
        ?string $frist = null,
        ?int $recurring = null,
    ): Bevakning {
        $b = new Bevakning();
        $b->setHubsCaseId(self::CASE_ID);
        $b->setTyp('test');
        $b->setTitel('Testbevakning');
        $b->setVillkorTyp($villkorTyp);
        $b->setVillkorArg($villkorArg);
        $b->setStatus(Bevakning::STATUS_AKTIV);
        $b->setFristDue($frist !== null ? new \DateTime($frist) : null);
        $b->setAnkare(Bevakning::ANKARE_STEG);
        $b->setRecurringDagar($recurring);
        $b->setLagstadgad($lagstadgad);
        $b->setSkapadAv('');
        $b->setForsenad(false);
        $b->setSkapad(clone $this->nu);
        return $b;
    }

    private function makeArende(string $steg): Arende {
        $a = new Arende();
        $a->setHubsCaseId(self::CASE_ID);
        $a->setEnhet('barn-familj@');
        $a->setArendeTyp('orosanmalan');
        $a->setCommitDestination('facksystem');
        $a->setStatus('tilldelat');
        $a->setSteg($steg);
        return $a;
    }
}
