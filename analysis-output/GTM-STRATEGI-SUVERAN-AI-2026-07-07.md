<!--
Go-to-market-strategi för ITSL: Hubs + AI-minneslagret + open source-LLM:er,
offentlig och privat marknad, byggd på suveränitetsprofilen.
Beställd av Fredrik 2026-07-07. Underlag: 16-agenters workflow (2 interna läsare,
6 webbresearch-dossierer, 3 oberoende strategiutkast, 3 domare, red team,
kompletthetskritik) + PALANTIR-HUBS-ANALYS-2026-07-07.md. Syntesen följer
domarpanelens konvergerande dom: utkast A:s ryggrad (sekvens, finansieringskedja,
grinddisciplin), utkast B:s motor (pilotekonomi, kvalificeringsprofil, verticals),
utkast C:s röst (trelagerberättelsen, namnarkitektur, CLA) — härdad med red
teams fem krav och kompletthetskritikens operativa golv.
-->

# ITSL go-to-market: Det suveräna operativa lagret med eget AI-minne

*2026-07-07 · intern strategi · beslutsunderlag för VD/styrelse*

---

## 1. Sammanfattning — strategin på en sida

**Berättelsen är en mening:** *"Det suveräna operativa lagret med eget AI-minne — ni äger alla tre lagren."* Verksamhetslagret (Hubs), minneslagret (ert organisationsminne i er drift, gallringsbart och spårbart) och intelligenslagret (valbar självhostad open source-LLM). Kunden äger data, drift och — som enda erbjudande på svenska marknaden — även modellen. Det är den enda formulering som samtidigt differentierar mot Microsoft Copilot (som äger modellen), facksystemen (som äger datat) och "gör inget" (som inte kan svara inför tillsynen).

**Formen är ett disciplinerat dubbelspår:** kommunreferensen är ryggraden och privatspåret är kassaflödesmotorn — men båda styrs av hårda grindar, och allt börjar med en **verifieringssprint på 90 dagar** som falsifierar eller bekräftar strategins lastbärande antaganden innan de får kosta något. Vi antar att fönstret stängs vid **månad 18** (Microsofts suveränitetstvätt, Nextclouds AI-satsningar), inte månad 36 — tempot dimensioneras därefter.

**Fem icke förhandlingsbara regler**, härledda ur granskningen:

1. **Verksamhetsvärde leder, suveränitet avgör.** Varje pitch ska överleva frågan "håller den om Microsoft imorgon lovar svensk processing?". Suveränitet är huvudargument endast där den är tvingande (säkerhetsskydd, klientsekretess) — annars säljer vi gapet inkorg↔akt och kuraterat organisationsminne, med suveräniteten som tie-breaker.
2. **Ordet "träna" utgår ur vokabulären.** Vi bygger inte om modellvikter — vi bygger ett ägt, kuraterat, raderbart kunskapslager. Det är ett *starkare* löfte: en minnespost kan gallras enligt art. 17 GDPR; en tränad vikt kan aldrig glömma. Formuleringen "träna en brain" punkteras av varje kunnig CTO i kundmötet ("så det är RAG?") — det vi säljer som ingen bygger på en helg är **ingestionsmotorn och styrningen**: fem källsystem till ett kuraterat Evidence-schema genom en PII-brandvägg, inkrementellt, auditerbart, exporterbart.
3. **Suveränitetsgrinden är absolut.** Ingen extern leverans före PII-brandvägg PÅ + lokala embeddings (idag går rå text till US-endpoint för embedding — det åtgärdas i v1-svepet innan något säljs). Ett enda ertappat brott mot det egna löftet bränner positioneringen i två små marknader som pratar med varandra.
4. **Ingen nollpris-ingång, någonsin.** Betald ritual (Hubs-dagen, Minnesdagen), betalda designpartner-piloter, transparent publicerad prislista. Det är anti-Palantir-positionen operationaliserad och LOU-säkrad.
5. **Koden betraktas som förlorad; vallgraven är operationell.** CLA + kommersiell licensoption etableras inom 60 dagar, men försvaret byggs på det som inte kan forkas: referenser, svensk förvaltningsjuridik som schema, konnektorunderhåll som tjänst, flottdrift och den svenska evalueringssviten.

**Ekonomisk logik utan externt kapital:** M1-affären (säljbar idag) + betalda privatpiloter (engångsintäkt, aldrig bokförd som ARR) finansierar produktifieringen av minneslagret (~6–9 personmånader) och Hubs Fas 3-ledtider; Vinnova/Digital Europe söks som utspädningsfri hävstång. Grov ARR-bana (planeringsantagande): ~1,5–2,5 Mkr vid månad 12, ~5–8 Mkr vid månad 24, ~12–20 Mkr vid månad 36 — räknad på de långsamma säljcykelantagandena, inte de snabba.

---

## 2. Utgångsläget: fönstret är öppet — och det stängs snabbare än vi vill

Tajmingen är ovanligt god, och den är dokumenterbar i säljmaterial:

