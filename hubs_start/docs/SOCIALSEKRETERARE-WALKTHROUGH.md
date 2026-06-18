<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Socialsekreterarens ärende — end-to-end: från orosanmälan till arkiverat beslut

> **Vad detta är:** den sammanhållna huvudberättelsen för en socialsekreterares (barn & familj) hela
> ärendelivscykel, hopvävd ur de fem detaljerade delflödena (Walkthrough 1–5). Den följer **ett** ärende —
> Barn 2026-0412, dnr `2026-IFO-1234` — kronologiskt från att en orosanmälan landar i funktionsbrevlådan,
> via triage, skyddsbedömning, förhandsbedömning, aktualisering, utredning, säkert möte med transkribering,
> beslut, e-signering, delgivning, bevakning och slutligen gallring av Hubs-kopian medan originalet bevaras
> i facksystemet och e-arkivet.
>
> **Persona:** `socialsekreterare` · **System of record (slutlagring):** Treserva (CGI) / Lifecare
> (Tietoevry) / Viva / Combine (Pulsen) — socialakten/BBIC-journalen → e-arkiv (Sydarkivera, FGS).
> **Plattform:** server v32 (Hub 25 Autumn). **Datum:** 2026-06-14.
>
> **Bärande arkitektur (genomgående):** Hubs är **mellanlagring (mellanarkiv)** — den sekretesssäkra,
> on-prem arbetsytan. **Slutlagring (system of record)** är alltid socialtjänstens verksamhetssystem; en
> bevarandepliktig allmän handling får aldrig bli *enbart* en Hubs-fil. Varje steg bär samma fyrdelade
> struktur: **Handläggaren** (exakt åtgärd, var) · **I Hubs** (NC-app/route/status, mellanlagring) · **I
> facksystemet** (slutlagring + handoff-mönster) · **Data** (riktning, sekretess/LOA, gallring).
>
> **Handoff-mönster:** **A** = API/REST (Ena REST-profil) · **B** = drag-to-case (registrera i facksystem) ·
> **C** = FGS-export till e-arkiv · **D** = manuell ("Markera som överförd", dag-1-fallback).
>
> **Brand-regel:** i produkt-/UI-text säger vi aldrig "Nextcloud"/"Talk"; i detta interna underlag namnger vi
> app-id (sdkmc, securemail, groupfolders, deck, tasks, calendar, spreed-itsl, collectives, forms, libresign,
> stt_whisper2, llm2, files_retention …) för att kunna wire:a.
>
> **De juridiska klockorna i berättelsen:** omedelbar skyddsbedömning samma dag/senast dagen efter (11 kap.
> 1 a § SoL); förhandsbedömning ≤ 14 dagar; registreringsplikt senast nästa arbetsdag (OSL 5:1); utredning
> ≤ 4 månader (11 kap. 2 § SoL 2025:400); överklagandefrist 3 veckor (FL 44 §); FL 6-mån/4-veckors-regeln
> (FL 11–12 §§). Socialtjänstsekretess genomgående (OSL 26 kap.).

---

## Akt I — Inflöde, triage och aktualisering (Walkthrough 1)

### Steg 1 — Orosanmälan landar i funktionsbrevlådan
- **Handläggaren:** ingen åtgärd än — systemhändelse. En anmälare utanför Hubs har skickat (skola via
  SDK/securemail, polis/region via SDK, privat utförare via digital fax).
- **I Hubs:** meddelandet tas emot på SDK-accesspunkten (AS4) och routas till funktionsadressen
  `orosanmalan@kommunen`. Det blir ett sdkmc-objekt med provenance fångad vid mottag: **kanal**, **avsändarens
  verifierade identitet + LOA**, **tidsstämpel**, **funktionsadress**. Route: `sdkmc` summary-endpoint
  `/ocs/v2.php/apps/sdkmc/api/v1/summary` → `/apps/sdkmc/?mailbox=orosanmalan@…`. Status: **Oläst ·
  Otilldelad**. En **AS4-kvittens** går tillbaka till avsändaren.
- **I facksystemet:** inget ännu — committas i Steg 9.
- **Data:** riktning **IN** (extern → Hubs). Orosanmälan + ev. PDF-bilagor. Presumtivt **OSL 26 kap.** (extra
  känsligt — barn). Avsändarens LOA loggas. Hubs-kopian får rensningsregel som aktiveras *efter* överföring.

### Steg 2 — Anmälan syns i triagekön (morgontriage)
- **Handläggaren:** Anna loggar in (Freja eID Plus, **LOA3**) och öppnar **`attHantera`** ("Att hantera") —
  den aggregerade triagekön, inte en mejlinkorg. Översta strip: *"2 förhandsbedömningar förfaller inom 3 dagar
  · 1 utredning klar för beslut."* Den nya anmälan syns som rad med **kanalikon**, **oläst-markör** och
  **destinations-chip** ("→ Treserva — ej registrerad"). Parallellt i den dedikerade kön **`orosanmalningar`**.
- **I Hubs:** `attHantera` renderar summary-endpointens kanalklassade rad; `orosanmalningar` speglar objektet i
  ett **Tables-register** (status/frist/källa/kanal) — status **Ny**. Route: `/apps/tables/#/table/{tableId}`
  (renderas som widget, aldrig rå tabell).
- **I facksystemet:** inget ännu.
- **Data:** **internt i Hubs**. Default radtext = **ärendereferens/metadata**, inte klartextcitat (GDPR art. 5
  dataminimering). Vyn visar bara rubrik/avsändare/antal för den som har behörighet till `orosanmalan@`.

### Steg 3 — Omedelbar skyddsbedömning (samma dag)
- **Handläggaren:** öppnar anmälan och gör — eller säkerställer att mottagningsgruppen gör — en **omedelbar
  skyddsbedömning** (behöver barnet *omedelbart* skydd?). Lagstadgat moment, **samma dag/senast dagen efter**
  (11 kap. 1 a § SoL), **måste dokumenteras**.
- **I Hubs:** dokumenteras som kort, mallstyrd notering — i ett snabbt **Forms**-internled eller direkt i ett
  tidigt **ärenderum**. Status i `orosanmalningar` → **Under förhandsbedömning**.
- **I facksystemet:** i de flesta kommuner sker skyddsbedömningen och dess dokumentation **direkt i
  Treserva/Lifecare** (dokumentationspliktigt myndighetsmoment) — då är facksystemet system of record redan här
  (mönster **D/A**) och Hubs roll krymper till kanal/mellanlagring för inkommandet.
- **Data:** **IN → dokumenteras**. OSL 26 kap. Skyddsbedömningstexten är journalnotat → hör hemma i
  facksystemet, inte enbart i Hubs. *(Se GAP-001 — den känsligaste luckan: var committas detta "officiellt"?)*

### Steg 4 — Plocka / tilldela ärendet
- **Handläggaren:** ur **`funktionsbrevlador`** (eller direkt i `orosanmalningar`) **plockar** Anna anmälan —
  knapp **"Ta/plocka ärendet"** — eller gruppledaren fördelar den på morgonmötet. Otilldelat syns för hela
  enheten tills någon tar ansvar.
- **I Hubs:** objektet får **tilldelad handläggare** (assignee). **14-dagars förhandsbedömnings-countdown** ska
  vara bunden till **inkom-datum (Steg 1)** — *inte* tilldelning (se GAP-002). Bevakning skapas i **Deck**
  (delad board: `bevakningar`) + ev. **Tasks/VTODO** (personlig påminnelse T-7/T-3/T-0, bara till tilldelad).
  Route: `/apps/deck/board/{boardId}/card/{cardId}`. Status → **Under förhandsbedömning · tilldelad NN**. ACL
  snävas: tilldelad skriver, gruppledare läser.
- **I facksystemet:** inget formellt ännu — men flera kommuner registrerar **aktualiseringen redan här**.
- **Data:** **internt** (tilldelning). Loggas (Activity/sdkmc-logg) för spårbarhet. Bevakningskortet är
  arbetsnotering (gallras), inte allmän handling.

