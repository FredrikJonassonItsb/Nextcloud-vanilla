# Hubs — komplett översyn mot ursprunglig kravställning

**Nattanalys 2026‑07‑05.** Flerfas multi‑agent‑genomlysning av hela appen (hubs_arende‑motorn, hubs_start‑frontenden, sdkmc‑backend‑tilläggen) mot de 268 kraven i `HUBS-KRAVSTALLNING-TOTAL.md` (K‑1…K‑8) plus sekundärspecarna. Metod: kravinventering → täckningsanalys mot faktisk kod med fil:rad‑evidens → adversariell verifiering → kvalitets‑/risksvep i 8 dimensioner → syntes.

> **Läsanvisning.** Rapporten säger först vad som är **solitt** (så bilden blir rättvis), sedan de **allvarligaste fynden** grupperade i teman, sedan **prioriterad plan**. Allt kod­påstående är citerat `fil:rad`. Fynd är märkta **BEKRÄFTAT** (adversariellt verifierat av en skeptiker som försökte fälla det) eller **PLAUSIBELT** (kodläst och citerat i en pass, men verifieraren hann inte köras — se metodnoten).

---

## 0. Sammanfattning på en sida

**Kärnmotorn och socialsekreterar‑UI:t är i förvånansvärt gott skick.** Ärendemotorn (K‑3) är den mest kompletta delen: 3‑identifierarmodellen, sagan R0–R10+T med kompensationer, `commit_destination NOT NULL`‑invarianten (hävdad i schemat, inte bara konvention), medlems‑/ACL‑modellen på skrivsidan och retention‑flippens disciplin (aldrig tid/kryssruta) är byggda och till stora delar testade. Dashboard‑UX:t (K‑6) är genomarbetat. **Det du bad om under de senaste sessionerna sitter.**

**Men tre lager återstår innan produktion — och de sammanfaller med de tre svagast täckta kravkapitlen:**

| Kapitel | TÄCKT | DELVIS | SAKNAS | Tolkning |
|---|---|---|---|---|
| K‑3 Ärendemotorn | 13 | 13 | 1 | **Stark** — kärnan sitter |
| K‑6 UI/UX + demoläge | 9 | 20 | 1 | **Bra** — flödet fungerar |
| K‑1 Scope/roller/moduler | 8 | 14 | 5 | Principer följs, breddning kvar |
| K‑2 Modul/licens/datalager | 8 | 8 | 2 | OK |
| K‑4 Klassificering/routing | 5 | 17 | 8 | Halvbyggt |
| K‑8 Demo/stub/gap/byggplan | 14 | 26 | 15 | GAP‑019 dominerar |
| **K‑5 Roller/SoR/integrationer** | **4** | 18 | **18** | **Utsidan är stub** |
| **K‑7 Sekretess/juridik/signering** | **1** | 18 | **17** | **Juridiklagret tunnast** |

**Total: 62 TÄCKT · 134 DELVIS · 67 SAKNAS · 5 BESLUT** (av 268). 14 P0‑blockerare och 108 P1 identifierade.

**De tre återstående lagren:**
1. **Utsidan (GAP‑019)** — hela facksystem‑/Frends‑/Inera‑integrationen är stub. Port‑kontrakten finns (bra förberedelse), noll live‑konnektorer. Mestadels EXTERN‑blockerat (kräver avtal).
2. **Juridik‑/bevisvärdeslagret** — PuB‑matris, signeringsbevis, retention‑paus, skyddsbedömnings‑persistens, åtkomstlogg. Utan detta är produkten enligt kravdokumentet "juridiskt osäljbar".
3. **Breddningen** — bara socialsekreteraren är djupbyggd; de 11 övriga rollerna är config‑profiler som ännu inte instansierats.

