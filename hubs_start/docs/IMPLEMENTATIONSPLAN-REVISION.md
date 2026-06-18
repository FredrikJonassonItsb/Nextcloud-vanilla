<!--
SPDX-FileCopyrightText: 2026 ITSL
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# HUBS — Implementationsplan, REVISION 1 (ägar-ratificering 2026-06-16)

**Datum:** 2026-06-17 · **Status:** ratificerad revisionsnot · **Gäller:** `HUBS-IMPLEMENTATIONSPLAN.md`
**Beslutsförfattare:** ägaren, ratificering 2026-06-16. Denna not går FÖRE masterplanen där de skiljer sig. Masterplanen behålls som referens; punkterna nedan ersätter/justerar den.

> **TL;DR:** sdkmc lämnas helt orörd. Allt nytt byggs som EN ny standalone Nextcloud-app, **`hubs_arende`**, i egen kodbas med egen DB, som konsumerar sdkmc via OCS. **dev15 ÄR** bygg- och utvecklingsmiljön (ingen separat bygg-instans) — hålls ren och återställbar, och våra tillägg återförs efter reset via en repeterbar provisioneringsrutin som mergas in i den gemensamma itsl-deploy. ExApp-vägen är juridiskt godtagen → vi bygger in-process NU men ExApp-rent → paketeras som ExApp senare. Inera-signering är målet, vi börjar med stub. AI är en senare fas.

---

## 1. Vad som ändras mot masterplanen (7 ratificerade punkter)

| # | Masterplanen sa | Ratificerat (gäller nu) | Påverkar §§ |
|---|---|---|---|
| **R-1** | STEG A = bryt sdkmc i `sdkmc-core` (M0) + `sdkmc-msg` (M1) FÖRE M4 (BESLUT-03) | **sdkmc lämnas ORÖRD. Ingen M0/M1-split, inget `class_alias`, ingen ny app-id i sdkmc.** sdkmc äger fortsatt skicka/ta emot meddelanden, taggar, korgar, retention. | §4.2, §4.1 STEG A, §6 (B-MOD-1), roadmap P3 |
| **R-2** | Registret bor i sdkmc:s app-DB (alt. b), namespace `OCA\SdkMc\Core\*`, lyfts till ExApp-DB (alt. c) | **ALLT nytt = en NY standalone-app `hubs_arende` i EGEN kodbas + EGEN DB.** Namespace `OCA\HubsArende`. Den **konsumerar sdkmc via OCS/events** (anropar sdkmc tag-API för `case:`-taggar, läser inflöde). Ingen kod skrivs in i sdkmc. | §4.3, §4.5, §3, §2.3 |
| **R-3** | Separat ren bygg-/integrations-instans; dev15 = read-only referens (BESLUT-06) | **dev15 ÄR bygg- och utvecklingsmiljön** — ren, återställbar, komplett och merge-mål. Ingen separat bygg-instans. | §1, §2.6, §5.1 #1, §6.1, BESLUT-06 |
| **R-4** | (Implicit: artefakter persisteras ad hoc via survive-matrisen) | **Repeterbar provisioneringsrutin "på sidan" (`provision/`)** som återställer våra tillägg efter en dev15-reset, och som **mergas in i den gemensamma `itsl`-deploy.** | §2.4, ny §6 nedan |
| **R-5** | Licens öppen fråga; IP-jurist bokas FÖRE proprietär M4 (BESLUT-02) | **ExApp-vägen är GODTAGEN av IP-juristen** (proprietär M4-väg klar). `hubs_arende` byggs in-process NC-app NU men **designas ExApp-rent** → paketeras som ExApp senare. | §4.5, §5.1 #4, BESLUT-02/05 |
| **R-6** | P4 `UnderskriftPort`: libresign-demo → Inera (GAP-033/034/035) | **Inera-signering = MÅL, börja med stub** (`SigneringPort` + stub-adapter). Ingen libresign-runtime krävs för att bygga ärende-motorn. | §3.1 P4, §2.1.3 |
| **R-7** | AI/transkribering som port P7 (LÅST stub) | **AI = senare fas.** Bekräftas: ingen AI i den deterministiska klass-/match-vägen; AI är opt-in, avstängbart, människo-bekräftat, och skjuts till en framtida fas. | §3.1 P7, §4.4 C3 |

**Oförändrat (bär genom revisionen):** Hubs är ALDRIG System of Record · `commit_destination` NOT NULL · retention startar ENBART på verifierad facksystem-callback · säkerhetsskydd avvisas fail-closed FÖRE registret föds · endast syntetisk data, hård PII-spärr · `case:{id}`-taggen är join-mekanismen · saga R1–R10 med kompensering · idempotens på `conversationId`.

