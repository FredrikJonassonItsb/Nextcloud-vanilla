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

### ✅ SESSION 2c (2026-06-18/19) — GUI-FYND-FIXAR (våg 0+1 av 19 användarfynd)
Användaren GUI-testade och rapporterade 19 fynd. Root-cause-pass (7-agents workflow) → headline:
"mest tunna integrationsgap — motorn ärlig-tom, frontend wirad mot demo; ~6 äkta buggar = snabba
lågrisk-fixar, resten = 8 obyggda läs-ytor + 4 produktbeslut." NYCKELINSIKT (varför ej fångat): alla
tester kördes mot DEMO-data som maskerade backend-tomheten → behövs KOMPONENT-tester med PROD-formad
data. Full triage/plan i workflow-output + analysis-output/gui-decisions.txt.
**FIXAT+DEPLOYAT (hubs_start 1.2.11 + hubs_arende 0.7.3):**
- #4 pill 'Dokument'→'Ärenderum' (ArendeKort.vue). #14 dokument-pill renderade {{ d }} på {namn,fileid}-
  OBJEKT → '[object Object]' på riktig data; nu d.namn + tooltip (ArendeKort dokNamn/dokTitle).
- #2 'Visa' på inflöde-rad → deepLinks.resolve(rad.deepLink) (MinaArenden.onOpenTriage). #9 'Skicka' →
  composerLink med caseRef (&case=) för direkt-taggning. #7/11 'Bevakning' → deepLinks.deckLink(boardId)
  med 404-säker fallback /apps/deck/ (i st.f. hårdkodad board/2). #17 MotesRemsa binder nu state.meetings
  (live /meetings/today) + refreshMeetings() vid bokning (i st.f. A.moten).
- #8 BACKEND: createCase R9 skriver nu en referens-fil (.url + groupfolder_ref-pekare) till startmeddelandet
  i ärenderummet → ärenderummet är ej längre tomt vid födsel. DB-VERIFIERAT (case 225: groupfolder_ref
  msg-*.url). Grindar: jest 49, phpunit 68.
**ANOMALI (ej mina ändringar, ej i 19-listan):** nytt GUI-skapat orosanmalan-case sågs födas beslut+
registrerad — TROLIGEN användarens samtidiga live-testning (224 advancerade också). Bevaka; ej en bug
introducerad här (demo av, smoke bevisar ren födsel forhandsbedomning).
**EJ GJORT ÄNNU (våg 2/3, root-cause klar):** #1 inflöde-dedup(4→2)+excerpt+verifierad-källa & #19 auto-
klassning (InflodeFeedService+InflodeRad); #3/#15/#16 mapToFullCard-berikning (SpreedClient.getRoomMetadata
+ ArendeService läser pekare) — säkra-meddelanden + diskussion-summary; #10 Diskutera→spreed & #18
enhetschatt→spreed (kräver talk-token i kort-datan); #5 Treserva dokument-val (CommitGrind); #6 signering
'kommer vidare'-kryssruta; #12 personliga anteckningar (REK: Spreed Note-to-Self).

### ✅ SESSION 2d (2026-06-19, autonom natt-körning) — HELA VÅG 2/3 + ALLA gui-decisions-REK BYGGDA
**hubs_start 1.2.12 + hubs_arende 0.7.4 (deployat dev15, smoke grön, OCS-rutter 401-verifierade).**
Arbetssätt: 8-agents understand-workflow (per-yta-kontrakt + adversariell dev15-data-availability) →
unified contract (`analysis-output/wave23-contract.md`) → 4-agents backend-implement-workflow (disjunkta
filer, var självverifierad) → frontend implementerat sekventiellt av lead (kopplade filer) → bygg/deploy/smoke.
Ingen GUI-login (BankID gated) → verifierat via jest/phpunit/php -l/smoke/route-probe, EJ GUI-klick.
**BACKEND:**
- ENGINE (#3/#7/#11/#10): mapToFullCard ger nu `pekare`-block {talkToken,groupfolderId,conversationId,
  deckBoardId,deckCardId,calendarUri,bevakningBoardId} (saga-original/typ, null≠0, graceful). mapToCard
  (kollapsat kort + /arende-summary) bär talkToken+bevakningBoardId. bevakningBoardId := deck_card.riktning
  (ingen separat Bevaknings-board finns). phpunit 68→72.
