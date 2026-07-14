<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Tests\Unit\Service;

use OCA\HubsArende\Db\AiUtkast;
use OCA\HubsArende\Db\AiUtkastMapper;
use OCA\HubsArende\Db\Arende;
use OCA\HubsArende\Db\Handelse;
use OCA\HubsArende\Db\HandelseMapper;
use OCA\HubsArende\Service\ArendeService;
use OCA\HubsArende\Service\Brain\AiUtkastKonfliktException;
use OCA\HubsArende\Service\Brain\AiUtkastService;
use OCA\HubsArende\Service\Brain\HandelseTypAi;
use OCA\HubsArende\Service\HandlingService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * AiUtkastService — HITL-backend för brain-per-ärende (SPEC 8.0.7).
 *
 * Rena enhetstester: mappers/HandlingService/ArendeService mockas, AiUtkast/Handelse
 * är riktiga entiteter. Verifierar de bindande facit:
 *  - utkast blir handling FÖRST vid godkännande (avvisa/utfallsspärr ⇒ generera() ANROPAS ALDRIG),
 *  - `innehall` nollas vid både godkännande och avvisning (raderingsfönstret),
 *  - fn_draft_beslutsformulering: serverside utfall_eko-dubbelkoll + spärrlexikon,
 *  - H1: utkast i annat ärende ⇒ DoesNotExistException (existens läcker aldrig).
 */
final class AiUtkastServiceTest extends TestCase {
    private const CASE_ID = 'caseid-aiu-00000001';
    private const REF = 'DNR-2026-42';

    private ArendeService&MockObject $arendeService;
    private AiUtkastMapper&MockObject $mapper;
    private HandlingService&MockObject $handlingService;
    private HandelseMapper&MockObject $handelseMapper;
    private LoggerInterface&MockObject $logger;
    private AiUtkastService $service;

    /** @var array{typ:?string, detalj:?array<string,mixed>}[] Fångade TYP_AI-poster. */
    private array $journal = [];

