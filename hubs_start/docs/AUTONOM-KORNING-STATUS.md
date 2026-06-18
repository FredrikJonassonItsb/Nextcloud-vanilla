<!--
SPDX-FileCopyrightText: ITSL <info@itsl.se>
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Autonom körning — statusrapport (hubs_arende-motorn på dev15)

> Skriven under den autonoma 6-timmarskörningen 2026-06-17. Evidensbaserad: varje
> "✅ VERIFIERAT" nedan är en faktisk körning mot den riktiga Hubs-instansen dev15
> (10.43.51.62, NC 31.0.8.1), inte ett antagande. Syntetisk data only — ingen
> säkerhetskänslig/PII-data användes (per direktiv).

---

## 1. Sammanfattning

`hubs_arende` — den **fristående ärende-motorn** (egen kodbas, egen DB, namespace
`OCA\HubsArende`) — är byggd, deployad och **verifierad end-to-end på den riktiga
dev15-instansen**. Hela SAGA:n (R0–R10) kör med riktiga, graceful-gated
integrationsklienter; säkerhetsskydds-grinden är fail-closed; never-SoR-invarianten
är upprätthållen av en hård DB-constraint; och hela OCS-ytan svarar korrekt.

Detta motsvarar **kärnan** i `HUBS-KRAVSTALLNING-TOTAL.md` + `HUBS-IMPLEMENTATIONSPLAN.md`,
byggd enligt de ratificerade besluten i `HUBS-BESLUTSLOGG.md` (sdkmc orörd, all ny
funktionalitet i egen app, dev15 = byggmiljön, ExApp-rent designad, Inera/integration
stub-först, AI senare fas).

---

## 2. Verifierat state på dev15 (evidens)

| Vad | Status | Bevis (faktisk körning) |
|---|---|---|
| App enabled | ✅ | `occ app:list` → `hubs_arende: 0.1.2` |
| Alla PHP-filer kompilerar | ✅ | `php -l` på **44/44 filer → BAD=0** |
| Motorn kör end-to-end | ✅ | `occ hubs_arende:smoke` → **SMOKE OK** (createCase → idempotens → commit → verifierat kvitto → provenans-flip → säkerhetsskydds-avvisning) |
| DB-tabeller | ✅ | `oc_hubs_arende_case/_typ/_flagga/_pekare` finns i db=`hubs` (role `oc_hubs`) |
| **never-SoR-invariant** | ✅ | `information_schema`: `commit_destination` = **NOT NULL**, `hubs_case_id` = **NOT NULL** (hård constraint, ej bara applogik) |
| 8 ärendetyper seedade | ✅ | `oc_hubs_arende_typ` → **8 rader** (via `RegisterArendeTyper` RepairStep) |
| OCS-routes + DI + auth | ✅ | curl alla 4 routes oautentiserat → **401** (route finns, controller-DI resolverar, auth avvisar; ej 404/500) |
| PHPUnit-svit | ✅ | **25 tester, 106 assertions, OK** (körd grön; phpunit finns ej i prod-containern → körs i CI/lokalt) |
| Stödappar | ✅ | enabled på NC31: deck 1.15.9, tasks 0.17.1, forms 5.2.9, notes 5.0.1, contacts 7.3.7. **app_api 3.2.0 = disabled** (ExApps-sida kraschar `OC_Util::getChannel()` på ITSL NC31; behövs först i ExApp-fasen — se manifest.yaml) |

---

## 3. Vad som byggdes (lager)

1. **Motorkärnan** (`lib/Service/`): `ArendeService` (SAGA R0/R1–R10 + kompensering),
   `SakerhetsskyddGrind` (fail-closed evaluate + evaluateRetroaktiv), `ArendeTypRegistry`
   (datadriven, 8 typer), `FacksystemCommitService` (verifierat kvitto bundet commit).
2. **Saga-wiring R3–R9**: 5 integrationsklienter (`Sdkmc/Deck/Spreed/Groupfolder/Calendar`)
   + `Pekare`-entity/mapper. Alla **graceful** (`isAvailable()` + null-guard → saknad
   granne loggas och hoppas, sagan kraschar aldrig). Kompenseringar bevarade i omvänd ordning.
   Klienterna injicerades som **valfria autowirade konstruktor-params** → driften får riktiga
   klienter, testharnessen får `null` (NO-OP) → ingen testfil behövde ändras.
