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
use OCA\HubsArende\Service\ArendeLifecycleService;
use OCA\HubsArende\Service\ArendeService;
use OCA\HubsArende\Service\ArendeTypRegistry;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Ärende-lifecycle steg-transitioner over the fixed steg-kedja
 * inflode → forhandsbedomning → utredning → beslut → uppfoljning → avslutat.
 *
 * CONTRACT (motor §lifecycle): a transition is a coordination-only state change on
 * the register row (no verksamhetsdata moves). The service:
 *   1. delegates EXISTENCE + object-level authz to {@see ArendeService::show()} (so a
 *      missing/unauthorised case surfaces as DoesNotExistException, never leaking and
 *      never re-implementing the H1 gate);
 *   2. validates the requested step against the allowed forward graph and rejects an
 *      illegal jump with \InvalidArgumentException (e.g. avslutat → utredning);
 *   3. on a legal change, setSteg() + persists via ArendeMapper::update();
 *   4. is idempotent on a same-step request: a no-op that performs no update (or an
 *      update that leaves steg unchanged).
 *
 * ── RECONCILIATION NOTES (names the parallel build may choose differently) ──
 *   * The entry point is asserted as `transitionera(string $ref, string $nyttSteg):
 *     Arende`. RECONCILE: if the build names it `transition()`, `bytSteg()`,
 *     `flyttaTillSteg()` or returns void, rename the calls below — the show()/update()
 *     interaction and the exception types are the stable contract.
 *   * Existence/authz delegation is asserted by stubbing ArendeService::show() to
 *     return the entity (happy path) or throw DoesNotExistException (which must bubble
 *     up unchanged). RECONCILE: if the build injects ArendeMapper directly and calls
 *     assertEnhetAtkomst() itself instead of going through show(), swap the show()
 *     stub for a findByCaseId()+assertEnhetAtkomst() stub — the BEHAVIOUR (missing ⇒
 *     DoesNotExistException bubbles) is what is pinned.
 *   * The illegal-transition exception is asserted as \InvalidArgumentException.
 *     RECONCILE: if the build defines a dedicated OgiltigOvergangException it should
 *     extend \InvalidArgumentException (or \DomainException) — adjust expectException
 *     accordingly; the rejected-then-no-update behaviour is the stable part.
 *   * Idempotent same-step is asserted as "update() is never called" (the simplest
 *     honouring of a no-op). RECONCILE: if the build chooses to update() anyway but
 *     leave steg unchanged, replace the never()-expectation in
 *     {@see testSameStepIsIdempotentNoOp} with an assertion that the persisted entity
 *     still carries the original steg.
 */
final class ArendeLifecycleServiceTest extends TestCase {
	private const CASE_ID = 'caseid-life-0001';

	private ArendeService&MockObject $arendeService;
	private ArendeMapper&MockObject $arendeMapper;
	private ArendeTypRegistry&MockObject $typRegistry;
	private LoggerInterface&MockObject $logger;
	private ITimeFactory&MockObject $timeFactory;

	private ArendeLifecycleService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->arendeService = $this->createMock(ArendeService::class);
		$this->arendeMapper = $this->createMock(ArendeMapper::class);
		$this->typRegistry = $this->createMock(ArendeTypRegistry::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);

