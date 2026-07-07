# GTM-strategiutkast — Lins C: "Plattformisten"

*En berättelse, ett paraply, partnerburen skala. Utkast 2026-07-07, baserat på PALANTIR-HUBS-ANALYS §7–10 samt åtta research-dossierer.*

---

## 1. Positionering & kärnbudskap

Tesen säljs som EN mening: **"Det suveräna operativa lagret med eget AI-minne — ni äger alla tre lagren."** Tre lager, tre ord: **Verksamhetslagret** (Hubs: ärendemotor, säkra kanaler, åtgärd-först-GUI), **Minneslagret** (brains: ert organisationsminne i er drift, gallringsbart, spårbart), **Intelligenslagret** (valbar självhostad open source-LLM). Kunden äger data, drift och — som enda leverantör på svenska marknaden — även modellen.

**Mot offentlig köpare** formuleras suveräniteten juridiskt, inte ideologiskt: "Er data har aldrig varit hos oss. Noll tredjelandsöverföringar, granskningsbar kod er DSO kan läsa, testad exit som leveransmoment år 1." Anti-Palantir-vinkeln är rapportens §7.4 ordagrant: *"Vi byggde aldrig det domstolen fällde"* — samma operativa mekanik (åtkomstlogg, motiveringstvång, verifierad writeback), spegelvänd vallgrav (NEVER-SoR, pekare i stället för kopior, gallring efter verifierad commit). Varje BVerfG- och NHS-rubrik är gratis marknadsföring. Regeringens molnpolicy (maj 2026) och cybersäkerhetslagen (jan 2026) är dörröppnarna: detta är numera styrelsefråga, inte IT-fråga.

**Mot privat köpare** formuleras suveräniteten som riskeliminering, inte Europa-ideologi (Bitkom-datat visar att man köper riskkontroll): "AI som er tystnadsplikt, ert SUA-avtal och er DORA-rapportering tål." Anti-Copilot-vinkeln är det juridiskt oemotsägliga: Microsofts egen chefsjurist kunde under ed inte garantera data mot CLOUD Act — datacenterplacering botar inte ägarskapsfrågan. Plus Copilot-tröttheten: 60 % fast i pilot, dokumenterade läckor av felklassade dokument. Vi säljer inte "bättre AI" — vi säljer *AI ni kan svara för inför tillsynsmyndigheten* (AI Act art. 26-loggning, EDPB 28/2024-aligned behandling inom egen miljö).

Ärlighetsregeln är absolut: vi säger aldrig "träna er LLM" om RAG. Formuleringen är *"vi bygger ert ägda kunskapslager som gör varje modell expert på er verksamhet — utan att data bakas in i vikter som aldrig kan glömma"*. Det är ett starkare GDPR-argument än träningsmetaforen, och det är sant.

## 2. Segmentering & ICP

Plattformistens logik: välj segment där **en partner kan replikera affären**, inte där ITSL säljer bäst själva.

**Våg 1 (nu): Kommunal socialtjänst — fyrtornet.** EN referenskommun med live-konnektor är grinden för allt (§7.6). Inte förhandlingsbart. ICP: mellanstor kommun (30–80 tkr invånare) med egen eller kommunalförbunds-drift, aktiv digitaliseringsagenda, gärna redan Nextcloud-nyfiken. Nya SoL (2025:400) med krav på systematisk uppföljning är den svenska efterfrågedrivaren.

**Våg 2 (från mån 6, parallellt): Privata sekretessprofessioner** — segment där brains säljer utan att M4-motorn behövs: (a) **advokatbyråer** (Advokatsamfundets ramavtalsmekanism = definierad väg in; Legoras USA-flytt öppnade suveränitetsluckan; extrem betalningsvilja bevisad), (b) **försvarets underleverantörskedja** (SUA-kaskaden tvingar köp; inget svenskt SME-alternativ finns; kräver dock säkerhetsklassad partner för leverans), (c) **privata vårdgivare** (AI-adoption bevisad via Tandem-vågen, suveränitetskritiken redan publik — ersättningssälj).