---

## 2. Reviderad målarkitektur

```
  Internet ──TLS──▶ itsl reverse-proxy ──▶ [hubs-php / NC 31 på dev15]   ◀── PUBLIK YTA
                                              │
            ┌── TUNN AGPL-BRO (in-process, "dum") ──┐        ┌──── sdkmc — ORÖRD ────┐
            │  hubs_start (Vue 2.7-frontend + skal) │        │  skicka/ta emot msg   │
            │  djuplänk · auth · event-vidarebefordran│  ◀──▶ │  taggar · korgar      │
            └───────────────┬───────────────────────┘  OCS   │  retention            │
                            │                                 └───────────────────────┘
                            │  OCS  (/ocs/v2.php/apps/hubs_arende/api/v1/...)
                            ▼
        ┌──── hubs_arende — STANDALONE ÄRENDE-MOTOR (egen kodbas, egen DB) ────┐
        │  NU: in-process NC-app (AGPL), designad ExApp-rent                   │
        │  SENARE: paketeras som ExApp (egen process) — juridiskt godkänt      │
        │  saga R1–R10 + kompensering · ArendeTyp-registry · match · commit    │
        │  SakerhetsskyddGrind (fail-closed, FÖRE R1)                          │
        │  Integration-portar: FacksystemCommit · Signering(→Inera) · Ediarium │
        │  EGEN DB: hubs_arende_case/_typ/_flagga/_pekare (opaka pekare)       │
        │  KONSUMERAR sdkmc via OCS (tag-API för case:-taggar, läser inflöde)  │
        └──────────────────────────────────┬───────────────────────────────────┘
                                           │  (mjuka beroenden, AppDetectionService)
     M1 Meddelanden (sdkmc/mail/securemail) · M2 Video&Chat (spreed-itsl) · M3 Filer (groupfolders)
```

**Förändringen mot masterplanens bild:** (1) sdkmc visas nu som en **orörd granne** som `hubs_arende` *konsumerar via OCS* — inte som källa för en M0/M1-split. (2) "M4 verksamhets-ExApp med egen intern Postgres" ersätts av **`hubs_arende` standalone-app med egen NC-app-DB nu** (`oc_hubs_arende_*`), designad ExApp-rent så att DB:n kan lyftas till egen Postgres när appen paketeras som ExApp. (3) Allt körs på **dev15** som bygg-/dev-miljö.

**Invariant genom alla faser (oförändrad):** `hubs_arende` lagrar ENDAST koordinations-state (register/pekare/routing) — ALDRIG verksamhetsdata (den bor i facksystemet). `commit_destination NOT NULL`.

---

## 3. Den nya appen: bindande kontrakt (`hubs_arende`)

Detta ersätter masterplanens "intern DB i sdkmc-core" (§4.3) och "M4-ExApp"-skiss (§4.5) som **konkret leverabel**.

- **App-id:** `hubs_arende` · **Namespace-rot:** `OCA\HubsArende` · **Vendor:** ITSL · **Licens:** AGPL-3.0-or-later (in-process NC-app nu, designas ExApp-rent). **NC-kompat 30–32** (dev15 kör NC 31.0.8) · **PHP 8.1+** · SPDX-header i varje fil.
- **Roll:** standalone ärende-motor i egen kodbas, egen DB; konsumerar sdkmc via OCS/events. Lagrar koordinations-state, aldrig verksamhetsdata.

**DB-tabeller** (Migration, NC-prefix `oc_` läggs på automatiskt): `hubs_arende_case` (registret; `hubs_case_id` UUID UNIQUE, `commit_destination` NOT NULL, `status`, `steg`, `provenance_state`, `retention_state`, `frist_due`, `arende_typ` fk) · `hubs_arende_typ` (process-mall-registry; `arende_typ_id` pk, `commit_destination`, `frist_policy`, `acl_profil`, hooks) · `hubs_arende_flagga` (cross-cutting flaggor) · `hubs_arende_pekare` (opaka pekare `deck_card|talk_room|groupfolder|calendar|case_tag`). Inga `oc_*`-FK:er; alla NC-objekt som opaka strängar/int.

