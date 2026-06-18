<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Walkthrough 1 — Inflöde & triage av en orosanmälan (socialsekreterare, barn & familj)

> **Vad detta är:** en numrerad, steg-för-steg-genomgång av *ett* konkret flöde — från att en orosanmälan
> kommer in till funktionsbrevlådan `orosanmalan@kommunen`, via triage i "Att hantera"/"Orosanmälningar",
> tilldelning ("Ta/plocka ärendet"), den omedelbara skyddsbedömningen, 14-dagars förhandsbedömnings-frist,
> och fram till **aktualiseringen** (registrering) i facksystemet (Treserva/Lifecare/Viva/Combine).
>
> **Syfte:** så konkret att en granskare kan avgöra om resonemanget **håller** eller har **luckor**. Varje steg
> namnger: (a) handläggarens exakta åtgärd och var, (b) vad som händer i Hubs (mellanlagring) — vilken NC-app,
> route/API, status, (c) vad som händer i facksystemet (slutlagring) och med vilket handoff-mönster (A/B/C/D),
> (d) data-riktning + sekretess/LOA/gallring, och (e) explicit flaggade `⚠ LUCKA`/`⚠ ANTAGANDE`.
>
> **Arkitektur (genomgående):** Hubs är **mellanlagring (mellanarkiv)**. **Slutlagring (system of record)** är
> verksamhetens ärendehanteringssystem — socialtjänst: **Treserva (CGI) / Lifecare (Tietoevry) / Viva /
> Combine (Pulsen)** (socialakten/BBIC-journalen). Arkivredovisning/slutarkiv sker via FGS → e-arkiv
> (Sydarkivera). **Brand-regel:** i UI säger vi aldrig "Nextcloud"/"Talk"; här namnger vi app-id för wiring.
>
> **Produktionsantagande (genomgående):** Spreed har HPB + recording-server; Collabora/OnlyOffice är driftsatt;
> sdkmc/securemail/mail-fax är ITSL:s egna tjänster med SDK-accesspunkt (AS4). Datum: 2026-06-14.
> **Handoff-mönster:** A = API/REST (Ena REST-profil), B = drag-to-case (registrera i facksystem), C = FGS-export
> till e-arkiv, D = manuell ("Markera som överförd").
>
> **Rättslig ram för detta flöde** (källor sist): omedelbar **skyddsbedömning** samma dag / senast dagen efter
> (11 kap. 1 a § SoL, motsv. nya **SoL 2025:400**), **förhandsbedömning ≤ 14 dagar** till beslut inleda/inte
> inleda utredning, **aktualisering** = ärendet öppnas i facksystemet, **registreringsplikt** (OSL 5 kap. 1 §,
> normalt senast nästa arbetsdag), **socialtjänstsekretess** (OSL 26 kap.) + **funktionsadress = behörighetsgräns**,
> **dokumentationsskyldighet** (höjd i SoL 2025:400), **LOA3** för identitet (BankID/Freja/SITHS).

---

## Förutsättningar innan steg 1 (uppsättning som detta flöde vilar på)

- **Funktionsadress** `orosanmalan@kommunen` finns registrerad i SDK:s adressbok (Diggs/SKR:s
  funktionsadress-rekommendation) och pekar på enhetens mottagningsgrupp. Den är en *funktion*, inte en person —
  SDK skickar org-till-org till funktionen, aldrig till en namngiven handläggare.
- **Behörighet (OSL-gräns):** bara medlemmar i mottagningsgruppen (registrator + mottagningssekreterare/
  gruppledare) ser innehållet i `orosanmalan@`. I Hubs uttrycks detta som `IConditionalWidget` på
  `funktionsbrevlador` — audience targeting är här en **åtkomstgräns**, inte en UX-finess (OSL 26 kap.).
- **Inflödeskanaler** som landar i samma kö: (1) **SDK/AS4** från annan myndighet (polis, region/BUP, annan
  kommun, skola med SDK-anslutning), (2) **säker e-post** (securemail, t.ex. skola utan SDK + BankID-länk), (3)
  **digital fax** (mail/fax-bryggan, privat utförare/vårdgivare under faxavveckling).

---

## Stegen

