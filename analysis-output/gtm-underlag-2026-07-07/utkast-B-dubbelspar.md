GTM-STRATEGIUTKAST — LINS B: DUBBELSPÅRET
*ITSL, 2026-07-07. Utgångspunkt: PALANTIR-HUBS-ANALYS-2026-07-07.md (§7–10) + åtta research-dossierer. Tes: AI-fönstret 2026–2027 är öppet nu, privat sektor köper snabbare än kommuner — kör båda motionerna parallellt, med olika paketering på samma stack.*

---

## 1. Positionering & kärnbudskap

**Gemensam kärna (ett faktum, två dialekter):** *"Er data lämnar aldrig er. Er AI bor hos er. Koden kan granskas. Utgången är testad."* Detta är den spegelvända vallgraven från Palantir-analysen: samma operativa mekanik (begreppsmodell + handlingsmodell + styrningsmodell), motsatt dataarkitektur. Vi kopierar aldrig in kundens verklighet — vi pekar på den, arbetar i den och gallrar efter verifierad commit.

**Mot offentlig köpare (Hubs):** suveränitet formuleras som *rättslig försvarbarhet*. Budskapet är inte "europeiskt är fint" utan "ni kan svara inför IMY, IVO och er egen nämnd": NEVER-SoR betyder att motorn inte *förmår* masskorrelering (BVerfG-argumentet i positiv form), AGPL-kärnan betyder att er DSO kan läsa koden som sätter ACL:erna, testad exit är leveransmoment år 1. Anti-Palantir-vinkeln är explicit men disciplinerad: *"Vi byggde aldrig det domstolen fällde"* — aldrig "svensk Palantir". Sveriges nya molnpolicy (maj 2026) och cybersäkerhetslagen (i kraft jan 2026) citeras i varje säljdokument.

**Mot privat köpare (brain-spåret):** suveränitet formuleras som *risk- och sekretesskontroll*, inte ideologi — det är vad Bitkom-datat och Mistral-kundlistan (HSBC, BMW, Airbus) visar att privat sektor faktiskt betalar för. Kärnbudskap: *"Gör varje LLM till expert på er verksamhet — utan att er data någonsin lämnar huset eller bakas in i en modell."* Anti-Copilot-vinkeln är juridiskt oemotsäglig och ska köras hårt: Microsofts jurist bekräftade under ed att CLOUD Act når EU-data oavsett datacenter; Copilot-projekt fastnar dessutom bevisligen i pilot av datasäkerhetsskäl (Gartner: 60 % fast i pilot). Vi säljer till organisationer som *redan* har AI-budget, en misslyckad Copilot-pilot och ett dokumenterat sekretesskäl.

**Ärlighetsregel (icke förhandlingsbar):** vi säger aldrig "träna" om något vi inte gör. Erbjudandet är ett ägt kunskapslager (RAG) med träning (LoRA) och suverän inferens som *trappa*. Det är starkare, inte svagare: en brain-post kan raderas enligt art. 17 — en fine-tunad vikt kan aldrig glömma.

## 2. Segmentering & ICP

**Spår A — offentligt (Hubs):** ICP är en mellanstor kommun (30–100 kkr invånare) med Treserva eller Lifecare, dokumenterad frustration över gapet inkorg↔akt, och en digitaliseringschef som redan nosat på Nextcloud/openDesk-vågen. Ordning: **1 referenskommun** (allt underordnas den, per §7.6), därefter 3–5 kommuner via samma kommunalförbund/driftorganisation, därefter bredd via partnerkanal. Vertikal: socialtjänst barn & familj först (där motorn, mallbiblioteket och 12-rollersverifieringen redan bor), övriga kommunala roller som config-rader, inte nya produkter.

**Spår B — privat (brain), tre namngivna verticals i prioritetsordning:**

