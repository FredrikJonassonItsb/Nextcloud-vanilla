<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Tests\Unit\Service;

use OCA\HubsArende\Service\GrindConfig;
use OCP\AppFramework\Services\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * GrindConfig — feature-flaggorna för utredningskedjans TVINGANDE grindar
 * (A7/A8/A9). KONTRAKT (beslut 2026-07-08): kod-default är AV (prod-säkert) — en
 * skarp driftmiljö får aldrig överraskande enforcement vid uppgradering; dev15
 * slår PÅ dem explicit. TRAILING OPTIONAL appConfig (positionell testharness) ⇒
 * ALLA flaggor AV.
 *
 * Detta är den enda pinne som säkrar prod-invarianten "av som default".
 */
final class GrindConfigTest extends TestCase {
	// ================================================================== //
	//  Default AV — utan appConfig (testharness) OCH med appConfig som
	//  saknar värde (getAppValueString-default '0').
	// ================================================================== //

	public function testAllaFlaggorAvUtanAppConfig(): void {
		$config = new GrindConfig(); // trailing optional utelämnat ⇒ appConfig null
		self::assertFalse($config->skyddsbedomningGrind(), 'A7 av som default');
		self::assertFalse($config->inteInledaMotiv(), 'A9a av som default');
		self::assertFalse($config->beslutDokument(), 'A9b av som default');
		self::assertFalse($config->avslutMotiv(), 'A9c av som default');
		self::assertFalse($config->autoOmprovning(), 'A8 av som default');
	}

	public function testAllaFlaggorAvNarAppConfigSaknarVarde(): void {
		// getAppValueString($flagga, '0') → '0' (osatt) ⇒ AV.
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getAppValueString')
			->willReturnCallback(fn (string $key, string $default = ''): string => $default);
		$config = new GrindConfig($appConfig);

		self::assertFalse($config->skyddsbedomningGrind());
		self::assertFalse($config->inteInledaMotiv());
		self::assertFalse($config->beslutDokument());
		self::assertFalse($config->avslutMotiv());
		self::assertFalse($config->autoOmprovning());
	}

	// ================================================================== //
	//  PÅ — endast när dev-miljön explicit satt flaggan till '1'.
	// ================================================================== //

	public function testFlaggaPaNarVardetArEtt(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getAppValueString')
			->willReturnCallback(function (string $key): string {
				return $key === GrindConfig::FLAGGA_SKYDDSBEDOMNING ? '1' : '0';
			});
		$config = new GrindConfig($appConfig);

		self::assertTrue($config->skyddsbedomningGrind(), 'satt till 1 ⇒ på');
		// De övriga är fortfarande AV (isolerade flaggor).
		self::assertFalse($config->inteInledaMotiv());
		self::assertFalse($config->beslutDokument());
		self::assertFalse($config->avslutMotiv());
		self::assertFalse($config->autoOmprovning());
	}

	public function testVarjeFlaggaLaserSinEgnaNyckel(): void {
		$pa = [
			GrindConfig::FLAGGA_INTE_INLEDA => '1',
			GrindConfig::FLAGGA_AVSLUT_MOTIV => '1',
		];
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getAppValueString')
			->willReturnCallback(fn (string $key): string => $pa[$key] ?? '0');
		$config = new GrindConfig($appConfig);

		self::assertTrue($config->inteInledaMotiv());
		self::assertTrue($config->avslutMotiv());
		self::assertFalse($config->skyddsbedomningGrind());
		self::assertFalse($config->beslutDokument());
		self::assertFalse($config->autoOmprovning());
	}

	public function testEnbartExaktEttSlarPa(): void {
		// Robusthet: bara strängen '1' ska räknas som PÅ (inte 'true'/'yes'/'0').
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getAppValueString')->willReturn('true');
		$config = new GrindConfig($appConfig);
		self::assertFalse($config->skyddsbedomningGrind(), "endast '1' ⇒ på");
	}
}
