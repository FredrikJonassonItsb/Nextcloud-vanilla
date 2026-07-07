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
 * Additiv migration: SAKUPPGIFTSLAGRET — dokumentkedjans minne.
 *
 * Skapar `hubs_arende_sakuppgift`: per-ärende-nyckel/värde-lager med de
 * sakuppgifter handläggaren BEKRÄFTAT i förhandsdialogen när en handling
 * skapades (ANALYS-FORIFYLLNAD-FALTKARTLAGGNING.md §4). Bekräftelsen i
 * dokument N blir förifyllnadskälla i dokument N+1 — samma uppgift skrivs
 * aldrig av två gånger.
 *
 * ANSVARSGRÄNSEN: här lagras endast FAKTA/HÄRLEDDA sakuppgifter som en
 * människa aktivt bekräftat (bekraftad_av + tidpunkt + ursprungsdokument =
 * spårbarheten). Ett fattat besluts UTFALL får refereras bakåt som faktum —
 * men bedömningar förifylls aldrig framåt.
 *
 * PII: värdena kan bära personuppgifter (som partsregistret) — samma regler:
 * aldrig i loggar/journal-detalj, gallras OVILLKORLIGEN med ärendet
 * (GallringService), NEVER-SoR består (slutversionen bor i facksystemet;
 * detta är transient arbetsminne).
 *
 * ADDITIV + idempotent: guarded av hasTable; speglar kolumnkonventionerna i
 * hubs_arende_part (Version000400).
 *
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 */
class Version000500Date20260707000000 extends SimpleMigrationStep {
    /**
     * @param IOutput $output
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('hubs_arende_sakuppgift')) {
            return null;
        }

        $table = $schema->createTable('hubs_arende_sakuppgift');

        $table->addColumn('id', Types::BIGINT, [
            'autoincrement' => true,
            'notnull' => true,
        ]);
        $table->addColumn('hubs_case_id', Types::STRING, [
            'notnull' => true,
            'length' => 36,
        ]);
        // Fältnyckel ur ArendedataService-vokabulären (t.ex. 'barnNamn',
        // 'inkomDatum', 'anmalareNamn'). Senaste bekräftelsen vinner (upsert).
        $table->addColumn('nyckel', Types::STRING, [
            'notnull' => true,
            'length' => 64,
        ]);
        // Det bekräftade värdet. Kan bära PII — samma regler som hubs_arende_part.
        $table->addColumn('varde', Types::TEXT, [
            'notnull' => true,
        ]);
        // Varifrån värdet ursprungligen kom (register|partsregister|anvandare|
        // journal|handlaggare|akten_tidigare_handling) — spårbarhet i källkedjan.
        $table->addColumn('kalla', Types::STRING, [
            'notnull' => true,
            'length' => 32,
        ]);
        // Ursprungsdokumentet (mall-slug) där bekräftelsen gjordes.
        $table->addColumn('ursprung', Types::STRING, [
            'notnull' => true,
            'length' => 128,
        ]);
        // Vem som bekräftade (handläggarens uid; '' = system/saga-kontext).
        $table->addColumn('bekraftad_av', Types::STRING, [
            'notnull' => true,
            'length' => 64,
            'default' => '',
        ]);
        $table->addColumn('bekraftad', Types::DATETIME, [
            'notnull' => true,
        ]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['hubs_case_id'], 'hubs_arende_sak_case');
        // Upsert-nyckeln (case, nyckel) — UNIQUE så senaste bekräftelsen
        // uppdaterar i stället för att duplicera (mapper-upsert med race-fång).
        $table->addUniqueIndex(['hubs_case_id', 'nyckel'], 'hubs_arende_sak_uq');

        return $schema;
    }
}
