# sdkmc

## SUMMARY
Backend-additions är ~6 900 rader additiv kod (9 tjänster, 9 OCS-controllers, 3 dashboard-widgets, demo-data, mail-snippet) som deployas manuellt till apps/sdkmc på dev15 — 14 OCS-routes är 401-verifierade och inflöde-feeden är dessutom verifierad mot riktig data i GUI-E2E. Tjänsterna läser genomgående riktiga källor (mail_*, sdkmc-tabeller, CalDAV, Contacts, NC-grupper, Spreed in-process) med graceful-empty-fallback; hårdkodat är begränsat till LOA='LOA3', team-räknare 0, favoriter stale:true och en config-gated syntetisk inflöde-dataset (default AV). KRITISKT: ingenting är inbakat i imagen eller upstream-forken — hubs-code/sdkmc/sdkmc-main innehåller INGEN av additionsfilerna och saknar 'ocs'-blocket i routes.php, så en container-restart raderar allt och återställning kräver manuell re-deploy plus handbyggd routes.php (komplett kopia saknas i repot). Mail-tillägget (punkt 4) är färdigwirat i overlay-källan men mail-overlayn är EJ ombyggd/deployad.

## DETAILS
## 1. MANIFEST.md — innehåll och route-patchar

`C:/Users/fredrik.jonasson/Cursor/Nextcloud-vanilla/hubs_start/backend-additions/MANIFEST.md` (uppdaterad 2026-06-17):

- **Deploy-state (rad 12–17):** ALLA sdkmc-additioner deployade + verifierade på dev15 (`/var/www/html/apps/sdkmc/lib/`, sdkmc 2.2.25, NC 31.0.8). Varje OCS-route svarar 401 oautentiserat; backup `.hubsbak` togs på `appinfo/routes.php` + `lib/AppInfo/Application.php` FÖRE in-place-edit.
- **Märkningskonvention (rad 19–24):** nya filer = SPDX + `HUBS-START BACKEND-ADDITION · UPSTREAM-KANDIDAT · Target: lib/...`; in-place-ändring ENDAST i `appinfo/routes.php` (ny `'ocs'`-nyckel) omsluten av `HUBS-START-ADD`-markörer. **`Application.php` rörs INTE** — OCS autowire:as; widget-registreringen är "frivillig och utelämnad" → **widgets är byggda men INTE registrerade/aktiva**.
- **Boundary-tabell (rad 29–36):** meddelande/kontakt/möte = sdkmc; ärende = hubs_arende. sdkmc:s `inflode/{action}` avvisar ärende-verb med `400 agas_av_arende_motorn` (verifierat i `InflodeFeedController.php:116–122`).
- **Fil→target-tabeller:** basblock rad 63–77 (Channel/Summary/Meeting/SecureMeeting-services, Summary/Recipient/SecureMeeting/Meeting-controllers, 3 widgets) + Fas 2 rad 40–47 (Team/Favoriter/InflodeFeed service+controller).
- **Route-block:** rad 87–97 (7 rutter) + rad 49–55 (4 rutter, Fas 2).
- **⚠ MANIFEST SLÄPAR EFTER trädet:** filerna från session 2d (2026-06-19) — `NoteToSelfWrapperService.php`, `NoteToSelfController.php`, `ArendeEnrichmentService.php`, `ArendeEnrichmentController.php`, `demo-data/InflodeDemoData.php`, `demo-data/favoriter/` — finns i trädet men saknas i MANIFESTens tabeller; deras routes dokumenteras bara i controller-docblockar (`NoteToSelfController.php:32–35`, `ArendeEnrichmentController.php:16–17`).
- **Mail-sektion (rad 120–127)** + hubs_start-registrering i byggplattformen (rad 129–141, `hubs-apps/setup-apps.list` + `occ config:system:set defaultapp`).

## 2. Tjänsterna — riktig data vs hårdkodat (alla under `backend-additions/sdkmc/lib/`)

