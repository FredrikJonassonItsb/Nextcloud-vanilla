<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Hubs-arkitektur för socialtjänst — det sammanhållna svaret

> **Vad detta är:** lead-arkitektens syntes av de fem arkitektur-varven (ark-1…ark-5) till **ett**
> sammanhållet svar på de sex bärande frågorna för socialsekreterarens Hubs. Den bärande modellen — som
> binder ihop allt — är **ärende-identitet som röd tråd**: en kanonisk ärende-token som propageras automatiskt
> över sdkmc/mail/Files/Deck/Talk/Calendar/Tables/libresign, dels i **FLOW** (deklarativa workflow_engine-regler),
> dels **PROGRAMMATISKT** (sdkmc-orkestrering + Frends).
>
> **Persona i centrum:** `socialsekreterare` (barn & familj, SoL 2025:400, BBIC). **Bärande arkitektur:**
> Hubs är **mellanlagring**; facksystemet (Treserva/Lifecare via **Frends**) är **system of record**.
> **Antagande (per uppdrag):** alla blockerare lösta — Treserva-commit via Frends (verifierad återkoppling),
> Inera Underskriftstjänst, lokal transkribering, Retention-paus. **Plattform:** server v32 (Hub 25 Autumn).
> **Datum:** 2026-06-14.
>
> **Varumärkesregel (enforced):** i produkt-/UI-text aldrig "Nextcloud"/"Talk"/"Circles". Vi säger *Hubs,
> ärenderum, korg, funktionsadress, säkert meddelande, ärendechatt, enhetschatt, team, bevakning, fördelningsvy,
> e-underskrift, facksystemet/Treserva*. App-id (sdkmc, mail, securemail, groupfolders/files, deck, spreed-itsl,
> circles, calendar, tables, libresign, workflowengine/flow, files_retention, activity, systemtags) namnges
> **bara** i byggnoteringar.
>
> **Grundas i:** `analysis-output/extended/ark-1…ark-5`, `UX-REDESIGN-SOCIALSEKRETERARE.md`,
> `SOCIALSEKRETERARE-WALKTHROUGH.md`, `GAP-ANALYSIS.md`. Systerdokument:
> `UI-EVOLUTION-SOCIALSEKRETERARE.md` (den byggbara UI-specen).

---

## 0. Headline — svaret på en sida

**Allt hänger på EN kanonisk token: `hubsCaseId` (UUID).** Den föds i Hubs ärenderegister (en `tables`-tabell
`hubs_arenden`, skriven uteslutande av sdkmc-orkestreringstjänsten), paras 1:1 (eller 1:n vid syskon) med
facksystemets `dnr` via Frends-callbacken, och bärs av **varje objekt i varje app** — som en restricted/invisible
**systemtag** `case:{hubsCaseId}` för filer/meddelanden/signeringar, och som ett **strukturerat fält/pekare** för
de objekt som inte är fil-taggbara (Deck-kort, Talk-rum, kalenderhändelse). "Öppna ärende" blir då **en** server-side-aggregering
("ge mig alla objekt taggade `case:X`"), aldrig en klient-fan-out.

De sex frågorna, i en mening var:

1. **Multi-korg & sortering:** triagen spänner över *alla* behöriga korgar samtidigt; den meningsbärande
   axeln är **ärendekoppling** (`nytt | hör-till | ej-kopplat`), inte korg — korg är filter, typ styr åtgärd, frist styr prioritet.
2. **Chatt (Talk) + Circles/Teams:** chatt premieras som **en flik på ärendekortet + en räknare i pulsen + en
   lugn enhetsyta**, aldrig en andra inkorg; team = Circles, en medlemskapssanning som delas med ärenderums-ACL.
3. **Mottagning → tilldelning (chef):** tilldelning är **två lägen** — symmetriskt *plock* i mottagningen och
   asymmetrisk *fördelning* av chefen vid beslut "inleda"; fördelningen skriver om ACL till least-permission.
4. **Ej-ärendekopplade meddelanden:** en uttrycklig **"Ej ärendekopplat"-hink** med en registrerings-/gallringsgrind
   — ingen rad får försvinna utan att kopplas, registreras eller gallras med dokumenterat stöd.
5. **Ärende-identitet som röd tråd:** `hubsCaseId` + `case:`-tagg + register-pekare; Flow taggar **filer**,
   programmatik taggar **meddelanden/Deck/Talk/kalender** och kör fler-objekts-orkestreringen.
6. **Deck specifikt:** **board per enhet/team — kort per ärende** (inte board per ärende); kortet bär en
   `case:`-label och en tvåvägs-pekare `{deckBoardId, deckCardId}` i registret för O(1)-uppslag.

**Den skarpaste skiljelinjen — Flow vs programmatiskt:**

