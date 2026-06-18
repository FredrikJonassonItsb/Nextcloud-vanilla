<!--
SPDX-FileCopyrightText: ITSL <info@itsl.se>
SPDX-License-Identifier: AGPL-3.0-or-later
-->
# HANDOVER → mig själv: fortsatt utveckling av orosanmälan-flödet

Skriven 2026-06-18 för en NY kontext. Auto-minnet (`MEMORY.md` →
[[hubs-orosanmalan-livetest]], [[hubs-start-app]], [[hubs-ops]], [[hubs-local-tests]],
[[arenderum-agarmodell]]) laddas automatiskt — läs det FÖRST, denna fil är den
operativa fortsättnings-guiden ovanpå det.

---

## 0. SYFTET med testet (förstå varför, inte bara hur)

Bevisa att Hubs centrala löfte — *"helheten sitter ihop"* — faktiskt håller för den
mest utvecklade personan (**socialsekreterare**), genom att köra **orosanmälan
end-to-end i det RIKTIGA gränssnittet som en riktig handläggare**:

> riktigt meddelande i SDKMC → **ta emot** (triage) → **ärende + ärenderum** (Talk-rum,
> groupfolder, kalender, Deck-kort, medlemmar) → **dokument** skapas → **möte** genomförs
> → **tilldela** → **steg** (förhandsbedömning→utredning→beslut→uppföljning) → **commit
> till Treserva** (facksystem) → **kvittens + gallringsklocka**.

Varje artefakt ska **landa i ärenderummet** och **synas korrekt i hubs_start**. Testet
är inte "klickar knappen?", utan "**hamnar resultatet rätt och syns det för handläggaren?**".
Kör ALLTID från riktiga meddelanden i SDKMC (ej demo-stubbar) — `demo_mode 0`,
inflöde-demo-grind `0`.

---

## 1. Miljö (var allt bor)

- **dev15** (NC **31**.0.8.1): `ssh -o BatchMode=yes ubuntu@10.43.51.62` (sudo för docker).
  Containrar: `hubs-php`, `hubs-postgres` (db `hubs`, role `oc_hubs`), `hubs-apache`.
  App-paths: `custom_apps/hubs_start`, `custom_apps/hubs_arende`, **`apps/sdkmc`** (ej custom_apps).
- **Versioner nu:** hubs_start **1.2.9**, libresign **11.6.0**, sdkmc patchad (SecureMeetingService/
  MeetingService/InflodeFeedService), hubs_arende (ArendeService/InfodeController patchade).
- **Lokalt repo** = `C:\Users\fredrik.jonasson\Cursor\Nextcloud-vanilla` = cwd.
- ⚠ **EFEMÄRT i containern** (försvinner vid container-recreate/`itsl deploy`): libresign-appen,
  `apk add openjdk21-jre-headless poppler-utils`, och ALLA `apps/sdkmc`-tillägg. Måste bakas in i
  imagen / upstream för persistens (se backend-additions/MANIFEST.md).

## 2. GUI-testrecept (optimerat — browsern är instabil)

- Browser = **"Windos"** (Edge, Chromium) via Chrome-MCP. `list_connected_browsers` →
  `select_browser <deviceId>`.
- **JAG KAN INTE LOGGA IN** (BankID/lösenord förbjudet). Användaren måste vara inloggad.
  Funktionskonton med orosanmälan-korg-åtkomst: `axel.israelsson` ELLER uid `197411040293`
  ("Nils Olov Fredrik Jonasson") — båda i `hubs-case`-gruppen för mailbox 4.
- **notify-push FRYSER sidan** (icke-idle) → `javascript_tool` och `read_page` timeoutar sporadiskt.
  Mönster som funkar: **FÄRSK flik** (`tabs_create_mcp`) är mest responsiv direkt efter navigate.
  När JS funkar: klicka via **realClick-helper** (full pointer-event-sekvens — `.click()` på NcButton
  är opålitligt). När JS fryser: `read_page` (väntar ej på idle ibland) + `computer left_click {ref}`.
  `location.reload()` förstör JS-kontexten → läs i SEPARAT anrop efter ~4 s.
- **Riktig orosanmälan:** korg `orosanmalan@gruppbox` (itsl mailbox 4), meddelande
  "Orosanmälan – elev i klass 1B". Demo-grind AV: `occ config:app:set sdkmc hubs_start_inflode_demo --value 0`.
- **Lyckad happy-path (verifierad):** triage "Ta emot & starta förhandsbedömning" → nytt ärende
  föds i `forhandsbedomning` → "→ Treserva" → "För över" → `registrerad` + dnr + steg→`utredning`.