| Tjänst | Datakälla | Riktig data? | Hårdkodat/stub |
|---|---|---|---|
| **SummaryService** (984 r) | `ItslMailboxMapper`, `AccountItslMailboxMapper`, `ItslTagMapper`, direkta QB-frågor mot `mail_messages/mail_mailboxes/mail_accounts` (rad 519–575, 612–655), `sdkmc_message_receipt` (663–689), `sdkmc_itsl_message_tag` | JA — riktiga unread/otilldelat-räknare, kvittorader, bevakningar (absence-rader), dnr-extraktion ur subject | `resolveLoa()` returnerar ALLTID `'LOA3'` (rad 902–908, TODO); receipt `updated_at` alltid null (679); 20s distribuerad cache |
| **ChannelClassificationService** (151 r) | Ren logik (suffix `@sdk/@personlig/@gruppbox/@fax/@sms/.securemail` rad 69–79; medborgar-heuristik e-post/personnummer→secure rad 98–122) | n/a (deterministisk klassificerare, ingen datakälla) | — |
| **InflodeFeedService** (804 r) | Samma mailbox-ACL-modell som Summary; QB mot `mail_*` INBOX (269–303); triage-filter joinar sdkmc:s EGNA taggtabeller `sdkmc_itsl_message_tag`+`sdkmc_itsl_tag` (`behandlad`/`case:%`, fail-open, rad 320–368); dedup på thread_root_id (389–433); innehållstyp korg→ämnesheuristik→kanal (524–587); transport-badge (602–610) | JA — verifierad mot riktig data (2 orosanmälningar, dedup 4→2, behandlad-exkludering; commit 953c4f43) | **Demo-grind**: app-config `sdkmc`/`hubs_start_inflode_demo`='1' → `InflodeDemoData::summary()` (rad 64–74, 124–132, 152–167), default '0'=AV. OBS docblock-drift: `previewExcerpt` (613–638) beskriver PII-skrubb i rubriken men skrubbar MEDVETET INTE längre (PII-till-behörig-principen) — bara whitespace+160-teckens-cap |
| **SecureMeetingService** (593 r) | Talk-rum IN-PROCESS via `\OCA\Talk\Service\RoomService` (fix rad 182–204, loopback-OCS-fallback 401:ar utan session); CalDAV-event via `ICalendarManager`/`ICreateFromString` (258–304); BankID-krav durabelt i DB via `ConversationBankIDAuthMapper` (379–408); dnr in i ICS som `CATEGORIES:hubs-dnr-*`+`X-HUBS-DNR` (345–349) | Delvis — rumsskapande+kalender+BankID-persist är riktiga operationer | SMS/securemail-intents fortfarande PHP-SESSION-nycklade (TODO rad 411–420, 437–443); `addEmailParticipant`/`addUserParticipant` går via loopback-OCS UTAN credentials (TODO 499, 524) → sannolikt tyst 401 server-side (loggas, fäller ej bokningen) — medborgar-inbjudan overifierad |
| **MeetingService** (474 r) | CalDAV-search på `LOCATION` innehållande `/call/` (156–188); Talk-rum/lobby via nullable `\OCA\Talk\Manager`/`ParticipantService` (359–431); BankID-badge via inline-SQL mot `sdkmc_conv_bank_auth` (443–473) | JA där källor finns; graceful-empty när Talk/CalDAV saknas | — (TODO: mapper-metod i st.f. inline-SQL) |
| **NoteToSelfWrapperService** (139 r) | Spreeds `NoteToSelfService`+`ChatManager` in-process, `class_exists`-gated (48–59) | JA — läser/skriver riktiga note-to-self-meddelanden | Läsväg ärlig-tom utan spreed; skrivväg kastar → controller svarar 503 (avsiktligt) |
| **ArendeEnrichmentService** (181 r) | Spreed `Manager`+`ChatManager` in-process; 30 senaste kommentarer → @-omnämnande-bool + max 2 opaka actorIds (94–117) | JA för omnämnande/deltagare | `olasta` ALLTID 0 (rad 124, ärlig — ingen unread-källa); `meddelanden`/`moten` alltid tomma (engine äger dem) |
| **TeamService** (260 r) | NC `IGroupManager`/`IUserManager` (enhet = NC-grupp); rums-token via `Manager::getRoomByObject('room', gid)` (215–245) | JA — riktigt medlemskap + display-namn | `olasta`/`omnamnanden` hårdkodade 0 (185–191, TODO Talk-unread); `narvaro`='unknown', `status`=null (170–172); `token`=null på dev15 (inget grupp-rum); HIDDEN_GROUPS=admin/guest_app |
| **FavoriterService** (488 r) | `OCP\Contacts\IManager` — explicita adressböcker med 'favoriter' i namnet (141–173); vCard-pekare `X-HUBS-SDK-REF`/`X-HUBS-USER-REF`/fax | JA — läser riktiga vCards | DIGG/user-directory-resolvern EJ byggd → klass a/c ALLTID `stale:true` (rad 239) + proveniens "Kunde inte färskhetskontrolleras" (334–340) + `identitet`=null (323–329); tom på dev15 utan seed |
| **Widgets** (AttHantera/Kvittenser/DagensMoten, ~240 r st) | Tunna projektioner: `SummaryService`/`buildReceipts` (KvittenserWidget:139)/`MeetingService::getTodaysMeetings` (DagensMotenWidget:141) | Koden riktig men **INAKTIV** — aldrig registrerade i `Application.php` (MANIFEST rad 23–24) | Hela ytan = byggd-men-ej-wirad |