- **Juridiken har avgjorts offentligt.** Microsofts Frankrike-jurist svarade under ed i franska senaten (juni 2025) att han *inte* kan garantera att EU-data undanhålls amerikanska myndigheter — "Non, je ne peux pas le garantir". CLOUD Act-exponeringen är en ägarskapsfråga, inte en datacenterfråga, och den botas inte av EU Data Boundary. Samtidigt vacklar EU–US-adekvansbeslutet efter Trump v. Slaughter (Schrems III-scenariot är konkret).
- **Sverige har fått verktygen.** Nationella molnpolicyn (maj 2026) gör suveränitet till förvaltningspolicy; cybersäkerhetslagen (2025:1506, i kraft jan 2026) lägger ledningsansvar och leverantörskedjekrav på NIS2-sektorerna; nya socialtjänstlagen kräver systematisk uppföljning. Att Microsoft svarat med en Sverigeriktad suveränitetskampanj visar att trycket är verkligt.
- **Privat betalningsvilja är bevisad — som riskkontroll, inte ideologi.** Bitkom: 85 % av tyska företag anser US-beroendet för stort; ~40 % skulle välja inhemsk databehandling *även med reella nackdelar*. Mistral gick $20M→$400M+ ARR på privat/suverän deployment (HSBC, BMW, Airbus). Svenska Berget AI fick hundratals kunder på två månader — och visar samtidigt att ren inferens kommodifieras (€25–500/mån); värdet ligger i lagret ovanför.
- **Copilot-tröttheten är inbrytningspunkten.** Gartner: 60 % av Copilot-projekten fast i pilot, huvudskälen datasäkerhet och oklart värde. En majoritet av svenska organisationer har alltså AI-budget, en misslyckad pilot och ett dokumenterat sekretesskäl — vår kvalificeringsprofil (§6).
- **openDesk bevisar stacken.** 160 000+ licenser i tysk offentlig sektor på Nextcloud-basen — samma grund ITSL står på.

**Men red team-slutsatsen styr tempot:** Microsoft behöver inte vinna den juridiska debatten, bara göra den irrelevant för en upphandlare ("ingen fick sparken för att köpa Microsoft"). Sovereign-tvätt med svensk kontraktspart är 12–18 månader bort. Nextcloud ser varje framgångsrikt AI-koncept på sin plattform först av alla, och Context Chat/Assistant är deras kärnsatsning. Därför: **planera som om fönstret stängs månad 18.** Allt som ska bevisas måste vara bevisat då.

---

## 3. Berättelsen och varumärkesarkitekturen

### Tre lager, ett löfte

| Lager | Produkt | Kundens ägande |
|---|---|---|
| **Verksamhetslagret** | Hubs (M-modulerna): ärendemotor, åtgärd-först-GUI, säkra kanaler, konnektorer till facksystem | Data pekas på, kopieras aldrig; gallring efter verifierad commit; testad exit år 1 |
| **Minneslagret** | **Hubs Minne** (rekommenderat namn): organisationens kuraterade kunskapslager — ingestionsmotor, Evidence-schema, PII-brandvägg, pgvector-minnen i kundens drift | Varje post källhänvisad, raderbar (art. 17), TTL-gallringsbar, exporterbar i öppet format — läsbar utan ITSL:s mjukvara |
| **Intelligenslagret** | **Hubs Intelligens**: valbar resonemangsmotor i tre suveränitetsnivåer — frontier-API → EU-hostad → helt självhostad open source-LLM | Vid nivå 3 äger kunden även modellvikterna (Apache 2.0) och järnet |

**Mot offentlig köpare** formuleras löftet juridiskt: *"Er data har aldrig varit hos oss. Noll tredjelandsöverföringar, kod er dataskyddsorganisation kan läsa, testad exit som leveransmoment."* Anti-Palantir-vinkeln behålls ordagrant från Palantir-analysen: **"Vi byggde aldrig det domstolen fällde"** — aldrig "svensk Palantir".

**Mot privat köpare** formuleras löftet som risk- och sekretesskontroll: *"AI som er tystnadsplikt, ert säkerhetsskyddsavtal och er DORA-rapportering tål."* Anti-Copilot-vinkeln är den juridiskt oemotsägliga (CLOUD Act = ägarskapsfråga) plus den praktiska (Copilot exponerar felklassade dokument; vår åtkomstmodell ärver era behörigheter).

### Varumärke

**Ett paraply: Hubs.** Två varumärken halverar ett litet teams kommunikationskraft. Produktfamiljen: **Hubs Verksamhet** (M-modulerna), **Hubs Minne** (minneslagret; *Hubs Memory* internationellt), **Hubs Intelligens** (LLM-lagret), **Hubs Konnektorer**. "Minne" är avsiktligt: det beskriver vad produkten är (kuraterat, sökbart, gallringsbart organisationsminne), är styrelsebegripligt och dödar träningsmetaforen. "Nate Open Stack" förblir internt utvecklingsnamn och externaliseras aldrig som varumärke. Alternativen ur utkasten ("Verket", "Hemvist") noteras — **namnbeslutet är VD:s, före första externa Minne-material** (beslutspunkt §14).

