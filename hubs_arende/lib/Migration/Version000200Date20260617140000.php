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
 * Additive migration: the ärenderum's first-class MEMBER ledger.
 *
 * Creates `hubs_arende_member` — the table that makes "who is in the case room"
 * an enumerable property of the engine (decision: förstaklassigt medlemskap).
 * Previously membership was only PROJECTED onto Talk participants / groupfolder
 * ACLs and never stored, so the engine could not list a room's users without
 * querying the external apps; this table fixes that.
 *
 * ADDITIVE + idempotent: guarded by hasTable, never touches the original
 * Version000000 / hardening Version000100 schema. Mirrors createPekareTable's
 * column conventions exactly (BIGINT autoincrement id, varchar keys).
 *
 * Columns:
 *   - id            BIGINT autoincrement PK
 *   - hubs_case_id  varchar(36)  NOT NULL  (FK-by-convention -> hubs_arende_case.hubs_case_id)
 *   - uid           varchar(64)  NOT NULL  (the member's NC user id)
 *   - roll          varchar(32)  NOT NULL  (mottagningskrets|handlaggare|co_handlaggare|observator)
 *   - skapad        DATETIME     NOT NULL
 *
 * Indexes:
 *   - hubs_arende_mbr_case  (hubs_case_id)            — list a room's members
 *   - hubs_arende_mbr_uq    UNIQUE (hubs_case_id, uid, roll) — idempotent record()
 *
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 */
class Version000200Date20260617140000 extends SimpleMigrationStep {
    /**
     * @param IOutput $output
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('hubs_arende_member')) {
            return null;
        }

        $table = $schema->createTable('hubs_arende_member');

        $table->addColumn('id', Types::BIGINT, [
            'autoincrement' => true,
            'notnull' => true,
        ]);
        $table->addColumn('hubs_case_id', Types::STRING, [
            'notnull' => true,
            'length' => 36,
        ]);
        $table->addColumn('uid', Types::STRING, [
            'notnull' => true,
            'length' => 64,
        ]);
        // mottagningskrets | handlaggare | co_handlaggare | observator
        $table->addColumn('roll', Types::STRING, [
            'notnull' => true,
            'length' => 32,
        ]);
        $table->addColumn('skapad', Types::DATETIME, [
            'notnull' => true,
        ]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['hubs_case_id'], 'hubs_arende_mbr_case');
        // A uid holds a given role at most once per case → idempotent record().
        $table->addUniqueIndex(['hubs_case_id', 'uid', 'roll'], 'hubs_arende_mbr_uq');

        return $schema;
    }
}