### Steg 5 — (Parallellt) kontroll av tidigare aktualiseringar i facksystemet
- **Handläggaren:** öppnar **Treserva/Lifecare i ett parallellt fönster** (separat inloggning, SITHS):
  finns barnet/familjen sedan tidigare? Tidigare anmälningar, pågående insatser, LVU-historik?
- **I Hubs:** ingen data hämtas in — Hubs läser inte facksystemets journal. På sin höjd en
  **deep-link/destinations-chip** mot facksystemets sökvy.
- **I facksystemet:** **läsning** i Treserva/Lifecare — facksystemet äger historiken (skulle vara **mönster A**
  om Hubs hade läs-API).
- **Data:** **läs i facksystemet** (utanför Hubs). OSL 26 kap.; åtkomst styrs av facksystemets behörighet.
  *(Se GAP-009 — sker helt utanför Hubs idag.)*

### Steg 6 — Skapa ärenderum (säker dokumentyta)
- **Handläggaren:** klickar **"Skapa ärenderum"**. Ett rum per barn/dnr-token; anmälan + bilagor läggs där;
  första BBIC-/förhandsbedömningsnotering påbörjas (mall ur `kunskapsbank`).
- **I Hubs:** orkestrerar en **Groupfolder** med **ACL** (tilldelad: write; gruppledare: read),
  **versionshantering** och en **restricted retention-tagg** (rensning *efter* överföring). Route:
  `/apps/files/?dir=/{arenderum}` · `/f/{fileId}`; API `/ocs/v2.php/apps/groupfolders/folders`. Samredigering
  on-prem via Collabora/OnlyOffice (WOPI). Status `arenderum` → **Påbörjad**.
- **I facksystemet:** inget — ärenderummet är *arbetslager*, inte socialakten.
- **Data:** **internt**. OSL 26 kap.; **least-permission ACL** är säkerhetskontrollen. **Dubbel countdown**
  (facksystemets bevarande + Hubs-rensning). *(Se GAP-005 — dnr ↔ Hubs-token-mappning.)*

### Steg 7 — Förhandsbedömning: tillåtna kontakter inom ramen
- **Handläggaren:** under förhandsbedömningen får **endast** vårdnadshavare, anmälaren och barnet kontaktas
  (inga utomstående uppgiftsinhämtningar — det får ske först i utredning). Kontakt via **säkert meddelande**
  (securemail + BankID-länk; SDK till myndighet) eller säkert samtal. Noteringar förs i ärenderummet.
- **I Hubs:** utgående via `attHantera` → sdkmc/securemail; svar/uppladdningar mellanlagras i ärenderummet.
  `kvittenser` visar leveranstidslinje. Knapp **"Skapa bevakning från meddelande"**.
- **I facksystemet:** inget ännu — journalpliktiga kontakter hör hemma i facksystemet vid/efter aktualisering.
- **Data:** **UT och IN** (tvåvägsdialog). OSL 26 kap.; identitets-badge per motpart. *(Se GAP-006 — ingen
  systemspärr mot att kontakta fel part under fasen.)*

### Steg 8 — Beslut: inleda / inte inleda utredning (inom 14 dgr)
- **Handläggaren:** fattar och dokumenterar beslut **"inleda utredning"** eller **"inte inleda"** inom
  14-dagarsfristen. Per SKR:s riskmodell: lågrisk **godkänns** (loggat, ingen signatur); annars **skickas för
  underskrift** (AES). I `bevakningar` växlar fristfärgen grå→gul→röd mot dag 14.
- **I Hubs:** beslutsdokumentet skapas/samredigeras i ärenderummet. Signering (om aktuell) via `attSignera` →
  signeringsadapter. Status `orosanmalningar` → **Beslut inleda** / **Beslut ej inleda**.
- **I facksystemet:** beslutet är ett **rättsligt myndighetsbeslut** och **måste** committas. "Inleda" → ett
  utredningsärende (4-månadersfrist startar, se Akt II). "Inte inleda" → avslutas; anmälan + beslut bevaras
  enligt plan. Mönster **D** dag 1, **B/A** där konnektor finns.
- **Data:** **klart för överföring**. OSL 26 kap. Beslut + anmälan = **bevarandepliktiga**.

### Steg 9 — Aktualisering: registrera/för över till facksystemet
- **Handläggaren:** **för över** anmälan + förhandsbedömning/beslut + provenance-metadata till
  **Treserva/Lifecare/Viva/Combine** — detta är **aktualiseringen** och uppfyller **registreringsplikten**
  (OSL 5:1, normalt senast nästa arbetsdag).
- **I Hubs:** **mönster B (drag-to-case):** ett **förifyllt registreringsformulär** (avsändare/inkom-datum/
  föreslaget dnr/ärendemening/sekretess) POST:as till facksystemet. **Mönster A (API/REST)** hos storkund.
  **Mönster D** (ladda ner + "Markera som överförd") som dag-1-fallback. Provenance-chip flippar: "→ Treserva
  — ej registrerad" → "Registrerad i Treserva, dnr 2026-IFO-1234". Hubs-token ↔ dnr mappas.
- **I facksystemet:** **aktualisering skapas** — anmälan blir inkommen allmän handling i akten,
  förhandsbedömning/beslut journalförs, bevarande-/gallringsregel sätts enligt dokumenthanteringsplanen.
  **Detta är ögonblicket "var hamnar det".**
- **Data:** **UT** (Hubs → facksystem). OSL 26 kap. följer med. Facksystemet äger nu bevarandet; Hubs-kopian
  får **rensningscountdown**. *(Se GAP-003 — dubbel/oklar tidpunkt; GAP-004 — mönster A overifierad.)*

### Steg 10 — Stäng loopen: kvittens, bevakning, gallring av Hubs-kopian
- **Handläggaren:** klarmarkerar bevakningen → väljer **"för till ärendet/facksystemet"** (allmän handling)
  eller **"gallra (personlig notering)"**. Anmälaren återkopplas via säker kanal i den mån sekretess tillåter.
- **I Hubs:** bevakningskortet stängs; status → **Klar (överförd)**. **Files Retention** aktiverar
  rensningsregeln ("Rensas ur Hubs 30 dgr efter överföring", ägarnotis före radering). `kvittenser` bevarar
  leveransbeviset tills det följt med handlingen.
- **I facksystemet:** ärendet lever vidare (utredning eller avslutad aktualisering); vid avslut → **FGS-export
  till e-arkiv (Sydarkivera), mönster C**.
- **Data:** **gallring i Hubs / bevarande i facksystem**. Rensningen minimerar dubbellagrad sekretess.
  *(Se GAP-007 — gallring vilar på manuell "Markera som överförd"; GAP-008 — gallringsregel i DHP.)*

> **Övergång Akt I → II:** beslut "inleda utredning" är fattat; raden i `orosanmalningar` går *Under
> förhandsbedömning* → *Beslut inleda*; en **aktualisering med dnr 2026-IFO-1234** finns i Treserva. Nu öppnas
> arbetslagret *runt* det Treserva-ärendet under utredningstiden.

---

## Akt II — Utredning i ärenderummet (Walkthrough 2)

### Steg 11 — Öppna/återanvänd ärenderummet för barnet
- **Handläggaren:** klickar **`arenderum`** ("Mina ärenderum") → kortet för Barn 2026-0412 → "Öppna
  ärenderum". Rummet skapades vid förhandsbedömningen (Steg 6); nu *återanvänds* det och växlar till
  utredningsläge.
- **I Hubs:** Groupfolder per dnr/barn (`/{ärenderum}/2026-IFO-1234-BarnNN/`) via `groupfolders`; öppnas via
  `/apps/files/?dir=/2026-IFO-1234-BarnNN`. Status: *Påbörjad*. Hubs **orkestrerar** (ACL, taggar, struktur).
- **I facksystemet:** inget nytt — Treserva-ärendet finns redan; rummet *speglar* dnr men är inte akten.
- **Data:** ingen extern rörelse; ärver sekretessmarkering (OSL 26 kap.). LOA3-session. *(Se GAP-010 —
  "ett klick → rum + ACL + tagg + struktur"-orkestreringen är inte färdigbyggd.)*