| | **Nextcloud Flow (workflowengine) klarar** | **Måste göras PROGRAMMATISKT (sdkmc-orkestrering / Frends)** |
|---|---|---|
| **Taggning** | Auto-tagga **filer** i ett ärenderum (mapp→tagg, Files Automated Tagging); sätt retention-tagg vid avslut. | Auto-tagga **meddelanden** (sdkmc-objekt) med rätt `case:X`; matchningen mot registret. |
| **Skapande** | — (Flow skapar inte mapp/board/kort/rum). | **Hela "skapa ärende" i ETT anrop**: Groupfolder+ACL+Deck-kort+Talk-rum+kalender+register-rad, alla stämplade `hubsCaseId`. |
| **Routning** | Flytta/blockera **fil** på tagg+mapp (File Access Control). | Routa **meddelande/bilaga** till rätt ärenderum (sdkmc skriver filen). |
| **Commit/frist** | Notifiera; sätt retention-paus-tagg via custom event. | Commit till Treserva via Frends; spegla dnr+frist tillbaka; flippa provenans. |

---

## 1. Den bärande modellen — ärende-identitet som röd tråd

### 1.1 En token, tre namn — och var de bor

Ett ärende bär genom sin livscykel **tre** identifierare; att hålla isär dem är hela poängen (löser GAP-005
"token↔dnr" och GAP-002 "fristens start").

| Identifierare | Form | Föds när | Ägs av | Roll |
|---|---|---|---|---|
| **`conversationId`** | sdkmc/AS4 Message/Conversation-ID (samt mail Message-ID, fax-ref) | meddelandet **inkommer** | sdkmc | Provenans-ankaret. Binder inflödet till ett ärende **innan** dnr finns. 14-dgr-klockan startar här (inkom-datum). |
| **`hubsCaseId`** | **UUID v4** (visas som triage-ref `SN 2026-0142`) | ärendet **skapas** (triage "Ta emot & starta") | **Hubs ärenderegister (tables, via sdkmc)** | **Den kanoniska röda tråden.** Bärs av varje objekt i varje app. Finns även utan dnr. |
| **`dnr`** | Treserva-dnr `2026-IFO-0142` | ärendet **registreras** i facksystemet (Frends-commit) | **Treserva** (system of record) | Slutdestinationens nyckel. Paras 1:1 (1:n vid syskon) med `hubsCaseId` via Frends-callbacken. |

`hubsCaseId` bor kanoniskt i `tables`-tabellen `hubs_arenden`, **skriven uteslutande av sdkmc-orkestreringstjänsten**
(aldrig av handläggaren rått). Tables är rätt hem därför att (a) det redan är den osynliga motorn bakom triage-/statusregister,
(b) det har OCS-API som dashboarden renderar som widget (aldrig rå tabell), (c) åtkomststyrning per vy ger OSL-säkerhetsgränsen.

> **Varför inte låta ConversationId vara ärende-id:t?** Ett ärende överlever sitt första meddelande, samlar många
> meddelanden (komplettering, svar, fax, SDK), och måste finnas **innan** dnr existerar och **efter** att Hubs-meddelandena
> gallrats. Därför en egen, stabil token. ConversationId **mappar in** i ärendet (1:n) — det **är** inte ärendet (GAP-041).

### 1.2 Ärenderegistrets rad-shape (`tables`-tabellen `hubs_arenden`)

```
hubsCaseId       UUID        — kanonisk token (PK)
triageRef        text        — 'SN 2026-0142' (kommunal referens före aktualisering)
barnRef          text        — pseudonym, ALDRIG klartext-PII
enhet            text        — ägande team/funktionsadress (barn-familj@) — ACL-gräns
agareUid         user        — tilldelad handläggare (null = otilldelat)
status           select      — otilldelat | tilldelat            (ark-3, fördelning)
steg             select      — inflode|forhandsbedomning|utredning|beslut|uppfoljning|avslutat
dnr              text        — Treserva-dnr (null tills registrerad via Frends)
provenanceState  select      — ej_registrerad | registrerad
conversationIds  text[]      — alla sdkmc/mail/fax-referenser som hör till ärendet (1:n)
groupfolderId    int         — ärenderummet (Groupfolder)
deckBoardId      int         — enhetens board
deckCardId       int         — ärendets kort på den boarden
talkToken        text        — ärendets chattrum (spreed-itsl)
calendarObjUri   text        — ev. seriekalender för ärendets möten
caseTagId        int         — systemtag-id för 'case:{hubsCaseId}' (restricted)
retentionState   select      — aktiv | pausad | gallras_efter_commit
fristDue         date        — speglad ur Treserva (Frends), ej självständigt räknad (GAP-018)
skapad           datetime
```

Raden **är** kopplingsnavet: den håller pekarna (`groupfolderId`, `deckCardId`, `talkToken`, `caseTagId`, `dnr`)
som gör "öppna ärende" till ett O(1)-uppslag i stället för en fan-out-sökning.