1. **Försvarsunderleverantörer (SUA-kedjan).** Starkast signal i researchen: SUA-regimen kaskaderar krav ned genom hundratals SME:er som *måste* ha säker miljö men saknar Saabs IT-budget; inget svenskt hyllalternativ finns; US-moln-AI är i praktiken uteslutet på nivå 1. Köparen är ofta VD direkt — utan SUA-avtal, inget kontrakt. Här är betalningsviljan regulatoriskt tvingande.
2. **Advokatbyråer.** Bevisad extrembetalningsvilja (Legora: $100M ARR på 18 månader), färdig inköpsmekanism (Advokatsamfundets vägledningar + ramavtalslista), och en öppen suveränitetslucka efter Legoras USA-flytt. Pitch: "er brain på er ärendehistorik, i er drift" — det ingen av de fyra godkända leverantörerna erbjuder.
3. **Finans-nischen under storbankerna** (sparbanker, förmedlare, betalningsinstitut). DORA träffar dem lika hårt som storbankerna utan att de kan självbygga; art. 28.8-exitkravet gör "open source i egen drift" till den enklaste rapporteringspositionen mot FI. Reversibilitet är här *lagkrav*, inte preferens.

Privata vårdgivare och NIS2-energibolag är våg 2 (bevisad AI-adoption respektive kommunlik säljmotion) — de öppnas först när minst en vertical i våg 1 konverterat. Vi säger uttryckligen nej till tillverkning och media i 18 månader.

## 3. Erbjudandetrappa & paketering

**Hubs (offentligt):**
- **Hubs Meddelanden** (M0+M1): säljbar produktion *idag* — ankaraffären och kilen in. Subscription per användare/år, transparent prislista (differentiator: hela svenska fältet gömmer priser bakom offert).
- **Hubs Verksamhet** (M0+M1+M4): demo/pilot efter Fas 1–2, produktion efter Fas 3. Säljs aldrig före K-7-lagret + en live-konnektor.
- **Konnektorer** (Treserva först): separat prissatt subscription per konnektor och år — den återkommande intäktsenheten och den proprietära vallgraven.
- **Drift & förvaltning**: via partner (se §5); ITSL tar produktsubscription.

**Brain-erbjudandet (privat) — ärlig trestegstrappa, allt i kundens drift:**
- **Nivå 1 "Kunskapslager" (RAG-brain):** ingestionsmotor + Evidence-schema + PII-brandvägg + pgvector-brains + lokala embeddings, frontier-API för resonemang (data i vila 100 % suverän). Innehåll: installation, 2–3 källkonnektorer (M365/Exchange/SharePoint prioriteras i bygget), stance-konfiguration, GDPR-mallpaketet. Detta är 8 av 10 affärer.
- **Nivå 2 "Anpassad modell" (+LoRA):** tunn adapter på öppen basmodell för ton/format/domänvokabulär, bakom samma RAG. Compute-kostnaden är löjligt låg (~400–1 200 USD per körning) — detta är tjänstemarginal, inte infraprojekt.
- **Nivå 3 "Suverän intelligens" (self-hosted LLM):** vLLM/SGLang + Mistral 3/gpt-oss-120b på 1× H100/H200 hos kunden eller hyrd EU-GPU (Hetzner/OVH/Scaleway — utanför CLOUD Act). Även inferensen slutar vara en överföring. Säljs med ärlig kvalitetsdeklaration mot vår svenska eval-svit.

**Prislogik:** designpartner-pilot 300–500 kkr fast (konsultledd, 8–10 veckor, Fredrik/teamet installerar); därefter subscription per brain-instans (grovt 15–40 kkr/mån beroende på nivå + antal källkonnektorer à 3–8 kkr/mån) + driftavtal. Ingen nollpris-ingång i något spår (LOU + Palantir-varningskatalogen §8).

## 4. Varumärkesarkitektur

**Ett bolag, två produktvarumärken, ett arkitekturlöfte.** ITSL som avsändare (litet bolag — trovärdigheten bor i personerna och referenserna, inte i paraplyet). **Hubs** förblir det offentliga varumärket. Brain-produkten får eget namn — förslag: **Hemvist** (arbetsnamn; juridisk klang, "er data har sin hemvist hos er", fungerar i både SUA- och advokatrummet; alternativ: Kärnminne, Insida). Skälen till separationen: (a) kommunköpare ska inte oroas av försvarslogotyper i referenslistan och vice versa; (b) brain-spåret måste kunna prissättas som premiumtjänst utan att smitta Hubs transparenta kommunprislista; (c) om ett spår dödas (§10) ska det kunna begravas utan att skada det andra. Delat mellan varumärkena: "Suverän per konstruktion"-arkitekturberättelsen och exit-protokollet som publicerad artefakt.

