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
 * PER-PART-DELGIVNING (FL 44 §) — additiv, bakåtsäker utökning av partsregistret.
 *
 * ★ LEGAL-FRIST-KOD ★ Ändrar hur laga kraft/överklagandefristen räknas: från EN
 * delgivningskolumn på ärendet (Arende::delgivningsdatum, som kunde signalera laga
 * kraft medan en av två vårdnadshavares frist fortfarande löpte) till delgivning
 * PER PART. Laga kraft = SENASTE delgivna partens frist. Se
 * {@see \OCA\HubsArende\Service\BevakningService::synkaOverklagandeFranParter()}.
 *
 * Fyra NULLBARA kolumner (default-säkert — befintliga rader och Arende::
 * delgivningsdatum-vägen fortsätter fungera oförändrat tills en part faktiskt
 * delges per part):
 *   - delgivningsdatum          partens FL 33 §-delgivningsdatum (ankaret).
 *   - delgivning_metod          ordinar|forenklad|muntlig|kungorelse|stamning.
 *   - delgivning_undantagen     parten ska MEDVETET EJ delges (OSL 10:3 / skyddad
 *                               adress / våldsscenario) — kritikerns tillägg:
 *                               modellen bär BÅDE "delge per part" OCH "nå inte
 *                               denna part". En undantagen part håller inte upp
 *                               laga kraft.
 *   - delgivning_undantag_grund fri kort grund (osl_10_3|skyddad_adress|vald|annan).
 *
 * PII: inga av dessa fält är PII (koordinations-/frist-data). Gallras med parten
 * (partsraden i {@see \OCA\HubsArende\Service\GallringService}).
 *
 * Guardad → idempotent.
 *
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 */
class Version001000Date20260712000000 extends SimpleMigrationStep {
    /**
     * @param IOutput $output
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('hubs_arende_part')) {
            // Partsregistret saknas (bör aldrig hända — Version000700 skapar det);
            // ingen isolerad kolumn-migration att göra.
            return null;
        }

        $table = $schema->getTable('hubs_arende_part');

        if (!$table->hasColumn('delgivningsdatum')) {
            // Partens delgivningsdatum (FL 33 §) — ankaret för dess överklagandefrist.
            $table->addColumn('delgivningsdatum', Types::DATETIME, [
                'notnull' => false,
                'default' => null,
            ]);
        }
        if (!$table->hasColumn('delgivning_metod')) {
            // ordinar | forenklad | muntlig | kungorelse | stamning.
            $table->addColumn('delgivning_metod', Types::STRING, [
                'notnull' => false,
                'length' => 32,
                'default' => null,
            ]);
        }
        if (!$table->hasColumn('delgivning_undantagen')) {
            // Parten ska MEDVETET EJ delges (OSL 10:3 m.fl.). notnull + default false
            // ⇒ befintliga rader blir "ej undantagna" (bakåtsäkert).
            $table->addColumn('delgivning_undantagen', Types::BOOLEAN, [
                'notnull' => true,
                'default' => false,
            ]);
        }
        if (!$table->hasColumn('delgivning_undantag_grund')) {
            // osl_10_3 | skyddad_adress | vald | annan (kort, pseudonym grund).
            $table->addColumn('delgivning_undantag_grund', Types::STRING, [
                'notnull' => false,
                'length' => 64,
                'default' => null,
            ]);
        }

        return $schema;
    }
}
