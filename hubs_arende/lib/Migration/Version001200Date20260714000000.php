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
 * E-UNDERSKRIFT FAS 1 (KRAV-SIGNERING-2026-07, K-SIGN-1–9/15/21/22):
 * `hubs_arende_signering` — persisterat begäran-state för signeringslivscykeln
 * genom {@see \OCA\HubsArende\Service\SigneringService} (enda konsumenten av
 * {@see \OCA\HubsArende\Integration\Port\SigneringPort}).
 *
 * KOORDINATIONSDATA, INTE INNEHÅLL (NEVER-SoR): raden bär referenser (handling_ref,
 * hash, signRequestId, uid/roll i signers_json) — aldrig dokumentinnehåll och aldrig
 * namn/personnummer. `sign_message` är NEUTRALISERAD per K-SIGN-4/15 (kortref +
 * dokumenttyp + hash-prefix, ALDRIG handläggar-fritext eller röjande filnamn).
 * Raderna rivs av destruktionsspegeln ({@see \OCA\HubsArende\Service\GallringService},
 * K-SIGN-19) — signeringsspåret överlever aldrig ärendet.
 *
 * Rader finns för BÅDA nivåerna i tvånivåmodellen (K-SIGN-1): niva='ades' bär
 * hela portlivscykeln (sign_request_id satt), niva='godkann' är ett persisterat
 * godkännande-kvitto (sign_request_id null — därav nullbar + unik).
 *
 * Speglar kolumnkonventionerna i hubs_arende_bevakning / hubs_arende_ai_utkast.
 * Guardad → idempotent.
 *
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 */
class Version001200Date20260714000000 extends SimpleMigrationStep {
    /**
     * @param IOutput $output
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('hubs_arende_signering')) {
            return $schema;
        }

        $table = $schema->createTable('hubs_arende_signering');

        $table->addColumn('id', Types::BIGINT, [
            'autoincrement' => true,
            'notnull' => true,
        ]);
        // FK -> hubs_arende_case.hubs_case_id (UUID v4).
        $table->addColumn('hubs_case_id', Types::STRING, [
            'notnull' => true,
            'length' => 36,
        ]);
        // Motorns dokumentreferens (fil-id/sökväg i ärenderummets groupfolder).
        $table->addColumn('handling_ref', Types::STRING, [
            'notnull' => true,
            'length' => 255,
        ]);
        // Visningsfilnamn INOM behörighetsgränsen (skickas ALDRIG externt — porten
        // får den neutraliserade varianten, U6/K-SIGN-15).
        $table->addColumn('filename', Types::STRING, [
            'notnull' => true,
            'length' => 255,
        ]);
        // Kanonisk SHA-256 (hex) av dokumentet vid begäran (U2).
        $table->addColumn('dokument_hash', Types::STRING, [
            'notnull' => true,
            'length' => 64,
        ]);
        // Portens begäran-id. Null för godkann-rader (aldrig begärda hos porten).
        $table->addColumn('sign_request_id', Types::STRING, [
            'notnull' => false,
            'length' => 64,
            'default' => null,
        ]);
        // pending | partially_signed | signed | rejected | expired | avbruten | godkand.
        $table->addColumn('status', Types::STRING, [
            'notnull' => true,
            'length' => 32,
        ]);
        // godkann | ades (tvånivåmodellen, K-SIGN-1).
        $table->addColumn('niva', Types::STRING, [
            'notnull' => true,
            'length' => 16,
        ]);
        // JSON: [{uid, role, status: vantar|signerad, tidpunkt|null}] (U4).
        $table->addColumn('signers_json', Types::TEXT, [
            'notnull' => false,
            'default' => null,
        ]);
        // NEUTRALISERAD SignMessage (kortref + dokumenttyp + hash-prefix) —
        // ALDRIG fritext/filnamn (K-SIGN-4/15).
        $table->addColumn('sign_message', Types::STRING, [
            'notnull' => false,
            'length' => 255,
            'default' => null,
        ]);
        // Uppnådd PAdES-nivå (ETSI-term, U7). Null tills signed.
        $table->addColumn('pades_level', Types::STRING, [
            'notnull' => false,
            'length' => 32,
            'default' => null,
        ]);
        // Skäl vid rejected/avbruten (lagras här, journalen bär aldrig fritexten).
        $table->addColumn('avvisad_skal', Types::STRING, [
            'notnull' => false,
            'length' => 255,
            'default' => null,
        ]);
        // Föregående signRequestId vid förnyad begäran (journalförd kedja, K-SIGN-7).
        $table->addColumn('kedja_fran', Types::STRING, [
            'notnull' => false,
            'length' => 64,
            'default' => null,
        ]);
        $table->addColumn('created_at', Types::DATETIME, [
            'notnull' => true,
        ]);
        $table->addColumn('updated_at', Types::DATETIME, [
            'notnull' => true,
        ]);
        $table->addColumn('expires_at', Types::DATETIME, [
            'notnull' => false,
            'default' => null,
        ]);

        $table->setPrimaryKey(['id']);
        // Hetsökningen: statuspanelen per ärende.
        $table->addIndex(['hubs_case_id'], 'hubs_signering_case');
        // Poll/refresh slår upp på portens id — unik (nullbara godkann-rader
        // deltar inte i unikheten på null-värden).
        $table->addUniqueIndex(['sign_request_id'], 'hubs_signering_reqid');

        return $schema;
    }
}