**Våg 3 (från mån 18): NIS2-energibolag och DORA-nischen** under storbankerna (sparbanker, förmedlare, betalningsinstitut) — lagdrivna, kommunlika i storlek, och exakt vad en MSP-partner kan bearbeta i volym. Tillverkning tas opportunistiskt via partner, aldrig missionerande själva.

Varför denna ordning: våg 1 bygger referensen och konnektor-vallgraven; våg 2 ger kassaflöde från brains med kort säljcykel medan M4 mognar (Fas 3-ledtiderna med Inera/Frends är månader); våg 3 är ren partnerreplikering av bevisade playbooks.

## 3. Erbjudandetrappa & paketering

**Hubs-trappan (offentlig):** M0 obligatorisk kärna (osynlig), **M1 Meddelanden som ankarprodukt** — enda produktionsfärdiga modulen idag, säljs som subscription per användare med *obegränsade försändelser på egen infrastruktur* (transparensen är i sig en differentiator i ett fält där alla gömmer priser bakom offert; Kivras 1,50–3 kr/försändelse är jämförelsepunkten vi vinner mot). M2 Video/M3 Filer som tillägg. **M4 Verksamhet** säljs som pilot/designpartnerskap tills Fas 3 är klar, produktionsavtal därefter. **Konnektorer är den återkommande intäktsenheten**: separat prissatt subscription per konnektor (Treserva först), inkl. underhåll vid facksystemens API-ändringar — det är underhållet kunden betalar för, och det kan AGPL-fripassagerare inte kopiera.

**Brain-trappan (privat + så småningom offentlig icke-sekretess), ärligt definierad:**

1. **Steg 1 — RAG-brain** (kärnprodukten): ingestionsmotor mot kundens källor (M365/Teams/Exchange byggs som grundpaket), Evidence-schema, PII-brandvägg med stance-matris, GDPR-mallpaket, pgvector-brain i kundens drift, MCP-åtkomst från valfri klient. Detta är retrieval — uppdaterbart i realtid, art. 17-raderbart, källhänvisat. 80 % av kundbehovet.
2. **Steg 2 — LoRA-anpassning** (tillval): tunn adapter på 7–14B-bas för ton/format/domänvokabulär. Kostar ~400–1 200 USD i beräkning per körning — prissätts som tjänst (~50–150 kkr) med ärlig avgränsning: beteende, inte kunskap.
3. **Steg 3 — Suverän intelligens** (premium): självhostad Mistral 3/gpt-oss-120b bakom vLLM i kundens miljö eller hos suverän driftpartner. Hela stacken CLOUD Act-immun.

**Prislogik** (siffror sätts av Sandra + extern benchmark, men strukturen): Hubs ~30–60 kr/anv/mån för M1, M4-tillägg per verksamhet; konnektor 60–150 kkr/år per facksystem; **brain prissätts per brain + per konnektor + driftnivå** — förslagsvis 8–20 kkr/mån per organisations-brain inkl. 2 källor, +2–4 kkr/mån per extra källa, steg 3-intelligens +10–25 kkr/mån (GPU-kostnad + marginal). Marknaden saknar "per brain"-prissättning — vi definierar enheten. Alltid transparent publicerad prislista: det är anti-Palantir-positionen operationaliserad. Ingen nollpris-ingång, någonsin.

## 4. Varumärkesarkitektur

**Ett paraply.** Plattformistens hela poäng är EN berättelse — två varumärken halverar det lilla teamets kommunikationskraft och förvirrar partnern som ska sälja helheten. Förslag: paraplyet är **Hubs**, lagren heter **Hubs Verksamhet** (M-modulerna), **Hubs Minne** (brain-produkten) och **Hubs Intelligens** (LLM-lagret). "Minne" är avsiktligt: det beskriver vad produkten faktiskt är (retrieval/kunskapslager), undviker det tekniskt felaktiga "träna", och är begripligt för en styrelse. Mot internationell/privat publik: *Hubs Memory*. "Nate Open Stack" förblir internt utvecklingsnamn — det byter namn vid externalisering, punkt.

