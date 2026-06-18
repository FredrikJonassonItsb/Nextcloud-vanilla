<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Hubs som mellanlagring (staging) — ärendehanteringssystemet som slutlagring (system of record)

> **Den bärande arkitekturberättelsen för Hubs.** Detta dokument är teaching-storyn för kund- och
> utvecklardemos: *var kommer datan ifrån, var hamnar den till slut, och varför ska Hubs medvetet
> INTE vara slutlagret.* Datum: 2026-06-13. Plattform: server v32. Brand-regel följer
> `PERSONA-DASHBOARD-SPEC.md` — i UI säger vi aldrig "Nextcloud"/"Talk"; i detta interna underlag
> namnger vi appar (sdkmc, spreed-itsl, Groupfolders, Tables, Deck, LibreSign m.fl.) och exakta
> svenska facksystem för spårbarhet.

---

## 0. Headline (TL;DR för demon)

**Hubs är mellanlagringen — en sekretesssäker, on-prem arbetsyta där säker kommunikation, signering,
möten och filer *tas emot, handläggs och bearbetas* — men aldrig den slutliga sanningen. Systemet av
sanning (slutlagring / system of record) är alltid verksamhetens ärendehanteringssystem:** Treserva /
Lifecare / Viva / Combine (socialtjänst & HSL), Cosmic (region-HSL), Provisum / Aider / e-Wärna
(överförmyndare), W3D3 / Public 360° / Ciceron / Platina / Evolution / LEX (registratur & nämnd),
Visma / Heroma / Personec (HR). Hubs-livscykeln är **mottag → handlägg → för över till facksystemet →
gallra i Hubs**. Det är inte en begränsning — det är hela den juridiska och kommersiella poängen:
genom att vara medvetet *transient* slipper Hubs bli ytterligare ett arkivbildande system att
gallringsbesluta, parallelldiariera och tvista om dataägarskap kring. Dashboarden måste göra denna
**provenance** (härkomst → destination) synlig på varje rad: *Kanal in · Status nu · Slutdestination
(facksystem) · Gallras i Hubs när X*.

---

## 1. Varför staging, inte arkiv? (den juridiska och operativa logiken)

Fyra krafter gör "Hubs = mellanlagring" till rätt arkitektur, inte en kompromiss:

### 1.1 Sekretess & dataägarskap (OSL 10:2a + on-prem)

Sekretessreglerade uppgifter får inte röjas (OSL 2009:400). Att lägga sekretesshandlingar hos en
extern molnleverantör är i sig ett *röjande* (SOU 2021:1). Den sekretessbrytande regeln **10 kap. 2 a §
OSL** (i kraft 1 juli 2023) tillåter utlämnande till leverantör för *enbart teknisk bearbetning/lagring*
"om det inte är olämpligt" — men kräver lämplighetsbedömning i varje fall (eSam ES2023-06). **Hubs
on-prem gör hela den bedömningen överflödig: ingen extern part får informationen.** Det gäller *medan*
datan ligger i Hubs. Men ju längre en handling lever i Hubs, desto mer börjar Hubs likna ett
arkivbildande system med egna bevarande-/gallringsbeslut. Genom att hålla Hubs *transient* — staging,
inte arkiv — undviker man att kommunen behöver fatta parallella arkivbeslut för Hubs *och* för
facksystemet. **Slutlagringen sker där arkivredovisningen redan finns.**

### 1.2 Registreringsplikten tvingar fram en överföring ändå (OSL 5:1)

Allmänna handlingar ska registreras (diarieföras) så snart de kommit in eller upprättats hos en
myndighet — i normalfallet **senast påföljande arbetsdag** (OSL 5 kap. 1 §; JO 3579-05; Skatteverkets
rättsliga vägledning 2025). Registret förs i diarie-/ärendesystemet (W3D3, Public 360°, Ciceron,
Platina, Lifecare osv.) — inte i en inkorg. Det betyder att **varje inkommande handling i Hubs som är
en allmän handling *måste* vandra vidare till facksystemet** för att uppfylla lagen. Hubs roll är då per
definition mellanlagring: stället där handlingen *landar och triageras*, innan den registreras och
arkiveras på rätt ställe. Hubs `registreraFordela`-widgeten är exakt den bryggan (se §4).

