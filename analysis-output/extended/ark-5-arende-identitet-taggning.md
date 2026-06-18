<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Ärende-identitet som röd tråd över hela Hubs — kanonisk ärende-id, taggning och automatik

> **Kärnfrågan.** Hur kopplas ETT ärende ihop tvärs ALLA Hubs-appar — så att säkra meddelanden, filer,
> möten, uppgifter, chatt och beslut hänger ihop under **samma ärende-identitet** — och hur mycket av det
> kan Nextcloud **Flow** göra automatiskt vs vad måste göras **programmatiskt** i sdkmc/en
> orkestreringstjänst?
>
> **Persona i centrum:** socialsekreterare (barn & familj). **Bärande arkitektur:** Hubs är mellanlagring;
> facksystemet (Treserva/Lifecare via **Frends**) är system of record. **Antagande (per uppdrag):** alla
> blockerare lösta (Treserva-commit via Frends med verifierad återkoppling, Inera-signering, lokal
> transkribering, Retention-paus). **Plattform:** server v32 (Hub 25 Autumn). **Datum:** 2026-06-14.
>
> **Varumärkesregel:** i produkt-/UI-text aldrig "Nextcloud"/"Talk". I detta interna underlag namnger vi
> app-id (sdkmc, mail, groupfolders, deck, spreed-itsl, calendar, tables, libresign, workflowengine,
> systemtags) för att kunna wire:a.
>
> **Grundas i:** `middleware-architecture.md`, `arendehantering-map.md`, `native-apps-map.md`,
> `WIDGET-APP-MAP.md`, `GAP-ANALYSIS.md` (särskilt GAP-005, GAP-007, GAP-010, GAP-019, GAP-041, GAP-051),
> `UX-REDESIGN-SOCIALSEKRETERARE.md`, `widgetApps.js`.

---

## 0. Headline (svaret på en sida)

**Ärende-identiteten är EN kanonisk token — `hubsCaseId` (UUID) — som föds i Hubs ärenderegister (en `tables`-tabell, ägd av sdkmc-orkestreringstjänsten) och paras 1:1 med facksystemets dnr via Frends-callbacken.** Den tokenen är den röda tråden: varje objekt i varje app bär den, inte som fritext utan som **en restricted/invisible systemtag** `case:{hubsCaseId}` (för filer, meddelanden, signeringar) och som ett **strukturerat fält** (`objectId`/`solfaktor` i Deck-kort, Talk-rum, kalenderhändelser). "Öppna ärende" i dashboarden är då en enda server-side-aggregering: *ge mig alla objekt taggade `case:X` i alla appar* → en flik-vy.

**Arbetsdelningen Flow vs programmatiskt — den skarpaste skiljelinjen i hela frågan:**

| | **Nextcloud Flow (workflowengine) klarar** | **Måste göras PROGRAMMATISKT (sdkmc-orkestrering / Frends)** |
|---|---|---|
| **Taggning** | Auto-tagga **filer** vid upp-/nedladdning i ett ärenderum (mapp→tagg, Files Automated Tagging); sätt **retention-tagg** vid avslut. | Auto-tagga **inkommande meddelanden** (sdkmc/mail) med rätt `case:X` när avsändare/ConversationId/dnr matchar — Flow ser inte sdkmc-meddelandeobjekt utan en **custom `IEntity`** + en matchnings-orkestrering. |
| **Skapande** | — (Flow skapar inte mappar, board, kort eller rum). | **Hela "skapa ärende"-orkestreringen i ETT anrop**: Groupfolder + ACL + Deck-kort + Talk-rum + kalender + register-rad, alla stämplade med `hubsCaseId`. |
| **Routning** | Flytta/blockera **fil** baserat på tagg + mapp (File Access Control). | Routa **meddelande/bilaga** till rätt ärenderum (sdkmc skriver filen till rätt Groupfolder). |
| **Commit/frist** | Notifiera; sätt retention-paus-tagg (via custom event). | Commit till Treserva via Frends; spegla dnr + frist tillbaka; flippa provenans. |