**Den enskilt allvarligaste upptäckten** är inte ett saknat krav utan en **kedja som ser klar ut men inte är säker**: en felkonfigurerad prod‑instans kan idag tyst fabricera ett "verifierat" commit‑kvitto → flippa retention → **fysiskt förstöra register + journal medan den faktiska personinformationen ligger kvar** i Talk/groupfolder/Deck. Det är en olaglig gallring av allmän handling (TF/arkivlagen) + en GDPR‑radering som raderar kartan men inte datat. **Botas till stor del av små fail‑closed‑grindar** (se P0‑A nedan).

---

## 1. Metod & tillförlitlighet

- **Fas 1 (15 agenter):** extraherade alla 268 krav med självbärande beskrivningar + kartlade 7 kodområden.
- **Fas 2 (8+16 agenter):** en agent per kapitel klassade varje krav mot **faktisk kod** (Grep/Read, fil:rad‑evidens). 16 adversariella verifierare skulle dubbelkolla.
- **Fas 3 (8+50 agenter):** 8 dimensionssvep (sekretess, saga, data, UX, drift, test, prestanda, juridik) + skeptiker per P0/P1‑fynd.

**Viktig begränsning (förbrukningstak):** månadens spend‑tak slog i mot slutet. Konsekvens:
- **Täckningsklassningen** (268 krav) är kodläst och citerad men **de 16 adversariella kravverifierarna hann inte köras**. Klassningarna är väl grundade men inte dubbel­granskade — behandla enskilda TÄCKT/SAKNAS som "hög sannolikhet", inte "bevisat".
- **Kvalitetssvepets fynd:** 14 fynd hann **adversariellt bekräftas (0 avfärdade — högt signalvärde)**, 36 P0/P1‑fynd är **PLAUSIBLA** (en pass, citerade, ej refuterade).

Råmaterialet ligger i `analysis-output/natt-2026-07-05/` (per‑kapitel‑krav, kodkartor, alla fynd med evidens).

---

## 2. Vad som är SOLITT (verifierat)

Detta ska inte byggas om — det är fundamentet resten står på:

- **3‑identifierarmodellen (K‑1.2, TÄCKT).** `hubs_case_id` UUID (mintas R1, CSPRNG), `dnr` nullable (sätts först vid verifierad commit), `conversation_id` idempotensankare — separata kolumner med rätt födelse­semantik. `Version000000…:65-136`.
- **Hubs aldrig SoR (K‑1.5, TÄCKT).** Schema­hävdat (endast koordinationskolumner), PII‑mönster i `objektRef` avvisas med test (`ArendeService.php:346-350`), referensfiler är `.url`‑pekare aldrig innehåll.
- **`commit_destination NOT NULL` (K‑1.6, TÄCKT).** NOT NULL‑constraint på både typ‑ och case‑rad + server‑side allowlist med kravets sex värden + fail‑fast före INSERT. `Version000000…:112-204`, `ArendeService.php:79-86`.
- **Sagan R0–R10+T med kompensationer** och retention‑flippens disciplin (flippar **endast** på `kvitto['verifierad']===true`, aldrig tid/kryssruta). `ArendeService.php:1852-1873`.
- **Medlems‑/ACL‑modellen på skrivsidan.** Per‑case NC‑grupp = åtkomstlista, handoff‑avsmalning (mottagningskrets revokeras vid tilldelning) — GAP‑057 stängt på skrivsidan.
- **Graceful degradation‑ställningen.** `isAvailable()` i alla 6 klienter, honest‑null‑deeplinks i frontend.
- **Socialsekreterar‑flödet** (triage→ta emot→kvittera→commit→utredning) inkl. de 6 fixarna från förra sessionen (dubbelklicksgard, kortrubrik, bevakningar, kollega‑validering, rumsnamn, omfördela).

---

## 3. De allvarligaste fynden (P0)

### P0‑A — Gallrings‑/retentionskedjan är den största juridiska risken

Fem fynd konvergerar mot samma kärna: **kedjan commit → retention‑flip → gallring kan förstöra register/journal utan att förstöra den faktiska personinformationen, och kan triggas av en fejkad "verifierad" commit.**

