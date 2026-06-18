# Native Nextcloud-appar (NC 32 / Hub 25 Autumn) bakom Hubs-dashboardens widgetar

> Internt vassningsunderlag för Hubs (ITSL). **Brand-regel:** i produkt-/UI-text säger vi aldrig "Nextcloud" eller "Talk" — vi säger Hubs, säkra meddelanden, säkert möte, ärenderum, e-underskrift osv. **Här i den interna analysen namnger vi de underliggande app-id:na och deras deep-link-rutter** så att utvecklingsteamet kan koppla varje widget till rätt app/endpoint.
> **Plattform:** server v32 ("Hub 25 Autumn", GA sep 2025; v32.0.1 enterprise nov 2025). **Datum:** 2026-06-13.

## Den arkitektoniska ramen (måste genomsyra varje koppling)

**Hubs är MELLANLAGRINGEN (middleware), inte slutlagringen.** Systemet av rekord (*slutlagring*) är alltid verksamhetens ärendehanteringssystem:

- **Socialtjänst:** Treserva, Lifecare/Procapita, Viva, Combine
- **HSL (kommunal hälso- och sjukvård):** Lifecare, Cosmic, Treserva HSL
- **Överförmyndare:** Provisum, Aider, Wärna
- **Registratur/nämnd:** W3D3, Public360, Ciceron, Platina, Evolution, LEX
- **HR:** Visma, Heroma, Personec

De native-appar som beskrivs nedan är **Hubs interna staging-lager**: de tar emot, triagerar, signerar, möter och samredigerar — men den *färdiga utfallshandlingen* (beslutet, journalanteckningen, den granskade årsräkningen) **committas av handläggaren in i facksystemet**. Två frågor måste därför kunna besvaras för varje widget/app:

1. **Varifrån kommer datan IN?** (SDK/säker e-post/fax → sdkmc; en region; en e-tjänst; ett facksystem-API; manuellt)
2. **Var hamnar utfallet TILL SLUT?** (alltid facksystemet/diariet/e-arkivet — Hubs-appen är transit, och dess egen retention/gallring ska spegla att originalet bevaras någon annanstans)

Detta är inte en filosofisk fotnot: det styr **retention-policy** (Hubs-mappen gallras tidigt eftersom originalet bor i facksystemet/e-arkivet), **vad som får vara "Klar"** (en widget-rad är klar när utfallet är *committat till facksystemet*, inte bara behandlat i Hubs) och **integrationsriktningen** (Hubs skriver sällan tillbaka automatiskt — handläggaren för över, Hubs loggar att det skett).

---

## Sammanfattning — vad kan wiras IDAG vs vad kräver extra backend

| App / funktion | App-id | Status i NC 32 | Wirebar idag på ren server? | Extra backend som krävs |
|---|---|---|---|---|
| Deck (kanban/uppgifter) | `deck` | Mogen, community-app | **Ja** | — (CalDAV-spegling inbyggd) |
| Tasks (CalDAV VTODO) | `tasks` | Mogen | **Ja** | — (kräver bara CalDAV, finns i core) |
| Calendar + Appointments | `calendar` | Mogen, core groupware | **Ja** | Auto-videorum kräver Talk/spreed installerad |
| Talk/spreed (rum, chat, lobby) | `spreed` | Core Hub-app | **Ja** (P2P, ≤3–5 deltagare) | **HPB** för grupper, lobby-skalning, inspelning, transkription |
| Talk inspelning | `spreed` + recording-backend | — | Nej | **Recording-server** (separat container) |
| Talk live-transkription/AI-undertext | `live_transcription` | Ny i v32 | Nej | **HPB (release > sep 2025) + NVIDIA-GPU ≥10 GB VRAM** |
| Forms | `forms` | Mogen | **Ja** | — (men saknar native filuppladdning + förgrening) |
| Tables | `tables` | Mogen (2.x), del av "Flow" | **Ja** | — (REST-API v2/v3) |
| Collectives | `collectives` | Mogen | **Ja** | Kräver `circles` (Team) — finns i core |
| Files + Groupfolders/Team folders | `files`, `groupfolders` | Core + officiell | **Ja** | — (ACL, versioner inbyggt) |
| Files Retention | `files_retention` | Officiell | **Ja** | — (kräver restricted-tagg) |
| Files Automated Tagging | core `workflowengine` | Core | **Ja** | — |
| Whiteboard | `whiteboard` | Officiell, förbättrad i v32 | Grundläge ja | **WebSocket-backend** för realtidssamarbete |
| LibreSign (signering) | `libresign` | v12.4.5 stödjer NC 32 | Delvis | **cfssl + Java/JSignPDF + pdftk** via `occ libresign:install` |
| Aktivitet | `activity` | Core | **Ja** | — (OCS-API v2) |
| Flow / workflow_engine | core + `flow` | Core (regler) + ExApp (Windmill) | Regler: **ja** | Avancerad BPA (Windmill) = ExApp/Docker |
| Assistant (AI-UI) | `assistant` | Ny standard i v32 | Installeras ja | **Text-providers** (llm2 lokalt **eller** OpenAI) |
| Lokal LLM | `llm2` | ExApp | — | **AppAPI/Docker; ~8 GB VRAM + 12 GB RAM** rekommenderat |
| Context Chat (RAG) | `context_chat` + `_backend` | ExApp | — | **llm2/OpenAI + backend-container** |
| Tal-till-text | `stt_whisper2` | ExApp | — | **AppAPI/Docker (Whisper, lokalt)** |

