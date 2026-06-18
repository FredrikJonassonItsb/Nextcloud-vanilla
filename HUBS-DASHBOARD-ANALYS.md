# Hubs Dashboard — Fullständig analys och marknadsanalys

**Datum:** 2026-06-13
**Underlag:** All ITSL-kod (sdkmc, mail, securemail, spreed-itsl, calendar, hubs-apps, gov-portal), Nextcloud 32.0.1-servern, live-granskning av kunddemo.hubs.se och Hubs Akademi (portal.hubs.se/academy), samt webbresearch.
**Metod:** 20 parallella analysagenter (8 kodanalys + 8 marknads-/UX-research + 3 oberoende konceptförslag + 1 domarpanel), ~1,57 miljoner agent-tokens, 406 verktygsanrop, plus interaktiv granskning av demomiljön i webbläsare. Rådata per sektion finns i `analysis-output/*.json`.

---

## 1. Sammanfattning

**Problemet är verifierat.** Det första gränssnittet i Hubs är i dag en generisk Nextcloud-dashboard med tre glesa widgets, och helheten hålls ihop av *användarens disciplin* — inte av produkten. Akademins eget utbildningsmaterial är beviset: ett helt varningsavsnitt ägnas åt att Talk-länken "MÅSTE ligga i Plats-fältet", diarienumret är den röda tråden genom alla appar men kopplas helt manuellt, och kanalvalet (SDK/Internpost/Säker e-post) är ett inlärt beslut som användaren måste fatta rätt varje gång.

**Lösningen är en fristående förstavy-app: "Hubs Start — Flödesnavet."** En standalone Nextcloud-app (sdkmc-mönstret, inga quilt-patchar) som sätts som defaultapp och gör handläggarens fyra vanligaste uppgifter startbara med ett klick: skicka säkert meddelande (med automatiskt kanalval — "Smart mottagare"), ta emot/tilldela, boka och genomföra säkert möte, identifiera motpart. Konceptet vann domarpanelen med 8,6/10 mot två alternativa koncept och förstärks med de bästa delarna från dem.

**Marknadstajmingen är exceptionell.** 160 av 290 kommuner är i SDK-produktion och resten är på väg; cybersäkerhetslagen (NIS2) gäller sedan 15 jan 2026 med öronmärkta statsbidrag på 200 mkr/år till kommunerna 2026–2028; Inera Digitalt möte avvecklas och kommunerna upphandlar ersättare nu. Ingen konkurrent (SEFOS, TDialog, CGI, SecureAppbox, Compodium) erbjuder en samlad handläggararbetsyta över SDK + säker e-post + fax + video + filer — dashboarden är inte bara en UX-förbättring, den är *differentiatorn* i upphandlingsdemos.

**Kritisk väg:** backend-tilläggen i sdkmc (summary-endpoint, mötes-wizard, kvittoläsning) och mail-appens komposer-deep-link måste levereras FÖRE dashboarden — annars visar förstavyn fel siffror och "ett klick" blir tre.

---

## 2. Nulägesanalys — kod och plattform

### 2.1 Byggplattformen (hubs-apps)

Hubs-sviten är N oberoende app-repon + ett byggrepo (quilt-patchar + overlay ovanpå upstream, alternativt standalone). Plattformen är välbyggd: en ny app registreras med **en rad i `setup-apps.list`**, ärver hela CI-kedjan (bygge, lint, security-audit, release-tarball till hubs-php) och får HMR-utvecklingsmiljö via `make nc-up` + `make webpack MODE=hmr`.

Viktigaste fynden för dashboarden:

- **Ingenting i koden definierar en gemensam förstavy** — varje Hubs-funktion är en separat NC-app med egen toppradsikon. Det är exakt luckan dashboarden ska fylla.
- **Quilt-underhåll kostar** vid varje upstream-bump (CI auto-filar drift-issues). Slutsats: dashboarden ska vara **standalone** och bara konsumera API:er.
- App-uppsättningen varierar per installation → dashboarden måste **detektera syskonappar i runtime** (`OCP\App\IAppManager`/capabilities).
- Varumärkesregeln (aldrig "Nextcloud"/"Talk" i kundvänd text) är doktrin utan verktygsstöd — dashboardens strängar måste gå genom l10n-terminologi-auditen.

### 2.2 sdkmc — navet (och flaskhalsen)

