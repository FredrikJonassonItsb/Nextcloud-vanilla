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
 * Additiv migration: DURABEL BRAIN-PROVISIONERINGS-RETRY (brain-per-ärende, kap 3.3).
 *
 * `hubs_arende_brain_provision` (NC lägger på `oc_`-prefixet) — den durabla retry-kön
 * bakom SAGA-steget R2b: när provisionern är onåbar/timeout/5xx skapas ärendet UTAN
 * brain (ärendeskapande får aldrig blockeras av AI-infra, kap 1.2) och raden läggs här;
 * {@see \OCA\HubsArende\BackgroundJob\BrainProvisionRetryJob} kör om det idempotenta
 * `POST /provision/tenants` med exponentiell backoff.
 *
 * LITEN tabell, normalt nära tom. `hubs_case_id` PRIMÄRNYCKEL ⇒ högst en rad per ärende
 * (idempotent enqueue, {@see \OCA\HubsArende\Service\Brain\BrainProvisionRetryService}).
 * Koordinationsdata utan PII (pseudonymt id + typ + räknare); gallras med ärendet.
 *
 * Kolumnerna följer SPEC kap 3.3:s DDL (`hubs_case_id`, `status`, `arende_typ`, `forsok`,
 * `nasta_forsok`, `skapad`) + `sista_forsok` (senaste försökets unixtid — observability
 * för N=5-larmet; skrivs aldrig av Node-sidan, ren NC-lokal retry-state).
 *
 * Guardad → idempotent (kör om utan effekt). NÄSTA LEDIGA Version-nummer efter
 * Version000700 (utredningskedjans högsta) — kolliderar ej med dess migrationer.
 *
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 */
class Version000800Date20260709000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('hubs_arende_brain_provision')) {
            return $schema;
        }

        $table = $schema->createTable('hubs_arende_brain_provision');

        // Kanoniskt ärende-id (UUID v4) — PRIMÄRNYCKEL ⇒ en rad per ärende (idempotens).
        $table->addColumn('hubs_case_id', Types::STRING, [
            'notnull' => true,
            'length' => 36,
        ]);
        // pending | klar | permanent_fel
        $table->addColumn('status', Types::STRING, [
            'notnull' => true,
            'length' => 16,
            'default' => 'pending',
        ]);
        // Ärendetyp-id — bärs med så jobbet kan POST:a utan att slå upp ärendet på nytt.
        $table->addColumn('arende_typ', Types::STRING, [
            'notnull' => true,
            'length' => 64,
        ]);
        // Antal gjorda försök — driver exponentiell backoff + N=5-larmtröskeln.
        $table->addColumn('forsok', Types::INTEGER, [
            'notnull' => true,
            'default' => 0,
        ]);
        // Unixtid för nästa försöksfönster (exponentiell backoff). NULL = förfaller direkt.
        $table->addColumn('nasta_forsok', Types::BIGINT, [
            'notnull' => false,
        ]);
        // Unixtid för senaste försöket (observability). NULL tills första retry:n körts.
        $table->addColumn('sista_forsok', Types::BIGINT, [
            'notnull' => false,
        ]);
        // Unixtid då raden köades.
        $table->addColumn('skapad', Types::BIGINT, [
            'notnull' => true,
        ]);

        $table->setPrimaryKey(['hubs_case_id']);
        // Claim-predikatet (BrainProvisionRetryJob): pending-rader vars fönster passerats.
        $table->addIndex(['status', 'nasta_forsok'], 'hubs_arende_brain_prov_due');

        return $schema;
    }
}
