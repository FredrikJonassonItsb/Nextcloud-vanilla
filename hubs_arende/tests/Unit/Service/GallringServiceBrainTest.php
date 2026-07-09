<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Tests\Unit\Service;

use OCA\HubsArende\Db\AiUtkastMapper;
use OCA\HubsArende\Db\Arende;
use OCA\HubsArende\Db\ArendeMapper;
use OCA\HubsArende\Db\Handelse;
use OCA\HubsArende\Db\HandelseMapper;
use OCA\HubsArende\Db\Pekare;
use OCA\HubsArende\Db\PekareMapper;
use OCA\HubsArende\Service\Brain\BrainProvisionRetryService;
use OCA\HubsArende\Service\Brain\BrainProvisionService;
use OCA\HubsArende\Service\Brain\HandelseTypAi;
use OCA\HubsArende\Service\GallringService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * BRAIN-GALLRING i retentions-svepet (SPEC-BRAIN-PER-ARENDE kap 9.3, facit "gallring").
 *
 * När ett committat ärende gallras måste dess brain-tenant gallras (DROP SCHEMA
 * CASCADE) via provisionern, med ett gallringsprotokoll skrivet i journalen FÖRE
 * borttag. Egenskaper som pinnas:
 *   1. En brain_tenant-pekare ⇒ ett TYP_AI/gallrad-protokoll journalförs FÖRE borttag
 *      och BrainProvisionService::delete() kallas med reason=null (riktig gallring),
 *      protokoll + händelse-ref; DÄREFTER purgas de lokala raderna (register + AI-
 *      utkast + retry-kö).
 *   2. Om brainen INTE kunde gallras (provisioner onåbar ⇒ delete=false) SKJUTS radens
 *      lokala gallring upp — register-raden raderas ALDRIG så länge brainen lever
 *      (ingen föräldralös brain).
 *   3. Ett ärende UTAN brain gallras normalt; AI-utkast-/retry-purge körs ändå
 *      (idempotent, NEVER-SoR).
 */
final class GallringServiceBrainTest extends TestCase {
    private ArendeMapper&MockObject $arendeMapper;
    private PekareMapper&MockObject $pekareMapper;
    private ITimeFactory&MockObject $timeFactory;
    private LoggerInterface&MockObject $logger;
    private HandelseMapper&MockObject $handelseMapper;
    private BrainProvisionService&MockObject $brain;
    private AiUtkastMapper&MockObject $aiUtkastMapper;
    private BrainProvisionRetryService&MockObject $retry;

    private \DateTime $now;

