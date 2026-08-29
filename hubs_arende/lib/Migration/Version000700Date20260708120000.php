<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Additiv migration: UTREDNINGSKEDJANS EVIDENS-LAGER (A8).
 *
 * `hubs_arende_typ.omprovningskrav` — flaggar ärendetyper vars vård kräver
 * lagstadgad omprövning/övervägande var 6:e månad (LVU 13 §, SoL övervägande).
 * true ⇒ BevakningService skapar omprövningsbevakningen AUTOMATISKT vid inträde
 * i uppföljning (A8), i stället för att förlita sig på att handläggaren råkar
 * skapa den. Seedas true för rattsligt_tvang + orosanmalan (ArendeTypRegistry).
 *
 * BOOLEAN notnull=false (Postgres/doctrine tillåter inte Bool NOT NULL default
 * false — samma mönster som plikt_grind/lagstadgad). Guardad → idempotent.
 *
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 */
class Version000700Date20260708120000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('hubs_arende_typ')) {
            $typ = $schema->getTable('hubs_arende_typ');
            if (!$typ->hasColumn('omprovningskrav')) {
                $typ->addColumn('omprovningskrav', Types::BOOLEAN, [
                    'notnull' => false,
                    'default' => false,
                ]);
            }
        }

        return $schema;
    }
}
