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
 * Create the four hubs_arende coordination-state tables.
 *
 * The engine stores ONLY coordination state (register / pointers / routing),
 * never verksamhetsdata (which lives in the facksystem). Schema follows the
 * shared contract; ISchemaWrapper pattern mirrors sdkmc Version020008.
 *
 * Tables (NC adds the oc_ prefix automatically):
 *   - hubs_arende_case    — the register (one row per hubsCaseId)
 *   - hubs_arende_typ     — datadriven ärendetyp-registry
 *   - hubs_arende_flagga  — cross-cutting flags per case
 *   - hubs_arende_pekare  — two-way pointers to non-taggable objects
 *
 * Invariant enforced at schema level: commit_destination NOT NULL.
 *
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 */
class Version000000Date20260616000000 extends SimpleMigrationStep {
    /**
     * @param IOutput $output
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $this->createCaseTable($schema);
        $this->createTypTable($schema);
        $this->createFlaggaTable($schema);
        $this->createPekareTable($schema);

        return $schema;
    }

    private function createCaseTable(ISchemaWrapper $schema): void {
        if ($schema->hasTable('hubs_arende_case')) {
            return;
        }

        $table = $schema->createTable('hubs_arende_case');

        $table->addColumn('id', Types::BIGINT, [
            'autoincrement' => true,
            'notnull' => true,
        ]);
        // Canonical join key — UUID v4, the only join nyckel across the stack.
        $table->addColumn('hubs_case_id', Types::STRING, [
            'notnull' => true,
            'length' => 36,
        ]);
        $table->addColumn('triage_ref', Types::STRING, [
            'notnull' => false,
            'length' => 64,
        ]);
        // Pseudonym only — NEVER plaintext PII.
        $table->addColumn('objekt_ref', Types::STRING, [
            'notnull' => false,
            'length' => 128,
        ]);
        // Owning team / funktionsadress — the ACL boundary.
        $table->addColumn('enhet', Types::STRING, [
            'notnull' => false,
            'length' => 128,
        ]);
        // Assigned handläggare (null = otilldelat).
        $table->addColumn('agare_uid', Types::STRING, [
            'notnull' => false,
            'length' => 64,
        ]);
        // otilldelat | tilldelat
        $table->addColumn('status', Types::STRING, [
            'notnull' => true,
            'length' => 32,
            'default' => 'otilldelat',
        ]);
        // inflode|forhandsbedomning|utredning|beslut|uppfoljning|avslutat
        $table->addColumn('steg', Types::STRING, [
            'notnull' => true,
            'length' => 32,
            'default' => 'inflode',
        ]);
        // Facksystem dnr (null until registered via the commit callback).
        $table->addColumn('dnr', Types::STRING, [
            'notnull' => false,
            'length' => 64,
        ]);
        // ej_registrerad | registrerad
        $table->addColumn('provenance_state', Types::STRING, [
            'notnull' => true,
            'length' => 32,
            'default' => 'ej_registrerad',
        ]);
        // INVARIANT: commit_destination NOT NULL.
        // facksystem|diarium|e_arkiv|extern_myndighet|triage_forward|karantan
        $table->addColumn('commit_destination', Types::STRING, [
            'notnull' => true,
            'length' => 32,
        ]);
        $table->addColumn('retention_state', Types::STRING, [
            'notnull' => true,
            'length' => 32,
            'default' => 'aktiv',
        ]);
        // Mirrored from the facksystem (Frends), not independently counted.
        $table->addColumn('frist_due', Types::DATE, [
            'notnull' => false,
        ]);
        // FK -> hubs_arende_typ.arende_typ_id
        $table->addColumn('arende_typ', Types::STRING, [
            'notnull' => false,
            'length' => 64,
        ]);
        // Idempotency anchor — the inflow conversationId (sdkmc/mail/fax ref).
        $table->addColumn('conversation_id', Types::STRING, [
            'notnull' => false,
            'length' => 255,
        ]);
        $table->addColumn('skapad', Types::DATETIME, [
            'notnull' => true,
        ]);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['hubs_case_id'], 'hubs_arende_case_uuid');
        $table->addIndex(['status'], 'hubs_arende_case_status');
        $table->addIndex(['enhet'], 'hubs_arende_case_enhet');
        $table->addIndex(['arende_typ'], 'hubs_arende_case_typ');
        $table->addIndex(['conversation_id'], 'hubs_arende_case_conv');
    }

    private function createTypTable(ISchemaWrapper $schema): void {
        if ($schema->hasTable('hubs_arende_typ')) {
            return;
        }

        $table = $schema->createTable('hubs_arende_typ');

        $table->addColumn('arende_typ_id', Types::STRING, [
            'notnull' => true,
            'length' => 64,
        ]);
        $table->addColumn('display_name', Types::STRING, [
            'notnull' => true,
            'length' => 128,
        ]);
        $table->addColumn('default_enhet', Types::STRING, [
            'notnull' => false,
            'length' => 128,
        ]);
        $table->addColumn('forsta_atgard', Types::STRING, [
            'notnull' => false,
            'length' => 64,
        ]);
        $table->addColumn('plikt_grind', Types::BOOLEAN, [
            'notnull' => false,
            'default' => false,
        ]);
        $table->addColumn('koppling_default', Types::STRING, [
            'notnull' => false,
            'length' => 32,
        ]);
        // frist-policy as text/json
        $table->addColumn('frist_policy', Types::TEXT, [
            'notnull' => false,
        ]);
        $table->addColumn('acl_profil', Types::STRING, [
            'notnull' => false,
            'length' => 64,
        ]);
        $table->addColumn('sekretess_grund', Types::STRING, [
            'notnull' => false,
            'length' => 64,
        ]);
        $table->addColumn('diarie_plikt', Types::BOOLEAN, [
            'notnull' => false,
            'default' => false,
        ]);
        $table->addColumn('dhp_handlingstyp', Types::STRING, [
            'notnull' => false,
            'length' => 64,
        ]);
        // INVARIANT carrier: every typ declares its commit_destination.
        $table->addColumn('commit_destination', Types::STRING, [
            'notnull' => true,
            'length' => 32,
        ]);
        $table->addColumn('frends_modul', Types::STRING, [
            'notnull' => false,
            'length' => 64,
        ]);
        $table->addColumn('pre_saga_hook', Types::STRING, [
            'notnull' => false,
            'length' => 64,
        ]);
        $table->addColumn('post_commit_hook', Types::STRING, [
            'notnull' => false,
            'length' => 64,
        ]);
        $table->addColumn('parts_modell', Types::STRING, [
            'notnull' => false,
            'length' => 64,
        ]);

        $table->setPrimaryKey(['arende_typ_id']);
    }

    private function createFlaggaTable(ISchemaWrapper $schema): void {
        if ($schema->hasTable('hubs_arende_flagga')) {
            return;
        }

        $table = $schema->createTable('hubs_arende_flagga');

        $table->addColumn('id', Types::BIGINT, [
            'autoincrement' => true,
            'notnull' => true,
        ]);
        $table->addColumn('hubs_case_id', Types::STRING, [
            'notnull' => true,
            'length' => 36,
        ]);
        $table->addColumn('flagga', Types::STRING, [
            'notnull' => true,
            'length' => 64,
        ]);
        $table->addColumn('satt_av', Types::STRING, [
            'notnull' => false,
            'length' => 64,
        ]);
        $table->addColumn('satt_at', Types::DATETIME, [
            'notnull' => true,
        ]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['hubs_case_id'], 'hubs_arende_flagga_case');
    }

    private function createPekareTable(ISchemaWrapper $schema): void {
        if ($schema->hasTable('hubs_arende_pekare')) {
            return;
        }

        $table = $schema->createTable('hubs_arende_pekare');

        $table->addColumn('id', Types::BIGINT, [
            'autoincrement' => true,
            'notnull' => true,
        ]);
        $table->addColumn('hubs_case_id', Types::STRING, [
            'notnull' => true,
            'length' => 36,
        ]);
        // deck_card | talk_room | groupfolder | calendar | case_tag
        $table->addColumn('objekt_typ', Types::STRING, [
            'notnull' => true,
            'length' => 32,
        ]);
        $table->addColumn('objekt_id', Types::STRING, [
            'notnull' => true,
            'length' => 255,
        ]);
        $table->addColumn('riktning', Types::STRING, [
            'notnull' => false,
            'length' => 32,
        ]);

        $table->setPrimaryKey(['id']);
        $table->addIndex(['hubs_case_id'], 'hubs_arende_pekare_case');
        $table->addIndex(['objekt_typ', 'objekt_id'], 'hubs_arende_pekare_obj');
    }
}
