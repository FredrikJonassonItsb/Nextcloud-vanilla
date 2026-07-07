# GTM-strategiutkast — Lins A "Sekventialisten": Offentligt först, privat som förlängning

*Utkast 2026-07-07 · baserat på PALANTIR-HUBS-ANALYS-2026-07-07.md (§7–§10) + 8 research-dossierer*

**Tes i en mening:** ITSL:s enda försvarbara väg med litet kapital är att göra EN kommunal referens ointaglig, mogna brain-erbjudandet på egen och kommunal icke-sekretess-data, och först därefter sälja det privat via driftpartners — långsamheten är priset, referensen är räntan.

---

## 1. Positionering & kärnbudskap

**Mot offentlig köpare:** *"Det operativa lagret er socialtjänst saknar — i er egen drift, granskningsbart ner till sista raden, och byggt för att kunna lämnas."* Tre bärande satser: (a) **"Vi byggde aldrig det domstolen fällde"** — NEVER-SoR, ingen tväranalys, ändamålet som registerrad (Palantir-analysen §9.4–9.5). Detta är anti-Palantir-vinkeln: samma operativa mekanik, spegelvänd vallgrav. (b) **"Compliance-stacken ingår i arkitekturen"** — cybersäkerhetslagen (i kraft jan 2026), AI Act art. 26-loggkrav, nya SoL:s uppföljningskrav: allt är schemafält och åtkomstlogg, inte konsultrapporter. (c) **"Reversibilitet som leveransmoment"** — testad exit år 1, AGPL-kärna, kundägd config. Svensk molnpolicy (maj 2026) citeras i varje anbud.

**Mot privat köpare (fas 2):** suveränitet säljs inte som ideologi utan som **riskeliminering**: *"AI på er egen data, i er egen drift — CLOUD Act-immunt per ägarstruktur, inte per datacenteradress."* Anti-Copilot-vinkeln är juridisk, aldrig funktionell: Microsofts vittnesmål i franska senaten ("Non, je ne peux pas le garantir") + Trump v. Slaughter/DPF-risken gör transatlantiska flöden till en känd svansrisk. Vi tävlar inte med Copilot på features — vi tävlar på att kunden kan svara inför sin tillsynsmyndighet, sin klient och sin styrelse. Copilot-tröttheten (60 % fast i pilot, datasäkerhet som huvudskäl) är inbrytningspunkten: kunden har redan budget och en misslyckad pilot.

**Om "träna en brain":** vi säger det ärligt — *"vi tränar inte om modellen, vi bygger ett ägt kunskapslager som gör varje modell expert på er verksamhet — och som kan gallras, auditeras och raderas post för post."* Det är ett GDPR-argument, inte en brasklapp. "Träna" används endast som rubrikmetafor med omedelbar precisering.

## 2. Segmentering & ICP

Sekventialistens ordning är sträng — varje segment finansierar och bevisar nästa:

**Våg 1 (nu–18 mån): Svenska kommuner, socialtjänst, 20 000–80 000 invånare.** ICP: kommun med Treserva eller Lifecare, egen eller kommunalförbunds-driftad IT, en digitaliseringschef som redan citerar molnpolicyn, och en socialchef som pressas av nya SoL:s uppföljningskrav. Mellanstorleken är vald: stor nog för budget, liten nog att sakna eget plattformsbygge, och DSO:n är en deltidsperson — vår aggregatvy-design är byggd för exakt henne. **Kommunalförbund och gemensamma driftorganisationer prioriteras** (flera kommuner per affär, §7.5). Mål: 1 referenskommun → 3–5 följare i samma förbund/facksystemkluster.

**Våg 2 (12–24 mån): Kommunala brains — samma kunder, nytt lager.** Börjar i **icke-sekretess-data** (BESLUT-09 blockerar AI på sekretess): kommunens kunskapsbank, rutiner, riktlinjer, styrdokument, KS-protokoll → handläggarstöd av typen "vad säger vår riktlinje om X". Detta är juridiskt okontroversiellt, träffar nya SoL:s "kunskapsbaserad socialtjänst" och säljs som tilläggsmodul till befintliga Hubs-kunder — noll ny säljkostnad.