### 1.3 Retention/gallring hör hemma där arkivredovisningen finns (arkivlagen + arkivförordningen 2024)

Huvudregel: allmänna handlingar **bevaras**; gallring kräver gallringsbeslut, och för kommuner beslutar
**kommunen själv** enligt sin dokumenthanteringsplan (Riksarkivet ger bara allmänna råd för
kommunsektorn). Gallrings-/bevarandelogiken — vilken handlingstyp som bevaras till evig tid, vilken som
gallras efter 2/5/10 år, FGS-leverans till e-arkiv (Sydarkivera) — bor i facksystemets/e-arkivets
arkivredovisning. **Arkivförordningen uppdaterades 1 aug 2024**: information i arkivbildande
informationssystem ska kunna *exporteras och raderas* före upphandling/införande. Hubs möter detta
**inte** genom att bli arkivet, utan genom att (a) exportera/överlämna till facksystemet och (b)
**gallra sin egen mellanlagrade kopia** när ärendet är överfört. Två retention-regimer:
- **Slutlagringens** bevarande-/gallringsregler → i facksystemet/e-arkivet (kommunens DHP, FGS).
- **Mellanlagringens** rensningsregel → i Hubs (Files Retention-app via restricted-tagg): *"gallras X
  dagar efter att ärendet förts över till facksystemet"*. Kort, transaktionell, suveränitetsbevarande.

