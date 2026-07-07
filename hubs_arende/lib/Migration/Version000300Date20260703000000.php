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
 * Additive migration: the case HÄNDELSEJOURNAL (event log).
 *
 * Creates `hubs_arende_handelse` — the engine's own audit trail per case, the
 * data source for the card's "Historik & beslut" timeline and the first brick
 * of the decided-but-unbuilt journal (BESLUT-19). NEVER-SoR: the journal
 * records WHAT the engine did (skapad, steg, tilldelad, medlem, registrerad,
 * rum, kopplad) — coordination state and receipts, never verksamhetsinnehåll.
 * The `detalj` column is a small JSON blob of coordination values (steg-namn,
 * dnr, roll) — ALDRIG fritext/PII.
 *
 * Retention: journal rows are deleted WITH the case (gallring/purge) — the
 * aktor_uid is personal data and must not survive the coordination row
 * (GDPR art. 5.1.e), and the facksystem owns the permanent record.
 *
 * ADDITIVE + idempotent: guarded by hasTable; mirrors hubs_arende_member's
 * column conventions.
 *
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 */
class Version000300Date20260703000000 extends SimpleMigrationStep {
    /**
     * @param IOutput $output
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('hubs_arende_handelse')) {
            return null;
        }

        $table = $schema->createTable('hubs_arende_handelse');

        $table->addColumn('id', Types::BIGINT, [
            'autoincrement' => true,
            'notnull' => true,
        ]);
        $table->addColumn('hubs_case_id', Types::STRING, [
            'notnull' => true,
            'length' => 36,
        ]);
        // skapad | steg | tilldelad | medlem | registrerad | rum | kopplad | avslutad
        $table->addColumn('typ', Types::STRING, [
            'notnull' => true,
            'length' => 32,
        ]);
        // Small JSON blob of coordination values (e.g. {"till":"utredning"},
        // {"dnr":"2026-IFO-0501"}). NEVER fritext/PII.
        $table->addColumn('detalj', Types::TEXT, [
            'notnull' => false,
        ]);
        // Who performed it; '' = system/saga context (CLI, jobs).
        $table->addColumn('aktor_uid', Types::STRING, [
            'notnull' => true,
            'length' => 64,
            'default' => '',
        ]);
        $table->addColumn('tid', Types::DATETIME, [
            'notnull' => true,
        ]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['hubs_case_id'], 'hubs_arende_hnd_case');

        return $schema;
    }
}
