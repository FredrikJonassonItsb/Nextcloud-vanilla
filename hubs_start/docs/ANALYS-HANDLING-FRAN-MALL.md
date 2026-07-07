---
titel: Analys — "Skapa handling från mall med ifylld ärendedata"
status: Analys / kravunderlag (ej implementerat)
datum: 2026-07-06
scope: hubs_start + hubs_arende + NC template-mekanismen + mallbiblioteket
---

# Analys — "Skapa handling från mall med ifylld ärendedata"

Målbild: från **hubs_start** (ärendekortet) eller från **Filer** (i ärenderummet) väljer
handläggaren en dokumentmall och får ett nytt dokument som redan är **ifyllt med ärendets
data** — dnr, enhet, handläggare, datum **och de fullständiga personuppgifterna** (barn,
vårdnadshavare, personnummer, sakuppgifter). Handlingen landar i ärenderummets mapp,
öppnas i Collabora för färdigställande, och committas sedan till facksystemet.

> **Bärande princip (fastställd med Fredrik):** HUBS **är** sekretessplattformen — PII ska
> och måste hanteras här, och **allt** måste finnas med i handlingarna. Invarianten är
> **auktorisationsgränsen** (rätt handläggare för ärendets enhet ser allt; inget läcker
> över gränsen), *inte* PII-döljning. Se [[hubs-pii-authorization-principle]].

---

## 0. Sammanfattning — vad krävs

Plattformen har redan **fyllnadsprimitiven** (NC `createFromTemplate` med typade fält,
Collabora/richdocuments, mallmappen) och **alla ärende-seams** (groupfolder-skrivning,
pekare, journal, authz, case-taggning, OCS-mönster). Det som **måste utvecklas** är fyra
lager:

1. **Mallarna får namngivna fält** (content controls i .docx) i stället för `[hakparentes]`.
2. **Ett datakällelager (`ArendedataService`)** som aggregerar hela ärendebilden —
   metadata ur registret **+ PII ur källorna där den bor** (anmälan/sdkmc, Treserva/SoR,
   folkbokföring, tidigare akthandlingar).
3. **En `HandlingService` + OCS-endpoint** i hubs_arende som binder mall↔ärendedata,
   skapar det ifyllda dokumentet i ärenderummet, taggar/pekar/journalför, authz-grindat.
4. **En frontend-action** i hubs_start ("Skapa handling från mall") + en mall-väljare och
   en förhandsgransknings-/kompletteringsdialog; och en **Filer-ingång** för native-flödet.