Ett andra märke byggs senare, när det finns något att certifiera: **Hubs Certified Partner** — det som skiljer betalande partner från AGPL-fripassagerare i kundens ögon.

### Vokabulärlagen (gäller även internt, från idag)

| Vi säger aldrig | Vi säger |
|---|---|
| "träna er LLM / träna en brain" | "vi bygger ert ägda kunskapslager — kuraterat, källhänvisat, raderbart" |
| "AI som förstår allt" | "varje svar spårbart till källposter ni kan visa upp" |
| "vi ersätter Copilot" | "vi levererar det er Copilot-pilot inte kunde bevisa: styrning, sekretess, spårbarhet" |
| "träning" om LoRA | "beteendeanpassning — ton och format, aldrig kunskap i vikter" (och endast ur karantän, se §4) |

Detta är inte semantik. Red team-analysen är entydig: träningsmetaforen punkteras i första tekniska kundmöte och tar förtroendet med sig — medan kuratering + styrning + raderbarhet är det löfte ingen konkurrent i "lägg-din-data-i-en-RAG"-klassen levererar. Ärligheten ÄR differentieringen.

---

## 4. Erbjudandet och prissättningen

### Hubs (offentlig sektor)

- **Hubs Meddelanden** (M0+M1) — enda produktionsfärdiga modulen idag; ankarprodukt och kilen in. Subscription per användare/år, obegränsade försändelser på egen infrastruktur (mot Kivras 1,50–3 kr/försändelse).
- **Hubs Samarbete** (M2 video/chat, M3 filer) — tillägg.
- **Hubs Verksamhet** (M4: ärendemotor, Min dag, grindar, åtkomstlogg) — säljs som betalt designpartnerskap tills Fas 3 (Frends/Inera-ledtider) är klar; produktionsavtal därefter. Säljs aldrig före K-7-lagret + en live-konnektor.
- **Hubs Konnektorer** — separat prissatt subscription per facksystem och år (Treserva först). **Det kunden betalar för är underhållet** vid facksystemens API-ändringar — det kan en AGPL-fripassagerare inte kopiera. Detta är den ackumulerande intäktsenheten.

### Hubs Minne (privat först, offentlig icke-sekretess därefter)

Ärlig trenivåtrappa, allt i kundens drift:

1. **Nivå 1 — Kunskapslagret** (kärnprodukten, ~8 av 10 affärer): ingestionsmotor mot kundens källor (M365/Teams/Exchange/SharePoint byggs som grundpaket, ~1–2 pm), Evidence-schema, PII-brandvägg med stance-styrning, GDPR-mallpaket, minnen i kundens drift, MCP-åtkomst från valfri klient. Resonemang via valbar modell. *Data i vila 100 % suverän.*
2. **Nivå 2 — Beteendeanpassning (LoRA)** — **i karantän tills DPIA-processen finns.** Ekonomi-granskningen är entydig: tränas en adapter på kundtexter hamnar persondata i vikter (art. 17-oraderbart) och ITSL kan bli modifierande aktör med GPAI-följdplikter under AI-förordningen. Erbjuds först med obligatorisk DPIA + dokumenterad PII-tvätt av träningskorpus per körning. Tills dess: finns inte i prislistan.
3. **Nivå 3 — Suverän intelligens**: självhostad Mistral 3/gpt-oss-120b bakom vLLM i kundens miljö eller hos suverän driftpartner (Safespring/Binero/Hetzner — utanför CLOUD Act). Levereras med ärlig kvalitetsdeklaration mot vår svenska evalueringssvit.

**Brain-portabilitet som produktlöfte** (kompletthetskritikens självmålsvarning): exit-berättelsen gäller även AI-lagret. Dokumenterat exportformat (Evidence-schema + embeddings), minnet läsbart utan ITSL:s mjukvara, exit-test även för Minne. Ingen konkurrent lovar det — Palantir-analysens "spegelvända vallgrav" fullbordad i AI-lagret.

### Prislogik

Strukturen (nivåerna sätts av Sandra mot Adda-benchmark — beslutspunkt §14):

- Hubs: per användare/år, transparent publicerad — riktmärken ur utkasten: Bas ~300–500 kr/anv/år, Verksamhet ~900–1 400 kr/anv/år; konnektor ~60–150 tkr/år per facksystem och kommun.
- **Minne prissätts per minne** ("per brain") + per källkonnektor + intelligensnivå — grovt 8–20 tkr/mån per organisationsminne inkl. 2 källor, +2–4 tkr/mån per extra källa; nivå 3 +10–25 tkr/mån (GPU till självkostnad + förvaltning; marginalen ligger i minneslagret, aldrig i järnet). Marknaden saknar per-minne-prissättning — vi definierar enheten.
- **Ritualer och piloter är betalda:** Minnesdagen ~50 tkr (kvalificerar köparen, finansierar säljkostnaden), designpartner-pilot 300–500 tkr fast (8–10 veckor, konsultledd). **Bokförs alltid som tjänsteintäkt, aldrig ARR** — grindarna mäts på riktig återkommande intäkt.
- Publicerad prislista är i sig differentiatorn i ett fält där alla gömmer priser bakom offert — och motmedlet mot Palantir-mönstret nollpris→kostnadsexplosion.

