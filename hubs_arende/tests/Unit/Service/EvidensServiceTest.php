<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Tests\Unit\Service;

use OCA\HubsArende\Db\Handelse;
use OCA\HubsArende\Db\HandelseMapper;
use OCA\HubsArende\Service\DokumenttypRegistry;
use OCA\HubsArende\Service\EvidensService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * EvidensService — bryter den cirkulära plikt-härledningen (GAP-U1): en artefakt
 * anses producerad när en VERKLIG handling ur mall finns i journalen (detalj.mall
 * matchar klassens nyckelord), och ett moment kvitterat via TYP_KVITTENS ELLER ett
 * grindval (godkand/override) för samma moment.
 *
 * Rena enhetstester: HandelseMapper mockas, Handelse-entiteterna är riktiga
 * (getDetalj() returnerar JSON-strängen precis som ur DB). Nyckelordsmatchningen
 * ska SPEGLA frontendens arendeFlow.js harledStatus.
 */
final class EvidensServiceTest extends TestCase {
	private const CASE_ID = 'caseid-evi-00000001';

	private HandelseMapper&MockObject $handelseMapper;
	private LoggerInterface&MockObject $logger;
	private EvidensService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->handelseMapper = $this->createMock(HandelseMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->service = new EvidensService($this->handelseMapper, $this->logger);
	}

	// ================================================================== //
	//  harArtefakt — nyckelordsmatchning mot journalens detalj.mall.
	// ================================================================== //

	public function testHarArtefaktSantNarHandlingMatcharKlassnyckelord(): void {
		$this->stubbaJournal([
			$this->handling('bbic-skyddsbedomning-barn'),
		]);
		self::assertTrue($this->service->harArtefakt(self::CASE_ID, 'skyddsbedomning'));
	}

	public function testHarArtefaktFalsktNarIngenHandlingMatchar(): void {
		$this->stubbaJournal([
			$this->handling('utredningsplan-barn'),
		]);
		self::assertFalse($this->service->harArtefakt(self::CASE_ID, 'skyddsbedomning'));
	}

	public function testHarArtefaktMatcharSkiftlagesokantSubstring(): void {
		$this->stubbaJournal([
			$this->handling('BBIC-Utredning-2026'),
		]);
		self::assertTrue($this->service->harArtefakt(self::CASE_ID, 'bbic-utredning'));
	}

	public function testHarArtefaktIgnorerarIckeHandlingHandelser(): void {
		// Ett steg-byte eller grindval är INGEN producerad artefakt.
		$this->stubbaJournal([
			$this->handelse(Handelse::TYP_STEG, ['fran' => 'forhandsbedomning', 'till' => 'utredning']),
			$this->handelse(Handelse::TYP_GRINDVAL, ['grind' => 'skyddsbedomning', 'val' => 'override']),
		]);
		self::assertFalse($this->service->harArtefakt(self::CASE_ID, 'skyddsbedomning'));
	}

	public function testHarArtefaktHopparOverHandlingUtanMall(): void {
		$this->stubbaJournal([
			$this->handelse(Handelse::TYP_HANDLING, ['handling' => 'skapad']), // ingen mall-nyckel
		]);
		self::assertFalse($this->service->harArtefakt(self::CASE_ID, 'skyddsbedomning'));
	}

	public function testHarArtefaktFallOpenVidLasfel(): void {
		// En journal-läsning som kraschar får ALDRIG låsa handläggaren ute.
		$this->handelseMapper->method('findByCaseId')
			->willThrowException(new \RuntimeException('db nere'));
		self::assertTrue(
			$this->service->harArtefakt(self::CASE_ID, 'skyddsbedomning'),
			'fail-open: läsfel ⇒ artefakt anses finnas (grinden degraderar)',
		);
	}

	// ================================================================== //
	//  harArtefakt — KANONISK dokumenttyp (T4-rotfix). Med registret injicerat
	//  matchar grinden på den STÄMPLADE detalj.dokumenttyp; legacy-rader utan
	//  stämpel faller tillbaka på nyckelord.
	// ================================================================== //

	public function testHarArtefaktKanoniskDokumenttypBarnetsRost(): void {
		// Buggklassen: mallen "08-barnets-installning-och-delaktighet" innehåller
		// aldrig "barnsamtal", så nyckelordsmatchningen kunde ALDRIG bli sann. Med
		// stämplad dokumenttyp='barnsamtal' uppfylls klassen.
		$service = new EvidensService($this->handelseMapper, $this->logger, new DokumenttypRegistry());
		$this->stubbaJournal([
			$this->handlingTyp('barnsamtal', '08-barnets-installning-och-delaktighet'),
		]);
		self::assertTrue($service->harArtefakt(self::CASE_ID, 'barnsamtal'));
	}