- sdkmc InflodeFeedService (#1/#19): dedup på thread_root_id (behåller icke-tom preview) → fixar 4→2
  dubblett-fan-out (Orosanmälan-korg har 2 mail_accounts m. samma email); PII-skrubbad `excerpt`;
  kanal-härledd `identitet`-badge; messageType-kedja korg→ämnes-brygga→kanal; `conversationId`-ankare.
- sdkmc NoteToSelf (#12): NY OCS GET/POST /api/v1/note-to-self (in-process Spreed Note-to-Self, class_exists-
  gated, graceful-empty). sdkmc ArendeEnrichment (#3/#15/#16): NY GET /api/v1/arende-enrichment?talkToken=
  (diskussion-summary: olasta/omnamnandeTillMig/2-senaste-avsändare). TeamService (#18): `token`-fält (null
  på dev15 — inget grupp-rum). Rutterna inlagda i live routes.php (401-verifierade).
**FRONTEND (hubs_start):**
- #5 CommitGrind: dokument-urvalslista (alla förvalda, bocka av utkast) → payload.valdaDokument.
- #6 SigneringsGrind (NY): "Jag har signerat"-kryssruta → CommitGrind (ersätter abrupt libresign-redirect).
- #10 ArendeDiskussion: "Öppna diskussionen" via full.pekare.talkToken (disabled när token saknas).
- #12 MinaAnteckningar (NY): privata per-användare-anteckningar (fetchNotes/addNote), quick-action på kortet.
- #18 onOppnaTradar → spreedRoomLink(team.token) m. ärlig info-fallback. #19/#1 InflodeRad: typ-chip-fallback
  till kanal-etikett (aldrig blankt/rått id) + absent-säker excerpt/verifierad-källa-badge.
- **BUGG JAG FÅNGADE+FIXADE (våg 0+1-regression):** `deepLinks.deckLink` saknades i DEFAULT-exporten →
  #7/11 "Bevakning"-knappen kastade `deepLinks.deckLink is not a function` i runtime. Nu exporterad + regr-test.
- **CACHE-NYCKEL-FIX (HIGH):** ArendeKort/MinaArenden nycklade full[] på dnr (null för oregistrerade) → nu
  triageRef (alltid satt) → flik-innehåll + talkToken resolvar för dnr-lösa ärenden.
**TEST-HÄRDNING (root-cause-luckan):** KOMPONENT-tester aktiverade (vue-jest kunde ej kompilera SFC mot
@babel/preset-env 7 → la till babel-core@7-bridge + ncvue-stub-mock). Nya specs: inflodeRad, storeEnrich,
commitGrind, signeringsGrind, minaAnteckningar, arendeDiskussion + utökad deepLinks. **jest 49→83, phpunit
68→72**, webpack-bygg grönt, live smoke OK.
**ÅTERSTÅR (GUI-klick-verifiering — kan ej göras utan BankID-login):** klicka igenom #5 dokument-urval,
#6 signerings-modal, #10/#18 rum-öppning (kräver riktig talk-token i kort-datan), #12 anteckningar-modal mot
riktigt Note-to-Self-rum (bara uid 197411040293 har ett), #1 inflöde visar 2 ej 4 rader + excerpt/badge.
**FLAGGAT (produktbeslut/uppföljning, ej byggt):** (a) ENGINE-konsumtion av payload.valdaDokument (registrera
valda fileids som handlingar) — frontend skickar det, motorn läser det ej än; (b) #18 grupp→rum-mappning saknas
på dev15 (token=null tills ett enhetschatt-rum provisioneras); (c) #12 anteckningar är PER-ANVÄNDARE (ej
per-ärende) per beslut #12 — överväg case-scoping; (d) #3-berikning + #18 är graceful-empty på tunn dev15-data.

### ✅ SESSION 3 (2026-06-19/20) — ANVÄNDAR-STYRDA FIXAR + DRIFT-LÄRDOMAR (hubs_start 1.2.15, hubs_arende 0.7.5)
Commits `0fb67a1c`→`9e4f0645` (deployat dev15). Grindar: **jest 88, phpunit 72, bygg grönt, smoke OK inkl [8b] hela resan**.

**⚠️ TVÅ KRITISKA DRIFT-LÄRDOMAR (läs FÖRST):**
1. **KÖR ALDRIG `docker restart hubs-php`.** NC:s entrypoint kör en `apps/`-omsynk vid varje container-start som **RENSAR alla `apps/sdkmc`-tillägg** (backend-additions). `custom_apps` (hubs_start, hubs_arende) + DB överlever. opcache `validate_timestamps` är PÅ → filändringar plockas upp UTAN restart, så restart behövs aldrig. **Om det ändå hänt:** re-deploya hela `hubs_start/backend-additions/sdkmc/lib`-trädet → `apps/sdkmc`, och pusha en komplett `routes.php` (spara en kopia av den deployade innan). Jag råkade göra detta en gång och återställde allt (401-verifierat).
2. **Reset till känt läge:** `scripts/dev15-reset.sh` (committat) → 0 ärenden/pekare/kvittenser/case-taggar/ärenderum, **de 2 orosanmälningarna otaggade i "Att ta emot"**. Idempotent. Raderar ärenderum via `occ groupfolders:delete` (UUID-namn). Lämnar kvar orphan Talk-/Deck-/kalenderobjekt (osynliga). dev15 är i detta läge NU.

**FIXAT + DEPLOYAT:**
- **"Ta emot"-buggen (rot-orsak hittad):** `skapaArende` skapade ärendet men **taggade aldrig källmeddelandet** → det kom tillbaka i "Att ta emot" varje poll + fick ingen tagg. Fix: (a) `api.skapaArende` taggar nu meddelandet i ANVÄNDARENS session (`case:{id}`+`behandlad`, IDOR-säkert, auto-skapar taggen); (b) feeden exkluderar behandlade — **OBS: taggarna bor i sdkmc:s EGNA tabeller `oc_sdkmc_itsl_message_tag`+`oc_sdkmc_itsl_tag`, INTE i `oc_mail_tags` (tomma)** — `filterHandled` joinar rätt tabeller nu (fail-open). Verifierat mot riktig data.
- **#6 signering-hang FIXAD:** två staplade NcModaler (SigneringsGrind→CommitGrind i samma tick) deadlockade focus-trap/scroll-lock → UI frös. **SigneringsGrind borttagen**; signerings-bekräftelsen ("Jag har signerat") är nu en INBÄDDAD, gateande sektion i CommitGrind (`payload.kraverSignering`) → EN modal, ingen stapling. `onSignera` öppnar CommitGrind direkt.
- **Hela resan till avslut:** ny **AvslutaGrind** + nästa-åtgärd "Avsluta ärende" vid uppfoljning → `transitionSteg('avslutat')` (ren steg-övergång, ej ny commit). Smoke `[8b]` bevisar utredning→beslut→uppfoljning→avslutat live.
- **Demo-länk (#1 önskemål):** `isDemo()` läser `?demo=1/0` (sessionStorage-persist) ovanpå boot-flaggan; fot-länk "Visa i demoläge". Default AV på skarp instans.
- **PII-principen (rättad ansats):** Hubs SKA visa PII för BEHÖRIGA — invarianten är "läck aldrig över behörighetsgräns", inte "göm PII". Feeden visar nu RIKTIG ämnesrad + oskrubbad excerpt (ACL-scopad till egna korgar). Sparad som kärnprincip → auto-minne [[hubs-pii-authorization-principle]].
- **Egna anteckningar** flyttade UT ur ärendekortet till global fot-knapp (per-användare, ej ärende-bundet).
- **Test-härdning:** komponent-tester aktiverade (babel-core@7-bridge + ncvue-stub), `@nextcloud/*`-mock märkt `__esModule` (annars trasig axios-default-import). jest 49→88.

**KOPPLAT I KOD MEN EJ DEPLOYAT (kräver dig):**
- **Punkt 4 — meddelandegränssnittet:** mail-overlayns `initITSL()` anropade aldrig composer-deep-link-haken → "Skicka" (`&case=`) var en no-op. NY modul `hubs-code/mail/mail-main/overlay/src/itsl/utils/initComposerDeepLink.js` (inkopplad i `initITSL`) öppnar komponeraren + kopplar sänt meddelande till ärendet via Väg-A. **Mail-overlayn har EGEN byggkedja + kräver GUI-verify (login + riktigt skick) — jag deployade den EJ.**

**MARKNADSFÖRING/VÄRDE:** `analysis-output/VARDE-OPERATIVA-VERKSAMHETSLAGRET.md` (untracked) — beslutsfattar-underlag om det operativa verksamhetslagret (grundkoncept, 6 personas, tidsvinster: Digg ~30 min/ärende + modellerat ~2–3 v/handläggare/år, compliance, differentiering).

**KVAR ATT GUI-KLICK-VERIFIERA (BankID-login krävs — jag kan ej):** hela resan Ta emot→tagg→ut ur feed→förhandsbed→utredning→beslut→signering(nu fixad)→uppföljning→avsluta; demo-länken; overlay-bygget för punkt 4. **Untracked i git:** `analysis-output/VARDE-*.md`, `analysis-output/rapport/`.

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