**Headline-slutsats:** ungefär 70 % av widgetkatalogen kan demas på en ren v32-server *idag* (Deck, Tasks, Calendar/Appointments, Forms, Tables, Collectives, Files/Groupfolders/Retention, Activity, Flow-regler, P2P-Talk). De fyra tunga backend-beroendena — **(1) Talk HPB**, **(2) Talk recording-server**, **(3) live_transcription-GPU**, **(4) LibreSign cfssl/JSignPDF**, plus **(5) AI via llm2 eller OpenAI** — är de som avgör vilka "wow"-flöden (inspelat SIP-möte, AI-undertext, on-prem signering, AI-triage) som är produktionsklara. Den **suveräna signeringskärnan** för svensk offentlig sektor bör dessutom primärt vara **Inera Underskriftstjänst-API / egen Sweden Connect-nod** (se `research-esignering.md`) — LibreSign är ett komplement/internt alternativ, inte den primära BankID-vägen.

---

## App-för-app: funktion, deep-links, kapabilitet, begränsningar, widgetar

### 1. Deck — `deck` (uppgifts-/bevakningsmodulen, kanban-halvan)

**Vad det gör.** Boards → stacks (listor) → kort med etiketter, **due dates**, tilldelning (en/flera), kommentarer, bilagor, kort↔kort-relationer, aktivitetsström och delning till användare/grupper/Team (Circles). Due dates **speglas automatiskt in i kalendern** via CalDAV.

**Deep-link-rutter.**
- App: `/apps/deck/`
- Board: `/apps/deck/board/{boardId}`
- Kort: `/apps/deck/board/{boardId}/card/{cardId}`
- REST/OCS-API: `/index.php/apps/deck/api/v1.0/...` och `/ocs/v2.php/apps/deck/api/v1.0/...` (kräver header `OCS-APIRequest: true`). Resurser: `boards`, `stacks`, `cards`, `labels`, `comments`.

**Kapabilitet.** Moget datalager för delade köer, status via stacks (Ny/Påbörjad/Väntar/Klar som kolumner), deadlines, tilldelning.

