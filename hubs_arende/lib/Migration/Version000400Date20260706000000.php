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
 * Additive migration: PARTSREGISTRET (party register).
 *
 * Creates `hubs_arende_part` — the engine's ONLY sanctioned PII table
 * (beslut Fredrik 2026-07-06, ANALYS-HANDLING-FRAN-MALL par 3.4 +
 * KRAVSTALLNING-NAVET K-NAV-4.3). Holds the parties of a case (barn,
 * vardnadshavare, anmalare, ...) as verified against folkbokforingen
 * via NAVET — names, personnummer and addresses live HERE and nowhere
 * else in the engine: not in Handelse.detalj, not in logs, not in room
 * names. NEVER-SoR still stands: this is transient ARBETSDATA for the
 * coordination phase, the facksystem owns the permanent record.
 *
 * Retention: part rows are deleted WITH the case (gallring/purge) —
 * the whole table is personal data and must not survive the
 * coordination row (GDPR art. 5.1.e).
 *
 * Fail-closed skydd: `skydd` (ingen | sekretessmarkering |
 * skyddad_folkbokforing) is NOT NULL and deliberately has NO default —
 * every insert MUST state the protection level explicitly; an insert
 * that "forgot" to classify the person fails at the database instead
 * of silently landing as oskyddad. Vid skyddad_folkbokforing far
 * `adress` aldrig fyllas (only sarskild_postadress may be stored).
 *
 * On sikt this table also backs the ArendeMatchService
 * registerPartHook stub (party-based case matching).
 *
 * ADDITIVE + idempotent: guarded by hasTable; mirrors
 * hubs_arende_handelse's column conventions.
 *
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 */
class Version000400Date20260706000000 extends SimpleMigrationStep {
    /**
     * @param IOutput $output
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('hubs_arende_part')) {
            return null;
        }

        $table = $schema->createTable('hubs_arende_part');

        $table->addColumn('id', Types::BIGINT, [
            'autoincrement' => true,
            'notnull' => true,
        ]);
        $table->addColumn('hubs_case_id', Types::STRING, [
            'notnull' => true,
            'length' => 36,
        ]);
        // barn | vardnadshavare | anmalare | annan_part | ...
        $table->addColumn('roll', Types::STRING, [
            'notnull' => true,
            'length' => 32,
        ]);
        // Display name (fornamn + efternamn). '' until verified/entered.
        $table->addColumn('namn', Types::STRING, [
            'notnull' => true,
            'length' => 255,
            'default' => '',
        ]);
        // 12 digits AAAAMMDDNNNN; null when the party has none/unknown.
        $table->addColumn('personnummer', Types::STRING, [
            'notnull' => false,
            'length' => 16,
        ]);
        // JSON blob {"rader":[],"postnummer":"","postort":""} — the
        // folkbokforingsadress/kontaktadress. MUST stay null when
        // skydd = skyddad_folkbokforing (the real address may never
        // be stored).
        $table->addColumn('adress', Types::TEXT, [
            'notnull' => false,
        ]);
        // JSON blob, same shape — Skatteverkets forwarding address;
        // the ONLY address allowed for skyddad_folkbokforing.
        $table->addColumn('sarskild_postadress', Types::TEXT, [
            'notnull' => false,
        ]);
        // Phone/e-mail free-form contact string.
        $table->addColumn('kontakt', Types::STRING, [
            'notnull' => false,
            'length' => 255,
        ]);
        // ingen | sekretessmarkering | skyddad_folkbokforing.
        // FAIL-CLOSED: notnull WITHOUT default — every insert must set
        // the protection level explicitly; never assume "ingen".
        $table->addColumn('skydd', Types::STRING, [
            'notnull' => true,
            'length' => 32,
        ]);
        // Folkbokforingsstatus: aktiv | avregistrerad_av | avregistrerad_uv
        // | ej_i_fbf; null = not yet checked against NAVET.
        $table->addColumn('fbf_status', Types::STRING, [
            'notnull' => false,
            'length' => 32,
        ]);
        // JSON array of tidigare beteckningar (previous personnummer)
        // from NAVET — needed to match incoming handlingar that carry
        // an old identity.
        $table->addColumn('identitetshistorik', Types::TEXT, [
            'notnull' => false,
        ]);
        // Where the post came from: navet | manuell | anmalan | ...
        $table->addColumn('kalla', Types::STRING, [
            'notnull' => true,
            'length' => 16,
        ]);
        // When last verified against folkbokforingen; null = never.
        $table->addColumn('verifierad', Types::DATETIME, [
            'notnull' => false,
        ]);
        $table->addColumn('skapad', Types::DATETIME, [
            'notnull' => true,
        ]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['hubs_case_id'], 'hubs_arende_part_case');

        return $schema;
    }
}