sdkmc är limmet i hela sviten: SDK-flödet via ITSL:s middleware till iiPax, LOA3/BankID-stegvis autentisering, brevlådemodellen (sdk/fax/gruppbox/personlig/sms), kvittenser, taggar/tilldelning, kalender-intents och SDK-adressboken. Allt en dashboard behöver finns *nästan*:

| Finns i dag | Saknas |
|---|---|
| `GET /apps/sdkmc/api/v2/frontend/getSettings` (LOA-nivå, policy) | **Dashboard-widget — ingen finns** |
| `GET /api/v2/sdkmc/user-mailboxes` (funktionsbrevlådor) | **Samlad summary-/räknar-endpoint** (olästa, otilldelat, kvittostatus i ETT anrop) |
| `mailbox-link/{id}?mid=` (verifierad deep-link till rätt tråd) | OCS-endpoints (allt är intern-API) |
| Kvittensdata i `message_receipt` | Användarvänd kvittoläsning (i dag InternalAPIAuth-låst, PENDING-semantik oklar) |
| Kalender-intents (SMS/BankID/securemail) | Intents per **event-UID** (i dag per e-post i PHP-session — parallellbuggar) |
| Guest-identity-endpoint för Talk | Lobby-status-endpoint |

Allvarligaste skuldposterna: LOA-detektering via REQUEST_URI-sniffning (öppen TODO), hård utloggning + `die()` vid LOA-uppgradering (ska ersättas av självvald uppgradering), 1 678 raders DOM-injektion i `calendar-sms.js` (hela "Säkra möten"-UX:et i kalendern är skört runtime-inskjutet), rå SQL mot mail-tabellerna, hårdkodade svenska aktivitetstexter.

### 2.3 mail — fem kanaler genom pseudo-adresser

ITSL-forken av NC Mail bär fem meddelandetyper (SDK, internpost, säker e-post, fax, SMS) via adress-suffix (`{nr}@fax`, `{epost}.{pnr}.securemail`, `@personlig`, `@gruppbox`). Kanalklassningen (`getIconTypeForEmail`) är duplicerad stränglogik som **måste kapslas i EN server-side-tjänst i sdkmc** innan dashboarden byggs på den. Virtuella brevlådor ("Mina meddelanden", "Otilldelade") är frontend-konstruktioner med oläst hårdkodat till 0 — dashboarden behöver riktiga server-räknare. Komposern kan inte djuplänkas med förvald kanal; route-parametern `/apps/mail/new?type=` är ett litet men **kritiskt** tillägg.

### 2.4 securemail — medborgarportalen

Helt separat värld (Node/Express + Vue3/Vuetify mot egen Dovecot), inte en NC-app. Protokollet (X-headers, MDN-läskvitton, X-Org-Reply-To) ger dashboarden datat för statuskedjan **Skickat → Levererat → Läst → Besvarat** — den emotionella ersättningen för "ringa och kolla att faxen kom fram". Teknisk skuld (3 000-raders server.js, tokens i localStorage, jwt.decode utan verify) bör åtgärdas men blockerar inte dashboarden.

### 2.5 spreed-itsl (video) och calendar

Talk-forken har ~9 kirurgiska ITSL-ändringar: BankID-låsta gästnamn, undertryckta invites (om inte `?resend-invitations`), moderatorns "Visa verifierad identitet". Problem: identiteten visas som en **försvinnande toast med personnummer** — ska bli en kvarliggande, värdig badge. Versionsgap: forken kräver NC 31, plattformen kör NC 32 — måste lösas i uppgraderingsplanen.

Kalendern är nästan vanilj — all "Säkra möten"-UX injiceras av sdkmc:s calendar-sms.js. Mötes-wizarden i dashboarden ersätter hela den konstruktionen server-side.

### 2.6 gov-portal — varför förra försöket misslyckades

Den tidigare dashboarden (React/Vite/Tailwind-SPA i NC-skal) föll på fem lärdomar som nu är designregler:

1. **Fel datakällor** (Talk 1:1-chattar rubricerade som "säkra meddelanden") → använd verifierade endpoints.
2. **Client-side fan-out-polling** → EN server-side aggregerings-endpoint med sinceIds + cache.
3. **Trasiga deep-links** (`/apps/spreed/new` m.fl.) → kontraktstester i CI mot syskonappars routes.
4. **Dubbel header/eget designspråk** → bygg med `@nextcloud/vue` så designen följer plattformen.
5. **Död settings-API** → bygg inget som inte används.