**Begränsningar (kända luckor Hubs ska täcka själv).** Ingen separat **påminnelse före due date** (#1549); aviseringar har historiskt gått till alla board-medlemmar snarare än bara tilldelad (#566). Drag-and-drop saknar fullgott tangentbordsalternativ → WCAG 2.5.7-risk om kanban-UI exponeras rått. Default-UI är inte handläggar-/ärendeorienterat.

**Widgetar det kan driva.** `bevakningar` (Mina bevakningar & frister), `minaUppgifter`, `granskningsko` (plockbar kö), `funktionsbrevlador` (otilldelat/plocka-mönstret), och som kanban-datalager bakom `rehabarenden`, `orosanmalningar`, `utskrivningsbevakning` när dessa behöver delad-kö-semantik.

**Data IN → UT.** IN: kort skapas "från meddelande" (sdkmc-summary → Deck-kort med länk + föreslagen frist). UT: när uppgiften klarmarkeras är *utfallet* (beslut, journalnotat) committat i facksystemet; Deck-kortet gallras som personlig arbetsnotering, inte bevaras som allmän handling (håll isär per `research-uppgifter.md`).

---

### 2. Tasks — `tasks` (uppgifts-/bevakningsmodulen, personliga VTODO-halvan)

**Vad det gör.** CalDAV-VTODO-app: titel, beskrivning, start-/förfallodatum, **påminnelsetider (VALARM)**, prioritet, dellistor, kommentarer. Synkar mot DAVx5/OpenTasks/Apple Reminders/Thunderbird. Ger "min personliga lista"-halvan som komplement till Decks delade boards.

**Deep-link-rutter.**
- App: `/apps/tasks/`
- Lista/kalender: `/apps/tasks/#/calendars/{calendarId}`
- Datalager: CalDAV `VTODO` på `/remote.php/dav/calendars/{user}/{calendar}/` (ingen separat OCS-API; läs/skriv via CalDAV eller core Calendar-API).

**Kapabilitet.** Native **påminnelser per uppgift** (det Deck saknar i kärnan) och äkta personlig/privat lista. Bra för "T-7/T-3/T-0"-påminnelser om de modelleras som VALARM.

**Begränsningar.** Ingen delad-kö-/board-vy (det är Decks domän); ingen rik status-modell utöver completed/percent-complete; ingen native koppling till ärende.

**Widgetar.** `minaUppgifter`, och påminnelse-motorn bakom `bevakningar` och `fristStrip`.

**Data IN → UT.** Personliga frister härledda ur frister i facksystemet/lagkrav (1 mars, dag 30 rehab, 14-dagars förhandsbedömning). Gallras som privat notering.

---

### 3. Calendar + Appointments — `calendar` (kalender + bokningsbar tid)

**Vad det gör.** Core groupware-kalender (CalDAV) plus inbyggt **Appointments**-bokningssystem: handläggaren skapar en bokningskonfiguration (längd, intervall, buffert, tillgänglighet), delar en **publik eller privat (hemlig URL) bokningssida**, och vid bokning skapas en kalenderhändelse. **Med Talk installerad skapas ett unikt mötesrum automatiskt** i bokningsbekräftelsen — exakt "bokningsbar tid → auto säkert videorum".

**Deep-link-rutter.**
- App: `/apps/calendar/`
- Specifik vy/datum: `/apps/calendar/dayGridMonth/{YYYY-MM-DD}`
- Bokningskonfiguration (admin-vy): `/apps/calendar/appointment/...`
- Publik bokningssida: `/apps/calendar/appointment/{userId}/{configToken}`
- Datalager: CalDAV `/remote.php/dav/calendars/{user}/...`

**Kapabilitet.** Mogen, officiell. Bokningssida + auto-videorum är produktionsklart. Card View ("Skapa bokningstid") → Quick View (längd/intervall) passar Hubs-mönstret.

**Begränsningar.** Publik bokningssida är **oautentiserad som standard** — för känsliga ärenden måste Hubs lägga LOA3/identitetslager framför eller begränsa till interna flöden. Helt fristående publik bokningsportal + alltid-auto-videorum är delvis uppströms-önskemål (#3480, #3484).

**Widgetar.** `bokningsbaraTider` (skapa bokningsbar tid + auto-videorum + BankID/Freja-lobby), `dagensMoten` (aggregerad mötesvy med en-klicks-anslut).

**Data IN → UT.** IN: extern part (medborgare/ställföreträdare/medarbetare) bokar; eller handläggaren kallar. UT: mötet *äger inget rekord* — beslut/SIP-plan/anteckning förs in i facksystemet; kalenderhändelsen är transit.

---

### 4. Talk / spreed — `spreed` (säkert möte: rum, chat, lobby, inspelning, transkription, SIP)

**Vad det gör.** Rum (1:1, grupp, publik), chat (**trådade konversationer nytt i v32**), filer i chatt, **lobby/väntrum**, moderatorroller, samtalspermissions, **SIP dial-in**, inspelning och (nytt i v32) **AI-undertext/live-transkription**. Hubs kör forken `spreed-itsl` med auto-videorum + BankID/Freja-lobby.

**Deep-link-rutter.**
- Rum/samtal i webben: `/call/{token}` (kanoniskt) eller `/index.php/apps/spreed/call/{token}`
- App: `/apps/spreed/`
- OCS-API rum: `/ocs/v2.php/apps/spreed/api/v4/room` (skapa/lista), `/ocs/v2.php/apps/spreed/api/v4/room/{token}`
- Chat: `/ocs/v2.php/apps/spreed/api/v1/chat/{token}`
- occ: `talk:room:create` (ägare/moderator-parametrar)

**Kapabilitetskonstanter (för Hubs lobby-/inspelnings-UI).**
- **Lobby-state:** `0` = ingen lobby, `1` = lobby för icke-moderatorer (= Hubs "väntrum tills handläggaren släpper in"). Per-deltagare kan släppas in manuellt — bäraren av "BankID/Freja-verifierad deltagare i väntrummet".
- **Recording-state:** `0` ingen, `1` video pågår, `2` audio pågår, `3` startar video, `4` startar audio, `5` misslyckades.
- **SIP-state:** `0` av, `1` på (unik PIN per deltagare), `2` på utan PIN (endast token). In-call-flagga `8` = deltagare via SIP dial-in.
- **Start-call-permission:** `0` alla, `1` konton på instansen, `2` endast moderatorer, `3` ingen.

**Vad som funkar idag vs kräver backend.**
- **Idag (P2P, ingen HPB):** 1:1 och små rum (~3–5 deltagare), chat, lobby, filer. Räcker för demo av enkla säkra samtal.
- **Kräver HPB (High-Performance Backend: signaling/Spreed + Janus SFU + NATS):** stabila gruppsamtal, skalning, och är **förutsättning** för inspelning och transkription. HPB är separat drift (dedikerad bandbredd/CPU).
- **Kräver recording-server:** separat container; inspelning sparas som **WebM** i `Talk/Recordings/`-mappen.
- **Kräver `live_transcription` + GPU:** se app 5 nedan.

**Widgetar.** `dagensMoten`, `bokningsbaraTider` (mötesrummet), `identitetsBadge` (LOA/metod per deltagare i lobbyn via completionData), och mötesdelen av SIP-/rehab-/granskningsflöden.

**Data IN → UT.** Mötet är ren transit. Inspelningen (om gjord) och whiteboard-exporten bör knytas till `arenderum` och föras till facksystemet/diariet om de blir allmän handling; annars gallras enligt beslut. Tänk OSL/arkivlag: en inspelning av ett myndighetsmöte kan vara allmän handling.

---

### 5. Live transkription / AI-undertext — `live_transcription` (NY i v32)

**Vad det gör.** Genererar live-undertexter (och översättning) i pågående säkert möte — "live AI subtitles" i Hub 25 Autumn. Del av Assistant-ekosystemet men egen app.

**Hårda krav (viktigt för wiring-beslut).**
- **HPB släppt EFTER september 2025** (måste vara konfigurerad i Talk-inställningarna).
- **NVIDIA-GPU med ≥10 GB VRAM**, x86-CPU 4 trådar + 2 trådar per samtidigt samtal, ~16 GB RAM för 1–2 samtidiga samtal.
- ~2.8 GB container + ~6.0 GB språkmodeller.
- **28 språk** inkl. svenska saknas explicit i listan (listan upptar bl.a. en/de/fr/es/it/nl/pl/pt-BR/ru/uk/zh m.fl. — **svenska bör verifieras separat innan det utlovas i demo**). Ethical-AI: **gul**.

**Widgetar/flöden.** Tillgänglighetsvärde (undertext för hörselnedsättning → WCAG/DOS-lagen-argument) och mötesdokumentation. **Inte** en dashboard-widget i sig, men ett säljbart tillval i mötespaketeringen. **Status: kräver tung backend — inte demo-bart utan GPU.**

**Data IN → UT.** Ljud → text on-prem (ingen molntjänst = suveränitet bevarad). Transkriptet är potentiellt allmän handling → samma arkivbedömning som inspelning.

---

### 6. Forms — `forms` (säkert formulär / samtycke, internt)

**Vad det gör.** Formulär med publik delningslänk, anonyma svar, ett-svar-per-inloggad, resultatvy med enkla diagram, CSV-export och **JSON-API**.

**Deep-link-rutter.**
- App: `/apps/forms/`
- Redigera: `/apps/forms/{hash}/edit`, resultat: `/apps/forms/{hash}/results`
- Publik svarslänk: `/apps/forms/{hash}` (oautentiserad)
- API: `/ocs/v2.php/apps/forms/api/v3/...` (formulär, frågor, svar)

**Kapabilitet.** Bra för **internt strukturerad inhämtning** med enkla fält: SIP-samtycke, rehab-samtycke, internt orosanmälnings-/avvikelseformulär, enkäter.

**Begränsningar (avgörande).** **Ingen native filuppladdning i svar** (#... fildropp måste lösas separat) och **ingen villkorslogik/förgrening** (#358). Publik länk är **oautentiserad** → kräver Hubs identitetslager (LOA3) framför känsliga flöden. → Forms kan **inte** ersätta en kommunal orosanmälan-e-tjänst (Open ePlatform/Abou) rakt av.

**Widgetar.** `mallarSamtycke` (samtyckesblanketter, plan för återgång FK 7459, SIP-samtycke), `orosanmalningar` (internt inrapporterings-led), enkätdelen av `nytta`.

**Data IN → UT.** IN: handläggare/medarbetare/extern part fyller i. UT: ett ifyllt samtyckesformulär är en **allmän handling** → arkiveras i `arenderum`/diariet, exporteras (CSV/JSON) till e-arkiv; gallras enligt dokumenthanteringsplan.

---

### 7. Tables — `tables` (strukturerat register, osynlig motor)

**Vad det gör.** No-code databaslager: tabeller → kolumner (text/tal/datum/val/användare) → rader → vyer, åtkomststyrning per tabell/vy, **egen dashboard-widget**, mobil/offline. Del av "Nextcloud Flow". Mogen 2.x-serie.

**Deep-link-rutter.**
- App: `/apps/tables/`
- Tabell: `/apps/tables/#/table/{tableId}`, vy: `/apps/tables/#/view/{viewId}`
- API: `/ocs/v2.php/apps/tables/api/2/...` (v2; v3 under utveckling) — tables, columns, rows, views.

**Kapabilitet.** Idealt **backend** för triage-status, deadline-/nytto-/avvikelse-/incidentregister och regel-/flaggningslogik. **Renderas som Hubs-widget, aldrig rå tabell.**

**Begränsningar.** Ingen inbyggd regelmotor för komplexa villkor (kombinera med Flow/egen logik); rå Tables-UI är inte handläggar-anpassat.

**Widgetar.** `nytta` (ROI-register), `samverkansavvikelser`, `incidentrapporter` (klock-logik ovanpå), `uppdragskontroll` (flaggningsregler), `complianceStatus`/`loggSparbarhet` (härledda register), och status-/frist-fält bakom `arsrakningar`, `orosanmalningar`, `utskrivningsbevakning`.

**Data IN → UT.** IN: härledd metadata (kanal, frist, status) från sdkmc/handläggare. UT: register är **internt arbetsmaterial / internkontroll** — det "riktiga" beslutet/avvikelsen committas till facksystemet/regionens avvikelsesystem; Tables speglar och mäter, äger inte rekordet.

---

### 8. Collectives — `collectives` (kunskapsbank)

**Vad det gör.** Wiki: sidor/undersidor, full-textsök, markdown lagrad i Files, åtkomst per **Team (Circles)**. On-prem kunskapsbank för rutiner, mallar, gallringsplaner, lathundar.

**Deep-link-rutter.**
- App: `/apps/collectives/`
- Specifik collective/sida: `/apps/collectives/{collectiveName}` resp. `/apps/collectives/{collectiveName}/{pagePath}`
- API: `/ocs/v2.php/apps/collectives/api/v1.0/...`; sidinnehåll bor som `.md` i Files.

**Kapabilitet.** Lågtröskel, portabelt, on-prem. "Internt 1177-stöd" för handläggaren.

**Begränsningar.** Kräver `circles`/Team-appen (finns i core). Ingen strukturerad data (det är Tables); ingen formell versionering utöver Files-versioner.

**Widgetar.** `kunskapsbank` (genväg till rutiner/mallar — låst utanför det konfigurerbara skalet, WCAG 3.2.6 Consistent Help).

**Data IN → UT.** Statiskt internt referensmaterial; inget ärenderekord.

---

### 9. Files + Groupfolders/Team folders — `files`, `groupfolders` (säkra filer / ärenderum)

**Vad det gör.** Core Files + **Groupfolders (Team folders)**: mappar ägda av grupper/Team, **avancerad ACL per fil/mapp** (Read/Write/Create/Delete/Share, allow/deny per användare/grupp/Team), automatisk **versionshantering**, papperskorg, system-/samarbetstaggar, händelselogg. On-prem samredigering via **Collabora (Nextcloud Office)** eller **OnlyOffice** över WOPI.

**Deep-link-rutter.**
- Files: `/apps/files/`
- Mapp: `/apps/files/?dir=/{path}` (eller v32 `/apps/files/files/{fileId}`)
- Direkt fil-id: `/f/{fileId}` (kanonisk djuplänk till valfri fil/mapp)
- Groupfolders admin: `/settings/admin/groupfolders`
- WebDAV-datalager: `/remote.php/dav/files/{user}/...`
- API: `/ocs/v2.php/apps/groupfolders/folders` (skapa/ACL).

**Kapabilitet.** Produktionsmoget fillager + rättssäkerhetsbyggblock. **Ärenderum = en Groupfolder per dnr/barn/uppdrag** med rätt ACL, gallringstagg och deltagare.

**Begränsningar.** Samredigering kräver Collabora/OnlyOffice-server (separat container/WOPI). ACL-modellen är kraftfull men kräver disciplinerad orkestrering (Hubs skapar rummet, sätter ACL — inte handläggaren manuellt).

**Widgetar.** `arenderum` (status/olästa/väntar-signatur/gallrings-countdown/medborgardelning per rum), `senasteFiler` ("vad hände med mina dokument senast"), och dokumentdelen av i princip alla ärende-personas.

**Data IN → UT.** IN: bilagor från säkra meddelanden, uppladdningar från medborgare (BankID-delning), samredigerade utkast. UT: **originalhandlingen committas till facksystemet/diariet**; ärenderummet är aktivt arbetslager → **FGS-export till e-arkiv** (Sydarkivera) + Retention-gallring när ärendet stängs. Detta är kärnan i "Hubs = mellanlagring".

---

### 10. Files Retention — `files_retention` (regelstyrd gallring)

**Vad det gör.** Gallrar filer automatiskt baserat på **tagg + tidsregel** (t.ex. "tagg X → radera 7 år efter senaste ändring"). Notis till ägaren innan radering. Kräver en **restricted/invisible-tagg** så att en delad användare inte kan ta bort taggen för att undvika gallring.

**Deep-link/konfig.** Admin: `/settings/admin/...` (Retention-sektion) eller appens egen inställningssida; taggar sätts via Files / Automated Tagging.

**Kapabilitet.** Operationaliserar kommunens dokumenthanteringsplan i Hubs-lagret. Direkt svar på **arkivförordningens 2024-krav** (export + radering före införande).

**Begränsningar.** Per-handlingstyp-gallring kräver att rätt restricted-tagg sätts vid rummets skapande; gallringsregeln måste vara **konfigurerbar per kund** (kommunen beslutar själv om gallring — Riksarkivet ger bara allmänna råd).

**Widgetar.** Gallrings-countdown i `arenderum`, `arkivGallring` ("Gallras 2031 enligt handlingstyp X" / "Bevaras" + Leverera till e-arkiv).

**Data IN → UT.** Säkerställer att Hubs-lagret **inte** blir oavsiktlig slutlagring: filer gallras när originalet bevarats i facksystem/e-arkiv.

---

### 11. Whiteboard — `whiteboard` (samverkanstavla i mötet)

**Vad det gör.** Officiell realtidstavla (oändlig canvas), **förbättrad i v32**. Kan **startas inifrån ett pågående säkert möte** (via chattens bifoga-meny) och delas till alla deltagare; resultatet sparas som fil.

**Deep-link.** Whiteboard-filer (`.whiteboard`) öppnas via Files (`/f/{fileId}`); skapas oftast i mötet, inte djuplänkat fristående. Kräver WebSocket-backend (`whiteboard_server`) för live-läge.

**Kapabilitet.** SIP-/planeringstavla (mål/ansvar/vem-gör-vad) i realtid med medborgare/anhöriga i mötet.

**Begränsningar.** **WebSocket-backend måste driftsättas** för realtidssamarbete (grundläge funkar utan men inte flerpartslive). Fri canvas är **notoriskt svår att göra WCAG-tillgänglig** → använd som *möteskomplement*, aldrig som ensam bärare av beslut (det formella beslutet dokumenteras i SIP-plan/Forms/facksystem).

**Widgetar.** Inte en egen widget; ett tillval i SIP-/mötespaketeringen (`dagensMoten`/`bokningsbaraTider`-flödet).

**Data IN → UT.** Tavlan exporteras till `arenderum`; den formella SIP-planen förs in i regionens system (Cosmic LINK/Lifecare) — tavlan är arbetsskiss.

---

### 12. LibreSign — `libresign` (intern on-prem dokumentsignering)

**Vad det gör.** Native PDF-signering i Nextcloud: digitala certifikat, **flera signatärer med signeringsordning/roller**, interna + externa signatärer i samma begäran, QR-kodvalidering av signerat dokument, validering av certifikatstatus. **v12.4.5 stödjer NC 32** (stöd 17→34).

**Deep-link-rutter.**
- App: `/apps/libresign/`
- Signeringsbegäran (extern signatär via länk): `/apps/libresign/p/sign/{uuid}` (kontolöst signeringsflöde)
- API: `/ocs/v2.php/apps/libresign/api/v1/...`

**Backend-beroenden (kräver installation).** `occ libresign:install` drar in **cfssl** (egen certifikatutfärdare för self-signed), **Java + JSignPDF** (applicerar signaturen på PDF) och **pdftk**. Detta är den "extra backend" som gör LibreSign mer än en knapp.

**Kapabilitet.** Helt on-prem signeringsworkflow med flera parter och externa signatärer utan konto.

**Begränsningar (kritiskt för svensk kontext).** LibreSigns README/dok nämner **inte BankID/Freja/eID** native — den bygger på egna/uppladdade certifikat (self-signed via cfssl eller extern CA). För svensk offentlig sektor är **avancerad e-underskrift via BankID/Freja det de facto-kravet** (eIDAS art. 26, SKR:s vägledning dec 2025). **Därför:** LibreSign är lämplig för **interna lågrisk-flöden** (SES/intern AES med org-certifikat) och som on-prem-fallback — men den **primära signeringskärnan för Hubs bör vara Inera Underskriftstjänst-API eller egen Sweden Connect-nod** (BankID/Freja/SITHS), per `research-esignering.md`. PAdES/PDF/A + LTV-bevarande ("Giltig nu/Giltig då") är ett gap att bygga oavsett motor.

**Widgetar.** `attSignera` (kö över dokument som väntar min underskrift, kravnivå-badge), `skickatForSignering` (utgående spårning Skickat→Öppnat→Signerat av X av Y), `justeringAnslag` (protokolljustering). **Notera:** dessa widgetar är `dataSource: proposed` och bör mot BankID gå via Inera/Sweden Connect; LibreSign driver internt/lågrisk-spåret.

**Data IN → UT.** IN: dokument från `arenderum`/beslut. UT: signerad PDF/A + valideringsintyg arkiveras i ärenderummet och **förs in i facksystemet/diariet**; signaturbeviset (inte bara kryptot) bevaras för långtidsvalidering.

---

### 13. Activity — `activity` (händelseström / spårbarhet)

**Vad det gör.** Core aktivitetsström: vem gjorde vad när (fil delad/ändrad, kort flyttat, kommentar). **OCS-API v2** med rich objects (`subject_rich`), filterbar per `object_type`/`object_id` (med `filter=filter`).

**Deep-link-rutter.**
- App: `/apps/activity/`
- API: `/ocs/v2.php/apps/activity/api/v2/activity/{filter}` (params: `object_type`, `object_id`, `since`, `limit`).

**Kapabilitet.** Råmaterial för "senaste händelser"-flöden, spårbarhet (NIS2) och leverans-/läshändelser där appen exponerar dem.

**Begränsningar.** Aktivitetsfeed är generisk och bullrig — Hubs bör **filtrera och översätta** till ärendekontext, inte visa rå feed. `fileid` kan vara sträng/tal inkonsekvent (#152) — hantera defensivt.

**Widgetar.** Bidrar till `senasteFiler`, `sakerhetshandelser`, `loggSparbarhet` (kompletterar SDK-loggen), och händelsedelen av `arenderum`.

**Data IN → UT.** Ren spårbarhet; matar internkontroll och compliance-bevis.

---

### 14. Flow / workflow_engine — core `workflowengine` + `flow` (automation)

**Vad det gör.** Tre lager: **(a)** core **workflow_engine** (regelbaserade åtgärder på triggers: t.ex. "fil med tagg X i mapp Y → blockera åtkomst / tagga / notifiera"), **(b)** **Files Automated Tagging** (sätt collaborative tags på upp-/nedladdning enligt regler — matar Retention och File Access Control), **(c)** **Nextcloud Flow** = avancerad BPA byggd på **Windmill** (ExApp) + Tables för riktiga business-flöden.

**Deep-link/konfig.**
- Regler: `/settings/admin/workflow` (och per-app flow-sektioner)
- Automated tagging: del av workflow-inställningarna
- Avancerad Flow (Windmill): egen ExApp-UI, kräver AppAPI/Docker.

**Kapabilitet.** **Idag utan extra backend:** automatisk taggning → driver Retention/gallring och File Access Control; enkla regelåtgärder. Utvecklare kan exponera egna events via `OCP\WorkflowEngine\IEntity` (`getEvents()`) — Hubs kan låta sdkmc-händelser bli flow-triggers.

**Begränsningar.** Avancerad BPA (Windmill) kräver ExApp/Docker. Kärn-regelmotorn är åtgärds-, inte ärende-orienterad.

**Widgetar.** Inte en widget; **infrastruktur** bakom `arkivGallring` (auto-tagg → Retention), `arenderum` (auto-ACL/tagg vid skapande), `incidentrapporter` (event → register/notis), regelmotorn bakom `uppdragskontroll`.

**Data IN → UT.** Automatiserar staging-regler (tagga, gallra, notifiera) — påverkar inte slutlagringen.

---

### 15. Assistant + AI-stacken — `assistant`, `llm2`, `context_chat`, `context_agent`, `stt_whisper2`, `translate2`, `summary_bot` (lokal AI-prioritering & textbearbetning)

**Vad det gör.** `assistant` (ny standard-UI i v32) ger grafiskt UI + smart picker + chatt; bakom den krävs en **text-provider**. Hubs-doktrinen: **endast lokala, grön-ratade modeller** (llm2/OLMo-familjen), avstängbart, transparent ("varför"), aldrig destruktivt, prioriterar ärendeegenskaper inte användarbeteende (GDPR art. 22).

**App-id + krav.**
- `assistant` — UI; **kräver provider** (llm2 lokalt **eller** integration_openai). Ethical: beror på provider.
- `llm2` — **lokal LLM** (llama.cpp, GGUF; Llama 3.1 default). **Rekommenderat ~8 GB VRAM + 12 GB RAM**; körs som **ExApp via AppAPI/Docker**. Ethical: **grön**. (Hubs bör välja grön-ratad modell, t.ex. OLMo 2, för suveränitetsargumentet.)
- `context_chat` + `context_chat_backend` — RAG över dokument; **major+minor-version måste matcha mellan de två**; kräver text-provider. Ethical: gul.
- `context_agent` — kör Nextcloud-uppgifter via assistenten (ExApp). Ethical: grön.
- `stt_whisper2` — tal-till-text (Whisper, lokalt, ExApp). Ethical: gul.
- `translate2` — översättning (MADLAD/Google-modeller, lokalt). Ethical: grön.
- `summary_bot` — sammanfattar Talk-chattar; kräver text-provider.

**Deep-link.** Assistant nås via global "smart picker" (Ctrl/`@`) och `/apps/assistant/`; mestadels integrerat i andra appar, inte djuplänkat fristående.

**Kapabilitet.** AI-triage-stöd vid hög volym (t.ex. 514k orosanmälningar/år nationellt): *föreslå* prioritering ovanpå deterministisk sortering (frist → sekretess → oläst); dokumentsammanfattning; transkription. **AI utan att data lämnar servern** = upphandlingsargument.

**Begränsningar.** Allt meningsfullt AI-värde kräver **GPU/Docker-backend** (llm2/whisper). Utan det: ingen lokal AI. OpenAI-provider bryter suveränitetslöftet → **får inte** användas för sekretessdata. Version-matchning context_chat ↔ backend är en driftfälla.

**Widgetar.** Lager ovanpå `attHantera`/`orosanmalningar` (föreslagen ordning + "varför"), sammanfattning i `arenderum`, transkription i mötesflödet. **Status: kräver backend — demo-bart bara med GPU eller med stubbed/förklarande "AI av" som default.**

**Data IN → UT.** AI läser staging-data lokalt och *föreslår* — den **fattar aldrig beslut och skriver aldrig till facksystemet**. Utfallet committas alltid av människa.

---

## Wiring-prioritering (vad demar vi, i vilken ordning)

**Nivå 1 — wirebart idag på ren v32, hög demo-utdelning:**
Deck + Tasks (`bevakningar`/`minaUppgifter`/`granskningsko`), Calendar/Appointments + P2P-Talk (`bokningsbaraTider`/`dagensMoten`), Files/Groupfolders + Retention (`arenderum`/`senasteFiler`/`arkivGallring`), Tables (`nytta`/`samverkansavvikelser`/`incidentrapporter`-register), Forms (`mallarSamtycke`), Collectives (`kunskapsbank`), Activity + Flow-regler (spårbarhet/auto-tagg).

**Nivå 2 — kräver en backend-komponent, planera in:**
Talk **HPB** (stabila gruppmöten + förutsättning för nivå 3), Whiteboard **WebSocket-server** (SIP-tavla), **Collabora/OnlyOffice** (samredigering i ärenderum), LibreSign **cfssl/JSignPDF** (internt signeringsspår).

**Nivå 3 — tung backend, "wow men dyrt":**
Talk **recording-server** (inspelat möte), **`live_transcription` + NVIDIA-GPU** (AI-undertext — **verifiera svenskt språkstöd före löfte**), **AI-stacken via `llm2`/`stt_whisper2` på Docker/GPU** (lokal AI-triage/sammanfattning/transkription).

**Suverän signeringskärna (parallellt spår, ej en NC-app):** **Inera Underskriftstjänst-API / egen Sweden Connect-nod** för BankID/Freja/SITHS-baserad AES — den primära vägen för `attSignera`/`skickatForSignering`/`justeringAnslag`. LibreSign är komplement, inte ersättning.

---

## Källor

**NC 32 / Hub 25 Autumn release & app-status**
- Nextcloud Hub 25 Autumn (v32) release: https://nextcloud.com/blog/nextcloud-hub25-autumn/
- Hub 25 Autumn pressmeddelande (sovereignty): https://nextcloud.com/blog/press_releases/hub-25-autumn/
- Upgrade to Nextcloud 32 (admin manual): https://docs.nextcloud.com/server/stable/admin_manual/release_notes/upgrade_to_32.html
- Hub 25 Autumn-funktioner (threaded Talk, live subtitles, Assistant): https://alternativeto.net/news/2025/9/nextcloud-hub-25-autumn-release-brings-new-ui-threaded-talk-office-enhancements-and-more
- Maintenance-uppdateringar (32.0.1 enterprise): https://nextcloud.com/blog/nextcloud-hub-25-autumn-for-enterprises-and-maintenance-updates-for-all-supported-versions/

**Deck / Tasks (uppgifter)**
- Deck REST/OCS-API: https://deck.readthedocs.io/en/latest/API/ · https://deck.readthedocs.io/en/latest/API-Nextcloud/
- Deck-dokumentation: https://deck.readthedocs.io/
- Deck issues #1549 (påminnelse), #566 (avisering tilldelad): https://github.com/nextcloud/deck/issues/1549 · https://github.com/nextcloud/deck/issues/566
- Tasks (VTODO/CalDAV): https://github.com/nextcloud/tasks
- Calendar/CalDAV admin-manual: https://docs.nextcloud.com/server/stable/admin_manual/groupware/calendar.html

**Calendar / Appointments**
- Appointment Booking System (DeepWiki): https://deepwiki.com/nextcloud/calendar/7-appointment-booking-system
- Auto-videorum / publik bokningssida (issues): https://github.com/nextcloud/calendar/issues/3480 · https://github.com/nextcloud/calendar/issues/3484

**Talk / spreed + HPB + inspelning + transkription**
- Talk API (rum/chat/konstanter): https://nextcloud-talk.readthedocs.io/en/latest/ · https://nextcloud-talk.readthedocs.io/en/latest/constants/
- Talk occ-kommandon (talk:room:create): https://nextcloud-talk.readthedocs.io/en/latest/occ/
- Spreed get room web URL (/call/{token}): https://help.nextcloud.com/t/spreed-api-get-room-web-url/236146
- HPB (signaling/Janus/NATS) – Spreed: https://www.spreed.eu/contact-nextcloud-talk-high-performance-backend/
- HPB self-host guide: https://arnowelzel.de/en/nextcloud-talk-high-performance-backend-with-docker
- Live transcription (krav, GPU, språk, HPB > sep 2025): https://docs.nextcloud.com/server/32/admin_manual/ai/app_live_transcription.html
- live_transcription repo: https://github.com/nextcloud/live_transcription

**Forms / Tables / Whiteboard / Collectives**
- Forms (API, gränser): https://github.com/nextcloud/forms · https://github.com/nextcloud/forms/issues/358 · https://help.nextcloud.com/t/form-with-file-upload-capability/62189
- Tables (API, Flow): https://nextcloud.com/blog/build-apps-using-nextcloud-tables/ · https://github.com/nextcloud/tables/issues/2237
- Whiteboard (WebSocket, i möte): https://nextcloud.com/blog/nextcloud-whiteboard/ · https://github.com/nextcloud/whiteboard/blob/main/README.md
- Collectives: https://apps.nextcloud.com/apps/collectives · https://nextcloud.com/blog/nextcloud-22-introduces-knowledge-management/

**Files / Groupfolders / Retention / Tagging**
- Groupfolders + ACL: https://github.com/nextcloud/groupfolders/blob/master/README.md · https://nextcloud.com/blog/access-control-lists/ · https://portal.nextcloud.com/article/Operations/Using-Groupfolders---Advanced-Permissions
- Retention: https://docs.nextcloud.com/server/stable/admin_manual/file_workflows/retention.html
- Versionshantering: https://docs.nextcloud.com/server/stable/admin_manual/configuration_files/file_versioning.html
- Office (Collabora/OnlyOffice WOPI): https://www.onlyoffice.com/office-for-nextcloud · https://help.nextcloud.com/t/onlyoffice-vs-collabora/232279

**LibreSign**
- App Store (NC 32-stöd, v12.4.5): https://apps.nextcloud.com/apps/libresign · https://apps.nextcloud.com/apps/libresign/releases?platform=17
- Repo (features, occ libresign:install, cfssl/jsignpdf/pdftk): https://github.com/LibreSign/libresign · https://gitlab.com/librecodecoop/libresign/libresign
- Dok: https://docs.libresign.coop

**Activity / Flow / workflow_engine**
- Activity OCS-API v2: https://github.com/nextcloud/activity/blob/master/docs/endpoint-v2.md · https://docs.nextcloud.com/server/latest/user_manual/en/activity.html
- Tagging & Workflows: https://portal.nextcloud.com/article/Operations/Tagging-and-Workflows · https://docs.nextcloud.com/server/stable/admin_manual/file_workflows/automated_tagging.html
- Nextcloud Flow (Windmill/Tables/OCS): https://nextcloud.com/flow/ · https://nextcloud.com/blog/nextcloud-flow-makes-it-easy-to-automate-actions-and-workflows/ · https://docs.nextcloud.com/server/stable/developer_manual/digging_deeper/flow.html · https://github.com/nextcloud/flow

**Assistant / AI-stacken**
- AI overview (alla appar, Ethical-rating): https://docs.nextcloud.com/server/stable/admin_manual/ai/overview.html
- llm2 (lokal LLM, krav): https://docs.nextcloud.com/server/32/admin_manual/ai/app_llm2.html
- Context Chat: https://docs.nextcloud.com/server/30/admin_manual/ai/app_context_chat.html
- Context Agent: https://docs.nextcloud.com/server/stable/admin_manual/ai/app_context_agent.html
- Nextcloud Assistant: https://nextcloud.com/assistant/

**Dashboard-API (för widget-registrering)**
- Interaktiva widgets i Hub: https://nextcloud.com/blog/guide-to-interactive-widgets-in-nextcloud-hub/
- Dashboard developer manual: https://docs.nextcloud.com/server/latest/developer_manual/digging_deeper/dashboard.html

**Suverän signeringsinfrastruktur (primär BankID-väg, ej NC-app — se research-esignering.md)**
- Inera Underskriftstjänsten: https://www.inera.se/tjanster/alla-tjanster-a-o/underskriftstjansten/
- Digg Underskriftstjänst (öppen källkod, Sweden Connect): https://www.digg.se/digitala-tjanster/e-underskrift/underskriftstjanst
- SKR Vägledning Digitala underskrifter (dec 2025): https://skr.se/download/18.383b393a19afcdc7ea383305/1765380441264/Vagledning-Digitala%20underskrifter-2025.pdf