## 5. Kanal & säljmotion

**Offentligt — partner bär, ITSL levererar produkt (Element-i-BWI-mönstret):** Ett 5-personersbolag ska inte söka egna ramavtal. Bli underleverantör/programvarupart till Redpill Linpro och/eller Atea på Kammarkollegiets/Addas ramavtal; drift via Safespring/Binero eller kommunens egen. Säljritualen är **Hubs-dagen** (§7.1): förmiddag Min dag i kommunens testmiljö, eftermiddag kommunens egen ärendetyp konfigurerad live — designad som marknadsdialog, LOU-granskad, alltid syntetisk data, aldrig förhandsbindande. Expansionsmotorn är peer-bevis: kommunnätverk + delbara ArendeTyp-/mallpaket. Registrera ITSL i Nextclouds ISV-program — deras AI-investeringar ska vara medvind, inte överlapp.

**Privat — direktsälj, grundat i ritual:** ingen säljkår finns, alltså säljer vi som Palantir i miniatyr: **Brain-dagen** — en dags workshop hos kunden, deras egen (icke-känsliga) data genom ingestionsmotorn, en fungerande brain vid dagens slut, fast pris ~50 kkr som kvalificerar köparen och finansierar säljkostnaden. Vägen in per vertical: SUA-segmentet via säkerhetsskyddskonsulter och SOFF-nätverket; advokat via Advokatsamfundets vägledningsspår och 2–3 managing partners i Fredriks/Rebeccas nätverk; finans via DORA-konsulter. En SI-partner (typ Knowit/förvarsnära konsult) rekryteras som leveranskanal när pilot 3 är signerad, inte förr.

## 6. Sekvensering med GO/NO-GO

**0–6 mån (bevisa, sälj piloter):**
- Hubs: K-7-lagret (åtkomstlogg + checkpoints, §6.1–6.2) klart; Treserva-konnektor live i EN referenskommun påbörjad; Hubs Meddelanden aktivt sålt (mål: 2 nya M1-kunder); prislista publicerad.
- Brain: v1-svepet klart — **PII-brandväggen PÅ och lokala embeddings är absolut grind; innan dess är suveränitetslöftet inte sant och ingenting får säljas.** Därefter: avhårdkodning av instansen påbörjad, M365/Exchange-konnektor byggd, 2 betalda designpartner-piloter signerade (en SUA, en advokat), à 300–500 kkr.
- **GO/NO-GO vid mån 6:** minst 1 betald brain-pilot signerad OCH referenskommunens konnektorarbete på spår. Om noll piloter trots ~20 kvalificerade möten → privatspåret pausas till efterfrågan bevisats (se §10).

**6–18 mån (referenser, paketering):**
- Hubs: konnektorn live och *använd* (mätt i verklig användning hos handläggare, NHS-läxan); Hubs-dagen körd mot 5+ kommuner; partneravtal med Redpill Linpro/Atea signerat; M4-pilot i kommun 2–3.
- Brain: piloterna konverterade till subscription; installer + käll-GUI klart (produkten paketerad, ~6–9 pm ackumulerat); nivå 3 (self-hosted LLM) levererad hos minst en kund; svensk eval-svit publicerad som säljbar tillgång; ISO 27001-resa påbörjad (Proton-läxan: suveränitetsköpare kräver ändå certifikat).
- **GO/NO-GO vid mån 18:** brain-ARR ≥ 2 Mkr och churn noll, ELLER Hubs-spåret har 5+ betalande kommuner. Om *båda* spåren underpresterar är problemet inte fokus utan produkt — då konsolideras allt till det spår som har starkast enskild kund.

**18–36 mån (skala det som bevisats):**
- Hubs: Apollo-light (flottuppgraderingar via driftpartner), 15–25 kommuner via kanal, uppföljningslagret mot nya SoL som ny modul.
- Brain: SI-partner levererar, ITSL tar plattformssubscription; våg 2-verticals (vård, energi); ev. återkoppling in i Hubs ("läsagent" per Palantir-analysens §6.6 — först nu, aldrig tidigare, AI Act-analys först).