Återanvändbart: defaultapp-mekanismen (bevisad), widget-designmönstret, API-lagret som referens.

### 2.7 Nextcloud 32 — byggblocken finns

Servern exponerar allt dashboarden behöver: `IAPIWidgetV2`/`IButtonWidget`/`IConditionalWidget`/`IReloadableWidget` (widgets), `defaultapp`-konfig + navigation order -10 (förstavy), `INavigationManager::setUnreadCounter` (badges), Unified Search-providers, Notifications-API, `IInitialState`, theming/`ITheme` (fullständig Hubs-profil inkl. login), `IAlternativeLogin` + `hide_login_form` (rent eID-login). Begränsningar som motiverar egen app i stället för ombyggd standard-dashboard: platt layoutmodell, inga item-statusar/åtgärder i WidgetItem, ingen tvingad/rollstyrd layout.

---

## 3. Live UX-granskning (kunddemo.hubs.se + Hubs Akademi)

Granskningen av demomiljön och utbildningsportalen gav konkreta belägg för "helheten sitter inte ihop":

1. **Första intrycket är en tom generisk dashboard** — tre widgets (Talk-omnämnanden, Viktiga meddelanden, Kommande händelser), ofta utan innehåll, med "Anpassa"-knapp. Inget av Akademins handläggarbegrepp (Ej tilldelad-kö, funktionsbrevlådor, kvittenser, LOA) syns på startsidan.
2. **Fax är en extern länkbrytning** — appmenyns "Fax" leder till `smswebb.genericmobile.se` i ny värld med annat utseende. Helhetsbrott mitt i sviten.
3. **Dubbla startvyer** — Talk har egen "starta"-yta som konkurrerar med dashboarden.
4. **Kanalvalet är begravt** i mails "Nytt meddelande"-dropdown (SDK-Meddelande/Internpost/Säker E-post) — det viktigaste beslutet i hela produkten är en undanskymd meny.
5. **Demoärendet Orosanmälan Dnr SN 2026-0142** spänner över mail + Talk + Filer + Kalender och hålls ihop helt manuellt via namnkonventioner.
6. **Branding-inkonsekvens** — "Nextcloud" syns i kundvänd yta (bl.a. Collectives-titel), i strid med den egna varumärkesregeln.
7. **Akademins varningstexter är en förteckning över saknade skyddsräcken**: "Talk-länken MÅSTE ligga i Plats-fältet", "Skriv aldrig känsliga personuppgifter i ämnesraden", gallringens oåterkalleliga "Kör nu", "verifiera alltid kort i mötets början vem du pratar med" (trots BankID). Utbildning kompenserar för UI-brister — varje sådant avsnitt är en backlog-post för dashboarden.

Akademin ger också **terminologin som är cementerad** hos kunderna och ska återanvändas exakt: SDK-Meddelande, Internpost, Säker E-post, funktionsbrevlåda, kvittens (transport → meddelande → läst), Ej tilldelad, tillitsnivå/LOA, grön/lila bock, ärendechatt, gallring.

---

## 4. Behov, personas och användningsfall

### Volymerna och smärtan är dokumenterade

- **514 000 orosanmälningar 2024** (+55 % sedan 2018) — ett av kommunsektorns största flöden av känslig information, i dag via blandning av e-tjänst/telefon/brev/fax.
- **Faxen lever** i vård/socialtjänst; felskickade fax är utpekad säkerhetsrisk och personal ringer för att verifiera mottagning.
- **Diggs nyttokalkyl:** SDK kan spara ~1 620 mnkr/år eller ~3 500 årsarbetskrafter; ~30 min sparad tid per ärende.
- **Dokumentationsbördan** tränger undan socialsekreterares kärnverksamhet; Arbetsmiljöverket fann brister i digital arbetsmiljö på >50 % av 1 500 inspekterade arbetsplatser. Starkaste argumentet för en samlad ingång: **aggregera, inte addera** — Hubs Start får inte bli "system nummer åtta".
- **HR är ett underskattat segment:** bara 34 % av chefer har verktyg för säker hantering av läkarintyg/rehabärenden.
- **SIP-möten saknar ändamålsenligt verktyg** (Region Uppsala bedömde 2022 Skype som "säkraste plattformen") — paketeringsmöjlighet: kallelse (SDK/säker e-post) + säkert videomöte + delade dokument + uppföljning i ett flöde.
- **SKR:s rekommendation 2025 om funktionsadresser** gör delade funktionsbrevlådor med tilldelning ("vem tar detta?") till kärnvyn — inte en personlig inkorg.