---

## 5. Marknader och sekvens

### Våg 1 (ryggraden, startar nu): EN kommunal referens

ICP med A-utkastets skärpa: kommun 20–80 000 invånare, Treserva eller Lifecare, egen eller förbundsdriven IT, en digitaliseringschef som redan citerar molnpolicyn och en socialchef pressad av nya SoL:s uppföljningskrav. **Kommunalförbund/gemensam drift prioriteras** (flera kommuner per affär). Referensen tas som *betalt designpartnerskap* — aldrig gratis (LOU + New Orleans-läxan). Allt annat i strategin är villkorat av att denna referens når **verklig användning** (dagliga aktiva handläggare, inte licenser — NHS-läxan).

**Första-kund-shortlist är leverabel vecka 1–2 i verifieringssprinten** (kompletthetskritikens punkt 1): 5–10 namngivna kommuner/förbund med relationsväg in, plus 3–5 advokatbyråer/vårdgivare ur Fredriks/Rebeccas faktiska nätverk. Utan namn är referensen en abstraktion.

### Våg 2 (kassaflödesmotorn, startar efter verifieringssprinten): privata sekretessprofessioner

Kvalificeringsprofilen framför allt (B-utkastets skarpaste bidrag): **sälj till den som redan har AI-budget + en misslyckad Copilot-pilot + ett dokumenterat sekretesskäl.** Vi går in efter att default-alternativet självdött, inte före. Ordningen — omkalibrerad efter realismgranskningen:

1. **Advokatbyråer först** (kortast väg): bevisad extrembetalningsvilja (Legora: $100M ARR på 18 månader), färdig inköpsmekanism (Advokatsamfundets ramavtalslista feb 2026 + uppdaterad vägledning apr 2026), och en öppen suveränitetslucka efter Legoras USA-flytt. Pitch: "ert minne på er ärendehistorik, i er drift" — det ingen av de fyra godkända leverantörerna erbjuder. Max **en** designpartner-pilot åt gången (Fredrik-taket).
2. **Privata vårdgivare** (ersättningssälj, inte missionssälj): AI-adoptionen redan bevisad (Tandem-vågen hos Capio/Aleris/Praktikertjänst), suveränitetskritiken redan publik (Fokus-granskningen om patientdata i utländska moln).
3. **Försvarets underleverantörskedja** — strategiskt starkast (SUA-kaskaden *tvingar* köp; GlobalEye-kedjan växer; inget svenskt SME-alternativ finns; köparen är ofta VD) men **säljs först när ITSL:s egen SUA-beredskap finns**: registrering och säkerhetsprövning av personal påbörjas månad 1 (ledtid 12+ mån), affärerna tas från månad 12–15.
4. **NIS2-energibolag och DORA-nischen under storbankerna** (sparbanker, förmedlare, betalningsinstitut — kan inte självbygga, exit-krav enligt DORA art. 28 gör "open source i egen drift" till enklaste rapporteringsposition mot FI) — våg 3-segment, öppnas via partner.

Tillverkning tas opportunistiskt; media bevakas som *komponentkälla* (det nationella svenska språkmodellsinitiativet från Bonnier/Schibsted/WASP kan bli exakt den suveräna open-vikts-modell vårt intelligenslager hostar), inte som kundsegment.

### Våg 3 (18–36 mån): kommunala minnen + partnerskala

**Kommunala Hubs Minne på icke-sekretessdata** till befintlig kundbas: kunskapsbank, rutiner, riktlinjer, styrdokument, KS-protokoll → "vad säger vår riktlinje om X". Juridiskt okontroversiellt (BESLUT-09 om AI på sekretess respekteras), träffar nya SoL:s kunskapskrav, noll ny säljkostnad. Detta är den kapacitetsbilligaste expansionen i hela strategin — och den förutsätter den kundlivscykelmotion (onboarding → adoption → merförsäljning M1→M4→Minne) som Rebecca äger från dag 1.

---

## 6. Säljmotion och kanal

**Direktsälj till referenser och designpartners (våg 1–2) — kanal som växel två, aldrig växel ett.** Alla tre domarna underkände partnerkanal som primärmotor: integratörer replikerar bevisade affärer, de skapar dem inte. Sekvens:

1. **Ritualerna** (betalda, LOU-granskade, alltid syntetisk data):
   - **Hubs-dagen** — förmiddag: Min dag i kommunens testmiljö; eftermiddag: kommunens egen ärendetyp konfigurerad live som `ArendeTyp`-rad ("er ärendetyp på tio minuter" — det Palantir behöver en FDE-vecka för). Körs först när live-konnektorn är sann.
   - **Minnesdagen** — en dag hos privat kund: deras egen icke-känsliga korpus genom ingestionsmotorn, fungerande minne vid dagens slut, ~50 tkr fast.