## 7. Kapacitetsplan — ärlig

Detta är strategins svagaste punkt och den ska bemannas, inte önskas bort. Fem personer klarar inte två fullskaliga motioner; de klarar **en produktmotion (Hubs via partner) plus en konsultledd pilotmotion (brain)**.

- **Fredrik:** brain-spårets arkitekt och pilotleverantör + Hubs teknisk rikting. Hans tid är flaskhalsen — därför max 2 samtidiga brain-piloter, någonsin.
- **Rebecca:** äger kundteamet = Hubs-referenskommunen och Hubs-dagen. Hon ska *inte* dras in i privatspåret före mån 12.
- **Sandra:** prislista, avtalspaket (med extern jurist), marknadsmaterial för båda varumärkena, ISO-resan.
- **Mattias:** drift av kundinstanser + brain-installationer; hans supportbörda är den verkliga taket för antal kunder — Apollo-light är därför en investering i Mattias kapacitet.
- **Johan (CTO):** utanför Open Stack-scope per beslut; ansvarar för Hubs-kodbasens kvalitet och K-7-bygget.

**Rekryteras/partnas bort:** (1) en verksamhetsnära ingenjör (FDE-mikroformat, §7.3) vid första kommunexpansionen; (2) all storskalig drift → Safespring/Binero/kundens IT; (3) juridik → extern advokat på retainer (DPIA, PuB, SUA-registrering av ITSL självt — påbörjas mån 1, ledtiden är lång); (4) sälj i privatspåret efter pilot 3 → SI-partner. Första anställning när brain-ARR passerar 2 Mkr: en leveransingenjör som klonar Fredriks pilotplaybook.

## 8. Open source-LLM-strategin

**Modellval:** Mistral 3-familjen (Apache 2.0, EU-hemvist) som primär referensstack; gpt-oss-120b som kvalitetsalternativ (Apache 2.0, en 80 GB-GPU). **Llama undviks** (EU-klausulen är oacceptabel licensrisk mot offentlig sektor); kinesiska modeller används inte i kundleveranser mot offentlig/försvarsnära sektor oavsett teknisk ofarlighet — säljbarhetsrisken räcker.

**Svenskan är den kända svagheten och görs till tillgång:** vi bygger en egen svensk evalueringssvit (förvaltningsspråk, socialtjänsttermer, juridisk svenska) som körs mot varje modell före kundlöfte, EuroEval som bas. Sviten är i sig säljbar och blir grinden för varje nivå 3-leverans: kunden får se exakt vad den självhostade modellen tappar mot frontier (ärlig förväntan: 70–80 % av lasterna klarar sig utan märkbar regression; komplex agentik gör det inte).

**Hårdvaruekonomi:** MoE-generationen flyttade riktig kvalitet till en-GPU-servrar — kommunal/SME-on-prem är realistisk. Tre driftsformer offereras: (a) kundens järn (~0,4–0,7 Mkr investering, H100/H200-klass), (b) hyrd EU-GPU utanför CLOUD Act (Hetzner GEX/OVH/Scaleway, ~25 k€/år vid 24/7 — break-even mot köp ~1,5–2 år), (c) frontier-API för icke-känsliga laster. Serving: vLLM default, SGLang för RAG-tunga laster (våra). **Tidpunkt:** nivå 3 offereras från mån 6 (efter att lokal embeddings-stack bevisats i egen drift), levereras första gången mån 9–12. Agent-loopen på öppen modell (steg 3 i dossiern) är FoU till dess smoke-sviten bevisar tool-calling-pålitlighet — den säljs inte innan dess.

## 9. Ekonomi

**Intäktsmodell:** allt är subscription utom piloter och Brain-/Hubs-dagar (fast pris, kvalificerande). Hubs: per användare/år (M1) + per modul + per konnektor/år; transparent publicerad. Brain: pilot (300–500 kkr) → subscription per brain-instans + källkonnektor + driftavtal; nivå 3 adderar hårdvaru-/GPU-marginal eller partnerdrift.

