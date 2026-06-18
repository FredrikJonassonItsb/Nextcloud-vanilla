<!--
SPDX-FileCopyrightText: ITSL <info@itsl.se>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
# HANDOVER — Hubs ärende-motor (datamodell + Fas A–F)

Sista uppdatering: 2026-06-17. Skriven för att en NY kontext ska kunna fortsätta
sömlöst. Persistenta fakta finns även i auto-minnet (`MEMORY.md`,
`arenderum-agarmodell.md`, `hubs-start-app.md`, `hubs-ops.md`).

## Vad detta är

- **hubs_arende** — fristående ärende-motor (NC-app, `OCA\HubsArende`) för Hubs/ITSL
  (svensk socialtjänst). Lagrar ENBART koordinations-state (register + pekare +
  medlemmar), **ALDRIG verksamhetsdata** (NEVER-SoR — det bor i facksystemet,
  committas dit). SAGA `createCase()` R0–R10 med kompensering.
- **sdkmc** — mail/brevlåde-app (taggar, korgar, retention). hubs_arende KONSUMERAR den.
- **hubs_start** — Vue 2.7-dashboard (socialsekreterar-vy). Bygger bundle via webpack.

## Miljö / drift (dev15)

- SSH: `ubuntu@10.43.51.62`. Docker behöver `sudo`.
- Containrar: `hubs-php` (NC 31.0.8 / image v2.2.66), `hubs-postgres`, `hubs-apache`.
- App-paths i containern: `custom_apps/hubs_arende`, **`apps/sdkmc`** (ej custom_apps),
  `custom_apps/hubs_start`. Datadir `/mnt/hubsdata` (logg `hubs.log`).
- DB: `sudo docker exec hubs-postgres psql -U oc_hubs -d hubs -tAc "<SQL>"`.
- OCS-bas: `occ config:system:get overwrite.cli.url` = `https://dev15.hubs.se`.
- **Service-konto (Seam A, LIVE):** uid `hubs-arende-svc` (i `admin`-gruppen), credential
  i app-config `hubs_arende.sa_user` / `sa_token` (--sensitive). Sagan kör riktiga
  OCS-anrop till grannappar med detta.
- Reseed/clear: `occ hubs_arende:seed-demo [--purge]`.
- **Deploy-mönster:** `tar czf - <filer> | ssh ubuntu@10.43.51.62 'sudo docker exec -i hubs-php tar xzf - -C /var/www/html/custom_apps && sudo docker exec hubs-php chown -R www-data:www-data ...'`.
  ⚠ **SEPARERA deploy och verify** — `... | ssh '...bash -s' < script` klobbrar tar-pipen.
- **Bundle-build (hubs_start):** `cd hubs_start && npm_package_name=hubs_start npm_package_version=1.2.3 NODE_ENV=production node node_modules/webpack/bin/webpack.js --config webpack.js`
  (npm-env måste sättas annars `Building undefined`). Deploy `hubs_start/js/`.

## Avsedd ägarmodell (kundens, verifierad mot koden)

- Ärende **1:1** Ärenderum (implicit: `Arende`-raden + pekare-mängd, ingen rum-tabell).
- Ärenderum **1:n** talkrum/gruppfoldrar (schemat tillåter, default 1, explicit 1:n-väg
  finns), **1:1** Deck-KORT (board delas per enhet), **1:n** medlemmar.
- Användare **1:n** SDKMC-brevlådor (n:m junction). Användare **1:1** kalender
  (handläggar-ägd). Användare kopplar meddelanden → ärenderum (referens).

## Byggt denna session — A1–F (allt deployat + verifierat live)

| Fas | Innehåll | Status |
|---|---|---|
| **A1** | tabell `hubs_arende_member` (uid,roll,skapad; UNIQUE case+uid+roll) + Member/MemberMapper + migration Version000200 | ✅ |
| **A2** | `aclKretsUids()` resolvar enhet→NC-grupp→uids; sagan recordar mottagningskrets; `GroupfolderClient::addGroup()` | ✅ 20 medlemmar |
| **A3** | `tilldela()`→handläggar-medlem; `laggTillMedlem/taBortMedlem/medlemmar`; **1:n** `laggTillTalkrum/laggTillGroupfolder`; nya OCS-routes | ✅ live |
| **B** | Handläggar-ägd kalender: `CalendarClient` ownerUid + `moveCaseCalendar` re-home vid tilldela | ✅ objekt SA→admin |
| **D** | Ärlig koppling (pekare bara när tagg landar) + fix av plattforms-bugg `{action}` terminal OCS POST (`InfodeController::actionFromUri`) | ✅ |
| **C** | Adversariell sekretessgranskning (22 agenter): **11 äkta fynd, 0 FP** | ✅ |
| **C-rem** | 5 contained fixar: assignee-validering, `taBortMedlem` roll-allowlist, gallring städar member-PII, `moveCaseCalendar` PUT-före-DELETE, **koppla-IDOR gatad default-off** | ✅ |
| **E** | **Per-ärende-isolering** (`ArenderumGroupService`: per-case NC-grupp = åtkomstlista, grantad ENBART på cases folder) + **handoff-avsmalning** (`atkomstUids`: otilldelat→krets, tilldelat→endast handläggare; GAP-057) | ✅ admin revokeras vid tilldelning |
| **F1** | `ReferensFilService` — `msg-<hash>.url` i ärendemappens groupfolder (endast djuplänk+id, NEVER-SoR) via `IRootFolder->get('__groupfolders/{id}')`; `Pekare(groupfolder_ref)`; gallras | ✅ fil landar+städas |
| **F2** | sdkmc `ItslTagService::itslTagMeta` — `case:`-tagg → "Ärende {ref}" (grön) + "Behandlad" (blå), synliga i mail-klienten (HUBS-START-ADD) | ✅ deployad, **visuell mail-bekräftelse återstår** |
| **F3** | `KopplaValjare.vue` — väljer målärende; Väg A (user-session-tagg) + referens; wirad i `MinaArenden` `onTriage('koppla')`/`onInflode('koppla')` | ✅ live: picker öppnas + listar 10 ärenden; val skriver referens i valt cases mapp |