**Våg 3 (18–36 mån): Privat, i dossierordningen** — (1) **försvarets underleverantörskedja** (SUA-kaskaden tvingar köp, inget svenskt SME-alternativ finns), (2) **advokatbyråer** (Advokatsamfundets ramavtalsmekanism = definierad väg in; suveränitetslucka efter Legoras USA-flytt), (3) **privata vårdgivare** (bevisad AI-adoption + publik suveränitetskritik = ersättningssälj), (4) **NIS2-energibolag** (kommunlik säljmotion vi redan kan). Finans-nischen och tillverkning tas opportunistiskt. Vi går in i våg 3 **endast via driftpartner** — se §5.

## 3. Erbjudandetrappa & paketering

**Hubs (offentligt):**
- **Hubs Bas** = M0+M1 (säkra meddelanden, kvittenser, korgar, retention) — enda produktionsfärdiga idag, ankarprodukt och instegs-SKU.
- **Hubs Samarbete** = +M2/M3 (video/chat, filer) — mjuka tillval.
- **Hubs Verksamhet** = M0+M1+M4 (ärendemotor, Min dag, grindar, åtkomstlogg) — demo-/pilotsäljbar efter Fas 1–2, produktionssäljbar efter Fas 3.
- **Konnektorer** som separat prissatta subscriptions per facksystem (Treserva först, per BESLUT-07) — den återkommande intäktsenheten och den proprietära zonen.
- **Prislogik:** transparent per-användare-och-år-subscription i Nextcloud-stil (riktmärke: Bas ~300–500 kr/anv/år, Verksamhet ~900–1 400 kr/anv/år, konnektor 80–150 tkr/år per facksystem och kommun) — **publicerad prislista är i sig differentiatorn** i en marknad där alla gömmer priser bakom offert, och motmedlet mot Palantirs nollpris→explosion-mönster (§8).

**Brain-erbjudandet — ärlig trappa, tre steg:**
1. **Steg 1: Suverän kunskaps-RAG** (kärnprodukten). Ingestionsmotor + Evidence-schema + PII-brandvägg + pgvector-brain + lokala embeddings, i kundens drift. Resonemang via valbar modell (frontier-API → EU-hostad → självhostad). Leverans: installation + käll-onboarding + GDPR-mallpaket. Pris: etablering 150–300 tkr + 8–20 tkr/mån per brain inkl. förvaltning.
2. **Steg 2: LoRA-beteendeanpassning** (tillval, säljs först när efterfrågad). Tunn adapter på 7–14B-bas för ton/format/domänvokabulär — kostar oss 400–1 200 USD per körning, säljs som tjänst 50–100 tkr. Aldrig kunskap i vikter — kunskapen bor i RAG-lagret där den kan gallras.
3. **Steg 3: Helt självhostad LLM** (suveränitetspremium). Mistral 3/gpt-oss-120b på 1× H100/H200 i kundens drift eller hyrd EU-GPU. Se §8.

Vi säljer **hela trappan som valbarhet** — det är positionen ingen hyperscaler kan kopiera. Vi lovar aldrig steg 3-kvalitet i steg 1-pris.

## 4. Varumärkesarkitektur

**Ett paraply: ITSL. Två produktfamiljer: Hubs och Verket.** Hubs behålls som namnet på verksamhetslagret (etablerat, kommunklingande). Brain-produkten behöver ett eget namn som inte är "Nate" (internt arbetsnamn, personkopplat till extern förlaga) och inte "brain" (engelska, antyder träning). Förslag: **ITSL Verket** — svenskt, myndighetsdoftande på rätt sätt, funkar i "ert verk" / "Verket kan er verksamhet"; alternativ: **Kärnan** eller **Stommen**. Beslut krävs av Fredrik/Sandra före våg 2-lansering; kriteriet är att namnet ska bära i en kommunal upphandling OCH i ett advokatbyrå-säljmöte. Arkitekturen blir: *ITSL Hubs* (verksamhetslagret) + *ITSL Verket* (kunskaps-/AI-lagret) + *ITSL Konnektorer* (integrationsfamiljerna). Ingen separat brand för privat marknad — samma namn, samma story, det ÄR poängen med sekventialismen: referenserna följer med varumärket.