**Grov ARR-bana (planeringsantagande, inte prognos):**
- **År 1:** ~1,5–2,5 Mkr — dominerat av brain-piloter (2–3 à 300–500 kkr) + 2–3 M1-kommunkunder + befintlig bas. Piloterna finansierar produktifieringen (6–9 pm) — det är hela poängen med dubbelspåret: privat kassaflöde betalar paketeringen medan kommunreferensen mognar.
- **År 2:** ~4–7 Mkr — 3–5 brain-subscriptions (2–3 Mkr), 5–8 Hubs-kommuner varav 1–2 med M4+konnektor (2–4 Mkr).
- **År 3:** ~10–15 Mkr — kanalen bär Hubs (15+ kommuner), SI-partner bär brain-leveranser, konnektorfamiljen börjar ackumulera.

**Vad finansierar vad:** brain-piloter (snabb, hög marginal) finansierar brain-produktifiering och del av Hubs K-7/konnektorbygge; M1-subscriptions täcker basdrift; inget externt kapital förutsätts, men banan tål inte att båda spåren underlevererar samtidigt (därav kill-kriterierna).

## 10. Risker & kill-kriterier

| Risk | Tidig signal | Motåtgärd/kill |
|---|---|---|
| **Fokussplittring dödar båda spåren** (huvudrisken med Lins B) | Fredrik >50 % kontextväxling; referenskommunens milstolpar slirar två sprintar i rad | Hård regel: Hubs-referenskommunen har alltid företräde vid konflikt; max 2 samtidiga brain-piloter. Om regeln bryts två gånger → pausa privatspåret |
| **Brain-efterfrågan är retorik, inte budget** | <1 signerad pilot efter 20 kvalificerade möten (mån 6) | KILL privatspåret som egen motion; behåll tekniken som framtida Hubs-modul |
| **Suveränitetslöftet ertappas som osant** (PII-brandvägg av, rå text till US-endpoint) | Intern audit före varje kundmöte | Absolut grind: ingen extern demo före P2. Ett enda ertappat brott bränner hela positioneringen i två små marknader som pratar |
| **Hyperscaler sovereignty-washing tar 80 % av marknaden** | Prospekt svarar "EU Data Boundary räcker" i >hälften av mötena | Skärp till det juridiskt oemotsägliga (CLOUD Act = ägarskapsfråga); fokusera verticals där "good enough" inte räcker (SUA, advokat) |
| **Nextcloud Context Chat äter brain-golvet** | Roadmap-bevakning kvartalsvis | ISV-positionering; differentiera på ingestion + PII + governance, aldrig på generisk fil-RAG |
| **Referenskommunen misslyckas / konnektorn förblir stub** | Verklig användning <50 % av handläggarna mån 12 | Ingen Hubs-expansion, ingen Hubs-dag mot nya kommuner förrän löst — en bränd referens i 290-kommunersmarknaden är irreversibel |
| **Supportbördan kväver Mattias** | >30 % av hans tid på brandkårsutryckningar | Apollo-light tidigareläggs; kundintag fryses tills driften är flottbar |
| **Nyckelpersonrisk (Fredrik)** | — | Pilotplaybook dokumenteras från pilot 1; leveransingenjör rekryteras vid 2 Mkr brain-ARR |

**Strategins ärliga kärna:** Dubbelspåret är rätt *därför att* spåren har olika klockor — kommunaffären är långsam men djup (konnektorer + referenser = vallgrav), privataffären är snabb men grund (tekniken kommodifieras; Berget AI visar att ren inferens redan pressats till €25/mån). Vi använder den snabba klockan för att finansiera den långsamma, säljer aldrig något som inte är sant (ingen "träning" som inte är träning, ingen suveränitet med brandväggen av, ingen bootcamp mot en stub), och vi har på förhand bestämt vad som dödar vilket spår. Det är skillnaden mellan ett dubbelspår och ett dubbelt vågspel.

*— Slut på utkast. Källförankring: PALANTIR-HUBS-ANALYS-2026-07-07.md §5, §7.1–7.6, §8, §9, §10 samt dossiererna licens-moduler, brain-produktifiering, suveranitetsmarknad, opensource-llm, konkurrenter-enterprise-ai, regulatoriska-drivare, gtm-monster, privat-segment-norden.*