### Steg 12 — Sätt ACL (behörighet = säkerhetsgräns, least permission)
- **Handläggaren:** ser (konfigurerar normalt inte själv) behörigheten: hon = skriv; gruppledare/2:a-handläggare
  = läs; resten av enheten = ingen åtkomst.
- **I Hubs:** **Advanced ACL** på Groupfolder (allow/deny per användare/grupp/Team). Default = **least
  permission**. Aktivitet loggas i `activity`.
- **I facksystemet:** inget — Treservas rollmodell är separat; Hubs-ACL gäller bara mellanlagrings-kopian.
- **Data:** ingen rörelse. ACL = OSL-säkerhetsgräns (kollega utan behörighet ser inte ens att rummet finns).

### Steg 13 — Lägg BBIC-strukturen i rummet (mallar ur kunskapsbanken)
- **Handläggaren:** öppnar **`kunskapsbank`** (Collectives) → "BBIC — utredningsmall barn" → instansierar
  mappstruktur + dokumentmallar (utredningsplan, barnets behov/föräldrarnas förmåga/familj & miljö, bedömning).
- **I Hubs:** mallar bor i **`collectives`** (`/apps/collectives/{collective}/{page}`); kopieras in som tomma
  arbetsdokument (`.docx`/`.odt`) i Groupfoldern enligt BBIC:s tre sidor + "Inhämtade uppgifter".
- **I facksystemet:** inget ännu — Treservas BBIC-journal fylls i Steg 19–20.
- **Data:** internt mallmaterial (ingen personuppgift förrän text skrivs). *(Se GAP-011 — BBIC kan kräva
  licens; GAP-012 — skrivs utredningen i Hubs eller direkt i Treserva?)*

### Steg 14 — Samla in handlingar (inkommande säkra filer → rummet)
- **Handläggaren:** i `attHantera`/`kvittenser` ser hon inkommande svar (BUP, skola, region) på begäran om
  uppgifter och **sparar bilagan till ärenderummet** ("Spara i ärenderum → 2026-IFO-1234").
- **I Hubs:** bilagan flyttas från sdkmc-lagret in i Groupfoldern, undermapp "Inhämtade uppgifter".
  Versionshantering (`files_versions`) aktiv. Provenance fångas (kanal SDK, BankID/SITHS-verifierad avsändare,
  tidsstämpel). `senasteFiler` visar "Skola laddade upp X · 2026-06-14".
- **I facksystemet:** inget ännu — men varje inhämtad uppgift är potentiellt **allmän handling** som ska
  journalföras (dokumentationsskyldighet, SoL 2025:400). Provenance: "→ Treserva — ej registrerad".
- **Data:** **IN** (extern part → Hubs). OSL 26 kap.; HSLF-FS 2016:40 om HSL-nära (BUP). *(Se GAP-013 —
  fasvalidering saknas: widgeten skiljer inte förhandsbedömnings- vs utredningsfas.)*

### Steg 15 — Samredigera utredningsdokumentet on-prem (Collabora)
- **Handläggaren:** öppnar utredningsdokumentet → on-prem kontorssvit (Collabora/Nextcloud Office). Hon och
  ev. medhandläggare skriver samtidigt; texten struktureras enligt BBIC. Inga uppgifter lämnar driftmiljön.
- **I Hubs:** Collabora/OnlyOffice över **WOPI** mot filen i Groupfoldern. Varje sparning → **version**
  (`files_versions`). Ingen molntjänst; suveränitet bevarad (svar på OSL 10:2a).
- **I facksystemet:** inget — utkastet lever i mellanlagringen.
- **Data:** internt arbetsmaterial. Versioner = spårbarhet. Utredningstexten innehåller känsliga
  personuppgifter, skyddad av ACL + on-prem. *(Se GAP-014 — Collabora/WOPI är Nivå 2-backendberoende.)*

### Steg 16 — Versionshantering & utkastdisciplin
- **Handläggaren:** öppnar vid behov "Versioner", jämför/återställer, låser ett avsnitt som klart.
- **I Hubs:** `files_versions` (core/Groupfolders) — automatisk versionering, papperskorg, återställning.
- **I facksystemet:** inget. Hubs-versionerna är arbetsmaterialets historik och **gallras med rummet**.
- **Data:** internt; versionshistoriken är inte i sig allmän handling (det är *slutversionen* som blir
  handling). *(Se GAP-015 — gallringsregel för Hubs-versioner måste förankras i DHP.)*

### Steg 17 — Inhämta samtycke (Forms + signeringssteg)
- **Handläggaren:** skickar ett **säkert samtyckesformulär** till vårdnadshavaren (inhämta/utbyta uppgifter
  med skola/BUP/region, ev. inför SIP) via `mallarSamtycke` (Forms). Vårdnadshavaren fyller i och bekräftar med
  BankID-loggat steg.
- **I Hubs:** `forms` (`/apps/forms/{hash}`) + identitetslager (LOA3) framför den publika länken. Det ifyllda,
  BankID-bekräftade samtycket arkiveras som **handling i ärenderummet**.
- **I facksystemet:** committas senare (B/A) med övriga utredningshandlingar.
- **Data:** **IN** (vårdnadshavare → Hubs). LOA3. Samtycke bryter sekretess för det specifika utbytet (men
  HSLF-FS 2016:40 kan inte avtalas bort). *(Se GAP-016 — Forms saknar native fildropp/förgrening + BankID-bro.)*

### Steg 18 — Dela utvalda handlingar säkert med vårdnadshavaren (kommunicering, läskvittens)
- **Handläggaren:** inför kommuniceringen (partsinsyn FL 25 §) väljer hon **utvalda** handlingar — **inte** hela
  rummet — och delar dem säkert. Knapp i `arenderum`: "Dela utvalda handlingar säkert".
- **I Hubs:** två vägar — säker delningslänk (Files-share) bakom LOA3/BankID, eller bilagor via säkert
  meddelande (sdkmc/securemail + BankID-länk). Mottagaren legitimerar sig med BankID → **läskvittens** i
  `kvittenser` (Skickad → Levererad → Notis öppnad → Inloggad LOA3 → Läst).
- **I facksystemet:** kommuniceringen (delgivna handlingar + kvittens) journalförs i Treserva-akten.
- **Data:** **UT** (Hubs → vårdnadshavare). Bara *utvalda* handlingar; uppgifter om tredje man kan behöva
  maskeras (partsinsyn ≠ rätt till allt). *(Se GAP-017 — maskering/sekretessprövning saknas; flödets
  allvarligaste felrisk.)*

### Steg 19 — Bevaka 4-månadersfristen (och ev. förlängning)
- **Handläggaren:** ser i `bevakningar` utredningens **4-månadersfrist** med eskaleringsfärg och påminnelse
  T-7/T-3/T-0. Vid särskilda skäl: dokumenterar **beslut om förlängning** (nytt beslut, ny frist).
- **I Hubs:** `bevakningar` på `deck` + `minaUppgifter`/`tasks` (VTODO VALARM). Fristen härleds ur
  utredningsstartdatum (11 kap. 2 § SoL: ≤ 4 mån, skyndsamt). Påminnelse *bara till tilldelad*.
- **I facksystemet:** **Treserva/Lifecare äger den formella fristbevakningen** (rödmarkerar passerat datum,
  varnar X dgr före). Hubs **dubblerar inte**. *(Se GAP-018 — dubbelbevakning: Hubs räknar egen frist utan
  läskonnektor → kan divergera från Treserva.)*

### Steg 20 — Färdigställ utredningstexten → committa BBIC-journalen till Treserva
- **Handläggaren:** när utredningen är klar **för hon över** utredningstexten/BBIC-journalen till
  **Treserva-akten**. I `arenderum` markerar hon "Förd till facksystemet".
- **I Hubs:** överföringsstatus sätts ("Förd till Treserva 2026-06-14"); provenance: "Registrerad i Treserva,
  dnr 2026-IFO-1234". Slutversionen committas; utkasthistoriken gallras.