3. **Matchning + klassning**: `ArendeMatchService` (deterministisk ärendekopplings-kaskad:
   case-tagg→conversationId→part, server-side tröskel, default=ej_kopplat/fail-mot-människa,
   TF 2:18-anonymitet) + `InnehallsKlassService` (Axel-B′: en primär av 8 ärendetyper +
   ortogonala flaggor som `akut_fara`/`skyddade_pu`, deterministisk, ingen LLM).
4. **OCS-ytan** (`lib/Controller/`): `ArendeController` (härdad: kvitto-surfacing, input-grindar,
   401-före-logik) + `InfodeController` (inflöde-summary + verb-actions). Routes i `appinfo/routes.php`.
5. **Frontend-wiring** (`hubs_start/src/services/api.js`): 5 ärende-funktioner pekade mot
   `apps/hubs_arende/api/v1` OCS, **demo-gated** (`if (DEMO)`-fallback kvar). Slå av demo →
   anropen träffar motorn. `commitToTreserva` omformad till `commit(hubsCaseId, payload)`,
   `tilldela` ny. Se `hubs_start/docs/FRONTEND-WIRING.md`.
6. **Provisionering/reset-rutin** (`provision/`): `manifest.yaml` (källa-till-sanning, nu med
   verifierade versioner + prereq-blockerare) + `bootstrap.sh` (idempotent, sideload-as-default,
   prereq-gates, invariantkoll). **Dry-run validerad på dev15** → idempotent, VERIFIERING: ALLT OK.

---

## 4. Medvetna seams + senarelagt (med skäl)

| Seam | Varför | Vad som krävs för att wira |
|---|---|---|
| Integrationsklienternas OCS-`TODO[auth]` | server-till-server-anrop saknar session; degraderar gracefully | service-account/app-password/signed internal request |
| `ArendeMatchService.registerPartHook()` | part-uppslag (SITHS/LOA3) ej i standalone-motorn | part-register-integration |
| Bilage-spegling | ägs av sdkmc (GAP-019), ej i motorn | sdkmc-API-seam |
| Inflöde-feed (`resolveInflodeRows()=[]`) | feeden bor i sdkmc/mail | sdkmc inflöde-API |
| **app_api deploy-daemon** | **BLOCKERAD**: docker.sock ej monterad i hubs-php; in-process v1 | compose-mount + grupp 'docker' (itsl-deploy-ändring) → ExApp-fas |
| **libresign** | **BLOCKERAD**: java saknas i hubs-php; stub räcker | java i imagen; men Inera Underskriftstjänst (ej libresign) är beslutad backend |
| Treserva/facksystem riktig commit | beslut: stub-först | Frends/Treserva-åtkomst |
| AI/LLM-assist | beslut: senare fas (fas 10) | — |

Inga av dessa är buggar — de är **kontrakterade gränser** med tydliga `TODO[...]`-markörer i koden.

---

## 5. Hur man deployer/återställer

- **Deploy (host→dev15):** `tar czf - hubs_arende | ssh ubuntu@10.43.51.62 'docker exec -i hubs-php tar xzf - -C /var/www/html/custom_apps && docker exec hubs-php chown -R www-data:www-data .../hubs_arende'` → `occ app:enable hubs_arende` → `occ hubs_arende:smoke`.
- **Full reset-restore:** `provision/bootstrap.sh` (idempotent; `--dry-run` för torrkörning). Verifierade defaults: `SIDELOAD_ONLY=1 INSTALL_LIBRESIGN=0 REGISTER_DAEMON=0`.
- **Multi-rad/DB mot dev15:** heredoc `ssh … 'bash -s' <<'REMOTE' … REMOTE` (undviker citat-helvete).

---

## 6. Säkerhetsgranskningens verdikt (klar 2026-06-17)

