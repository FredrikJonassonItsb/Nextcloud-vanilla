<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Migration;

use OCA\HubsArende\Db\ArendeMapper;
use OCA\HubsArende\Service\BevakningService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Post-migration repair: migrera BEFINTLIGA ärenden in i det nya bevaknings-
 * registret. Innan denna modell bar ärendet EN engångs-frist (register.fristDue);
 * nu är fristen en förstaklassig, kvitterbar bevakning. För varje ärende som
 * saknar bevakningar men har en fristDue skapas EN bevakning som speglar den
 * (BevakningService::backfillForCase — lean, inget Deck-kort).
 *
 * Idempotent: ett ärende som redan har bevakningar (nytt, eller redan migrerat)
 * hoppas över. Best-effort — ett fel på ett ärende stoppar inte de övriga.
 */
class BackfillBevakningar implements IRepairStep {
    /** Sidstorlek vid iteration över registret. */
    private const BATCH = 1000;

    public function __construct(
        private ArendeMapper $arendeMapper,
        private BevakningService $bevakningService,
    ) {
    }

    public function getName(): string {
        return 'hubs_arende: backfilla bevakningar för befintliga ärenden';
    }

    public function run(IOutput $output): void {
        $skapade = 0;
        $granskade = 0;
        // findAll(limit) — dev/kommun-skala ryms i en batch; höj vid behov.
        foreach ($this->arendeMapper->findAll(self::BATCH) as $arende) {
            $granskade++;
            if ($this->bevakningService->backfillForCase($arende)) {
                $skapade++;
            }
        }
        $output->info(sprintf(
            'hubs_arende: bevaknings-backfill klar (%d ärenden granskade, %d bevakningar skapade).',
            $granskade,
            $skapade,
        ));
    }
}
