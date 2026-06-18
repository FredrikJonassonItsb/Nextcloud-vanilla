<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Tests\Unit\Service;

use OCA\HubsArende\Db\Arende;
use OCA\HubsArende\Db\ArendeMapper;
use OCA\HubsArende\Db\Pekare;
use OCA\HubsArende\Db\PekareMapper;
use OCA\HubsArende\Service\GallringService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * L2 — retention sweep (gallring) of cases whose committed gallrings-deadline has
 * passed. Reconciled against the built {@see GallringService}.
 *
 * CONTRACT (motor §retention, granskningsrapport L2): once a case is committed its
 * retentionState flips to `gallras_efter_commit` and the verified receipt's
 * `gallrasDatum` is persisted. A periodic sweep must then, AND ONLY THEN, delete
 * the coordination row + its pekare. The register is NEVER System of Record, so
 * deleting a gallringsbar row loses no verksamhetsdata (that lives in the
 * facksystem, retained under its own gallringsplan).
 *
 * Pinned safety properties:
 *   1. A row that is provenance=registrerad + retention=gallras_efter_commit + has a
 *      gallras_datum in the PAST is gallrad: arendeMapper->delete is called for it and
 *      its pekare are torn down (findByCaseId + delete-loop).
 *   2. SÄKERHETSVAKT — a row WITHOUT a gallras_datum is NEVER deleted (no deadline ⇒
 *      fail safe toward retention).
 *   3. SÄKERHETSVAKT — a row whose gallras_datum is in the FUTURE is NEVER deleted even
 *      if the candidate query mistakenly returned it (defense in depth: the service
 *      re-checks gallrasDatum <= now, it does not blindly trust the finder).
 *   4. An empty candidate set is a clean no-op: antal=0, zero delete calls.
 *
 * Verified facts about the built service (so this test matches it):
 *   * `gallra(?\DateTime $now = null): array{antal:int, hubsCaseIds:string[]}`.
 *   * Candidates come from `ArendeMapper::findGallringsbara($now)`.
 *   * Pekare teardown = `PekareMapper::findByCaseId($id)` then `delete()` per row
 *     (the mapper has no deleteByCaseId()).
 *   * Constructor param names: arendeMapper, pekareMapper, logger, timeFactory.
 */
final class GallringServiceTest extends TestCase {
	private ArendeMapper&MockObject $arendeMapper;
	private PekareMapper&MockObject $pekareMapper;
	private ITimeFactory&MockObject $timeFactory;
	private LoggerInterface&MockObject $logger;

	private GallringService $service;

	/** Fixed "now" — candidates' gallras_datum are placed relative to this. */
	private \DateTime $now;

