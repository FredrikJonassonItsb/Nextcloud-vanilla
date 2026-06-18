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
 * Hardening migration for hubs_arende_case (runs on `occ upgrade`).
 *
 * This step is ADDITIVE only — it never touches the original
 * Version000000Date20260616000000 schema (already applied on dev15). Every
 * change is guarded (hasTable / hasIndex / hasColumn) so the step is idempotent
 * and a no-op when the objects already exist; mirrors the sdkmc pattern.
 *
 * M6 — Idempotens-race (findByConversationId TOCTOU → dubbel-ärende):
 *   Replace the plain index on conversation_id with a UNIQUE index so the DB
 *   closes the race window the app layer cannot. On Postgres NULL values are
 *   distinct in a UNIQUE index, so the many rows without a conversationId are
 *   unaffected (multiple NULLs remain allowed); only real conversationId values
 *   are forced unique.
 *
 * L2 — Gallrings-deadline saknas på registerraden:
 *   Add the nullable `gallras_datum` (DATE) column so the engine can persist
 *   the kvitto's gallrasDatum (committedAt + 90d) — a verkställbar
 *   gallrings-deadline, distinct from frist_due (a handläggnings-SLA-frist).
 *
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 */
class Version000100Date20260617120000 extends SimpleMigrationStep {
    /**
     * @param IOutput $output
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('hubs_arende_case')) {
            return null;
        }

        $table = $schema->getTable('hubs_arende_case');

        // M6: UNIQUE index on conversation_id. NULLs stay distinct on Postgres,
        // so the many conversationId-less rows are not constrained. Short,
        // unique index name; create only if absent.
        if (!$table->hasIndex('hubs_arende_conv_uq')) {
            $table->addUniqueIndex(['conversation_id'], 'hubs_arende_conv_uq');
        }

        // L2: nullable gallrings-deadline column (DATE, default null).
        if (!$table->hasColumn('gallras_datum')) {
            $table->addColumn('gallras_datum', Types::DATE, [
                'notnull' => false,
                'default' => null,
            ]);
        }

        return $schema;
    }
}
