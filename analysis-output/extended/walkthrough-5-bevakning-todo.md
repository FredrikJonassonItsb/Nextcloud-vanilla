<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Walkthrough 5 — Bevakning & todo: från meddelande till frist (och varför den formella bevakningen ändå bor i Treserva)

> **Persona:** `socialsekreterare` (barn & familj). **Datum:** 2026-06-14. **Plattform:** server v32 (Hub 25 Autumn).
> **System of record (slutlagring):** Treserva (CGI) / Lifecare (Tietoevry) / Viva / Combine (Pulsen) — socialakten/BBIC-journalen, som har **inbyggd bevakningsfunktion** (registrerad bevakning visas på handläggarens skrivbord, texten blir **röd när bevakningsdatumet passerats**, man kan ange **antal dagar före förfallodatum** som varningen ska visas, och andra kan lägga bevakning i ens ärenden).
> **Det som dokumenteras här:** ett *enda* flöde — hur Anna skapar en bevakning/uppgift från ett inkommande meddelande, hur Hubs lägger påminnelser **T-7/T-3/T-0 bara till tilldelad**, hur de lagstadgade fristerna (förhandsbedömning 14 dgr, utredning 4 mån, uppföljning av tidsbegränsat beslut, FL 6-mån) modelleras, hur "Att göra (socialtjänst)"-listan ser ut, och **var gränsen går mellan Hubs personliga/delade arbetslista (mellanlagring) och Treservas formella, arkivpliktiga fristbevakning (slutlagring)**.
> **Brand-regel:** i UI säger vi aldrig "Nextcloud"/"Talk". Här namnger vi app-id (deck, tasks, sdkmc, groupfolders, tables, forms, libresign) för spårbarhet.
> **Handoff-mönster:** **A** = API/REST · **B** = drag-to-case/registrera i diariet · **C** = FGS-export · **D** = manuell ("Markera som överförd"). Detta flöde domineras av **D** (todo gallras eller länkas) och bygger på **B/A** för det registrerade ärendet.

**Den bärande tesen för hela flödet:** *Hubs-todon fångar det inkommande **innan** det blir ett ärende, och bevakar det säkra flödet runt omkring. Den formella fristen, den arkivpliktiga aktiviteten och journalanteckningen committas i Treserva/Lifecare — som redan rödmarkerar passerade bevakningar. Hubs dubblerar inte facksystemets fristbevakning; Hubs stänger gapet inkorg↔facksystem.*

---

## Steg 1 — Inkommande meddelande landar i triagekön

**Handläggaren:** Anna loggar in (Freja eID Plus, LOA3) och öppnar **Att hantera** (`attHantera`) på Hubs Start. Bland raderna ligger en komplettering från en skola i ett pågående utredningsärende (Barn 2026-0412): "Bifogar efterfrågad pedagogisk kartläggning." Kanalikon = SDK, avsändare SITHS-verifierad, inkom 08:14. Raden bär en destinations-chip "→ Treserva — utredning pågår".

**I Hubs (mellanlagring):** Inget skapas än. `attHantera` är en aggregerad, server-side-klassad triagevy över sdkmc/securemail/mail-fax via `/ocs/v2.php/apps/sdkmc/api/v1/summary`. Meddelandet + bilaga ligger i sdkmc-lagret; en kopia av bilagan kan redan ha speglats till ärenderummet (Groupfolder) om auto-routing är på (se Steg 3-not). Status på raden: *oläst → läst*.

**I facksystemet (slutlagring):** Inget ännu — committas i Steg 6–7. Meddelandet är en allmän handling som ska registreras/journalföras i Treserva-akten, men det steget är en *annan* handoff (mönster B/A) än bevakningen detta flöde handlar om.

**Data:** Riktning IN (extern → Hubs). Innehåll: säkert meddelande + PDF-bilaga, sekretess enl. OSL 26 kap. (socialtjänstsekretess), avsändar-LOA = SITHS/LOA3. Retention: sdkmc-meddelandet/-loggen hålls transient (SDK-logg 12 mån utan innehåll); bilagan gallras ur Hubs efter överföring till facksystemet.