2. **Vägen in per segment:** advokat via Advokatsamfundets vägledningsspår + managing partners i eget nätverk (verifieras i sprinten, §8); vård via DSO/medicinskt ansvarig med Fokus-granskningen som dörröppnare; SUA via säkerhetsskyddskonsulter och SOFF-nätverket; kommun via förbund + Adda DIS.
3. **LOU-vägen:** aldrig egna ramavtal — underleverantör/programvarupart hos Atea/Redpill Linpro (Element-i-BWI-mönstret: integratören bär kontrakt och SLA, ITSL tar produktsubscription). Men med red teams regel: **dela aldrig leveransmetodik med en utvecklingskapabel partner före CLA + kommersiell licens + partnerns sunk cost.** Varje integratörsrelation behandlas som potentiell konkurrent med exitplan.
4. **Ekosystem som hävstång, förhandlat som försäkring:** Nextcloud ISV-avtal *med roadmap-insyn och co-sell* (inte bara distribution); AGPL-apparna i app store som leadmotor; närvaro på Nextcloud Conference; positionering för en svensk openDesk-motsvarighet.
5. **Peer-motorn:** årligt kommunnätverk (AIPCon i miniatyr), delbara ArendeTyp-definitioner och mallpaket, varje vinst pressmeddelas. Kommuner köper på peer-bevis.
6. **Institutionella hävstänger** (kompletthetskritikens gratisoptioner): **AI Sweden-partnerskap** (trovärdighet åt svenska eval-sviten via sampublicering, kommunala AI-nätverk som leads, rekryteringskanal) och **Vinnova/Digital Europe-ansökan** med referenskommunen som partner — kan finansiera Minne-produktifieringen (6–9 pm) utan utspädning.
7. **Marknadsmotorn får ett program** (inte bara "pressmeddela vinster"): Sandra äger innehållsserie kring CLOUD Act/molnpolicyn/DPF-risken, kanalval (Socionomdagarna, KOMMITS, Almedalen), och konkurrentbevakningen (§12).

---

## 7. Open source-LLM-strategin

Ramen är red teams kalibrering: **intelligenslagret får aldrig bära affärsvärdet.** EU-hostade frontier-API:er och kommodifierad inferens (Berget-mönstret) konvergerar inom 18–24 månader — allt bestående värde ligger i minneslagret (data, schema, gallringsbarhet, PII-styrning) och verksamhetslagret. Självhostad LLM är en *valbarhet som fullbordar suveränitetslöftet*, en produktkomponent — inte produkten.

- **Modellval:** **Mistral 3-familjen primärt** (Apache 2.0 + EU-hemvist — licens och jurisdiktion i ett) och **gpt-oss-120b** som kvalitetsalternativ (Apache 2.0, kör på en 80 GB-GPU). **Aldrig Llama** (licensens EU-klausul är oacceptabel risk mot offentlig kund). Kinesiska modeller används inte i kundleveranser — säljbarhetsrisken räcker oavsett teknisk ofarlighet. Bevaka OpenEuroLLM, AI Swedens GPT-SW3-spår och det svenska medieinitiativet som framtida komponentkällor.
- **Tekniktrappan** (ur produktifieringsanalysen): steg 1 — lokala embeddings (TEI/e5) + metadata lokalt: **görs nu, är förutsättningen** (P2). Steg 2 — suveräna RAG-svar via vLLM-gateway: ~1–2 pm, erbjuds från månad 6–9 som premium. Steg 3 — suverän agent-loop på öppen modell: FoU tills tool-calling-pålitligheten bevisats mot egna smoke-sviter; säljs inte innan dess. Claude-beroendet i agent-runnern redovisas ärligt under tiden ("resonemangslagret i tre suveränitetsnivåer").
- **Svenskan görs till produkt:** en **egen svensk evalueringssvit** (förvaltningsspråk, socialtjänsttermer, juridisk svenska; EuroEval som bas) körd mot varje modellkandidat — grind för varje nivå 3-leverans och säljbar artefakt ("vi bevisar svenskan innan ni köper"). Ärlig förväntan mot kund: 70–80 % av arbetslasterna klarar självhostat utan märkbar regression; komplex agentik gör det inte ännu. Sviten sampubliceras om möjligt med AI Sweden. **Den existerar inte idag — v0 byggs i verifieringssprinten**, innan den säljs.
- **Hårdvaruekonomi:** MoE-generationen gjorde en-GPU-drift verklig: 1× H100/H200 (~0,5 Mkr) bär Mistral Large 3/gpt-oss-120b; hyrd suverän EU-GPU ~25 k€/år 24/7 (break-even mot köp ~1,5–2 år). Tumregel till kund: nivå 3 kostar 300–600 tkr/år i infrastruktur + subscription. ITSL äger aldrig järn — GPU-drift går via Safespring/Binero/Hetzner.

---

## 8. Fasplan med grindar

### Fas 0 — Verifieringssprinten (månad 0–3) · *red teams krav: falsifiera innan det kostar*