Ett andra varumärke skapas dock: **"Hubs Certified Partner"** — certifieringsmärket ÄR plattformistens produkt lika mycket som koden. Det är vad som skiljer betalande partner från AGPL-fripassagerare i kundens ögon.

## 5. Kanal & säljmotion

Kärnprincipen: **ITSL säljer aldrig i volym själva. ITSL bygger produkt, certifierar partner och driver fyrtorn.**

- **Offentligt, LOU-vägen:** aldrig egna ramavtal — bli **underleverantör/programvarupart hos Atea och Redpill Linpro** (Kammarkollegiet/Adda-ramavtalen; Redpill listar redan Safespring som underleverantör — kedjan finns). Avrop via Addas DIS. Kommunalförbund och gemensamma driftorganisationer prioriteras som multiplikatorer (flera kommuner per affär). Element-i-BWI-mönstret: integratören bär kontrakt och SLA, ITSL levererar produkt + expertis + subscription.
- **Drift:** Safespring/Binero (suverän svensk IaaS) som föredragna driftpartners; Basalt/motsvarande för SUA-klassade miljöer i försvarssegmentet.
- **Nextcloud-ekosystemet:** certifierad ISV i Nextclouds partnerprogram, Hubs-apparna (AGPL-delarna) i app store som leadmotor, närvaro på Nextcloud Conference, positionering för en framtida svensk openDesk-motsvarighet. Nextclouds AI-investeringar görs till medvind genom att Hubs Minne registreras mot deras Assistant/Task Processing-API:er.
- **Ritualen: "Hubs-dagen"** (§7.1) — paketerad, LOU-granskad marknadsdialog: förmiddag Min dag i kommunens testmiljö, eftermiddag kommunens egen ärendetyp konfigurerad live ("er ärendetyp på tio minuter"). Syntetisk data, aldrig förhandsbindande. Från mån 12: **partnern kör Hubs-dagen**, ITSL certifierar innehållet — det är skalmomentet. Privat motsvarighet: **"Minnesdagen"** — en dag, kundens syntetiska/avidentifierade korpus, fungerande brain vid dagens slut.
- **Community-motorn:** årligt kommunnätverk (AIPCon-i-miniatyr), delbara ArendeTyp-definitioner och mallpaket som ekosystemartefakter.

**Ärlig nackdel, hanterad:** partnerkanal tar 12–18 månader att ge intäkt, marginaldelning (räkna 30–40 % till partner) och kvalitetsrisk. Motmedel: (1) fyrtornen görs alltid i egen regi — partnern replikerar, uppfinner inte; (2) certifieringen villkoras av genomförd utbildning + granskad första leverans; (3) mot AGPL-fripassagerar-risken: **inför CLA nu och erbjud kommersiell licens som alternativ (Element-modellen)** — AGPL blir då ett vapen som tvingar integratörer till bordet i stället för förbi det. Konnektorerna och Apollo-light-leveransförmågan förblir det partnern inte kan forka.

## 6. Sekvensering

**0–6 mån — "Bevisa":**
K-7-lagret (åtkomstlogg + checkpoints) + gallringskvitton byggda; EN Treserva-konnektor live i EN referenskommun (designpartneravtal, betalt, inte gratis); brain v1-svepet klart inkl. P2 (lokala embeddings — utan detta är suveränitetsargumentet inte sant om vår egen drift); 2 betalda brain-designpartners i privat sektor (1 advokatbyrå, 1 försvarsunderleverantör eller vårdgivare); CLA + publicerad prislista + första partnersamtal (Redpill Linpro, Safespring). **GO/NO-GO mån 6:** referenskommun i verklig användning (mätt i aktiva handläggare, inte licenser) OCH minst 1 brain-designpartner som betalar → annars stanna, fixa produkt.

**6–18 mån — "Certifiera":**
Hubs-dagen + Minnesdagen paketerade och körda ≥6 ggr; partnerprogram v1 med 2 signerade partners (1 integratör, 1 driftpartner) och första partnerledda leverans; underleverantörsstatus på minst ett ramavtal; Apollo-light så att partner uppdaterar 10+ instanser själv; M365-källpaket för Minne; steg 3-intelligens levererad hos minst 1 kund; ISO 27001-resa påbörjad (Proton-läxan: suveränitetsköpare kräver ändå certifikat). **GO/NO-GO mån 18:** ≥2 partners som stängt egna affärer utan ITSL i säljmötet, ARR ≥4 MSEK → annars pivot mot direktsälj i smalt segment (linsens kill-punkt).