	public function testHarArtefaktStampladAnnanTypRaknasInte(): void {
		// En stämplad rad är auktoritativ: fel dokumenttyp räknas inte, även om
		// mall-sluggen råkar innehålla klassens nyckelord.
		$service = new EvidensService($this->handelseMapper, $this->logger, new DokumenttypRegistry());
		$this->stubbaJournal([
			$this->handlingTyp('bbic-utredning', 'utredning-med-skyddsbedomning-i-namnet'),
		]);
		self::assertFalse($service->harArtefakt(self::CASE_ID, 'skyddsbedomning'));
	}

	public function testHarArtefaktLegacyRadUtanStampelFallerTillbakaPaNyckelord(): void {
		// Äldre journalrad (ingen dokumenttyp-stämpel) matchas som förr via mall-nyckelord.
		$service = new EvidensService($this->handelseMapper, $this->logger, new DokumenttypRegistry());
		$this->stubbaJournal([
			$this->handling('02-omedelbar-skyddsbedomning'),
		]);
		self::assertTrue($service->harArtefakt(self::CASE_ID, 'skyddsbedomning'));
	}

	// ================================================================== //
	//  harKvittens — TYP_KVITTENS.moment ELLER TYP_GRINDVAL.grind.
	// ================================================================== //

	public function testHarKvittensSantViaTypKvittens(): void {
		$this->stubbaJournal([
			$this->handelse(Handelse::TYP_KVITTENS, ['moment' => 'skyddsbedomning']),
		]);
		self::assertTrue($this->service->harKvittens(self::CASE_ID, 'skyddsbedomning'));
	}

	public function testHarKvittensSantViaGrindvalForSammaMoment(): void {
		$this->stubbaJournal([
			$this->handelse(Handelse::TYP_GRINDVAL, ['grind' => 'skyddsbedomning', 'val' => 'godkand']),
		]);
		self::assertTrue($this->service->harKvittens(self::CASE_ID, 'skyddsbedomning'));
	}

	public function testHarKvittensFalsktForAnnatMoment(): void {
		$this->stubbaJournal([
			$this->handelse(Handelse::TYP_KVITTENS, ['moment' => 'kommunicering']),
			$this->handelse(Handelse::TYP_GRINDVAL, ['grind' => 'avslut', 'val' => 'vald']),
		]);
		self::assertFalse($this->service->harKvittens(self::CASE_ID, 'skyddsbedomning'));
	}

	public function testHarKvittensIgnorerarHandlingMedSammaNyckelord(): void {
		// En handling är ingen KVITTENS — harKvittens tittar bara på kvittens/grindval.
		$this->stubbaJournal([
			$this->handling('skyddsbedomning-akut'),
		]);
		self::assertFalse($this->service->harKvittens(self::CASE_ID, 'skyddsbedomning'));
	}

	public function testHarKvittensFallOpenVidLasfel(): void {
		$this->handelseMapper->method('findByCaseId')
			->willThrowException(new \RuntimeException('db nere'));
		self::assertTrue($this->service->harKvittens(self::CASE_ID, 'skyddsbedomning'));
	}

	// ================================================================== //
	//  Helpers
	// ================================================================== //

	/** @param Handelse[] $rader */
	private function stubbaJournal(array $rader): void {
		$this->handelseMapper->method('findByCaseId')->willReturn($rader);
	}

	private function handling(string $mall): Handelse {
		return $this->handelse(Handelse::TYP_HANDLING, ['handling' => 'skapad', 'mall' => $mall]);
	}

	/** En handling med STÄMPLAD kanonisk dokumenttyp (som HandlingService skriver). */
	private function handlingTyp(string $dokumenttyp, string $mall): Handelse {
		return $this->handelse(Handelse::TYP_HANDLING, ['handling' => 'skapad', 'mall' => $mall, 'dokumenttyp' => $dokumenttyp]);
	}

	/** @param array<string,mixed> $detalj */
	private function handelse(string $typ, array $detalj): Handelse {
		$h = new Handelse();
		$h->setHubsCaseId(self::CASE_ID);
		$h->setTyp($typ);
		$h->setDetalj(json_encode($detalj));
		$h->setAktorUid('');
		$h->setTid(new \DateTime('2026-07-08 09:00:00'));
		return $h;
	}
}