**Nyckelklasser** (exakta signaturer styr över filgränser): `AppInfo\Application` · `Db\Arende`+`ArendeMapper`, `ArendeTyp`+`ArendeTypMapper` · `Service\SakerhetsskyddGrind::evaluate()` (fail-closed, FÖRE allt) · `Service\ArendeTypRegistry::get()` · `Service\ArendeService::createCase()` (saga R1–R10 + kompensering, säkerhetsskydds-grind FÖRST, `commit_destination` NOT NULL, idempotent på `conversationId`) + `tilldela()`/`commit()` · `Service\FacksystemCommitService::commit()` (verifierat kvitto) · `Integration\Port\{FacksystemCommitPort,SigneringPort,EdiariumPort}` + `Integration\Stub\*` (default `INTEGRATION_MODE=stub`) · `Controller\ArendeController extends OCSController`.

**OCS-routes** (`appinfo/routes.php`, `'ocs' =>`, prefix `/ocs/v2.php/apps/hubs_arende/api/v1/...`): `POST /api/v1/arende` · `GET /api/v1/arende/{ref}` · `GET /api/v1/arende-summary` · `POST /api/v1/arende/{ref}/tilldela` · `POST /api/v1/treserva/commit`.

**Grundas i VERKLIG sdkmc-kod** (mönster, inte ändring): `lib/Db/ItslTag.php` + `ItslTagMapper` (QBMapper) · `lib/Migration/Version020008*.php` · `lib/AppInfo/Application.php`. Saga/register-shape ur `HUBS-INTERNALS-ARENDEMOTOR.md`; ArendeTyp-fält ur `ARENDETYPER-FLODESANALYS.md`.

> **OBS frontend-route-prefix:** masterplanen §4.6/BESLUT-21 patchar `api.js` v1→v2 mot sdkmc-routes. Den nya appens egna routes ligger på `hubs_arende/api/v1` (egen app, egen versionering). `api.js` ska peka dashboardens ärende-grenar mot `hubs_arende`-prefixet — inte mot sdkmc. (sdkmc:s egna OCS-routes är fortsatt `/api/v2/` och rörs inte.)

---

## 4. dev15 som bygg-/dev-miljö (ersätter "separat bygg-instans")

Masterplanens §2.6/§5.1#1/BESLUT-06 krävde en separat ren bygg-instans med dev15 som read-only referens. **Ratificerat: dev15 ÄR bygg- och utvecklingsmiljön.** Konsekvenser:

- **dev15 hålls ren, återställbar och komplett** — den är merge-målet. Utforskande/destruktivt arbete sker på dev15, men varje tillägg ska kunna återställas deterministiskt efter en reset.
- **All install/scaffolding görs på dev15** via det verifierade occ-mönstret `sudo docker exec -u www-data hubs-php php /var/www/html/occ <cmd>` och itsl-mönstret `sudo itsl <...>`.
- **PII-regeln gäller fullt ut på dev15:** endast syntetisk data, hård PII-spärr — eftersom dev15 nu är arbetsbänken, inte bara en referens.
- **Promotion** bygg(=dev15) → stage → prod behålls; dev15 är första (och nu enda) itsl-managed bygg-/integrationssteget.

---

## 5. Repeterbar provisioneringsrutin "på sidan" (`provision/`)

Ny leverabel som masterplanen saknade konkret. Eftersom dev15 är arbetsbänken och ska kunna återställas, behövs en **idempotent, repeterbar rutin** som återför våra tillägg efter en reset — och som sedan **mergas in i den gemensamma `itsl`-deploy.**

- **Plats:** `provision/` (egen mapp "på sidan", utanför app-koden). Innehåll: skript/manifest som (1) installerar/aktiverar saknade appar, (2) deployar `hubs_arende` + bro-app, (3) kör `occ upgrade` (migrationer), (4) seedar `hubs_arende_typ` (8 rader, idempotent check-then-insert), (5) sätter config (`INTEGRATION_MODE` per port, demo-flaggor).
- **Egenskaper:** idempotent (köra två gånger = samma sluttillstånd), deterministisk syntetisk seed, ingen handredigerad config.php — allt via occ/itsl config.
- **Slutmål:** rutinen är en **bro till `itsl deploy`** — den mergas in i den gemensamma itsl-deploy så att `hubs_arende` + bro + appar + seed överlever en deploy/reset per konstruktion (ersätter ad hoc survive-matrisen i §2.4 för våra artefakter).

---

## 6. Reviderade P0-steg (ersätter masterplanens §6.1 "Nästa 5 steg")