Det största och mest kritiska är lager 2 (datakällan/PII-aggregeringen) — inte för att PII
är känsligt (det ska med), utan för att **registret medvetet inte lagrar PII** ("kartan
inte territoriet"), så datan måste läsas färskt ur rätt källa per livscykelfas.

---

## 1. De två ingångarna (UX)

### Ingång A — från hubs_start (ärendekortet) — *huvudflödet*
`NastaAtgardKnapp.vue` → "…"-menyn (`ovrigaAtgarder`) får posten
`{ key: 'skapa-handling-mall', label: 'Skapa handling från mall', icon: 'FileDocumentPlus' }`.
Flödet speglar "Ny chatt i ärendet":
`ArendeKort.onMenuAction` → emit → `MinaArenden` handler → **MallVäljareModal** (välj mall)
→ **HandlingForhandsModal** (visar förifyllda fält, handläggaren kompletterar/justerar)
→ `api.skapaHandlingFromMall(ref, payload)` → `HUBS_ARENDE_OCS POST /arende/{ref}/handling`
→ svar med fil-id/path → `deepLinks.fileLink(...)` öppnar dokumentet i Filer/Collabora.

### Ingång B — från Filer (i ärenderummet) — *native-flödet*
Handläggaren står i ärenderummets mapp och väljer **+ Ny → Ny fil från mall**. NC:s native
mallväljare kör `createFromTemplate` i handläggarens egen session och skriver in dokumentet
i mappen. För att få **ifylld ärendedata** här måste den native fält-dialogen förpopuleras
utifrån vilket ärende mappen tillhör (mappen ↔ `hubsCaseId` via groupfolder-pekaren). Detta
är svårare (kräver att haka in i NC:s create-dialog) och föreslås som **fas 2**.

---

## 2. Vad plattformen REDAN har (grunden att bygga på)

### 2.1 NC:s fyllnadsprimitiv (verifierat på dev15 NC 31 + kodläst NC 32)
- `OCP\Files\Template\ITemplateManager::createFromTemplate(string $filePath, string
  $templateId='', string $templateType='user', array $templateFields=[]): array` — **publik
  OCP-API**, kan anropas **in-process** från hubs_arende.
- **VIKTIGT (kod-verifierat):** `createFromTemplate` **fyller INTE dokumentet själv**. Den
  (a) kopierar mallen till målsökvägen och (b) avfyrar `FileCreatedFromTemplateEvent` med
  `$templateFields`. **Själva ifyllningen görs av en LYSSNARE** — NC-kärnan har ingen docx/
  odt-parser. Detta är "rörmokeri, inte manipulation".
- **Vem fyller?** För .docx/.odt är det **`richdocuments` (Collabora)** som lyssnar och
  injicerar värdena i content controls. `richdocuments 8.7.4` finns på dev15 → native fält
  fungerar där. **Men vi kan lika gärna registrera VÅR EGEN lyssnare** som fyller våra
  mallar med ärendedata (se Strategi A' i §4) — då äger vi ifyllningen och behöver inte
  Collabora vid genereringen.
- Fälttyper i NC 31: **rich-text** + **checkbox** (enum deklarerar date/drop-down/picture
  men de saknar implementationsklass → `FieldFactory` kastar för dem). Fält definieras som
  **content controls** i .docx, med `{index, type, content|checked}` i create-payloaden.
- `listTemplateFields(int $fileId)`, `registerTemplateFileCreator(callable)`,
  `ICustomTemplateProvider`, events `RegisterTemplateCreatorEvent` /
  `FileCreatedFromTemplateEvent` → en app kan registrera egen mallkälla OCH haka in
  ifyllningen efter skapande.

### 2.2 Mallbiblioteket (klart, denna session)
18 .docx i den delade mallmappen (`/Mallar`, live på dev15 för barn-familj). **Men de
saknar content controls idag** — platt text med `[hakparentes]`. Källan är Markdown i
`hubs_start/mallbibliotek/socialsekreterare-barn-familj/`.

### 2.3 Ärende-motorns seams (verifierat i hubs_arende)
Allt som en genereringsflöde behöver finns redan:
- **Groupfolder-skrivning:** `GroupfolderClient` + mönstret `IRootFolder->get(
  '__groupfolders/{folderId}')->newFile($namn, $innehåll)`; folder-id resolvas via
  `PekareMapper::findByCaseAndTyp($hubsCaseId, 'groupfolder')`. (Se `ReferensFilService`
  som redan skriver `.url`-filer i ärenderummet.)
- **Pekare:** `PekareMapper::record($hubsCaseId, 'groupfolder_ref', $filnamn)`.
- **Journal:** `ArendeService::loggaHandelse($hubsCaseId, TYP, $detalj)` (`Handelse`-tabellen).
- **Authz (invarianten):** `ArendeService::assertEnhetAtkomst($arende)` — fail-closed,
  404 om ej behörig för ärendets enhet. Objektnivå.
- **Case-taggning:** `ItslTagService`/`SdkmcClient::tagMessage` för `case:{hubsCaseId}`.
- **ACL-arv:** filer i ärenderummet ärver per-case-gruppens ACL automatiskt → bara ärendets
  medlemmar når handlingen (auktorisationsgränsen upprätthålls på mappnivå).
- **OCS-mönster:** `POST /arende/{ref}/...` (t.ex. `/talkrum`, `/medlem`, `/steg`).

### 2.4 hubs_start-wiringen (verifierat)
- Action-kaskad: `ArendeKort` → `MinaArenden` → modal → `api.js` → OCS →
  `deepLinks.fileLink`. Bevisat mönster (NyChattModal).
- `api.js` boundary: `HUBS_ARENDE_OCS('apps/hubs_arende/api/v1' + path)` för ärende-verb.
- Redan i UI: fot-länk **"Kunskapsbank & mallar"** → Collectives.

---

## 3. Omvärderingen: PII är kärnan, inte ett hinder

Registret (`oc_hubs_arende_case`) håller **koordinationsdata, inte verksamhetsdata** — och
avvisar aktivt personnummer vid skapande (`/\d{6,8}[-+]?\d{4}/` → 400). Det är **by design**
(K-1.5/K-1.23, "M4 lagrar ingen verksamhetsdata"). Det betyder **inte** att HUBS inte får
röra PII — HUBS hanterar PII överallt (säker e-post, filer i ärenderummet, Talk). Den
genererade handlingen är verksamhetsdata som lever som en **fil** i ärenderummet (rätt
åtkomstskyddad), blir **allmän handling vid commit** till facksystemet — helt i linje med
doktrinen.

**Konsekvens för designen:** eftersom registret inte har PII måste `HandlingService` **läsa
PII färskt ur källan där den bor**, per livscykelfas. Det är det centrala nybygget.

### 3.1 Var bor varje datafält?

| Fält i handlingen | Källa i HUBS | Läsväg (finns / byggs) |
|---|---|---|
| dnr, kortRef, triageRef, enhet, steg, ärendetyp, frist, skapad, gallringsdatum, commit-destination | **Registret** (`Arende`-raden) | `ArendeService::show()` — **finns** |
| Handläggarens namn/roll, medlemmar | **NC-användare** (uid→display) + member-ledger | `IUserManager` + `MemberMapper` — **finns** |
| Barnets namn, personnummer, adress; vårdnadshavare; sakuppgifter | **Anmälan** (intag) och/eller **Treserva** (SoR) och/eller **folkbokföring** | **byggs** (läs-konnektorer, se 3.2) |
| Tidigare akthandlingars innehåll (t.ex. utredningen) | **Ärenderummets groupfolder-filer** | `arenderumDokument()` finns; innehållsläsning **byggs** |
| Länkar (ärenderum, Talk, team, kalender) | **Pekare** | `pekarBlock()` — **finns** |

### 3.2 PII-källor per fas — **alla tre är nybygge** (kod-verifierat)

Ingen av de tre automatiska PII-källorna finns idag. Det är analysens viktigaste fynd:
motorn är ett strikt "NEVER-SoR"-koordineringslager, så all PII måste hämtas via
**nya** läsvägar.

- **Vid intag** (mottagen orosanmälan, skyddsbedömning): PII bor i **anmälan/inflödes-
  meddelandet**. sdkmc har **inget** direkt innehålls-API (bara mappningar/kvittenser) →
  vägen går via **Nextcloud Mail `IMailManager::getSource()`**: conversation-pekaren
  (`objekt_typ='conversation'`) → email-messageId (`MessageThreadMapper`) → IMailManager →
  IMAP-källa → MIME-parse. **Nybygge, men billigast** (allt finns att haka i). Extraktion =
  alltid human-in-the-loop.
- **Efter registrering** (utredning, beslut, plan): **Treserva (SoR)** är auktoritativ.
  `FacksystemCommitPort` är **enbart commit/mint** (commit/registerCallback/verifyCallback)
  — **ingen läsmetod finns**. Krävs en **ny `FacksystemQueryPort`** (getCase/getPerson/
  getHandlingar) via Frends GET, symmetriskt bunden bredvid commit-porten. Arkitekturen är
  redo (port-systemet finns) — men det är ett **eget integrationsbygge**.
- **Personuppslag ur personnummer** (folkbokföring): **saknas helt**. DIGG-integrationen ger
  bara *myndighets*-katalog, inte medborgar-folkbokföring. SSN kan tas emot ur inflödet
  (`ArendeMatchService`, fail-closed) men det finns **ingen Navet/Skatteverket-uppslagsväg**
  (`TODO[register]`-stub returnerar `null`). **Nytt integrationsbygge** + SITHS/BankID-LoA +
  PII-audit (OSL 26 kap).
- **Handläggarens komplettering:** förhandsdialogen låter handläggaren **fylla i/justera**.
  Handläggaren är sakägaren och gör alltid den mänskliga bekräftelsen.

**Realistisk MVP (justerad efter fynden):** eftersom ingen automatisk PII-källa finns färdig
lutar MVP:n på **register-/användar-/pekar-data (finns) + handläggaren fyller PII i dialogen**.
Första automatiska PII-källan att bygga = **anmälan-läsaren** (via Mail, vid intag).

### 3.3 FATTADE BESLUT (Fredrik, 2026-07-06) — styr designen

1. **Treserva-LÄS SKA byggas** (`FacksystemQueryPort`) — men den är *berikning/verifiering*,
   inte primärkälla: **den mesta informationen ska bo i Hubs** under ärendets aktiva liv.
2. **Navet-integration SKA implementeras.** Alla kundkommuner har redan Navet-abonnemang
   som kan anslutas. → egen kravställning (se `KRAVSTALLNING-NAVET-FOLKBOKFORING.md`).
3. **Gallring = policy vid avslut.** När ärendet avslutas i ärenderummet städas innehållet
   genom gallringspolicys (per ärendetyp). Detta STÄNGER gallrings-luckan i §7.5.
4. **Utkast får leva i Hubs under kort tid.** Det är **slutresultatet** som ska in i
   SoR-systemen (via CommitGrind som idag). Hubs är arbetslagret; SoR är slutlagret.

### 3.4 Arkitektursvaret på beslut 1: **PARTSREGISTRET** (nytt, centralt)

"Den mesta informationen i Hubs" realiseras som en per-ärende-tabell
**`oc_hubs_arende_part`** i motorn: parterna i ärendet (barn, vårdnadshavare, anmälare,
motpart, samverkanspart) med roll, namn, personnummer, adress, kontaktuppgifter,
**skyddsmarkering**, källa (`anmalan|navet|treserva|manuell`) och verifierad-tidsstämpel.

- **Föds vid intag:** anmälan-extraktion (human-in-the-loop) + Navet-uppslag ur pnr +
  handläggarens manuella komplettering.
- **Berikas/verifieras** mot Treserva efter registrering (`FacksystemQueryPort`).
- **Primär ifyllnadskälla** för `ArendedataService` — lokal, snabb, authz-grindad
  (`assertEnhetAtkomst`), varje läsning auditloggas.
- **Gallras med ärendet** enligt policy (beslut 3) — arbetsdata, aldrig SoR (beslut 4).
- **Synergier:** fyller exakt hålet `ArendeMatchService::registerPartHook`
  (`TODO[register]`-stubben — SSN-matchningssteget i ärendekopplings-kaskaden väntar på
  ett partsregister) och kan driva mottagarval för säker kommunikation.
- **Doktrinjustering (medveten):** motorns "ingen PII i DB" ändras till "PII ENBART i det
  dedikerade partsregistret, med hård authz + audit + gallringsbunden livscykel".
  NEVER-SoR består: Hubs är fortfarande inte system of record — partsregistret är
  transient arbetsdata med gallringsklocka.

---

## 4. Fyllnadsstrategin — tre vägar (alla delar EN fyllningsmotor)

Nyckelinsikt ur kodläsningen: eftersom NC-kärnan ändå inte fyller dokumentet måste **vi
bygga en docx-fyllningsmotor** oavsett väg (t.ex. PhpWord eller content-control-/`{{fält}}`-
substitution). Den motorn återanvänds i alla tre strategierna nedan — skillnaden är bara
*var* och *hur* den triggas.

### Strategi A — pure native + richdocuments
Content controls i mallarna; `createFromTemplate(...)` kopierar + richdocuments (Collabora)
fyller. Fördel: ingen egen fyllningsmotor. Nackdelar: ifyllningen ägs av richdocuments
(bara rich-text+checkbox, Collabora-beroende vid genereringen), och det är oklart hur man
förpopulerar värden utanför NC:s egen create-dialog. Svag för vårt PII-rika, deterministiska
behov.

### Strategi A' — native picker + VÅR EGEN lyssnare  ⟵ elegant för Filer-ingången
Vi registrerar en lyssnare på **`FileCreatedFromTemplateEvent`**. När en av *våra* mallar
skapas i en **ärenderums-mapp** (mapp→`hubsCaseId` via groupfolder-pekaren) fyller **vi**
dokumentet med full ärendedata via vår fyllningsmotor. Ger Filers native "+ Ny från mall"-UX
**plus** full kontroll över ifyllningen (all PII, alla fälttyper), utan Collabora-beroende
vid fyllningen. Kräver att mallarna är igenkännbara (fält-konvention) och att lyssnaren
resolvar ärendet ur målmappen.

### Strategi B — backend `HandlingService` (headless)
`HandlingService` anropar samma fyllningsmotor, skriver de färdiga byte:na via den
beprövade groupfolder-seamen (`newFile`), helt headless/deterministiskt, authz-grindat.
Ger hubs_start-ingången utan att bero på användarsession eller Collabora.

### Rekommendation
- **Ingång A (hubs_start, huvudflödet):** **Strategi B** — backend, robust, full PII.
- **Ingång B (Filer native):** **Strategi A'** — native picker + vår egen event-lyssnare.
- **Bygg fyllningsmotorn EN gång** (delad av B och A'). Undvik ren Strategi A (ger oss för
  lite kontroll för det PII-kompletta behovet).

Detta låter huvudflödet (B) levereras först, och native-Filer-upplevelsen (A') adderas
utan att bygga om fyllningen.

---

## 5. Komponenter som måste utvecklas

### 5.1 Mallar (biblioteket)
- **Fält-konvention** i Markdown-källan: `[Barnets namn]` → maskinläsbart fält-id
  (t.ex. `{{barn.namn}}`). Ett **fält-register** per mall (fält-id, typ, källa, obligatorisk).
- **build-docx.sh**-utökning: generera (a) en "templater"-docx med `{{fält}}` (Strategi B)
  och/eller (b) en content-control-docx (Strategi A).
- **Per-ärendetyp-mappning:** vilka fält en mall behöver × var de hämtas (ArendeTyp-registret
  är redan datadrivet — naturlig plats för mall↔fält-mappning).

### 5.2 Datakällelager — `ArendedataService` (NYTT, hubs_arende)
- `hamtaArendedata($ref, $malldef): array` → returnerar en **fält→värde-karta** genom att:
  1. läsa registret (`ArendeService::show` + `mapToFullCard` + `pekarBlock`),
  2. resolva användare/medlemmar (`IUserManager` + `MemberMapper`),
  3. **läsa PARTSREGISTRET** (§3.4 — primär PII-källa, lokal i Hubs),
  4. vid behov fylla på registret ur källorna (anmälan-läsare → Navet → Treserva-läs),
  5. authz-grinda (`assertEnhetAtkomst`) + **audit** varje PII-läsning.

### 5.2a Partsregister — `Part`/`PartMapper` + `PartService` (NYTT, hubs_arende)
Se §3.4. Tabell `oc_hubs_arende_part` (hubs_case_id, roll, namn, pnr, adress, kontakt,
skyddsmarkering, kalla, verifierad, skapad). CRUD via `PartService` (authz + audit +
pnr-valideringsundantaget flyttar hit — motorns generella pnr-avvisning består utanför
denna tabell). OCS: `GET/POST/DELETE /arende/{ref}/part`. Gallras av `GallringService`
enligt policy (beslut 3). UI: parts-flik/panel på ärendekortet (frontend-tillägg).
- **Läs-portar (alla nya — inget finns idag):**
  - `AnmalanReadPort` — via Mail `IMailManager::getSource()` (conversation→messageId→IMAP→MIME).
    *Billigast; bygg först.*
  - `FacksystemQueryPort` — ny symmetrisk Treserva-läsport bredvid commit-porten (Frends GET).
  - `FolkbokforingPort` — Navet/Skatteverket-uppslag (tyngst; SITHS/BankID + audit).
  - Graceful: saknas en källa → fältet lämnas för handläggaren i dialogen.

### 5.2b Fyllningsmotor — `DocxFyllningsMotor` (NYTT, delad)
Kärnkomponenten (måste byggas oavsett strategi eftersom NC-kärnan inte fyller): tar en
mall + fält→värde-karta → producerar ifyllda docx-byte. Två rimliga implementationer:
`{{fält}}`-substitution i document.xml (enkel, deterministisk) eller PhpWord/content-controls
(rikare). Återanvänds av både `HandlingService` (B) och event-lyssnaren (A').

### 5.3 `HandlingService` + OCS (NYTT, hubs_arende)
- `POST /arende/{ref}/handling` `{ mallId, faltOverride }` → `ArendeController::generateHandling`.
- Flöde: authz → `ArendedataService::hamtaArendedata` → **`DocxFyllningsMotor::fyll`** →
  skriv i groupfoldern (`newFile`) → `Pekare('handling'/'groupfolder_ref')`
  → `loggaHandelse(TYP_HANDLING, {mall, filnamn})` → returnera `{ok, fileid, path}`.
- **Ny journaltyp** `TYP_HANDLING` (Handelse) + ny `objekt_typ='handling'` (Pekare) för
  spårbarhet/gallring.
- **Retention:** handlingen är **arbetsmaterial** tills commit; gallras med ärendet.
  ("Gör detta till en handling"/CommitGrind flippar till allmän handling i SoR.)

### 5.4 Frontend (hubs_start)
- `NastaAtgardKnapp.ovrigaAtgarder`: ny action `skapa-handling-mall`.
- `MinaArenden`: handler + två modaler — **MallVäljareModal** (lista mallar; kan läsa
  mallmappen/kunskapsbanken) och **HandlingForhandsModal** (visa förifyllda fält,
  komplettera, bekräfta).
- `api.js`: `skapaHandlingFromMall(ref, payload)` → `HUBS_ARENDE_OCS('/arende/'+ref+'/handling')`.
- `deepLinks`: öppna resultatet i Filer/Collabora (`fileLink`).
- Demo-läge: mock-svar (som övriga api-funktioner).

### 5.5 Native Filer-ingång — Strategi A' (fas 2)
- En **`FileCreatedFromTemplateEvent`-lyssnare** (i en NC-app, t.ex. hubs_arende): när en av
  våra mallar skapas i en ärenderums-mapp, resolva `hubsCaseId` ur målmappen (groupfolder-
  pekaren), hämta ärendedata (`ArendedataService`) och fyll dokumentet (`DocxFyllningsMotor`)
  — samma motor som backend. Ev. `ICustomTemplateProvider` för att exponera mallmappen som
  egen mallkälla. Ger native "+ Ny från mall"-UX med full ärende-ifyllning.

---

## 6. Bygg-delta — återanvänds vs nybygge

| Del | Återanvänds (finns) | Nybygge |
|---|---|---|
| Skapande-rörmokeri | NC `createFromTemplate` + event | — |
| **Fyllningsmotor** | (NC fyller EJ själv) | **`DocxFyllningsMotor` (delad av B + A')** |
| Dokumentplacering | `GroupfolderClient`/`newFile` via pekare | — |
| Spårbarhet | `PekareMapper`, `loggaHandelse` | ny `objekt_typ='handling'`, `TYP_HANDLING` |
| Authz/ACL | `assertEnhetAtkomst`, per-case-grupp | audit-logg för PII-läsning |
| Case-koppling | `ItslTagService` case-tagg | — |
| Metadata | `ArendeService::show/mapToFullCard/pekarBlock` | — |
| **PII-data** | anmälan-pekare finns | **`ArendedataService` + läs-portar (anmälan/Treserva/folkbokföring)** |
| Mallar | 18 .docx | **fält-konvention + fält-register + per-typ-mappning** |
| OCS | routes-mönster | `POST /arende/{ref}/handling` + controller |
| Frontend | action-/modal-/api-mönster | action + 2 modaler + api-funktion |

**Tyngdpunkt:** `ArendedataService` + PII-läs-portarna, och mall-fält-registret. Resten är
mönster som redan finns.

---

## 7. Feasibility-frågor

**Besvarade (kod-verifierat):**
- ✅ **Q4 `createFromTemplate` fyller inte själv** → den avfyrar en event; ifyllning görs av
  lyssnare (richdocuments för docx). Slutsats: bygg egen fyllningsmotor + använd via backend
  (B) och/eller egen event-lyssnare (A'). Kontext-frågan blir irrelevant för B (vi skriver
  byte:na direkt via groupfolder-seamen).
- ✅ **Q6 Content-controls/fälttyper** → NC 31/32 stödjer bara rich-text + checkbox som
  injicerbara fält; date/dropdown/picture saknar implementation. Ingen kärn-parser finns —
  därför äger vår fyllningsmotor fält-tolkningen (obegränsad av NC:s enum).

**Besvarade (bakgrundskörning mot koden):**
1. ⛔ **Treserva-LÄS = SAKNAS.** `FacksystemCommitPort` har bara commit/callback; ingen
   read. → **ny `FacksystemQueryPort`** krävs (Frends GET). Efter registrering bor all PII
   enbart i Treserva.
2. ⛔ **sdkmc anmälan-innehåll = SAKNAS (direkt).** sdkmc lagrar mappningar/kvittenser, ej
   innehåll. → läs via **Mail `IMailManager::getSource()`** (conversation→messageId→IMAP→MIME).
3. ⛔ **Folkbokföring/Navet = SAKNAS.** Bara DIGG-myndighetskatalog; inget person-ur-pnr.
   → **nytt Navet-bygge** + SITHS/BankID + PII-audit.
5. ⚠️ **Groupfolder-gallring = DELVIS.** Gallring raderar `.url`-referensfiler + pekare, men
   **inte** groupfoldern eller andra dokumentfiler i den (NEVER-SoR by design). → en
   **genererad handling gallras INTE automatiskt** med ärendet. Se risk §9.

---

## 8. Faser (justerade efter BESLUTEN i §3.3)

- **Fas 1 (MVP):** hubs_start-ingång (B) + `DocxFyllningsMotor` + `HandlingService`/OCS +
  frontend-action/modaler + **PARTSREGISTRET** (schema + PartService + parts-UI; fylls
  manuellt/via dialogen). `ArendedataService` läser register + partsregister. Gallrings-
  policyn utökas: `GallringService` städar handling-pekade filer + partsregister vid avslut
  (beslut 3). Levererar värde direkt — ifyllnaden blir automatisk i takt med att källorna
  ansluts, utan omdesign.
- **Fas 2a — NAVET (beslutad, kunder har abonnemang):** `FolkbokforingPort` → Frends →
  Navet: uppslag person-ur-pnr vid intag fyller partsregistret (namn, adress,
  vårdnadshavare, **skyddsmarkering**). Kravställning: `KRAVSTALLNING-NAVET-FOLKBOKFORING.md`.
- **Fas 2b — Treserva-LÄS (beslutad):** `FacksystemQueryPort` (Frends GET) → berikar/
  verifierar partsregistret efter registrering. Sekundär till partsregistret (beslut 1).
- **Fas 2c:** `AnmalanReadPort` (Mail-vägen) → extraktion ur anmälan som förslag in i
  partsregistret (human-in-the-loop).
- **Fas 3:** Native Filer-ingång (Strategi A'), per-ärendetyp fält-mappning för alla mallar,
  åter-generering, signeringsflöde (Inera/LibreSign) direkt ur handlingen.

## 9. Risker
- **PII-källorna är ALLA nybygge** (kod-verifierat) — men nu BESLUTADE spår (§3.3): Navet
  (kunder har abonnemang) + Treserva-läs + anmälan-läsare. Partsregistret gör att MVP
  levererar innan de landat.
- **Gallrings-luckan: BESLUTAD lösning (§3.3.3–4).** Utkast får leva kort i Hubs;
  slutresultat committas till SoR; vid avslut städar gallringspolicyn ärenderummet —
  `GallringService` utökas att radera `objekt_typ='handling'`-pekade filer + partsregistret.
  Kvarvarande risk: policyn per ärendetyp måste författas (juridik: arbetsmaterial får
  gallras; det som blivit allmän handling måste vara committat FÖRE städning — grinda
  avslut på ocommittade handlingar).
- **Partsregistret ändrar PII-doktrinen i motorn** (medvetet, §3.4) — kräver adversarial
  sekretessgranskning före "klart" (authz, audit, gallring, skyddsmarkering, inga läckor
  i loggar/API-svar).
- **NC fält-begränsning** (rich-text+checkbox) — irrelevant om egen fyllningsmotor (rekommenderat).
- **Determinism/kvalitet** i auto-extraktion ur anmälan → alltid human-in-the-loop.
- **Authz/audit** måste vara vattentät: PII får aggregeras men aldrig över enhetsgränsen;
  varje PII-läsning loggas (`CriticalActionPerformedEvent`-mönster).
- **Mall-underhåll:** fält-register per mall × ärendetyp måste hållas i synk med mallarna.
