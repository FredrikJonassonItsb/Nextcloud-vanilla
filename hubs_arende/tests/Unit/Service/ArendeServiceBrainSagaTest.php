<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Tests\Unit\Service;

use OCA\HubsArende\Db\ArendeMapper;
use OCA\HubsArende\Db\ArendeTyp;
use OCA\HubsArende\Db\Handelse;
use OCA\HubsArende\Db\HandelseMapper;
use OCA\HubsArende\Db\Pekare;
use OCA\HubsArende\Db\PekareMapper;
use OCA\HubsArende\Service\ArendeService;
use OCA\HubsArende\Service\ArendeTypRegistry;
use OCA\HubsArende\Service\Brain\BrainProvisionRetryService;
use OCA\HubsArende\Service\Brain\BrainProvisionService;
use OCA\HubsArende\Service\Brain\BrainProvisionUnavailable;
use OCA\HubsArende\Service\Brain\HandelseTypAi;
use OCA\HubsArende\Service\FacksystemCommitService;
use OCA\HubsArende\Service\SakerhetsskyddGrind;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * SAGA-steg R2b — BRAIN-PROVISIONERING (SPEC-BRAIN-PER-ARENDE kap 1.2/3.3).
 *
 * HÅRD INVARIANT som pinnas här: ärendeskapande får ALDRIG blockeras av AI-infra.
 * Konsekvenserna som testas:
 *   1. Lyckad provisionering ⇒ brain_tenant-pekare (hubs_case_id → tenant_id) skrivs
 *      och en TYP_AI/provisionerad-journal loggas; ärendet skapas normalt.
 *   2. RETRYBAR provisionerings-fault (BrainProvisionUnavailable) ⇒ ärendet KÖAS
 *      durabelt (BrainProvisionRetryService::enqueue) och skapandet FORTSÄTTER utan
 *      brain — INGET kast bubblar ur createCase.
 *   3. Ett OHANTERAT brain-fel (godtycklig \Throwable ur provision) sväljs likaså —
 *      belt-and-suspenders; ärendet skapas ändå.
 *   4. En SENARE saga-fas som fallerar kör R2b:s kompensering: tenanten rivs
 *      (rollback) och brain_tenant-pekaren tas bort.
 *   5. Utan brain-injektion (positionell testharness) hoppas R2b helt (bevarad
 *      bakåtkompatibilitet — inga nya beroenden krävs).
 */
final class ArendeServiceBrainSagaTest extends TestCase {
    private ArendeMapper&MockObject $arendeMapper;
    private ArendeTypRegistry&MockObject $typRegistry;
    private SakerhetsskyddGrind&MockObject $grind;
    private FacksystemCommitService&MockObject $commitService;
    private ISecureRandom&MockObject $secureRandom;
    private ITimeFactory&MockObject $timeFactory;
    private LoggerInterface&MockObject $logger;
    private HandelseMapper&MockObject $handelseMapper;
    private PekareMapper&MockObject $pekareMapper;
    private BrainProvisionService&MockObject $brain;
    private BrainProvisionRetryService&MockObject $retry;