- **I facksystemet:** utredningen/BBIC-journalanteckningen **skapas i Treserva-akten** — här får den allmänna
  handlingen sin arkivredovisning. Mönster **B** (standard); **A** (storkund); **D** (dag-1-fallback).
- **Data:** **UT** (Hubs → Treserva). Här uppfylls dokumentationsskyldigheten (SoL 2025:400) och
  registrerings-/journalföringsplikten. *(Se GAP-012/GAP-019 — den största icke-byggda biten: ingen färdig
  Treserva-konnektor; "Förd över" är dag 1 en manuell sanningsmarkering.)*

### Steg 21 — Knyt övriga handlingar till akten (bilagor, samtycke, kvittenser)
- **Handläggaren:** för även över de **bevarandepliktiga bilagorna** (inhämtade uppgifter som blev del av
  beslutsunderlaget, det signerade samtycket, kommuniceringskvittensen) — eller bekräftar att de följer med.
- **I Hubs:** varje fil får överföringsstatus; `kvittenser`/`activity` levererar bevisen som metadata. Rena
  arbetsutkast/dubbletter markeras för gallring.
- **I facksystemet:** bilagorna/kvittensbevisen **bevaras med utredningen** (mönster B/A/D). Treserva→e-arkiv
  (FGS) vid akttömning.
- **Data:** **UT**. Allmänna handlingar bevaras; arbetsmaterial gallras. *(Se GAP-020 — gallringsbedömningen
  per handling är manuell utan stöd.)*

### Steg 22 — Gallra Hubs-kopian (dubbel countdown) efter bekräftad överföring
- **Handläggaren:** behöver normalt inget göra — gallringen är automatisk. På `arenderum`-kortet ser hon
  **dubbel countdown**: facksystemets bevarande + Hubs egen rensning.
- **I Hubs:** `files_retention` gallrar Groupfoldern baserat på **restricted-tagg + tidsregel** (ägarnotis
  före radering). `arkivGallring`/FGS-byggare (E-ARK CSIP/SIP) för de fall ett original ska till e-arkiv.
- **I facksystemet:** originalet bevaras i Treserva-akten och i slutänden i **e-arkiv (Sydarkivera, FGS)**.
- **Data:** radering i Hubs. *(Se GAP-007 — Retention triggar på tagg+tid, inte på verifierad commit; risk
  att original gallras för tidigt.)*

---

## Akt III — Säkert videomöte, inspelning, transkribering, AI-utkast (Walkthrough 3)

### Steg 23 — Skapa bokningsbar tid och kalla vårdnadshavaren
- **Handläggaren:** i sidopanelens **`dagensMoten`** / primäråtgärden **"Kalla till säkert möte"** väljer hon
  "Skapa bokningstid" → `bokningsbaraTider` (Calendar Appointments): längd 45 min, buffert. Kopplar tiden till
  barnets dnr och skickar bokningslänken via **säker e-post + BankID-länk** (securemail).
- **I Hubs:** `calendar` skapar bokningskonfiguration (`/apps/calendar/appointment/{userId}/{configToken}`).
  Vid bokning skapas CalDAV-händelse, och **eftersom spreed-itsl är installerat skapas automatiskt ett unikt
  mötesrum** (`/call/{token}`). Status på `dagensMoten`-raden: *Bokad*.
- **I facksystemet:** inget — mötet är transit.
- **Data:** **UT** (kallelse) via securemail, LOA3-länk; ärendereferens, inte personuppgifter i klartext.
  *(Se GAP-021 — publik bokningssida oautentiserad; GAP-022 — auto-Talk-rum fork-/versionkänsligt.)*

### Steg 24 — Samtycke till mötet och till inspelning inhämtas i förväg
- **Handläggaren:** skickar ett **samtyckesformulär** (`mallarSamtycke`) som täcker både (a) säkert möte/SIP
  och (b) **uttryckligt samtycke till inspelning + automatisk transkribering/AI-sammanfattning**.
  Vårdnadshavaren bekräftar med **BankID-loggat signeringssteg**.
- **I Hubs:** `forms` fångar svaret; signeringssteg via `libresign` (lågrisk/SES, BankID framför inloggningen)
  eller Inera för starkare bevis. Signerat samtycke arkiveras i ärenderummet.
- **I facksystemet:** committas i Steg 33; samtycket är en allmän handling.
- **Data:** **IN** (samtycke), BankID/LOA3, tidsstämplat. OSL 26 kap. *(Se GAP-023 — samtycke ≠ rättslig grund
  och häver inte sekretess; rättslig grund är myndighetsutövning.)*

### Steg 25 — Anslut mötet och släpp in via BankID-lobby
- **Handläggaren:** öppnar `dagensMoten` → **en-klicks-anslut**. I lobbyn: "1 i väntrum — vårdnadshavare,
  BankID/LOA3 verifierad". Hon **släpper in** deltagaren manuellt.
- **I Hubs:** spreed-itsl, lobby-state `1`; per-deltagare-insläpp. Identiteten bärs av lobbyns completionData
  (BankID/Freja via ID-core) → `identitetsBadge`. SMS-OTP är **spärrad** för LOA3 (synligt spärrtillstånd).
- **I facksystemet:** inget — transit.
- **Data:** identitetsbevis (metod + LOA + tidpunkt). HSLF-FS 2016:40: krypterad kanal + stark autentisering
  uppfyllt. *(Se GAP-024 — BankID-lobby är ITSL:s spreed-itsl-fork, inte native på ren v32.)*

### Steg 26 — Samtycke i rummet bekräftas, inspelningen startar
- **Handläggaren:** Anna informerar muntligt att mötet spelas in. Eftersom `recording_consent` är **påtvingat**
  måste **varje deltagare kryssa i samtycke innan de släpps in i samtalet**, och alla ser en **synlig notis**
  om att inspelning pågår. Anna trycker **Starta inspelning**.
- **I Hubs:** capability `recording-consent` aktiv; samtyckes-tidsstämpel loggas (Activity + SDK-logg).
  Recording-state `3 → 1`. Recording server joinar som osynlig browser via HPB.
- **I facksystemet:** inget — inspelningen är transient mellanlagring.
- **Data:** ljud+video fångas on-prem. **0 tredjelandsöverföringar.** *(Se GAP-025 — recording server är den
  sköraste komponenten; egen maskin, browser-baserad.)*

### Steg 27 — Mötet genomförs; ev. stödanteckning samredigeras live
- **Handläggaren:** håller samtalet, ställer BBIC-relevanta frågor; korta stödord i ett dokument i rummet —
  men **det formella underlaget blir transkriptet + den godkända anteckningen**, inte live-klottret.
- **I Hubs:** Collabora/OnlyOffice (WOPI) över Groupfolder; versionshantering på. Ingen live-textremsa
  (`live_transcription`/Vosk används ej — saknar svenska, se GAP-030).
- **I facksystemet:** inget ännu.
- **Data:** stödanteckning = utkast; sekretessklassad.

### Steg 28 — Mötet avslutas; WebM-inspelningen landar som fil i ärenderummet
- **Handläggaren:** avslutar samtalet; får **avisering när inspelningsfilen är klar**.
- **I Hubs:** recording server laddar upp **WebM** **in i ärenderummets Groupfolder** (styrt dit, ärver ACL +
  Retention — inte default `Talk/Recordings/`). `senasteFiler`/`arenderum` visar filen (`/f/{fileId}`).
  **Retention/gallringsklocka sätts direkt** via restricted-tagg: *"rå-inspelning gallras X dagar efter godkänd
  anteckning"*.
- **I facksystemet:** inget — WebM är **rå-artefakt, transient**.
- **Data:** WebM i ärenderummet. *(Se GAP-026 — rå-inspelning är potentiellt allmän handling; försvarbart bara
  som utkast med beslutad kort gallringsfrist i DHP.)*

### Steg 29 — Lokal transkribering: WebM → KB-Whisper → rått transkript
- **Handläggaren:** klickar **"Transkribera & sammanfatta möte (lokalt)"** på `motesanteckningar`-raden (eller
  triggas automatiskt när WebM landar).
