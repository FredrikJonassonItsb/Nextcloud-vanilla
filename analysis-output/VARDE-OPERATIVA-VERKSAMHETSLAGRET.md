<!--
SPDX-FileCopyrightText: ITSL <info@itsl.se>
SPDX-License-Identifier: AGPL-3.0-or-later
Värde-/faktaunderlag för beslutsfattare. Avsett att vägas in i ett större
marknadsföringsdokument. Brand-säkert (inga underliggande produktnamn).
Tidssiffror märkta som extern källa (Digg) eller modellerad uppskattning.
-->

# Hubs Start & det operativa verksamhetslagret — värde för beslutsfattare

## Kärnan i en mening

> **Hubs Start är handläggarens *enda förstavy* — och under den ligger ett operativt verksamhetslager som binder ihop alla säkra kanaler, ärendelivscykeln och facksystemet, så att rätt sak hamnar rätt, i tid, spårbart, utan att känslig information lämnar kommunens egen drift.**

Två meningar för en slide:
- **Idag** hoppar handläggaren mellan fax, säker e-post, e-tjänster, diariet och facksystemet, väljer kanal manuellt, bär frister i huvudet och ringer för att kontrollera att saker kommit fram.
- **Med det operativa lagret** möts hon av *en* åtgärds-kö, systemet väljer säker kanal åt henne, ärenderummet skapas i ett klick, fristerna räknar ner själva, och varje handling kvitteras och förs över till facksystemet — automatiskt dokumenterat.

---

## 1. Grundkonceptet: "det operativa verksamhetslagret"

Kommunen har redan ett **facksystem** (Treserva, Lifecare, Viva, Combine, Provisum m.fl.) — den juridiska slutlagringen, akten. Men *vägen dit* — allt som händer från att något kommer in tills beslutet är fattat, signerat och arkiverat — sker idag splittrat över många system, kanaler och manuella steg.

**Det operativa verksamhetslagret är den säkra arbetsytan däremellan ("mellanlagringen"):**

```
  INKOMMANDE                OPERATIVA LAGRET (Hubs)              SLUTLAGRING
  ──────────                ──────────────────────              ───────────
  Säker e-post  ┐                                          ┌→
  Fax (digital) ┤        ┌──────────────────────┐         │
  SDK/myndighet ┼──────► │  ÅTGÄRD-FÖRST-VYN     │ ──────► ┤  Facksystemet
  SMS           ┤        │  + ärendemotorn       │         │  (akten, journalen,
  e-tjänst/form ┤        │  + ärenderummet       │         │   diariet, e-arkiv)
  internpost    ┘        └──────────────────────┘         └→
                          • triage  • frister
                          • ärenderum (säkra filer)
                          • säkert möte  • e-signering
                          • kvittens  • gallring
```

Tre principer gör lagret starkt:

1. **Helheten sitter ihop.** En triage-kö samlar *allt* inkommande som kräver åtgärd — oavsett kanal — med verifierade djuplänkar ner i rätt funktion. Inget faller mellan systemen.
2. **Motorn äger koordinationen, inte journalen.** Lagret skapar och håller ihop ärendets *arbetsstate* (ärenderum, frister, status, kvittenser) men för alltid över den rättsliga handlingen till facksystemet. Facksystemet förblir sanningskällan — lagret konkurrerar inte, det *stänger gapet* mellan inkorg och akt.
3. **Allt i kommunens egen drift.** Data lämnar aldrig den egna miljön. Det är förutsättningen för att över huvud taget få arbeta med den känsligaste informationen.

---

## 2. Det nya arbetssättet: åtgärd-först

Skillnaden mot en vanlig inkorg är att lagret är byggt **åtgärd-först, inte information-först**. Handläggaren möts inte av en lista att tolka, utan av *nästa sak att göra* — och knappen som löser den lyser upp.

| Vanlig inkorg (information-först) | Operativt lager (åtgärd-först) |
|---|---|
| "Här är 40 mejl, lista ut vad du ska göra" | "Det här kräver åtgärd nu — tryck på knappen som lyser" |
| Handläggaren väljer kanal (mest felbenägna momentet) | Systemet väljer säker kanal automatiskt ("Smart mottagare") |
| Frister i huvudet / på post-it | Fristerna räknar ner själva, med påminnelser till rätt person |
| "Ringa och kolla att faxen kom fram" | Leveranskvittens: Skickad → Levererad → Öppnad → Läst |
| Mappar och behörighet skapas manuellt | Ärenderum + behörighet + gallringsregel i ett klick |