**Controllers** (`SummaryController` 390 r, `RecipientController` 303 r — söker cachad DIGG-adressbok ur app-config + `ItslAccountService`-interna brevlådor, max 50 träffar; `InflodeFeedController` med verb-boundary; `TeamController`/`FavoriterController`/`MeetingController`/`SecureMeetingController`/`NoteToSelfController`/`ArendeEnrichmentController`) är alla tunna, `#[NoAdminRequired]`, 401 utan användare, aldrig 500.

## 3. KRITISKT — persistensproblemet

- **Bekräftat i docs:** `hubs_start/docs/HANDOVER-FORTSATTNING.md:40–42` (efemärt: libresign, apk-paket, ALLA `apps/sdkmc`-tillägg) och rad 187–188 (drift-lärdom 1): **NC:s entrypoint kör apps/-omsynk vid varje container-start som RENSAR alla `apps/sdkmc`-tillägg**; `custom_apps` + DB överlever. Det har HÄNT en gång och återställdes manuellt (401-verifierat efteråt). opcache validate_timestamps=PÅ → restart behövs aldrig.
- **INGET är inbakat i imagen:** ingen Dockerfile/byggkedja i repot lägger in additionsfilerna (sdkmc:s `docker/nextcloud/Dockerfile` är bara en dev-container, tom `/var/www/html`).
- **Upstream-forken `C:/Users/fredrik.jonasson/Cursor/Nextcloud-vanilla/hubs-code/sdkmc/sdkmc-main` innehåller INGEN av filerna** (verifierat): `lib/Service/` saknar samtliga 9 nya tjänster, `lib/Controller/OCS/` finns inte, `lib/Dashboard/` finns inte, `appinfo/routes.php` saknar `'ocs'`-nyckel (0 grep-träffar). Enda HUBS-START-ADD i forken är en ANNAN, orelaterad in-place-ändring: `lib/Service/ItslTagService.php:213–216, 1174–1180, 1229` (Fas F2 — läsbara tagg-displaynamn/färger).
- **Filerna finns alltså BARA i `hubs_start/backend-additions/`** (källa-till-sanning) + live på dev15. **En komplett, deploybar `routes.php` finns INTE i repot** — 'ocs'-blocket existerar bara som snippets (11 rutter i MANIFEST, 2 i NoteToSelfController-docblock, 1 i ArendeEnrichmentController-docblock). `.hubsbak`-backupen på dev15 togs 2026-06-17 och saknar därmed fas-2d-rutterna. Återställning efter wipe = manuell tar-pipe av `backend-additions/sdkmc/lib` + handbyggd routes.php (recept i HANDOVER §3 rad 72 + drift-lärdom 1).

## 4. Mail-tillägget (punkt 4)