**⚠ ANTAGANDE:** att `attHantera` korrekt kopplar raden till rätt dnr/barn-token kräver att summary-endpointen bär en ärendereferens (ConversationId↔dnr-mappning). Om meddelandet kom utan referens (ny avsändare, fel ämnesrad) måste Anna manuellt välja ärende i Steg 2 — annars blir bevakningen löst kopplad. **⚠ LUCKA:** mappningen ConversationId→dnr är inte specificerad i underlagen; den är förutsättning för att "kopplar dnr" i nästa steg ska vara sann och inte ett fritextfält.

---

## Steg 2 — "Skapa bevakning från meddelande" (signaturfunktionen)

**Handläggaren:** På meddelanderaden klickar Anna **Skapa bevakning** (primäråtgärd/`IButtonWidget`-action på raden). En Quick View öppnas med förifyllt: **titel** = "Följ upp komplettering — Barn 2026-0412" (avsändare + ämne), **länk** till meddelandet, **ärendereferens** = dnr/barn-token, och en **föreslagen frist**. Anna väljer hur bevakningen ska bo: **(a) personlig uppgift** (bara jag, arbets-/genomförandefokus) eller **(b) delad bevakning på ärendets board** (teamet/2:a-handläggaren ser den). Här väljer hon (a) — det är en personlig "renskriv kartläggningen och uppdatera utredningstexten"-uppgift.

**I Hubs (mellanlagring):**
- Val (a) **personlig uppgift** → skapas som **VTODO i Tasks** (`tasks`, app-id `tasks`) på Annas kalender via CalDAV: `/remote.php/dav/calendars/{anna}/{bevakningar}/`. Fält: SUMMARY (titel), DESCRIPTION (ärendereferens, **inte** klartextcitat — GDPR-dataminimering), DUE (frist), och **tre VALARM** (T-7/T-3/T-0, se Steg 4). Status: `NEEDS-ACTION`. Widget: `minaUppgifter`.
- Val (b) **delad bevakning** → skapas som **Deck-kort** (`deck`) på barnets/ärendets board: `POST /ocs/v2.php/apps/deck/api/v1.0/boards/{boardId}/stacks/{stackId}/cards` med `OCS-APIRequest: true`. Kortet får due date, tilldelning (bara Anna), kort↔kort-relation till ärendekortet, och en länk till sdkmc-meddelandet i beskrivningen. Widget: `bevakningar` (delad) / `todolista`.

I båda fallen: korttext = **ärendereferens**, default, inte känsligt klartextcitat om barnet.

**I facksystemet (slutlagring):** Inget ännu — committas i Steg 6 (om uppgiften blir en formell aktivitet) eller gallras i Steg 8 (om den är ren personlig arbetsnotering). Hubs-bevakningen är medvetet *transient*.

**Data:** Riktning internt i Hubs (härledd metadata). Sekretess: ärendereferens bär ingen ny känslig uppgift; länken pekar till sekretessbärande meddelande bakom åtkomstkontroll. Retention: gallras som personlig notering (Tasks) eller arkivmedvetet vid klarmarkering (Deck), se Steg 8.

**⚠ ANTAGANDE:** att Anna *väljer rätt* mellan personlig VTODO och delad Deck-board är en UX-/disciplinfråga. Underlagen säger "personlig → Tasks, delad → Deck", men en handläggare under tidspress kan lägga allt personligt och därmed göra teamet blint vid frånvaro. **Mitigering:** för **fristbärande** bevakningar (förhandsbedömning, utrednings-4-mån, uppföljning) bör Hubs *föreslå* delad board (så ingen faller mellan stolarna vid sjukdom), och bara renodlade arbets-steg ("renskriv text") föreslås som personliga. Detta förslag-default är inte explicit i widgetApps.js.

---

## Steg 3 — Bevakningen kopplas till ärenderummet (kontext, inte journal)

**Handläggaren:** Anna ser i bevakningens Quick View en genväg **Öppna ärenderum**. Klick → `/apps/files/?dir=/{arenderum-2026-0412}` (Groupfolder). Bilagan (pedagogisk kartläggning) ligger redan där om auto-routing speglat den; annars drar Anna in den.

**I Hubs (mellanlagring):** Ärenderummet (`arenderum`, app-id `groupfolders` + ACL + versioner + Retention) är en säker dokumentyta per barn/dnr. ACL: Anna skriver, gruppledaren läser. Bevakningskortet/-uppgiften **refererar** till rummet (deep-link), men bevakningen är **inte** en handling i rummet — den är arbetsmetadata. Bilagan, däremot, är en handling.

