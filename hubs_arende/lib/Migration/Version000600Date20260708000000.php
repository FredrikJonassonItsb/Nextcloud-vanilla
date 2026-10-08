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
 * Additiv migration: BEVAKNINGS-LIVSCYKELN — förstaklassiga, självslocknande watches.
 *
 * Se hubs_start/docs/KRAVSTALLNING-BEVAKNINGAR.md + analysen
 * analysis-output/BEVAKNING-LIVSCYKEL-ANALYS-2026-07-07.md. Ersätter den gamla
 * modellen (ETT inert Deck-kort + ETT engångs-frist-tal) med en tabell där ett
 * ärende kan ha FLERA aktiva bevakningar, var och en med eget villkor som
 * SLÄCKER den när det bevakade uppnås.
 *
 * Tre additiva ändringar (var och en guardad → idempotent):
 *   1. `hubs_arende_bevakning`   — registret (flera rader per hubs_case_id).
 *   2. `hubs_arende_case.delgivningsdatum` — delgivnings-ankaret som gör
 *      överklagandefristen (3 v efter delgivning → laga kraft) till en
 *      automatisk standardbevakning (beslut: "bygg delgivningsdatum-ankare nu").
 *   3. `hubs_arende_typ.bevakningsmallar` — datadrivna standardmallar (JSON)
 *      per ärendetyp; ersätter det oanvända perStegFrist.
 *
 * PII: bevakningsraderna bär KOORDINATIONSDATA (typ/villkor/datum) — aldrig
 * namn/pnr/sakinnehåll. Gallras ovillkorligen med ärendet (GallringService).
 *
 * Speglar kolumnkonventionerna i hubs_arende_part / hubs_arende_sakuppgift.
 *
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 */
class Version000600Date20260708000000 extends SimpleMigrationStep {
    /**
     * @param IOutput $output
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // --- 1. Registret ---------------------------------------------------
        if (!$schema->hasTable('hubs_arende_bevakning')) {
            $table = $schema->createTable('hubs_arende_bevakning');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('hubs_case_id', Types::STRING, [
                'notnull' => true,
                'length' => 36,
            ]);
            // Mall-/typ-id (t.ex. 'forhandsbedomning_14d', 'overklagande', 'manuell').
            $table->addColumn('typ', Types::STRING, [
                'notnull' => true,
                'length' => 32,
            ]);
            // Pseudonym rubrik — ALDRIG PII.
            $table->addColumn('titel', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            // Maskinläsbart villkor (Bevakning::VILLKOR_*) som släcker bevakningen.
            $table->addColumn('villkor_typ', Types::STRING, [
                'notnull' => true,
                'length' => 32,
            ]);
            // Villkorets argument (t.ex. målsteg för steg_uppnatt). Nullbart.
            $table->addColumn('villkor_arg', Types::STRING, [
                'notnull' => false,
                'length' => 128,
                'default' => null,
            ]);
            // aktiv | uppnadd | passerad | avbruten.
            $table->addColumn('status', Types::STRING, [
                'notnull' => true,
                'length' => 16,
                'default' => 'aktiv',
            ]);
            // Deadline (null = ren villkorsbevakning utan datum).
            $table->addColumn('frist_due', Types::DATE, [
                'notnull' => false,
                'default' => null,
            ]);
            // Vad fristen räknades från (Bevakning::ANKARE_*).
            $table->addColumn('ankare', Types::STRING, [
                'notnull' => true,
                'length' => 32,
                'default' => 'manuell',
            ]);
            // Cykellängd i dagar; ≠null ⇒ uppnådd föder ny post (recurring).
            $table->addColumn('recurring_dagar', Types::INTEGER, [
                'notnull' => false,
                'default' => null,
            ]);
            // Rättslig frist (styr eskalerings-/UI-ton) vs SLA/intern.
            // notnull=false: Postgres/doctrine tillåter inte Bool NOT NULL default
            // false (samma mönster som plikt_grind/diarie_plikt); insert sätter
            // alltid ett explicit värde, så null uppstår aldrig i praktiken.
            $table->addColumn('lagstadgad', Types::BOOLEAN, [
                'notnull' => false,
                'default' => false,
            ]);
            // Skaparens uid; '' = system/saga-kontext.
            $table->addColumn('skapad_av', Types::STRING, [
                'notnull' => true,
                'length' => 64,
                'default' => '',
            ]);
            $table->addColumn('uppnadd_datum', Types::DATETIME, [
                'notnull' => false,
                'default' => null,
            ]);
            $table->addColumn('uppnadd_av', Types::STRING, [
                'notnull' => false,
                'length' => 64,
                'default' => null,
            ]);
            // true = villkoret uppnåddes EFTER att fristen passerat.
            // notnull=false av samma Postgres/doctrine-skäl som lagstadgad ovan.
            $table->addColumn('forsenad', Types::BOOLEAN, [
                'notnull' => false,
                'default' => false,
            ]);
            // Deck-kortets id + board (projektion). Best-effort, kan vara null.
            $table->addColumn('deck_card_id', Types::STRING, [
                'notnull' => false,
                'length' => 64,
                'default' => null,
            ]);
            $table->addColumn('deck_board_id', Types::STRING, [
                'notnull' => false,
                'length' => 64,
                'default' => null,
            ]);
            $table->addColumn('skapad', Types::DATETIME, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['hubs_case_id'], 'hubs_arende_bev_case');
            // Hetsökningen i BevakningVarselJob/fristDue-projektionen: aktiva med frist.
            $table->addIndex(['status', 'frist_due'], 'hubs_arende_bev_frist');
        }

        // --- 2. Delgivnings-ankaret på registret ----------------------------
        if ($schema->hasTable('hubs_arende_case')) {
            $case = $schema->getTable('hubs_arende_case');
            if (!$case->hasColumn('delgivningsdatum')) {
                $case->addColumn('delgivningsdatum', Types::DATE, [
                    'notnull' => false,
                    'default' => null,
                ]);
            }
        }

        // --- 3. Datadrivna standardmallar på ärendetypen --------------------
        if ($schema->hasTable('hubs_arende_typ')) {
            $typ = $schema->getTable('hubs_arende_typ');
            if (!$typ->hasColumn('bevakningsmallar')) {
                // JSON-array [{typ,titel,villkorTyp,villkorArg,ankareDagar,ankare,
                // recurringDagar,lagstadgad,vidSteg}]. Null = inga standardmallar.
                $typ->addColumn('bevakningsmallar', Types::TEXT, [
                    'notnull' => false,
                    'default' => null,
                ]);
            }
        }

        return $schema;
    }
}