1. **Tyst stub‑fallback fullbordar en olaglig gallringskedja** *(P0, juridik, KÄNT‑ESKALERAT)*. `Application.php:137` gör `$modeMap[$mode] ?? $default` — ett `mode='live'` utan bunden adapter (eller stavfel i config) faller **tyst** till `FacksystemCommitStub`, som mintar dnr + kvitto med `verifierad=true` + `gallrasDatum=+90d` (`FacksystemCommitStub.php:29-30,82`). `ArendeService::commit` flippar då `provenance='registrerad'` → retention → gallring river register + journal. **En felkonfad prod raderar allmän handling som aldrig registrerades någonstans.** → **Fix S: fail‑closed `resolvePort` (kasta i stället för `?? $default`) + occ‑statuskommando som visar effektivt mode per port.**

2. **GDPR‑gallringen river kartan men inte datat** *(P0, data, PLAUSIBELT)*. `GallringService.php:114-145` raderar referensfiler/team/pekare/member/journal/grupp/registerrad — men **ingen** `sdkmcClient/spreedClient/groupfolderClient/deckClient/calendarClient` injiceras (`:47-71`). Teardown‑koden **finns redan** (`DemoSeedService::tearDownExternal:192-228` river groupfolder/talk/deck/kalender/tagg/team) men är bara wirad till demo‑purge. Resultat: Talk‑rummen (med klientnamn), akt‑filerna på disk och Deck‑korten **ligger kvar** medan pekarna som skulle hitta/revidera dem raderas **först**. Samma hål dokumenteras i `dev15-reset.sh:32-34`. → **Fix M: bryt ut `tearDownExternal` till en delad `ArenderumTeardownService`, anropa den per pekare FÖRE pekar‑raderingen.**

3. **Retention‑flippen sker ENBART i commit()-vägen** *(P1, data, NYTT)*. `findGallringsbara` kräver `provenance='registrerad' AND retention='gallras_efter_commit'`. Enda skrivpunkten är `commit()`. Ett kat6‑ärende (`diariefor_direkt`) föds registrerat men `buildEntity` sätter `retention='aktiv'` (`:2443`) — så **kat6, triage_forward, karantän och avslutade‑utan‑registrering blir aldrig gallringsbara** och lever för evigt. → **Fix M.**

4. **Ingen retention‑paus vid TF‑utlämnandebegäran** *(K‑7.23 / GAP‑031, SAKNAS)*. Gallringsklockan kan radera en begärd handling mitt under prövning. Mekaniken finns nästan gratis (`findGallringsbara` respekterar redan `retention='pausad'`). → **Fix S: `POST /arende/{ref}/retention-paus` + häv + journalpost.**

5. **Mail‑speglade taggarna saknar raderingsväg** *(P1, data, NYTT)*. F2b‑speglingen skriver `case:{uuid}`/`behandlad` till `oc_mail_tags`/`oc_mail_message_tags` men **ingen kod raderar spegeln** — den överlever `dev15-reset`, gallring OCH retroaktiv karantän (`ItslTagService.php:85-232`). PII‑bärande koppling kvar efter "radering". → **Fix S.**

### P0‑B — Inre sekretess (OSL 26 kap) läcker på LÄSsidan

Skrivsidans handoff‑avsmalning är solid, men **läs‑API:t korsar samma gräns den skrivsidan försvarar.**