    protected function setUp(): void {
        parent::setUp();

        $this->arendeMapper = $this->createMock(ArendeMapper::class);
        $this->typRegistry = $this->createMock(ArendeTypRegistry::class);
        $this->grind = $this->createMock(SakerhetsskyddGrind::class);
        $this->commitService = $this->createMock(FacksystemCommitService::class);
        $this->secureRandom = $this->createMock(ISecureRandom::class);
        $this->timeFactory = $this->createMock(ITimeFactory::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->handelseMapper = $this->createMock(HandelseMapper::class);
        $this->pekareMapper = $this->createMock(PekareMapper::class);
        $this->brain = $this->createMock(BrainProvisionService::class);
        $this->retry = $this->createMock(BrainProvisionRetryService::class);

        $this->timeFactory->method('getDateTime')
            ->willReturnCallback(static fn (): \DateTime => new \DateTime('2026-07-09T08:00:00+00:00'));
        $this->secureRandom->method('generate')
            ->willReturn("\x01\x23\x45\x67\x89\xab\xcd\xef\x01\x23\x45\x67\x89\xab\xcd\xef");
        $this->grind->method('evaluate')->willReturn([
            'avvisad' => false,
            'reason' => SakerhetsskyddGrind::REASON_OK,
            'retroaktiv' => false,
            'indikator' => SakerhetsskyddGrind::IND_NONE,
            'kvitto' => [],
        ]);
        $this->arendeMapper->method('findByConversationId')->willReturn(null);
        $this->arendeMapper->method('insert')->willReturnArgument(0);
        $this->typRegistry->method('get')->willReturn($this->typ());
    }

    // ================================================================== //
    //  (1) Lyckad provisionering → brain_tenant-pekare + TYP_AI-journal.
    // ================================================================== //

    public function testSuccessfulProvisionRecordsPekareAndJournalsTypAi(): void {
        $this->arendeMapper->method('update')->willReturnArgument(0);

        $this->brain->expects(self::once())
            ->method('provision')
            ->willReturn(['tenant_id' => 'tenant-xyz', 'schema' => 'case_x', 'status' => 'aktiv', 'idempotent' => false]);

        // brain_tenant-pekaren skrivs (hubs_case_id → tenant_id).
        $pekare = [];
        $this->pekareMapper->method('record')
            ->willReturnCallback(function (string $caseId, string $typ, string $objektId) use (&$pekare): Pekare {
                $pekare[] = [$typ, $objektId];
                return new Pekare();
            });

        // Journal fångas (TYP_AI/provisionerad ska finnas).
        $journal = [];
        $this->handelseMapper->method('record')
            ->willReturnCallback(function (string $caseId, string $typ, array $detalj = []) use (&$journal): Handelse {
                $journal[] = [$typ, $detalj];
                return new Handelse();
            });

        $this->retry->expects(self::never())->method('enqueue');

        $service = $this->makeService();
        $arende = $service->createCase(['conversationId' => 'conv-b1', 'arendeTyp' => 'orosanmalan', 'objektRef' => 'obj-b1']);

        self::assertSame('otilldelat', $arende->getStatus(), 'ärendet skapas normalt');
        self::assertContains(['brain_tenant', 'tenant-xyz'], $pekare, 'brain_tenant-pekaren skrevs med tenant_id');
        self::assertContains(
            [HandelseTypAi::typVarde(), ['handling' => HandelseTypAi::PROVISIONERAD, 'idempotent' => false]],
            $journal,
            'en TYP_AI/provisionerad-journal loggades',
        );
    }

    // ================================================================== //
    //  (2) BrainProvisionUnavailable → durabel retry, ärendet skapas ändå.
    // ================================================================== //

    public function testRetryableFaultEnqueuesAndNeverFellsCreate(): void {
        $this->arendeMapper->method('update')->willReturnArgument(0);

        $this->brain->method('provision')
            ->willThrowException(new BrainProvisionUnavailable('provisioner onåbar'));

        // Ärendet KÖAS durabelt (idempotent enqueue) — det är hela poängen med R2b.
        $enq = [];
        $this->retry->expects(self::once())
            ->method('enqueue')
            ->willReturnCallback(function (string $caseId, string $typId) use (&$enq): void {
                $enq[] = [$caseId, $typId];
            });

        // Ingen brain_tenant-pekare vid retry (ingen tenant ännu).
        $this->pekareMapper->expects(self::never())->method('record');

        $service = $this->makeService();
        // INGET kast får bubbla — ärendet ska skapas trots att provisionern är nere.
        $arende = $service->createCase(['conversationId' => 'conv-b2', 'arendeTyp' => 'orosanmalan', 'objektRef' => 'obj-b2']);

        self::assertSame('otilldelat', $arende->getStatus(), 'ärendeskapande blockeras aldrig av AI-infra');
        self::assertCount(1, $enq, 'ärendet köades för durabel efterprovisionering');
        self::assertSame('orosanmalan', $enq[0][1], 'ärendetyp bärs med till kön');
        self::assertNotSame('', $enq[0][0], 'ett hubsCaseId köades');
    }

    // ================================================================== //
    //  (3) Godtyckligt \Throwable ur provision sväljs (belt-and-suspenders).
    // ================================================================== //

    public function testUnexpectedProvisionErrorIsSwallowed(): void {
        $this->arendeMapper->method('update')->willReturnArgument(0);

        // Ett fel som INTE är BrainProvisionUnavailable (kontraktsbrott/bugg) får
        // ändå aldrig fälla skapandet (kap 3.3 belt-and-suspenders).
        $this->brain->method('provision')
            ->willThrowException(new \RuntimeException('oväntat provisioner-fel'));

        // Ett oväntat fel klassas inte som retrybart ⇒ ingen enqueue.
        $this->retry->expects(self::never())->method('enqueue');

        $service = $this->makeService();
        $arende = $service->createCase(['conversationId' => 'conv-b3', 'arendeTyp' => 'orosanmalan', 'objektRef' => 'obj-b3']);

        self::assertSame('otilldelat', $arende->getStatus());
    }

    // ================================================================== //
    //  (4) Senare saga-fel → R2b-kompensering river tenanten (rollback).
    // ================================================================== //

    public function testLaterSagaFailureRollsBackTheBrainTenant(): void {
        $this->brain->method('provision')
            ->willReturn(['tenant_id' => 'tenant-rb', 'schema' => 's', 'status' => 'aktiv', 'idempotent' => false]);
        $this->pekareMapper->method('record')->willReturn(new Pekare());

        // Tvinga ett saga-fel EFTER R2b: nästa register-update kastar.
        $this->arendeMapper->method('update')
            ->willThrowException(new \RuntimeException('saga-fel efter R2b'));

        // Kompenseringen måste riva den provisionerade tenanten …
        $this->brain->expects(self::once())->method('rollback')->with('tenant-rb')->willReturn(true);
        // … och ta bort brain_tenant-pekaren.
        $this->pekareMapper->expects(self::atLeastOnce())
            ->method('deleteByCaseAndTyp')
            ->with(self::isType('string'), 'brain_tenant');

        $service = $this->makeService();

        // Sagan kompenseras och wrappar felet i \RuntimeException (motorns kontrakt).
        $this->expectException(\RuntimeException::class);
        $service->createCase(['conversationId' => 'conv-b4', 'arendeTyp' => 'orosanmalan', 'objektRef' => 'obj-b4']);
    }

    // ================================================================== //
    //  (5) Utan brain-injektion hoppas R2b helt (bakåtkompatibelt).
    // ================================================================== //

    public function testWithoutBrainCollaboratorR2bIsSkipped(): void {
        $this->arendeMapper->method('update')->willReturnArgument(0);

        // Positionell testharness: inga brain-tjänster → provision/enqueue anropas aldrig.
        $this->brain->expects(self::never())->method('provision');
        $this->retry->expects(self::never())->method('enqueue');

        $service = new ArendeService(
            $this->arendeMapper, $this->typRegistry, $this->grind, $this->commitService,
            $this->secureRandom, $this->timeFactory, $this->logger,
        );
        $arende = $service->createCase(['conversationId' => 'conv-b5', 'arendeTyp' => 'orosanmalan', 'objektRef' => 'obj-b5']);

        self::assertSame('otilldelat', $arende->getStatus());
    }

    // ================================================================== //
    //  Helpers
    // ================================================================== //

    private function makeService(): ArendeService {
        return new ArendeService(
            $this->arendeMapper,
            $this->typRegistry,
            $this->grind,
            $this->commitService,
            $this->secureRandom,
            $this->timeFactory,
            $this->logger,
            pekareMapper: $this->pekareMapper,
            handelseMapper: $this->handelseMapper,
            brainProvisionService: $this->brain,
            brainProvisionRetryService: $this->retry,
        );
    }

    private function typ(): ArendeTyp {
        $typ = new ArendeTyp();
        $typ->setArendeTypId('orosanmalan');
        $typ->setDisplayName('orosanmalan');
        $typ->setCommitDestination('facksystem');
        $typ->setDefaultEnhet('barn-familj@');
        $typ->setFristPolicy(json_encode(['typ' => 'domstol', 'speglasUrTreserva' => true]));
        return $typ;
    }
}