	protected function setUp(): void {
		parent::setUp();

		$this->arendeMapper = $this->createMock(ArendeMapper::class);
		$this->pekareMapper = $this->createMock(PekareMapper::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->now = new \DateTime('2026-06-17T00:00:00+00:00');
		$this->timeFactory->method('getDateTime')
			->willReturnCallback(fn (): \DateTime => clone $this->now);

		// Named args: robust to constructor parameter ORDER, matches param NAMES.
		$this->service = new GallringService(
			arendeMapper: $this->arendeMapper,
			pekareMapper: $this->pekareMapper,
			logger: $this->logger,
			timeFactory: $this->timeFactory,
		);
	}

	// ================================================================== //
	//  2 förfallna gallringsbara rader → delete×2 + pekare raderas.
	// ================================================================== //

	public function testGallrarForfallnaRowsDeletesRowAndPekare(): void {
		$a = $this->makeGallringsbar('caseid-aaaa-0001', $this->daysAgo(5));
		$b = $this->makeGallringsbar('caseid-bbbb-0002', $this->daysAgo(1));

		$this->stubCandidates([$a, $b]);

		// Each case has exactly one pekare; the service finds them by case-id.
		$this->pekareMapper->method('findByCaseId')
			->willReturnCallback(fn (string $caseId): array => [$this->makePekare($caseId)]);

		// The CASE row is deleted once per gallringsbar rad (load-bearing assert).
		$deletedCases = [];
		$this->arendeMapper->expects(self::exactly(2))
			->method('delete')
			->willReturnCallback(function (Arende $row) use (&$deletedCases): Arende {
				$deletedCases[] = $row->getHubsCaseId();
				return $row;
			});

		// Each case's pekare are torn down first (findByCaseId + delete-loop).
		$deletedPekare = [];
		$this->pekareMapper->expects(self::exactly(2))
			->method('delete')
			->willReturnCallback(function (Pekare $p) use (&$deletedPekare): Pekare {
				$deletedPekare[] = $p->getHubsCaseId();
				return $p;
			});

		$result = $this->service->gallra();

		self::assertSame(2, $result['antal'], 'gallra() returnerar antal gallrade rader');
		self::assertSame(
			['caseid-aaaa-0001', 'caseid-bbbb-0002'],
			$result['hubsCaseIds'],
			'returnerar de pseudonyma hubsCaseId:n som purgades',
		);
		self::assertSame(['caseid-aaaa-0001', 'caseid-bbbb-0002'], $deletedCases, 'båda förfallna case-raderna raderas');
		self::assertSame(['caseid-aaaa-0001', 'caseid-bbbb-0002'], $deletedPekare, 'pekare raderas för varje gallrad case');
	}

	// ================================================================== //
	//  SÄKERHETSVAKT — rad UTAN gallras_datum raderas ALDRIG.
	// ================================================================== //

	public function testRowWithoutGallrasDatumIsNeverDeleted(): void {
		// retention=gallras_efter_commit + registrerad, men gallras_datum===null.
		// Utan verkställbar deadline får raden ALDRIG gallras (defense in depth även
		// om finder:n skulle släppa igenom den).
		$utanDatum = $this->makeGallringsbar('caseid-cccc-0003', null);
		$this->stubCandidates([$utanDatum]);

		$this->arendeMapper->expects(self::never())->method('delete');
		$this->pekareMapper->expects(self::never())->method('delete');

		$result = $this->service->gallra();

		self::assertSame(0, $result['antal'], 'rad utan gallras_datum bidrar inte till antalet');
	}

	// ================================================================== //
	//  SÄKERHETSVAKT — rad med FRAMTIDA deadline raderas inte ännu.
	// ================================================================== //

	public function testFutureDeadlineRowIsNotYetGallrad(): void {
		// Även om finder:n felaktigt returnerar en icke-förfallen rad, skippar
		// servicen den (vakten kollar gallrasDatum <= now oberoende av query:n).
		$framtid = $this->makeGallringsbar('caseid-dddd-0004', $this->daysFromNow(10));
		$this->stubCandidates([$framtid]);

		$this->arendeMapper->expects(self::never())->method('delete');
		$this->pekareMapper->expects(self::never())->method('delete');

		$result = $this->service->gallra();

		self::assertSame(0, $result['antal'], 'framtida deadline ⇒ ingen gallring ännu');
	}

	// ================================================================== //
	//  Tom lista → antal=0, inga delete-anrop.
	// ================================================================== //

	public function testEmptyCandidateListIsCleanNoOp(): void {
		$this->stubCandidates([]);

		$this->arendeMapper->expects(self::never())->method('delete');
		$this->pekareMapper->expects(self::never())->method('delete');

		$result = $this->service->gallra();

		self::assertSame(0, $result['antal'], 'tom lista ⇒ noll gallrade, inga delete-anrop');
		self::assertSame([], $result['hubsCaseIds']);
	}

	// ================================================================== //
	//  Helpers
	// ================================================================== //

	/** @param Arende[] $rows */
	private function stubCandidates(array $rows): void {
		$this->arendeMapper->method('findGallringsbara')->willReturn($rows);
	}

	/**
	 * A gallringsbar register row: provenans=registrerad, retention=gallras_efter_commit,
	 * with the given gallras_datum (null ⇒ no deadline ⇒ must NOT be gallrad).
	 */
	private function makeGallringsbar(string $caseId, ?\DateTime $gallrasDatum): Arende {
		$arende = new Arende();
		$arende->setHubsCaseId($caseId);
		$arende->setArendeTyp('orosanmalan');
		$arende->setCommitDestination('facksystem');
		$arende->setProvenanceState('registrerad');
		$arende->setRetentionState('gallras_efter_commit');
		$arende->setDnr('2026-IFO-' . substr($caseId, -4));
		$arende->setGallrasDatum($gallrasDatum);
		return $arende;
	}

	private function makePekare(string $caseId): Pekare {
		$p = new Pekare();
		$p->setHubsCaseId($caseId);
		$p->setObjektTyp('deck_card');
		$p->setObjektId('obj-' . substr($caseId, -4));
		return $p;
	}

	private function daysAgo(int $days): \DateTime {
		return (clone $this->now)->sub(new \DateInterval('P' . $days . 'D'));
	}

	private function daysFromNow(int $days): \DateTime {
		return (clone $this->now)->add(new \DateInterval('P' . $days . 'D'));
	}
}