- **DB-verifiering (sanning):** `sudo docker exec hubs-postgres psql -U oc_hubs -d hubs -c
  "SELECT id,steg,status,provenance_state,dnr,conversation_id FROM oc_hubs_arende_case ORDER BY id DESC;"`
  + `oc_hubs_arende_pekare` (talk_room/groupfolder/deck_card/calendar/groupfolder_ref) + `oc_hubs_arende_member`.

## 3. Bygg/deploy/verify-recept (exakt)

- **jest:** `cd hubs_start && npm test` (49 tester).
- **phpunit (hubs_arende):** `MSYS_NO_PATHCONV=1 docker run --rm -v "/c/Users/fredrik.jonasson/Cursor/Nextcloud-vanilla/hubs_arende:/app" -w /app composer:2 php vendor/bin/phpunit -c phpunit.xml` (55).
- **php -l:** samma composer:2-image, `php -l <fil>`.
- **smoke (end-to-end motor):** `ssh … 'sudo docker exec -u www-data hubs-php php /var/www/html/occ hubs_arende:smoke'`.
- **bygg hubs_start:** `cd hubs_start && NODE_ENV=production npm_package_name=hubs_start npm_package_version=<v> node node_modules/webpack/bin/webpack.js --config webpack.js`. Bumpa `appinfo/info.xml <version>`.
- **deploy hubs_start:** `MSYS_NO_PATHCONV=1 tar czf - js appinfo/info.xml | ssh … 'sudo docker exec -i hubs-php tar xzf - -C /var/www/html/custom_apps/hubs_start && chown -R www-data …'` → sen `occ config:app:set hubs_start installed_version --value <v>` + `occ upgrade` (cache-bust). SEPARERA tar-pipe och verify-anrop (annars klobbras pipen).
- **deploy sdkmc-tillägg:** från `hubs_start/backend-additions/sdkmc`, tar `lib/Service/<fil>.php` → `-C /var/www/html/apps/sdkmc`.
- **deploy hubs_arende:** från `hubs_arende`, tar `lib/...` → `-C /var/www/html/custom_apps/hubs_arende`.

## 4. Status: vad SITTER (verifierat) vs vad som ÅTERSTÅR

### ✅ SESSION 2 (2026-06-18 forts.) — HELA SOCIALFÖRVALTNINGSFLÖDET PÅ MOTOR-NIVÅ
**hubs_arende 0.7.0 → 0.7.1 (deployat dev15, live-smoke grön).** Adversariell gap-analys
(16-agents workflow) → bekräftat kontrakt → implementerat + verifierat. Alla 8 ärendetyper kör nu
createCase→(hook)→commit→livscykel end-to-end (ej bara orosanmälan):
- **HOOK-INFRA** — datadriven hook-dispatch (`dispatchHook` + `hookHandlers()` map, hook-id→closure,
  ingen `if(kategori===N)`-gren). `EdiariumPort` injicerad som trailing-optional i `ArendeService`.
- **RT-1 (kat6 `diariefor_direkt`)** — pre-saga-hook FÖRE R2: e-diariet registrerar led-0, ärendet
  **föds 'registrerad' + dnr** (omvänd ordning). Idempotent compose: senare commit() kortsluts via
  receiptFromRegistered(). Fail-closed om porten saknas. Live: `dnr@birth=SN-2026-0101`, diarium=1.
- **FAM-1 (kat8 `familjeratt_yttrande`)** — post-commit-hook i commit():s verified-block, **best-effort**
  (fäller aldrig kvittot), körs en gång. Live: `postHook=yttrande(diarium=1)`.
- **MODUL-FAILCLOSED** — `FacksystemCommitService::resolveModul` kastar nu istället för att tyst defaulta
  `ifo_barn` (felrouting=sekretessincident). Ärv-modul-typer (komplettering/verkställighet, frendsModul=null)
  → commit FAIL-CLOSED. Live verifierat.
- **ORO-1 (pliktGrind fas-spärr)** — `ArendeLifecycleService::transitionera` blockerar
  förhandsbedömning→utredning för pliktGrind-typ utan **explicit** `$kontext['skyddsbedomningKvitterad']`
  (ej cirkulär steg-härledning). förhandsbedömning→avslutat ogated. Live: blockerad-utan=JA, tillåten-med=JA.