1. **`hubs_arende`-app-skelett i egen kodbas** (id `hubs_arende`, namespace `OCA\HubsArende`, AGPL, SPDX, NC 30–32, PHP 8.1+, `AppInfo\Application` + DI). Grunda QBMapper/Migration-mönstret i verklig sdkmc-kod. Designa ExApp-rent (inga `oc_*`-FK, single-writer via `ArendeService`). *(Ersätter gammalt steg 1+2; ingen separat bygg-instans, ingen sdkmc-split.)*
2. **Egen DB via Migration** — `hubs_arende_case/_typ/_flagga/_pekare`, `commit_destination` NOT NULL avvisar null-insert, `UNIQUE(hubs_case_id)`, 8 seed-rader i `hubs_arende_typ` (idempotent). Test mot sqlite+postgres + rollback. *(Realiserar R-2.)*
3. **Säkerhetsskydds-grinden FÖRST** — `Service\SakerhetsskyddGrind::evaluate()` fail-closed, körs FÖRE allt i `ArendeService::createCase()`; avvisning → ingen rad/tagg/rum, bara avvisningskvitto + audit. Mata syntetiskt säkerhetsskyddsklassat inflöde, bevisa att inget `hubs_case_id` föds. *(P0-SÄK, lagbrottsgolv.)*
4. **Integration-portar + stubbar** — `FacksystemCommitPort` (+ `SigneringPort` mot Inera-mål, `EdiariumPort`) med `INTEGRATION_MODE=stub` per port via `IAppConfig`. Stub levererar verifierad async callback `{ok,dnr,committedAt,gallrasDatum,verifierad:true}` (Frends-mönstret). **Signering: stub nu, Inera mål.** *(Realiserar R-6.)*
5. **`createCase()`-sagan R1–R10 + kompensering** mot stubbarna — single-writer, `commit_destination` NOT NULL, idempotent på `conversationId`, konsumerar sdkmc tag-API via OCS för `case:`-taggar. Verifiera atomicitet, kompensering (R(n-1)…R1), commit-bunden retention (flip ENBART på verifierad callback). Första körbara e2e-skivan. *(Stänger GAP-056/010/057/007 mot stub.)*
6. **Repeterbar provisioneringsrutin** (`provision/`, §5) som återställer steg 1–5 på dev15 efter reset, sedan mergas in i `itsl`-deploy. *(Realiserar R-3+R-4.)*

**Senare faser (oförändrad ordning, justerat scope):** ArendeTyp-driven klass/match (8 kategorier, deterministisk, AI senare) → riktiga grannar en i taget (groupfolders ▶ Deck ▶ Spreed ▶ CalDAV) → riktig facksystem-konnektor (Frends) + **Inera-signering live** → **ExApp-paketering** av `hubs_arende` (DB-lyft till egen Postgres, juridiskt klart) → **AI-fas** (transkribering/utkast, opt-in, människo-bekräftat).

---

## 7. Beslutslogg-justeringar

| BESLUT | Före | Efter ratificering 2026-06-16 |
|---|---|---|
| **BESLUT-01** Datalager | (b) sdkmc-DB nu → (c) ExApp-DB | **Egen DB i ny app `hubs_arende`** nu (NC-app-DB), ExApp-rent → ExApp-DB senare. Sdkmc-DB rörs inte. |
| **BESLUT-02** Licens | öppen, boka IP-jurist | **STÄNGD: ExApp-vägen godtagen av IP-jurist.** Bygg in-process nu, ExApp-rent, paketera som ExApp senare. |
| **BESLUT-03** sdkmc M0/M1-split | dela FÖRE M4 | **UTGÅR. sdkmc lämnas orörd.** Separationen sker via en separat app (`hubs_arende`), inte via split. |
| **BESLUT-06** Bygg-instans | separat ren instans, dev15 read-only | **dev15 ÄR bygg-/dev-miljön** (ren, återställbar, merge-mål) + repeterbar provisioneringsrutin. |
| **BESLUT-21** Route-prefix | sdkmc `api/v1`→`v2` | Ärende-routes ligger på **`hubs_arende/api/v1`** (egen app). sdkmc-routes oförändrade. |

Övriga beslut (07/11/13/16/25 m.fl.) står oförändrade.

---

**Sammanfattning:** Revisionen byter strategi från "bryt loss en kärna ur sdkmc och bygg en M4-ExApp på en separat instans" till "**lämna sdkmc orört och bygg en separat standalone-app `hubs_arende` med egen DB, på dev15, som konsumerar sdkmc via OCS, designad ExApp-rent (juridiskt klar) och paketerad som ExApp senare, återställbar via en repeterbar provisioneringsrutin som mergas in i itsl-deploy.**" Säkerhetsskydds-golvet, no-SoR-invarianten och commit-bunden retention bär oförändrade. Inera-signering är mål via stub; AI skjuts till en senare fas.
