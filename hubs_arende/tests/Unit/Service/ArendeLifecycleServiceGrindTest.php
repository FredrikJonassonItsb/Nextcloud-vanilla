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
use OCA\HubsArende\Db\Handelse;
use OCA\HubsArende\Db\HandelseMapper;
use OCA\HubsArende\Service\ArendeLifecycleService;
use OCA\HubsArende\Service\ArendeService;
use OCA\HubsArende\Service\ArendeTypRegistry;
use OCA\HubsArende\Service\EvidensService;
use OCA\HubsArende\Service\GrindConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * UTREDNINGSKEDJANS GRINDAR (A7/A9) i ArendeLifecycleService::transitionera().
 *
 * Detta test pinnar det CONFIG-GATADE, tvingande beteendet (GrindConfig + Evidens-
 * Service mockas) — kompletterar det befintliga {@see ArendeLifecycleServiceTest}
 * som täcker det GAMLA klient-boolean-beteendet med flaggan AV.
 *
 * KONTRAKT som verifieras:
 *  - A7 hård skyddsbedömnings-existens-grind (forhandsbedomning→utredning):
 *      * blockerar (\InvalidArgumentException, ingen update) utan artefakt OCH utan
 *        override-skäl,
 *      * SLÄPPER + journalför TYP_GRINDVAL {grind:skyddsbedomning,val:override} när
 *        override-skäl ges trots saknad artefakt,
 *      * SLÄPPER + journalför {val:godkand} när EvidensService hittar artefakten.
 *  - A9a inte-inleda-motiv (forhandsbedomning→avslutat): kräver orsak; journalför.
 *  - A9b kommunicerings-checkpoint (utredning→beslut): kräver gjord/skäl; journalför.
 *  - A9c avslutsmotiv (utredning→avslutat): kräver utfall; journalför.
 *  - ALLA flaggor AV ⇒ gammalt beteende oförändrat (A7 faller till klient-boolean,
 *    A9a/b/c släpper utan motiv och journalför INGET grindval).
 *
 * Grindarna kastar FÖRE setSteg ⇒ en blockerad övergång persisteras ALDRIG.
 */
final class ArendeLifecycleServiceGrindTest extends TestCase {
	private const CASE_ID = 'caseid-grind-0001';

	private ArendeService&MockObject $arendeService;
	private ArendeMapper&MockObject $arendeMapper;
	private ArendeTypRegistry&MockObject $typRegistry;
	private LoggerInterface&MockObject $logger;
	private ITimeFactory&MockObject $timeFactory;
	private HandelseMapper&MockObject $handelseMapper;
	private GrindConfig&MockObject $grindConfig;
	private EvidensService&MockObject $evidensService;

	/** @var array<int,array{0:string,1:string,2:array<string,mixed>}> Alla record()-anrop (typ,detalj). */
	private array $journal = [];