6. **OCS‑läsytan är enhets‑grindad, inte per‑ärende** *(P1, sekretess, BEKRÄFTAT)*. `show/historik/medlemmar/bevakningar/dokumentlista` auktoriseras **enbart** av `enhetTillaten()` (grupp‑id‑match, ingen `MemberMapper`‑koll — `ArendeService.php:2047-2072`). När ett ärende tilldelats och mottagningskretsen revokerats ur foldern/gruppen kan **varje kollega kvar i enhetsgruppen** fortfarande GET:a ärendet och läsa medlemslista, koordinationspekare och **hela journalen**. Värre: `arenderumDokument` läser `__groupfolders/{id}` via `IRootFolder` (system‑scope, förbi per‑user‑ACL, `:2378-2411`) → **dokumentnamn (som kan bära klientnamn) läcker** till enhetskollega utan folder­åtkomst. → **Fix M: kräv medlemskap i ledgern (eller gruppledarroll) på läsytorna efter tilldelning; otilldelade ärenden förblir enhets‑synliga.**

7. **`laggTillMedlem` tillåter självupphöjning** *(P1, sekretess, BEKRÄFTAT)*. `POST /arende/{ref}/medlem` grindas bara av enhets‑`show()` — ingen koll att anroparen redan är på ärendet. En enhetskollega som medvetet aldrig kopplats in kan POST:a `{uid: sig själv, roll: co_handlaggare}` → `syncArenderumGrupp` ger folder‑åtkomst + `addParticipant` ger chatt‑åtkomst. Samma endpoint saknar dessutom enhets‑grind på **mål**‑uid → kan lägga till en användare från **annan enhet** som observatör (äkta tvär‑enhets‑läcka). `ArendeService.php:1477-1509`. → **Fix M: kräv att anroparen är medlem/gruppledare + enhets‑grinda mål‑uid; återanvänd i `taBortMedlem`.**

8. **`SummaryController::receipts` är instansbred** *(P1, sekretess, BEKRÄFTAT)*. `GET …/receipts` kräver bara inloggning och kör `SELECT * FROM sdkmc_message_receipt ORDER BY id DESC` utan `WHERE user/korg` — leveranskvittenser (mottagaradresser) för **hela instansen** läcker. `SummaryController.php:151-223`. → **Fix M: scopa till anroparens behöriga korgar.**

9. **`MeetingController::lobby` är en IDOR** *(P1, sekretess, BEKRÄFTAT)*. Väntrummets gästlista (medborgarnamn) returneras för **godtyckligt mötestoken** utan deltagarkoll. `MeetingController.php:59-66` + `MeetingService.php:117-147`. → **Fix S: verifiera att anroparen är deltagare/moderator.**

10. **Medborgar‑e‑post loggas på INFO** *(P1, sekretess, BEKRÄFTAT)*. `SecureMeetingService.php:433,459,517` skriver `['email'=>$email]` i klartext i loggrader. → **Fix S: PII‑säkert digest (samma mönster som `GroupfolderClient::safeRef`).**

### P0‑C — Hela utsidan är stub (GAP‑019, den erkänt tyngsta blockeraren)

`FacksystemCommitService` är korrekt generaliserad, destination‑gated och fail‑closed på modulrouting — men **noll live‑konnektorer** finns (`K-3.22, K-5.10, K-8.17/19` alla DELVIS/SAKNAS). Port‑kontraktet + stateful stub + kontraktstester är rätt förberedelse. Detta hindrar produktion och är **mestadels EXTERN** (kräver Frends/Treserva‑avtal). Motor‑sidan behöver bara: (1) fail‑fast i `resolvePort` (= P0‑A.1, gör nu, S), (2) första `FrendsCommitPortLive` + signerad async callback‑route (L, EXTERN), (3) ärv‑modul‑mekanismen för komplettering/verkställighet (M).

### P0‑D — Juridik‑/bevisvärdeslagret (K‑7: 1 TÄCKT av 36)

11. **PuB‑/laglig‑grund‑matris saknas trots ratificerat BESLUT‑12** *(K‑5.29/K‑7.32, SAKNAS)*. `ArendeTyp`‑entiteten saknar `lagligGrund, personuppgiftsansvarig, andamalsbegransning, gdpr_art9` (`ArendeTyp.php:56-77`, 0 grep‑träffar) — trots att BESLUT‑12 ratificerades med "config‑fält från dag 1" och registret byggdes **efter** ratificeringen. Enligt dokumentet: utan matrisen är produkten "juridiskt osäljbar" (PuB‑avtal + RoPA omöjliga). **Schemat kan byggas nu; innehåll per kund är beslut (T‑PUB‑1).** → **Fix S/M: migration + 4 nullable fält + seed‑värden för de 8 typerna.**