Inga våg 2/3-timmar får konsumeras innan dessa är gröna eller röda:

| # | Test | Grönt = |
|---|---|---|
| V1 | Första-kund-shortlist byggd (5–10 kommuner/förbund + 3–5 privata med namngiven relationsväg) | Lista med ägare per namn, vecka 2 |
| V2 | 5 betalningsvilje-möten i privat segment (advokat/vård) dokumenterade | ≥2 vill se Minnesdagen mot betalning |
| V3 | Skriftlig avsiktsförklaring från EN driftpartner (Safespring/Binero) | Signerad LOI |
| V4 | Designpartneravtal med referenskommun | Signerat, betalt |
| V5 | v1-svepet: PII-brandvägg PÅ + lokala embeddings i drift | Suveränitetsgrinden sann |
| V6 | Svensk eval-svit v0 körd mot 2 modellkandidater | Resultat dokumenterat |
| V7 | CLA + licensarkitektur-granskning (AGPL/proprietär-gränssnittet, extern jurist) | Beslutat och infört |
| V8 | KLASSA-gapanalys + ISO 27001-förstudie; SUA-registrering påbörjad; säkerhetsprövningsfrågan utredd | Gap-lista med plan |
| V9 | Vinnova/Digital Europe-ansökan utkast med referenskommunen | Inlämningsbar |

**GO/NO-GO månad 3:** V2 rött (ingen privat betalningsvilja i eget nätverk) → privatspåret pausas, allt fokus på kommunreferensen. V4 rött → ingen expansionsplanering alls, M1-affären är bolaget tills löst.

### Fas 1 — Bevisa (månad 3–9)

- **Hubs:** K-7-lagret (åtkomstlogg + checkpoints + gallringskvitton) byggt (Johan/dev äger); Treserva-konnektorn live hos referenskommunen; SLA-dokument och PuB/DPIA-paket färdiga (före första anbud, §13); prislista publicerad.
- **Minne:** EN betald designpartner-pilot (advokat, 300–500 tkr) levererad av Fredrik efter v1-svepet; Minnesdagen paketerad; produktifiering pågår (avhårdkodning, käll-GUI, M365-paket — finansierad av piloten + ev. Vinnova).
- **GO/NO-GO månad 9:** referenskommunen i verklig användning (mål: ≥50 % av handläggarna aktiva veckovis) OCH piloten på väg mot subscription. Referens röd → all expansion fryses; en bränd referens i en skvallrig 290-kommunersmarknad är irreversibel.

### Fas 2 — Konvertera (månad 9–18)

- Pilot → Minne-subscription; andra designpartner (vård); SUA-affärer öppnas när registreringen är klar; nivå 3 (självhostad LLM) levererad hos första kund som kräver den.
- 2–3 kommuner via referensens förbund/kluster; Hubs-dagen körs mot shortlisten; Apollo-light så att en driftpartner kan uppdatera flottan; ramavtalsposition som underleverantör etablerad.
- **GO/NO-GO månad 18 (fönstergrinden):** ≥2 Mkr äkta Minne-ARR (ej pilotintäkt) ELLER ≥4 betalande kommuner. Båda röda → konsolidera till det spår som har starkast enskild kund; överväg exitklausulerna (§12).

### Fas 3 — Skala det som bevisats (månad 18–36)

- Partner tar volym (Hubs Certified Partner-programmet byggs *nu*, inte tidigare); kommunala Minnen till befintlig bas; NIS2/DORA-verticals via partner; konnektorfamilj 2–3 (Lifecare, e-diarium); federationsberättelsen för kommunkluster; uppföljningslagret mot nya SoL som ny modul.
- **Milstolpe månad 30:** >40 % av ny-ARR partnergenererad — annars fortsatt direktsälj i smala segment och nedskalad ambition.

---

## 9. Organisation och kapacitet

Realismgranskningens hårdaste dom var Fredrik-ekvationen: alla utkast gav honom 2,5–3 heltidsroller. Lösningen är **en huvudroll per person och halvår, explicit schemalagd**:

| Person | Månad 0–9 | Månad 9–18 |
|---|---|---|
| **Fredrik** | Minne: v1-svep → EN pilot → produktifiering. (Hubs-motorn: endast arkitekturbeslut) | Minne: pilot 2 + nivå 3-leverans; playbook dokumenteras från pilot 1 |
| **Johan + dev** | Hubs-motorn: K-7, konnektorn, ExApp-seamen, CI | M4-hårdning Fas 3; Apollo-light med Mattias |
| **Rebecca** | Referenskommunen + shortlist + kundlivscykeln (onboarding/adoption) | + Hubs-dagen som ritual, förbundsexpansion, partnerrelation (först nu) |
| **Sandra** | Prislista, avtalspaket (extern jurist), Vinnova-ansökan, konkurrentbevakning, innehållsserie | + ISO-resan, partnermarginalmodell |
| **Mattias** | Drift + support + smoke-sviter; supporttak bevakas (>30 % brandkårstid = intagsstopp) | Apollo-light som SIN produkt; partner-enablement drift |