**I facksystemet (slutlagring):** Bilagan är en allmän handling som ska föras till **Treserva-akten** (mönster B/A) — men det är dokument-handoffen, inte bevaknings-handoffen. Committas separat (utanför detta flödes kärna; se walkthrough för orosanmälan/ärenderum).

**Data:** Riktning: bilaga IN (extern → ärenderum), bevakning = intern referens. Sekretess: rummets ACL är säkerhetsgräns (OSL). Retention: **dubbel countdown** — facksystemets bevarande (i Treserva/e-arkiv) + Hubs egen rensning ("rensas X dgr efter överföring").

**⚠ LUCKA:** om bilagan auto-routas till ärenderummet *och* ligger kvar i sdkmc *och* förs till Treserva finns den i tre lager samtidigt under en period (dubbel-/trippellagrad sekretess). Underlagen löser detta principiellt med Retention-rensning "efter bekräftad överföring", men **tidpunkten för "bekräftad överföring"** är odefinierad när handoffen är manuell (mönster D) — Retention vet inte automatiskt att handläggaren registrerat i Treserva. Se Steg 8 ⚠.

---

## Steg 4 — Hubs lägger påminnelser T-7/T-3/T-0 (bara till tilldelad)

**Handläggaren:** Inget aktivt — detta är systemets jobb. Anna har en frist (säg DUE = 2026-06-30 för en uppföljning, se Steg 5). Hon vill bli puffad *före* fristen, inte bara se en röd siffra på dagen.