### 1.3 Mappningen `hubsCaseId ↔ dnr` (GAP-005, syskon-1:n)

- **1:1 normalfall:** Frends-commit returnerar `{hubsCaseId, dnr}` → sdkmc skriver `dnr` + `provenanceState='registrerad'`
  och **kompletterar systemtaggen** så objekt blir sökbara på *både* `case:{hubsCaseId}` *och* en dnr-alias-tagg. Provenans-chippen flippar.
- **1:n syskonfall:** en orosanmälan gäller flera barn → **ett `hubsCaseId` per barn**, men de delar `conversationId`.
  Frends returnerar `[{hubsCaseId, dnr}…]`. (Detta entydiga svar efterlystes i GAP-005.)
- **Gallring bunden till verifierad commit (GAP-007):** `retentionState='gallras_efter_commit'` sätts **först** när
  Frends-callbacken bekräftat — aldrig vid en kryssruta.

---

## 2. App → hur ärendet kopplas → Flow eller programmatiskt (tabellen)

Genomgående mekanism: **systemtag `case:{hubsCaseId}`** för objekt som NC:s tag-API kan tagga (fil/meddelande/signering),
och **strukturerat fält/register-pekare** för de objekt som inte är fil-taggbara (Deck-kort, Talk-rum, kalenderhändelse).
ITSL:s **itsl-tag-API** (finns redan) är skrivvägen för meddelandetaggning; för filer NC:s systemtags + Automated Tagging.

| App (app-id) | Objekt | Bärare av ärende-id | Mekanism (HUR) | **Flow eller programmatiskt** |
|---|---|---|---|---|
| **sdkmc/mail/securemail/fax** | meddelande, kvittens | `case:{id}`-tag + `conversationId` i registret | itsl-tag-API + matchningsregel | **Programmatiskt** (sdkmc matchningsmotor). *Trigger* kan exponeras som custom `IEntity` i Flow, men matchning+taggning sker i kod. |
| **groupfolders/files** | ärenderum, filer | Groupfolder + `case:{id}` systemtag | mapp skapas av orkestrering; tagg via Automated Tagging | **Bägge:** mappen/ACL skapas **programmatiskt**; per-fil-taggen sätts av **Flow** (mapp→tagg-regel). |
| **deck** | **kort = ärende** | label `case:{id}` + register-pekare `{boardId,cardId}` | Deck-API (2 steg: POST kort → PUT label/due) + register-pekare | **Programmatiskt** (Deck-kort kan inte fil-taggas; kortskapande är 2-stegs-API). |
| **spreed-itsl** | ärende-chattrum | `talkToken`-pekare (+ ev. fork-`objectId`) | room-API + register-pekare | **Programmatiskt** (Talk saknar native objektbindning till dnr; se §6). |
| **calendar** | möte/serie | `hubsCaseId` i `CATEGORIES` / `X-HUBS-CASE` | CalDAV-property | **Programmatiskt** (skapas av orkestrering/MeetingWizard). |
| **tables** | ärenderad | **är själva nyckeln** (`hubsCaseId` PK) | OCS rows-API | **Programmatiskt** (sdkmc ensam skrivare). |
| **libresign/Inera** | signeringsbegäran | `case:{id}` på dokument + metadata på begäran | tag + begäran-metadata | **Programmatiskt** (vid "Skicka för underskrift"). |
| **workflowengine** | Flow-reglerna | — (reglerna *sätter* taggar) | deklarativa regler i `/settings/admin/workflow` | **Flow** (det deklarativa lagret; §3.1). |

**Tumregeln:** core-Flow är **fil-/tagg-centrisk och deklarativ** — den reagerar på *filhändelser* och utför
*filoperationer* (tagga, blockera, flytta, retention, notifiera). Allt som rör **icke-fil-objekt** (meddelanden,
Deck-kort, Talk-rum, kalender, facksystem-commit) eller **skapande/orkestrering av flera objekt** ligger utanför
core-Flow och görs **programmatiskt** i sdkmc-orkestreringstjänsten (ev. Windmill ExApp för per-kund-konfigurerbar logik).

---

## 3. Automatik — vad Flow gör vs vad som görs programmatiskt

### 3.1 Vad Nextcloud FLOW (workflowengine) ska göra — det deklarativa lagret

Konfigureras i `/settings/admin/workflow`, körs av core utan extra backend:

1. **Auto-tagga filer i ärenderum (Files Automated Tagging).** *Fil skapad/uppdaterad i `…/Ärenden/{rum}` →
   sätt tagg `case:{hubsCaseId}`.* Orkestreringen genererar regeln (eller en mappnamn→tagg-konvention) när rummet skapas.
   Så hamnar **filer** i röda tråden utan manuell taggning.