- `backend-additions/mail/initITSL-additions.js` (242 r): dokumenterat referens-snippet; STATUS-huvudet (rad 4–12) säger **INTEGRATED** — receptet lever nu som riktig modul.
- Live-modulen `C:/Users/fredrik.jonasson/Cursor/Nextcloud-vanilla/hubs-code/mail/mail-main/overlay/src/itsl/utils/initComposerDeepLink.js` (187 r): parsar `/apps/mail/new?type=…&to=…&case=…`, öppnar ComposerItsl via `itslStore.setMessageType`+`startComposerSession`, bär `itsl.caseRef`, och `onComposedForCase()` (rad 118–126) POST:ar sänt meddelandes databaseId till `apps/hubs_arende/api/v1/inflode/koppla` (återanvänder verifierad Väg-A-koppling, hook på rad 180).
- **Wirad i källan:** `overlay/src/itsl/utils/initITSL.js:11` (import) + `:37` (anrop efter initStore()).
- **EJ DEPLOYAD:** mail-overlayn har egen byggkedja; overlayn är inte ombyggd/redeployad och sändkopplingen kräver GUI-verifiering med riktig sändning (HANDOVER-FORTSATTNING.md:200–201). Status = (b) byggt men overifierat, ej i drift.

## 5. OCS-routes totalt (sdkmc-additionerna) + verifieringsstatus

14 rutter i live `apps/sdkmc/appinfo/routes.php` `'ocs'`-block, alla konsumerade av `hubs_start/src/services/api.js` (rad 121–258, 407, 609, 633, 651):

| # | Verb | URL | Controller#action | 401-verifierad |
|---|---|---|---|---|
| 1 | GET | /api/v1/summary | OCS\Summary#summary | JA (MANIFEST 2026-06-17) |
| 2 | GET | /api/v1/receipts | OCS\Summary#receipts | JA |
| 3 | GET | /api/v1/recipients/search | OCS\Recipient#search | JA |
| 4 | GET | /api/v1/recipients/classify | OCS\Recipient#classify | JA |
| 5 | POST | /api/v1/secure-meeting | OCS\SecureMeeting#create | JA |
| 6 | GET | /api/v1/meetings/today | OCS\Meeting#today | JA |
| 7 | GET | /api/v1/meetings/{token}/lobby | OCS\Meeting#lobby | JA |
| 8 | GET | /api/v1/team | OCS\Team#index | JA (Fas 2) |
| 9 | GET | /api/v1/favoriter | OCS\Favoriter#index | JA (Fas 2) |
| 10 | GET | /api/v1/inflode-summary | OCS\InflodeFeed#summary | JA (Fas 2) + riktig-data-verifierad |
| 11 | POST | /api/v1/inflode/{action} | OCS\InflodeFeed#action | JA (Fas 2) |
| 12 | GET | /api/v1/note-to-self | OCS\NoteToSelf#index | JA (session 2d, HANDOVER:144,160) |
| 13 | POST | /api/v1/note-to-self | OCS\NoteToSelf#create | JA (session 2d) |
| 14 | GET | /api/v1/arende-enrichment | OCS\ArendeEnrichment#show | JA (session 2d) |