## 5. Kanal & säljmotion

**Direktsälj endast till referenskunderna (våg 1, 1–5 kommuner).** Fredrik + Rebecca kör dessa personligen — FDE-loopen i mikroformat (§7.3) kräver det.

**Sedan kanal, aldrig egen säljkår:**
- **LOU-vägen:** bli underleverantör/programvarupart på Kammarkollegiets och Addas ramavtal via **Atea eller Redpill Linpro** (Element-i-BWI-mönstret: integratören bär kontrakt och SLA, ITSL tar produktsubscription). Parallellt: Adda DIS för direktavrop.
- **Driftpartner:** Safespring/Binero för kunder utan egen drift — och detta är **bryggan till privat marknad**: samma partner driftar advokatbyråns Verket-instans som kommunens Hubs.
- **Ritualen: "Hubs-dagen"** (§7.1) — paketerad marknadsdialog, aldrig gratis-pilot (LOU + New Orleans-läxan): förmiddag Min dag i kommunens testmiljö på syntetisk data, eftermiddag kommunens egen ärendetyp konfigurerad live ("er ärendetyd på tio minuter"). Körs FÖRST när live-konnektorn är sann — en bootcamp som slutar i "konnektorn är en stub" bränner en skvallrig 290-kommunersmarknad (§7.6).
- **Peer-motorn:** årligt kommunnätverk ("AIPCon i miniatyr", §7.5) + delbara ArendeTyp-/mallpaket. Kommuner köper på peer-bevis; varje vinst pressmeddelas (Nextcloud-mönstret).
- **Nextcloud-ekosystemet:** certifierad ISV, synlighet på Nextcloud Conference, öppen demo-instans — communityt är kommunernas IT-folk, inte utvecklare.

## 6. Sekvensering med GO/NO-GO

**0–6 mån — "Grinden":**
- K-7-lagret klart (beständig åtkomstlogg + checkpoints + gallringskvitton, §6.1–6.2) och P0-fynden stängda.
- EN live Treserva-konnektor i EN referenskommun i produktion.
- Openbrain P1–P9-svepet klart, kritiskt P2 (lokala embeddings, PII-brandvägg PÅ) — utan detta är suveränitetsargumentet inte sant om vår egen drift.
- Prislista publicerad; Hubs-dagen LOU-granskad.
- **GO/NO-GO (mån 6):** referenskommun i *verklig användning* (mätt i dagliga aktiva handläggare, inte licenser — NHS-läxan) + signerat avtal. NO-GO → stanna i M1-affären (säkra meddelanden), frys M4-expansion.

**6–18 mån — "Bevis + intern brain-mognad":**
- 3–5 kommuner via referensens förbund/kluster; Apollo-light så att en driftpartner kan uppdatera flottan (§7.2).
- Första kommunala Verket-piloten (kunskapsbank, icke-sekretess) hos referenskommunen — konsultledd, betald.
- Ramavtalsposition via Atea/Redpill Linpro etablerad; driftpartneravtal med Safespring/Binero.
- Verket avhårdkodas (parametriserad provisioning, käll-GUI, M365-konnektorer) — ~4–6 pm.
- **GO/NO-GO (mån 18):** ≥4 betalande kommuner + ≥1 betald Verket-pilot med mätt användning + partneravtal signerat. NO-GO på Verket → Verket förblir internt verktyg, Hubs fortsätter ensamt.

