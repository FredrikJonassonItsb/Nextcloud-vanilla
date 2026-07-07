---
titel: Analys — förifyllnad av samtliga mallar ur ärendets information + ansvarsgränsen
status: Analys/kravunderlag v1.0 (fält-för-fält-kartläggning i BILAGA)
datum: 2026-07-07
relaterat: ANALYS-HANDLING-FRAN-MALL.md (fas 1 byggd), ANALYS-FORIFYLLNAD-BILAGA.md (per mall)
---

# Förifyllnad av samtliga mallar — kartläggning & ansvarsgränsen

Frågan (Fredrik 2026-07-07): *kartlägg vilken information som rimligtvis har inkommit i
ett ärende, analysera varje fält i varje mall mot det innehållet, och visa hur vi
underlättar för handläggaren att fylla i dokumenten — utan att systemet tar ansvar för
besluten.*

Detta dokument är huvudanalysen. Den fullständiga fält-för-fält-genomgången av alla
18 mallar ligger i [`ANALYS-FORIFYLLNAD-BILAGA.md`](ANALYS-FORIFYLLNAD-BILAGA.md).

---

## 1. Den bärande principen: ansvarsgränsen är inte ny — den är juridiken

Socialtjänstens dokumentationsregler (SOSFS 2014:5, genomgående i mallarna) kräver redan
att **faktiska uppgifter hålls isär från bedömningar**. Mallarna kodifierar det: "håll
isär vad som faktiskt sagts/observerats och din bedömning". Ansvarsgränsen för
förifyllnad **följer exakt samma linje**:

> **Systemet får äga fakta. Bedömningar förblir alltid handläggarens — undantagslöst.**

Det ger en femgradig typskala som varje fält i varje mall klassats efter:

| Typ | Definition | Systemets roll | Exempel |
|---|---|---|---|
| **FAKTA** | Objektiv metadata systemet *vet* | Autofyller, källmarkerat | dnr, enhet, handläggare, barnets namn ur partsregistret |
| **HÄRLEDD** | Sakuppgift som härleds ur källor med rimlig säkerhet | Förifyller som **förslag**; handläggaren bekräftar | anmälans inkomdatum ur meddelande-metadata; "utredning inleddes" ur journalens stegövergång |
| **SAMMANSTÄLLNING** | Referat/sammanfattning av innehåll | Förbereder **utkast med källhänvisning**; måste redigeras och ägas av handläggaren | referat av oron ur anmälans brödtext; "vad som framkom" ur mötesunderlag |
| **BEDÖMNING** | Ställningstagande, motivering, riskvärdering, beslut | **Förifylls ALDRIG** — oavsett teknisk möjlighet. Systemet får ge *beslutsstöd* (visa källmaterial, checklistor) men värdet är handläggarens | "behöver barnet omedelbart skydd?", beslutsmotivering, analys, insatsval |
| **KVITTO** | Systemgenererade bekräftelser | Fylls av systemet **efter** händelsen, inte i utkastet | journalförd, committad till Treserva, signerad |

Tre saker gör att handläggaren *underlättas utan att systemet tar över ansvaret*:

1. **Typen styr interaktionen, inte bara datakällan.** Ett FAKTA-fält fylls tyst; ett
   HÄRLEDD-fält visas som förslag med källa ("ur journalen 2026-07-02"); ett
   SAMMANSTÄLLNING-fält får utkastmarkering som *tvingar* aktiv redigering; ett
   BEDÖMNING-fält lämnas tomt med mallens handledning som beslutsstöd.
2. **Källmarkering + granskningssteget.** Förhandsdialogen (byggd i fas 1) visar varje
   fälts värde, källa och varningar — handläggaren bekräftar *aktivt* innan dokumentet
   skapas. Det som skapas journalförs (mall, antal ersättningar, skyddsöverridningar).
3. **Human-in-the-loop är husets etablerade princip** (transkriberingsflödet: AI-utkast →
   mänsklig granskning → commit; klassningsmotorn: deterministisk, LLM aldrig autonomt på
   sekretess). Förifyllnaden ärver samma princip — ingen sammanställning når ett dokument
   utan att en människa redigerat/bekräftat den.

**Varför detta håller juridiskt:** beslutet fattas av delegat enligt delegationsordningen
och dokumenteras med beslutsfattarens underskrift. Systemet producerar aldrig ett
ställningstagande — det flyttar bara *redan känd sakinformation* till rätt plats i
dokumentet, med spårbarhet. Skulle ett förifyllt faktum vara fel är det (a) synligt
källmarkerat, (b) granskat i förhandssteget, (c) redigerbart i dokumentet — ansvaret för
den slutliga handlingen är oförändrat handläggarens/delegatens, precis som när hen skriver
av samma uppgift manuellt ur akten. Systemet ändrar *arbetsmomentet*, inte *ansvaret*.

