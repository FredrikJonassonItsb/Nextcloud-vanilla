<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Tests\Unit\Service;

use OCA\HubsArende\Db\Arende;
use OCA\HubsArende\Db\ArendeMapper;
use OCA\HubsArende\Db\Handelse;
use OCA\HubsArende\Db\HandelseMapper;
use OCA\HubsArende\Db\Pekare;
use OCA\HubsArende\Db\PekareMapper;
use OCA\HubsArende\Service\ArendeLifecycleService;
use OCA\HubsArende\Service\ArendeService;
use OCA\HubsArende\Service\ArendeTypRegistry;
use OCA\HubsArende\Service\Brain\BrainProvisionService;
use OCA\HubsArende\Service\Brain\HandelseTypAi;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * BRAIN-FRYSNING VID AVSLUT (SPEC-BRAIN-PER-ARENDE kap 3.4/9.2).
 *
 * När ett ärende når terminalsteget `avslutat` görs dess brain read-only: tenanten
 * FRYSES (skrivnyckeln dödas). Egenskaper som pinnas:
 *   1. Avslut + befintlig brain_tenant-pekare ⇒ BrainProvisionService::freeze() kallas
 *      med tenant_id + hubsCaseId, och en TYP_AI/fryst-journal loggas.
 *   2. Avslut UTAN brain (ingen pekare) ⇒ ingen frysning; övergången lyckas ändå.
 *   3. En icke-avslut-övergång rör ALDRIG brainen.
 *   4. Frysningen är BEST-EFFORT: en misslyckad/kastande freeze får aldrig fälla den
 *      redan persisterade övergången (och loggar då ingen fryst-journal).
 */
final class ArendeLifecycleServiceBrainFreezeTest extends TestCase {
    private const CASE_ID = 'caseid-brain-0001';

    private ArendeService&MockObject $arendeService;
    private ArendeMapper&MockObject $arendeMapper;
    private ArendeTypRegistry&MockObject $typRegistry;
    private LoggerInterface&MockObject $logger;
    private ITimeFactory&MockObject $timeFactory;
    private HandelseMapper&MockObject $handelseMapper;
    private PekareMapper&MockObject $pekareMapper;
    private BrainProvisionService&MockObject $brain;