    protected function setUp(): void {
        parent::setUp();
        $this->arendeService = $this->createMock(ArendeService::class);
        $this->mapper = $this->createMock(AiUtkastMapper::class);
        $this->handlingService = $this->createMock(HandlingService::class);
        $this->handelseMapper = $this->createMock(HandelseMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new AiUtkastService(
            arendeService: $this->arendeService,
            mapper: $this->mapper,
            handlingService: $this->handlingService,
            logger: $this->logger,
            handelseMapper: $this->handelseMapper,
            userSession: null,
        );

        // show() släpper alltid igenom i basfallet (authz testas hos ArendeService::show).
        $arende = new Arende();
        $arende->setHubsCaseId(self::CASE_ID);
        $this->arendeService->method('show')->with(self::REF)->willReturn($arende);

        // Fånga TYP_AI-journalen (best-effort-skrivning).
        $this->handelseMapper->method('record')->willReturnCallback(
            function (string $cid, string $typ, array $detalj = [], string $aktor = ''): Handelse {
                $this->journal[] = ['typ' => $typ, 'detalj' => $detalj];
                return new Handelse();
            }
        );
    }

    // ================================================================== //
    //  skapa
    // ================================================================== //

    public function testSkapaInsatterUtkastOchJournalforSkapat(): void {
        $this->mapper->expects($this->once())->method('insert')
            ->willReturnCallback(static function (AiUtkast $u): AiUtkast {
                $u->setId(7);
                return $u;
            });

        $utkast = $this->service->skapa(
            self::CASE_ID,
            'fn_draft_journal',
            ['falt' => ['sammanfattning' => 'x']],
            ['h-1', 'h-2'],
            ['runId' => 'run-abc', 'mallId' => 'journalanteckning', 'modellversion' => 'm-1.0'],
        );

        self::assertSame(AiUtkast::STATUS_UTKAST, $utkast->getStatus());
        self::assertSame('run-abc', $utkast->getRunId());
        self::assertNotNull($utkast->getInnehall());
        self::assertSame(HandelseTypAi::UTKAST_SKAPAT, $this->journal[0]['detalj']['handling']);
    }

    // ================================================================== //
    //  godkann — handling FÖRE nollning; innehall nullas; TYP_AI godkant
    // ================================================================== //

    public function testGodkannSkaparHandlingNollarInnehallOchJournalfor(): void {
        $utkast = $this->utkast(11, 'fn_draft_journal', 'journalanteckning', ['falt' => ['namn' => 'x']]);
        $this->mapper->method('findById')->with(11)->willReturn($utkast);

        $this->handlingService->expects($this->once())->method('generera')
            ->with(self::REF, 'journalanteckning', ['namn' => 'x'])
            ->willReturn(['ok' => true, 'filnamn' => 'journal-x.docx', 'antalErsatta' => 1, 'ersatta' => []]);

        $this->mapper->expects($this->once())->method('update')->with($utkast);

        $res = $this->service->godkann(self::REF, 11);

        self::assertTrue($res['ok']);
        self::assertSame(AiUtkast::STATUS_GODKANT, $utkast->getStatus());
        self::assertNull($utkast->getInnehall(), 'raderingsfönster: innehall nollas vid godkännande');
        self::assertNotNull($utkast->getAvgjord());
        self::assertSame(HandelseTypAi::UTKAST_GODKANT, $this->journal[0]['detalj']['handling']);
        self::assertSame('fn_draft_journal', $this->journal[0]['detalj']['funktion']);
    }

    public function testGodkannRedigeratSaatterDiffPct(): void {
        $utkast = $this->utkast(12, 'fn_draft_journal', 'journalanteckning', ['text' => 'ursprunglig lydelse om mötet']);
        $this->mapper->method('findById')->with(12)->willReturn($utkast);
        $this->handlingService->method('generera')->willReturn(['ok' => true, 'filnamn' => 'j.docx', 'antalErsatta' => 0, 'ersatta' => []]);

        $res = $this->service->godkann(self::REF, 12, null, ['text' => 'helt annan omskriven text nu']);

        self::assertNotNull($res['diffPct']);
        self::assertGreaterThan(0, $res['diffPct']);
        self::assertSame($res['diffPct'], $utkast->getDiffPct());
        self::assertArrayHasKey('diff_pct', $this->journal[0]['detalj']);
    }

    public function testGodkannRedanAvgjortGerKonflikt(): void {
        $utkast = $this->utkast(13, 'fn_draft_journal', 'journalanteckning', ['falt' => []]);
        $utkast->setStatus(AiUtkast::STATUS_GODKANT);
        $this->mapper->method('findById')->with(13)->willReturn($utkast);

        $this->handlingService->expects($this->never())->method('generera');

        try {
            $this->service->godkann(self::REF, 13);
            self::fail('förväntade AiUtkastKonfliktException');
        } catch (AiUtkastKonfliktException $e) {
            self::assertSame(AiUtkastKonfliktException::FELKOD_REDAN_AVGJORT, $e->getFelkod());
        }
    }

    public function testGodkannUtanMallIdGerInvalidArgument(): void {
        $utkast = $this->utkast(14, 'fn_avslutssyntes', null, ['falt' => []]);
        $this->mapper->method('findById')->with(14)->willReturn($utkast);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->godkann(self::REF, 14);
    }

    // ================================================================== //
    //  fn_draft_beslutsformulering — utfall_eko-dubbelkoll + spärrlexikon
    // ================================================================== //

    public function testBeslutsformuleringUtfallEkoMismatchGerUtfallssparr(): void {
        $utkast = $this->utkast(20, AiUtkast::FN_BESLUTSFORMULERING, 'beslut', ['text' => 'Motivering till beslutet.']);
        $utkast->setUtfallEko('inleda');
        $this->mapper->method('findById')->with(20)->willReturn($utkast);

        $this->handlingService->expects($this->never())->method('generera');

        try {
            // Människans utfall = inte_inleda ≠ utkastets utfall_eko = inleda.
            $this->service->godkann(self::REF, 20, 'inte_inleda');
            self::fail('förväntade utfallsspärr');
        } catch (AiUtkastKonfliktException $e) {
            self::assertSame(AiUtkastKonfliktException::FELKOD_UTFALLSSPARR, $e->getFelkod());
        }
        self::assertNotNull($utkast->getInnehall(), 'ingen handling ⇒ innehall rörs inte vid spärr');
    }

    public function testBeslutsformuleringUtfallsordILexikonGerUtfallssparr(): void {
        $utkast = $this->utkast(21, AiUtkast::FN_BESLUTSFORMULERING, 'beslut',
            ['text' => 'Nämnden rekommenderar bifall till ansökan.']);
        $utkast->setUtfallEko('inleda');
        $this->mapper->method('findById')->with(21)->willReturn($utkast);

        $this->handlingService->expects($this->never())->method('generera');

        $this->expectException(AiUtkastKonfliktException::class);
        // utfall_eko matchar, men texten bär ett rekommendationsförslag ⇒ spärr.
        $this->service->godkann(self::REF, 21, 'inleda');
    }

    public function testBeslutsformuleringRenTextOchEkoMatchSkaparHandling(): void {
        $utkast = $this->utkast(22, AiUtkast::FN_BESLUTSFORMULERING, 'beslut',
            ['falt' => ['motivering' => 'Utredningen visar att stödbehovet är utrett.']]);
        $utkast->setUtfallEko('inleda');
        $this->mapper->method('findById')->with(22)->willReturn($utkast);

        $this->handlingService->expects($this->once())->method('generera')
            ->willReturn(['ok' => true, 'filnamn' => 'beslut.docx', 'antalErsatta' => 1, 'ersatta' => []]);

        $res = $this->service->godkann(self::REF, 22, 'inleda');

        self::assertTrue($res['ok']);
        self::assertSame(AiUtkast::STATUS_GODKANT, $utkast->getStatus());
        self::assertNull($utkast->getInnehall());
    }

    // ================================================================== //
    //  avvisa — innehall nollas OMEDELBART; INGEN handling
    // ================================================================== //

    public function testAvvisaNollarInnehallOchSkaparIngenHandling(): void {
        $utkast = $this->utkast(30, 'fn_draft_kommunicering', 'kommunicering', ['text' => 'brevutkast']);
        $this->mapper->method('findById')->with(30)->willReturn($utkast);

        $this->handlingService->expects($this->never())->method('generera');
        $this->mapper->expects($this->once())->method('update')->with($utkast);

        $res = $this->service->avvisa(self::REF, 30, 'ton');

        self::assertSame(AiUtkast::STATUS_AVVISAT, $res['status']);
        self::assertNull($utkast->getInnehall(), 'raderingsfönster: innehall raderas omedelbart vid avvisning');
        self::assertSame(HandelseTypAi::UTKAST_AVVISAT, $this->journal[0]['detalj']['handling']);
        self::assertSame('ton', $this->journal[0]['detalj']['orsak_kategori']);
    }

    public function testAvvisaRedanAvgjortGerKonflikt(): void {
        $utkast = $this->utkast(31, 'fn_draft_kommunicering', 'kommunicering', ['text' => 'x']);
        $utkast->setStatus(AiUtkast::STATUS_AVVISAT);
        $this->mapper->method('findById')->with(31)->willReturn($utkast);

        $this->expectException(AiUtkastKonfliktException::class);
        $this->service->avvisa(self::REF, 31);
    }

    // ================================================================== //
    //  H1 — utkast i annat ärende läcker aldrig existens
    // ================================================================== //

    public function testHamtaUtkastIAnnatArendeGerDoesNotExist(): void {
        $frammande = $this->utkast(40, 'fn_lage', null, ['text' => 'x']);
        $frammande->setHubsCaseId('caseid-annat-99999999');
        $this->mapper->method('findById')->with(40)->willReturn($frammande);

        $this->expectException(DoesNotExistException::class);
        $this->service->hamta(self::REF, 40);
    }

    public function testGodkannUtkastIAnnatArendeGerDoesNotExist(): void {
        $frammande = $this->utkast(41, 'fn_draft_journal', 'journalanteckning', ['falt' => []]);
        $frammande->setHubsCaseId('caseid-annat-99999999');
        $this->mapper->method('findById')->with(41)->willReturn($frammande);

        $this->handlingService->expects($this->never())->method('generera');
        $this->expectException(DoesNotExistException::class);
        $this->service->godkann(self::REF, 41);
    }

    // ================================================================== //
    //  Helpers
    // ================================================================== //

    /** @param array<string,mixed> $innehall */
    private function utkast(int $id, string $funktion, ?string $mallId, array $innehall): AiUtkast {
        $u = new AiUtkast();
        $u->setId($id);
        $u->setHubsCaseId(self::CASE_ID);
        $u->setRunId('run-' . $id);
        $u->setFunktion($funktion);
        $u->setMallId($mallId);
        $u->setInnehall(json_encode($innehall));
        $u->setStatus(AiUtkast::STATUS_UTKAST);
        $u->setModellversion('m-1.0');
        $u->setSkapad(new \DateTime('2026-07-09 09:00:00'));
        return $u;
    }
}
