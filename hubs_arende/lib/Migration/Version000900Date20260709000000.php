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
 * Additiv migration: AI-UTKASTREGISTRET (brain-per-ärende, SPEC-BRAIN-PER-ARENDE
 * kap 8.0.4). `hubs_arende_ai_utkast` bär de generativa funktionernas (fn_draft_* /
 * fn_avslutssyntes) råa förslag TILLS människans HITL-beslut. Ett utkast blir en
 * handling FÖRST vid godkännande (via HandlingService) — aldrig maskinell commit.
 *
 * RADERINGSFÖNSTER (granskningsfynd säkerhet 9.8/8.0.4): `innehall` bär rått
 * AI-genererat ärendeinnehåll och NOLLAS av AiUtkastService vid både godkännande
 * och avvisning; raderna gallras dessutom ovillkorligen MED ärendet
 * (AiUtkastMapper::deleteByCaseId i GallringService-svepet).
 *
 * NUMRERING: P-A2 (kärnintegration/authz + fryst-tenant-tabell) tar Version000800;
 * detta register (P-A3) tar nästa lediga Version000900 för att inte kollidera.
 * TYP_AI kräver INGEN migration (Handelse::typ är en fri sträng).
 *
 * Speglar kolumnkonventionerna i hubs_arende_bevakning / hubs_arende_handelse.
 * Guardad → idempotent.
 *
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 */
class Version000900Date20260709000000 extends SimpleMigrationStep {
    /**
     * @param IOutput $output
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('hubs_arende_ai_utkast')) {
            return $schema;
        }

        $table = $schema->createTable('hubs_arende_ai_utkast');

        $table->addColumn('id', Types::BIGINT, [
            'autoincrement' => true,
            'notnull' => true,
        ]);
        // FK -> hubs_arende_case.hubs_case_id (UUID v4).
        $table->addColumn('hubs_case_id', Types::STRING, [
            'notnull' => true,
            'length' => 36,
        ]);
        // Korrelation till ork.run_log (orkestrerarens körnings-audit).
        $table->addColumn('run_id', Types::STRING, [
            'notnull' => true,
            'length' => 36,
        ]);
        // fn_draft_* | fn_avslutssyntes.
        $table->addColumn('funktion', Types::STRING, [
            'notnull' => true,
            'length' => 64,
        ]);
        // Mallen som ett godkännande genererar via HandlingService (nullbar).
        $table->addColumn('mall_id', Types::STRING, [
            'notnull' => false,
            'length' => 64,
            'default' => null,
        ]);
        // Utkast-JSON. RADERAS (NULL) vid både godkännande och avvisning.
        $table->addColumn('innehall', Types::TEXT, [
            'notnull' => false,
            'default' => null,
        ]);
        // JSON-lista handelse-/tanke-id:n (källrefs).
        $table->addColumn('kallrefs', Types::TEXT, [
            'notnull' => false,
            'default' => null,
        ]);
        // utkast | godkant | avvisat | avstadd | utgangen.
        $table->addColumn('status', Types::STRING, [
            'notnull' => true,
            'length' => 16,
            'default' => 'utkast',
        ]);
        // Unified-liknande diff vid redigerat godkännande (provenans).
        $table->addColumn('diff_text', Types::TEXT, [
            'notnull' => false,
            'default' => null,
        ]);
        // Andel ändrad text vid redigerat godkännande (0–100).
        $table->addColumn('diff_pct', Types::INTEGER, [
            'notnull' => false,
            'default' => null,
        ]);
        // fn_draft_beslutsformulering: människans utfall (serverside eko-kontroll 8.8).
        $table->addColumn('utfall_eko', Types::STRING, [
            'notnull' => false,
            'length' => 32,
            'default' => null,
        ]);
        // Modellnamn/-version/prompt (aldrig gissad — tas ur litellm-svaret).
        $table->addColumn('modell', Types::STRING, [
            'notnull' => false,
            'length' => 128,
            'default' => null,
        ]);
        $table->addColumn('modellversion', Types::STRING, [
            'notnull' => false,
            'length' => 64,
            'default' => null,
        ]);
        $table->addColumn('prompt_version', Types::STRING, [
            'notnull' => false,
            'length' => 32,
            'default' => null,
        ]);
        $table->addColumn('skapad', Types::DATETIME, [
            'notnull' => true,
        ]);
        // uid som fattade HITL-beslutet + tidpunkt (null tills avgjort).
        $table->addColumn('avgjord_av', Types::STRING, [
            'notnull' => false,
            'length' => 64,
            'default' => null,
        ]);
        $table->addColumn('avgjord', Types::DATETIME, [
            'notnull' => false,
            'default' => null,
        ]);

        $table->setPrimaryKey(['id']);
        // Hetsökningen: HITL-listan per ärende (case + status), SPEC 8.0.4-index.
        $table->addIndex(['hubs_case_id', 'status'], 'hubs_ai_utkast_case');

        return $schema;
    }
}