### Personas (styr rollprofilerna)

| Persona | Kärnbehov i förstavyn |
|---|---|
| **Socialsekreterare/handläggare** | Triage av orosanmälningar/SDK i funktionsbrevlådor, kvittenser, boka säkert möte med medborgare |
| **Registrator** | Hög volym, tangentbordstriage, fördelning till handläggare, dnr-taggning |
| **Kommunsjuksköterska/HSL** | Bevakning av inkommande från regionen, fax-bryggan |
| **HR/chef** | Avskild yta för känsliga personalärenden (FK, företagshälsovård) |
| **Överförmyndarhandläggare** | Deadline-driven kö (årsräkningar före 1 mars) |
| **Förvaltare/admin** | SDK-logg (DIGG-krav), bakgrundsjobb, gallring med skyddsräcken, volymstatistik/ROI |

---

## 5. Fullständig marknadsanalys

### 5.1 SDK-ekosystemet — mitt i utrullningskurvan

- **139 anslutna organisationer dec 2025** (62 ett år tidigare); 42 000+ SDK-meddelanden 2025. Juni 2026: **160 kommuner i produktion, 193 i QA, 33 påbörjade**; 16 av 21 regioner i produktion. Endast 7 statliga myndigheter — stor tillväxtpotential på statssidan.
- **Godkända programvaror:** Merkurius (CGI), Tdialog (Compodium), iipax SDK (Ida Infront), **Hubs v1.0 (ITSL)**, SEFOS (Meaplus), Secure Appbox Connect, Visiba Care SDK Roter.
- ⚠️ **Konkurrensgap:** Hubs v1.0 saknar **SDK API MT/MK-godkännande** på Diggs lista — CGI, SEFOS, SecureAppbox och Visiba har det. Prioritera certifieringen; det blir skall-krav i avrop, och dashboarden bygger rimligen ändå på API:erna.
- **Upphandlingskanalen** är Addas DIS "Säker digital kommunikation" (2023–2033) — ITSL är redan kvalificerad. Dashboard-funktionalitet ska synas i DIS-kravsvaren (spårbarhet, behörighet, loggning, statusöversikt).
- **Roadmap:** Digg öppnar SDK för strukturerade meddelandetyper och maskin-till-maskin → dashboarden bör designas för att visa även systemgenererade flöden (köer, fel, kvittenser per meddelandetyp).
- **Avgiftsrisk:** Diggs föreslagna transaktionsavgifter i grannsystemen (0,80 kr/meddelande) signalerar att SDK kan avgiftsbeläggas — bygg volymmätning per organisation från start ("Nytta hittills"-widgeten blir samtidigt kostnadsuppföljning).

### 5.2 Konkurrenter — säkra meddelanden mot medborgare

Marknaden består av tre skikt som **ingen aktör täcker samlat**: digital post (Kivra dominerar, envägs), säkra dialoglösningar med BankID (TDialog, SEFOS, CGI, SecureAppbox) och SDK (org-till-org). Hubs helhetsgrepp saknar direkt motsvarighet.

| Konkurrent | Styrka | Svaghet mot Hubs |
|---|---|---|
| **SEFOS (Meaplus)** — närmast i bredd | Meddelanden+video+fax+filer, Outlook/Teams-integration, on-prem eller SaaS, stark i små kommuner | M365-beroende, splittrad över tillägg, **ingen samlad dashboard** |
| **TDialog (Compodium/Certezza)** | Först SDK-godkänd, on-prem, kundkontrollerade nycklar, stor kommunbas | Separat silo, äldre UI |
| **CGI (Merkurius/Messit)** | Störst varumärke, helhets-SaaS, 24/7 | Leverantörsdrift — inte kundens servrar |
| **SecureAppbox** | Säker e-post + funktionsbrevlåda + GDPR e-Fax | Smalare, ingen video/SDK-arbetsyta |
| **Kivra** | 6+ M användare, digital post | Envägs, ingen dialog/handläggarflöden, 1,50–3 kr/brev |
| Inkommande hot | Zivver (via M365-spåret), Cryptshare/Inuit (fick BankID, marknadsför mot SDK) | Saknar svensk offentlig kravbild på djupet |