**Grindar gröna:** jest 49, **phpunit 68** (55→68, +13 nya: hooks/fail-closed/plikt-grind),
php -l, **live `occ hubs_arende:smoke`** (9 djupsteg + per-typ 8/8 + plikt-grind). DB-verifierat:
rattsligt_tvang→registrerad+SN-2026-0101/diarium, familjeratt→registrerad+2026-IFO-0506/facksystem.
Smoke utökad i `lib/Command/Smoke.php` (per-typ-loop + introspektion via EdiariumStub::getDiarium).
Nya tester: `tests/Unit/Service/ArendeServiceHookTest.php`, `tests/Unit/Integration/FacksystemCommitServiceModulTest.php`,
+4 ORO-1-metoder i `ArendeLifecycleServiceTest.php`. **Ej git-committat** (hubs_arende/hubs_start är untracked; ingen push utan instruktion).

### ✅ SESSION 2b (2026-06-18 forts.) — GUI E2E-VERIFIERAT (riktig orosanmälan, inloggad som Fredrik Jonasson)
Versioner: **hubs_arende 0.7.2 + hubs_start 1.2.10** (deployat dev15). Register rensat (DELETE _case/_pekare/_member/_flagga,
behöll _typ) → triagerade en RIKTIG orosanmälan i GUI → **hela kedjan verifierad end-to-end** (GUI + DB):
ta emot → case 224 (orosanmalan, conversation_id=riktig Horde-msg-id) → ärenderum (groupfolder 159 + talk_room btb4ziet +
deck_card 153 + calendar .ics + 2 mottagningskrets-medlemmar; "Öppna ärenderum" öppnar groupfoldern) → pliktGrind låser
steppern ("Kvittera skyddsbedömningen först") → "Kvittera skyddsbedömning" → CommitGrind (NcModal) → "För över" →
**registrerad + dnr 2026-IFO-0501 + retention gallras_efter_commit + gallras 2026-09-16** → steg→**utredning** (kortet visar
"Registrerad i Treserva … Hubs-rum gallras sep. 2026").
**REGRESSION JAG FÅNGADE + FIXADE:** ORO-1 var bara backend-wirad — `ArendeController::steg` släppte kontext, så GUI:s
steg-advance (förhandsbedömning→utredning) hade blockerats av plikt-grinden. Komplett fix: tråda `skyddsbedomningKvitterad`
controller→api.transitionSteg→store→MinaArenden.onCommitted (verifierad commit = kvittering). Grindar om: phpunit 68, jest 49.
GUI-verifierat: steg-advance släpps förbi grinden, ingen plikt-grind-avvisning i loggen.
**EJ KÖRT i GUI (gränser):** Möte-wizarden ÖPPNAR korrekt men EJ submittad (kräver medborgarens personnummer = förbjudet att
mata in; bokning skickar riktig BankID/SMS-inbjudan = gated). Tilldela finns EJ i socialsekreterar-kortet (ligger i gruppledar-
fördelningsvyn, annat roll-läge). Signera ej kört. Dokument: ärenderummet öppnas tomt (korrekt för nytt ärende).

### ÅTERSTÅR efter session 2 (prioriterad)
- **AB-01 (kat2 insats-sub-typ-router)** — BYGGS EJ ensidigt: kräver (a) bekräftelse att `insatsTyp` finns
  på inflöde-raden, och (b) en **migration** (persistera resolverad frendsModul/insatsTyp på case-raden så
  modulen överlever till commit — annars defaultar ansokan_bistand till `ifo_vuxen`, möjlig felrouting för
  LSS/ek_bistånd-insats). MODUL-FAILCLOSED fångar bara null-modul, ej fel-default. **Top nästa steg.**
- **DESLUT (fixa EJ ensidigt):** FAM-2 partsmodell-datamodell, FAM-3 partsåtskild ACL (familjeratt_inre_sekretess,
  Δ5/GAP-058), AB-04/AB-06 (behörighets-grind/diarieför-vid-start), KOMPL-07 (tillförs befintligt dnr),
  per-kommun funktionsadresser (ekonomi@ vs ekb@). Plus B-besluten ur kravställning: B-MOD-1 (sdkmc M0/M1-split),
  B-LIC-1 (proprietär ExApp+IP-jurist), B-SEC-1 (säkerhetsskydd+retroaktiv karantän), B-PUB-1 (PuB-/laglig-grund-matris).
- **BY-DESIGN (rör ej):** speglasUrTreserva⇒null frist; born-in-forhandsbedomning (steg ⊥ forstaAtgard);
  pure-attach (komplettering/verkställighet) standalone-create gateas i klassningslagret (ArendeMatchService [BYGGS]), ej motorn.