2. **Sätt retention-tagg vid avslut.** *När `state:committed` satts (av orkestreringen efter Frends-callback) →
   sätt restricted `retention:hubs-30d`.* `files_retention` gallrar sedan på tid. Restricted-taggen hindrar handläggaren att radera den.
3. **File Access Control / blockering.** *Fil med `sekretess:hög` får inte delas externt utan LOA3.* Stödjer
   maskerings-/sekretessdisciplinen (GAP-017).
4. **Notifiering vid filhändelse.** *Ny fil i ärenderum → notifiera tilldelad.* Kompletterar (ersätter inte) kortets aviseringar.
5. **Posta i ärendechatt (Flow→Talk-åtgärd, native).** *Handling committad / frist tippar → posta systemmeddelande
   i ärende-Talk-rummet.* (ark-2 §8: "committad till Treserva, dnr X".)
6. **Custom event-brygga (`IEntity`).** sdkmc registrerar en custom `IEntity` som exponerar `MessageReceivedEvent`,
   `CaseCommittedEvent` som Flow-triggers. *Trigger-ramen* finns i Flow; *logiken* (matchning, itsl-tag) körs i operationens kod.

> **Vad Flow INTE klarar:** skapa mappar/board/kort/rum; tagga ett *meddelande* (sdkmc-objekt) utan custom entity;
> läsa/skriva facksystemet; köra fler-objekts-transaktioner; spegla en frist ur Treserva. Core-Flow har heller ingen
> native "kör webhook/skript"-operation — det kräver Windmill ExApp eller egen listener.

### 3.2 Vad som måste göras PROGRAMMATISKT — sdkmc-orkestreringstjänsten

Fyra orkestreringar (ITSL-kod, tunn tjänst i sdkmc + Frends-konnektorn):

**(A) Auto-koppla inkommande meddelande → ärende (matchningsmotorn).** Vid `MessageReceivedEvent`:
1. Slå upp `conversationId` i `hubs_arenden.conversationIds[]` → träff = befintligt ärende.
2. Annars heuristik: avsändar-org-cert + funktionsadress + ev. dnr i ärendemening + (valfritt) lokal `llm2`-förslag
   (avstängbart, transparent, aldrig auto-commit) → **föreslå** matchande ärende.
3. Träff → `itsl-tag` meddelandet `case:{id}`, append `conversationId`, lägg i ärendets Meddelanden-flik.
4. **Ingen säker träff → lämna otaggat i "Att ta emot"/"Ej ärendekopplat"** — det legitima "ej kopplat"-tillståndet
   (bättre obesvarat än feltaggat; felkoppling är en sekretessincident). Handläggaren kopplar manuellt → då skrivs taggen + `conversationId`.

**(B) "Skapa ärende" i ETT anrop (GAP-010).** Vid triage "Ta emot & starta förhandsbedömning", transaktionellt:
```
1. INSERT hubs_arenden-rad → hubsCaseId (UUID)
2. systemtags: skapa 'case:{hubsCaseId}' (restricted) → caseTagId
3. groupfolders: skapa ärenderum + ACL (least-permission) + BBIC-struktur → groupfolderId
   + lägg Automated Tagging-regel mapp→case-tag (Flow tar över per-fil-taggningen)
4. deck: hämta enhetens board → POST kort (title=barnRef·triageRef) → PUT label case:{id}
   → {deckBoardId, deckCardId}
5. spreed-itsl: POST gruppkonversation (name=barnRef·triageRef, deltagare = ACL-krets) → talkToken
6. (vid behov) calendar: förbered seriekalender → calendarObjUri
7. starta 14-dgr-klocka bunden till inkom-datum (conversationId), INTE till nu (GAP-002)
8. tagga utlösande meddelande(n) case:{id}, flytta från 'Att ta emot' → ärendekort
9. öppna mallstyrd skyddsbedömnings-notering; committas direkt via CommitGrind (GAP-001)
```
Allt med samma `hubsCaseId`. Detta är "ett klick" som UX-specen lovar.

**(C) Routa fil/bilaga till rätt ärenderum.** När en bilaga följer ett kopplat meddelande skriver sdkmc filen till
`case:X`-rummets Groupfolder (WebDAV). Då fångar Flow-regeln (3.1.1) taggningen. (Flow *flyttar* inte in i rätt rum —
den vet inte vilket ärende; sdkmc vet, via matchningen i A.)

**(D) Commit till Treserva + spegla dnr/frist + provenans-flip (Frends).** Vid `CommitGrind`-"För över":
`Frends.commitToTreserva({hubsCaseId, payload})` → vid **verifierad callback** `{hubsCaseId, dnr}`: skriv `dnr` +
`provenanceState='registrerad'`, komplettera systemtag med dnr-alias, spegla `fristDue` ur Treserva, flippa provenans-chip,
flytta stepper/Deck-kort, släck pliktmarkör, sätt `retentionState='gallras_efter_commit'` (varpå Flow-regel 3.1.2 gallrar). **Aldrig gallring utan denna callback** (GAP-007).