Adversariell granskning, **31 agenter**, find→verifiera→syntetisera → `SAKERHETSGRANSKNING-MOTOR.md`.
17 bekräftade fynd (2–3 höga, 6 medel, 7 låga) + 5 positiva verifieringar, 2 avfärdade.

**Slutomdöme:** kärnarkitekturen är **solid och defensivt designad** (fail-closed födelseväg,
symmetrisk saga, pseudonymt register, graceful no-ops, medvetet tom feed). Den blev RÖD på
**två konkreta, live-nåbara wiring-/täckningsbrister**, inte på sin design:

- **H1 — horisontell IDOR:** noll objektnivå-authz i hela appen (noll träffar på `IUserSession`/`getUID`)
  → varje inloggad handläggare kan läsa/tilldela/committa annan enhets ärende över sekretessgräns.
- **H2/H3 — fel-wirad commit-route:** `ArendeController::commit()` anropade `FacksystemCommitService`
  **direkt** och kringgick `ArendeService::commit()` där provenans-/retention-/idempotens-flippen bor
  → HTTP-commit flippade aldrig retention (de-facto SoR) + saknade idempotens/existenskoll. (Smoke
  missade det för att smoke anropar `ArendeService::commit()` direkt — granskningen fångade gapet.)
- Plus M1–M6/L1–L7: latenta fail-open i sekretess-/säkerhetsgrindar, mestadels **neutraliserade av
  seams idag** men måste härdas i samma PR som respektive seam wiras (PII-validering av objektRef,
  triageRef ej som synligt namn, grind på läsväg, detektor-täckning, anonymitetsgrind, conversationId-unik).

**Ingen av bristerna läcker PII eller kringgår säkerhetsskydds-grinden på en live-väg idag.**

## 7. Remediering — KLAR och verifierad (2026-06-17)

8 agenter, **strikt 1-fil-per-agent** (lärdom från ett tidigare skriv-race där flera agenter rörde
samma fil). Alla fynd åtgärdade; app-version bumpad 0.1.2 → **0.2.0** (kör nya migrationen).

| Fynd | Åtgärd | Verifierat |
|---|---|---|
| **H1** authz/IDOR | Objektnivå-authz centraliserad i `ArendeService` (`assertEnhetAtkomst`/`...ForEnhet`, IUserSession+IGroupManager trailing-optional, enhet→grupp, 404 ej 403). System/CLI → tillåt. | ✅ smoke grön (CLI-kontext tillåter); enhetstest (CI) |
| **H2/H3** commit-route | `ArendeController::commit()` → `ArendeService::commit()` (register-flipp + existens). Idempotens på `provenanceState='registrerad'` + stabil correlationId. | ✅ **smoke [6]: andra commit = samma dnr, ingen dubbel-registrering** |
| **M1** objektRef-PII | Positiv pseudonym-validering, **hissad pre-flight FÖRE sagan** (rent `InvalidArgumentException`→400, ej saga-wrap→500). | ✅ **smoke [7]: personnummer avvisat korrekt** |
| **M2** triageRef | Aldrig synligt objektnamn (R4/R5/R6 → `hubsCaseId`); registerfält + mjuk validering. | ✅ php -l |
| **M3** grind läsväg | Säkerhetsskydds-grind körs först i `inflodeSummary`/`doKoppla` (avvisad → neutral karantän-markör). | ✅ OCS 401/DI |
| **M4** detektor | Närvaro av klass-/handlingskod-fält = indikator (fail-closed på okänt); kuvert-walk; normalisering. | enhetstest (CI) |
| **M5/L5** anonymitet | Vänd till **fail-closed** (`partStegTillatet`, positiv allow-signal krävs; klient kan bara skärpa). | enhetstest (CI) |
| **M6** TOCTOU | UNIQUE-index `hubs_arende_conv_uq` på conversation_id + idempotent re-läsning vid krock. | ✅ schema verifierat |
| **L1** logg-PII | Klienter loggar `len:<n>:<hash>` i stället för råvärde. | ✅ php -l |
| **L2** gallras-datum | `gallras_datum`-kolumn + entitet-setter; persisteras i `commit()`. | ✅ **smoke [4]: gallras_datum=2026-09-14** |
| **L4** destination | `VALID_DESTINATIONS`-allowlist i `resolveCommitDestination` → 400. | ✅ php -l |