**Deck-svaret (specifikt, eftersom det saknades i exemplen):** **board per enhet/team — kort per ärende.** INTE board per ärende. Ett barn- & familjeteam har **en** Deck-board ("Barn & familj — utredning"); varje ärende är **ett kort** vars `title` är pseudonym + dnr-token, som bär en **label** `case:{hubsCaseId}` och vars `description`/kommentar håller deep-links till ärenderum, Talk-rum och Treserva-akten. Ärendekortet i dashboarden (`ArendeKort.vue`) ↔ Deck-kortet kopplas via att båda bär `hubsCaseId`; sdkmc lagrar mappningen `hubsCaseId → {deckBoardId, deckCardId}` i ärenderegistret så att uppslaget är O(1), inte en sökning.

---

## 1. Ärende-identiteten — den kanoniska token

### 1.1 En token, tre namn — och var de bor

Ett ärende bär genom sin livscykel **tre** identifierare. Att hålla isär dem är hela poängen (det löser GAP-005 "Hubs-token ↔ dnr-mappning" och GAP-002 "fristens start"):

| Identifierare | Form | När den föds | Vem äger den | Roll |
|---|---|---|---|---|
| **`conversationId`** | sdkmc/AS4 Message/Conversation ID (samt mail Message-ID, fax-referens) | när meddelandet **inkommer** | sdkmc | Provenance-ankaret. Binder inflödet till ett ärende **innan** dnr finns. 14-dgr-klockan startar här (inkom-datum). |
| **`hubsCaseId`** | **UUID v4**, t.ex. `c1a7…` (visas som triage-ref `SN 2026-0142`) | när ärendet **skapas** (triage "Ta emot & starta") | **Hubs ärenderegister (tables, via sdkmc)** | **Den kanoniska röda tråden.** Bärs av varje objekt i varje app. Existerar även när dnr ännu saknas. |
| **`dnr`** | Treserva-dnr, `2026-IFO-0142` | när ärendet **registreras/aktualiseras** i facksystemet (Frends-commit) | **Treserva** (system of record) | Slutdestinationens nyckel. Paras 1:1 (eller 1:n vid syskon) med `hubsCaseId` via Frends-callbacken. |

**Var `hubsCaseId` bor — kanoniskt:** i en `tables`-tabell `hubs_arenden` som **ägs och skrivs uteslutande av sdkmc-orkestreringstjänsten** (aldrig av handläggaren rått). Tables är rätt hem därför att (a) det redan är "den osynliga motorn" bakom triage-/statusregister i `native-apps-map.md`, (b) det har OCS-API (`/ocs/v2.php/apps/tables/api/2/tables/{id}/rows`) som dashboarden redan renderar som widget, aldrig som rå tabell, och (c) åtkomststyrning per vy ger OSL-säkerhetsgränsen. sdkmc äger *skrivningen*; Tables är *lagret*.

> **Varför inte låta sdkmc-meddelandet/ConversationId vara ärende-id:t?** Ett ärende överlever sitt första meddelande, samlar många meddelanden (komplettering, svar, fax, SDK från annan myndighet), och måste finnas innan ett dnr existerar och efter att Hubs-meddelandena gallrats. Därför en egen, stabil token. ConversationId **mappar in** i ärendet (1:n), det **är** inte ärendet (GAP-041).

### 1.2 Ärenderegistrets rad-shape (`tables`-tabellen `hubs_arenden`)

```
hubsCaseId       UUID        — kanonisk token (PK)
triageRef        text        — 'SN 2026-0142' (kommunal referens före aktualisering)
barnRef          text        — pseudonym, ALDRIG klartext-PII
enhet            text        — ägande team/funktionsadress (barn-familj@) — ACL-gräns
agareUid         user        — tilldelad handläggare (null = otilldelat, i 'Att ta emot')
steg             select      — inflode|forhandsbedomning|utredning|beslut|uppfoljning|avslutat
dnr              text        — Treserva-dnr (null tills registrerad via Frends)
provenanceState  select      — ej_registrerad|registrerad
conversationIds  text[]      — alla sdkmc/mail/fax-referenser som hör till ärendet (1:n)
groupfolderId    int         — ärenderummet (Groupfolder)
deckBoardId      int         — enhetens board
deckCardId       int         — ärendets kort på den boarden
talkToken        text        — ärendets chattrum (spreed-itsl)
calendarObjUri   text        — ev. seriekalender för ärendets möten
caseTagId        int         — systemtag-id för 'case:{hubsCaseId}' (restricted)
retentionState   select      — aktiv|pausad|gallras_efter_commit
fristDue         date        — speglad ur Treserva (Frends), ej självständigt räknad (GAP-018)
skapad           datetime
```