### 3.3 Var Windmill (ExApp) passar in

Per-kund-konfigurerbar logik **utan kodändring** (matchningsheuristikens vikter, kravnivå-mappning beslutstyp→AES,
vilka stacks en board har) kan ligga som Windmill-flöden (Nextcloud Flow = Windmill, ExApp/Docker). Kärnorkestreringen
(A–D) bör vara hårdkodad i sdkmc för transaktionsgaranti och sekretess.

---

## 4. Deck-kopplingen specifikt (det entydiga svaret)

**Modell: board per enhet/team — kort per ärende. INTE board per ärende.**

Motivering, entydigt:
- En **board per ärende** ger hundratals boards per enhet, ingen plockbar delad kö, och bryter Decks delnings-/ACL-modell
  (board delas till team/Circle). Skalar inte, saknar enhetsöverblick.
- En **board per enhet** ger exakt vad socialtjänstprocessen behöver: mottagnings-/utredningsgruppens **delade, plockbara
  kö**, stacks som processteg (Förhandsbedömning · Utredning · Beslut · Uppföljning), och ett kort = ett ärende som flyttas
  mellan stacks när steppern flyttas.

**Kort↔ärende-kopplingen:**
- Deck-kortets `title` = `{barnRef} · {dnr|triageRef}` (aldrig klartext-PII, GDPR).
- Kortet bär en **label** `case:{hubsCaseId}` (Deck-labels är board-scoped → en `case:`-label per kort; alternativt
  läggs `hubsCaseId` i en dold rad i `description`). Detta är Decks motsvarighet till en tagg, eftersom Deck-kort
  **inte** är fil-taggbara med systemtags.
- sdkmc lagrar `{deckBoardId, deckCardId}` på ärenderaden → **O(1)-uppslag** från `hubsCaseId` till kortet och tillbaka.
  Det är denna tvåvägs-pekare som binder dashboardens `ArendeKort.vue` till Deck-kortet
  (klick "Skapa bevakning"/"Bevakningar"-fliken → `/apps/deck/board/{deckBoardId}/card/{deckCardId}`).
- **Skapande är programmatiskt** (2-stegs-API: POST kort → PUT label/due), inte Flow.
- **Assignee:** Deck bär kortets tilldelning native (`assigned:anna`-sök) — det är den maskinläsbara "ärende → utredare"
  som driver "Mina ärenden"-filtret, T-7/T-3/T-0-påminnelser (bara till tilldelad) och chefens arbetsbörde-räkning (ark-3 §4.1).
- **Frister:** kortets due speglas ur Treserva (Frends), CalDAV-speglas till kalendern.

> **Stacks ⇄ stepper:** när `ArendeKort`s `ProcessStepper` flyttas (efter verifierad commit) flyttar orkestreringen
> Deck-kortet till motsvarande stack. Ett kort kan inte tyst avancera om plikt (skyddsbedömning) är okvitterad — fas-spärren
> gäller även Deck-flytten.

---

## 5. De fyra övriga frågorna (kort syntes — UI i systerdokumentet)

### 5.1 Multi-korg & sortering (ark-1)

En **korg** är en behörighetsstyrd ström av inkommande sdkmc-objekt (personlig brevlåda, gruppkorg/funktionsadress,
fax, SDK), inte en mapp. En socialsekreterare ser **flera korgar samtidigt**. Triagen klassar varje rad längs **tre
ortogonala axlar**, och **Axel C avgör zon**:

- **Axel A — Korg** (härkomst/behörighet): filter + etikett, **aldrig** primär sortering (annars 13 inkorgar). Korg-väljaren
  visar bara korgar handläggaren har OSL-behörighet till (`IConditionalWidget` = OSL-gräns, server-filtrerat).
- **Axel B — Informationstyp** (8 typer: orosanmälan, komplettering, fråga, remiss, internpost, fax, SDK-myndighet, skräp):
  styr radens **åtgärdsknappar** och batch-gruppering.
- **Axel C — Ärendekoppling** (`nytt-ärende | hör-till-ärende | ej-kopplat`): routar till zon.
  - `hör-till-ärende` → **"Att hantera (mina korgar)"** (ärende*arbete* — kompletteringar, svar, remissvar).
  - `nytt-ärende` + `ej-kopplat` → **"Att ta emot"** (ärende*beslut*).

Matchningskonfidens (GAP-041): hög (ConversationId/dnr-träff) → auto-koppling med förkryssat ärendechip; låg → "föreslås
koppla, bekräfta?"; ingen → nytt/ej-kopplat. **System-stödd men människo-bekräftad** — aldrig en gissning som tyst flyttar
sekretess till fel akt. Frist är inte en fjärde axel utan **prioritet inom zon** (röd→gul→grå, härledd ur inkom-datum).