**Varför det här sparar tid och minskar risk:** det tar bort de många små, repetitiva och felbenägna besluten (vilken kanal? var lägger jag filen? vilken frist? har det kommit fram?) och gör dem till kod. En stressad handläggare får värde på *första klicket* utan att först lära sig ett system.

---

## 3. Nyttan per roll (personas)

Samma motor under huven — men varje roll möter en yta anpassad efter sitt uppdrag, sina lagkrav och sina frister. Det ger **bredd utan vertikala punktlösningar** (en leverantör, ett gränssnitt, en driftmiljö i stället för ett system per yrkesgrupp).

| Roll | Vardagsproblemet idag | Vad lagret gör | Konkret nytta |
|---|---|---|---|
| **Socialsekreterare** (barn & familj) | Orosanmälningar i flera kanaler; hårda frister (14 dgr / 4 mån); risk att barn faller mellan stolarna | Triage av anmälningar, auto-ärenderum, fristklocka, säker klientdialog, e-signerat beslut | "Ingen frist i huvudet, inget barn mellan stolarna." Tom kö = bevisat omhändertaget |
| **Registrator / nämndsekreterare** | Allt inkommande ska registreras i tid (JO: senast nästa arbetsdag), fördelas, byggas till nämndhandlingar | Förifylld registrering, fördelning, hela beslutskedjan digital (kallelse → justering → anslag → expediering) | Inget oregistrerat över en dag; felskick undviks (verifierad adress vs faxnummer); spårbart |
| **Kommunsjuksköterska (HSL)** | Missad "utskrivningsklar" = betalningsansvar (kostnad/dygn); samverkan med region | Deadline-driven utskrivningsbevakning, kr-riskindikator, samverkansavvikelse i ett klick, SIP-möte | "Aldrig en missad utskrivningsklar." Direkt kr-exponering synlig och hanterbar |
| **HR / chef** (rehab & personal) | Hälsodata/läkarintyg hamnar i öppen e-post; rehab-frister (dag 8/30) | Avskild, sekretessmärkt yta; fristremsa; säkra rehab-rum och möten | "Rätt frist, rätt kanal, aldrig öppen e-post." Avskilt från övrig kommunikation |
| **Överförmyndarhandläggare** | Deadline-låst årshjul (topp 1 mars); känsliga redovisningar | Granskningskö i takt mot fristen, komplettering, beslut + e-underskrift on-prem | Kö i takt mot 1 mars; känsliga uppgifter lämnar aldrig egen server |
| **Förvaltare / IT / infosäk** | "Är vi säkra? Kan vi bevisa efterlevnad? Är det värt pengarna?" — svaren i sju olika system | Sammanvägd efterlevnadsbild, incidentklockor (24h/72h), logg, ROI-underlag | Svar i den ordningen, utan sju system. Operativ data → automatisk efterlevnadsbild |

---

## 4. Tidsvinster och kvantifierad nytta

**Extern, citerbar grund (kanalen):** Digg uppskattar att säker digital kommunikation som ersätter fax, rekommenderade brev, bud och telefon sparar **~30 minuter per ärende**, motsvarande i storleksordningen **~1 620 mnkr/år** och **~3 500 årsarbetskrafter** nationellt. *Det är besparingen bara på att byta kanal.*

**Modellerad nytta ovanpå — arbetssättet (illustrativt, validera per kommun):**

| Moment som försvinner / automatiseras | Uppskattad besparing |
|---|---|
| Morgontriage i *en* kö i stället för att öppna 4–5 system/inkorgar | ~10–20 min/dag |
| Automatiskt kanalval (eliminerar dessutom felskick = sekretessincident) | ~2–3 min/meddelande + undviken incident |
| Auto-skapat ärenderum (mapp + behörighet + gallringsregel i ett klick) | ~5–10 min/nytt ärende |
| Leveranskvittens i stället för att ringa och kontrollera | ~5 min/delgivning |
| Frister som räknar ner själva med påminnelser | undviker *missad lagfrist* (IVO/JO-risk), inte bara tid |
| Lokal mötestranskribering + AI-utkast (när skarpt) | ~10–20 min/möte i minskad journalskuld |