**Bonusfixar:** (1) `Arende#summary` returnerar nu `dashboardSummary()` (ärende-korten),
ej bara counts — annars var dashboardens "Mina ärenden" + pickern tomma i live-läge.
(2) `api.kopplaMeddelandeTillArende`: **referens FÖRST** (durabelt) + **tagg best-effort**
— ett syntetiskt/icke-ägt meddelande får inte blockera kopplingen.

## Beslut (fattade av kunden)

- Kalender: **handläggar-ägd** (re-home vid tilldela).
- Medlemskap: **förstaklassigt** (egen tabell) + handoff-avsmalning.
- ACL-isolering: **per-ärende NC-grupp** (ej granulära ACL-regler).
- Meddelande-koppling: **REFERENS, ej kopia** (NEVER-SoR); IDOR-fix **Väg A**
  (frontend taggar i användarens session); **synliga** taggar i mail-klienten.

## Öppna trådar / nästa steg

1. **Sista milen F2:** koppla ett RIKTIGT mail-meddelande och bekräfta visuellt att
   "Ärende {ref}" + "Behandlad" syns i sdkmc-klienten (demons inflöde är syntetiskt →
   user-scopade taggen är graceful no-op där; `taggSatt:false` men referens skrivs ändå).
2. **F4 (tillval):** bygg `NewMessagesClassifier` i sdkmc (eventet dispatchas ingenstans
   idag) + fysisk "Behandlat"-IMAP-flytt.
3. **Härdning (låg, från granskningen):** kvot/rate-limit på `laggTillTalkrum/Groupfolder`;
   larm vid enhet-grupp-namnkollision (`resolveEnhetGroups`); unik-constraint på
   `(hubs_case_id, objekt_typ)` för deck/talk/calendar.
4. **Produktion:** service-konto med LÄGSTA rättighet (ej admin); credential ur vault
   (ej app-config); gallring bör även riva externa rum (idag rivs pekare+grupp+member,
   ej groupfolder/talk via klient — pre-existing).
5. **Frontend-koppla för "Att ta emot":** picker triggas via `onTriage('koppla')`. "Ej
   ärendekopplat"-sektionen är tom i demon (allt triagerat) men samma picker gäller där.

## Gotchas (spar tid)

- **Numerisk uid** (t.ex. `197411040293`) blir INT som PHP array-nyckel → `array_map('strval', array_keys(...))`.
- **Browser-tooling (Chrome-MCP mot Edge "Windos"):** notify-push håller sidan icke-idle →
  `find/screenshot/navigate/javascript` timeout:ar sporadiskt; refs blir stale mellan
  find→click. Funkar bäst: FÄRSK flik + `read_page` (väntar ej på idle) + click-by-ref;
  `Ctrl+Shift+R` för cache-bust när bundlen ändrats utan version-bump.
- **Deck/Calendar soft-delete:** `deleteCard`/`deleteCalendarObject` soft-deletar (rad
  ligger kvar med deleted_at); räkna `WHERE deleted_at IS NULL`. Kalender forcerar nu
  permanent delete; Spreed `deleteRoom` (hard) vs `archiveRoom` (säkerhetsskydd, bevarar).
- **OCS terminal `{action}`** binder ej på POST i NC 31 (drabbar även sdkmc) — löst via
  `InfodeController::actionFromUri()`.
- **Migration:** version-bump i info.xml + `occ upgrade` kör den (eller blockerar appen tills upgrade).

## Nyckelfiler

- Motor: `hubs_arende/lib/Service/{ArendeService,ArenderumGroupService,ReferensFilService,GallringService,DemoSeedService}.php`,
  `lib/Db/{Member,MemberMapper,Pekare,Arende}.php`, `lib/Integration/Client/*.php`,
  `lib/Controller/{ArendeController,InfodeController}.php`, `appinfo/routes.php`.
- sdkmc: `apps/sdkmc/.../Service/ItslTagService.php` (itslTagMeta, HUBS-START-ADD).
- Frontend: `hubs_start/src/components/socialsekreterare/{KopplaValjare,MinaArenden}.vue`,
  `src/services/api.js`, `src/store/index.js`.
- Design/analys: `hubs_arende/docs/FAS-F-DESIGN.md`, `hubs_start/docs/STATUS-OCH-ROADMAP.md`,
  workflow-output (datamodell-analys + sekretessgranskning) i sessionens task-filer.