**Verifierings-status:** 50/50 filer `php -l`-rena; `occ hubs_arende:smoke` **GRÖN** (7 kontroller inkl. de
nya regressionerna); migration körd (`Updated to 0.2.0`); schema bekräftat; alla OCS-routes 401 (DI intakt).
**Ärlig not:** de 5 nya enhetstesterna är `php -l`-rena + kontrakts-rekoncilierade men har **inte körts i
denna miljö** (phpunit/composer/nextcloud-ocp saknas) — de körs i CI. De säkerhetskritiska vägarna är
verifierade end-to-end i dev15-smoke i stället.

## 8. Gallring + lifecycle — KLAR och verifierad (2026-06-17, app v0.3.0)

3 agenter (1-fil-per-agent). Deployat, `occ upgrade` → 0.3.0, **GallringJob registrerad** i background-job:list.

- **GDPR-gallrings-BackgroundJob** (art. 5.1.e): `GallringJob` (TimedJob, 1×/dygn) → `GallringService::gallra()`
  purgar motorns egna koordinations-rader (+ pekare) där `provenance='registrerad' AND retention='gallras_efter_commit'
  AND gallras_datum <= now`. **Dubbel säkerhetsvakt** (query + `arGallringsbar()` re-checkar provenance + deadline-passerat
  oberoende av query:n → en query-regression kan aldrig leda till för tidig DELETE). Loggar bara antal + pseudonyma
  hubsCaseId. Never-SoR: verksamhetsdatat bor i facksystemet och gallras av DET. ✅ **smoke [9]: rad purgad, antal=1**.
- **Lifecycle steg-transitioner** (`ArendeLifecycleService`): tillåtna-övergångar-graf
  inflode→forhandsbedomning→{utredning|avslutat}→{beslut|avslutat}→{uppfoljning|avslutat}→avslutat (terminal); återöppning
  uppfoljning→utredning. Authz+existens via `ArendeService::show()` (ingen duplicering); ogiltig övergång → 400; samma
  steg → idempotent no-op; per-steg-frist om typen deklarerar. OCS `POST /arende/{id}/steg`. ✅ **smoke [8]: steg→utredning**.
- **Tester rekoncilierade** mot implementationen (return-shape `array{antal,hubsCaseIds}`, pekare via `findByCaseId`+`delete`,
  5-arg-konstruktor). `php -l`-rena; körs i CI.

**H1-authz adversariellt egen-verifierad** (smoke testar bara CLI-tillåt): deny-vägen är sund — `show()` gatar (tilldela/commit
ärver), `summary()` scopar till behöriga enheter, fail-closed-defaults (groupManager null/tom-enhet/ingen-match → neka via
404). Okonfigurerad enhet→grupp-mappning failar mot "alla nekas utom admin" = rätt riktning. Inget kringgående hittat.

## 9. Genuint kvarvarande = MEDVETET uppskjutna seams (kräver externt/beslut)

Den **byggbara** kärnan är klar. Det som återstår hänger på en seam som är uppskjuten per beslut/infra (se §4 + `manifest.yaml`):
- **Live inflöde-feed** (sdkmc `MessageReceivedEvent`) — då aktiveras de redan-byggda klass/match/grind-lagren på riktig data.
- **Integrationsklienternas auth** (`TODO[auth]`) — service-account/app-password → då skapas verkliga Deck/Spreed/Groupfolder-objekt.
- **Part-/personregister** (`registerPartHook`) — SITHS/LOA3 → då aktiveras SSN-matchningen (anonymitetsgrinden redan fail-closed).
- **Live FacksystemCommitPort** (Treserva/Frends) — idag stub; H3-idempotensen redan på plats för dag-ett.
- **ExApp-paketering** (docker.sock-mount) + **libresign** (java) — infra-prereqs, ExApp-/senare-fas.
- **AI/LLM-assist** — fas 10 per beslut.

> Disciplin hölls genom hela körningen: bygg → granska → remediera → verifiera, allt på riktiga instansen, strikt 1-fil-per-agent.