		// Constructor (verifierad): arendeService (existens+authz via show), arendeMapper
		// (persist), typRegistry (per-steg-frist-uppslag), logger, timeFactory. typRegistry
		// ostubad → get() ger null → maybeRecomputeFristDue lämnar fristDue orörd.
		$this->service = new ArendeLifecycleService(
			arendeService: $this->arendeService,
			arendeMapper: $this->arendeMapper,
			typRegistry: $this->typRegistry,
			logger: $this->logger,
			timeFactory: $this->timeFactory,
		);
	}

	// ================================================================== //
	//  Giltig framåt-övergång inflode → forhandsbedomning.
	// ================================================================== //

	public function testValidForwardTransitionSetsStegAndPersists(): void {
		$arende = $this->makeArende('inflode');

		// Existence + authz delegated to show() (called exactly once).
		$this->arendeService->expects(self::once())
			->method('show')
			->with(self::CASE_ID)
			->willReturn($arende);

		// On a legal change the row is persisted with the new steg.
		$persisted = null;
		$this->arendeMapper->expects(self::once())
			->method('update')
			->willReturnCallback(function (Arende $row) use (&$persisted): Arende {
				$persisted = $row;
				return $row;
			});

		// RECONCILE: method name `transitionera`.
		$this->service->transitionera(self::CASE_ID, 'forhandsbedomning');

		self::assertNotNull($persisted, 'update() persisterar den ändrade raden');
		self::assertSame(
			'forhandsbedomning',
			$persisted->getSteg(),
			'steg är satt till det nya, giltiga steget',
		);
	}

	// ================================================================== //
	//  Ogiltig övergång avslutat → utredning → \InvalidArgumentException.
	// ================================================================== //

	public function testIllegalBackwardTransitionThrowsAndDoesNotPersist(): void {
		$arende = $this->makeArende('avslutat');

		$this->arendeService->method('show')
			->with(self::CASE_ID)
			->willReturn($arende);

		// En ogiltig övergång får ALDRIG persisteras.
		$this->arendeMapper->expects(self::never())->method('update');

		// RECONCILE: \InvalidArgumentException — eller en subklass (t.ex.
		// OgiltigOvergangException extends \InvalidArgumentException).
		$this->expectException(\InvalidArgumentException::class);

		$this->service->transitionera(self::CASE_ID, 'utredning');
	}

	// ================================================================== //
	//  Samma steg → samma → idempotent no-op (ingen/oförändrad update).
	// ================================================================== //

	public function testSameStepIsIdempotentNoOp(): void {
		$arende = $this->makeArende('utredning');

		$this->arendeService->expects(self::once())
			->method('show')
			->with(self::CASE_ID)
			->willReturn($arende);

		// Idempotent: en övergång till SAMMA steg gör ingen persistering.
		// RECONCILE: om bygget ändå anropar update() men lämnar steg oförändrat,
		// byt detta mot en assertion att persisterad entitet fortfarande har 'utredning'.
		$this->arendeMapper->expects(self::never())->method('update');

		$this->service->transitionera(self::CASE_ID, 'utredning');

		self::assertSame('utredning', $arende->getSteg(), 'steget är oförändrat');
	}

	// ================================================================== //
	//  Existens/authz delegeras till show() → DoesNotExistException bubblar.
	// ================================================================== //

	public function testMissingOrUnauthorisedCaseBubblesDoesNotExist(): void {
		// show() gatar både existens OCH H1-authz; när den kastar
		// DoesNotExistException (okänt ELLER obehörigt) ska felet bubbla upp
		// oförändrat — lifecycle-tjänsten får varken sätta steg eller persistera.
		$this->arendeService->method('show')
			->with('finns-inte')
			->willThrowException(new DoesNotExistException('inget ärende'));

		$this->arendeMapper->expects(self::never())->method('update');

		$this->expectException(DoesNotExistException::class);

		$this->service->transitionera('finns-inte', 'forhandsbedomning');
	}

	// ================================================================== //
	//  ORO-1 — pliktGrind fas-spärr (skyddsbedömning blockerar stepper).
	// ================================================================== //

	public function testPliktGrindBlocksForhandsbedomningToUtredningWithoutKvittens(): void {
		$arende = $this->makeArende('forhandsbedomning');
		$this->arendeService->method('show')->with(self::CASE_ID)->willReturn($arende);
		// pliktGrind=true-typ + ingen kvittens ⇒ utredning ska blockeras.
		$this->typRegistry->method('get')->willReturn($this->makeTyp(true));

		// Den blockerade övergången får ALDRIG persisteras.
		$this->arendeMapper->expects(self::never())->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Plikt-grind');

		$this->service->transitionera(self::CASE_ID, 'utredning');
	}

	public function testPliktGrindAllowsForhandsbedomningToUtredningWithKvittens(): void {
		$arende = $this->makeArende('forhandsbedomning');
		$this->arendeService->method('show')->with(self::CASE_ID)->willReturn($arende);
		$this->typRegistry->method('get')->willReturn($this->makeTyp(true));

		$this->arendeMapper->expects(self::once())->method('update')->willReturnArgument(0);

		// Med en kvitterad skyddsbedömning är övergången tillåten.
		$result = $this->service->transitionera(
			self::CASE_ID,
			'utredning',
			['skyddsbedomningKvitterad' => true],
		);

		self::assertSame('utredning', $result->getSteg());
	}

	public function testPliktGrindDoesNotBlockForhandsbedomningToAvslutat(): void {
		// "Inte inleda utredning" (förhandsbedömning→avslutat) är ALDRIG gateat.
		$arende = $this->makeArende('forhandsbedomning');
		$this->arendeService->method('show')->with(self::CASE_ID)->willReturn($arende);
		$this->typRegistry->method('get')->willReturn($this->makeTyp(true));

		$this->arendeMapper->expects(self::once())->method('update')->willReturnArgument(0);

		$result = $this->service->transitionera(self::CASE_ID, 'avslutat');

		self::assertSame('avslutat', $result->getSteg());
	}

	public function testNonPliktTypeIsNeverGated(): void {
		// En typ med pliktGrind=false gateas aldrig (config-driven, ingen kategori-gren).
		$arende = $this->makeArende('forhandsbedomning');
		$this->arendeService->method('show')->with(self::CASE_ID)->willReturn($arende);
		$this->typRegistry->method('get')->willReturn($this->makeTyp(false));

		$this->arendeMapper->expects(self::once())->method('update')->willReturnArgument(0);

		$result = $this->service->transitionera(self::CASE_ID, 'utredning');

		self::assertSame('utredning', $result->getSteg());
	}

	// ================================================================== //
	//  Helpers
	// ================================================================== //

	private function makeTyp(bool $pliktGrind): ArendeTyp {
		$typ = new ArendeTyp();
		$typ->setArendeTypId('orosanmalan');
		$typ->setDisplayName('orosanmalan');
		$typ->setCommitDestination('facksystem');
		$typ->setPliktGrind($pliktGrind);
		// Ingen egen-klocka fristPolicy ⇒ maybeRecomputeFristDue lämnar fristDue orörd.
		$typ->setFristPolicy(json_encode(['typ' => 'ingen']));
		return $typ;
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
}