Denna rad **är** kopplingsnavet: den håller pekarna (`groupfolderId`, `deckCardId`, `talkToken`, `caseTagId`, `dnr`) som gör att "öppna ärende" blir O(1)-uppslag i stället för fan-out-sökning. Allt i en server-side-aggregering (CONTRACTS hård regel: ingen klient-fan-out).

### 1.3 Mappningen `hubsCaseId ↔ dnr` (löser GAP-005, GAP-007, 1:n-syskonfallet)

- **1:1 normalfall:** Frends-commit returnerar `{hubsCaseId, dnr}` → sdkmc skriver `dnr` + `provenanceState='registrerad'` på raden, och **kompletterar systemtaggen** så att objekt blir sökbara på *både* `case:{hubsCaseId}` *och* en dnr-alias-tagg. Provenans-chippen flippar.
- **1:n syskonfall:** en orosanmälan gäller flera barn → **ett `hubsCaseId` per barn**, men de delar `conversationId` (samma inkommande anmälan mappar till flera ärenden). Frends returnerar en lista `[{hubsCaseId, dnr}…]`. Detta är exakt det entydiga svar GAP-005 efterlyste.
- **Gallring bunden till verifierad commit (GAP-007):** `retentionState` sätts till `gallras_efter_commit` **först** när Frends-callbacken bekräftat — aldrig vid en kryssruta. Retention-taggen sätts då programmatiskt; Flow utför sedan själva gallringen på tid.

---

## 2. Per app — hur kopplingen realiseras

Genomgående mekanism: **systemtag `case:{hubsCaseId}`** för filer/meddelanden/signeringar (objekt som NC:s tag-API kan tagga), och **strukturerat fält** för objekt som inte är fil-taggbara (Deck-kort, Talk-rum, kalenderhändelse — där `hubsCaseId` läggs i kortets label/`objectId`/kategorifält). ITSL:s **itsl-tag-API** (finns redan) är den skrivväg sdkmc använder för meddelandetaggning; för filer används NC:s systemtags (WebDAV `/remote.php/dav/systemtags` + Automated Tagging).

### 2.1 sdkmc / mail / securemail / fax — säkra meddelanden

- **Koppling:** varje inkommande/utgående meddelande får taggen `case:{hubsCaseId}` via **itsl-tag-API:t**. sdkmc lagrar dessutom meddelandets `conversationId` i ärenderadens `conversationIds[]`.
- **Auto-koppling av inkommande (kärnan i automatiken):** när ett nytt meddelande landar kör sdkmc en **matchningsregel** (se §3.2): matchar avsändarens org-cert/funktionsadress + `conversationId` (svar i en tråd) + ev. dnr i ärendemening mot ärenderegistret. Träff → auto-tagga `case:X`, lägg i ärendets Meddelanden-flik. Ingen träff → hamnar **otaggat i "Att ta emot"** (det legitima "ej kopplat"-tillståndet — aldrig tyst feltaggat).
- **Brand/UI:** i dashboarden syns detta som meddelanderaderna i ärendekortets *Meddelanden*-flik med `kvittenser`-tidslinje; otaggat inflöde i Zon 1 "Att ta emot".

### 2.2 Files / Groupfolders — ärenderummet (mapp per ärende)

- **Koppling — två lager, bälte + hängslen:**
  1. **Strukturellt:** **en Groupfolder per ärende** (`/Ärenden/{barnRef}-{kort-hubsCaseId}`), skapad av orkestreringen med least-permission-ACL (handläggare skriver, gruppledare läser) + BBIC-mappstruktur. `groupfolderId` skrivs på ärenderaden.
  2. **Semantiskt:** en **Files Automated Tagging-regel** sätter `case:{hubsCaseId}` på *varje fil som hamnar i mappen* (Flow klarar detta — se §3.1). Då hänger filer ihop med ärendet även om de senare flyttas, och retention-/sök-/aggregeringslogiken blir mapp-oberoende.
