<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Tests\Unit\Brain;

use OCA\HubsArende\BackgroundJob\BrainProvisionRetryJob;
use OCA\HubsArende\Db\Arende;
use OCA\HubsArende\Db\ArendeMapper;
use OCA\HubsArende\Db\HandelseMapper;
use OCA\HubsArende\Service\Brain\BrainProvisionRetryService;
use OCA\HubsArende\Service\Brain\BrainProvisionService;
use OCA\HubsArende\Service\Brain\BrainProvisionUnavailable;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * BrainProvisionRetryJob — den durabla retry-loopen bakom SAGA-steget R2b (kap 3.3).
 *
 * Verifierar de tre bindande beteendena:
 *   1. FÖRÄLDRALÖS-SKYDD: ärende som inte längre finns i registret ⇒ raden markeras
 *      permanent, och provisionern anropas ALDRIG (ingen brain för ett dött ärende).
 *   2. RETRY-IDEMPOTENS: lyckad (idempotent) POST ⇒ status='klar' + TYP_AI-journal.
 *   3. ICKE-FÄLLANDE: BrainProvisionUnavailable ⇒ backoff-schemaläggning, jobbet kastar
 *      aldrig upp; permanent fel (409/422) ⇒ terminal markering + driftlarm.
 */
final class BrainProvisionRetryJobTest extends TestCase {
    private const CASE = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

    private ITimeFactory&MockObject $time;
    private ArendeMapper&MockObject $arendeMapper;
    private BrainProvisionRetryService&MockObject $retry;
    private BrainProvisionService&MockObject $provision;
    private HandelseMapper&MockObject $handelseMapper;
    private LoggerInterface&MockObject $logger;
    private TestableRetryJob $job;

    protected function setUp(): void {
        parent::setUp();
        $this->time = $this->createMock(ITimeFactory::class);
        $this->time->method('getTime')->willReturn(1_700_000_000);
        $this->arendeMapper = $this->createMock(ArendeMapper::class);
        $this->retry = $this->createMock(BrainProvisionRetryService::class);
        $this->provision = $this->createMock(BrainProvisionService::class);
        $this->handelseMapper = $this->createMock(HandelseMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->job = new TestableRetryJob(
            $this->time,
            $this->arendeMapper,
            $this->retry,
            $this->provision,
            $this->handelseMapper,
            $this->logger,
        );
    }

    /** 1. Föräldralöst ärende: markeras permanent, provisionern rörs ALDRIG. */
    public function testForaldralostArendeMarkerasPermanentUtanPost(): void {
        $this->retry->method('claimDue')->willReturn([
            ['hubs_case_id' => self::CASE, 'arende_typ' => 'orosanmalan', 'forsok' => 0],
        ]);
        // Registret svarar "finns inte" ⇒ sen SAGA-rollback rev ärendet.
        $this->arendeMapper->method('findByCaseId')
            ->with(self::CASE)
            ->willThrowException(new DoesNotExistException('borta'));

        // Spärren: ingen provisionering, terminal markering.
        $this->provision->expects(self::never())->method('provision');
        $this->retry->expects(self::once())->method('markPermanent')->with(self::CASE);
        $this->retry->expects(self::never())->method('markKlar');

        $this->job->kor();
    }

    /** 2. Lyckad idempotent efterprovisionering: status=klar + TYP_AI-journal. */
    public function testLyckadProvisioneringMarkerarKlarOchJournalfor(): void {
        $this->retry->method('claimDue')->willReturn([
            ['hubs_case_id' => self::CASE, 'arende_typ' => 'orosanmalan', 'forsok' => 2],
        ]);
        $this->arendeMapper->method('findByCaseId')->willReturn($this->createMock(Arende::class));
        $this->provision->method('provision')->with(self::CASE, 'orosanmalan')->willReturn([
            'tenant_id' => 'tenant-1',
            'schema' => 'arende_x',
            'status' => 'aktiv',
            'idempotent' => true,
        ]);

        $this->retry->expects(self::once())->method('markKlar')->with(self::CASE);
        $this->retry->expects(self::never())->method('schemalaggAterforsok');
        // Journalför TYP_AI {handling:'provisionerad'} (utan status-fält).
        $this->handelseMapper->expects(self::once())
            ->method('record')
            ->with(self::CASE, 'ai', ['handling' => 'provisionerad']);

        $this->job->kor();
    }

    /** 3a. Retrybart fel: backoff-schemaläggning, jobbet kastar inte. */
    public function testRetrybartFelSchemalaggerBackoffUtanKast(): void {
        $this->retry->method('claimDue')->willReturn([
            ['hubs_case_id' => self::CASE, 'arende_typ' => 'orosanmalan', 'forsok' => 1],
        ]);
        $this->arendeMapper->method('findByCaseId')->willReturn($this->createMock(Arende::class));
        $this->provision->method('provision')->willThrowException(new BrainProvisionUnavailable('nere'));

        // forsok 1 → 2, nästa fönster = nu + 15min·2^1 = nu + 1800.
        $this->retry->expects(self::once())
            ->method('schemalaggAterforsok')
            ->with(self::CASE, 2, 1_700_000_000 + 1800);
        $this->retry->expects(self::never())->method('markPermanent');
        $this->retry->expects(self::never())->method('markKlar');

        // Får inte kasta.
        $this->job->kor();
    }

    /** 3b. Larmtröskel: femte misslyckandet loggar driftlarm (critical). */
    public function testFemteMisslyckandetLoggarDriftlarm(): void {
        $this->retry->method('claimDue')->willReturn([
            ['hubs_case_id' => self::CASE, 'arende_typ' => 'orosanmalan', 'forsok' => 4],
        ]);
        $this->arendeMapper->method('findByCaseId')->willReturn($this->createMock(Arende::class));
        $this->provision->method('provision')->willThrowException(new BrainProvisionUnavailable('nere'));

        $this->retry->expects(self::once())->method('schemalaggAterforsok')->with(self::CASE, 5, self::anything());
        $this->logger->expects(self::atLeastOnce())->method('critical');

        $this->job->kor();
    }

    /** 3c. Permanent fel (409/422): terminal markering + driftlarm, ingen backoff. */
    public function testPermanentFelMarkerarTerminaltMedLarm(): void {
        $this->retry->method('claimDue')->willReturn([
            ['hubs_case_id' => self::CASE, 'arende_typ' => 'orosanmalan', 'forsok' => 0],
        ]);
        $this->arendeMapper->method('findByCaseId')->willReturn($this->createMock(Arende::class));
        $this->provision->method('provision')->willReturn(['permanent_fel' => true, 'kod' => 'ogiltigt_hubs_case_id']);

        $this->retry->expects(self::once())->method('markPermanent')->with(self::CASE);
        $this->retry->expects(self::never())->method('schemalaggAterforsok');
        $this->logger->expects(self::atLeastOnce())->method('critical');

        $this->job->kor();
    }

    /** Svepet får aldrig krascha cron-runnern: claimDue kastar ⇒ sväljs. */
    public function testSvepFangarAllaFel(): void {
        $this->retry->method('claimDue')->willThrowException(new \RuntimeException('db nere'));
        $this->logger->expects(self::atLeastOnce())->method('error');

        // Ingen exception ska nå ut.
        $this->job->kor();
    }
}

/**
 * Testbar subklass: exponerar den skyddade run()-metoden.
 */
final class TestableRetryJob extends BrainProvisionRetryJob {
    public function kor(): void {
        $this->run(null);
    }
}