**Försiktigt sammanvägt:** sparar arbetssättet bara **30 minuter per handläggare och dag** (utöver kanalbesparingen) motsvarar det **~2–3 veckors arbetstid per handläggare och år** — tid som går tillbaka till klient- och utredningsarbete i stället för administration.

> **Den viktigaste vinsten går dock inte att räkna i minuter:** en tom kö och en frist som aldrig missas är ett *rättssäkerhets- och patient-/barnsäkerhetsvärde*. Den dyraste händelsen är inte 30 spillda minuter — det är det missade ärendet.

---

## 5. Rättssäkerhet, sekretess och efterlevnad — inbyggt, inte påklistrat

Det operativa lagret gör efterlevnad till en **biprodukt av det dagliga arbetet** i stället för ett separat projekt:

- **Sekretess rätt:** känslig information *visas* för behöriga handläggare (det är hela poängen — de har laglig grund) men lagret är byggt så att information **aldrig läcker över en behörighetsgräns**. Behörighetsstyrning, inte informationsgömmande.
- **Datasuveränitet:** all data i kommunens egen drift, **0 tredjelandsöverföringar** — svaret på OSL- och CLOUD Act-oron, och på den nya cybersäkerhetslagstiftningen.
- **Spårbarhet & kvittens:** varje utskick och delgivning har en leveranstidslinje; varje överföring till facksystemet är dokumenterad.
- **Automatisk gallring/retention:** Hubs-kopian gallras efter bekräftad överföring; facksystem och e-arkiv förblir slutlagring.
- **Stark identitet:** verifierad motpart (BankID/Freja/SITHS) med synlig tillitsnivå (LOA) — inget gissande om vem man pratar med.
- **Incident- och efterlevnadsstöd:** klockor och sammanvägd statusbild för förvaltningens lag- och rapporteringskrav.

För en beslutsfattare: **lägre risk, bevisbar efterlevnad, och underlag som går rakt upp till nämnd/ledning.**

---

## 6. Varför detta är starkt — och svårt att kopiera

1. **Automatiskt kanalval** ("Smart mottagare") tar bort handläggarens mest felbenägna moment — genuint unikt mot dagens säker-meddelande-produkter.
2. **Kvittens som förstaklassdata** + synlig verifierad motpartsidentitet — den emotionella tryggheten ("kom det fram? till rätt person?") inbyggd.
3. **Ärenderummet** binder ihop meddelanden, möten och filer per ärende, on-prem — eliminerar molnberoendet som annars tvingar fram juridiska bedömningar.
4. **Roll-bredd utan vertikallås:** sex (och fler) yrkesroller på *samma* lager och driftmiljö — i stället för ett punktsystem per grupp.
5. **Stänger gapet inkorg ↔ facksystem** i stället för att ersätta facksystemet — låg tröskel, ingen "rip-and-replace".
6. **Allt i egen drift** — datasuveränitet som upphandlings- och efterlevnadsargument, inte bara en teknisk detalj.

---

## 7. Sammanfattande värdepunkter (för slides / det större dokumentet)

- **En vy, alla säkra kanaler** — slutet på system-hoppandet.
- **Åtgärd-först** — systemet visar nästa steg och löser det med ett klick; värde på första klicket.
- **Systemet väljer säker kanal** — rätt kanal varje gång, felskick (= sekretessincident) byggs bort.
- **Inga frister i huvudet** — klockor och påminnelser; tom kö = inget ärende glömt.
- **Ärenderum i ett klick** — säker dokumentyta med behörighet och gallring, automatiskt.
- **Kvittens i stället för att ringa** — se att delgivningen kom fram och lästes.
- **Allt i egen drift, 0 tredjelandsöverföringar** — datasuveränitet och efterlevnad inbyggt.
- **Stänger gapet till facksystemet** — kompletterar, ersätter inte; låg införandetröskel.
- **~30 min/ärende (Digg, kanalen) + ~2–3 veckor/handläggare/år (modellerat, arbetssättet)** — tid tillbaka till kärnuppdraget.
- **Den dyraste händelsen byggs bort** — det missade barnet, den missade utskrivningen, den läckta uppgiften.

---

*Tidssiffror: ~30 min/ärende, ~1 620 mnkr/år, ~3 500 årsarbetskrafter är Diggs nationella uppskattning för säker digital kommunikation. Arbetssätts-besparingarna är modellerade exempel avsedda att valideras mot kommunens egna volymer. Underlag finns i `analysis-output/` (personas, användningsmönster, marknads- och regulatorikanalys).*
