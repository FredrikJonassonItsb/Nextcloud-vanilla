<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Migration;

use OCA\HubsArende\Service\ArendeTypRegistry;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Post-migration repair step that idempotently seeds the 8 default ärendetyper
 * into hubs_arende_typ. Runs on app install/upgrade (and `occ maintenance:repair`).
 *
 * seedDefaults() inserts a row only when its id is absent, so a kommun's local
 * edits to the registry are preserved across upgrades.
 */
class RegisterArendeTyper implements IRepairStep {
	public function __construct(
		private ArendeTypRegistry $registry,
	) {
	}

	public function getName(): string {
		return 'hubs_arende: seed default ärendetyper (hubs_arende_typ)';
	}

	public function run(IOutput $output): void {
		$inserted = $this->registry->seedDefaults();
		$output->info(sprintf('hubs_arende: seedade %d ärendetyper.', $inserted));
		// Patcha bevakningsmallar på redan befintliga typrader (kolumnen är ny) —
		// annars får gamla orosanmalan-rader aldrig sin 14d→4mån-kedja.
		$patchade = $this->registry->synkaBevakningsmallar();
		$output->info(sprintf('hubs_arende: patchade bevakningsmallar på %d typrader.', $patchade));
		// A8 — patcha omprovningskrav på befintliga typrader (kolumnen är ny) så
		// LVU/orosanmälan får automatisk lagstadgad omprövningsbevakning.
		$omprovning = $this->registry->synkaOmprovningskrav();
		$output->info(sprintf('hubs_arende: patchade omprovningskrav på %d typrader.', $omprovning));
	}
}