---

## 2. Vad som rimligtvis har inkommit — ärendets information per fas

Ärendets informationsinnehåll växer med livscykeln. En mall som används i ett visst steg
kan bara förifyllas ur det som *finns då*:

| Ärendesteg | Information som rimligtvis finns | Nya källor sedan föregående steg |
|---|---|---|
| **Inflöde** | Anmälan/meddelandet (metadata: inkom-tid, kanal, ämne; innehåll = fas 1.5), triageRef, enhet, klassning | meddelanden, register |
| **Förhandsbedömning** | + hubsCaseId/kortRef, skapad-datum, ev. tilldelad handläggare, **skyddsbedömningens utfall** (dokumentkedjan), parter (barn/anmälare — manuellt eller uppslag), journal: stegövergång med datum | partsregister, journal, akten (01/02) |
| **Utredning** | + dnr (efter registrering), beslut att inleda (03), utredningsplan (04), samtal/möten (kalender + anteckningar 06/07/08), samtycken (09), inhämtade uppgifter (11), vårdnadshavare ur Navet | akten (03–11), kalender, treserva (fas 2) |
| **Beslut** | + färdig utredning (05), kommunicering (16), medlemmar/medbedömare | akten (05, 16) |
| **Uppföljning/avslut** | + beslutet (15), vårdplan/genomförandeplan (12/13), SIP (14), delgivning (17), kvittenser | akten (12–17), kvitton |

**Kärninsikten — dokumentkedjan:** samma sakuppgift återkommer i mall efter mall
(anmälans inkomdatum står i 01, 02 och 03; barnets uppgifter i nästan alla; beslutet
i 15, 16, 17 och 18). Det som handläggaren skrev/bekräftade i ett *tidigare* dokument i
kedjan är den naturliga källan för *nästa*. Idag är den kunskapen inlåst i .docx-filerna —
arkitektursvaret är ett **sakuppgiftslager** (§4).

## 3. Källorna — status och tillgänglighet

| Källa | Innehåll | Status |
|---|---|---|
| **Register** (Arende-raden) | kortRef, triageRef, dnr, enhet, handläggare, steg, frist, skapad | ✅ fas 1 (byggd) |
| **Partsregister** | parter med roll/namn/pnr/adress/skydd/vårdnadshavar-relationer | ✅ fas 1 (byggd) |
| **Journal** (händelser) | stegövergångar m. datum+aktör (fristankare!), tilldelning, registrering | ✅ finns — *exponeras inte i ArendedataService ännu* |
| **Medlemmar** | handläggare/co-handläggare/medbedömare | ✅ finns — ej exponerad |
| **Meddelanden** (case-taggade) | anmälans inkom-tid, kanal, ämne | ✅ metadata finns (sdkmc) — ej exponerad |
| **Kalender** (dnr-märkta möten) | mötesdatum, deltagare (kommande/genomförda) | ✅ finns — ej exponerad |
| **Akten: tidigare handlingar** | dokumentkedjans innehåll | ⚠️ filerna finns; *strukturerad återläsning byggs* (→ sakuppgiftslagret §4) |
| **Anmälans innehåll** | brödtext, avsändare | 🔨 fas 1.5 (Mail-läsaren; human-in-the-loop-extraktion) |
| **Treserva** (QueryPort) | tidigare insatser, akthistorik | 🔨 fas 2b |
| **Navet** (skarp) | folkbokföring → partsregistret | 🔨 fas 2a (mock live) |

## 4. Arkitektursvaret: SAKUPPGIFTSLAGRET (dokumentkedjans minne)

Fält-analysen (bilagan) visar att den största förifyllnadsvinsten *inte* ligger i fler
integrationer utan i att **återanvända det handläggaren redan bekräftat i ärendet**.
Förslaget:

- En per-ärende-tabell **`hubs_arende_sakuppgift`** (nyckel, värde, källa, bekräftad-av,
  bekräftad-datum, ursprungsdokument) — t.ex. `anmalan.inkom`, `anmalan.anmalare`,
  `skyddsbedomning.utfall`, `utredning.inledd`, `beslut.datum`, `beslut.utfall`.
- **Skrivs när en handling skapas**: de fält handläggaren bekräftade i förhandsdialogen
  är *bekräftade sakuppgifter* — de sparas strukturerat (inte bara i docx:en).
  Handläggarens bekräftelse i dokument N blir förifyllnad i dokument N+1.