### 5.2 Chatt (Talk) + Circles/Teams (ark-2)

Tre lager: **(a) ärende-chatt** (en `spreed`-tråd per ärende, deltagare = ärenderummets ACL, namn = ärendereferens) —
syns som **flik "Diskussion"** i `ArendeKort`s Quick View bredvid "Säkra meddelanden"; **(b) team-/enhetschatt** (Circles-team
→ konversation: mottagningsgruppen, utredningsgrupp, enheten) — en **lugn "Enhetschatt ▸"-sidoyta** i foten, *aldrig* ett kort
i ärendeströmmen; **(c) 1:1 + omnämnanden + närvaro**.

Tre regler så chatten inte blir en andra inkorg: **(1)** omnämnanden — inte olästa — är valutan (Dagspulsen får **en**
räknare `💬 omnämnanden`, räknar mentions/1:1, inte rå olästa); **(2)** ärende-chatt syns *på ärendet* (`DiskussionChip`
💬 n / 💬@1), och bara ett *omnämnande till mig* kan höja ett kort till "Kräver åtgärd nu"; **(3)** team-chatt är pull, inte push.

**Sekretess:** intern ärende-chatt = **arbetsmaterial** (gallras med ärenderummet); *extern* samverkanstråd = sannolikt
allmän handling (märks separat). Det som *är* en handling (beslut/bedömning) **committas ur chatten** via
**"Gör detta till en handling" → mall-utkast → human-in-the-loop → `CommitGrind`** — chatten lagrar aldrig ensam en allmän
handling. Talk saknar native objektbindning till dnr → kopplingen bärs av registrets `talkToken`-pekare (samma mönster som allt annat).

### 5.3 Mottagning → tilldelning (chef) (ark-3)

Två tilldelnings-*lägen*, en datamodell:
- **(a) Plock** — mottagningens symmetriska "jag tar nästa orosanmälan" (steg 3–8, förhandsbedömning).
- **(b) Fördelning** — gruppledarens/1:e socialsekreterarens asymmetriska "jag delar ut till NN" vid beslut "inleda" (steg 8→9).

Mellan beslut-inleda och fördelad-till-NN är ärendet **`otilldelat`** — en egen, behörighetsstyrd kö som bara fördelaren
(+ read: gruppen) ser. **Fördelningen orkestrerar fyra saker atomärt** (samma kärna som §3.2-B): sätt `assignee` (Deck) →
skapa/återanvänd ärenderum → **skriv om ACL till least-permission** (mottagning revoke, utredare write) → skapa/flytta Deck-kort →
logga (`activity`). ACL-omskrivningen är *både* arbetsfördelning *och* sekretessåtgärd (OSL 26 kap., GAP-051).

Chefen får ett **eget lättviktigt läge — fördelningsvyn** (inte en ny app): Zon A "Att fördela" (inledda utan utredare,
kan ej tystna — compliance-KPI), Zon B "Utredarnas belastning" (**tal + frist-färg, aldrig innehåll** — chefen har inte
automatiskt sekretess-åtkomst till varje barn), Zon C "Mottagningens pågående" (read-only). **Fristen flyttas inte vid
fördelning** — 4-mån-klockan startar vid beslut "inleda", inte vid fördelning. Utredaren ser ärendet först när det är tilldelat
*henne*, med "NY — tilldelad dig av {chef}"-markör och ett provenans-band som bär hela kedjan mottagning→chef→utredare→Treserva.

### 5.4 Ej-ärendekopplade meddelanden (ark-4)

Fyra fall: **(a)** hör till befintligt ärende men ej kopplat (vanligast) → **Koppla**; **(b)** ska bli nytt → **Skapa**;
**(c)** ska aldrig bli ärende → **Gallra/Registrera/Vidarebefordra**; **(d)** ska besvaras utan ärende → **Besvara**.

En uttrycklig **"Ej ärendekopplat"-hink** (eget band i "Att ta emot", aggregerad över alla korgar) med en **röd-när-gammal
räknare** som compliance-KPI ("7 — 2 äldre än 3 dagar"). Sex åtgärder per rad: Koppla · Skapa nytt · Besvara · Vidarebefordra ·
Gallra · Registrera. **Den juridiska kärnan:** registreringsplikten (OSL 5:1) gäller **handlingen, inte ärendet**, och eftersom
socialtjänstens handlingar normalt är sekretessbelagda (OSL 26 kap.) är "hålla ordnad utan registrering" oftast **stängd** —
därför finns **"Registrera utan ärende"** som egen väg. **"Gallra" är aldrig ett naket klick** — den öppnar en gallringsgrind
(handlingstyp ur DHP → visat gallringsbeslut → bekräfta); saknas grund byts knappen mot "Registrera". `files_retention` gallrar
**aldrig** en ej-kopplat-rad på tid. Auto-förslag (dnr i ämne > avsändar-match > ConversationId > avstängbar `llm2`) **föreslår**
default-åtgärd; bilagan speglas **vid bekräftelse**, inte vid förslag (annars felkopplad sekretess i fel rum, GAP-043).