    protected function setUp(): void {
        parent::setUp();

        $this->arendeService = $this->createMock(ArendeService::class);
        $this->arendeMapper = $this->createMock(ArendeMapper::class);
        $this->typRegistry = $this->createMock(ArendeTypRegistry::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->timeFactory = $this->createMock(ITimeFactory::class);
        $this->handelseMapper = $this->createMock(HandelseMapper::class);
        $this->pekareMapper = $this->createMock(PekareMapper::class);
        $this->brain = $this->createMock(BrainProvisionService::class);

        $this->arendeMapper->method('update')->willReturnArgument(0);
    }

    // ================================================================== //
    //  (1) Avslut med brain → freeze() + TYP_AI/fryst-journal.
    // ================================================================== //

    public function testAvslutFreezesBrainAndJournalsFryst(): void {
        $arende = $this->makeArende('utredning');
        $this->arendeService->method('show')->with(self::CASE_ID)->willReturn($arende);

        $this->pekareMapper->method('findByCaseAndTyp')
            ->with(self::CASE_ID, 'brain_tenant')
            ->willReturn([$this->pekare('tenant-f1')]);

        // Frysningen körs mot tenanten + ärendet, orsak 'avslut'.
        $this->brain->expects(self::once())
            ->method('freeze')
            ->with('tenant-f1', self::CASE_ID, 'avslut')
            ->willReturn(true);

        $journal = [];
        $this->handelseMapper->method('record')
            ->willReturnCallback(function (string $caseId, string $typ, array $detalj = []) use (&$journal): Handelse {
                $journal[] = [$typ, $detalj];
                return new Handelse();
            });

        $result = $this->makeService()->transitionera(self::CASE_ID, 'avslutat');

        self::assertSame('avslutat', $result->getSteg());
        self::assertContains(
            [HandelseTypAi::typVarde(), ['handling' => HandelseTypAi::FRYST]],
            $journal,
            'en TYP_AI/fryst-journal loggades vid frysningen',
        );
    }

    // ================================================================== //
    //  (2) Avslut utan brain → ingen frysning, övergången lyckas.
    // ================================================================== //

    public function testAvslutWithoutBrainIsCleanNoOp(): void {
        $arende = $this->makeArende('beslut');
        $this->arendeService->method('show')->with(self::CASE_ID)->willReturn($arende);

        $this->pekareMapper->method('findByCaseAndTyp')
            ->with(self::CASE_ID, 'brain_tenant')
            ->willReturn([]); // inget brain för detta ärende

        $this->brain->expects(self::never())->method('freeze');

        $result = $this->makeService()->transitionera(self::CASE_ID, 'avslutat');

        self::assertSame('avslutat', $result->getSteg());
    }

    // ================================================================== //
    //  (3) Icke-avslut-övergång rör aldrig brainen.
    // ================================================================== //

    public function testNonAvslutTransitionNeverTouchesBrain(): void {
        $arende = $this->makeArende('inflode');
        $this->arendeService->method('show')->with(self::CASE_ID)->willReturn($arende);

        // Varken tenant-uppslag eller frysning får ske för en vanlig framåt-övergång.
        $this->pekareMapper->expects(self::never())->method('findByCaseAndTyp');
        $this->brain->expects(self::never())->method('freeze');

        $result = $this->makeService()->transitionera(self::CASE_ID, 'forhandsbedomning');

        self::assertSame('forhandsbedomning', $result->getSteg());
    }

    // ================================================================== //
    //  (4) Best-effort: en kastande freeze fäller inte övergången.
    // ================================================================== //

    public function testFreezeFailureDoesNotFellTheTransition(): void {
        $arende = $this->makeArende('uppfoljning');
        $this->arendeService->method('show')->with(self::CASE_ID)->willReturn($arende);

        $this->pekareMapper->method('findByCaseAndTyp')
            ->with(self::CASE_ID, 'brain_tenant')
            ->willReturn([$this->pekare('tenant-f4')]);

        // Frysningen kastar — övergången är redan persisterad och får inte rivas.
        $this->brain->method('freeze')->willThrowException(new \RuntimeException('provisioner nere'));

        // Fånga alla journalrader: steg-övergången (TYP_STEG) skrivs som vanligt, men
        // INGEN TYP_AI/fryst-rad får skrivas när frysningen inte lyckades.
        $journal = [];
        $this->handelseMapper->method('record')
            ->willReturnCallback(function (string $caseId, string $typ, array $detalj = []) use (&$journal): Handelse {
                $journal[] = [$typ, $detalj];
                return new Handelse();
            });

        $result = $this->makeService()->transitionera(self::CASE_ID, 'avslutat');

        self::assertSame('avslutat', $result->getSteg(), 'övergången står kvar trots frys-fel');
        self::assertNotContains(
            [HandelseTypAi::typVarde(), ['handling' => HandelseTypAi::FRYST]],
            $journal,
            'ingen fryst-journal när frysningen fallerade',
        );
    }

    // ================================================================== //
    //  Helpers
    // ================================================================== //

    private function makeService(): ArendeLifecycleService {
        return new ArendeLifecycleService(
            arendeService: $this->arendeService,
            arendeMapper: $this->arendeMapper,
            typRegistry: $this->typRegistry,
            logger: $this->logger,
            timeFactory: $this->timeFactory,
            handelseMapper: $this->handelseMapper,
            pekareMapper: $this->pekareMapper,
            brainProvisionService: $this->brain,
        );
    }

    private function makeArende(string $steg): Arende {
        $arende = new Arende();
        $arende->setHubsCaseId(self::CASE_ID);
        $arende->setEnhet('barn-familj@');
        $arende->setArendeTyp('orosanmalan');
        $arende->setCommitDestination('facksystem');
        $arende->setStatus('tilldelat');
        $arende->setSteg($steg);
        return $arende;
    }

    private function pekare(string $tenantId): Pekare {
        $p = new Pekare();
        $p->setHubsCaseId(self::CASE_ID);
        $p->setObjektTyp('brain_tenant');
        $p->setObjektId($tenantId);
        return $p;
    }
}