    protected function setUp(): void {
        parent::setUp();

        $this->arendeMapper = $this->createMock(ArendeMapper::class);
        $this->pekareMapper = $this->createMock(PekareMapper::class);
        $this->timeFactory = $this->createMock(ITimeFactory::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->handelseMapper = $this->createMock(HandelseMapper::class);
        $this->brain = $this->createMock(BrainProvisionService::class);
        $this->aiUtkastMapper = $this->createMock(AiUtkastMapper::class);
        $this->retry = $this->createMock(BrainProvisionRetryService::class);

        $this->now = new \DateTime('2026-07-09T00:00:00+00:00');
        $this->timeFactory->method('getDateTime')->willReturnCallback(fn (): \DateTime => clone $this->now);

        // Inga externa pekare utöver brain_tenant (isolera brain-beteendet).
        $this->pekareMapper->method('findByCaseId')->willReturn([]);
    }

    // ================================================================== //
    //  (1) Brain gallras: protokoll FÖRE borttag + DELETE + lokal purge.
    // ================================================================== //

    public function testGallrarBrainWithProtocolThenPurgesLocalRows(): void {
        $rad = $this->makeGallringsbar('caseid-g1', $this->daysAgo(3));
        $this->arendeMapper->method('findGallringsbara')->willReturn([$rad]);

        $this->pekareMapper->method('findByCaseAndTyp')
            ->with('caseid-g1', 'brain_tenant')
            ->willReturn([$this->tenantPekare('caseid-g1', 'tenant-g1')]);

        // Gallringsprotokollet journalförs (TYP_AI/gallrad); dess id blir händelse-ref.
        $journal = [];
        $this->handelseMapper->method('record')
            ->willReturnCallback(function (string $caseId, string $typ, array $detalj = []) use (&$journal): Handelse {
                $journal[] = [$typ, $detalj];
                $h = new Handelse();
                $h->setId(555);
                return $h;
            });

        // DELETE med reason=null (riktig gallring), händelse-ref='555' och protokoll.
        $this->brain->expects(self::once())
            ->method('delete')
            ->with(
                'tenant-g1',
                'gallring',
                null,
                '555',
                self::callback(static fn ($p): bool => is_array($p) && ($p['typ'] ?? null) === 'gallring'),
            )
            ->willReturn(true);

        // Lokala purge-vägar körs EFTER lyckad brain-gallring.
        $this->aiUtkastMapper->expects(self::once())->method('deleteByCaseId')->with('caseid-g1');
        $this->retry->expects(self::once())->method('deleteByCase')->with('caseid-g1');
        $this->arendeMapper->expects(self::once())->method('delete');

        $result = $this->makeService()->gallra();

        self::assertSame(1, $result['antal']);
        self::assertContains(
            [HandelseTypAi::typVarde(), ['handling' => HandelseTypAi::GALLRAD, 'orsak_kategori' => 'retention_efter_commit']],
            $journal,
            'ett TYP_AI/gallrad-protokoll skrevs FÖRE borttag',
        );
    }

    // ================================================================== //
    //  (2) Brain-DELETE misslyckas → radens lokala gallring skjuts upp.
    // ================================================================== //

    public function testFailedBrainDeleteDefersLocalGallring(): void {
        $rad = $this->makeGallringsbar('caseid-g2', $this->daysAgo(3));
        $this->arendeMapper->method('findGallringsbara')->willReturn([$rad]);

        $this->pekareMapper->method('findByCaseAndTyp')
            ->with('caseid-g2', 'brain_tenant')
            ->willReturn([$this->tenantPekare('caseid-g2', 'tenant-g2')]);
        $this->handelseMapper->method('record')->willReturnCallback(function (): Handelse {
            $h = new Handelse();
            $h->setId(42);
            return $h;
        });

        // Provisionern onåbar ⇒ DELETE returnerar false.
        $this->brain->method('delete')->willReturn(false);

        // Ingen lokal rad får raderas för ett ärende vars brain lever kvar.
        $this->arendeMapper->expects(self::never())->method('delete');
        $this->aiUtkastMapper->expects(self::never())->method('deleteByCaseId');
        $this->retry->expects(self::never())->method('deleteByCase');

        $result = $this->makeService()->gallra();

        self::assertSame(0, $result['antal'], 'ingen orphan: radens gallring skjuts upp till nästa svep');
    }

    // ================================================================== //
    //  (3) Inget brain → normal gallring + idempotent lokal purge.
    // ================================================================== //

    public function testCaseWithoutBrainGallrasNormally(): void {
        $rad = $this->makeGallringsbar('caseid-g3', $this->daysAgo(3));
        $this->arendeMapper->method('findGallringsbara')->willReturn([$rad]);

        $this->pekareMapper->method('findByCaseAndTyp')
            ->with('caseid-g3', 'brain_tenant')
            ->willReturn([]); // icke-brainärende

        // Ingen provisioner-DELETE och inget gallringsprotokoll utan brain.
        $this->brain->expects(self::never())->method('delete');
        $this->handelseMapper->expects(self::never())
            ->method('record')
            ->with(self::anything(), HandelseTypAi::typVarde(), self::anything());

        // Men den lokala purgen körs ändå (idempotent, NEVER-SoR).
        $this->aiUtkastMapper->expects(self::once())->method('deleteByCaseId')->with('caseid-g3');
        $this->retry->expects(self::once())->method('deleteByCase')->with('caseid-g3');
        $this->arendeMapper->expects(self::once())->method('delete');

        $result = $this->makeService()->gallra();

        self::assertSame(1, $result['antal']);
    }

    // ================================================================== //
    //  Helpers
    // ================================================================== //

    private function makeService(): GallringService {
        return new GallringService(
            arendeMapper: $this->arendeMapper,
            pekareMapper: $this->pekareMapper,
            logger: $this->logger,
            timeFactory: $this->timeFactory,
            handelseMapper: $this->handelseMapper,
            brainProvisionService: $this->brain,
            aiUtkastMapper: $this->aiUtkastMapper,
            brainProvisionRetryService: $this->retry,
        );
    }

    private function makeGallringsbar(string $caseId, ?\DateTime $gallrasDatum): Arende {
        $arende = new Arende();
        $arende->setHubsCaseId($caseId);
        $arende->setArendeTyp('orosanmalan');
        $arende->setCommitDestination('facksystem');
        $arende->setProvenanceState('registrerad');
        $arende->setRetentionState('gallras_efter_commit');
        $arende->setDnr('2026-IFO-' . substr($caseId, -2));
        $arende->setGallrasDatum($gallrasDatum);
        return $arende;
    }

    private function tenantPekare(string $caseId, string $tenantId): Pekare {
        $p = new Pekare();
        $p->setHubsCaseId($caseId);
        $p->setObjektTyp('brain_tenant');
        $p->setObjektId($tenantId);
        return $p;
    }

    private function daysAgo(int $days): \DateTime {
        return (clone $this->now)->sub(new \DateInterval('P' . $days . 'D'));
    }
}
