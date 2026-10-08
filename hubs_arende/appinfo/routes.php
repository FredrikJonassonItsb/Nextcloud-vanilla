<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * OCS routes for the standalone hubs_arende ärende-motor.
 *
 * Effective prefix: /ocs/v2.php/apps/hubs_arende/api/v1/...
 *
 * Per the shared contract these are exposed as OCS routes so the engine can be
 * consumed cross-app (and later as an ExApp) rather than via internal page routes.
 */
return [
    'ocs' => [
        // POST /ocs/v2.php/apps/hubs_arende/api/v1/arende  -> createCase (runs the SAGA)
        [
            'name' => 'Arende#createCase',
            'url' => '/api/v1/arende',
            'verb' => 'POST',
        ],
        // GET /ocs/v2.php/apps/hubs_arende/api/v1/arende/{ref}  -> show (by hubsCaseId or dnr)
        [
            'name' => 'Arende#show',
            'url' => '/api/v1/arende/{ref}',
            'verb' => 'GET',
        ],
        // GET /ocs/v2.php/apps/hubs_arende/api/v1/arende-summary  -> dashboard aggregate
        [
            'name' => 'Arende#summary',
            'url' => '/api/v1/arende-summary',
            'verb' => 'GET',
        ],
        // POST /ocs/v2.php/apps/hubs_arende/api/v1/arende/{ref}/tilldela  -> assignment + ACL rewrite
        [
            'name' => 'Arende#tilldela',
            'url' => '/api/v1/arende/{ref}/tilldela',
            'verb' => 'POST',
        ],
        // POST /ocs/v2.php/apps/hubs_arende/api/v1/treserva/commit  -> FacksystemCommitService
        [
            'name' => 'Arende#commit',
            'url' => '/api/v1/treserva/commit',
            'verb' => 'POST',
        ],

        // --- Ärenderum-medlemmar (förstaklassiga) + 1:n extra-rum --------------
        // GET    /arende/{ref}/medlemmar      -> rummets medlemmar (uid+roll)
        [
            'name' => 'Arende#medlemmar',
            'url' => '/api/v1/arende/{ref}/medlemmar',
            'verb' => 'GET',
        ],
        // GET    /arende/{ref}/historik       -> händelsejournalen (Historik & beslut)
        [
            'name' => 'Arende#historik',
            'url' => '/api/v1/arende/{ref}/historik',
            'verb' => 'GET',
        ],
        // GET    /arende/{ref}/bevakningar    -> läs-projektion av bevaknings-registret
        [
            'name' => 'Arende#bevakningar',
            'url' => '/api/v1/arende/{ref}/bevakningar',
            'verb' => 'GET',
        ],
        // POST   /arende/{ref}/bevakning      -> skapa ad hoc-bevakning (manuell)
        [
            'name' => 'Arende#skapaBevakning',
            'url' => '/api/v1/arende/{ref}/bevakning',
            'verb' => 'POST',
        ],
        // POST   /arende/{ref}/bevakning/{id}/kvittera -> klarmarkera (manuell_kvittering)
        [
            'name' => 'Arende#kvitteraBevakning',
            'url' => '/api/v1/arende/{ref}/bevakning/{id}/kvittera',
            'verb' => 'POST',
        ],
        // DELETE /arende/{ref}/bevakning/{id} -> avbryt bevakning (ej längre relevant)
        [
            'name' => 'Arende#avbrytBevakning',
            'url' => '/api/v1/arende/{ref}/bevakning/{id}',
            'verb' => 'DELETE',
        ],
        // POST   /arende/{ref}/delgivning     -> sätt delgivningsdatum (→ överklagandebevakning)
        [
            'name' => 'Arende#setDelgivning',
            'url' => '/api/v1/arende/{ref}/delgivning',
            'verb' => 'POST',
        ],
        // POST   /arende/{ref}/medlem         -> lägg till co-handläggare/observatör
        [
            'name' => 'Arende#laggTillMedlem',
            'url' => '/api/v1/arende/{ref}/medlem',
            'verb' => 'POST',
        ],
        // DELETE /arende/{ref}/medlem         -> ta bort medlem
        [
            'name' => 'Arende#taBortMedlem',
            'url' => '/api/v1/arende/{ref}/medlem',
            'verb' => 'DELETE',
        ],
        // POST   /arende/{ref}/talkrum        -> 1:n extra talkrum (samma hubs_case_id)
        [
            'name' => 'Arende#laggTillTalkrum',
            'url' => '/api/v1/arende/{ref}/talkrum',
            'verb' => 'POST',
        ],
        // GET    /rum/{token}                 -> reverse-lookup rum→ärende (P1.3b, bot läser registret)
        [
            'name' => 'Arende#rumLosning',
            'url' => '/api/v1/rum/{token}',
            'verb' => 'GET',
        ],
        // POST   /arende/{ref}/dokumentchatt  -> registrera filchatt-rum som pekare (P1.3b)
        [
            'name' => 'Arende#registreraDokumentchatt',
            'url' => '/api/v1/arende/{ref}/dokumentchatt',
            'verb' => 'POST',
        ],
        // POST   /arende/{ref}/groupfolder    -> 1:n extra groupfolder (samma hubs_case_id)
        [
            'name' => 'Arende#laggTillGroupfolder',
            'url' => '/api/v1/arende/{ref}/groupfolder',
            'verb' => 'POST',
        ],
        // POST /ocs/v2.php/apps/hubs_arende/api/v1/arende/{hubsCaseId}/steg  -> lifecycle transition
        [
            'name' => 'Arende#steg',
            'url' => '/api/v1/arende/{hubsCaseId}/steg',
            'verb' => 'POST',
        ],

        // --- Partsregistret (motorns enda PII-tabell; K-NAV-4.x) ------------
        // GET /ocs/v2.php/apps/hubs_arende/api/v1/arende/{ref}/parter -> ärendets parter
        [
            'name' => 'Part#parter',
            'url' => '/api/v1/arende/{ref}/parter',
            'verb' => 'GET',
        ],
        // POST /ocs/v2.php/apps/hubs_arende/api/v1/arende/{ref}/part -> manuell part
        [
            'name' => 'Part#laggTill',
            'url' => '/api/v1/arende/{ref}/part',
            'verb' => 'POST',
        ],
        // POST /ocs/v2.php/apps/hubs_arende/api/v1/arende/{ref}/part/uppslag
        //   -> Navet-uppslag (via FolkbokforingPort) in i partsregistret
        [
            'name' => 'Part#uppslag',
            'url' => '/api/v1/arende/{ref}/part/uppslag',
            'verb' => 'POST',
        ],
        // POST /ocs/v2.php/apps/hubs_arende/api/v1/arende/{ref}/part/{id}/uppdatera
        //   -> uppdatera befintlig part från Navet (rättelse-garantin, K-NAV-4.4)
        [
            'name' => 'Part#uppdatera',
            'url' => '/api/v1/arende/{ref}/part/{id}/uppdatera',
            'verb' => 'POST',
        ],
        // DELETE /ocs/v2.php/apps/hubs_arende/api/v1/arende/{ref}/part/{id}
        [
            'name' => 'Part#taBort',
            'url' => '/api/v1/arende/{ref}/part/{id}',
            'verb' => 'DELETE',
        ],
        // ★ LEGAL-FRIST ★ POST .../part/{id}/delgivning -> per-part-delgivning (FL 44 §)
        [
            'name' => 'Part#setDelgivning',
            'url' => '/api/v1/arende/{ref}/part/{id}/delgivning',
            'verb' => 'POST',
        ],
        // ★ LEGAL-FRIST ★ POST .../part/{id}/delgivning/undanta -> undanta part (OSL 10:3)
        [
            'name' => 'Part#undantaDelgivning',
            'url' => '/api/v1/arende/{ref}/part/{id}/delgivning/undanta',
            'verb' => 'POST',
        ],

        // --- Handling-från-mall (fas 1: mall + ärendedata → docx i akten) ---
        // GET /ocs/v2.php/apps/hubs_arende/api/v1/arende/{ref}/mallar -> mallbibliotekets .docx
        [
            'name' => 'Handling#mallar',
            'url' => '/api/v1/arende/{ref}/mallar',
            'verb' => 'GET',
        ],
        // GET /ocs/v2.php/apps/hubs_arende/api/v1/arende/{ref}/handling-utkast
        //   -> förifyllnadsförslag (register + partsregister, skyddsgrindat)
        [
            'name' => 'Handling#utkast',
            'url' => '/api/v1/arende/{ref}/handling-utkast',
            'verb' => 'GET',
        ],
        // POST /ocs/v2.php/apps/hubs_arende/api/v1/arende/{ref}/handling
        //   -> generera ifylld handling i ärenderummets groupfolder
        [
            'name' => 'Handling#skapa',
            'url' => '/api/v1/arende/{ref}/handling',
            'verb' => 'POST',
        ],

        // --- E-underskrift fas 1 (KRAV-SIGNERING-2026-07, K-SIGN-1–9) -------
        // Tvånivåmodellens OCS-yta — delat kontrakt med hubs_start. Allt går
        // genom SigneringService (enda konsumenten av SigneringPort).
        // GET .../arende/{ref}/signering -> {niva_matris, poster:[SigneringDTO]}
        [
            'name' => 'Signering#oversikt',
            'url' => '/api/v1/arende/{ref}/signering',
            'verb' => 'GET',
        ],
        // POST .../signering/godkann -> digitalt godkännande (K-SIGN-2; journalförs,
        //   renderas ALDRIG som underskrift). Body: {handlingRef, filename, dokumentHash}.
        [
            'name' => 'Signering#godkann',
            'url' => '/api/v1/arende/{ref}/signering/godkann',
            'verb' => 'POST',
        ],
        // POST .../signering/begar -> AdES-begäran via SigneringPort (K-SIGN-3/4).
        //   Body: {handlingRef, filename, dokumentHash, signers:[{uid, role}]}.
        [
            'name' => 'Signering#begar',
            'url' => '/api/v1/arende/{ref}/signering/begar',
            'verb' => 'POST',
        ],
        // POST .../signering/{signRequestId}/refresh -> idempotent poll (K-SIGN-22)
        [
            'name' => 'Signering#refresh',
            'url' => '/api/v1/arende/{ref}/signering/{signRequestId}/refresh',
            'verb' => 'POST',
        ],
        // POST .../signering/{signRequestId}/fornya -> NY begäran, journalförd kedja (K-SIGN-7)
        [
            'name' => 'Signering#fornya',
            'url' => '/api/v1/arende/{ref}/signering/{signRequestId}/fornya',
            'verb' => 'POST',
        ],
        // POST .../signering/{signRequestId}/avbryt -> lokalt avbruten, body {skal} (K-SIGN-7)
        [
            'name' => 'Signering#avbryt',
            'url' => '/api/v1/arende/{ref}/signering/{signRequestId}/avbryt',
            'verb' => 'POST',
        ],
        // POST .../signering/{signRequestId}/paminn -> journalförd påminnelse (v1, K-SIGN-7)
        [
            'name' => 'Signering#paminn',
            'url' => '/api/v1/arende/{ref}/signering/{signRequestId}/paminn',
            'verb' => 'POST',
        ],

        // --- Inflöde-bands (KorgValjare + de tre banden 1a/1b/1c) -----------
        // GET /ocs/v2.php/apps/hubs_arende/api/v1/inflode-summary
        //   -> korgar + klassade/matchade inflöde-rader (server-side aggregat)
        [
            'name' => 'Infode#inflodeSummary',
            'url' => '/api/v1/inflode-summary',
            'verb' => 'GET',
        ],
        // POST /ocs/v2.php/apps/hubs_arende/api/v1/inflode/{action}
        //   -> koppla|skapa|besvara|vidarebefordra|gallra|registrera
        [
            'name' => 'Infode#inflodeAction',
            'url' => '/api/v1/inflode/{action}',
            'verb' => 'POST',
        ],

        // --- Fördelningsvy + verifierade Treserva-kvittenser (read-only) -----
        // HUBS-START BACKEND-ADDITION — the dashboard's fördelnings- + kvittens-ytor.
        // GET /ocs/v2.php/apps/hubs_arende/api/v1/fordelning-summary
        //   -> gruppledarens fördelningsvy (otilldelade + handläggar-belastning)
        [
            'name' => 'Fordelning#fordelningSummary',
            'url' => '/api/v1/fordelning-summary',
            'verb' => 'GET',
        ],
        // GET /ocs/v2.php/apps/hubs_arende/api/v1/treserva/receipts
        //   -> verifierade commit-kvittenser (provenance='registrerad')
        [
            'name' => 'Fordelning#treservaReceipts',
            'url' => '/api/v1/treserva/receipts',
            'verb' => 'GET',
        ],

        // --- Admin DEV/DEMO-verktyg (ADMIN-ONLY) ----------------------------
        // POST /ocs/v2.php/apps/hubs_arende/api/v1/admin/seed-demo
        //   -> DemoSeedService->reseed() (purge + seed). Backar "Återställ
        //      demo-data till utgångsläge"-knappen i hubs_start admin-inställningar.
        [
            'name' => 'Admin#seedDemo',
            'url' => '/api/v1/admin/seed-demo',
            'verb' => 'POST',
        ],

        // --- Brain-per-ärende: gateway-authz (server-till-server) ------------
        // POST /ocs/v2.php/apps/hubs_arende/api/v1/authz/check
        //   -> AuthzController::check(). Anropas av brain-gw (Node) UTAN NC-session
        //      för varje MCP-/funktionsanrop: {uid, hubs_case_id, funktion} →
        //      {allow, roll, skal, skydd}. #[PublicPage] + eget gateway-secret
        //      (fail-closed), se AuthzController. Ruttnamnet 'Authz#check' resolvar
        //      till OCA\HubsArende\Controller\AuthzController (platt namespace).
        [
            'name' => 'Authz#check',
            'url' => '/api/v1/authz/check',
            'verb' => 'POST',
        ],
    ],
];