---

## 6. Sekvensdiagram (text) — inkommande meddelande → ärende-tagg propageras till alla appar

```
EXTERN PART                sdkmc / FLOW                      ÄRENDEREGISTER (tables)         APPAR (case:{hubsCaseId})
(skola/polis/region)       (orkestrering)                    hubs_arenden                    files·deck·spreed·calendar·libresign
     │                          │                                  │                                  │
 1.  │  meddelande IN  ────────►│  MessageReceivedEvent            │                                  │
     │  (SDK/AS4, fax,          │  fånga provenans:                │                                  │
     │   securemail)            │  kanal·avsändar-LOA·tid·korg     │                                  │
     │                          │  + conversationId                │                                  │
     │                          │                                  │                                  │
 2.  │                          │  KLASSA (Axel A/B/C):            │                                  │
     │                          │  korg + messageType + match      │                                  │
     │                          │  conversationId/dnr ─────────────►  slå upp i conversationIds[]    │
     │                          │                                  │                                  │
     │              ┌───────────┴───────────┐                      │                                  │
     │              ▼                       ▼                      │                                  │
 3.  │   (A) HÖR-TILL-ÄRENDE        (B) EJ MATCHAD                 │                                  │
     │   hög konfidens             → "Att ta emot" /              │                                  │
     │       │                       "Ej ärendekopplat"           │                                  │
     │       │                       (otaggat — legitimt)         │                                  │
     │       │                            │                       │                                  │
     │       │                       MÄNNISKA TRIAGERAR:          │                                  │
     │       │                       Koppla │ Skapa │ Gallra/Reg  │                                  │
     │       │                            │                       │                                  │
 4.  │       │           ┌────────────────┴─────────┐             │                                  │
     │       │           ▼                          ▼             │                                  │
     │       │   "Skapa ärende" (1 anrop)    "Koppla till X"      │                                  │
     │       │     INSERT rad → hubsCaseId ───────────────────────► ny/befintlig rad                │
     │       │     systemtags: case:{id} ─────────────────────────► caseTagId                       │
     │       │                                                    │                                  │
 5.  │       │     ORKESTRERING (programmatiskt, atomärt):        │                                  │
     │       │       groupfolder + ACL + BBIC ────────────────────► groupfolderId ────────────────► [files] mapp skapad
     │       │         + Automated Tagging-regel mapp→case  ──────┼──────────────── FLOW taggar ───► [files] filer case:{id}
     │       │       deck: POST kort → PUT label case:{id} ───────► {deckBoardId,deckCardId} ──────► [deck] kort=ärende, label
     │       │       spreed: POST rum (deltagare=ACL) ────────────► talkToken ────────────────────► [spreed] ärende-chatt
     │       │       calendar: CATEGORIES=hubsCaseId ──────────────► calendarObjUri ───────────────► [calendar] möten
     │       │       itsl-tag meddelandet case:{id} ──────────────► append conversationId ─────────► [sdkmc] meddelande taggat
     │       │       14-dgr-klocka ← inkom-datum (conversationId)  │                                  │
     │       │                                                    │                                  │
 6.  │       │   (CHEF, vid beslut "inleda") FÖRDELNING:          │                                  │
     │       │     status: otilldelat → tilldelat NN ─────────────► agareUid = NN                   │
     │       │     ACL omskrivs least-permission ─────────────────┼──────────────────────────────► [files] mottagning revoke, NN write
     │       │     assignee → Deck-kort ──────────────────────────┼──────────────────────────────► [deck] assigned:NN
     │       │                                                    │                                  │
 7.  │       └──────────────► (libresign vid "Skicka för underskrift")                              │
     │                            case:{id} på dokument + metadata ┼──────────────────────────────► [libresign] begäran taggad
     │                                                            │                                  │
 8.  │  ◄── COMMIT (Frends) ──── CommitGrind "För över" ──────────► provenanceState=registrerad      │
     │      callback {hubsCaseId, dnr}                             │  dnr satt, systemtag + dnr-alias│
     │                          FLOW: state:committed → retention  │  retentionState=gallras_efter_  ► [files] retention:hubs-30d
     │                          FLOW→Talk: posta "committad, dnr X"┼──────────────────────────────► [spreed] systemkvitto i tråd
     ▼                                                            ▼                                  ▼
  Treserva (system of record)                          "Öppna ärende" = EN aggregering: alla objekt med case:{hubsCaseId}
```