- **Deep-link:** `/apps/files/?dir=/Ärenden/{rum}` resp. `/f/{fileId}`. Dubbel countdown (facksystem-bevarande + Hubs-rensning) per `arenderum`-widgeten.

### 2.3 Deck — uppgifter/bevakning (**det specifika svaret**)

**Modell: board per enhet/team — kort per ärende.** (INTE board per ärende.)

Motivering, entydigt:
- En **board per ärende** skulle ge hundratals boards per enhet, ingen plockbar delad kö, och bryta Decks delnings-/ACL-modell (board delas till team/Circle). Det skalar inte och saknar enhetsöverblick.
- En **board per enhet** ger exakt det socialtjänstprocessen behöver: mottagningsgruppens/utredningsgruppens **delade, plockbara kö**, stacks som processteg (Förhandsbedömning · Utredning · Beslut · Uppföljning), och ett kort = ett ärende som flyttas mellan stacks när steppern flyttas.

**Kort↔ärende-kopplingen (det som saknades i exemplen):**
- Deck-kortets `title` = `{barnRef} · {dnr|triageRef}`.
- Kortet bär en **label** `case:{hubsCaseId}` (Deck-labels är board-scoped → en `case:`-label per kort; alternativt läggs `hubsCaseId` i en dold rad i `description`). Detta är Decks motsvarighet till en tagg eftersom Deck-kort **inte** är fil-taggbara med systemtags.
- sdkmc lagrar `{deckBoardId, deckCardId}` på ärenderaden → **O(1)-uppslag** från `hubsCaseId` till kortet och tillbaka. Det är denna tvåvägs-pekare som binder dashboardens `ArendeKort.vue` till Deck-kortet (klick "Skapa bevakning"/"Bevakningar-fliken" → öppnar rätt kort via `/apps/deck/board/{deckBoardId}/card/{deckCardId}`).
- **Skapande:** Deck-API:t skapar kortet i två steg (POST kort → PUT label/due) — därför är kortskapande **programmatiskt** i orkestreringen, inte i Flow.
- **Frister:** kortets due date speglas ur Treserva (Frends), CalDAV-speglas till kalendern; T-7/T-3/T-0-påminnelser bara till tilldelad (Hubs-jobb ovanpå Deck #1549/#566).

> Stacks ⇄ stepper: när `ArendeKort`s `ProcessStepper` flyttas (efter verifierad commit) flyttar orkestreringen Deck-kortet till motsvarande stack. En kort kan inte tyst avancera om plikt (skyddsbedömning) är okvitterad — fas-spärren gäller även Deck-flytten.

### 2.4 Talk / spreed-itsl — chattrum per ärende

- **Modell: ett Talk-rum per ärende** (gruppkonversation), `name` = `{barnRef} · {dnr}`, skapat av orkestreringen; `talkToken` skrivs på ärenderaden. Detta är ärendets interna **chatt som Teams** (kollegor, gruppledare) — skild från det säkra *mötet* (video) och från extern klientkommunikation (som går via sdkmc).
- **Koppling — ärlig teknisk not (GAP-relaterat):** Talk:s v4-room-API har `objectType`/`objectId`, men core tillåter idag bara värdet `room` (breakout-rooms) och saknar lookup-by-object. **Därför kopplas rummet kanoniskt via ärenderegistrets `talkToken`-pekare, inte via Talks objektfält.** Vill man ha det inbäddat i Talk självt är det en liten **`spreed-itsl`-fork-utökning** (tillåt `objectType='hubs_case'`, `objectId=hubsCaseId` + ett find-by-object-endpoint) — ett rent fork-jobb, inte core. Tills dess: pekaren i registret räcker för dashboard-aggregeringen.
- **Deep-link:** `/call/{talkToken}`. Syns i ärendekortets *Möten/Chatt*-yta.

### 2.5 Calendar — möten med ärende-id

- **Koppling:** kalenderhändelser för ärendets möten skapas med `hubsCaseId` i `CATEGORIES` (VEVENT-fält som överlever export) och/eller en `X-HUBS-CASE`-property; `calendarObjUri` kan skrivas på ärenderaden för seriemöten. SIP-möten via `MeetingWizard`/`createSecureMeeting` får dnr-chip i Zon 4.
- **Auto-videorum:** Appointments + spreed-itsl skapar mötesrummet; rummet knyts till ärendet via samma `hubsCaseId`-konvention. Mötet äger inget rekord (transit) — godkänd anteckning committas till Treserva via `CommitGrind`.

### 2.6 Tables — ärenderegistret (navet, §1.2)

- Tables **är** lagret där `hubsCaseId` och alla pekare bor. Renderas aldrig rått; dashboardens `fetchArende(dnr|hubsCaseId)` läser raden + dess pekare och aggregerar. Tables är också statushem för 14-dgr-/4-mån-speglingar.

### 2.7 libresign / Inera — signering taggad

- **Koppling:** en signeringsbegäran (PAdES/PDF/A) bär `case:{hubsCaseId}` (på dokumentet i ärenderummet **och** som metadata på begäran). När signerat: signerad handling + valideringsintyg + delgivningsbevis arkiveras i ärenderummet (taggat) och committas via `CommitGrind` till Treserva-akten. `attSignera`/`skickatForSignering`-status bor i ärendekortets *Beslut*-flik, filtrerat på `case:X`.

### 2.8 Sammanfattande kopplings-matris

| App (app-id) | Objekt | Bärare av ärende-id | Mekanism | Skapas av |
|---|---|---|---|---|
| sdkmc/mail/securemail | meddelande, kvittens | `case:{id}`-tag + `conversationId` i registret | itsl-tag-API + matchningsregel | sdkmc (auto/triage) |
| groupfolders/files | ärenderum, filer | Groupfolder + `case:{id}` systemtag | Automated Tagging (Flow) på mapp | orkestrering (mapp) + Flow (tagg) |
| deck | kort = ärende | label `case:{id}` + `{boardId,cardId}`-pekare | Deck-API (2 steg) + register-pekare | orkestrering |
| spreed-itsl | chattrum | `talkToken`-pekare (+ ev. fork-`objectId`) | room-API + register-pekare | orkestrering |
| calendar | möte/serie | `CATEGORIES`/`X-HUBS-CASE` = `hubsCaseId` | CalDAV-property | orkestrering/MeetingWizard |
| tables | ärenderad | är själva nyckeln (`hubsCaseId` PK) | OCS rows-API | sdkmc (ensam skrivare) |
| libresign/Inera | signeringsbegäran | `case:{id}` på dokument + metadata | tag + begäran-metadata | orkestrering vid "Skicka för underskrift" |

---

## 3. Automatik — vad Flow gör vs vad som görs programmatiskt

Detta är frågans tyngdpunkt. **Regeln att hålla i huvudet:** core-Flow (`workflowengine` + Files Automated Tagging) är **fil-/tagg-centrisk och deklarativ** — den reagerar på filhändelser och utför fil-operationer (tagga, blockera, flytta, retention, notifiera). Allt som rör **icke-fil-objekt** (meddelanden, Deck-kort, Talk-rum, kalender, facksystem-commit) eller **skapande/orkestrering av flera objekt** ligger utanför core-Flow och görs **programmatiskt** i sdkmc-orkestreringstjänsten (ev. via Windmill ExApp för det som vill vara konfigurerbart per kund).

### 3.1 Vad Nextcloud FLOW (workflowengine) ska göra — det deklarativa lagret

Konfigureras i `/settings/admin/workflow`, körs av core utan extra backend:

1. **Auto-tagga filer i ärenderum (Files Automated Tagging).**
   Regel: *fil skapad/uppdaterad i mapp `…/Ärenden/{rum}` → sätt tagg `case:{hubsCaseId}`.* Eftersom regeln är per mapp genererar orkestreringen denna regel (eller använder en mappnamn→tagg-konvention) när rummet skapas. Detta är hur **filer** hamnar i den röda tråden utan att handläggaren taggar manuellt.

2. **Sätt retention-tagg vid avslut.**
   Regel: *när ärendet markeras avslutat/`gallras_efter_commit` (signaleras via en tagg `state:committed` som orkestreringen sätter efter Frends-callback) → sätt restricted retention-tagg `retention:hubs-30d`.* `files_retention` gallrar sedan på tid. Restricted/invisible-taggen hindrar handläggaren från att ta bort den för att undvika gallring.

3. **File Access Control / blockering.**
   Regel: *fil med tagg `sekretess:hög` får inte delas externt / laddas ned utan LOA3.* Stödjer maskerings-/sekretessdisciplinen (GAP-017), om än inte hela lösningen.

4. **Notifiering vid filhändelse.**
   Regel: *ny fil i ärenderum → notifiera tilldelad handläggare.* Kompletterar (ersätter inte) ärendekortets aviseringar.

5. **(Custom event-brygga) — gör sdkmc-händelser till Flow-triggers.**
   sdkmc registrerar en **custom `IEntity`** (implementerar `OCP\WorkflowEngine\IEntity::getEvents()`) som exponerar t.ex. `MessageReceivedEvent`, `CaseCommittedEvent`. Då kan *delar* av meddelande-/commit-logiken uttryckas som Flow-regler med checks (avsändare matchar, funktionsadress = X). **Men** själva matchningen mot ärenderegistret och taggningen via itsl-tag-API:t sker i operationens kod — Flow ger UI:t och trigger-ramen, sdkmc ger logiken. Detta är gränslandet, och det är medvetet tunt: Flow är inte en ärende-orkestrerare.

> **Vad Flow INTE klarar (avgörande):** skapa mappar/board/kort/rum; tagga ett *meddelande* (sdkmc-objekt, inte fil) utan custom entity; läsa/skriva facksystemet; köra fler-objekts-transaktioner; spegla en frist ur Treserva. Core-Flow har heller ingen native "kör webhook/externt skript"-operation — den vägen kräver **Windmill ExApp** (Docker) eller en egen event-listener. Allt detta → §3.2.

### 3.2 Vad som måste göras PROGRAMMATISKT — sdkmc-orkestreringstjänsten

Detta är ITSL-koden (en tunn orkestreringstjänst i sdkmc + Frends-konnektorn). Fyra orkestreringar:

**(A) Auto-koppla inkommande meddelande → ärende (matchningsmotorn).**
Vid `MessageReceivedEvent`:
1. Slå upp `conversationId` i `hubs_arenden.conversationIds[]` → träff = befintligt ärende.
2. Annars heuristik: avsändar-org-cert + funktionsadress + ev. dnr i ärendemening + (valfritt) lokal `llm2`-förslag (avstängbart, transparent, aldrig auto-commit) → **föreslå** matchande ärende.
3. Träff → `itsl-tag` meddelandet `case:{id}`, append `conversationId`, lägg i ärendets Meddelanden-flik.
4. Ingen säker träff → lämna **otaggat i "Att ta emot"** (Zon 1). Det legitima "ej kopplat"-tillståndet — bättre obesvarat än feltaggat (sekretess). Handläggaren kopplar manuellt ("Koppla till befintligt ärende") → då skrivs taggen + `conversationId`.

**(B) "Skapa ärende" i ETT anrop (löser GAP-010 — orkestreringen som inte är "ett klick" idag).**
Vid triage "Ta emot & starta förhandsbedömning" kör orkestreringen, transaktionellt och i rätt ordning:
```
1. INSERT hub_arenden-rad → hubsCaseId (UUID)
2. systemtags: skapa 'case:{hubsCaseId}' (restricted) → caseTagId
3. groupfolders: skapa ärenderum + ACL (least-permission) + BBIC-struktur → groupfolderId
   + lägg Automated Tagging-regel mapp→case-tag (Flow tar över taggningen sen)
4. deck: hämta enhetens board → POST kort (title=barnRef·triageRef) → PUT label case:{id}
   → {deckBoardId, deckCardId}
5. spreed-itsl: POST gruppkonversation (name=barnRef·triageRef) → talkToken
6. (vid behov) calendar: förbered seriekalender → calendarObjUri
7. starta 14-dgr-klocka bunden till inkom-datum (conversationId), INTE till nu (GAP-002)
8. tagga utlösande meddelande(n) case:{id}, flytta från 'Att ta emot' → ärendekort
9. öppna mallstyrd skyddsbedömnings-notering; den committas direkt via CommitGrind (GAP-001)
```
Allt med samma `hubsCaseId`. Detta är "ett klick" som UX-spec:en lovar; idag är det flera manuella admin-steg (GAP-010).

**(C) Routa fil/bilaga till rätt ärenderum.**
När en bilaga följer med ett kopplat meddelande skriver sdkmc filen till `case:X`-rummets Groupfolder (WebDAV). Då fångar Flow-regeln (3.1.1) taggningen automatiskt. (Flow *flyttar* inte in i rätt rum — den vet inte vilket ärende; sdkmc vet, via matchningen i A.)

**(D) Commit till Treserva + spegla dnr/frist + provenans-flip (Frends).**
Vid `CommitGrind`-"För över": `Frends.commitToTreserva({hubsCaseId, payload})` → vid **verifierad callback** `{hubsCaseId, dnr}`: skriv `dnr` + `provenanceState='registrerad'`, komplettera systemtag med dnr-alias, spegla `fristDue` ur Treserva, flippa provenans-chip, flytta stepper/Deck-kort, släck pliktmarkör, sätt `retentionState='gallras_efter_commit'` (varpå Flow-regel 3.1.2 så småningom gallrar). Aldrig gallring utan denna callback (GAP-007).

### 3.3 Var Windmill (ExApp) passar in

Det som ska vara **konfigurerbart per kund utan kodändring** — t.ex. matchningsheuristikens vikter, kravnivå-mappning beslutstyp→AES, vilka stacks en board har — kan ligga som Windmill-flöden (Nextcloud Flow = Windmill, ExApp/Docker). Kärnorkestreringen (A–D) bör dock vara hårdkodad i sdkmc för transaktionsgaranti och sekretess (ingen ärendedata genom en generisk flow-motor i onödan).

---

## 4. "Öppna ärende" — hur dashboarden samlar allt

**Mekaniken:** dashboardens `fetchArende(hubsCaseId)` gör **en** server-side-aggregering i sdkmc som läser ärenderaden och följer dess pekare — inte en klient-fan-out:

```
GET /ocs/v2.php/apps/sdkmc/api/v1/arende/{hubsCaseId}   (eller {dnr})
  → läs hub_arenden-raden
  → meddelanden:  itsl-tag-sök 'case:{id}' i sdkmc/mail  → Meddelanden-flik (+ kvittenser)
  → dokument:     systemtags-sök 'case:{id}' i files/groupfolders (caseTagId) → Dokument-flik
  → bevakningar:  GET deck card {deckBoardId,deckCardId} (+ kommentarer/due) → Bevakningar-flik
  → chatt/möten:  GET talk room {talkToken} + calendar CATEGORIES='hubsCaseId' → Möten-flik
  → beslut:       libresign-begäranden taggade 'case:{id}' (+ Inera-status) → Beslut-flik
  → provenans:    dnr + dubbel countdown (Treserva-bevarande / Hubs-rensning)
```

Resultatet renderas som `ArendeKort.vue`s expanderade Quick View med flikarna **Dokument · Meddelanden · Möten · Bevakningar · Beslut** (exakt UX-spec:ens kort). **Ärende-taggen är den enda joinnyckeln** — varje fliks innehåll är "alla objekt med `case:{hubsCaseId}` i app N". Eftersom registret håller direktpekare (`deckCardId`, `talkToken`, `groupfolderId`) blir de tunga uppslagen O(1); bara meddelande-/fil-/signeringslistorna är tagg-sökningar (indexerade).

**"Att ta emot" = inverterad vy:** allt **otaggat** inflöde (meddelanden utan `case:`-tagg, `agareUid=null`) — det legitima "ej kopplat"-tillståndet — som väntar på triage. Tom "ej registrerad"-kö (allt taggat + committat) = compliance-KPI (GAP-049).

**Dnr-vägen:** facksystemet/Frends pekar tillbaka via `dnr` → sdkmc slår upp `dnr → hubsCaseId` i registret → samma aggregering. Så fungerar deep-linking från Treserva in i rätt Hubs-ärende.

---

## 5. Konsekvenser, ärlighet, gap

- **Löser direkt:** GAP-005 (kanonisk token + 1:n-syskonmodell), GAP-010 (ett-klicks-orkestrering), GAP-041 (ConversationId↔ärende-mappning), GAP-002 (frist bunden till inkom-datum/conversationId), och ger den infrastruktur GAP-007/019 behöver (commit-bunden gallring via Frends-callback).
- **Kräver fork-jobb (ärligt):** Talk-rummets *inbäddade* objektbindning (`objectType='hubs_case'`) är en `spreed-itsl`-utökning; tills dess bär ärenderegistrets `talkToken`-pekare kopplingen. Deck-kort kan inte fil-taggas → `case:`-label + register-pekare är lösningen.
- **Sekretess-/ACL-gräns (GAP-051):** `case:`-systemtaggen och Tables-vyn måste ACL:as per enhet/funktionsadress så att "öppna ärende" aldrig exponerar ett barn till fel handläggare. Restricted-taggen + Groupfolder-ACL + Tables-vy-behörighet är tre lager som måste vara koherenta.
- **GDPR-dataminimering:** taggvärdet är `hubsCaseId` (UUID/triage-ref), aldrig klartext-PII; korttitlar är pseudonymer (`barnRef`). Taggen läcker inget även om den syns brett.

---

## Implementering

- **Appar:** `tables` (ärenderegistret `hubs_arenden`, navet) · `groupfolders`+`files`+`files_versions` (ärenderum) · `systemtags`/Files Automated Tagging (filtaggning) · `deck` (board per enhet, kort per ärende) · `spreed-itsl` (chattrum + ev. fork-objektbindning) · `calendar` (möten med `hubsCaseId` i CATEGORIES) · `libresign`/Inera (signering taggad) · `workflowengine` (Flow-regler) · sdkmc/mail/securemail (meddelandetaggning via itsl-tag-API). Slutlagring: Treserva via **Frends**.
- **I Flow (deklarativt, `/settings/admin/workflow`):** (1) Automated Tagging mapp→`case:{id}` på ärenderum; (2) avslut/`state:committed`→restricted `retention:hubs-30d`; (3) File Access Control på `sekretess:hög`; (4) notifiering vid filhändelse; (5) custom `IEntity` i sdkmc som exponerar `MessageReceivedEvent`/`CaseCommittedEvent` som Flow-triggers. Per-kund-konfigurerbar logik kan ligga i **Windmill ExApp**.
- **Programmatiskt (sdkmc-orkestrering + Frends):** ärenderegister-skrivning (ensam skrivare); UUID-generering; **matchningsmotorn** (conversationId/avsändare/dnr → auto-tagga eller lämna i "Att ta emot"); **ett-klicks "skapa ärende"** (register+tag+groupfolder+ACL+Deck-kort+Talk-rum+kalender, alla med `hubsCaseId`); fil-/bilageroutning till rätt rum; **Frends-commit** + dnr/frist-spegling + provenans-flip + commit-bunden retention. Talk-objektbindning = `spreed-itsl`-fork. Nya OCS-routes: `/api/v1/arende-summary`, `/api/v1/arende/{hubsCaseId|dnr}`, `/api/v1/treserva/commit`.

## UI i socialsekreterarvyn

- **Ärende-id syns aldrig rått** — det bär `ArendeKort.vue`: titel `{barnRef} · dnr {dnr|triageRef}`, `ProcessStepper`, `FristChip`, `ProvenansChip`. `hubsCaseId` är den osynliga joinnyckeln bakom.
- **"Öppna ärende"** = expandera kortet → Quick View med flikarna **Dokument · Meddelanden · Möten · Bevakningar · Beslut**, var och en fylld av "alla objekt med `case:{hubsCaseId}`". Ett klick, ingen sidbyte, ingen fan-out.
- **"Att ta emot" (Zon 1)** = otaggat inflöde (ej kopplat ännu) med två knappar: *Ta emot & starta förhandsbedömning* (kör ett-klicks-orkestreringen → nytt `hubsCaseId`, raden blir ett ärendekort i Zon 2/3) och *Koppla till befintligt ärende* (skriver `case:`-taggen + `conversationId` på en befintlig ärenderad).
- **Deck-kopplingen syns** i kortets *Bevakningar*-flik: "Skapa bevakning" → POST på enhetens board, kortet öppnas via `/apps/deck/board/{deckBoardId}/card/{deckCardId}`; stepper-flytt = stack-flytt.
- **Commit-ögonblicket** (`CommitGrind`): "För till Treserva" → Frends → vid verifierad callback flippar `ProvenansChip` ("Registrerad i Treserva, dnr X"), dubbel countdown startar, pliktmarkör släcks. Det är där `hubsCaseId` paras med `dnr` och den röda tråden når slutlagringen.