**Nyckelinsikt:** medborgarsidan är de facto-standardiserad (notis + BankID + webbrevlåda) — differentieringen avgörs på **handläggarsidan**, exakt där dashboarden ligger. Prissättning är genomgående dold (offert/demo) — transparent prismodell är i sig en differentiator.

### 5.3 Konkurrenter — säkra videomöten

- **Compodium/Vidicue** leder nischen (dubbel autentisering, lobby, svenska datacenter) men är **molnbaserad** — inte on-prem.
- **Inera Digitalt möte avvecklas** → kommuner upphandlar ersättare **nu**; Digitala Samtal fångar dem aktivt (Lifecare-integrerade, bokningsfria flöden).
- **Visiba Care** dominerar vårdsidan, skiftar mot AI-triage.
- **Teams/Zoom underkänns** för sekretess: Schrems II + OSL-röjandefrågan (SOU 2021:1, eSam ES2023-06). Kommuner kör tvåspår — Hubs ska inte konkurrera med Teams överallt utan göra det glasklart *när* det säkra mötet ska användas och göra tröskeln minimal.
- **Gemensamt vinnande mönster** (matcha, uppfinn inte): eID-verifiering av ALLA deltagare före insläpp, lobby där handläggaren ser verifierad identitet, länk via SMS/e-post utan kontokrav, svensk drift. Funktioner att matcha: teknikkontroll före möte, telefoninringning, ombud/flerpart. Differentiering: **mötesloggar/revisionsspår kopplade till ärende**.
- **Hubs juridiska trumf:** on-prem hos kunden löser OSL-röjandefrågan *i grunden* — skarpare än konkurrenternas "svenskt datacenter". Ska synas i UI:t: "all data lagras i er driftmiljö".

### 5.4 Nextcloud-ekosystemet — mönstret är bevisat

- **Bygg inte om standard-dashboarden — bygg egen defaultapp.** Både openDesk (Tyskland: egen Nubus-portal framför Nextcloud m.fl.) och Murena (egen launcher, 65 000+ användare) visar att seriösa aktörer lägger ett eget portallager som första gränssnitt. openDesks "central navigation"-API (gemensam appväxlare över alla moduler) är den mest relevanta tekniska förebilden — koden är öppen.
- **Använd `@nextcloud/vue`** — designsystemet fick stor översyn i v32 och uppströms släpper 2 majorversioner/år; egen frontend utanför komponentbiblioteket är en underhållsskuld.
- **Versionsplan krävs:** plattformen kör NC 32 (EOL ~sep 2026), uppströms är på v34; spreed-forken kräver NC 31.
- **Talk "Munich"** (juni 2026, uttalat Teams-alternativ: trådar, telefoni, transkribering, webinarer) kommer uppströms — exponera i dashboarden i stället för att bygga eget.
- **Marknadsmedvind:** ~2 miljoner nya "sovereign workspace"-platser 2025, Danmark lämnar Microsoft, Schleswig-Holstein skalar. **Sverige saknar nationell motsvarighet till openDesk/La Suite — Hubs kan positionera sig som "svensk openDesk för sekretessbelagd kommunikation"** med SDK som unik differentierare. Dashboarden är skyltfönstret för den positioneringen.

### 5.5 UX-trender — vad förstavyn ska följa

- **Task-orienterad design** är det dominerande skiftet: kärnan är "nästa åtgärd" + status, inte grafer. Mät framgång i **tid-till-åtgärd**, inte tid-på-dashboard.
- **GOV.UK Task list** är det mest beprövade myndighetsmönstret: få statusar (börja minimalt), verb-först-rubriker, valfri ordning. **Triage-paradigmet** (Superhuman Split Inbox, Linear Triage): sektionerad kö i stället för blandad ström, explicit tom-kö-tillstånd — för sekretess är "inget missat" ett *compliance-värde*.
- **Ctrl+K-kommandopalett** är standard i professionella verktyg och skalar från nybörjare till expert — billig att bygga ovanpå Unified Search.
- **Viva Connections-modellen** (Card View = agera direkt i kortet, Quick View = detalj utan sidbyte, rollmålgruppsstyrning) + ett gemensamt designspråk så användaren inte möter "10 olika gränssnitt".
- **Svenska designsystem att låna från:** Försäkringskassans (öppen källkod sedan okt 2024), Arbetsförmedlingens, Inera/IDS — igenkänning sänker utbildningströskeln och signalerar "byggt för svensk offentlig sektor".
- **WCAG 2.2 AA nu, inte 2.1:** EN 301 549 v4.1.1 väntas 2026. Konkret: klickytor ≥24×24 px, fokus aldrig dolt av sticky paneler, tangentbordsalternativ till drag-and-drop (NC:s standard-dashboard klarar inte detta), Consistent Help, inga kognitiva test i auth. **Dokumenterad efterlevnad per kriterium (VPAT) som bilaga i Adda-avrop.**
- **AI återhållsamt och lokalt:** om AI införs (sammanfattning, prioriteringsförslag) måste det köras on-prem — då blir det en differentiator: "AI-assistans utan att data lämnar er server".