**Läsanvisning:** Steg 1–3 = *inflöde + klassning* (programmatisk matchning; otaggat lämnas legitimt i "Att ta emot"/"Ej
ärendekopplat"). Steg 4–5 = *koppling/skapande* (registret föds, alla appar stämplas med `case:{hubsCaseId}` — Flow taggar
filerna, programmatik resten). Steg 6 = *chefens tilldelning* (ACL→least-permission). Steg 8 = *commit* (dnr paras, retention
bunden till verifierad callback). **Ärende-taggen är den enda joinnyckeln** — varje fliks innehåll i "Öppna ärende" är "alla
objekt med `case:{hubsCaseId}` i app N", och registrets direktpekare gör de tunga uppslagen O(1).

---

## 7. Konsekvenser, ärlighet, gap

- **Löser direkt:** GAP-005 (kanonisk token + 1:n-syskon), GAP-010 (ett-klicks-orkestrering), GAP-041 (ConversationId↔ärende),
  GAP-002 (frist ur inkom-datum), GAP-049 (tom "ej registrerad"-kö = compliance-KPI), och ger infrastrukturen för GAP-007/019
  (commit-bunden gallring).
- **Kräver fork-jobb (ärligt):** Talk-rummets *inbäddade* objektbindning (`objectType='hubs_case'`) är en `spreed-itsl`-utökning;
  tills dess bär registrets `talkToken`-pekare kopplingen. Deck-kort kan inte fil-taggas → `case:`-label + register-pekare är lösningen.
- **Sekretess-/ACL-gräns (GAP-051):** `case:`-systemtaggen, Groupfolder-ACL och Tables-vy-behörighet måste vara **tre koherenta
  lager** ACL:ade per enhet/funktionsadress, så att "öppna ärende" aldrig exponerar ett barn för fel handläggare.
- **GDPR-dataminimering:** taggvärdet är `hubsCaseId` (UUID/triage-ref), aldrig klartext-PII; korttitlar är pseudonymer (`barnRef`).
  Taggen läcker inget även om den syns brett.
- **Policy per kommun (ej kod):** gränsen arbetsmaterial vs allmän handling för chatt; vad som diarieförs; gallringsfrister i DHP —
  förankras med kommunjurist.

---

## Implementering (sammanfattning)

- **Appar:** `tables` (ärenderegistret `hubs_arenden`, navet) · `groupfolders`+`files`+`files_versions` (ärenderum) ·
  `systemtags`/Files Automated Tagging (filtaggning) · `deck` (board per enhet, kort per ärende, assignee) · `spreed-itsl`
  (ärende-/team-/1:1-chatt + ev. fork-objektbindning) · `circles` (team = en medlemskapssanning) · `calendar` (möten med
  `hubsCaseId` i CATEGORIES) · `libresign`/Inera (signering taggad) · `workflowengine` (Flow-regler) · `files_retention`
  (commit-bunden gallring) · `activity` (spårbarhet) · sdkmc/mail/securemail (meddelandetaggning via itsl-tag-API). Slutlagring:
  Treserva via **Frends**.
- **I Flow (deklarativt):** (1) Automated Tagging mapp→`case:{id}`; (2) `state:committed`→restricted `retention:hubs-30d`;
  (3) File Access Control på `sekretess:hög`; (4) notifiering vid filhändelse; (5) Flow→Talk-åtgärd posta systemmeddelande;
  (6) custom `IEntity` (`MessageReceivedEvent`/`CaseCommittedEvent`) som triggers. Per-kund-logik i **Windmill ExApp**.
- **Programmatiskt (sdkmc + Frends):** ärenderegister-skrivning (ensam skrivare) · UUID-generering · **matchningsmotorn** (A) ·
  **ett-klicks "skapa ärende"** (B) · fil-/bilageroutning (C) · **Frends-commit + dnr/frist-spegling + provenans-flip + commit-bunden
  retention** (D) · **fördelnings-/omfördelnings-orkestrering** (assignee + ACL-omskrivning, ark-3) · **gallringsgrinden** (ark-4) ·
  Deck-kort-skapande (2-stegs) · Talk-rum-skapande + `talkToken`-pekare. Nya OCS-routes: `/api/v1/arende-summary`,
  `/api/v1/arende/{hubsCaseId|dnr}`, `/api/v1/treserva/commit`, `/api/v1/fordelning-summary`, `/api/v1/arende/{ref}/tilldela`,
  `/api/v1/inflode-summary`, `/api/v1/ej-kopplat`.

---

*Grundas i `analysis-output/extended/ark-1…ark-5`, `UX-REDESIGN-SOCIALSEKRETERARE.md`, `SOCIALSEKRETERARE-WALKTHROUGH.md`,
`GAP-ANALYSIS.md`. UI-specen finns i `UI-EVOLUTION-SOCIALSEKRETERARE.md`. Varumärkesregel: aldrig "Nextcloud"/"Talk"/"Circles" i UI-text.*