**Steg 1 — Orosanmälan landar i funktionsbrevlådan**
- **Handläggaren:** ingen åtgärd än — detta är ett systemhändelse-steg. (En anmälare utanför Hubs har skickat:
  skola via SDK/securemail, polis/region via SDK, privat utförare via digital fax.)
- **I Hubs (mellanlagring):** meddelandet tas emot på SDK-accesspunkten (AS4-transport) och routas till
  funktionsadressen `orosanmalan@`. Det blir ett objekt i sdkmc med provenance-metadata fångad redan vid mottag:
  **kanal** (SDK/säker e-post/fax), **avsändarens verifierade identitet + LOA** (SDK org-cert / BankID/Freja /
  SITHS), **tidsstämpel**, **funktionsadress**. App/route: `sdkmc` summary-endpoint
  `/ocs/v2.php/apps/sdkmc/api/v1/summary` (server-side kanalklassning) → `/apps/sdkmc/?mailbox=orosanmalan@…`.
  Status: **Oläst · Otilldelad**. En **AS4-kvittens** (mottaget) går tillbaka till avsändarens system.
- **I facksystemet (slutlagring):** inget ännu — committas i steg 9 (aktualisering). Treserva/Lifecare vet inte
  om att anmälan finns.
- **Data:** riktning **IN** (extern → Hubs). Innehåll: orosanmälan + ev. bilagor (PDF). Sekretess: presumtivt
  **OSL 26 kap.** (socialtjänstsekretess) — extra känsligt (barn). LOA: avsändarens nivå loggas; SDK org-till-org
  bär organisationsidentitet (ej individ-LOA3 nödvändigtvis). Gallring/retention: Hubs-kopian får
  rensningsregel som aktiveras *efter* överföring (Files Retention, restricted-tagg) — se steg 9–10.
- `⚠ ANTAGANDE:` att skolans/avsändarens system faktiskt är SDK-anslutet. Idag är SDK-utrullningen ojämn; många
  skolor saknar SDK och skickar via säker e-post eller (fortf.) fax/papper. Flödet håller för alla tre kanaler,
  men "verifierad LOA3-avsändare" gäller bara SDK-org-cert / BankID-signerad securemail — **inte** anonym fax.
- `⚠ LUCKA:` **anonym orosanmälan** (privatperson får vara anonym) saknar verifierad avsändaridentitet helt.
  Provenance-bandet måste kunna visa "avsändare ej verifierad/anonym" som ett *legitimt* tillstånd, inte ett fel.