### 5.6 Regulatorik — köpsignalerna har datum

| Drivkraft | Datum | Effekt |
|---|---|---|
| Cybersäkerhetslagen (NIS2), alla kommuner/regioner omfattas, personligt ledningsansvar | I kraft 15 jan 2026 | Dashboard som "compliance-fönster" säljer mot ledningen, inte bara IT |
| Cybermiljarden: 200 mkr/år kommuner, 50 mkr/år regioner | 2026–2028 | Paketera dashboarden så den kvalificerar som NIS2-åtgärd i bidragsmotiveringar |
| SDK-anslutningsvågen | 2025–2026 | "Den som äger startsidan äger vanan" — fönstret är nu |
| OSL 10:2a + eSam ES2023-06 | Gällande | On-prem eliminerar lämplighetsbedömningen som plågar Microsoft-alternativen |
| Statlig e-legitimation (Sverige-id, LOA4) | Dec 2026 | Förbered auth-arkitekturen |
| eIDAS2/EUDI-wallet obligatorisk acceptans | Nov 2027 | "eIDAS2-redo" differentierar i anbud redan 2026 |
| Interoperabilitetsförordningen (EU 2024/903) | Sedan jan 2025 | Formellt upphandlingsstöd för öppen källkod — lyft Nextcloud-basen |
| MCF-incidentrapportering | Löpande | Dashboard-funktion som samlar säkerhetshändelser till rapportunderlag — få konkurrenter löser detta |

Räkna med Microsofts motoffensiv ("sovereign cloud" i Sverige 2026). Hubs motbudskap: suveränitet kräver ägarskap, jurisdiktion och öppen kod — och dashboarden är platsen där beviset visualiseras.

---

## 6. Koncepttävlingen och utslaget

Tre oberoende agenter tog fram fullständiga koncept som bedömdes av en domarpanel på fem kriterier (helhet, tid-till-värde, genomförbarhet, differentiering, tillgänglighet/myndighetskänsla):

| Koncept | Poäng | Kärna |
|---|---|---|
| **1. Hubs Start — Flödesnavet** | **8,6** | Åtgärd-först: fyra primärknappar + "Smart mottagare" (automatiskt kanalval) + triage-kö |
| 2. Hubs Inkorg | 8,1 | Triage-först: EN sektionerad ström över alla kanaler, tangentbordsdriven |
| 3. Hubs Start rollbaserad | 7,3 | Rollprofilmotor + bästa onboarding och WCAG-dokumentation |

**Varför Flödesnavet vann:** tid-till-värde 10/10 — en stressad handläggare får värde på första klicket utan att lära sig ett triagemönster, och "Smart mottagare" automatiserar det mest felbenägna beslutet (kanalvalet) genom att göra Akademins inlärda tumregel till kod. Alla citerade endpoints finns verifierbart i kodbasen.

---

## 7. Rekommenderat koncept: Hubs Start — Flödesnavet (förstärkt)

### 7.1 Kärnidé

En standalone-app (`hubs-start`) som defaultapp efter LOA3-inloggning. Användaren väljer **VEM** — systemet väljer **HUR** (Smart mottagare slår upp motparten i SDK-adressboken, interna brevlådor och fritt personnummer/e-post och väljer kanal: myndighet → SDK-Meddelande, kollega → Internpost, medborgare → Säker E-post, faxnummer → Fax; överstyrbar, loggad). Runt detta en GOV.UK-inspirerad åtgärdskö som aggregerar alla kanaler.

### 7.2 Förstavyns arkitektur