> Designkonsekvens: ärenderums-widgeten (`arenderum`) visar **två** countdowns — facksystemets
> bevarandestatus ("Bevaras / Gallras 2031 i e-arkivet") *och* Hubs egen rensning ("Rensas ur Hubs 30
> dgr efter överföring"). Det gör skillnaden mellan mellan- och slutlagring pedagogiskt synlig.

### 1.4 Marknads-/produktlogik: var inte system nummer åtta

Verksamheten har redan investerat i sitt facksystem; det är där handläggaren fattar och dokumenterar
beslut, där tillsyn sker, där statistik dras. Hubs vinner *inte* genom att försöka ersätta Treserva
eller Public 360° — det vinner genom att **fylla gapet** mellan den externa, sekretessbärande
kommunikationen (som facksystemen är usla på: fax, rek-brev, okrypterad e-post, Skype) och
facksystemets strukturerade ärende. Hubs är limmet, inte arkivet. "Integrerar mot — ersätter inte —
W3D3/Public 360°/Ciceron/Lifecare" är genomgående i `personaConfig.js` av just detta skäl.

---

## 2. Dataflödet (livscykeln) — mottag → handlägg → för över → gallra

Den kanoniska berättelsen, i fem steg. Varje persona är en variant av samma flöde.

```
  EXTERN PART                HUBS = MELLANLAGRING (staging, on-prem)              FACKSYSTEM = SLUTLAGRING
  (medborgare, region,   ┌───────────────────────────────────────────────┐      (system of record)
   bank, skola, FK …)    │  1 MOTTAG     2 HANDLÄGG      3 BEARBETA         │
        │                │  säker kanal  triage/frist    möte · signering   │      Treserva / Lifecare
   BankID/Freja/SITHS    │  sdkmc        Deck/Tasks      spreed-itsl        │      Viva / Combine / Cosmic
        │     ───────►   │  securemail   Tables-status   LibreSign          │      Provisum / Aider / e-Wärna
   SDK (AS4) · säker     │  mail/fax     ärenderum       Forms/samtycke     │ ───► W3D3 / Public360° / Ciceron
   e-post · digital fax  │  (Groupfolders + ACL + Retention)                │      Platina / Evolution / LEX
        ◄───────         │            4 FÖR ÖVER  ──────────────────────────┼────► Visma / Heroma / Personec
   kvittens · delgivning │            (registrera, för in, FGS/API)         │      + e-arkiv (Sydarkivera, FGS)
                         │            5 GALLRA i Hubs (Retention, restricted-tagg)
                         └───────────────────────────────────────────────┘
```

**1. Mottag (in i mellanlagringen).** Inkommande via säker kanal: SDK/AS4 (sdkmc), säker e-post
(securemail), digital fax (mail/fax-bryggan), inkommande säkert möte/inbjudan (spreed-itsl), säkert
formulär (Forms). Provenance fångas redan här: *kanal, avsändare (BankID/Freja/SITHS-verifierad LOA),
tidsstämpel, ev. funktionsadress*. Detta blir "var kommer detta ifrån".

**2. Handlägg (triage & frist).** Allt aggregeras i `attHantera` (server-side kanalklassning via
`/ocs/v2.php/apps/sdkmc/api/v1/summary`). Status och frister sätts (Deck/Tasks som datalager, Tables
som strukturerat statusregister). Inget av detta är slutlig sanning — det är *arbete pågår*.

**3. Bearbeta (möte / signering / dokument).** Säkert videomöte (spreed-itsl + BankID/Freja-lobby),
e-underskrift (LibreSign / Inera Underskriftstjänst-API / Sweden Connect-nod → PAdES/PDF/A), samtycke
(Forms + BankID), dokumentyta (ärenderum = Groupfolders + ACL + Collabora/OnlyOffice on-prem).
Resultatet — ett signerat beslut, en utredning, en SIP-plan, en granskad årsräkning — är fortfarande i
mellanlagringen.

**4. För över (in i slutlagringen).** Handläggaren *committar* utfallet till facksystemet. Tre mönster
(se §3): registrering/diarieföring, strukturerad import via API, eller FGS-export till e-arkiv. **Detta
är ögonblicket "var hamnar det".** Här uppstår den allmänna handlingens registrering (OSL 5:1) och
arkivredovisning. Dashboardens jobb: göra steget till **ett klick med förifylld metadata**, och visa
att det är gjort.

**5. Gallra (ut ur mellanlagringen).** När ärendet är överfört och bevarat på rätt ställe, rensas Hubs
mellanlagrade kopia enligt Retention-regel (restricted-tagg, ägarnotis innan radering). Det håller Hubs
transient, minimerar dubbellagrad sekretess (GDPR-dataminimering) och stänger suveränitetsberättelsen:
*ingen permanent skuggdatabas av kommunens känsligaste flöden.*

> **Viktig nyans:** for vissa flöden är Hubs *enda* bäraren av en handling bara en kort stund (ett
> säkert meddelande som omedelbart diarieförs). För andra (ett pågående ärenderum med medborgardelning,
> en flerparts-signering över veckor) är Hubs aktiv arbetsyta länge — men *ändå* mellanlagring: det
> som blir bestående förs över. Skillnaden är **avsikt**, inte varaktighet.

---

## 3. Integrationsmönster mot slutlagringen (hur "för över" faktiskt sker)

Per facksystemsfamilj. Hubs ska inte bygga djupintegration mot alla från dag ett — men dashboarden ska
*modellera destinationen* så att steget alltid är synligt, även när det första leveransläget är manuellt
("öppna förifylld registrering", "exportera FGS-paket").

| Mönster | Hur det fungerar | Facksystem / exempel | Hubs-widget som äger steget |
|---|---|---|---|
| **A. Registrering / diarieföring** | Förifylld metadata (avsändare, inkommen-datum, föreslaget dnr, ärendemening, sekretessmarkering) skickas/klistras in i diariet; handlingen registreras senast nästa arbetsdag (OSL 5:1). | W3D3, Public 360°, Ciceron, Platina, Evolution, LEX. Konkurrerande mönster finns: **Formpipe "Teams for Platina/W3D3"** registrerar dokument/ärenden direkt från Teams — Hubs gör motsvarande från en *sekretesssäker* yta. | `registreraFordela`, `utlamnande` |
| **B. Strukturerad import via öppet API** | Utfallet (beslut, utredning, granskningsresultat) skrivs till facksystemets ärende via dess API/standardgränssnitt. | **Treserva** ("öppna API:er och standardiserade gränssnitt"), **Lifecare/Cosmic**, **Combine/Viva**; integrationslager som **Bitoreq** kopplar mot Lifecare/Treserva/Combine/Viva. **Provisum/Aider/e-Wärna** för överförmyndare (årsräkning/beslut). | `arsrakningar`, `granskningsko`, `rehabarenden` |
| **C. Meddelande-/samverkansflöde** | Kvittenser och samverkansavvikelser går tillbaka som strukturerade meddelanden till motpartens system. | **Lifecare SP** (samordnad vård-/utskrivningsplanering region↔kommun), regionens avvikelsefunktion, SDK org-till-org. HL7/FHIR-interoperabilitet på vårdsidan (Cosmic-migrering pågår i regioner). | `utskrivningsbevakning`, `samverkansavvikelser` |
| **D. FGS-export till e-arkiv** | Avslutat ärende paketeras enligt **FGS Paketstruktur** (SIP) och levereras till e-arkiv/slutarkiv. | **Sydarkivera** (gemensamt e-arkiv, FGS-driver), Riksarkivets FGS. Notera: W3D3-exportpaket krävde ompaketering till korrekt SIP (Twoday) — Hubs bör tala FGS rakt. | `arkivGallring` |
| **E. Utkanal till medborgare** | Beslut/delgivning ut till medborgarens digitala brevlåda (komplement till säker dialog). | Mina meddelanden / Kivra (på sikt; SOU 2024:47). Dialog ≠ massutskick — Hubs äger dialogen. | `kvittenser`, `skickatForSignering` |

**Designprincip för alla fem:** *destinationen modelleras alltid, leveransläget kan vara manuellt
först.* En widget visar "Slutdestination: Treserva — ej överförd" som en **öppen åtgärd**, precis som en
frist. Tom "ej överförd"-kö = allt committat = compliance-värde (registreringsplikten uppfylld).

---

## 4. Hur dashboarden gör mellan-/slutlagring synlig (provenance på varje rad)

Detta är den konkreta UI-konsekvensen — det demon ska visa.

### 4.1 Provenance-modellen: tre koordinater per objekt

Varje rad i en triage-/ärendewidget bär:
1. **Härkomst (var kommer detta ifrån)** — kanalikon + verifierad identitet: *SDK · BankID LOA3*,
   *Säker e-post*, *Digital fax*, *Forms*, *Inkommande från region (Lifecare SP)*. Befintliga widgets
   `attHantera`, `identitetsBadge`, `kvittenser` bär redan delar av detta.
2. **Tillstånd nu (var ligger det medan vi jobbar)** — *Hubs · mellanlagring*, GOV.UK-status
   (Ny/Påbörjad/Väntar/Klar för beslut/Klar). En diskret men genomgående "i er driftmiljö"-markör
   (`dataSuveranitet`) säger *medan det är hos oss är det på er server*.
3. **Slutdestination (var hamnar det)** — målfacksystem + överföringsstatus: *Slutförvaras i: Public
   360° — ej registrerad* / *Förd till Treserva 2026-06-12* / *FGS-levererad till Sydarkivera*. Plus
   Hubs egen rensningscountdown: *Rensas ur Hubs 30 dgr efter överföring*.

### 4.2 Konkreta widget-tillägg (bygger på befintlig katalog, inga nya appar krävs för v1)

- **`registreraFordela` (registrator) — bryggans flaggskepp.** "Card View som öppnar förifyllt
  registreringsformulär … Stänger gapet meddelande↔diarium (integrerar mot, ersätter inte,
  diariesystemet)." Lägg till explicit destinationsrad och post-överförings-status:
  *Inkommen via SDK → föreslaget dnr → Registrera i W3D3 → ✓ registrerad, rensas ur Hubs.*
- **`arenderum` — dubbel retention synlig.** Visa både facksystemets bevarandestatus och Hubs
  rensningscountdown (se §1.3). Knapp "För över till facksystem / Leverera till e-arkiv (FGS)".
- **`attHantera` — provenance-kolumn.** Varje triage-rad får en liten "→ destination"-chip som visar
  vart raden är på väg när den är klar (även om den är tom/"ej satt" än).
- **`arkivGallring` (registrator/förvaltare) — gränssnittet mot slutlagringen.** "Avslutade ärenden med
  gallringsstatus … Leverera till e-arkiv (FGS)." Detta ÄR överlämningen till slutlagring; märk det så.
- **`kvittenser` / `skickatForSignering` — slutlig kvitto-loop.** När delgivning/signering är klar och
  utfallet committat, stäng raden med "Förd till [facksystem]".
- **`dataSuveranitet` — säg det i klartext.** Mikro-copy: *"Hubs är er säkra mellanlagring. Den
  bestående handlingen bevaras i ert ärendesystem. Inget lämnar er driftmiljö."* Det är hela storyn på
  en rad, och svaret på CLOUD Act-/OSL 10:2a-frågan.

### 4.3 Per persona — varifrån → vart

| Persona | Härkomst (in i Hubs) | Bearbetning i Hubs (mellanlagring) | Slutlagring (system of record) |
|---|---|---|---|
| **Socialsekreterare** | Orosanmälan via Forms/SDK/fax (skola/vård/polis/privat); medborgarsvar via BankID | Förhandsbedömning (14 dgr), utredning (4 mån), ärenderum/barn, beslut e-signeras | **Treserva / Lifecare / Viva / Combine** (social journal, BBIC) |
| **Registrator / nämndsekr.** | Allt inkommande (SDK, säker e-post, fax) | Registrera & fördela, nämndcykel, justering/anslag, utlämnande | **W3D3 / Public 360° / Ciceron / Platina / Evolution / LEX** → e-arkiv (FGS, Sydarkivera) |
| **Kommunsjuksköterska (HSL)** | Utskrivnings-/inskrivningsmeddelanden från region (Lifecare SP), säkra meddelanden | Utskrivningsbevakning (betalningsansvar), SIP, samverkansavvikelse | **Treserva HSL / Lifecare / Cosmic**; avvikelse → regionens system |
| **HR / chef (rehab)** | Läkarintyg, FK-kontakt, fackliga, medarbetarsvar via säker kanal/BankID | Känslig inkorg, rehab-rum, plan för återgång (dag 30), signering | **Visma / Heroma / Personec** (personalakt, rehabmodul) |
| **Överförmyndare** | Årsräkning via e-tjänst (Provisum/Aider/e-Wärna), inskannat papper, post | Granskningskö mot 1 mars, komplettering, arvodes-/tillsynsbeslut e-signeras | **Provisum / Aider / e-Wärna** (+ besked till bank org-till-org via SDK) |
| **Förvaltare / IT / infosäk** | Säkerhetshändelser ur Hubs egna kanaler (auth/delning/routing) | Incident-triage, MCF-rapportkedja, logg/spårbarhet, provisionering | **MCF** (incidentrapport), **SIEM** (loggexport), e-arkiv (gallring/FGS) |

---

## 5. Demo-manus (teaching-storyn i tre minuter)

1. **"Var kommer det ifrån?"** Öppna `attHantera`. En orosanmälan kom in via SDK, avsändare verifierad
   med BankID (LOA3), kl. 08:14. Peka: *kanalen och identiteten är bevisad redan vid mottag.*
2. **"Var ligger det nu?"** Klicka raden → den lever i Hubs mellanlagring, status *Under
   förhandsbedömning*, 14-dagars countdown, allt **i er driftmiljö** (visa `dataSuveranitet`-markören).
   *Hubs har inte röjt något till en tredje part — OSL 10:2a är inte ens en fråga.*
3. **"Vad gör vi med det?"** Skapa ärenderum, kalla till säkert SIP-möte, skicka beslut för
   e-underskrift (AES/BankID → PAdES/PDF/A). Allt bearbetas on-prem.
4. **"Var hamnar det till slut?"** Tryck *Registrera & fördela* → förifylld metadata → **Treserva /
   diariet**. Nu är den allmänna handlingen registrerad i tid (OSL 5:1) och kommer arkivredovisas där
   arkivredovisningen finns.
5. **"Och sen?"** Hubs-kopian får en rensningscountdown. *Den bestående sanningen bor i facksystemet;
   Hubs lämnar ingen permanent skuggdatabas av era känsligaste flöden.*

**Engångsmeningen för säljaren:** *"Hubs är kommunens säkra mellanlagring — där det känsliga tas emot,
handläggs och signeras utan att lämna er server — och så för ni över det bestående till ert
ärendesystem, som förblir sanningen. Inte system nummer åtta. Limmet som saknades."*

---

## 6. Risker & nyanser att vara ärlig om i demon

- **"Pågående ärenderum lever länge i Hubs."** Sant — men avsikten är transient: det bestående förs
  över, mellanlagrings-kopian gallras. Skilj på *aktiv arbetsyta* och *arkiv*.
- **Dubbel registrering/dubbellagring.** Risk att samma handling finns i både Hubs och facksystem under
  en period. Hanteras av Retention-rensning + tydlig "förd över"-status (inte "kopierad till").
- **Integrationsmognaden varierar.** Treserva/Lifecare har öppna API:er; vissa diariesystem tar helst
  manuell registrering eller FGS-paket. Därför: *modellera destinationen alltid, automatisera stegvis.*
- **Vem äger handlingen juridiskt medan den är i Hubs?** Myndigheten (Hubs är teknisk bearbetning/
  lagring i egen miljö, OSL 10:2a ej ens aktualiserad on-prem). Viktig att kunna svara klart.
- **Gallringsbeslut för Hubs egen mellanlagring** måste ändå dokumenteras i kommunens DHP som en
  rensningsregel ("arbetsmaterial/mellanlagring rensas efter överföring"). Inte tungt, men inte noll.

---

## Källor

**System of record — svenska facksystem (slutlagring)**
- Treserva, öppna API:er och standardgränssnitt, SoL/LSS/HSL + nya socialtjänstlagen 2025 — https://www.cgi.com/se/sv/treserva
- Treserva Hälsoärende (HSL) — https://www.cgi.com/se/sv/treserva/treserva-halsoarende
- Jämförelse verksamhetssystem socialtjänst (Lifecare/Treserva/Combine/Viva) + Bitoreq-integration — https://bitoreq.se/experttjanster/tietoevry-cgi-jamforelse
- Lifecare SP — samordnad vård-/utskrivningsplanering region↔kommun (Region Halland) — https://vardgivare.regionhalland.se/tjanster-it-stod/it-stod-och-system/lifecare/
- Lifecare SP, Region Västernorrland (utskrivning/SIP, region↔7 kommuner) — https://www.rvn.se/sv/delplatser/Vardgivare/Vardgivarwebb/administration-och-stod/lifecare-sp---stod-for-sammanhallen-vardplanering/
- FVIS / Cosmic ersätter Lifecare (Region Halland, pågående migrering) — https://fvis.regionhalland.se/arbetet-infor-fvis/vagen-till-ett-nytt-vardinformationsstod/inforandeplanering/
- Provisum — verksamhetssystem för överförmyndarförvaltningen (+ e-tjänst för ställföreträdare) — https://www.provisum.se/
- Provisum via Sambruk — https://sambruk.se/provisum-overformyndarens-verksamhetsstod/
- Aider Tillsyn (överförmyndare, lansering 2025) — https://support.aider.nu/sv/articles/6884612-overformyndare-och-aider
- e-Wärna / Mitt Wärna (digital årsräkning, ställföreträdare) — https://docplayer.se/10051597-Anvandarhandbok-e-warna-stallforetradare.html
- W3D3 / Platina (Formpipe ECM, diarie-/ärende-/dokumenthantering) — https://www.formpipe.com/products/teams-platina-w3d3/
- Formpipe "Teams for Platina/W3D3" (registrera ärenden/dokument från kommunikationsyta — konkurrerande mönster) — https://www.formpipe.com/products/teams-platina-w3d3/
- Public 360° (Software Innovation/Tietoevry ECM) — https://www.mkse.com/affarssystem-dokumenthantering/public-360
- Ciceron e-arkiv & tilläggstjänster — https://www.ciceron.nu/e-arkiv/tillaggstjanster
- W3D3-exportpaket → FGS SIP ompaketering (Twoday) — https://www.contentbysigma.se/sv-SE/ServicesAndProducts/SubPages/Dokumentsystem

**Mellanlagring/staging, registreringsplikt & integration (juridik + SDK)**
- OSL 5:1 — registrering senast påföljande arbetsdag (Skatteverket rättslig vägledning 2025) — https://www4.skatteverket.se/rattsligvagledning/edition/2025.1/329083.html
- Diarieföring / registrering av allmänna handlingar (allmanhandling.se) — https://allmanhandling.se/registrering-av-handlingar/
- Legala handboken — registrering och diarieföring (OSL 5, JO 3579-05) — http://www.legalahandboken.se/offentlighet/regler_reg.html
- eSam — allmänna handlingar i AI-utveckling / mellanlagring (2025-06-17) — https://www.esamverka.se/publikationer/juridik/2025-06-17-allmanna-handlingar-i-ai-utveckling.html
- eSam ES2023-06, Utkontraktering — sekretess och dataskydd (OSL 10:2a-bedömning) — https://www.esamverka.se/download/18.43a3add4188b9f2345a2fe78/1687332814480/ES2023-06%20V%C3%A4gledning%20Utkontraktering%20-%20sekretess%20och%20dataskydd.pdf
- Digg — Säker digital kommunikation (SDK), vidareförmedling in i verksamhetssystem — https://www.digg.se/saker-digital-kommunikation
- SKR — Säker digital kommunikation (SDK), integration med befintliga system — https://skr.se/skr/naringslivarbetedigitalisering/digitalisering/digitalinfrastruktur/sakerdigitalkommunikationsdk.13701.html
- CGI — Vad är SDK? (säker vidareförmedling till underliggande verksamhetssystem) — https://www.cgi.com/se/sv/blogg/offentlig-sektor/saker-digital-kommunikation-SDK-vad-ar-det
- ITSL — SDK för snabb och trygg kontakt med kommun och myndighet — https://itsl.se/secure-digital-communication/

**Arkiv, gallring, FGS, e-arkiv (slutlagringens regler)**
- Riksarkivet — uppdaterad arkivförordning 1 aug 2024 (export + radering före införande) — https://riksarkivet.se/inlagg/uppdaterad-arkivforordning-forbattrad-digital-hantering-och-tydligare-mandat
- Riksarkivet — FGS för e-arkiv — https://riksarkivet.se/fgs-earkiv
- Sydarkivera — FGS Paketstruktur (wiki) — https://wiki.sydarkivera.se/wiki/FGS_Paketstruktur
- Digg/Ena — bevarande- och gallringsregler — https://www.digg.se/styrning-och-samordning/ena---sveriges-digitala-infrastruktur/byggblock/sparbarhet/ramverk-loggning-och-sparbarhet/lagkrav/bevarande--och-gallringsregler
- Nextcloud Files Retention (mellanlagringens rensningsregel, restricted-tagg) — https://docs.nextcloud.com/server/stable/admin_manual/file_workflows/retention.html

**Sekretess, kryptering, identitet, NIS2 (gäller medan datan är i mellanlagringen)**
- Socialstyrelsen HSLF-FS 2016:40 — kryptering + stark autentisering vid kommunikation över öppna nät — https://www.socialstyrelsen.se/kunskapsstod-och-regler/regler-och-riktlinjer/juridiskt-stod-for-dokumentation/kommunicera-over-internet-eller-andra-oppna-nat/
- Digg — tillitsnivåer för e-legitimering (LOA, BankID/Freja/SITHS) — https://www.digg.se/digitala-tjanster/e-legitimering/om-e-legitimering/tillitsnivaer-for-e-legitimering
- Cybersäkerhetslag (2025:1506), i kraft 15 jan 2026 — https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/cybersakerhetslag-20251506_sfs-2025-1506/
- Digg — Bilaga för IT-säkerhet inom SDK (logg 12 mån, AS4, ej meddelandeinnehåll) — https://www.digg.se/saker-digital-kommunikation/sdk-for-deltagarorganisationer/anslutningsavtal-regelverk-samt-bilagor/regelverk-for-deltagarorganisationer-inom-sdk/bilaga-for-it-sakerhet-inom-sdk

**Interna underlag (samma analyspaket)**
- `analysis-output/extended/research-filer.md` (ärenderum/Groupfolders/Retention; FGS/Sydarkivera; arkivförordningen 2024)
- `analysis-output/extended/research-compliance-nis2.md` (OSL 10:2a; SDK-logg 12 mån; MCF; datasuveränitet)
- `analysis-output/extended/research-utskrivning-hsl.md` (Lifecare SP; utskrivning; samverkansavvikelser)
- `hubs_start/docs/PERSONA-DASHBOARD-SPEC.md` + `hubs_start/src/services/personaConfig.js` (widgetkatalog, 6 personas, "integrerar mot — ersätter inte"-principen)