401-proben bevisar route+DI+auth-avvisning (ej 404/500) — INTE datainnehåll. Utöver proben är datavägar verifierade för: inflöde-summary (riktig orosanmälan-data, GUI-E2E session 2b/3), summary-ytan indirekt (dashboard renderar på dev15 i live-läge). Hela raden re-verifierades efter apps/sdkmc-wipen (HANDOVER:188). OBS: hubs_arende har EGNA rutter (arende-summary, fordelning-summary, treserva/*, inflode/skapa|koppla m.fl.) som ligger utanför denna analys per boundary-tabellen.

**Testtäckning för PHP-additionerna:** php -l + 401-probe + live-smoke — det finns INGA phpunit-tester för backend-additions-koden (phpunit 72 = hubs_arende; jest 88 = hubs_start-frontend, som dock komponenttestar feed-radernas kontrakt).

## DEMO_OR_STUB
- InflodeDemoData.php (backend-additions/demo-data/, target lib/Service/DemoData/) — 452 rader helt SYNTETISK IFO-inflödesdata (~12 rader, fiktiva Luhn-giltiga personnummer); gateas av app-config sdkmc/hubs_start_inflode_demo='1' i InflodeFeedService.php:64-74+124-132; default '0'=AV och dev15 kör AV (HANDOVER §2)
- demo-data/favoriter/*.vcf — 11 syntetiska funktions-vCards + tombstone; INGEN config-gate — 'gaten' är att adressboken måste seedas manuellt via CardDAV-PUT (README); ej seedad på dev15 → FavoriterService ärligt tom
- SummaryService.php:902-908 resolveLoa() — hårdkodad 'LOA3' (TODO: läs sdkmc:s riktiga login-security-state)
- SummaryService.php:679 — receipt updated_at alltid null (kvittenstabellen saknar kolumnen; 4-stegs-pillens tider saknas)
- TeamService.php:185-191 — olasta=0, omnamnanden=0 hårdkodade (ärliga nollor, TODO Talk-unread); :170-172 narvaro='unknown', status=null (ingen presence-backend)
- ArendeEnrichmentService.php:124 — diskussion.olasta alltid 0; meddelanden/moten alltid tomma (engine äger dem)
- FavoriterService.php:239 — stale:true ALLTID för klass a/c (DIGG-resolvern obyggd, KONTAKTER-FAVORITER §5); :334-340 proveniens 'Kunde inte färskhetskontrolleras'; :323-329 identitet=null för a/c (ingen verifierad-badge utan färsk resolve)
- SecureMeetingService.php:423-460 — SMS/securemail-intents PHP-session-nycklade (eventUid lagras men IntentProcessorService matchar på e-post; TODO full session-oberoende)
- SecureMeetingService.php:498-546 — addEmailParticipant/addUserParticipant via loopback-OCS UTAN credentials (TODO) — sannolikt tyst 401; fäller ej bokningen men medborgar-inbjudan är overifierad
- Dashboard-widgets (AttHantera/Kvittenser/DagensMoten) — riktig kod men ALDRIG registrerade i Application.php (MANIFEST rad 23-24, medvetet) = inaktiv/död kod tills registrering sker
- InflodeFeedService.php:613-638 previewExcerpt — docblock påstår PII-skrubb men implementationen skrubbar MEDVETET inte (PII-till-behörig-principen); dokumentationsdrift, ej bugg

## VERIFIED_WORKING
- Alla 14 sdkmc-OCS-routes svarar 401 oautentiserat på dev15 (route+DI+auth OK, ej 404/500) — MANIFEST.md:14-17 (11 rutter, 2026-06-17) + HANDOVER-FORTSATTNING.md:144,160 (note-to-self×2 + arende-enrichment, session 2d) + :188 (hela raden re-verifierad efter wipe-incidenten)
- Inflöde-feeden mot RIKTIG data: dedup 4→2 dubbletter (två mail_accounts per funktionsbrevlåda) + behandlad/case:-exkludering via sdkmc:s egna taggtabeller — verifierad mot riktiga orosanmälningar (HANDOVER session 3 'Verifierat mot riktig data'; commit 953c4f43 fixade join mot rätt tabeller)
- GUI-E2E (session 2b, inloggad användare): riktig orosanmälan i korgen 'orosanmalan@gruppbox' → 'Ta emot' via inflöde-feeden → case 224 med hela ärenderummet, DB-verifierat — bevisar sdkmc-feedens hela läsväg + deepLink i skarpt läge (demo-grind 0)
- SecureMeetingService in-process Talk-rumsskapande + CalDAV-event: möte-wizarden ÖPPNAR korrekt i GUI (session 2b); rumsskapande-fixen (RoomService i st.f. loopback-401) är kodverifierad — men bokning ALDRIG submittad i GUI (personnummer förbjudet att mata in) = delvis overifierad
- Boundary-avvisningen: sdkmc InflodeFeedController avvisar ärende-verben skapa/koppla med 400 agas_av_arende_motorn (kod InflodeFeedController.php:116-122; symmetrin GUI-bevisad genom att skapa/koppla körs mot hubs_arende i E2E)
- Mail-modulen initComposerDeepLink.js är wirad i overlay-källan (initITSL.js:11+37) och POST:ar mot hubs_arende /inflode/koppla — BYGGT MEN OVERIFIERAT: overlayn ej ombyggd/deployad, ingen riktig sändning testad (HANDOVER:200-201)
- php -l grönt på alla PHP-additioner + live occ hubs_arende:smoke grön efter varje deploy (HANDOVER session 2d/3) — dock finns INGA phpunit-tester specifikt för backend-additions-koden

## RISKS
- PERSISTENS (störst): en enda container-recreate/'docker restart hubs-php'/'itsl deploy' raderar ALLA apps/sdkmc-tillägg inkl. routes.php-blocket (har hänt en gång). Ingenting är inbakat i image eller committat i upstream-forken — hubs-code/sdkmc/sdkmc-main saknar samtliga filer och 'ocs'-blocket
- Ingen komplett deploybar routes.php finns i repot — 'ocs'-blocket måste handbyggas ur 3 spridda snippets (MANIFEST + 2 controller-docblockar); .hubsbak-backupen på dev15 är från 2026-06-17 och saknar de 3 fas-2d-rutterna → återställning riskerar tyst tappa note-to-self/arende-enrichment
- MANIFEST.md släpar efter trädet (fas-2d-filer + demo-data saknas i tabellerna) — den som deployar 'enligt MANIFEST' missar 4 filer + 3 rutter
- Demo-grinden är en enda runtime-config (hubs_start_inflode_demo) — flippas den till '1' på en skarp instans visas syntetiska personnummer-bärande rader i feeden; ingen miljöspärr utöver default '0'
- SecureMeeting deltagar-tillägg (medborgar-e-postinbjudan) går via credential-lös loopback-OCS och är sannolikt trasig i drift; felet loggas bara — mötesbokning kan se lyckad ut utan att inbjudan skickats. Overifierat pga BankID-gaten
- Backend-additions saknar egna phpunit-tester; verifieringen vilar på 401-probe + frontend-jest + hubs_arende-smoke — regressionsrisk vid framtida sdkmc-uppgradering (in-place-additioner mot sdkmc 2.2.25:s interna mappers/tabeller)
- previewExcerpt-docblocken beskriver PII-skrubb som inte längre görs — risk att en framtida granskare/utvecklare fattar fel beslut utifrån dokumentationen

## NEXT_STEPS
- Baka in persistensen: committa additionsfilerna + 'ocs'-routeblocket i hubs-code/sdkmc-forken (upstream-PR per MANIFESTens märkningskonvention) ELLER lägg dem i image-bygget/deployskedet för dev15 — dagens läge överlever inte en restart
- Spara en KOMPLETT kopia av den deployade routes.php (alla 14 rutter) i repot, t.ex. backend-additions/sdkmc/appinfo/routes.php.snippet, så wipe-återställning inte kräver rekonstruktion
- Uppdatera MANIFEST.md med fas-2d-leveranserna (NoteToSelf, ArendeEnrichment, InflodeDemoData, demo-data/favoriter) + de 3 nya routeraderna
- Bygg + deploya mail-overlayn och GUI-verifiera punkt 4 (composer-deep-link + sändkoppling via /inflode/koppla) — kräver inloggning + riktig sändning
- GUI-klick-verifiera det som bara är 401/kod-verifierat: möte-bokning end-to-end (inkl. deltagar-inbjudan — fixa credential-frågan i addEmailParticipant först), note-to-self-modalen, arende-enrichment på kort med riktig talk-token, favoriter efter CardDAV-seed
- Besluta om widget-registreringen (3 färdiga widgets ligger döda) och om resterande TODO:er: riktig LOA-källa, receipt updated_at-kolumn, Talk-unread för team, DIGG-resolver för favoriter
- Rätta previewExcerpt-docblocken i InflodeFeedService så dokumentation och beteende (ingen skrubb, ACL-buren) stämmer överens