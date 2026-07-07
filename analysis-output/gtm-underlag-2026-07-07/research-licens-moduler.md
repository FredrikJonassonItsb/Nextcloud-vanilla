Nedan är sammanfattningen, baserad på `hubs_start/docs/MODULARISERING-LICENS-DATALAGER.md`, `hubs_start/docs/HUBS-BESLUTSLOGG.md`, `hubs_start/docs/STATUS-OCH-ROADMAP.md` samt `analysis-output/PALANTIR-HUBS-ANALYS-2026-07-07.md` §7 (alla i `C:\Users\fredrik.jonasson\Cursor\Nextcloud-vanilla`).

# Hubs affärs-/licens-/paketeringsmodell — underlag för GTM

## 1. M0–M4-paketeringen och vad som är säljbart när

Ratificerad SKU-modell (BESLUT-17): **kärna-plus-tillägg, inte fyra likvärdiga block.** M0 = osynlig, obligatorisk plattformskärna (ärenderegister, `case:{id}`-taggmotor, saga-API, AppDetectionService). Ovanpå: **M1 Meddelanden (ankarprodukt, självförsörjande)** — säkra meddelanden/fax/internpost/SMS, kvittenser, korgar, retention; **M2 Video&Chat** och **M3 Filer** (självförsörjande tillägg, inga hårda beroenden); **M4 Verksamhet** = M0+M1+ärendemotor, med M2/M3/Kontakter som mjuka tillval (graceful degradation via AppDetectionService är tekniskt verifierad). Minsta säljbara M4-enhet är alltså M0+M1+M4.

Viktig revision: den ursprungliga planen att bryta sdkmc i M0/M1 **utgick** vid ratificeringen 2026-06-16 — i stället byggdes ärendemotorn som **standalone-appen `hubs_arende`** med egen kodbas och egen DB som konsumerar sdkmc via OCS/events. Modulgränsen realiseras alltså via separat app, inte refaktor.

**Mognadsläge (kodförankrat, 2026-06-17):** M1-stacken är till största del `[FINNS]` och i drift (dev15 kör sdkmc 2.2.25 i itsl-managed kundmiljö) — det är den enda modul som är säljbar som produktion idag. M4 är **en körande demo med skarp motor-kärna men stubbat integrationslager**: saga-stegen som skapar riktiga rum/kort/kalendrar saknar auth-seam, inflödesfeeden är tom, facksystem/Inera/diarium är stubbar, ingen CI. M4 blir demo-/pilotsäljbar efter Fas 1–2 (interna seams, inga externa avtal) och produktionssäljbar först efter Fas 3 (Frends-miljö, facksystem-testinstans, Inera-avtal — lång ledtid). Palantir-analysen §7.6 drar den kommersiella slutsatsen hårt: **K-7-lagret + EN live-konnektor i EN referenskommun före all expansionsteater** — en bootcamp som slutar i "konnektorn är en stub" bränner referensmarknaden.

## 2. AGPL vs proprietärt — var licensgränsen går