12. **Signering är ett honor‑system utan bevisvärde** *(K‑7.14, DELVIS + P1 juridik)*. `CommitGrind` gatear "För över" på kryssrutan "Jag har signerat" — men `signeradBekraftad` skickas aldrig i payloaden, motorn läser varken den eller `valdaDokument` (0 träffar), `SigneringPort` har noll konsumenter. Ett SMS‑/kontosignerat avslagsbeslut passerar grinden. → **Fix M: persistera signeringsintyg {metod, intygadAvUid, tid, dokumentHashRef} i journalen; Inera‑enforcement är EXTERN (B‑SIGN‑1).**

13. **Skyddsbedömnings‑kvittensen (SoL 11:1a) är ett flyktigt boolean** *(P1, juridik, KÄNT‑ESKALERAT)*. Pliktgrinden läser `kontext['skyddsbedomningKvitterad']` men journalen skriver bara `{fran, till}` — **vem** som kvitterade och **när** dokumenteras ingenstans (`ArendeLifecycleService.php:119-148`). → **Fix S: journalför kvittensen (`TYP_SKYDDSBEDOMNING`).**

14. **Ingen läs‑/åtkomstlogg** *(P1, juridik, NYTT)*. Journalen loggar bara mutationer — läsningar (`show`, historik, case‑messages, bevakningar) loggas ingenstans. Otillåten intern slagning kan varken upptäckas eller utredas — vilket i sig är ett compliance‑krav för socialtjänst. → **Fix M: åtkomstlogg‑tabell med egen (längre) gallringsfrist, eller kanalisera till NC `admin_audit`.**

---

## 4. Viktiga P1‑teman

### Saga‑robusthet
- **Klientfel är fail‑open i R3–R9 + idempotensankaret gör det permanent** *(BEKRÄFTAT‑närliggande)*. Klienterna sväljer HTTP/auth‑fel → null, sagan behandlar det som "app saknas" → hoppar steget utan kompensering, och `conversation_id`‑ankaret gör att en retry returnerar den halvfärdiga raden. **Ärenden kan födas utan akt/chatt och aldrig läkas.** → Skilj "app ej installerad" från "anrop misslyckades" (typad `IntegrationException` → kompensering → retry).
- **Misslyckad kompensering lämnar spökrad** som ser komplett ut (status sätts redan vid INSERT) och fångar idempotensankaret för alltid *(BEKRÄFTAT)*. → INSERT:a i `status='skapas'`, flippa i R10, sweep städar strandade rader.
- **Omfördelning är ingen ren handoff** *(NYTT)*: `tilldela()` raderar aldrig förra handläggarens `ROLL_HANDLAGGARE`‑rad → hen behåller folder‑/chatt‑åtkomst och ärendet i "Mina ärenden". (Sekretessrelevant — jag fixade ägarbytet i förra sessionen, men ledger‑raden städas inte.) → radera tidigare ägar‑roll i `tilldela()`.
- **R2‑race hoppar kompenseringen** för kat6 → dubbel diarieföring utan spår *(BEKRÄFTAT)*. `:404-419` returnerar `$winner` utan `compensate()`. → **Fix S.**