**18–36 mån — "Skala":**
5–8 aktiva partners; partnern äger leverans, ITSL äger produkt + certifiering + fyrtorn i nya verticals; konnektorfamilj 2–3 (Lifecare, e-diarium); federationsberättelsen för kommunkluster; kommunnätverket årligt; utvärdera internationalisering via Nextcloud-ekosystemet (openDesk-spåret) — endast med partner som bär lokalmarknaden. **Milstolpe:** >50 % av ny-ARR partnergenererad vid mån 30.

## 7. Kapacitetsplan

- **Fredrik:** produkt + arkitektur + fyrtornsleveranser + partnerteknisk certifiering. Slutar sälja i volym mån 12 — det är själva testet på linsen.
- **Rebecca:** kundteamet → växlas till **partner enablement**: certifieringsprogrammet, Hubs-dagen-manus, partnermaterial. Hon äger relationen med integratörspartnern.
- **Sandra:** prislista, partneravtalens marginalmodell, marknadsmaterial kring suveränitetsargumenten (CLOUD Act-vittnesmålet, molnpolicyn), ISO-resans ekonomi.
- **Mattias:** support/drift → **partnersupport 2:a linjen** + Apollo-light-driften + smoke-sviterna som leveranskvalitet.
- **Johan (CTO):** Hubs-kärnans tekniska skuld (ExApp-seamen, CI), utanför Open Stack-scope enligt beslut.

**Rekryteras/partnas bort:** juridik (extern advokat: DPIA-paket, PuB-mallar, CLA, partneravtal — får inte göras internt), GPU-drift (Safespring/Binero/Hetzner — ITSL äger aldrig järn), volymsälj (partnern), samt vid mån 12 **en (1) rekrytering: verksamhetsnära ingenjör/FDE** för referenskommun-loopen (§7.3) så att Fredrik frigörs. Teamet växer inte förrän partnerintäkt bevisats — det är plattformistens disciplin.

## 8. Open source-LLM-strategin

**När:** steg 1 (lokala embeddings, TEI/e5) omedelbart — det är en förutsättning, inte ett erbjudande. Steg 2 (suveräna RAG-svar via självhostad modell) erbjuds från mån 6–9 som premium. Steg 3 (suverän agent-loop) tidigast mån 18, efter benchmarking mot egna smokes — lova aldrig agentik på öppen modell förrän smoke-08-klassen passerar.

**Vilka modeller:** **Mistral 3-familjen primärt** (Apache 2.0, EU-hemvist — licens + geografi i ett), **gpt-oss-120b** som kvalitetsalternativ (Apache 2.0, kör på EN 80 GB-GPU). **Aldrig Llama** (EU-klausulen är oacceptabel licensrisk mot offentlig kund). Kinesiska modeller: inte i flaggskeppet, möjligen intern motor för okänsliga uppgifter. Bevaka OpenEuroLLM och Cohere/Aleph Alpha.

**Svenskan är den svaga punkten — gör den till produkt:** bygg en **svensk evalueringssvit** (förvaltningsspråk, socialtjänsttermer, sekretessformuleringar) som körs mot varje modellkandidat och levereras som artefakt i varje steg 3-affär. Ingen konkurrent har den; den blir en del av certifieringen.

**Hårdvaruekonomi:** MoE-generationen gjorde en-GPU-drift realistisk: 1× H100/H200 (~0,5 Mkr) bär Mistral Large 3/gpt-oss-120b; hyrd suverän GPU (OVH/Scaleway/Hetzner, utanför CLOUD Act) ~25 k€/år, break-even mot köp ~1,5–2 år. Tumregel till kund: steg 3 kostar 300–600 kkr/år i infrastruktur + vår subscription — och 70–80 % av arbetslasterna klarar det utan märkbar regression. Den ärligheten säljer resten av trappan.

