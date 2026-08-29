<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Vidga pekarregistrets `riktning` från 32 → 255 tecken.
 *
 * `riktning` är ett generiskt sekundärfält per pekartyp (t.ex. deck_card:s boardId).
 * För `dokumentchatt`-pekaren (Collaboras dela→chatt) bär det DOKUMENTETS FILNAMN,
 * som losRum() returnerar som `fil` till boten så `!råd` kan ge dokumentanpassade
 * råd. Filnamn (t.ex. `02-omedelbar-skyddsbedomning-636663-20260712.docx`) är
 * längre än 32 tecken ⇒ 32-taket bröt registreringen (Postgres "value too long").
 * 255 rymmer filnamnen; bakåtsäkert (endast breddning, ingen dataförlust).
 *
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 */
class Version001100Date20260712120000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('hubs_arende_pekare')) {
            return null;
        }
        $table = $schema->getTable('hubs_arende_pekare');
        if (!$table->hasColumn('riktning')) {
            return null;
        }
        $col = $table->getColumn('riktning');
        if ($col->getLength() !== null && $col->getLength() < 255) {
            $col->setLength(255);
        }

        return $schema;
    }
}