- **Läses av `ArendedataService`** som källa med hög konfidens (`kalla:
  'akten_tidigare_handling'` + ursprungsdokument i källmarkeringen).
- Gallras med ärendet (som partsregistret). PII-regler ärvs. NEVER-SoR består —
  slutresultatet committas till facksystemet som idag; sakuppgiftslagret är arbetsminne.
- **Detta löser BEDÖMNINGS-paradoxen:** skyddsbedömningens *utfall* ("ej omedelbart
  skyddsbehov, beslutat 2026-07-02 av NN") är en BEDÖMNING i mall 02 — men när den väl är
  **fattad och dokumenterad** är den ett **FAKTUM för mall 03** ("skyddsbedömning gjord
  [datum], utfall: …"). Systemet återger då ett fattat beslut (med källa), det fattar det
  inte. Bedömningen förifylls aldrig framåt — bara *refereras* bakåt.

## 5. Fältvokabulären — utökningen av ArendedataService

*(Sammanställs ur bilagans 18 mallanalyser — se §7 Aggregat.)*

Fas 1 exponerar 5 fält. Kartläggningen ger den prioriterade utökningen per våg:

- **Våg A (register/journal/kalender/medlemmar — ingen ny integration):** anmälans
  inkomdatum+kanal (meddelande-metadata), stegövergångsdatum (förhandsbedömning inledd,
  utredning inledd/klar — fristberäkningar!), dagens datum i *underskriftsblocket* (en
  entydig datum-betydelse — till skillnad från övriga datumfält), medbedömare/gruppledare
  (medlemmar), mötesdatum/deltagare (kalender), vårdnadshavare 1/2 namn+pnr
  (partsregistret — redan data, ej exponerad som fält), anmälarens namn/funktion
  (partsregistret roll=anmalare).
- **Våg B (sakuppgiftslagret §4):** dokumentkedjans fält — skyddsbedömningens utfall+datum,
  beslut att inleda+datum, utredningens frågeställningar, beslutets innehåll+datum+delegat,
  överklagandefristens ankare (delgivningsdatum).
- **Våg C (fas 1.5/2-integrationer):** anmälans brödtext som källvisning/utkast
  (SAMMANSTÄLLNING — aldrig autonom), Treserva-historik, samtyckens omfattning.

## 6. UI-principerna som bär ansvarsgränsen

1. **Färgkodad källmarkering per fält** i förhandsdialogen: grönt = FAKTA (autofyllt),
   gult = HÄRLEDD/förslag (kräver blick), blått = SAMMANSTÄLLNING/utkast (kräver
   redigering — dokumentet markerar stycket "UTKAST — redigera"), grått/tomt = BEDÖMNING
   (handläggarens; handledningen visas som stöd).
2. **Bedömningsfält är aldrig input-fält i dialogen.** De listas inte ens som ifyllbara —
   de tillhör dokumentarbetet i redigeraren. Dialogen fyller *ramen*, aldrig *ställnings-
   tagandet*. (Detta är den tydligaste manifestationen av ansvarsgränsen.)
3. **Källpanel vid dokumentarbetet** (framtida): öppna handlingen med ärendets
   källmaterial bredvid (anmälan, journal, tidigare handlingar) — systemet *visar*
   underlaget, handläggaren *värderar* det.
4. **Journalföring av förifyllnaden:** vilka fält som autofylldes/bekräftades/overreds
   (skyddsoverride finns redan). Spårbarheten är en del av ansvarsmodellen.
5. **Utkastmarkering på SAMMANSTÄLLNING:** text som systemet förberett får synlig
   markering i dokumentet tills handläggaren redigerat den (fas 2-mekanik; fas 1 lämnar
   sådana fält tomma).

## 7. Aggregat ur fält-kartläggningen (18/18 mallar, 478 fält)

**Typfördelningen — ansvarsgränsen i siffror:**

| Typ | Antal | Andel | Systemets roll |
|---|---|---|---|
| FAKTA | 152 | 32 % | autofyll, källmarkerad |
| HÄRLEDD | 156 | 33 % | förslag som bekräftas |
| SAMMANSTÄLLNING | 54 | 11 % | utkast med källa, kräver redigering |
| **BEDÖMNING** | **102** | **21 %** | **förifylls aldrig — handläggarens** |
| KVITTO | 14 | 3 % | systemet, efter händelsen |

**Ansvarsfördelningen:** system 111 (23 %) · förslag+bekräfta 232 (49 %) ·
handläggaren 135 (28 %). **Systemet kan alltså underlätta 72 % av fälten (343/478) —
utan att röra ett enda av de 102 bedömningsfälten.**

**Fas-tillgänglighet:** fas 1 (dagens källor) 271 fält (57 %) · fas 1.5 (anmälan-läsaren)
+54 · fas 2 (Treserva/Navet skarp) +19 · alltid manuella 134.

**Nyckelmönster ur kartläggningen:**

1. **Dokumentkedjan är tät.** Avslutsanteckningen (18) matas av åtta tidigare dokument;
   SIP:en (14) av sex; besluts-/kommunicerings-/underrättelsekedjan (15→16→17) delar
   beslutsuppgifterna. Sakuppgiftslagret (§4) är rätt arkitektur — bekräftat av datan.
2. **Kryssrutor är analysens fällor.** Flera kryssgrupper *ser ut* som fakta men är
   rättsliga klassificeringar (t.ex. "omfattas anmälaren av anmälningsplikt?" — styr
   anonymitetshanteringen) → klassade BEDÖMNING, förifylls aldrig. Omvänt är vissa
   kryss ren kanalfakta ("skriftlig anmälan" ur meddelande-metadata) → förslag.
3. **Fristankare kräver bekräftelse.** Anmälans inkomtidpunkt driver 14-dagars­fristen
   och skyddsbedömningens "samma dag" — alltid HÄRLEDD/förslag, aldrig tyst autofyll,
   även när metadatan är säker.
4. **Intyganden är handläggarens.** Formuleringar som "har dokumenterats skriftligt av
   mottagaren" är påståenden om handläggarens egen handling — systemet sätter dem aldrig.
5. **SAMMANSTÄLLNING kluster i utredningsfasen** (referat av oron, vad som framkommit,
   BBIC-områdenas texter) — det är där fas 1.5:s anmälan-läsare och framtida källpanel
   (§6.3) ger störst avlastning, alltid med redigeringskrav.

Fullständig fält-för-fält-tabell per mall: [`ANALYS-FORIFYLLNAD-BILAGA.md`](ANALYS-FORIFYLLNAD-BILAGA.md).

## 7b. Genomfört (natten 2026-07-07) — förslaget är implementerat

Våg A + sakuppgiftslagret byggdes, deployades (hubs_arende v0.11.0 på dev15) och
E2E-bevisades samma natt som analysen:

- **Sakuppgiftslagret** (`hubs_arende_sakuppgift` + `SakuppgiftService`): handläggarens
  bekräftade fält sparas med käll-attribution vid varje handlingsskapande och fyller
  luckor i nästa utkast (`kalla: akten_tidigare_handling`). Levande källor vinner;
  skydds-varnade fält fylls aldrig ur minnet; gallras med ärendet.
- **Våg A-fälten**: `anmalareNamn`/`anmalareKontakt` (partsregistret), `inkomTidpunkt` +
  `utredningInledd` (journalens datum-ankare), `medutredare` (medlemsliggaren) — 10 fält
  totalt i vokabulären, alla platshållare grep-verifierade globalt entydiga.
- **E2E-bevis** (axels ärende, dev15): handläggarifyllt anmälarnamn i dokument 1
  förifyllde dokument 2 ur akten; ändrat journalförslag attribuerades `handlaggare`,
  oförändrat förslag behöll ursprungskällan. 124/124 tester gröna.

## 8. Implementationsväg

1. **Våg A** — utöka `ArendedataService` med journal-/kalender-/medlems-/meddelande-fälten
   (ingen ny integration; dagar av arbete). Utöka `PLATSHALLARE`-kartan och mallarnas
   fält-register per mall (bilagan är specifikationen).
2. **Sakuppgiftslagret** — migration + `SakuppgiftService` + skrivning ur
   `HandlingService` (bekräftade fält) + läsning i `ArendedataService`. Dokumentkedjan
   börjar leva.
3. **Fas 1.5/2-källorna** enligt tidigare plan (anmälan-läsaren, Navet skarp, Treserva).
4. **UI-vågorna**: källfärger i förhandsdialogen (A), utkastmarkering (B), källpanel (C).

## 9. Risker & ärlighet

- **Fel förifyllt faktum ser trovärdigt ut.** Motmedel: källmarkering, granskningssteg,
  journalföring — och att HÄRLEDD aldrig blir tyst autofyll.
- **Automation bias** (handläggaren litar blint). Motmedel: BEDÖMNING förifylls aldrig;
  SAMMANSTÄLLNING kräver redigering; utkastmarkering tills berörd.
- **Sakuppgiftslagret får inte bli skugg-SoR.** Det speglar *bekräftade uppgifter ur
  ärendets egna handlingar*, gallras med ärendet, och slutversionen bor i facksystemet.
- **Mallversionering:** fält-registret per mall måste följa mallbibliotekets versioner.