**18–36 mån — "Privat förlängning":**
- Verket säljs privat via driftpartner: först 2–3 designpartners i segment 1–2 (försvarsunderleverantör, advokatbyrå), konsultledda.
- Självhostad LLM-option (steg 3) produktifierad när första kund kräver den — inte förr.
- ISO 27001-spår startas (Proton-läxan: suveränitetsköpare kräver ändå certifikat).
- **GO/NO-GO (mån 30):** ≥2 privata referenser + Verket-ARR ≥ 2 Mkr. NO-GO → privat spår läggs ned, ITSL blir renodlat kommunbolag.

## 7. Kapacitetsplan

- **Fredrik:** produkt + arkitektur + FDE hos referenskommunen (§7.3) + Verket-piloterna. Han är flaskhalsen — allt i planen är dimensionerat efter det.
- **Rebecca:** kundteamet = äger referenskommunrelationen, Hubs-dagen som ritual, kommunnätverket, partnerrelationerna (Atea/Redpill).
- **Sandra:** prislista, avtalspaket (med extern jurist: PuB, DPIA-mall, Verket-biträdesavtal), pressmotorn (varje vinst publiceras), ISO-förarbete.
- **Mattias:** drift + Apollo-light är HANS produkt; supportprocess + SLA-struktur; sedermera partner-enablement (lära Safespring drifta Hubs).
- **Johan (CTO):** M4-hårdning, ExApp-seamen, CI — utanför Open Stack-scope per roster.
- **Rekryteras/partnas bort:** extern IP/GDPR-jurist (avrop, ej anställning); vid mån 12+ EN verksamhetsnära ingenjör (socionombakgrund + teknik) för FDE-loopen; ALL storskalig drift, SLA-jour och kontraktsbärande till partners. **Ingen säljrekrytering någonsin i denna lins** — kanalen är säljkåren.

## 8. Open source-LLM-strategin

**När:** steg 1 (lokala embeddings/TEI, e5-base) NU — det är P2 och en förutsättning, inte en option. Steg 2 (självhostade RAG-svar) produktifieras när första betalande kund kräver det, tidigast mån 12 — att bygga före efterfrågan binder kapital vi inte har. Steg 3 (suverän agent-loop) är 2027-fråga; Claude-beroendet i runnern accepteras öppet under tiden och redovisas ärligt i kunddialog ("resonemangslagret i tre suveränitetsnivåer").

**Vilka modeller:** **Mistral 3-familjen primärt** (Apache 2.0, EU-hemvist — bästa kombination av licens, jurisdiktion och kvalitet) + **gpt-oss-120b** som kvalitetsalternativ (Apache 2.0, en 80 GB-GPU). **Aldrig Llama** (EU-klausulen är oacceptabel licensrisk mot offentlig sektor); kinesiska modeller endast som ev. intern motor för okänsliga uppgifter, aldrig i erbjudandet. Serving: vLLM default, SGLang för RAG-tunga laster.

**Svenska språket:** den svaga punkten och därför en produkt: vi bygger en **egen svensk evalueringssvit** (förvaltningsspråk, socialtjänsttermer, SoL/OSL-vokabulär) körd mot EuroEval + egna testfall — den blir grind för varje modelloption OCH säljbar tillgång ("vi bevisar svenskan innan ni köper"). Mistral Large 3 och gpt-oss-120b är godtagbara; under 14B lovar vi inget på svenska.

**Hårdvaruekonomi:** MoE-skiftet gör kommunal on-prem realistisk: 1× H100/H200 (~450–600 tkr) räcker för Mistral Large 3/gpt-oss-120b. Alternativ: hyrd suverän GPU hos OVH/Scaleway/Hetzner (~2,7–3 €/tim ≈ 250–290 tkr/år 24/7; break-even mot köp ~1,5–2 år) — utanför CLOUD Act. Paketering: steg 3-tillägg prissätts som hårdvara till självkostnad + förvaltning ~15–25 tkr/mån; marginalen ligger i Verket-lagret, inte i järnet (Berget AI visar att inferens kommodifieras).