## 9. Ekonomi

**Intäktsmodell:** 100 % subscription (Nextcloud-läxan: subscription undanröjer upphandlingsfriktion). Fyra strömmar: (1) Hubs-modulsubscriptions per användare, (2) konnektorsubscriptions per facksystem — den återkommande vallgraven, (3) Minne-subscriptions per brain + källa + intelligensnivå, (4) partneravgifter (certifiering + årlig partnerlicens). Engångstjänster (Minnesdagen, LoRA, exit-test) hålls under 25 % av omsättningen — vi är produktbolag.

**Grov ARR-bana** (konservativ, partnerkanalens ledtid inräknad): mån 6: ~0,8–1,2 MSEK (referenskommun + 2 designpartners). Mån 18: ~4–6 MSEK (3–4 kommuner à 250–400 kkr, 6–8 privata Minne-kunder à 150–400 kkr, första partneraffärer). Mån 36: ~15–25 MSEK (partnervolym i våg 3-segmenten, 25–40 kunder), varav >hälften partnergenererad med 60–70 % nettomarginal till ITSL efter partnerdelning.

**Vad finansierar vad:** M1-subscriptions + brain-designpartners (kort säljcykel, privat betalningsvilja) finansierar M4:s långa Fas 3-ledtider och Apollo-light-investeringen. Privata Minne-affärer är kassaflödesmotorn 2026–2027; konnektorerna är den ackumulerande värdemotorn. Ingen extern finansiering krävs för basplanen — men partnerprogrammets uppbyggnad (Rebecca + material + certifiering) är den post som motiverar eventuellt mindre kapitaltillskott om våg 2 överpresterar.

## 10. Risker & kill-kriterier

1. **Partner blir konkurrent (AGPL).** Sannolikast av allt — Element genomled exakt detta. Tidig signal: partner rekryterar egna Hubs-utvecklare, bygger egen konnektor. Motmedel: CLA + kommersiell licens från dag 1, konnektorer i proprietär zon, certifieringsvärdet. **Kill-signal för kanalstrategin:** en icke-betalande aktör vinner en kommunaffär på vår kod → omedelbar översyn av licensarkitekturen.
2. **Partnerkanalen levererar inte.** Mätbart: 0 signerade partners mån 12, eller 0 partnerledda affärer mån 18 → **NO-GO, pivot till direktsälj i två smala segment** (advokat + försvarskedjan). Det är linsens ärliga huvudrisk: tid.
3. **Referenskommunen faller** (konnektorn förblir stub, eller adoptionen uteblir à la NHS/FDP). Mät verklig användning månatligen. Ingen referens mån 9 → all expansions- och partnerteater fryses.
4. **Nextcloud Context Chat höjer golvet** in i Minne-territoriet. Bevaka varje release; motmedel är ISV-integration + det Nextcloud aldrig bygger (Evidence-schema, PII-stance, ingestionskonnektorer, svensk eval).
5. **Hyperscaler sovereignty-washing tar 80 % av marknaden.** Om "EU Data Boundary räcker" vinner i tre raka upphandlingar trots CLOUD Act-argumentet → suveränitetsbudskapet ensamt bär inte; skifta tyngd till verksamhetsvärde (åtgärd-först, konnektorer) med suveränitet som tie-breaker.
6. **Suveränitets-sanning internt:** om P2 (brandvägg på, lokala embeddings) inte är i drift före första externa Minne-leverans är erbjudandet falskt — absolut leveransgrind, ingen affär före den.
7. **Regulatorisk grind:** BESLUT-09 (AI på sekretess) förblir blockerad → Minne mot offentlig sektor begränsas till icke-sekretessdata; planen ovan är byggd så att detta inte dödar den (privat först), men kommunicera aldrig motsatsen.

**Strategins enda mening:** bevisa allt själva en gång, certifiera andra att göra det tusen gånger — och låt konnektorerna, certifieringen och den testade exiten vara vallgraven som varken AGPL-fripassageraren eller hyperscalern kan kopiera.