**I Hubs (mellanlagring):**
- **Tasks/VTODO-vägen:** tre **VALARM** sätts relativt DUE: `TRIGGER:-P7D`, `-P3D`, `-PT0S` (T-0). Tasks/Calendar levererar native push/e-post till **uppgiftens ägare** = Anna. Detta är inbyggt i CalDAV-kärnan.
- **Deck-vägen:** Deck-kärnan har **ingen** separat "påminnelse före due date" (öppet uppströms-ärende #1549) och har historiskt skickat aviseringar till **alla** board-medlemmar, inte bara tilldelad (#566). **Därför bygger Hubs egen påminnelselogik ovanpå Deck:** en bakgrundsjobb läser korts due dates, beräknar T-7/T-3/T-0 och skickar notis **bara till `assignedUsers`** (täcker #1549 + #566). WCAG: omordning/klarmarkering har knapp-/tangentbordsalternativ (2.5.7, 2.5.8 target size 24×24).

**I facksystemet (slutlagring):** Inget. Treserva har sin **egen** påminnelse (antal dagar före bevakningsdatum, röd vid passerat). **Hubs påminnelser dubblerar inte denna** — Hubs påminner om det *ännu icke registrerade* arbetet runt inflödet; Treserva påminner om den *formellt registrerade* bevakningen efter Steg 6.

**Data:** Riktning: intern notis till tilldelad. Sekretess: notistext = ärendereferens + frist, ingen känslig uppgift (en push till mobil får aldrig läcka barnets situation). Retention: notiser är transienta.

**⚠ LUCKA (central för flödet):** **dubbel bevakning under övergångsfönstret.** Mellan Steg 2 (Hubs-bevakning skapad) och Steg 6 (formell bevakning registrerad i Treserva) finns fristen bevakad **bara i Hubs**. Efter Steg 6 finns den i **både** Hubs *och* Treserva tills Hubs-uppgiften klarmarkeras/gallras (Steg 8). I det fönstret riskerar handläggaren två konkurrerande påminnelser med olika datum om de inte hålls i synk. **Underlagen säger uttryckligen "Hubs dubblerar INTE facksystemets fristbevakning" — men mekanismen som river Hubs-påminnelsen när Treserva tagit över är inte specificerad.** Föreslagen regel: när Hubs-uppgiften markeras "förd till ärendet/facksystemet" (Steg 8) ska dess kvarvarande VALARM/Deck-påminnelser **avaktiveras**, så Treserva blir ensam fristägare. Detta måste byggas explicit.

**⚠ ANTAGANDE:** att Hubs egen Deck-påminnelse-motor (bakgrundsjobb) finns och är driftsatt. Den är "proposed" logik ovanpå native Deck, inte en färdig NC-funktion — utan den faller Deck-vägen tillbaka på Decks bristfälliga avisering. Tasks-vägen (VALARM) fungerar native idag.

---

## Steg 5 — Fristerna modelleras: vilken klocka är det egentligen?

**Handläggaren:** När Anna skapar/granskar en bevakning väljer (eller bekräftar) hon **fristtyp**. Hubs föreslår frist utifrån ärendekontext. De fyra lagstadgade klockorna detta flöde måste bära:

1. **Förhandsbedömning — 14 dagar** från inkommen orosanmälan till beslut inleda/inte inleda utredning. (Startar när anmälan plockas; egen walkthrough äger den, men den syns här i `bevakningar`.)
2. **Utredning — 4 månader** (11 kap. 2 § SoL 2025:400), skyndsamt, förlängning bara vid särskilda skäl.
3. **Uppföljning av tidsbegränsat beslut** — nytt beslut **"i god tid innan beslutet upphör"** (Socialstyrelsen/Kunskapsguiden; SoL 2025:400 skärper uppföljning). Insatsen får **inte** fortgå utan giltigt beslut. För Barn 2026-0412 i exemplet: ett tidsbegränsat insatsbeslut upphör 2026-06-30 → bevakning på t.ex. T-21 så nytt beslut hinner fattas.
4. **Förvaltningslagen 11–12 §§ (FL 2017:900)** — efter **6 månaders** handläggning kan parten begära att ärendet avgörs; myndigheten har då **4 veckor** att avgöra eller avslå med överklagbart beslut.

**I Hubs (mellanlagring):** Fristtyp + DUE lagras på VTODO/Deck-kortet och kan speglas i ett **Tables-register** (`orosanmalningar`/status-fält) för aggregerad "frister denna vecka"-strip. Eskaleringsfärg grå→gul→röd härleds ur (DUE − idag). `fristStrip`/`bevakningar` renderar.

**I facksystemet (slutlagring):** Den **rättsligt bindande** fristen ägs av Treserva: 4-mån-utredningsfristen och uppföljningsbevakningen registreras som Treserva-bevakning när ärendet/beslutet finns där. Treserva rödmarkerar vid passerat datum oberoende av Hubs.

**Data:** Riktning: frist härledd ur lagkrav + ärendedata (ingen extern data). Sekretess: fristtyp i sig är inte känslig; kopplingen till barn är det. Retention: frist-metadata gallras med uppgiften.

**⚠ LUCKA:** Hubs kan bara *härleda* fristens startdatum korrekt om det vet rätt utgångspunkt. Förhandsbedömningens 14 dgr börjar vid **inkommen anmälan** (inte vid plock — JO-praxis: fristen löper från det anmälan kom in till myndigheten, även om den låg otilldelad i funktionsbrevlådan). **⚠ ANTAGANDE:** att Hubs sätter 14-dgr-klockans start till sdkmc-inkomsttidsstämpeln, inte till plock-tidpunkten. Om Hubs (fel) startar vid plock blir fristen för generös och rättsosäker. Underlagen säger "i samma sekund den blir hennes startar countdown" (plock) i prosan men "fristen löper från inkommen" i juridiken — **detta är en motstridighet som måste lösas i datamodellen** (startdatum = inkomstdatum; plock påverkar bara *tilldelning*, inte fristen).

**⚠ LUCKA:** 4-månadersfristen kan **förlängas** vid särskilda skäl. Hubs-bevakningen måste kunna bära ett förlängningsbeslut (nytt DUE + motivering) — men förlängningsbeslutet är en formell handling som hör hemma i Treserva. Hur Hubs-fristen uppdateras när Treserva-fristen förlängs (mönster A läs-synk? manuell?) är ospecificerat. Risk: Hubs visar röd frist medan Treserva har en giltig förlängning → falsk-röd.

---

## Steg 6 — Bevakningen blir en formell aktivitet → registreras i Treserva

**Handläggaren:** När uppgiften går från "min personliga arbetslapp" till en **formell handläggningsåtgärd/aktivitet** (t.ex. beslut om uppföljning, en journalförd aktivitet, en formell fristbevakning som ska överleva Annas semester och tillsyn), **för Anna över** den till facksystemet. Hon öppnar Treserva (parallellt fönster / deep-link) och **registrerar bevakningen där**: bevakningsdatum, ansvarig, antal dagar före som varningen ska visas.

**I Hubs (mellanlagring):** Inget nytt skapas; Hubs-uppgiften får status "förd till ärendet/facksystemet" och en provenance-uppdatering: *"Bevakning registrerad i Treserva, dnr 2026-IFO-… · Hubs-uppgift länkad"*. Om mönster A finns kan en tunn konnektor POST:a bevakningen till Treservas API; annars är det manuellt (mönster D) med "Markera som överförd".

**I facksystemet (slutlagring):** Treserva skapar den **arkivpliktiga, formella bevakningen**: visas på handläggarens Treserva-skrivbord, blir **röd vid passerat datum**, kan ärvas av kollega, och varnar X dagar före. Detta är nu **fristens system of record**. Eventuell journalanteckning (dokumentationsskyldighet, skärpt i SoL 2025:400) committas också här.

**Data:** Riktning UT (Hubs → Treserva). Innehåll: bevakningsmetadata (datum, typ, ansvarig); ev. journaltext förs separat. Sekretess: överföring sker org-internt (ingen extern part). Retention: i Treserva enligt kommunens dokumenthanteringsplan; Hubs-kopian mot gallring (Steg 8).

**⚠ LUCKA:** **mönster A mot Treserva för bevakningar är inte verifierat.** Underlagen säger Treserva har "öppna API:er" och nämner registrering av *aktualisering/beslut* (mönster B/A), men inte att man kan **skapa en bevakningspost** via API. Sannolik dag-1-verklighet: **mönster D** (handläggaren skriver in bevakningen manuellt i Treserva, Hubs loggar att det skett). Att utlova auto-sync av bevakningar Hubs→Treserva är ett **antagande som inte bör säljas innan API-kapabiliteten är bekräftad per kund**.

**⚠ ANTAGANDE:** att handläggaren faktiskt gör Steg 6. Inget i Hubs *tvingar* fram registreringen — om Anna nöjer sig med Hubs-bevakningen och hoppar Treserva, lever fristen i ett icke-arkivpliktigt system (rättssäkerhets-/arkivrisk). Mitigering: provenance-chip "→ Treserva — ej registrerad" som **öppen åtgärd** (som en frist), och tom "ej registrerad"-kö som compliance-KPI.

---

## Steg 7 — "Att göra (socialtjänst)"-listan: dagens konkreta steg

**Handläggaren:** Anna öppnar **Mina uppgifter** (`minaUppgifter`) och **Mina bevakningar & frister** (`bevakningar`). GOV.UK task-list-mönster, verb-inledda titlar, minimal statusmodell (`Ny · Påbörjad · Väntar på motpart · Klar` + rött `Åtgärd krävs`). Hon ser: "Renskriv pedagogisk kartläggning — Barn 2026-0412" (idag), "Skriv utredningsbedömning — Barn Z" (4-mån gul, 12 dgr kvar), "Följ upp — insats upphör 30/6 — Barn 2026-0412" (T-21). Toggle **Mina / Enhetens**.

**I Hubs (mellanlagring):** `minaUppgifter` renderar Tasks/VTODO (personliga); `bevakningar`/`todolista` renderar Deck-board (delade, ärendekopplade). Aggregerad "frister denna vecka"-strip kan matas av Tables-spegling. En valfri **lokal AI** (`llm2`, grön-ratad, avstängbar) kan *föreslå* ordning med synligt "varför" (frist + okänd avsändare), men prioriterar **ärendeegenskaper** (frist/sekretess/oläst), aldrig användarbeteende (GDPR art. 22), och skriver aldrig till facksystemet.

**I facksystemet (slutlagring):** Inget — listan är ren mellanlagring/arbetsvy. Genomförandet av varje steg dokumenteras i Treserva när det blir en handling.

**Data:** Riktning: läs/render av Hubs-egen data. Sekretess: listtitlar = ärendereferens. Retention: uppgifter gallras som personliga noteringar; delade bevakningar arkivmedvetet (Steg 8).

**⚠ ANTAGANDE:** att "Enhetens"-toggle inte bryter OSL. En handläggare får inte se rubriker/avsändare/antal från ärenden hen saknar behörighet till — `IConditionalWidget` är här en **åtkomstgräns**, inte bara ett filter. Underlagen är tydliga med detta principiellt, men "Enhetens bevakningar" förutsätter att board-/ärende-ACL är korrekt satt per barn så att vyn inte exponerar sekretess till fel handläggare. **⚠ LUCKA:** ACL-granulariteten "vem ser vems bevakning på enhetsnivå" är inte beskriven i detalj.

---

## Steg 8 — Stäng loopen: klarmarkera → gallra (personlig) eller för till ärendet

**Handläggaren:** Anna har renskrivit kartläggningen och uppdaterat utredningstexten. Hon klarmarkerar uppgiften. Hubs frågar: **"Gallra (personlig notering)"** eller **"För till ärendet/facksystemet"**. För den rena arbets-lappen ("renskriv text") väljer hon **gallra**. För en bevakning som blev en formell aktivitet väljer hon **för till ärendet** (vilket bekräftar Steg 6-överföringen).

**I Hubs (mellanlagring):**
- **Gallra** → VTODO/Deck-kort tas bort/arkiveras som personlig notering; ingen allmän handling skapas. Status `COMPLETED` → rensas.
- **För till ärendet** → uppgiften markeras länkad till Treserva-aktiviteten; provenance: *"Förd till Treserva 2026-06-14"*. Ev. kvarvarande Hubs-påminnelser **avaktiveras** så Treserva blir ensam fristägare (jfr Steg 4 ⚠).

**I facksystemet (slutlagring):** Vid "för till ärendet" är den formella aktiviteten/bevakningen + ev. journalanteckning redan committad (Steg 6). Vid "gallra" händer inget i facksystemet — och det är **rätt**: en personlig att-göra-lapp är inte en allmän handling och ska inte arkiveras (arkivlagen 1990:782 — skilj gallringsbar personlig notering från arkivpliktig allmän handling).

**Data:** Riktning: avslut internt. Sekretess/arkiv: valet "gallra vs för till ärendet" är **den juridiskt känsligaste interaktionen i hela flödet** — felaktig hopblandning skapar arkiv- och offentlighetsproblem (en kommunjurists första fråga). Retention: gallrad uppgift försvinner; länkad uppgift gallras ur Hubs efter bekräftad facksystem-överföring.

**⚠ LUCKA (den största):** **vad är "bekräftad överföring"?** Vid mönster D (manuell) klickar handläggaren "Markera som överförd", men inget verifierar att registreringen i Treserva *faktiskt* skedde. Retention-rensningen av Hubs-kopian hänger på ett mänskligt påstående. Om handläggaren klickar "överförd" utan att ha registrerat → Hubs gallrar sin kopia → handlingen/fristen finns ingenstans (worst case för rättssäkerhet och offentlighet). **Mönster A (API-bekräftelse från Treserva) skulle lösa detta men är inte verifierat tillgängligt.** Tills dess bör Hubs-rensningen ha en **säkerhetsmarginal** (gallra inte omedelbart vid "markera överförd"; vänta X dgr / kräv andra-handläggares bekräftan för fristbärande poster).

**⚠ ANTAGANDE:** att handläggaren förstår skillnaden "gallra vs för till ärendet" vid varje klarmarkering. Detta är en utbildnings-/UX-fråga; default-valet och formuleringen avgör om gränsen mellan personlig notering och allmän handling hålls. Fel default = systematiskt arkivfel.

---

## Systemöversikt för detta flöde

| Steg | Hubs-app (mellanlagring) | Facksystem (slutlagring) | Handoff |
|---|---|---|---|
| 1 — Inkommande i triage | `attHantera` (sdkmc/securemail/mail, summary-endpoint) | Treserva (ej registrerat ännu) | — (inflöde) |
| 2 — Skapa bevakning från meddelande | `tasks` (VTODO) **eller** `deck` (delad board) / `todolista` | Inget ännu — committas i Steg 6 | — |
| 3 — Koppla till ärenderum | `arenderum` (groupfolders + ACL + Retention) | Bilaga → Treserva-akten (separat dok-handoff) | B/A (dokument) |
| 4 — Påminnelser T-7/T-3/T-0 | `bevakningar`/`minaUppgifter` (Tasks VALARM; Hubs-logik ovanpå Deck #1549/#566) | Treserva har egen bevakningspåminnelse (röd vid passerat) | — |
| 5 — Modellera frister | `fristStrip`/`bevakningar` (+ Tables status) | Treserva äger rättsligt bindande frist | — |
| 6 — Formell aktivitet → registrera | provenance-uppdatering i `bevakningar` | **Treserva** skapar formell, arkivpliktig bevakning + journal | **D** (A om API) |
| 7 — "Att göra (socialtjänst)"-lista | `minaUppgifter` + `bevakningar`/`todolista` (GOV.UK task-list) | Inget — arbetsvy | — |
| 8 — Klarmarkera: gallra / för till ärendet | Tasks/Deck `COMPLETED`; Retention | Treserva (om "för till ärendet"); inget (om "gallra") | **D** |

**Modellmening:** *Hubs stagar arbetslistan runt det inkommande → den formella bevakningen/aktiviteten committas i Treserva/Lifecare (som rödmarkerar passerade bevakningar). Hubs dubblerar INTE facksystemets fristbevakning — den stänger gapet inkorg↔facksystem och gallras efter bekräftad överföring.*

---

## Identifierade luckor

1. **⚠ LUCKA — ConversationId→dnr-mappning (Steg 1–2).** "Kopplar dnr" förutsätter en mappning mellan sdkmc-meddelandets referens och ärendet i Treserva. Mappningsmekanismen är inte specificerad; utan den blir ärendekopplingen ett fritextfält.

2. **⚠ LUCKA — riv-mekanismen som hindrar dubbel bevakning (Steg 4 & 6).** Underlagen säger uttryckligen att Hubs inte ska dubblera Treservas fristbevakning, men **avaktiveringen av Hubs-påminnelsen när Treserva tagit över är inte byggd/specificerad**. Risk för två konkurrerande påminnelser med olika datum i övergångsfönstret.

3. **⚠ LUCKA — fristens startdatum (Steg 5).** Prosan startar 14-dgr-klockan vid *plock*; juridiken (JO-praxis) startar den vid *inkommen anmälan*. Motstridigt. Datamodellen måste sätta start = inkomstdatum, annars rättsosäker (för generös) frist.

4. **⚠ LUCKA — förlängd 4-månadersfrist (Steg 5).** Vid särskilda skäl förlängs utredningsfristen i Treserva. Hur Hubs-fristen synkas (eller inte) → risk för "falsk-röd" frist i Hubs medan Treserva har giltig förlängning.

5. **⚠ LUCKA — mönster A för bevakningar mot Treserva är overifierad (Steg 6).** Treservas öppna API är dokumenterat för aktualisering/beslut, **inte** för att skapa bevakningsposter. Dag-1-verklighet är mönster D (manuell). Auto-sync av bevakningar bör inte utlovas innan API-kapabilitet bekräftas per kund.

6. **⚠ LUCKA (störst) — "bekräftad överföring" är ett mänskligt påstående vid mönster D (Steg 8).** Retention-rensningen av Hubs-kopian hänger på att handläggaren klickat "markera överförd" — inget verifierar att Treserva-registreringen skedde. Worst case: klick utan registrering → Hubs gallrar → handling/frist finns ingenstans. Kräver säkerhetsmarginal eller API-bekräftelse.

7. **⚠ LUCKA — ACL-granularitet för "Enhetens bevakningar" (Steg 7).** "Vem ser vems bevakning" på enhetsnivå är en OSL-åtkomstgräns (`IConditionalWidget`), men granulariteten per barn/ärende är inte detaljbeskriven; risk att enhetsvyn exponerar sekretess till fel handläggare om board-ACL slarvas.

8. **⚠ LUCKA — Hubs Deck-påminnelse-motorn är "proposed", inte native (Steg 4).** T-7/T-3/T-0-logiken som täcker Deck #1549/#566 är egen kod ovanpå Deck och måste driftsättas; utan den faller Deck-vägen tillbaka på Decks bristfälliga avisering (till alla, ingen pre-deadline-puff). Tasks/VTODO-vägen fungerar native idag.

9. **⚠ ANTAGANDE — handläggar-disciplin på två ställen (Steg 2 & 8).** (a) Att välja delad board för fristbärande bevakningar (annars team-blindhet vid frånvaro). (b) Att förstå "gallra vs för till ärendet" vid varje klarmarkering (annars systematiskt arkivfel). Båda är UX-/default-/utbildningsberoende, inte tekniskt garanterade.