- **I Hubs:** `stt_whisper2` (ExApp, AppAPI/Docker) kör **KB-Whisper** (faster-whisper/CTranslate2) →
  **transkript (.txt/.vtt)** i ärenderummet. Helt on-prem. KB-Whisper ~47 % lägre WER än large-v3.
- **I facksystemet:** inget — rått transkript är **transient**.
- **Data:** ljud → svensk text on-prem. Samma gallringsklocka som WebM. *(Se GAP-026/GAP-027 — rått transkript
  lika känsligt som inspelningen, potentiellt allmän handling.)*

### Steg 30 — Lokal AI-sammanfattning: transkript → llm2 → utkast
- **Handläggaren:** låter den lokala modellen producera ett **utkast** (kompletteras ev. av `call_summary_bot`
  som redan postat deltagare/uppgifter i tråden).
- **I Hubs:** `llm2` (llama.cpp, GGUF, grön-ratad) via Assistant/core Text Processing API läser transkriptet
  **lokalt** och *föreslår*: kort sammanfattning · fattade beslut/överenskommelser · åtgärds-/att-göra-lista
  med ansvarig · närvaro · **flagga "innehåller känsliga uppgifter — sekretessprövas"**. Chunkning/map-reduce
  för långt transkript.
- **I facksystemet:** inget — utkastet är **förslag, aldrig auto-committat**.
- **Data:** AI läser staging-data lokalt; **fattar aldrig beslut, skriver aldrig till facksystemet** (GDPR art.
  22). 0 tredjelandsöverföringar. *(Se GAP-028 — hallucinationsrisk; Steg 31 obligatoriskt.)*

### Steg 31 — Human-in-the-loop: granska, redigera och godkänn
- **Handläggaren:** **läser transkriptet mot utkastet**, rättar fel/hallucinationer, stryker irrelevant
  känsligt, formulerar den slutliga journalanteckningen och trycker **"Godkänn"**. En `bevakningar`-post
  ("Granska & godkänn mötesanteckning") stängs.
- **I Hubs:** den godkända texten sparas som versionerad fil; **"Godkänn" loggas som händelse** (vem, vad, när)
  — Activity + SDK-logg.
- **I facksystemet:** inget ännu — committas i Steg 33.
- **Data:** den godkända anteckningen är nu den enda artefakt som ska bevaras; rå-WebM + rått transkript är
  fortfarande transient. *(Se GAP-029 — human-in-the-loop måste vara tekniskt påtvingat, sida-vid-sida, inte
  ett klick.)*

### Steg 32 — (Vid behov) beslut e-signeras
- *(Detta är samma signeringssteg som detaljeras i Akt IV; för en mötesanteckning som leder till ett beslut.
  Lågrisk → "Godkänn" (LibreSign, loggat); formellt myndighetsbeslut → **AES via Inera** → PAdES/PDF/A-1 +
  LTV.)* Många mötesanteckningar journalförs dock utan formell signatur — steget är **valfritt**.

### Steg 33 — Den godkända anteckningen förs över till Treserva-journalen
- **Handläggaren:** trycker **"För över till facksystem"** → den godkända anteckningen (+ ev. signerat beslut +
  signerat samtycke) registreras i **Treserva-akten/BBIC-journalen**.
- **I Hubs:** destinations-chip "→ Treserva — ej registrerad" → "Förd till Treserva, dnr 2026-IFO-1234".
  Mönster **B** (standard) / **A** (storkund) / **D** (dag 1).
- **I facksystemet:** den godkända mötesanteckningen blir **journalanteckning i socialakten** (SoL 2025:400).
  Under pågående utredning räknas mötet in i **4-månadersfristen**.
- **Data:** **UT** (godkänd text) → Treserva. *(Se GAP-019 — integrationsmognad styr realismen; dag 1 manuell.)*

### Steg 34 — Rå-WebM och rått transkript gallras
- **Handläggaren:** inget aktivt krävs; ser **gallrings-countdown** och får notis innan radering. Kan välja
  "gallra nu" när anteckningen är committad.