**Steg 2 — Anmälan syns i triagekön (morgontriage)**
- **Handläggaren:** loggar in (Freja eID Plus / SITHS / BankID, **LOA3**) och öppnar **`attHantera`** ("Att
  hantera") — den aggregerade triagekön, inte en mejlinkorg. Ser den nya anmälan som en rad med **kanalikon**,
  **oläst-markör** och en **destinations-chip** ("→ Treserva — ej registrerad"). Parallellt syns den i den
  dedikerade kön **`orosanmalningar`** ("Orosanmälningar – förhandsbedömning").
- **I Hubs (mellanlagring):** `attHantera` renderar summary-endpointens kanalklassade rad. `orosanmalningar`
  speglar samma objekt i ett **Tables-register** (status/frist/källa/kanal) — status **Ny**. Ingen fristklocka
  startar än (den knyts till skyddsbedömning/tilldelning i steg 3–4). Route: `/apps/tables/#/table/{tableId}`
  (renderas som widget, aldrig rå tabell).
- **I facksystemet:** inget ännu — committas i steg 9.
- **Data:** riktning **internt i Hubs** (ingen ut). Default korttext/radtext = **ärendereferens/metadata**, inte
  klartextcitat av känsliga barnuppgifter (GDPR art. 5 dataminimering). Sekretess: vyn får bara visa rubrik/
  avsändare/antal för den som har behörighet till `orosanmalan@`.
- `⚠ ANTAGANDE:` att `orosanmalningar` är *proposed-integration* (Tables-backat) — den renderas, men "14-dgr-
  countdown" och statusflödet är ännu inte kopplat till en sann frist-källa förrän Tables-registret matas av
  sdkmc-inflödet. Demo-bart, men frist-siffran är inte "sann" utan den konnektorn.

**Steg 3 — Omedelbar skyddsbedömning (samma dag)**
- **Handläggaren:** öppnar anmälan och gör — eller säkerställer att mottagningsgruppen gör — en **omedelbar
  skyddsbedömning** (behöver barnet *omedelbart* skydd?). Detta är ett separat, lagstadgat moment som ska ske
  **samma dag anmälan inkom, senast dagen efter** (11 kap. 1 a § SoL), och **måste dokumenteras**.
- **I Hubs (mellanlagring):** skyddsbedömningen dokumenteras som en kort, mallstyrd notering. Två
  designalternativ: (a) i ett snabbt **Forms**-internled (skyddsbedömningsmall) kopplat till objektet, eller (b)
  direkt i ett tidigt **ärenderum** (om sådant skapas redan här). Status i `orosanmalningar` → **Under
  förhandsbedömning**. Skyddsbedömningen är *inte* förhandsbedömningen — den är ett akut delmoment i den.
- **I facksystemet:** i de flesta kommuner sker skyddsbedömningen och dess dokumentation **direkt i Treserva/
  Lifecare** eftersom det är ett dokumentationspliktigt myndighetsmoment. Då är facksystemet "system of record"
  redan här (mönster D/A) och Hubs roll krymper till *kanal/mellanlagring* för själva inkommandet.
- **Data:** riktning **IN→dokumenteras**. Sekretess: OSL 26 kap. Retention: skyddsbedömningstexten är en
  **allmän handling/journalnotat** → hör hemma i facksystemet, inte enbart i Hubs.
- `⚠ LUCKA:` **var dokumenteras skyddsbedömningen "officiellt"?** Om Hubs ärenderum ännu inte är skapat (steg 6)
  och facksystems-aktualisering ännu inte gjord (steg 9), finns ett glapp där ett **dokumentationspliktigt,
  tidskritiskt** moment riskerar att bara ligga i ett Forms-svar/en Hubs-notering. Antingen (i) tvinga
  aktualisering i facksystemet *före* skyddsbedömningen, eller (ii) acceptera att Hubs-noteringen *omgående*
  förs över. Personaunderlaget förutsätter ofta att handläggaren "gör kontroll i Treserva i parallellt fönster" —
  men säger inte entydigt var skyddsbedömningen committas. **Detta är den känsligaste luckan i hela flödet.**
- `⚠ ANTAGANDE:` att tidsstämpeln "samma dag" beräknas från **inkom-datum** i sdkmc (provenance), inte från när
  handläggaren råkar öppna raden. Annars kan en anmälan som inkom sent fredag och öppnas måndag redan ha brutit
  skyddsbedömnings-fristen utan att klockan visat det.

**Steg 4 — Plocka / tilldela ärendet ("Ta ärendet")**
- **Handläggaren:** ur **`funktionsbrevlador`** (eller direkt i `orosanmalningar`) **plockar** anmälan — knapp
  **"Ta/plocka ärendet"** — eller så fördelar gruppledaren den på morgonmötet. Otilldelat syns för hela enheten
  tills någon tar ansvar (ingen anmälan faller mellan stolarna).
- **I Hubs (mellanlagring):** objektet får **tilldelad handläggare** (assignee). I samma sekund **startar
  14-dagars förhandsbedömnings-countdown** (om den inte redan startade vid inkomst — se lucka). Bevakning skapas
  i **Deck** (delad board: `bevakningar`/`todolista`) + ev. **Tasks/VTODO** (personlig påminnelse T-7/T-3/T-0,
  bara till tilldelad — täcker Deck #1549/#566). Route: `/apps/deck/board/{boardId}/card/{cardId}`. Status →
  **Under förhandsbedömning · tilldelad NN**. ACL på objektet snävas in: tilldelad skriver, gruppledare läser.
- **I facksystemet:** inget formellt ännu — men flera kommuner registrerar **aktualiseringen redan här** (när en
  handläggare tar ärendet) i Treserva/Lifecare. Då sker steg 9 parallellt med steg 4. (Se lucka i steg 9 om
  *när* aktualisering "egentligen" sker.)
- **Data:** riktning **internt** (tilldelning). Sekretess: tilldelning loggas (Activity / sdkmc-logg) för
  spårbarhet (NIS2/internkontroll). Retention: bevakningskortet är **personlig/delad arbetsnotering** (gallras),
  inte allmän handling.
- `⚠ LUCKA:` **var startar 14-dagarsklockan — vid inkomst eller vid tilldelning?** Juridiskt löper fristen från
  att anmälan **inkom** till myndigheten (steg 1), *inte* från tilldelning. Persona-underlaget säger ibland
  "i samma sekund den blir hennes startar countdown" (steg 4) — det är **fel referenspunkt** och kan ge falsk
  trygghet (några dagar redan förbrukade innan plock). **Klockan måste starta på inkom-datum (steg 1).**
- `⚠ ANTAGANDE:` att Hubs känner till vilken handläggargrupp/enhet som äger funktionsadressen, så att "plocka"
  bara kan göras av behörig. Om flera enheter delar adress krävs routing-regel.

**Steg 5 — (Parallellt) kontroll av tidigare aktualiseringar i facksystemet**
- **Handläggaren:** öppnar **Treserva/Lifecare i ett parallellt fönster** (separat inloggning, SITHS) och
  kontrollerar: finns barnet/familjen sedan tidigare? Tidigare anmälningar, pågående insatser, LVU-historik?
- **I Hubs (mellanlagring):** ingen data hämtas in i Hubs (Hubs läser inte facksystemets journal). Hubs kan på
  sin höjd visa en **deep-link/destinations-chip** mot facksystemets sökvy.
- **I facksystemet (slutlagring):** **läsning** i Treserva/Lifecare — facksystemet äger historiken. Mönster:
  ingen överföring; ren uppslagning (skulle vara **mönster A** om Hubs hade läs-API mot facksystemet).
- **Data:** riktning **läs i facksystemet** (utanför Hubs). Sekretess: OSL 26 kap.; åtkomst styrs av
  facksystemets behörighet, inte Hubs.
- `⚠ LUCKA:` detta steg sker **helt utanför Hubs** idag (separat inloggning, manuell context-switch). Hubs
  "stänger gapet inkorg↔facksystem" men *inte* gapet "se historik vid triage". En läskonnektor (mönster A) mot
  Treservas öppna API skulle kunna visa "tidigare aktualisering finns: ja/nej" som en chip — men det är **inte
  byggt** och förutsätter facksystem-API + att läsning ur facksystemet via Hubs är rättsligt/behörighetsmässigt
  tillåten. Tills dess: dubbelarbete och risk att triage-beslut tas utan historik synlig i samma vy.

**Steg 6 — Skapa ärenderum (säker dokumentyta)**
- **Handläggaren:** klickar **"Skapa ärenderum"** (i `orosanmalningar`/`arenderum`). Ett rum per barn/dnr-token
  skapas; anmälan + bilagor läggs där; första BBIC-/förhandsbedömningsnotering påbörjas (mall ur `kunskapsbank`).
- **I Hubs (mellanlagring):** Hubs orkestrerar en **Groupfolder** (Team folder) med **ACL** (tilldelad: write;
  gruppledare: read), **versionshantering**, och en **restricted retention-tagg** (gallringsregel per handlingstyp,
  rensning *efter* överföring). Anmälningsobjektets bilagor flyttas/kopieras in. Route: `/apps/files/?dir=/
  {arenderum}` · `/f/{fileId}`; API `/ocs/v2.php/apps/groupfolders/folders`. Samredigering av noteringen sker
  on-prem via Collabora/OnlyOffice (WOPI). Status `arenderum` → **Påbörjad**.
- **I facksystemet:** inget ännu — committas i steg 9. (Ärenderummet är *arbetslager*, inte socialakten.)
- **Data:** riktning **internt** (objekt → strukturerad dokumentyta). Sekretess: OSL 26 kap.; **least-permission
  ACL** är säkerhetskontrollen. Retention: **dubbel countdown** — facksystemets bevarande (sätts i steg 9+) och
  Hubs egen rensning ("Rensas ur Hubs X dgr efter överföring").
- `⚠ ANTAGANDE:` att rummet skapas per **barn**, inte per anmälan. Flera anmälningar kan gälla samma barn; flera
  barn kan finnas i en familj/anmälan. Token-modellen (barn↔dnr) måste hantera 1:n. Underlaget säger "per
  barn/dnr" men löser inte syskon-/familjefallet entydigt.
- `⚠ LUCKA:` **dnr finns oftast inte förrän facksystemet aktualiserat** (steg 9). Då skapas rummet på en
  *tillfällig* Hubs-token och måste **återkopplas** till facksystemets dnr i steg 9 — den mappningen
  (Hubs-token ↔ Treserva-dnr) är en byggdetalj som inte är specificerad och är en vanlig felkälla (fel rum mot
  fel dnr).

**Steg 7 — Förhandsbedömning: tillåtna kontakter inom ramen**
- **Handläggaren:** under förhandsbedömningen får **endast** vårdnadshavare, anmälaren och barnet kontaktas (inga
  utomstående uppgiftsinhämtningar — det får ske först i utredning). Kontakt sker via **säkert meddelande**
  (securemail + BankID-länk till vårdnadshavare; SDK till anmälaren om myndighet) eller säkert samtal/möte.
  Noteringar förs i ärenderummet.
- **I Hubs (mellanlagring):** utgående säkra meddelanden via `attHantera` → sdkmc/securemail; svar/uppladdningar
  (t.ex. vårdnadshavares komplettering via BankID-delning) mellanlagras i ärenderummet. `kvittenser` visar
  leveranstidslinje (Skickad→Levererad→Öppnad→Läst). Knapp **"Skapa bevakning från meddelande"** kan sätta en
  uppföljnings-bevakning kopplad till objektet.
- **I facksystemet:** inget ännu — men kontakter/noteringar som är journalpliktiga hör hemma i facksystemet vid/
  efter aktualisering (steg 9).
- **Data:** riktning **UT och IN** (tvåvägsdialog, inte massutskick). Sekretess: OSL 26 kap.; identitets-badge
  per motpart (metod + LOA + tidpunkt). Retention: dialogens allmänna handlingar → facksystemet; rena
  arbetsnoteringar gallras.
- `⚠ LUCKA:` Hubs har ingen automatisk **spärr** mot att kontakta "fel" part (utomstående) under
  förhandsbedömningsfasen — det är en rättslig regel som idag vilar på handläggarens kunskap, inte på en
  systemkontroll. En statusbunden varning ("under förhandsbedömning: endast vårdnadshavare/anmälare/barn") vore
  möjlig men är **inte specificerad**.

**Steg 8 — Beslut: inleda / inte inleda utredning (inom 14 dgr)**
- **Handläggaren:** fattar och dokumenterar beslut **"inleda utredning"** eller **"inte inleda utredning"** inom
  14-dagarsfristen. Per SKR:s riskmodell: lågrisk-beslut **godkänns** (loggat, ingen formell signatur); annars
  **skickas för underskrift** (AES via BankID/Freja → PAdES/PDF/A). I `bevakningar` växlar fristfärgen
  grå→gul→röd mot dag 14.
- **I Hubs (mellanlagring):** beslutsdokumentet skapas/samredigeras i ärenderummet. Signeringssteg (om beslut
  ska signeras) går via `attSignera` → signeringsadapter (LibreSign internt lågrisk / **Inera
  Underskriftstjänst-API** för skarp AES i prod). Status `orosanmalningar` → **Beslut inleda** / **Beslut ej
  inleda**.
- **I facksystemet:** beslutet är ett **rättsligt myndighetsbeslut** och **måste** committas till facksystemet.
  "Inleda" → ett utredningsärende (4-månadersfrist startar — utanför detta flödes scope, se walkthrough 2).
  "Inte inleda" → avslutas; anmälan + beslut bevaras enligt plan (orosanmälan ska till socialnämnden, inte ligga
  kvar i t.ex. elevakt). Mönster D (manuell) dag 1, B/A där konnektor finns.
- **Data:** riktning **klart för överföring**. Sekretess: OSL 26 kap. Retention: beslut + anmälan =
  **bevarandepliktiga** i facksystemet/e-arkivet.
- `⚠ ANTAGANDE:` att "inte inleda"-beslut ändå **registreras** (aktualiseras) i facksystemet — många kommuner
  registrerar även anmälningar som inte leder till utredning. Om kommunen *inte* gör det finns risk att en
  bevarandepliktig handling bara ligger i Hubs → strider mot transient-principen. Underlaget förutsätter
  registrering men kommunpraxis varierar.

**Steg 9 — Aktualisering: registrera/för över till facksystemet**
- **Handläggaren:** **för över** anmälan + förhandsbedömning/beslut + provenance-metadata (kanal, inkom-datum,
  avsändare, sekretessmarkering) till **Treserva/Lifecare/Viva/Combine** — detta är **aktualiseringen** och
  uppfyller **registreringsplikten** (OSL 5 kap. 1 §, normalt senast nästa arbetsdag). I praktiken kan
  aktualiseringen ha skett tidigare (steg 3/4); senast här måste den vara gjord.
- **I Hubs (mellanlagring):** **mönster B (drag-to-case):** Hubs öppnar ett **förifyllt registreringsformulär**
  (avsändare/inkom-datum/föreslaget dnr/ärendemening/sekretess) och POST:ar till facksystemet, eller genererar en
  importfil. **Mönster A (API/REST)** hos storkund med Treservas öppna API (Ena REST-profil). **Mönster D**
  (ladda ner + "Markera som överförd") som dag-1-fallback. Provenance-chip flippar: "→ Treserva — ej
  registrerad" → "Registrerad i Treserva, dnr 2026-IFO-1234". Hubs-token ↔ dnr mappas; ärenderummet får
  facksystemets dnr.
- **I facksystemet (slutlagring):** **aktualisering skapas** — ärende/aktualisering öppnas, anmälan blir
  inkommen allmän handling i akten, förhandsbedömning/beslut journalförs, bevarande-/gallringsregel sätts enligt
  kommunens dokumenthanteringsplan. **Detta är ögonblicket "var hamnar det".**
- **Data:** riktning **UT** (Hubs → facksystem). Sekretess: OSL 26 kap. följer med. Retention: facksystemet
  äger nu bevarandet; Hubs-kopian får **rensningscountdown**.
- `⚠ LUCKA:` **dubbel/oklar tidpunkt för aktualisering.** Aktualisering kan ske (a) vid skyddsbedömning (steg 3),
  (b) vid plock (steg 4), eller (c) vid beslut (steg 8/9) — beroende på kommun och facksystem. Hubs-flödet måste
  välja **en** kanonisk punkt och visa den i provenance-bandet, annars uppstår risk för **dubbelregistrering**
  (samma anmälan både som Hubs-objekt och facksystems-ärende utan klar "förd över"-status) eller **för sen
  registrering** (registreringsplikt brusten). **Antagandet att handläggaren manuellt för över (mönster D) dag 1
  innebär att registreringsplikten vilar på en manuell åtgärd — ingen systemgaranti.**
- `⚠ ANTAGANDE:` att Treservas/Lifecares API faktiskt accepterar skapande av aktualisering via mönster A. Treserva
  marknadsförs med "öppna API:er", men exakt vilka operationer som exponeras (skapa aktualisering, bifoga PDF,
  sätta sekretessmarkering) är **inte verifierat** i underlaget — A kan i praktiken bli B/D tills integrationen
  är byggd och testad per kund.

**Steg 10 — Stäng loopen: kvittens, bevakning, gallring av Hubs-kopian**
- **Handläggaren:** klarmarkerar bevakningen. Vid klarmarkering väljer hon **"för till ärendet/facksystemet"**
  (allmän handling) eller **"gallra (personlig notering)"** (privat arbetslapp) — håller isär arkivpliktigt från
  gallringsbart. Anmälaren återkopplas (i den mån sekretess tillåter) via säker kanal; överklagande-/
  uppföljningsfrist sätts som bevakning där relevant.
- **I Hubs (mellanlagring):** bevakningskortet stängs; objektets status → **Klar (överförd)**. **Files
  Retention** aktiverar rensningsregeln på ärenderummet/objektet ("Rensas ur Hubs 30 dgr efter överföring",
  ägarnotis före radering). `kvittenser` bevarar leveransbeviset tills det följt med handlingen till facksystemet.
- **I facksystemet (slutlagring):** ärendet lever vidare i Treserva/Lifecare (utredning eller avslutad
  aktualisering); vid avslut → facksystemets ansvar att arkivredovisa → **FGS-export till e-arkiv (Sydarkivera),
  mönster C** (utanför detta flödes scope).
- **Data:** riktning **gallring i Hubs / bevarande i facksystem**. Sekretess: rensningen minimerar dubbellagrad
  sekretess (GDPR-dataminimering; suveränitet — ingen permanent skuggdatabas). Retention: två regimer
  (facksystemets DHP/FGS = bevarande; Hubs restricted-tagg = kort rensning efter överföring).
- `⚠ LUCKA:` rensningen förutsätter att **överföringen verkligen bekräftats**. Med mönster D ("Markera som
  överförd") är bekräftelsen **handläggarens egen markering**, inte ett maskinellt kvitto från facksystemet —
  om hon markerar fel kan Hubs gallra en kopia vars original aldrig kom in i Treserva. **Behöver en
  återkvittens/verifiering från facksystemet (mönster A) innan Hubs-gallring triggas — annars finns en reell
  risk att tappa en bevarandepliktig handling.**
- `⚠ ANTAGANDE:` att kommunens dokumenthanteringsplan har en **uttrycklig rensningsregel** för Hubs som
  mellanlagring ("arbetsmaterial/mellanlagring rensas efter överföring"). Utan ett dokumenterat gallringsbeslut
  för Hubs-lagret är även en kort rensning formellt en oreglerad gallring (arkivlagen).

---

## Systemöversikt för detta flöde

| Steg | Hubs-app (mellanlagring) | Facksystem (slutlagring) | Handoff |
|---|---|---|---|
| 1 Inkomst till funktionsadress | `sdkmc`/`securemail`/`mail-fax` (summary-endpoint) | — (inget ännu) | — (AS4-kvittens tillbaka) |
| 2 Syns i triagekö | `attHantera` + `orosanmalningar` (Tables) | — | — |
| 3 Omedelbar skyddsbedömning | `forms`/`arenderum` (notering) | Treserva/Lifecare (dokumenteras ofta direkt) | D/A |
| 4 Plocka/tilldela | `funktionsbrevlador` + `deck`/`tasks` (bevakning) | (ev. aktualisering här) | — / D |
| 5 Kontroll tidigare aktualisering | (deep-link/chip) | Treserva/Lifecare (läsning) | A (önskat) / manuellt idag |
| 6 Skapa ärenderum | `arenderum` (groupfolders+ACL+Retention) | — | — |
| 7 Tillåtna kontakter | `attHantera`/`kvittenser` + `arenderum` | — | — |
| 8 Beslut inleda/ej inleda | `orosanmalningar` + `attSignera` (AES) | Treserva/Lifecare (beslut journalförs) | D/B/A |
| 9 Aktualisering (registrering) | `registreraFordela`-mönster (förifyllt) | **Treserva/Lifecare/Viva/Combine** (aktualisering, dnr) | **B/A** (D dag 1) |
| 10 Stäng loop + gallra | `bevakningar` + `files_retention` + `kvittenser` | facksystemet bevarar → e-arkiv (FGS) | C (vid avslut) |

---

## Identifierade luckor

1. **Var dokumenteras den omedelbara skyddsbedömningen "officiellt"?** (steg 3) Ett tidskritiskt,
   dokumentationspliktigt moment riskerar att bara ligga i ett Hubs-Forms-svar/en notering om varken ärenderum
   (steg 6) eller facksystems-aktualisering (steg 9) ännu finns. **Känsligaste luckan.** Lösning: tvinga
   aktualisering/överföring före eller omedelbart efter skyddsbedömningen.
2. **Var startar 14-dagarsklockan?** (steg 4) Juridiskt löper fristen från **inkomst** (steg 1), inte från
   tilldelning/plock. Underlaget säger ibland "startar när hon plockar" — fel referenspunkt → falsk trygghet.
   Klockan måste bindas till sdkmc inkom-datum.
3. **När sker aktualiseringen — och risk för dubbelregistrering/för sen registrering.** (steg 9) Aktualisering
   kan ske vid skyddsbedömning, plock eller beslut beroende på kommun/facksystem. Flödet måste välja en kanonisk
   punkt och visa "förd över"-status, annars brister registreringsplikten (OSL 5:1) eller uppstår dubbellagring.
4. **Mönster A mot Treserva/Lifecare är ett antagande, inte verifierat.** (steg 9) "Öppna API:er" är marknadsförda
   men vilka operationer (skapa aktualisering, bifoga PDF, sätta sekretess) som faktiskt exponeras är inte
   bekräftat → A blir i praktiken B/D tills byggt och testat per kund.
5. **Hubs-token ↔ facksystems-dnr-mappning** är ospecificerad. (steg 6/9) Ärenderum skapas före dnr finns →
   återkoppling till rätt dnr är en känd felkälla (fel rum mot fel ärende).
6. **Ingen systemkontroll på "tillåtna kontakter" under förhandsbedömning.** (steg 7) Den rättsliga begränsningen
   (endast vårdnadshavare/anmälare/barn) vilar på handläggarens kunskap, inte på en spärr i Hubs.
7. **Gallring av Hubs-kopian vilar på manuell "Markera som överförd" (mönster D).** (steg 10) Utan maskinell
   återkvittens från facksystemet (mönster A) finns risk att gallra en kopia vars original aldrig nådde fram.
8. **Saknad/oklar gallringsregel för Hubs som mellanlagring i kommunens DHP.** (steg 10) Även en kort rensning är
   formellt en gallring som kräver ett dokumenterat beslut (arkivlagen).
9. **"Se historik vid triage" sker utanför Hubs** (steg 5) — separat facksystems-inloggning; gapet
   "inkorg↔facksystem" stängs men inte "historik i samma vy" utan en läskonnektor (ej byggd; även rättsligt
   beroende av behörighet att läsa facksystemet via Hubs).
10. **Anonym/overifierad avsändare** (steg 1) måste vara ett legitimt provenance-tillstånd, inte ett fel; och
    "verifierad LOA3-avsändare" gäller bara SDK-org-cert/BankID-signerad securemail — inte anonym fax/papper.

---

### Källor (rättslig grund för detta flöde)

- Förhandsbedömning ≤ 14 dgr (beslut inleda/inte inleda): https://kunskapsguiden.se/omraden-och-teman/barn-och-unga/handlaggning-och-dokumentation-med-barnet-i-centrum/aktualisera/forhandsbedomning/
- Besluta om att inleda/inte inleda utredning: https://kunskapsguiden.se/omraden-och-teman/barn-och-unga/handlaggning-och-dokumentation-med-barnet-i-centrum/aktualisera/besluta-om-att-inleda-eller-inte-inleda-en-utredning/
- Aktualisering av ett ärende: https://kunskapsguiden.se/omraden-och-teman/barn-och-unga/handlaggning-och-dokumentation-med-barnet-i-centrum/aktualisera/aktualisering-av-ett-arende/
- Omedelbar skyddsbedömning samma dag / senast dagen efter (11 kap. 1 a § SoL), dokumentationsplikt: https://kunskapsguiden.se/omraden-och-teman/barn-och-unga/handlaggning-och-dokumentation-med-barnet-i-centrum/aktualisera/aktualisering-av-ett-arende/ · JO dnr 5219-2018: https://lagen.nu/avg/jo/5219-2018
- Socialtjänstlag (2025:400), höjda dokumentationskrav: https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/socialtjanstlag-2025400_sfs-2025-400/
- SDK + funktionsadress (org-till-org, funktion ej person; orosanmälan via SDK): https://skr.se/skr/naringslivarbetedigitalisering/digitalisering/digitalinfrastruktur/sakerdigitalkommunikationsdk.13701.html · https://www.inera.se/nyheter/nyheter/kommunalt-innehall-i-adressboken-for-sdk/
- Registrering av allmän handling senast nästa arbetsdag (OSL 5:1, JO 3579-05): http://www.legalahandboken.se/offentlighet/regler_reg.html
- OSL 10:2a / on-prem eliminerar lämplighetsbedömning (eSam ES2023-06): https://www.esamverka.se/download/18.43a3add4188b9f2345a2fe78/1687332814480/ES2023-06%20V%C3%A4gledning%20Utkontraktering%20-%20sekretess%20och%20dataskydd.pdf

*(Tekniska kopplingar — app-id, routes, handoff-mönster — grundas i `analysis-output/extended/native-apps-map.md`,
`arendehantering-map.md`, `middleware-architecture.md`, `esign-todo-native.md`, persona-underlagen och
`hubs_start/src/services/widgetApps.js` / `docs/WIDGET-APP-MAP.md`.)*
