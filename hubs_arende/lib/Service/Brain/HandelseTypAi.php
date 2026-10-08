<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Service\Brain;

use OCA\HubsArende\Db\Handelse;

/**
 * TYP_AI — Handelse-journalens underkategorier för brain-per-ärende (SPEC-BRAIN-
 * PER-ARENDE kap 8.0.4). Egen konstant-klass så att kärnintegrationen (SAGA-steg
 * R2b, provisioner-klienten, HITL-servicen) kopplar in dem i Handelse-skrivningen
 * utan att svälla Handelse-entiteten.
 *
 * `Handelse::detalj` för TYP_AI är ett LITET JSON-objekt UTAN fritext/PII — bara
 * koordinationsvärden: `{handling, funktion?, run_id?, modellversion?,
 * prompt_version?, diff_pct?, orsak_kategori?, protokoll_ref?}` (facit: audit utan
 * ärendeinnehåll).
 *
 * INGESTION-REGEL (SPEC 9-flöde 10-B): av dessa speglas ENDAST `utkast_godkant` /
 * `utkast_avvisat` till gw.audit_log via handelse-konnektorn (HITL-utfallet). De
 * övriga är livscykelmeta som stannar lokalt (brainen är vid fryst/gallrad ändå
 * stängd) — de skrivs för hubs_arende:s eget spår, aldrig för extern ingestion.
 *
 * Ingen migration behövs: `Handelse::typ` är en fri sträng; TYP_A='ai' registreras
 * som konstant på Handelse-entiteten (kärnintegrationen), underkategorin bor i
 * detalj.handling.
 */
final class HandelseTypAi {
    /**
     * Handelse::typ-värdet för alla AI-livscykelhändelser. Speglar mönstret där
     * Handelse-entiteten äger TYP_-konstanterna; deklareras här tills
     * kärnintegrationen lyfter in `Handelse::TYP_AI` på entiteten (då blir denna
     * en ren alias). Håll värdet i synk med Handelse::TYP_AI.
     */
    public const TYP = 'ai';

    // --- Underkategorier (detalj.handling) ---------------------------------
    /** Brain-tenant provisionerad (SAGA R2b). Livscykelmeta — ingesteras ALDRIG. */
    public const PROVISIONERAD = 'provisionerad';
    /** Tenant fryst (avslutat ärende). Livscykelmeta — ingesteras ALDRIG. */
    public const FRYST = 'fryst';
    /** Tenant gallrad (drop_schema_cascade). Livscykelmeta — ingesteras ALDRIG. */
    public const GALLRAD = 'gallrad';
    /** AI-utkast skapat (status=utkast). Lokal audit. */
    public const UTKAST_SKAPAT = 'utkast_skapat';
    /** HITL: utkast godkänt → handling i akten. INGESTERAS (10-B). */
    public const UTKAST_GODKANT = 'utkast_godkant';
    /** HITL: utkast avvisat → mellanprodukt kasserad. INGESTERAS (10-B). */
    public const UTKAST_AVVISAT = 'utkast_avvisat';
    /** Nekad brain-åtkomst (authz deny speglad lokalt). Lokal audit. */
    public const NODATKOMST = 'nodatkomst';
    /** Ärende återöppnat efter frysning (thaw). Lokal audit. */
    public const ATEROPPNAD = 'ateroppnad';

    /** @return string[] Underkategorier som speglas till gw.audit_log (flöde 10-B). */
    public static function ingesterbara(): array {
        return [self::UTKAST_GODKANT, self::UTKAST_AVVISAT];
    }

    /** true om underkategorin ska speglas externt (HITL-utfall), false = lokal livscykelmeta. */
    public static function arIngesterbar(string $underkategori): bool {
        return in_array($underkategori, self::ingesterbara(), true);
    }

    /**
     * Handelse::typ-värdet att skriva. Föredrar entitetskonstanten om
     * kärnintegrationen redan lagt in den; annars den lokala {@see TYP}.
     */
    public static function typVarde(): string {
        return defined(Handelse::class . '::TYP_AI') ? Handelse::TYP_AI : self::TYP;
    }
}