### Data & drift
- **Ingen `UserDeletedEvent`‑lyssnare** *(NYTT)*: raderas en handläggares konto (normal offboarding) strandas dess ärenden tyst med död `agare_uid`. → registrera lyssnare som nollställer ägare + notifierar.
- **CI körs aldrig** *(BEKRÄFTAT)*: workflowen ligger i `hubs_arende/.github/` — inte i repo‑roten — så de 87+95 testerna **gate:ar ingenting**. → **Fix S: flytta `ci.yml` till `Nextcloud-vanilla/.github/workflows/` + lägg jest‑jobb.**
- **Session 4–5c‑koden är okommittad** *(NYTT)*: hela wipe‑återställningskällan (backend‑additions, migrations, scripts) ligger som modified/untracked. → committa (kräver din tillåtelse).
- **Service‑kontot saknar provisioneringssteg** och koden refererar en doc som inte finns (`ServiceAccountAuth.php:24`). → skriv `provision/service-account.md` + bootstrap‑steg.
- **FristVarselJob utan catch‑up**: exakt‑match `dagarKvar===3||===0` → en missad jobbdag = lagfrist‑varsel borta för alltid. → fönster + `varslad_t3/t0`‑flagga.

### UX‑ärlighet
- **Backendfel visas som lugnande tomtillstånd** *(NYTT)*: `state.error` sätts men renderas ingenstans → "allt inkommande är omhändertaget" när motorn i själva verket felade. → felband + retry.
- **Gruppledarens fördelningsvy är bruten i live** *(KÄNT‑ESKALERAT)*: `onFordela/onOmfordela` → `inflodeAction('tilldela')` → sdkmc svarar `400 okand_atgard`. → **Fix S: byt till `api.tilldela()` (redan byggd).**
- **'Gallra' ger falsk juridisk kvittens** ("Gallrat med dokumenterat stöd — loggat") på en backend‑no‑op.
- **KopplaValjare listar råa 36‑teckens UUID:n** i vyns mest sekretesskritiska val (felkopplingsrisk). → använd kort‑ref (6 tecken) som redan finns.
- **Mötesbokningens SMS‑fel sväljs** av en generisk "Säkert möte bokat"‑toast.

### Prestanda (biter i drift)
- **`findAll(200)`‑taket gör "Mina ärenden" funktionellt fel** vid >200 ärenden — frist‑ärenden **försvinner ur vyn** *(BEKRÄFTAT)*. → filtren in i SQL (JOIN mot member, WHERE enhet), paginering efter filtret.
- **N+1 ~5 queries/kort** → ~1000 queries vid 200‑taket per sidladdning *(BEKRÄFTAT)*. → batch‑hämta pekare (`WHERE hubs_case_id IN (…)`), memoisera `ArendeTypRegistry`.
- **DeckClient hämtar hela boarden** per operation *(BEKRÄFTAT)*; **SummaryService ~300 queries/poll + cache som aldrig träffar** (TTL 20s < poll 30s) + obegränsad `NOT IN`‑lista som till slut slår i parametertaket → **triagekön blir tyst tom** *(BEKRÄFTAT)*. → anti‑join i SQL, TTL > poll, persistera stackId.

---

## 5. NYTT vs KÄNT

**Redan känt/i backlog** (bekräftat att det fortfarande gäller): GAP‑019 (hela utsidan stub), Inera/signering EXTERN, PuB‑matris (BESLUT‑12), retention‑paus (GAP‑031), reconciliation/backup (GAP‑056/BESLUT‑19), gamla Talk‑rum överlever reset, fördelningsvyns tilldela‑routing.

**Nytt eller eskalerat ikväll** (viktigast): den tysta stub‑fallbackens **olagliga gallringskedja** (P0); gallringen river **kartan men inte datat** (P0); läsytans **inre‑sekretess‑läcka** + `arenderumDokument` **förbi ACL**; `laggTillMedlem` **självupphöjning**; `receipts`/`lobby` **IDOR**; **CI gate:ar ingenting**; `findAll(200)` gör vyn **funktionellt fel**; **ingen UserDeleted‑lyssnare**; mail‑taggar **oraderbara**; skyddsbedömning/signering **utan bevisvärde**; **ingen åtkomstlogg**.

---

## 6. BESLUT som krävs (bygg INTE ensidigt)