## 9. Ekonomi

**Modell:** subscription-ARR i fyra strömmar — (1) Hubs-moduler per användare/år, (2) konnektorer per facksystem/kommun/år, (3) Verket per brain/mån + etablering, (4) partnerkickback (ITSL:s subscription genom integratörens kontrakt). Engångstjänster (Hubs-dagen efterföljande etablering, Verket-installation, LoRA) hålls under 30 % av omsättningen — vi bygger produktbolag, inte konsultbolag.

**Grov ARR-bana (konservativ):** Mån 12: ~1,5–2,5 Mkr (referens + 2–3 kommuner à Bas/Verksamhet + konnektor + första Verket-pilot). Mån 24: ~5–8 Mkr (6–10 kommuner, 2–3 Verket-instanser, ramavtalsflöde börjar). Mån 36: ~12–20 Mkr (15–25 kommuner varav flera via förbund, 5–8 privata Verket-kunder via partner). **Vad finansierar vad:** M1-affären (säljbar idag) + konsultledda piloter finansierar M4-hårdningen; kommun-ARR finansierar Verket-produktifieringen (6–9 pm); Verket-piloternas betalning finansierar privat-expansionen. Inget externt kapital förutsätts — det är linsens poäng och dess begränsning.

## 10. Risker & kill-kriterier

1. **AI-fönstret stängs** (linsens kända akilleshäl): hyperscalers "sovereignty washing" + Nextcloud Context Chat höjer golvet innan vi når privat marknad. *Tidig signal:* Copilot in-country-processing lanseras i Sverige med kommunreferenser; Context Chat får evidence-liknande schema. *Motdrag:* våg 2 (kommunala brains) får INTE vänta på perfekt M4 — den kan starta på ren M1-kundbas. *Kill:* om vid mån 18 ingen betald Verket-pilot finns trots två försök → privat AI-spår dödas.
2. **Referenskommunen fastnar** — konnektorn levererar inte eller professionen bojkottar (NHS-läxan). *Signal:* dagliga aktiva användare platå <30 % av handläggarna efter 3 mån. *Kill:* NO-GO mån 6 = frys till M1-bolag.
3. **Kommunala säljcykler äter kassan** (12–24 mån). *Signal:* ingen andra kommun signerad inom 9 mån efter referens-go-live. *Motdrag:* förbundskanalen prioriteras; M1-affären som kassaflödesgolv.
4. **Free-riding på AGPL** — integratör driftar Hubs utan oss. *Motdrag:* CLA + kommersiell licensoption (Element-mönstret) etableras NU, innan det behövs; vallgraven är konnektorer + Apollo-light + referenser, aldrig koden.
5. **Fredrik-beroendet** — en person bär produkt, FDE och Verket. *Signal:* leveransslip >1 kvartal på två milstolpar i rad. *Motdrag:* verksamhetsingenjör-rekryteringen tidigareläggs; Mattias tar Apollo-light helt.
6. **Regulatorisk chock:** BESLUT-09 (AI på sekretess) förblir blockerad bortom 2027 → Verket i kommun stannar på kunskapsbanksnivå. Acceptabelt — det är därför privatspåret finns. Omvänt: om IMY öppnar tidigt är det en accelerator, inte plan.

**Strategins övergripande kill-kriterium:** om mån 30-grinden missas på BÅDA benen (kommunexpansion OCH privat Verket) är sekventialismen falsifierad — då är rätt drag att sälja konnektorfamiljen/bolaget till en större integratör medan referensen ännu lyser, inte att fortsätta bootstrappa.

---

*Ordräkning ~2 500. Öppna beslut som krävs av VD: (1) produktnamnet för brain-erbjudandet (§4), (2) prislistans nivåer (§3 — riktmärken angivna, Sandra validerar mot Adda-avrop), (3) CLA-införandet (§10.4).*