1. **Header:** hälsning + LOA-statuschip ("Inloggad med BankID — Tillitsnivå 3"; vid LOA-2 gul chip med självvald CTA "Legitimera med BankID" — ersätter dagens tvångsutloggning) + "all data lagras i er driftmiljö"-märkning.
2. **Åtgärdsrad (alltid överst):** *Nytt säkert meddelande* · *Boka säkert möte* · *Starta möte nu* · *Sök motpart*. Samma åtgärder via Ctrl+K.
3. **Vänsterkolumn (60 %):** "Att hantera" — **sektionerad kö från koncept 2**: *Kräver åtgärd / Otilldelat / Nytt / Bevakas / Klart idag*, med kanalflikar, GOV.UK-statusar, verb-först-rader, "Ta ärendet"-knapp direkt i kön, explicit tom-kö-tillstånd ("Allt hanterat — inga ägarlösa ärenden") och **kanaltäcknings-deklaration** ("dessa kanaler bevakas") så ofullständighet aldrig är tyst.
4. **Högerkolumn (40 %):** *Dagens säkra möten* (lobby-/verifieringsstatus, pre-flight-kontroll), *Skickat — kvittenser*, *Funktionsbrevlådor*, *Bevakningar*.
5. **Under vikningen:** *Bokningsbara tider*, *Nytta hittills*.

Hierarkiprincip: åtgärd före information; inga grafer ovanför vikningen; varje rad har en primär åtgärd (Card View) + expanderbar detalj utan sidbyte (Quick View). Widgets renderas villkorligt per installerad app och per roll. Terminologin följer Akademin exakt.

### 7.3 Nyckelflöden

- **Skicka säkert meddelande:** Smart mottagare → kanalchip med förklaring → komposern öppnas förifylld via `/apps/mail/new?type=…&to=…` → kvittensrad direkt i "Skickat"-widgeten → status uppdateras transport→meddelande→läst.
- **Boka säkert möte (ersätter calendar-sms.js):** trestegs-wizard (Vem/När/Skydd) → EN server-side-operation skapar Talk-rum + CalDAV-event med länken **garanterat i LOCATION** + intents per event-UID + ICS med personlig länk. Eliminerar Akademins största varningsavsnitt.
- **Genomför möte & identifiera motpart:** "Dagens säkra möten" med nedräkning, BankID-badge, lobby-status i realtid ("1 verifierad deltagare väntar"), verifierad identitet som kvarliggande badge i stället för försvinnande toast. "Starta möte nu" = spontanmöte under 30 sekunder.
- **Ta emot/tilldela/besvara:** otilldelat i funktionsbrevlådor + tilldelat mig + medborgarsvar + bevakningar i en kö; "Ta ärendet" utan att lämna vyn; deep-link till exakt tråd via mailbox-link.
- **Bevakning/delegering vid frånvaro:** synliga bevakningsrader åt båda håll, aldrig tyst åtkomstförlust.
- **Förvaltarens "Systemhälsa"** (rollstyrd): SDK-loggstatus (DIGG-kravet), adressbokssynk, gallring med **skriv-för-att-bekräfta**-skydd, bakgrundsjobb, komponentversioner, volymstatistik ("fax ned 40 %, SDK upp 3×") som NIS2-/ROI-underlag.

### 7.4 Förstärkningar från övriga koncept (domarpanelens syntes)

**Från koncept 2:** sektionerade kön + tom-kö-tillstånd + kanaltäcknings-deklaration; aggregationsarkitekturen som bindande regel (EN server-side stream-/summary-endpoint, sinceIds, per-användar-cache, lasttest); statusmodellen ägs av sdkmc och dupliceras aldrig; tangentbordstriage (j/k/e/a) som **opt-in-expertläge**, inte default.

**Från koncept 3:** onboarding som ersätter firstrunwizard (femstegsmodal: LOA-förklaring, brevlådegenomgång, kom igång-checklista); dokumenterad WCAG 2.2-efterlevnad per kriterium (VPAT-bilaga till Adda-avrop); skriv-för-att-bekräfta på gallring; anti-corruption-lager + kontraktstester i CI mot syskonappars routes. Rollstyrning i **förenklad form**: tre fasta profiler (Handläggare bas / Registrator / Förvaltare) via grupptillhörighet — full rollprofilmotor skjuts till fas 2.

---

## 8. Teknisk arkitektur och kritisk väg

### 8.1 Paketering