	protected function setUp(): void {
		parent::setUp();
		$this->arendeService = $this->createMock(ArendeService::class);
		$this->arendeMapper = $this->createMock(ArendeMapper::class);
		$this->typRegistry = $this->createMock(ArendeTypRegistry::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->handelseMapper = $this->createMock(HandelseMapper::class);
		$this->grindConfig = $this->createMock(GrindConfig::class);
		$this->evidensService = $this->createMock(EvidensService::class);

		$this->timeFactory->method('getDateTime')->willReturnCallback(
			fn (): \DateTime => new \DateTime('2026-07-08 09:00:00'),
		);
		$this->arendeMapper->method('update')->willReturnArgument(0);
		// Re-hämtning efter bevakningsnollställning är inte wirad (bevakningService null).

		// transitionera journalför BÅDE ev. grindval OCH steg-övergången (TYP_STEG) via
		// samma record(). Samla alla anrop så vi kan assertera på grindval-posten separat
		// utan att kollidera med den ovillkorliga TYP_STEG-posten.
		$this->handelseMapper->method('record')->willReturnCallback(
			function (string $caseId, string $typ, array $detalj = [], string $aktorUid = ''): Handelse {
				$this->journal[] = [$caseId, $typ, $detalj];
				return new Handelse();
			},
		);
	}

	/** Alla journalförda grindval (TYP_GRINDVAL) i anropsordning. @return array<int,array<string,mixed>> */
	private function grindval(): array {
		return array_map(
			static fn (array $rad): array => $rad[2],
			array_values(array_filter($this->journal, static fn (array $rad): bool => $rad[1] === Handelse::TYP_GRINDVAL)),
		);
	}

	/** Bygg tjänsten med aktuella mockar (GrindConfig/EvidensService injiceras). */
	private function makeService(): ArendeLifecycleService {
		return new ArendeLifecycleService(
			arendeService: $this->arendeService,
			arendeMapper: $this->arendeMapper,
			typRegistry: $this->typRegistry,
			logger: $this->logger,
			timeFactory: $this->timeFactory,
			handelseMapper: $this->handelseMapper,
			userSession: null,
			bevakningService: null,
			grindConfig: $this->grindConfig,
			evidensService: $this->evidensService,
			commitService: null,
		);
	}

	// ================================================================== //
	//  A7 — hård skyddsbedömnings-existens-grind (flagga PÅ).
	// ================================================================== //

	public function testA7BlockerarUtanArtefaktOchUtanOverride(): void {
		$this->arendeService->method('show')->willReturn($this->makeArende('forhandsbedomning'));
		$this->typRegistry->method('get')->willReturn($this->makeTyp(true));
		$this->grindConfig->method('skyddsbedomningGrind')->willReturn(true);
		$this->evidensService->method('harArtefakt')
			->with(self::CASE_ID, 'skyddsbedomning')->willReturn(false);

		// Blockerad övergång: ingen persistering (kastar FÖRE setSteg).
		$this->arendeMapper->expects(self::never())->method('update');

		try {
			$this->makeService()->transitionera(self::CASE_ID, 'utredning');
			self::fail('grinden borde ha kastat');
		} catch (\InvalidArgumentException $e) {
			self::assertStringContainsString('skyddsbedömning', $e->getMessage());
		}
		self::assertSame([], $this->journal, 'inget journalförs på en blockerad övergång');
	}

	public function testA7SlapperMedOverrideOchJournalforOverride(): void {
		$this->arendeService->method('show')->willReturn($this->makeArende('forhandsbedomning'));
		$this->typRegistry->method('get')->willReturn($this->makeTyp(true));
		$this->grindConfig->method('skyddsbedomningGrind')->willReturn(true);
		$this->evidensService->method('harArtefakt')->willReturn(false);

		$this->arendeMapper->expects(self::once())->method('update')->willReturnArgument(0);

		$result = $this->makeService()->transitionera(
			self::CASE_ID,
			'utredning',
			['override' => ['skal' => 'gjord_i_facksystem']],
		);
		self::assertSame('utredning', $result->getSteg());

		// TYP_GRINDVAL {grind:skyddsbedomning,val:override,skal:...} journalförs.
		$gv = $this->grindval();
		self::assertCount(1, $gv);
		self::assertSame('skyddsbedomning', $gv[0]['grind']);
		self::assertSame('override', $gv[0]['val']);
		self::assertSame('gjord_i_facksystem', $gv[0]['skal']);
	}

	public function testA7SlapperNarArtefaktFinnsOchJournalforGodkand(): void {
		$this->arendeService->method('show')->willReturn($this->makeArende('forhandsbedomning'));
		$this->typRegistry->method('get')->willReturn($this->makeTyp(true));
		$this->grindConfig->method('skyddsbedomningGrind')->willReturn(true);
		$this->evidensService->method('harArtefakt')
			->with(self::CASE_ID, 'skyddsbedomning')->willReturn(true);

		$this->arendeMapper->expects(self::once())->method('update')->willReturnArgument(0);

		$result = $this->makeService()->transitionera(self::CASE_ID, 'utredning');
		self::assertSame('utredning', $result->getSteg());

		$gv = $this->grindval();
		self::assertCount(1, $gv);
		self::assertSame('skyddsbedomning', $gv[0]['grind']);
		self::assertSame('godkand', $gv[0]['val']);
	}

	// ================================================================== //
	//  A9a — inte-inleda-motiv (forhandsbedomning→avslutat, flagga PÅ).
	// ================================================================== //

	public function testA9aKraverOrsakVidInteInleda(): void {
		$this->arendeService->method('show')->willReturn($this->makeArende('forhandsbedomning'));
		$this->typRegistry->method('get')->willReturn($this->makeTyp(true));
		$this->grindConfig->method('inteInledaMotiv')->willReturn(true);

		$this->arendeMapper->expects(self::never())->method('update');

		try {
			$this->makeService()->transitionera(self::CASE_ID, 'avslutat');
			self::fail('grinden borde ha kastat');
		} catch (\InvalidArgumentException $e) {
			self::assertStringContainsString('orsak', $e->getMessage());
		}
		self::assertSame([], $this->journal);
	}

	public function testA9aSlapperMedOrsakOchJournalfor(): void {
		$this->arendeService->method('show')->willReturn($this->makeArende('forhandsbedomning'));
		$this->typRegistry->method('get')->willReturn($this->makeTyp(true));
		$this->grindConfig->method('inteInledaMotiv')->willReturn(true);

		$this->arendeMapper->expects(self::once())->method('update')->willReturnArgument(0);

		$result = $this->makeService()->transitionera(
			self::CASE_ID,
			'avslutat',
			['inteInledaVal' => ['orsak' => 'ingen_grund', 'beslutsfattare' => 'chef1']],
		);
		self::assertSame('avslutat', $result->getSteg());

		$gv = $this->grindval();
		self::assertCount(1, $gv);
		self::assertSame('inte_inleda', $gv[0]['grind']);
		self::assertSame('vald', $gv[0]['val']);
		self::assertSame('ingen_grund', $gv[0]['orsak']);
	}

	// ================================================================== //
	//  A9b — kommunicerings-checkpoint (utredning→beslut, flagga PÅ).
	// ================================================================== //

	public function testA9bKraverKommuniceringNarArtefaktSaknas(): void {
		$this->arendeService->method('show')->willReturn($this->makeArende('utredning'));
		$this->typRegistry->method('get')->willReturn($this->makeTyp(true));
		$this->grindConfig->method('beslutDokument')->willReturn(true);
		$this->evidensService->method('harArtefakt')
			->with(self::CASE_ID, 'kommunicering')->willReturn(false);

		$this->arendeMapper->expects(self::never())->method('update');

		try {
			$this->makeService()->transitionera(self::CASE_ID, 'beslut');
			self::fail('grinden borde ha kastat');
		} catch (\InvalidArgumentException $e) {
			self::assertStringContainsString('Kommunicering', $e->getMessage());
		}
		self::assertSame([], $this->journal);
	}

	public function testA9bSlapperMedOverrideSkalOchJournalfor(): void {
		$this->arendeService->method('show')->willReturn($this->makeArende('utredning'));
		$this->typRegistry->method('get')->willReturn($this->makeTyp(true));
		$this->grindConfig->method('beslutDokument')->willReturn(true);
		$this->evidensService->method('harArtefakt')->willReturn(false);

		$this->arendeMapper->expects(self::once())->method('update')->willReturnArgument(0);

		$result = $this->makeService()->transitionera(
			self::CASE_ID,
			'beslut',
			['kommuniceringVal' => ['gjord' => false, 'skal' => 'sker_i_beslut']],
		);
		self::assertSame('beslut', $result->getSteg());

		$gv = $this->grindval();
		self::assertCount(1, $gv);
		self::assertSame('kommunicering', $gv[0]['grind']);
		self::assertSame('override', $gv[0]['val']);
		self::assertSame('sker_i_beslut', $gv[0]['skal']);
	}

	public function testA9bSlapperNarKommuniceringFinnsUtanVal(): void {
		// Artefakt finns ⇒ ingen fråga, inget grindval journalförs (bara steg-övergången).
		$this->arendeService->method('show')->willReturn($this->makeArende('utredning'));
		$this->typRegistry->method('get')->willReturn($this->makeTyp(true));
		$this->grindConfig->method('beslutDokument')->willReturn(true);
		$this->evidensService->method('harArtefakt')
			->with(self::CASE_ID, 'kommunicering')->willReturn(true);

		$this->arendeMapper->expects(self::once())->method('update')->willReturnArgument(0);

		$result = $this->makeService()->transitionera(self::CASE_ID, 'beslut');
		self::assertSame('beslut', $result->getSteg());
		self::assertSame([], $this->grindval(), 'inget grindval när kommunicering redan finns');
	}

	// ================================================================== //
	//  A9c — avslutsmotiv (utredning→avslutat, flagga PÅ).
	// ================================================================== //

	public function testA9cKraverUtfallVidAvslut(): void {
		$this->arendeService->method('show')->willReturn($this->makeArende('utredning'));
		$this->typRegistry->method('get')->willReturn($this->makeTyp(true));
		$this->grindConfig->method('avslutMotiv')->willReturn(true);

		$this->arendeMapper->expects(self::never())->method('update');

		try {
			$this->makeService()->transitionera(self::CASE_ID, 'avslutat');
			self::fail('grinden borde ha kastat');
		} catch (\InvalidArgumentException $e) {
			self::assertStringContainsString('utfall', $e->getMessage());
		}
		self::assertSame([], $this->journal);
	}

	public function testA9cSlapperMedUtfallOchJournalfor(): void {
		$this->arendeService->method('show')->willReturn($this->makeArende('utredning'));
		$this->typRegistry->method('get')->willReturn($this->makeTyp(true));
		$this->grindConfig->method('avslutMotiv')->willReturn(true);

		$this->arendeMapper->expects(self::once())->method('update')->willReturnArgument(0);

		$result = $this->makeService()->transitionera(
			self::CASE_ID,
			'avslutat',
			['avslutsmotiv' => ['utfall' => 'behov_tillgodosett', 'kvarstaende' => true]],
		);
		self::assertSame('avslutat', $result->getSteg());

		$gv = $this->grindval();
		self::assertCount(1, $gv);
		self::assertSame('avslut', $gv[0]['grind']);
		self::assertSame('vald', $gv[0]['val']);
		self::assertSame('behov_tillgodosett', $gv[0]['utfall']);
		self::assertTrue($gv[0]['kvarstaende']);
	}

	// ================================================================== //
	//  ALLA FLAGGOR AV ⇒ gammalt beteende oförändrat.
	// ================================================================== //

	public function testAllaFlaggorAvA7FallerTillKlientBoolean(): void {
		// Flagga AV: A7 kräver den gamla klient-booleanen skyddsbedomningKvitterad.
		$this->slaAvAllaFlaggor();
		$this->arendeService->method('show')->willReturn($this->makeArende('forhandsbedomning'));
		$this->typRegistry->method('get')->willReturn($this->makeTyp(true));
		// EvidensService ska ALDRIG konsulteras när flaggan är av.
		$this->evidensService->expects(self::never())->method('harArtefakt');

		$this->arendeMapper->expects(self::never())->method('update');

		try {
			$this->makeService()->transitionera(self::CASE_ID, 'utredning'); // ingen klient-boolean
			self::fail('grinden borde ha kastat');
		} catch (\InvalidArgumentException $e) {
			self::assertStringContainsString('kvitteras', $e->getMessage());
		}
		self::assertSame([], $this->journal);
	}

	public function testAllaFlaggorAvA7SlapperMedKlientBoolean(): void {
		$this->slaAvAllaFlaggor();
		$this->arendeService->method('show')->willReturn($this->makeArende('forhandsbedomning'));
		$this->typRegistry->method('get')->willReturn($this->makeTyp(true));

		$this->arendeMapper->expects(self::once())->method('update')->willReturnArgument(0);

		$result = $this->makeService()->transitionera(
			self::CASE_ID,
			'utredning',
			['skyddsbedomningKvitterad' => true],
		);
		self::assertSame('utredning', $result->getSteg());
	}

	public function testAllaFlaggorAvA9cAvslutSlapperUtanMotiv(): void {
		// Flagga AV: avslut kräver INGET utfall och journalför INGET grindval.
		$this->slaAvAllaFlaggor();
		$this->arendeService->method('show')->willReturn($this->makeArende('utredning'));
		$this->typRegistry->method('get')->willReturn($this->makeTyp(true));

		$this->arendeMapper->expects(self::once())->method('update')->willReturnArgument(0);

		$result = $this->makeService()->transitionera(self::CASE_ID, 'avslutat');
		self::assertSame('avslutat', $result->getSteg());
		self::assertSame([], $this->grindval(), 'flagga av ⇒ inget grindval');
	}

	// ================================================================== //
	//  Helpers
	// ================================================================== //

	private function slaAvAllaFlaggor(): void {
		$this->grindConfig->method('skyddsbedomningGrind')->willReturn(false);
		$this->grindConfig->method('inteInledaMotiv')->willReturn(false);
		$this->grindConfig->method('beslutDokument')->willReturn(false);
		$this->grindConfig->method('avslutMotiv')->willReturn(false);
		$this->grindConfig->method('autoOmprovning')->willReturn(false);
	}

	private function makeTyp(bool $pliktGrind): ArendeTyp {
		$typ = new ArendeTyp();
		$typ->setArendeTypId('orosanmalan');
		$typ->setDisplayName('Orosanmälan');
		$typ->setCommitDestination('facksystem');
		$typ->setPliktGrind($pliktGrind);
		// Ingen egen-klocka ⇒ maybeRecomputeFristDue lämnar fristDue orörd.
		$typ->setFristPolicy(json_encode(['typ' => 'ingen']));
		return $typ;
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