- **I Hubs:** `files_retention` raderar **rå-WebM + rått transkript** ("X dgr efter godkänd
  anteckning/överföring"). Den godkända anteckningen får ärenderummets vanliga rensningsklocka. **Dubbel
  countdown** synlig.
- **I facksystemet:** Treserva/e-arkiv bär den bevarade handlingen.
- **Data:** rå-artefakter raderas (dataminimering). *(Se GAP-026 — kräver gallringsbeslut i DHP; GAP-031 —
  Retention-klockan måste kunna **pausas** vid en utlämnandebegäran (TF).)*

---

## Akt IV — Beslut, e-signering, delgivning, arkivering (Walkthrough 4)

> Fallet i denna akt är medvetet det "tunga": **avslag på ansökan om insats enligt 4 kap. 1 § SoL** — ett
> överklagbart myndighetsbeslut som kräver formell underrättelse, fullföljdshänvisning och delgivning. Ett
> bifall/lågrisk hade i stället bara "Godkänts" (loggat) per SKR:s riskmodell.

### Steg 35 — Ta fram beslutshandlingen (Collabora)
- **Handläggaren:** öppnar ärenderummet → väljer beslutsmallen ur `kunskapsbank` → skriver beslutet on-prem:
  sökande, beslut (avslag), motivering, tillämpat lagrum, **fullföljdshänvisning** (överklaga till
  förvaltningsrätten inom 3 veckor), beslutsfattare/delegation.
- **I Hubs:** dokumentet skapas/redigeras i Groupfolder-ärenderummet (`files` + `groupfolders` +
  `files_versions`), samredigering via **Collabora (WOPI)**. Mall ur `collectives`. Status: **utkast**.
- **I facksystemet:** inget ännu — committas i Steg 43. *(Se GAP-032 — dubbel-författande: skrivs beslutet i
  Collabora *och* i Treserva-journalen divergerar texterna; renast är att exportera utkast ur Treserva.)*
- **Data:** OSL 26 kap.; allt on-prem; LOA3-session.

### Steg 36 — Lägg beslutet i signeringskön ("Skicka för underskrift")
- **Handläggaren:** i `arenderum` (eller `attSignera`) klickar **"Skicka beslut för underskrift"**. Väljer
  kravnivå-badge (**AES** för myndighetsbeslut), undertecknare (sig själv som delegat), exportformat **PDF/A-1**.
- **I Hubs:** Collabora-dokumentet exporteras till **PDF/A-1**; en signeringsbegäran skapas i
  signeringsadaptern. Widget: **`attSignera`** (`ncAppId: libresign`, status `proposed-integration`).
  - **LibreSign-vägen (demo):** `POST /ocs/v2.php/apps/libresign/api/v1/request-signature`. Förutsätter
    `occ libresign:install` + lokal rot-CA.
  - **Inera-vägen (prod):** signeringsadaptern skapar ett signeringsärende mot **Inera Underskriftstjänst-API**
    med vald **signaturprofil** (AES). Kräver **SITHS funktionscertifikat** + Inera-anslutning.
- **I facksystemet:** inget ännu. *(Se GAP-033 — Inera anslutningsprofil mTLS vs OOB måste verifieras.)*
- **Data:** internt i Hubs (LibreSign); i Inera-fallet går **dokument-hash** ut till nationell tjänst.

### Steg 37 — Signera beslutet (undertecknarens action)
- **Handläggaren (= delegat):** öppnar **`attSignera`** → kortet "Beslut – avslag insats, Barn 2026-0412".
  Klickar **"Signera"**.
  - **LibreSign:** signeringsvyn (`/apps/libresign/p/{uuid}`), autentiserar med `account` (Hubs-kontot, vars
    inloggning i sig är LOA3); JSignPDF → **PAdES-likt** sigill mot lokal rot-CA.
  - **Inera:** startar **BankID/Freja/SITHS eID** (LOA3); användaren godkänner signeringsmeddelandet; Inera
    genererar engångsnyckel + cert och returnerar en **PAdES**-signatur + valideringsintyg.
- **I Hubs:** signerad PDF skrivs tillbaka till ärenderummet; status → **"Signerat"** (audit trail). Hubs
  härdar **PDF/A-1** och (prod) lägger på **LTV + kvalificerad tidsstämpel**.
- **I facksystemet:** inget ännu. *(Se GAP-034 — LibreSign-AES ≠ svensk myndighets-AES; håller inte för
  överklagbart avslagsbeslut, produktion kräver Inera/Sweden Connect.)*
- **Data:** signerad handling = **allmän handling**; får inte gallras godtyckligt.

### Steg 38 — Bevarande-/valideringskontroll ("Giltig nu / Giltig då")
- **Handläggaren:** ser i `attSignera`/`arenderum` en **bevarandepanel**: PAdES + PDF/A-1 ✓, LTV ✓,
  tidsstämpel ✓, "Verifiera underskrift nu".
- **I Hubs:** panelen läser PDF-signaturens status + valideringsintyget — **mellanlagringens kvalitetsgrind**
  innan commit (en obevarbar handling får inte gå vidare till arkiv).
- **I facksystemet:** inget ännu.
- **Data:** intern verifiering. Bevisvärdet kräver att man arkiverar *beviset* om underskriften
  (valideringsintyg + LTV). *(Se GAP-035 — LibreSign saknar robust LTV/tidsstämpel; "Giltig då" gäller
  realistiskt bara Inera/Sweden Connect.)*

### Steg 39 — (Avgränsning) lågrisk-alternativet "Godkänn" vs "Signera"
- **Handläggaren:** *om* beslutet varit lågrisk/gynnande hade hon klickat **"Godkänn"** (loggat) i stället —
  per SKR:s riskmodell (SES/AES/QES). Ingen BankID krävs.
- **I Hubs:** "Godkänn" registreras som loggad händelse (account-baserad signering / aktivitetslogg).
- **I facksystemet:** committas ändå i Steg 43, men godkännandeloggen är beviset.
- *(Se GAP-036 — kravnivå-mappning per beslutstyp är en policy-/juristfråga, odefinierad i underlaget.)*

### Steg 40 — (Vid annan beslutsfattare) medsignering & spegelvy
- **Handläggaren:** om delegationen kräver att 1:e socialsekreterare/utskott signerar, skickar hon beslutet och
  följer **`skickatForSignering`** (spegelvy): *Skickat → Öppnat → Signerat 1 av N → Klart*. "Påminn"-knapp.
- **I Hubs:** samma signeringsadapter, multi-signatär. LibreSign stödjer signeringsordning, roller, gästlänkar.
- **I facksystemet:** inget ännu. *(Se GAP-037 — LibreSign gäst-/extern-identitet är svag; extern part i
  myndighetsbeslut kräver Inera-AES.)*

### Steg 41 — Delge medborgaren säkert (med läskvittens)
- **Handläggaren:** i `arenderum`/`attHantera` klickar **"Delge beslut"**. Väljer kanal och **delgivningssätt**
  (vanlig / förenklad / digital brevlåda). Den signerade PDF/A:n bifogas.
  - **Säker e-post + BankID-länk** (`securemail`) (LOA3 vid öppning), **eller** **SDK** org-till-org, **eller**
    **Mina meddelanden / Kivra** (digital brevlåda).
- **I Hubs:** utgående delgivning i `sdkmc`/`securemail`; **`kvittenser`** följer tidslinjen Skickad →
  Levererad → Notis öppnad → Inloggad (LOA3) → Läst. Läskvittenset är delgivningsbeviset.
- **I facksystemet:** committas i Steg 43 efter bekräftad delgivning.
- **Data:** **UT** (Hubs → medborgare/ombud): signerat avslagsbeslut + fullföljdshänvisning. *(Se GAP-038 —
  läskvittens ≠ juridisk delgivning per automatik; delgivningslagen 2010:1932 har egna former.)*

### Steg 42 — Sätt överklagandefrist som bevakning
- **Handläggaren:** när delgivning skett (eller 2-veckorsfiktionen vid förenklad delgivning) skapar Hubs
  automatiskt en **bevakning** "Överklagandefrist – Barn 2026-0412 löper ut {datum}".
- **I Hubs:** bevakning i **`deck`** + **`tasks`/VTODO** med påminnelse T-7/T-3/T-0 *bara till tilldelad*.
  Fristen = **3 veckor** från delgivningsdatum (FL 44 §).
- **I facksystemet:** den **formella** fristbevakningen bor i **Treserva/Lifecare** (rödmarkerar passerat).
  Hubs **dubblerar inte**. Mönster **D**.
- **Data:** intern bevakning, ärendereferens. *(Se GAP-039 — fristens startpunkt beror på delgivningssättet;
  Hubs måste härleda startdatum ur valt sätt (steg 41↔42 hårt kopplade).)*

### Steg 43 — Committa signerad handling + delgivningsbevis till Treserva
- **Handläggaren:** klickar **"För över till Treserva"** (destinations-chip → "Förd till Treserva, dnr
  2026-IFO-1234").
- **I Hubs:** den signerade **PAdES/PDF/A-1**-handlingen + **valideringsintyget** + **delgivningskvittensen**
  paketeras och förs över. Mönster **A** (tunn Treserva-konnektor) / **B** (drag-to-case) / **D** (manuell, dag 1).
- **I facksystemet:** **Treserva-akten** får det rättsliga beslutet, den bevarade signerade handlingen,
  valideringsintyget, delgivningsbeviset och journalanteckningen. **System of record-ögonblicket.**
- **Data:** **UT** Hubs → Treserva (on-prem). Efter bekräftad överföring får Hubs-kopian rensnings-countdown.
  *(Se GAP-019/GAP-007 — mönster A inte byggt; "bekräftad överföring" är dag 1 en kryssruta, inte ett
  API-svar; för tidig gallring av enda kopian = arkiv-/offentlighetsbrott.)*

### Steg 44 — Arkivera till e-arkiv (FGS) vid ärendeavslut
- **Handläggaren / registrator / förvaltare:** vid ärendeavslut når de bevarandepliktiga handlingarna
  **slutarkivet**.
- **I Hubs:** *normalt äger Treserva* vägen vidare till e-arkiv. Där Hubs-rummet **självt** bär
  bevarandepliktiga original paketeras de enligt **FGS Paketstruktur 2.0 (E-ARK CSIP/SIP)** + **FGS
  Ärendehantering 2.0** via `arkivGallring` och levereras till **Sydarkivera/e-arkiv**. Mönster **C**.
- **I facksystemet:** Treserva → e-arkiv är **facksystemets** ansvar. Hubs/diariet är *mellanarkiv*,
  e-arkivet *slutarkiv*.
- **Data:** **UT** (Hubs/Treserva → e-arkiv); sekretessmarkering följer med i FGS-metadata. *(Se GAP-040 —
  ansvarsgränsen Treserva→e-arkiv vs Hubs→e-arkiv är oskarp; risk för dubbelarkivering.)*

---

## Akt V — Bevakning & todo: tvärsnittet (Walkthrough 5)

> Denna akt zoomar in på *bevakningslogiken* som löper genom hela ärendet: hur en bevakning föds ur ett
> inkommande meddelande, hur Hubs lägger påminnelser, hur de fyra lagstadgade klockorna modelleras, och **var
> gränsen går mellan Hubs arbetslista (mellanlagring) och Treservas formella, arkivpliktiga fristbevakning
> (slutlagring)**.

### Steg 45 — Inkommande meddelande landar i triagekön
- **Handläggaren:** Anna öppnar **`attHantera`**; bland raderna ligger en komplettering från en skola i ett
  pågående utredningsärende (Barn 2026-0412): "Bifogar efterfrågad pedagogisk kartläggning." Kanalikon = SDK,
  avsändare SITHS-verifierad, inkom 08:14. Destinations-chip "→ Treserva — utredning pågår".
- **I Hubs:** inget skapas än; meddelandet + bilaga ligger i sdkmc-lagret; en kopia av bilagan kan ha speglats
  till ärenderummet om auto-routing är på. Status: *oläst → läst*.
- **I facksystemet:** inget ännu — committas i Steg 50–51.
- **Data:** **IN** (extern → Hubs). OSL 26 kap.; avsändar-LOA = SITHS/LOA3. *(Se GAP-041 — ConversationId→dnr-
  mappning ospecificerad.)*

### Steg 46 — "Skapa bevakning från meddelande" (signaturfunktionen)
- **Handläggaren:** klickar **Skapa bevakning** på raden. Quick View med förifyllt: **titel** ("Följ upp
  komplettering — Barn 2026-0412"), **länk** till meddelandet, **ärendereferens** (dnr), **föreslagen frist**.
  Hon väljer hur den ska bo: **(a) personlig uppgift** (Tasks/VTODO) eller **(b) delad bevakning** på ärendets
  board (Deck).
- **I Hubs:** (a) → **VTODO i Tasks** på Annas kalender (CalDAV) med tre **VALARM** (T-7/T-3/T-0); widget
  `minaUppgifter`. (b) → **Deck-kort** på barnets board (`POST .../boards/{boardId}/stacks/{stackId}/cards`);
  widget `bevakningar`/`todolista`. Korttext = **ärendereferens**, inte känsligt klartextcitat.
- **I facksystemet:** inget ännu. *(Se GAP-042 — disciplinval personlig vs delad; för fristbärande poster bör
  Hubs *föreslå* delad board så ingen faller mellan stolarna.)*

### Steg 47 — Bevakningen kopplas till ärenderummet (kontext, inte journal)
- **Handläggaren:** ser genväg **Öppna ärenderum**; bilagan ligger där om auto-routing speglat den.
- **I Hubs:** bevakningen **refererar** till rummet (deep-link) men är **inte** en handling i rummet — den är
  arbetsmetadata. Bilagan, däremot, är en handling.
- **I facksystemet:** bilagan är allmän handling som ska föras till Treserva-akten (separat dok-handoff, B/A).
- **Data:** bilaga **IN**, bevakning = intern referens. **Dubbel countdown**. *(Se GAP-043 — bilagan kan ligga
  i tre lager samtidigt (sdkmc + ärenderum + Treserva); "bekräftad överföring" odefinierad vid mönster D.)*

### Steg 48 — Hubs lägger påminnelser T-7/T-3/T-0 (bara till tilldelad)
- **Handläggaren:** inget aktivt — systemets jobb.
- **I Hubs:** **Tasks/VTODO:** tre **VALARM** (`-P7D`, `-P3D`, `-PT0S`) native i CalDAV-kärnan, till ägaren.
  **Deck:** kärnan saknar "påminnelse före due date" (#1549) och har historiskt aviserat *alla* board-medlemmar
  (#566) → **Hubs bygger egen påminnelselogik** ovanpå Deck (bakgrundsjobb → notis bara till `assignedUsers`).
- **I facksystemet:** Treserva har **egen** påminnelse (röd vid passerat). **Hubs dubblerar inte.**
- **Data:** intern notis (ärendereferens + frist, ingen känslig uppgift). *(Se GAP-044 — riv-mekanismen som
  avaktiverar Hubs-påminnelsen när Treserva tagit över är inte byggd → dubbel bevakning i övergångsfönstret;
  GAP-045 — Hubs Deck-påminnelse-motor är "proposed", inte native.)*

### Steg 49 — Fristerna modelleras: vilken klocka är det egentligen?
- **Handläggaren:** väljer/bekräftar **fristtyp**. De fyra lagstadgade klockorna detta tvärsnitt bär:
  1. **Förhandsbedömning — 14 dagar** (från **inkommen** anmälan, inte plock).
  2. **Utredning — 4 månader** (11 kap. 2 § SoL 2025:400), förlängning bara vid särskilda skäl.
  3. **Uppföljning av tidsbegränsat beslut** — nytt beslut "i god tid innan beslutet upphör".
  4. **FL 11–12 §§** — efter **6 mån** kan parten begära avgörande; myndigheten har då **4 veckor**.
- **I Hubs:** fristtyp + DUE på VTODO/Deck-kortet, speglas i Tables-register; eskaleringsfärg grå→gul→röd ur
  (DUE − idag). `fristStrip`/`bevakningar` renderar.
- **I facksystemet:** den **rättsligt bindande** fristen ägs av Treserva (rödmarkerar passerat oberoende av
  Hubs). *(Se GAP-002/GAP-046 — 14-dgr-klockan måste starta på inkomstdatum; GAP-047 — förlängd 4-mån-frist
  kan ge "falsk-röd" i Hubs medan Treserva har giltig förlängning.)*

### Steg 50 — Bevakningen blir en formell aktivitet → registreras i Treserva
- **Handläggaren:** när uppgiften går från "personlig arbetslapp" till **formell handläggningsåtgärd** (beslut
  om uppföljning, journalförd aktivitet, formell fristbevakning), **för Anna över** den till facksystemet och
  **registrerar bevakningen där** (bevakningsdatum, ansvarig, antal dagar före varning).
- **I Hubs:** Hubs-uppgiften får status "förd till ärendet/facksystemet" + provenance: "Bevakning registrerad
  i Treserva … · Hubs-uppgift länkad". Mönster **A** om API finns; annars **D** ("Markera som överförd").
- **I facksystemet:** Treserva skapar den **arkivpliktiga, formella bevakningen** — fristens system of record.
- **Data:** **UT** (Hubs → Treserva). *(Se GAP-048 — mönster A för *bevakningar* mot Treserva är overifierad;
  GAP-049 — inget i Hubs tvingar fram registreringen → frist kan leva i icke-arkivpliktigt system.)*

### Steg 51 — "Att göra (socialtjänst)"-listan & klarmarkering: gallra / för till ärendet
- **Handläggaren:** i **Mina uppgifter** + **Mina bevakningar & frister** (GOV.UK task-list, verb-inledda
  titlar) ser hon dagens steg. Vid klarmarkering frågar Hubs: **"Gallra (personlig notering)"** eller **"För
  till ärendet/facksystemet"** — håller isär arkivpliktigt från gallringsbart.
- **I Hubs:** **Gallra** → VTODO/Deck-kort `COMPLETED` → rensas. **För till ärendet** → uppgiften länkas till
  Treserva-aktiviteten; ev. kvarvarande Hubs-påminnelser **avaktiveras** så Treserva blir ensam fristägare.
- **I facksystemet:** vid "för till ärendet" är aktiviteten/bevakningen redan committad (Steg 50); vid "gallra"
  händer inget — en personlig att-göra-lapp är inte allmän handling.
- **Data:** valet "gallra vs för till ärendet" är **den juridiskt känsligaste interaktionen i hela flödet**.
  *(Se GAP-007/GAP-050 — "bekräftad överföring" är ett mänskligt påstående vid mönster D; worst case: klick
  utan registrering → Hubs gallrar → handling/frist finns ingenstans. GAP-051 — ACL-granularitet "Enhetens
  bevakningar".)*

---

## Konsoliderad systemöversikt (varje steg → Hubs-app → facksystem → handoff A/B/C/D)

| # | Steg | Hubs-app (mellanlagring) | Facksystem (slutlagring) | Handoff |
|---|---|---|---|---|
| 1 | Inkomst till funktionsadress | `sdkmc`/`securemail`/`mail-fax` (summary-endpoint) | — (AS4-kvittens tillbaka) | — |
| 2 | Syns i triagekö | `attHantera` + `orosanmalningar` (Tables) | — | — |
| 3 | Omedelbar skyddsbedömning | `forms`/`arenderum` (notering) | Treserva/Lifecare (dokumenteras ofta direkt) | **D/A** |
| 4 | Plocka/tilldela | `funktionsbrevlador` + `deck`/`tasks` (bevakning) | (ev. aktualisering här) | — / **D** |
| 5 | Kontroll tidigare aktualisering | (deep-link/chip) | Treserva/Lifecare (läsning) | **A** (önskat) / manuellt idag |
| 6 | Skapa ärenderum | `arenderum` (groupfolders+ACL+Retention) | — | — |
| 7 | Tillåtna kontakter (förhandsbedömning) | `attHantera`/`kvittenser` + `arenderum` | — | — |
| 8 | Beslut inleda/ej inleda | `orosanmalningar` + `attSignera` | Treserva/Lifecare (beslut journalförs) | **D/B/A** |
| 9 | Aktualisering (registrering) | `registreraFordela`-mönster (förifyllt) | **Treserva/Lifecare/Viva/Combine** (dnr) | **B/A** (D dag 1) |
| 10 | Stäng loop + gallra | `bevakningar` + `files_retention` + `kvittenser` | facksystemet bevarar → e-arkiv | **C** (vid avslut) |
| 11 | Öppna/återanvänd ärenderum | `groupfolders`/`files` (`arenderum`) | Treserva (dnr finns redan) | — (spegling) |
| 12 | Sätt ACL | `groupfolders` ACL + `activity` | — | — |
| 13 | BBIC-struktur | `collectives` (`kunskapsbank`) → `files` | — (Treserva BBIC fylls i steg 20) | — |
| 14 | Samla handlingar | sdkmc → `files`/`groupfolders` (`senasteFiler`) | — | committas 20–21 (**B/A**) |
| 15 | Samredigera utredningstext | Collabora/OnlyOffice (WOPI) + `files_versions` | — | committas 20 |
| 16 | Versionshantering | `files_versions` + `activity` | — | — (gallras) |
| 17 | Samtycke (uppgiftsutbyte/SIP) | `forms` (`mallarSamtycke`) + ID-core | Treserva-akten | committas 21 (**B/A**) |
| 18 | Säker delning + läskvittens | `files`-share/sdkmc + ID-core (`kvittenser`) | Treserva (kommunicering journalförs) | **E** (utkanal) + **B** |
| 19 | Frist 4 mån | `deck`/`tasks` (`bevakningar`) | Treserva äger formell fristbevakning | **A** (bör läsa Treserva) |
| 20 | Committa utredning | `arenderum`/`registreraFordela` | **Treserva BBIC-journal** | **B/A/D** |
| 21 | Knyt bilagor/kvittens | `files` + `kvittenser`/`activity` | Treserva-akten | **B/A/D** |
| 22 | Gallra Hubs-kopian | `files_retention` / FGS-byggare (`arkivGallring`) | e-arkiv (Sydarkivera, FGS) | **C** + retention |
| 23 | Boka tid + kalla | `bokningsbaraTider`/`dagensMoten` (calendar + spreed-itsl) + securemail | — (transit) | — |
| 24 | Samtycke (möte + inspelning) | `mallarSamtycke` (forms + libresign) | committas 33 (→ Treserva) | **B/A/D** |
| 25 | Anslut + BankID-lobby | `dagensMoten`/`identitetsBadge` (spreed-itsl lobby, ID-core) | — (transit) | — |
| 26 | Inspelning + `recording_consent` | spreed-itsl + recording server (HPB) | — (transient) | — |
| 27 | Mötet + stödanteckning | `arenderum` (Collabora/OnlyOffice) | — | — |
| 28 | WebM → ärenderum | `senasteFiler`/`arenderum` (groupfolders + files_retention) | — (transient, gallras 34) | — |
| 29 | Transkribering (KB-Whisper) | `motesanteckningar` (`stt_whisper2` + KB-Whisper) | — (transient) | — |
| 30 | AI-sammanfattning (utkast) | `motesanteckningar` (`llm2`/Assistant) | — (förslag) | — |
| 31 | Granska + Godkänn | `motesanteckningar` + `bevakningar` (loggad händelse) | committas 33 | — |
| 32 | (Ev.) e-signera beslut | `attSignera`/`skickatForSignering` (Inera / libresign) | Signerad PDF/A → Treserva-akten | **A/B/D** |
| 33 | För över godkänd anteckning | `arenderum` destinations-chip → Treserva | **Treserva** (BBIC-journal) | **B** (A storkund / D dag 1) |
| 34 | Gallra rå-data + rensa Hubs | `arkivGallring`/`files_retention` (restricted-tagg) | Treserva/e-arkiv bär handlingen | **C** (vid avslut) |
| 35 | Ta fram beslut | `files`/`groupfolders` + Collabora + `collectives` (mall) | — (committas 43) | — |
| 36 | Skicka för underskrift | `attSignera` → `libresign` / **Inera API** | — | — |
| 37 | Signera | `libresign` (PAdES, lokal rot) / **Inera** (BankID/Freja/SITHS) | — | — |
| 38 | Validera/bevara ("Giltig nu/då") | signeringsadapter + `files` | — | — |
| 39 | (Lågrisk) Godkänn | `attSignera` (loggad) / `activity` | — | — |
| 40 | (Ev.) Medsignering | `skickatForSignering` → `libresign`/Inera (+ `sdkmc`) | — | — |
| 41 | Delge medborgaren | `securemail`/`sdkmc` + `kvittenser` (receipt) | — (committas 43) | — |
| 42 | Sätt överklagandefrist | `bevakningar` → `deck` + `tasks` | Treserva/Lifecare äger formell frist | **D** |
| 43 | Committa beslut till facksystem | destinations-chip i `arenderum`/`attHantera` | **Treserva** (akt, beslut, journal, bevis) | **A**/B/**D** |
| 44 | Arkivera (FGS) | `arkivGallring` → `files_retention` + FGS-byggare | **e-arkiv** (Sydarkivera) / via Treserva | **C** |
| 45 | Inkommande i triage (tvärsnitt) | `attHantera` (sdkmc/securemail/mail) | Treserva (ej registrerat ännu) | — |
| 46 | Skapa bevakning från meddelande | `tasks` (VTODO) **eller** `deck` / `todolista` | — (committas 50) | — |
| 47 | Koppla bevakning till ärenderum | `arenderum` (groupfolders + ACL + Retention) | Bilaga → Treserva-akten (separat) | **B/A** (dokument) |
| 48 | Påminnelser T-7/T-3/T-0 | `bevakningar`/`minaUppgifter` (Tasks VALARM; Hubs-logik ovanpå Deck) | Treserva egen bevakningspåminnelse | — |
| 49 | Modellera frister | `fristStrip`/`bevakningar` (+ Tables status) | Treserva äger rättsligt bindande frist | — |
| 50 | Formell aktivitet → registrera bevakning | provenance-uppdatering i `bevakningar` | **Treserva** (formell, arkivpliktig bevakning + journal) | **D** (A om API) |
| 51 | Klarmarkera: gallra / för till ärendet | Tasks/Deck `COMPLETED`; Retention | Treserva (om "för till ärendet"); inget (om "gallra") | **D** |

> *(Handoff **E** = säker utkanal till medborgare/ombud, för fullständighet — delning/delgivning ut ur Hubs;
> A/B/C/D är handoffs mot facksystem/e-arkiv.)*

---

## Modellmening (hela ärendet i en mening)

*Hubs stagar orosanmälan → förhandsbedömning → ärenderum → utredningsdokument → säkert möte med lokal
transkribering & AI-utkast → signerat beslut → säker delgivning med läskvittens → bevakning; och handläggaren
för över varje **bestående** handling — anmälan, utredningstext, samtycke, godkänd mötesanteckning, signerat
beslut, delgivningsbevis — till **Treserva/Lifecare/Viva/Combine** (socialakten/BBIC-journalen, och i slutänden
e-arkiv via FGS), varefter Hubs-kopian gallras. Avsikten är transient; varaktigheten kan vara månader.*

---

*Grundas i `analysis-output/extended/walkthrough-1…5`, `persona-usage-socialsekreterare.md`,
`hubs_start/docs/WIDGET-APP-MAP.md` och de underliggande underlagen (`native-apps-map.md`,
`arendehantering-map.md`, `middleware-architecture.md`, `esign-todo-native.md`, `transcription-ai.md`,
`widgetApps.js`). Luckor/antaganden konsoliderade i `GAP-ANALYSIS.md`; signeringsdetaljerna i `SIGNING-INERA.md`.*