- Standalone-app enligt sdkmc-mönstret, en rad i `setup-apps.list`, CI-mallarna ger bygge/release/hubs-php-distribution gratis.
- Frontend: Vue + `@nextcloud/vue`; strängar genom l10n-tools med terminologi-audit (aldrig "Nextcloud"/"Talk" i UI).
- Förstavy: `occ config:system:set defaultapp --value=hubs-start,dashboard,files` + navigation order -10.
- WCAG 2.2 AA inbyggt från dag 1; extern granskning + VPAT som leverabel.

### 8.2 Kritisk väg — sekvensen är avgörande

**Steg 0 (före dashboarden, i sdkmc/mail-releaser):**

1. **Kanalklassningstjänst** i sdkmc — suffixlogiken (@sdk/@fax/{pnr}.securemail/…) kapslas server-side på ETT ställe. Smart mottagares hjärta.
2. **OCS summary-endpoint** `GET /ocs/v2.php/apps/sdkmc/api/v1/summary` — olästa per kanal, otilldelat per funktionsbrevlåda (riktiga server-räknare, inte dagens hårdkodade 0), tilldelat mig, kvittostatus (klargjord PENDING-semantik från MW), delegationer, LOA-nivå — i ETT anrop.
3. **Secure-meeting-wizard-endpoint** — Talk-rum + CalDAV med LOCATION + intents per event-UID, server-side.
4. **Lobby-status-endpoint** (verifierade gäster i väntrum).
5. **Mail-routerhook** `/apps/mail/new?type=…` (enda overlay-tillägget).

**Steg 1:** Dashboard-appen med Att hantera-kön, åtgärdsraden, Smart mottagare, Dagens säkra möten, kvittenswidgeten, LOA-chip, onboarding. **Speglingswidgets (IAPIWidgetV2) i standard-dashboarden från dag 1** — löser mobilklienter och dubbel-startvy-problemet utan extra arbete.

**Steg 2:** Förvaltarvyn Systemhälsa, Nytta hittills, bokningsbara tider, tangentbordsläge, Unified Search-providers per kanal.

**Steg 3 (fas 2):** full rollprofilmotor, notify_push-realtid, ärendeaggregering per dnr ("Relaterade resurser"-spåret), incidentrapporteringsstöd (MCF), ev. Mina meddelanden-koppling för envägsutskick.

### 8.3 Största riskerna och mitigeringar

| Risk | Mitigering |
|---|---|
| sdkmc-tilläggen försenas → dashboarden visar fel siffror | Steg 0 är release-blockerande; dashboarden släpps inte utan |
| Bräckliga suffixkonventioner bryter Smart mottagare | EN server-side klassningstjänst, kontraktstester |
| Deep-link-röta vid upstream-bump (gov-portals fall) | CI-kontraktstester mot syskonappars routes (notify.yml-mönstret) |
| Fan-out-prestanda på små installationer | Server-side aggregation, cache, sinceIds, lasttest |
| LOA-detekteringens REQUEST_URI-sniffning | Åtgärdas i sdkmc; dashboardens LOA-UI blir aldrig bättre än källan |
| NC-versionsdrift (v32 EOL ~sep 2026; spreed kräver NC 31) | Koordinerad uppgraderingsplan för hela sviten; `@nextcloud/vue` minskar ytan |
| Flöden läcker tillbaka till gamla vägar | Komposer-deep-linken är kritisk väg; mät tid-till-åtgärd |
| Rollstyrning via skör gruppprovisionering | Tre enkla profiler + vettiga defaults + admin-UI |

---

## 9. Slutsats

Hubs har redan funktionsbredden ingen konkurrent matchar — det som saknas är ytan som bevisar det. Flödesnavet gör tre saker samtidigt: det löser den dokumenterade UX-skulden (kanalval, mötesbokning, kvittenser, LOA-avbrott), det blir compliance-fönstret som NIS2-eran efterfrågar, och det blir demovinnaren i Adda-avropen under exakt de år (2026–2028) när kommunerna har både lagkrav och öronmärkta pengar. Bygg backend-kontrakten först, dashboarden direkt därpå, och låt "tom kö = inget missat" bli produktens löfte.

---

*Rådata: `analysis-output/` — 8 kodanalyser, 8 marknadsanalyser med källhänvisningar, 3 konceptförslag, domarutslag. Workflow-skriptet är resumerbart för uppföljande analyser.*