### KÄRNFLÖDET (session 1, GUI-verifierat) — kvarstår
**KÄRNFLÖDET sitter enligt spec + är GUI-verifierat** (se [[hubs-orosanmalan-livetest]] för detalj):
skapa→förhandsbedömning→commit(registrerad+dnr)→steg→utredning; ärenderum (Talk/groupfolder/Deck/
kalender/medlemmar) skapas; koppla-referens skrivs; dagspuls/nästa-åtgärd/plikt fylls. Grindar gröna
(jest 49, phpunit 55, smoke 9/9). Fixhistorik v1.2.4→1.2.9 + libresign + möte-buggar i minnet.

**ÅTERSTÅR — gap-backlog (av 34 verifierade gap, ~14 djupa ej fixade):** möte-ärende-bindning FRONTEND
(#17 — backend tar emot dnr, MeetingWizard skickar det ej än), Väg-A server-authz per-meddelande (#9/10),
Frends live-port (#11, stub by-design dev15), skyddsbedömnings-materialisering i sagan (#7/13),
persona-per-grupp (#26), preSagaHook kat-6 (#28), KategoriBadge (#3), koppla/gallra-detaljer (#8/29/30).
Plus: de våg-1-fixar som deployats men ej GUI-klick-verifierats (möte-shape, dokument-flik, dagspuls-filter,
retention-text) — verifiera dem via GUI.

## 5. ⚠ HAR VI MISSAT NÅGOT? — granska URSPRUNGLIGA kravställningen

**Viktig blind fläck:** hela gap-analysen (34 gap) var **scopad till orosanmälan-socialsekreterar-flödet**.
Den ursprungliga kravställningen är MYCKET bredare. NÄSTA steg innan "100%":

1. **Läs `hubs_start/docs/HUBS-KRAVSTALLNING-TOTAL.md`** (268 krav K-1.1…K-8.55) + `HUBS-DASHBOARD-ANALYS.md`
   + `docs/KOMMUNROLLER-SOR-INTEGRATIONER.md` + `docs/ARENDETYPER-FLODESANALYS.md`. Mappa: vilka K-krav
   är **täckta vs missade**?
2. **De 7 andra ärendetyperna** (ansökan_bistand, ekonomi, komplettering, vard_samverkan, rattsligt_tvang,
   verkstallighet, familjeratt) — bara orosanmälan är djuptestad. Var och en har egen första-åtgärd,
   commitDestination, frist, ev. pre/post-hook. Kör samma flöde-test per typ.
3. **commitDestination-variation** (facksystem/diarium/e-arkiv/extern_myndighet/triage/karantän) — INVARIANTEN
   "icke-null commitDestination" + No-SoR-fallen (46 fall, 4 utfall). rattsligt_tvang (#28 preSagaHook
   `diariefor_direkt` — diarieför FÖRE ärenderum) är otestad.
4. **Andra personor/kommunroller** (12 sekretess-roller; gruppledare-fördelning, HSL, överförmyndare,
   registrator…) + persona-per-grupp (#26).
5. **Juridik/datalager** (öppna beslut i BESLUTSLOGG): PuB-/laglig-grund-matris (osäljbart utan den),
   datalager-beslut (Tables UNDERKÄND → sdkmc egen DB → ExApp), modularisering M0/M1-split (#B-MOD-1).
   Dessa är BESLUT, inte buggar — flagga för användaren, fixa inte ensidigt.

**Rekommenderat arbetssätt (samma som funkade):** multi-agent workflow — (1) fan-out gap-analys
spec-vs-kod per krav-område, adversariellt verifierad; (2) fan-out implementering **1 fil/agent mot
delat kontrakt**; sen integrera + bygg/deploy/GUI-verifiera SEKVENTIELLT (delad dev15/browser).
LÄRDOMAR: definiera kontraktet exakt (annars dubbel-logik, jfr commit-steg-dubbel-advancen);
verifiera att endpoints en fix pekar på FAKTISKT har data (jfr inflöde-regressionen: hubs_arende:s
feed är owirad/tom — behåll sdkmc).

## 6. Gränser/gotchas (komprimerat)
- Container-ändringar efemära (§1). git push ALDRIG utan instruktion. Inga lösenord matas in.
- Brand-regel: aldrig "Nextcloud"/"Talk"/"Circles" i kund-UI.
- Numerisk uid (197411040293) blir PHP int-array-nyckel → `array_map('strval',…)`.
- Deck/Calendar soft-delete (`deleted_at IS NULL`). OCS terminal `{action}` binder ej på POST i NC31
  (löst via `actionFromUri()`). Cache-bust = info.xml-bump + occ upgrade.
- Testärenden på dev15 (206/209/212 m.fl.) kan rensas via `occ hubs_arende:seed-demo --purge` om ren start önskas.