**Rekryteringstriggers (inte datum):** verksamhetsnära ingenjör (FDE-mikroformat) när kommun 2 signerar; leveransingenjör som klonar pilot-playbooken när Minne-ARR passerar 2 Mkr. **Partnas bort:** juridik (extern advokat på retainer från månad 1 — DPIA, PuB, CLA, SUA-registrering), all GPU-/storskalig drift, volymsälj. Ingen säljrekrytering — ritualerna och kanalen är säljkåren.

---

## 10. Ekonomi

**Strikt intäktsseparation** (ekonomidomarens krav): **ARR** = subscriptions (Hubs-moduler, konnektorer, Minne per minne/källa/nivå, partnerlicens). **Tjänsteintäkt** = piloter, Minnesdagar/Hubs-dagar, exit-tester, ev. LoRA. Grindarna mäts uteslutande på ARR; tjänster hålls <30 % av omsättningen på 36 månaders sikt — vi bygger produktbolag.

**Finansieringskedjan utan externt kapital:** M1-subscriptions (säljbara idag) är kassaflödesgolvet → privatpiloternas tjänsteintäkt (2–3 × 300–500 tkr år 1) finansierar Minne-produktifieringen (6–9 pm) → kommun-ARR finansierar M4:s Fas 3-ledtider och Apollo-light → Minne-ARR finansierar partnerprogrammet. **Vinnova/Digital Europe** söks i Fas 0 som utspädningsfri accelerator av produktifieringen.

**Grov ARR-bana** (planeringsantagande på långsamma säljcykelantaganden, inte prognos): månad 12: ~1,5–2,5 Mkr ARR (+ ~1 Mkr tjänst); månad 24: ~5–8 Mkr; månad 36: ~12–20 Mkr (15–25 kommuner varav flera via förbund, 5–10 privata Minne-kunder, konnektorfamiljen ackumulerar). Banan tål inte att båda spåren underlevererar samtidigt — därav grindarna.

---

## 11. Vallgraven — byggd på insikten att koden är förlorad

AGPL-kärnan ger vem som helst fri drifträtt; CLA skyddar bara framtida kod; en reverse-engineerad konnektor kan underhållas av en storkonsult med 40 utvecklare. Därför:

**Juridiskt försvar (nödvändigt men otillräckligt):** CLA + kommersiell licensoption inom 60 dagar (Element-modellen — kräver bolagsrättsligt beslut om ägande av bidrag, §14); riktig licensarkitektur-granskning av AGPL/proprietär-gränssnittet (M4-ExApp-seamen är juridiskt klarerad men tekniskt orealiserad — den byggs innan proprietär M4 säljs); konnektorer och Minne-tjänsten i den proprietära zonen.

**Operationellt försvar (den verkliga vallgraven):**
1. **Referenser i verklig användning** — den enda tillgång som inte kan forkas.
2. **Svensk förvaltningsjuridik som schema** — OSL-grindar, gallringsbeslut, SoL-uppföljning, lagrum som schemafält. Det Nextcloud *strukturellt* aldrig bygger och en generisk RAG aldrig blir.
3. **Konnektorunderhåll som tjänst** — värdet är inte koden utan att den följer facksystemens API-ändringar.
4. **Apollo-light-flottdriften** — leveransförmågan som gör 30 instanser förvaltningsbara.
5. **Svenska evalueringssviten + PII-apparaten** — bevisbar kvalitet och styrning ingen hyrflyttar.
6. **Brain-portabiliteten som löfte** — kontraintuitivt men avgörande: att kunden *kan* lämna är varför den väljer oss framför alla som låser in.

---

## 12. Risker, kill-kriterier och bevakning