Dessa är kund‑/verksamhetsbeslut, inte buggar:
- **T‑PUB‑1** — innehållet i PuB‑matrisen per kommun (schemat kan dock byggas nu, P0‑D.11).
- **B‑SIGN‑1** — Inera‑avtal + SITHS‑ledtid (enda vägen till bevisbärande myndighetsunderskrift).
- **T‑SOR‑1** — utfall (iii) "tidsbegränsat mellanlager" per ärendetyp (rättslig grund + gallringsregel).
- **B‑SEC‑1** — säkerhetsskyddsgrindens terminerande utfall (avvisa/karantän vs snävare ACL).
- **Mail‑retentionens koppling** till case‑retention (sdkmc `ExpungeJob` är idag separat tid/tagg‑styrd).
- **Ex‑handläggarens roll** efter omfördelning (helt bort, eller kvar som observatör?).

---

## 7. Prioriterad plan

### Steg 0 — små fail‑closed‑grindar som tar bort oproportionerlig juridisk risk (gör först, alla S)
1. **`resolvePort` fail‑closed** (kasta vid okänt mode i st.f. `?? $default`) + occ‑statuskommando. *Stoppar den olagliga gallringskedjan.*
2. **`resolvePort` samma fix** blockerar även syntetiska "verifierade" dnr i live‑läge.
3. **`retention-paus`‑endpoint** (TF‑skydd).
4. **`MeetingController::lobby` deltagarkoll** (IDOR).
5. **PII‑digest i `SecureMeetingService`‑loggar.**
6. **Flytta `ci.yml` till repo‑roten** + jest‑jobb (så de 182 testerna faktiskt gate:ar).
7. **Fördelningsvyn → `api.tilldela()`** (bruten live‑vy).
8. **R2‑race: anropa `compensate()`** före `return $winner`.

### Steg 1 — sekretess‑ & datakedjan (P0/P1, mestadels M)
9. **Per‑ärende läsgrind** på `show/historik/medlemmar/bevakningar/dokumentlista` + `arenderumDokument` via anroparens mount.
10. **`laggTillMedlem`/`taBortMedlem` authz** (anroparen på ärendet + enhets‑grinda mål‑uid).
11. **`receipts` korg‑scoping.**
12. **`GallringService` river externa objekt** (delad `ArenderumTeardownService`) + per‑rad‑felisolering + mail‑tagg‑radering.
13. **Retention‑flip även utanför commit** (kat6/triage/karantän) + `UserDeletedEvent`‑lyssnare.
14. **Persistera skyddsbedömning + signeringsintyg + åtkomstlogg** i journalen.
15. **PuB‑matris‑schemat** (4 fält + seed).

### Steg 2 — robusthet, prestanda, UX‑ärlighet (P1)
16. Saga: skilj klientfel från saknad app; `status='skapas'` + reconciliation‑sweep; omfördelnings‑handoff städar ledger; Talk‑deltagarsynk.
17. Prestanda: SQL‑filter i `dashboardArenden`, batch‑pekare, `ArendeTypRegistry`‑memo, Deck‑stackId, SummaryService anti‑join + TTL.
18. UX: felband vid `state.error`, kort‑ref i KopplaValjare, ärliga möte‑/gallra‑kvitton.

### Steg 3 — utsidan & breddning (L / EXTERN)
19. GAP‑019: `FrendsCommitPortLive` + async callback (kräver avtal).
20. Inera‑adapter bakom `SigneringPort` (kräver avtal).
21. Instansiera nästa roll (registrator/e‑diarium) som config‑profil för att bevisa (modul × produkt)‑mappningen.

---

*Underlag: `analysis-output/natt-2026-07-05/` (268 klassade krav, 82 svep‑fynd med fil:rad‑evidens, kodkartor). Rapporten är en genomlysning — inga kodändringar gjordes. Säg till om du vill att jag börjar bygga Steg 0 (de små grindarna ger mest riskreduktion per rad) eller går djupare i något tema.*