Grundprincip (MODULARISERING §2): **processgränsen ÄR licensgränsen.** Allt in-process PHP mot OCP = combined work = AGPL: hubs_start-bron (`AGPL-3.0-or-later`), sdkmc (`agpl`), mail-forken (`AGPL-3.0-only` — obs: kan inte lyftas till framtida AGPLv4, olik spreed-itsl `-or-later`), spreed-itsl, calendar/Files/Groupfolders. **KAN vara proprietärt:** M4-motorn som ExApp (egen container, egen DB, arm's-length HTTP/AppAPI), facksystemkonnektorerna (bor i ExApp-zonen), ev. rå-OCS-SPA utan `@nextcloud/*`-bundling.

**Beslutsläge:** BESLUT-02 är **STÄNGT — IP-juristen har godtagit ExApp-lösningen**; proprietär M4-som-ExApp är juridiskt klarerad (standalone, arm's-length, egen DB). Men det finns en teknisk hake med direkt GTM-relevans: ExApp-paketeringen (seam I) **fungerar inte ännu** — app_api 3.2.0 kraschar på ITSL:s NC31 och docker.sock är inte monterad, så `hubs_arende` kör i dag in-process (= de facto AGPL-zon, och bär redan AGPL-SPDX-headers). Den proprietära optionen är alltså designad och juridiskt klarerad men **inte realiserad**. Öppna beslut i övrigt: BESLUT-08 (Inera-signeringsavtal, lång ledtid) och BESLUT-09 (AI på sekretess — blockerad tills myndighetsvägledning).

App store-regeln: apps.nextcloud.com kräver AGPL-kompatibelt → **proprietär M4 förutsätter privat distribution** (sidoladdning nu, privat registry cr.itsl.se som drift-mål, BESLUT-04).

## 3. Konnektorfamiljerna som intäktsenhet

Detta är den tydligast uttalade intäktstanken: `FacksystemCommitService` med **per-produkt-konnektorer som separat prissatta/licensierbara artefakter** (BESLUT-17 + MODULARISERING §1/§4). Mappningen är tvådimensionell (modul × produkt): socialtjänst-SoR (Treserva/Lifecare/Viva), Sokigo-klustret, e-diarium/e-arkiv (Public360 ≠ W3D3 ≠ Platina ≠ Ciceron ≠ Evolution — olika schema per produkt). Nattanalysen värderar konnektorbygget till **5–10× GAP-019** — den tyngsta integrationsbördan men också den återkommande intäktsenheten. Referensordning (BESLUT-07): Treserva/Lifecare först, EdiariumConnector som test-orakel. Läge: **noll riktiga konnektorer byggda**; porten/kontraktet finns, konnektorfamilje-selektionen (`systemOfRecord`-fält) saknas.

## 4. Kända prissättningstankar

Det finns **ingen prislista, inga kronbelopp** i underlagen. Det som finns: (a) SKU-strukturen M0 obligatorisk/M1 ankare/M2/M3/M4 + per-konnektor-prissättning; (b) principen **transparent modulprissättning** som uttryckligt motmedel mot Palantirs nollpris→kostnadsexplosion-mönster (§8); (c) marknadsanalysen (`analysis-output/market-konkurrenter-meddelanden.json`): hela svenska säkra-meddelanden-fältet gömmer priser bakom offert (endast Kivra 1,50–3 kr/försändelse känt) → **transparent, förutsägbar prismodell (per användare eller per server, obegränsade försändelser på egen infrastruktur) är i sig en differentiator i upphandlingar**; (d) Digg/Adda tar inga federationsavgifter → prissättningsfrihet, men varje affär vinns i avrop via Addas DIS.

## 5. Vad som saknas kommersiellt

- **Prislista/prismodell** — SKU-strukturen finns, siffror saknas helt. BESLUT-17 noterar att avtal inte kan slutföras utan detta.
- **Avtalsmallar** — PuB-/DPIA-matrisen är schemafält (BESLUT-12) men innehållet ägs per kund; utan den är Hubs "juridiskt osäljbart, PuB oundertecknbart". Signeringskravmatris per kund (BESLUT-08) öppen.
- **Partner-/kanalmodell** — Apollo-light-visionen (§7.2: driftpartner uppdaterar 30 kommuninstanser på en eftermiddag) och kommunalförbund som GTM-kanal (§7.5) är idéer, inte program.
- **Säljbara ritualer/artefakter** — "Hubs-dagen" (§7.1) har alla tekniska byggstenar (DemoSeedService, `?demo=1`, mallbibliotek) men är inte paketerad eller LOU-granskad; exit-protokollet/reversibilitet-som-produktegenskap (§7.4) är opublicerat; delbara ArendeTyp-/mallpaket (§7.5) obyggda.
- **Referenskund** — ingen live-konnektor i någon referenskommun; detta är per §7.6 grinden för allt övrigt.

## 6. Hårda constraints på en GTM

1. **AGPL-kärnan ger konkurrenter fri drifträtt.** Vem som helst (driftleverantörer, konsultbolag) kan ta M0–M3 + hubs_start och drifta åt kommuner utan att betala ITSL. Vallgraven kan aldrig vara koden — den måste vara konnektorerna (proprietär-zonen), leveransförmågan (Apollo-light), domänkonfigurationen och referenserna. GTM som säljer "koden" saknar skydd; GTM som säljer "körande, förvaltad, integrerad" har det.
2. **Öppen kärna är samtidigt säljargumentet** — granskningsbarhet, testad exit, kundägd config är exakt anti-Palantir-positionen (§7.4) och det legala svaret på LOU-risken med gratis-piloter. AGPL:n ska exploateras offensivt i upphandling, inte gömmas.
3. **Proprietär M4 kräver privat distribution + fungerande ExApp-infra** — ingen app store-kanal, och tills seam I löses kör motorn in-process i AGPL-zonen. Att sälja M4 proprietärt idag vore att sälja något som inte finns i den licensformen.
4. **§13-skyldigheten ligger på driftande part** (kommun/driftleverantör måste erbjuda källa för NC + forkar) → compliance-paket måste ingå i leveransen; mail `-only` vs spreed `-or-later` måste hanteras i kombinerad distribution; inga AGPL-bibliotek får bundlas på proprietär sida.
5. **Regulatoriska grindar styr segmentsekvensen:** säkerhetsskyddsgrinden (BESLUT-13) blockerar roller utanför socialtjänst tills verifierad; AI på sekretess (BESLUT-09) är blockerad → "träna en brain"-erbjudanden mot offentlig sektor måste börja i icke-sekretess-data eller privat marknad; Inera/Frends-ledtider (månader) sätter tidigaste produktionsdatum för M4.
6. **Ingen nollpris-ingång** — Hubs-dagen ska vara marknadsdialog/demonstration, aldrig förhandsbindande gratis-pilot (LOU + Palantir-varningskatalogen §8).