| Risk | Tidig signal | Motdrag / kill |
|---|---|---|
| **Microsoft suveränitetstvättar** (risk #1) | "EU Data Boundary räcker" vinner >hälften av mötena; MS-lansering med svensk kontraktspart + kommunreferens | Förberett ompositioneringspaket: verksamhetsvärde leder (inkorg↔akt, konnektorn, kuratering); suveränitet endast tvångssegment. Testas i varje säljdokument från dag 1 |
| **Nextcloud äter Minne-golvet** | Context Chat får Evidence-liknande schema/källkonnektorer | Differentiera enbart på förvaltningsjuridik + PII-stance + konnektorer; ISV-avtal med roadmap-insyn. **Tidig exitklausul:** skeppar Nextcloud governance-RAG → sälj Minne-spåret till/via dem i stället för frontalmöte |
| **Storkonsult forkar/går till Mistral direkt** | Partner rekryterar Hubs-utvecklare; bygger egen konnektor | Metodik delas aldrig före CLA + sunk cost; konnektorunderhåll + referenser som vallgrav; exitplan per partnerrelation |
| **Privat betalningsvilja är retorik** | V2 rött i sprinten; <1 pilot efter 20 kvalificerade möten | Privatspåret pausas/dödas; tekniken blir framtida Hubs-modul |
| **Referenskommunen faller** (NHS-läxan) | Aktiva handläggare <50 % månad 3 efter go-live | All expansion fryses; Hubs-dagen ställs in tills löst |
| **Suveränitetslöftet ertappas osant** | Intern audit före varje kundmöte | Absolut grind (V5); ett brott = positioneringen bränd |
| **Fredrik-flaskhalsen** | Leveransslip >1 kvartal på två milstolpar i rad | Rollschemat §9 upprätthålls; rekryteringstrigger tidigareläggs |
| **Supporttaket (Mattias)** | >30 % brandkårstid | Intagsstopp + Apollo-light tidigareläggs |
| **Self-hosted-premien smälter** | EU-frontier-API:er "good enough" i kunddialog | Planerad för: intelligenslagret bär aldrig affärsvärdet (§7) |
| **BESLUT-09 (AI på sekretess) förblir låst** | Myndighetsvägledning uteblir | Planen tål det: privat först, kommunala minnen på icke-sekretess |

**Bevakningen ägs av Sandra, månatlig kadens, kopplad till kill-signalerna:** Nextcloud-roadmap/releases, Microsoft Sverige-lanseringar, Adda-avrop, Legora/Harvey-rörelser, Mistral-partnerskap i Norden, Berget/Evroc-prispunkter. Utan ägd bevakning är kill-kriterierna omätta i praktiken.

**Exitklausuler (styrelseförankras, §14):** (a) månad 30: missas båda grindbenen → sälj konnektorfamiljen/bolaget till integratör medan referensen lyser; (b) när som helst: Nextcloud-triggern ovan; (c) privatspåret: dödas vid rött V2 eller noll konvertering månad 18 — tekniken behålls som Hubs-modul.

---

## 13. Operativa golvet — måste finnas före första anbudet

1. **SLA-dokument i siffror** — servicenivåer per allvarlighetsgrad, jourfråga löst (socialjourens kanal 02:00 är verksamhetskritisk myndighetsutövning), eskalationskedja, vitesexponering i partnerled.
2. **PuB-/DPIA-paketet** (BESLUT-12: "juridiskt osäljbar utan") + Minne-biträdesavtal + registerförteckningsmallar.
3. **KLASSA-svar och informationssäkerhetsbilaga** (ofta inträdeskrav, inte merit) + ISO 27001-plan; SUA: även *personalens* säkerhetsprövning utredd.
4. **Publicerad prislista + publicerat exit-protokoll** (inkl. Minne-export) — säljartefakter, inte formalia.
5. **Onboarding-/adoptionsprogram** för handläggare (NHS-läxan: konvertering ≠ användning) med mättal.

---

## 14. Beslutspunkter för VD/styrelse

| # | Beslut | Rekommendation | När |
|---|---|---|---|
| 1 | Produktnamn för minneslagret | **Hubs Minne** (alternativ: Verket, Hemvist) | Före första externa material |
| 2 | Vokabulärlagen — "träna" utgår, även i VD:s egen pitch | Anta ordagrant | Nu |
| 3 | Prislistans nivåer | Sandra validerar riktmärkena (§4) mot Adda-avrop | Fas 0 |
| 4 | CLA + kommersiell licensoption + ägande av bidrag | Inför (bolagsrättsligt beslut krävs) | ≤60 dagar |
| 5 | SUA-registrering + säkerhetsprövning av personal | Starta månad 1 (ledtid 12+ mån) | Nu |
| 6 | Ägarsamsyn om exitklausulerna (§12) | Styrelseförankra före Fas 1 | Fas 0 |
| 7 | LoRA-karantänen (ingen nivå 2 utan DPIA-process) | Anta | Nu |
| 8 | Vinnova/Digital Europe-ansökan | Skriv i Fas 0 med referenskommunen | Fas 0 |

---

## 15. Metod och underlag

Strategin syntetiserades ur en 16-agenters granskningsprocess (2026-07-07): två interna kodläsare (licens-/modulmodellen; Minne-produktifieringsläget — bl.a. att en konsultledd pilot är ~2–3 pm bort efter v1-svepet och paketerad produkt ~6–9 pm), sex webbresearch-dossierer (suveränitetsmarknaden, open source-LLM-landskapet, konkurrenter, regulatoriska drivare, GTM-mönster från Nextcloud/Element/Red Hat/Mistral, privata nordiska segment), tre oberoende strategiutkast (offentligt-först / dubbelspår / plattform-först), tre domare (realism 8-6-4, vinnbarhet 7-8-6, ekonomi/juridik 8-7-6 — konvergerande dom: "A:s ryggrad, B:s motor, C:s röst"), ett red team (fem härdningar, samtliga inarbetade) och en kompletthetskritik (tolv punkter, samtliga adresserade). Bygger vidare på `analysis-output/PALANTIR-HUBS-ANALYS-2026-07-07.md`. Samtliga delrapporter med källhänvisningar finns arkiverade i sessionens arbetsmaterial; sifferuppgifter om marknadsstorlek och konkurrenters intäkter är sekundärkällor med markerad osäkerhet.
