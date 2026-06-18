<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Kommunroller, System of Record & integrationsbehov — håller ArendeTyp-modellen kommun-brett?

> **Syntesdokument över 12 kommunala roller.** Prövar `ARENDETYPER-FLODESANALYS.md`-modellen (en motor · en saga · datadriven `ArendeTyp`-registry · ortogonala flaggor) mot hela kommunens verksamhet, inte bara socialtjänsten. Grundas i `ARENDETYPER-FLODESANALYS.md` (config-radens 18 fält §2.3; ryggrad R1–R9 §2.1; dubbelmärkning §4; klassningskaskad §5) och `HUBS-INTERNALS-ARENDEMOTOR.md` (saga med kompensering §1.2.3; matchningskaskad §2.3; GAP-019/056/057/058/060/063; Circles-synk rad 459; juristkonto/audit rad 460).

---

## 0. SAMMANFATTNING & VERDIKT

### 0.1 Verdikt (en mening)

**ArendeTyp-modellen — en motor, en saga, N config-rader, M ortogonala flaggor — HÅLLER kommun-brett: ryggraden R1–R9 är oförändrad i alla 12 roller, och skillnaderna mellan rollerna förblir DATA, inte kontrollflöde. MEN config-raden, som designades mot socialtjänstens premisser, måste UTÖKAS med ~7 strukturella fält, och sagan måste få ETT nytt utfall den idag helt saknar: "föds inte" (avvisa/karantän).**

Verdikt-formeln, kommun-bred:

> *EN motor · EN saga **med ett terminerande icke-föds-utfall** · N config-rader **med ~7 nya fält** · ~12 hooks · M+ flaggor · ett `aclProfil`-bibliotek · `FacksystemCommitService` med N konnektorfamiljer.*

Ingen roll motiverar ett eget kodflöde. Men **ingen roll är heller ren config på den *nuvarande* raden** — och det är breddningens hela poäng. I socialtjänst-dokumentet var 6 av 8 kategorier ren config; kommun-brett faller den siffran till **0 av 12 roller** — inte för att motorn är fel, utan för att socialtjänst-config-raden saknar de fält som blir nödvändiga så fort man lämnar Treserva-domänen.

### 0.2 De fyra brutna grundantagandena

Modellen antog tyst fyra premisser som var osynliga så länge bara socialtjänsten testades. Alla fyra bryts kommun-brett:

| # | Socialtjänst-antagandet (implicit) | Bryts av | Konsekvens |
|---|---|---|---|
| **A1** | Det finns *alltid* ett facksystem (Treserva) att committa till. | Roll 1,3,5,8,9,10,11,12 | Behövs `systemOfRecord`/`sorFallback` + `frendsModul='diarium'\|'ingen'\|null` |
| **A2** | Sekretess är *default*; offentlighet är undantag. | Roll 1,8,11,12 (offentlighet som norm) | ACL-default måste vara **per-roll konfigurerbar**, ej hårdkodad deny-by-default |
| **A3** | Sekretessnivån är *statisk* (`hög\|normal`). | Roll 1,3,8,9,11 (temporal anbudssekretess; arbetsmaterial→allmän handling) | Behövs `sekretessGrund[]` som **struct med temporalitet + händelse-trigger** |
| **A4** | Sagan ska *alltid* hitta en route och föda ett ärende. | Roll 1,3,5,8,9,10,11,12 (säkerhetsskydd → avvisa) | Behövs ett **terminerande utfall** `avvisad`/karantän före R2 |

### 0.3 Den centrala SoR-slutsatsen — var principen håller, var den brister

**"Hubs är ALDRIG System of Record" håller — som princip om verksamhetsdata.** Men den är underspecificerad och måste skärpas till en **fyrvägs-doktrin**, eftersom modellen tyst antog att ett SoR alltid *finns* och att Hubs alltid *får röra* informationen:

| Utfall | När | Princip-status |
|---|---|---|
| **(i) Fallback-SoR** — Hubs vidareförmedlar/committar till diarium/e-arkiv | SoR saknas men handlingen är/blir allmän handling | **Behålls** — täcker majoriteten av (c)-fallen |
| **(ii) Nytt register / pekare** — Hubs blir EJ SoR men pekar dit | Strukturerad data utan facksystem; alla (e) restricted (visselblåsning) | **Behålls** (Hubs pekar) |
| **(iii) Tidsbegränsat mellanlager med spårbarhet** | Varken SoR eller fallback finns | **Undantag** — kräver uttryckligt beslut, gallringsregel, rättslig grund |
| **(iv) AVVISA / KARANTÄN** — Hubs vägrar ta emot/lagra | Säkerhetsskyddsklassificerat | **Inversion** — Hubs får ej vara ens mellanlager |

**Den dödligaste felmoden** (återkommer i 9 av 12 roller): ett ärende som **varken** committas till SoR **eller** medvetet avslutas → **Hubs blir SoR genom passivitet** (skuggregister utan gallringsklocka). Motgift: en tvingande invariant — **varje config-rad MÅSTE ha icke-null `commitDestination`**, och retention-flippen (R8) får aldrig ske utan att ett av de fyra utfallen är explicit registrerat på raden.

**Den enda legitima SoR-roll Hubs får ta** är det smala **audit-/provenans-spåret** (handover skedde korrekt; mottagningskvittens fanns — roll 12) — aldrig verksamhetsdata.

---

## 1. DEN TVÄRGÅENDE FLAGG-MODELLEN

Flaggorna är **ortogonala mot ärendetypen** — de bär risknivå och tvärgående regim, inte routing-identitet, och styr maskning, audit-skärpa och eskaleringsväg (à la `akut_fara` i grunddokumentets §4). Användarens utökade set, som gäller **alla** roller:

| Flagga | Semantik | Effekt på motorn |
|---|---|---|
| `akut_risk` | Akut fara för liv/hälsa (barn far illa, våld, suicid, hot) | Routing-override → jour/mottagning; prio-höjning |
| `barn_berörs` | Barn är part eller berörd | Höjer ACL-default; aktiverar skyddsbedömning |
| `skyddade_personuppgifter` | Folkbokföringssekretess (OSL 22 kap.) / kvalificerad skyddsidentitet | **Hård ACL-override** → snävast krets; göms i ALLA aggregat |
| `hälsa_diagnos_funktionsnedsättning` | GDPR art. 9 särskild kategori | Egen laglig-grund-gate, ej bara ACL |
| `lagöverträdelse_misstanke` | Brott/oegentlighet/jäv misstänks | Kan *öppna* anmälningskanal (bryter sekretess) ELLER isolera |
| `rättslig_frist` | Lagstadgad/domstolsbunden tidsgräns | Aktiverar FristChip med bindande:'lag' |
| `personuppgiftsincident(72h)` | PU-incident enligt GDPR art. 33 | Startar 72h-klocka ur *kännedom*; ev. dubbelanmälan |
| `säkerhetsskydd_beredskap` | Säkerhetsskyddsklassificerat / beredskapsregim | **Karantän/avvisning** (ej snävare ACL) — se §5.4 |
| `felmottaget_men_känsligt` | Känsligt landade fel; ska vidare oöppnat | Triggar karantän-vidarebefordra-grind + retroaktiv isolering |

**Två skillnader mot grundmodellen avslöjas av rollserien:**
1. `riskKlass` (normal/förhöjd/hög/kritisk) måste bli en **härledd, explicit axel** ovanpå flaggorna — flera roller har ärenden som är *lågrisk-typ men högrisk-instans* (en synpunkt som bär lagöverträdelse, en faktura som bär jäv, en månadsrapport med akut fara). Risk ska fångas **och** ärendetyp fångas, ortogonalt.
2. `säkerhetsskydd_beredskap` och `felmottaget_men_känsligt` är inte vanliga ACL-modifierare utan **terminerande/retroaktiva** — de kortsluter sagan respektive river redan satta taggar (se §2.8, §5.4).

---

## 2. MODELL-FIT & NÖDVÄNDIGA ArendeTyp-UTÖKNINGAR

### 2.1 Passar mallen brett? — verdikt per roll

Klassning: **REN CONFIG** (ryggrad + befintliga fält, bara nya enum-värden) · **CONFIG + HOOK** (deklarerad pre/post-hook, ingen ny motor) · **NYTT FÄLT** (config-raden utökas strukturellt) · **NYTT SAGA-UTFALL** (sagan kan *vägra* föda).

| Roll | Klassning | Tyngsta tillägg |
|---|---|---|
| **1. Registrator/Nämndsekr.** | NYTT FÄLT + SAGA-UTFALL | Temporal sekretess (anbud), `handling_med_sekretessbilaga`, dubbel-commit diarium→e-arkiv, säkerhetsskydd→avvisa, anonymitetsskydd (TF 2:18) stänger av matchningskaskaden |
| **2. Kommunsjuksköterska/HSL** | NYTT FÄLT | Multi-commit (`frendsModul[]`), `frendsModulMode` (Pascal/NPÖ får ej skrivas), `vardgivarSpar` SoL↔HSL, partsmodell god man |
| **3. HR/Chef** | NYTT FÄLT + SAGA-UTFALL | `hr_*`-modulfamilj, `aclProfil='vissel_isolerad'`, `chef_är_part`, `restricted`-nivå, GDPR art.9-gate, säkerhetsskydd→avvisa |
| **4. Överförmyndare** | NYTT FÄLT | `inflodeVia` (e-tjänst går *förbi* mellanlagret), `sorProdukt` (3-delad marknad), anmälningsplikt-flagga som *öppnar* kanal, ställföreträdare-som-motpart |
| **5. IT/Infosäk/Dataskydd** | NYTT FÄLT + SAGA-UTFALL | `fristPolicy` multi-milstolpe (NIS2 24/72/30h), `sorFallback`, `dubbelanmalan` (IMY+MSB), säkerhetsskydd→karantän, klassning som *policy-input* |
| **6. Skola/Elevhälsa** | NYTT FÄLT + HOOK | `sekretessMur` (EMI/HSL ↔ utbildning), `anmal_huvudman`-hook, triage-only-läge, `skyndsamt_odefinierat` |
| **7. Omsorgsutförare** | NYTT FÄLT + HOOK | `frendsModul='verkstallighet'`, `preSagaHook='spegla_myndighetsbeslut'` (ärende föds ur *inkommande beslut*), `vardgivarSpar`, `ivo_anmalan`-hook |
| **8. Bygglov/Miljö/Livsmedel** | NYTT FÄLT + SAGA-UTFALL | `frendsModul` byter målfamilj (ByggR/Ecos), `objektRef` ersätter `barnRef`, `fristPolicy.ankare='komplett_ansokan'`, samordnad tillsyn = två commits, säkerhetsskydd→avvisa |
| **9. Upphandling/Ekonomi** | NYTT FÄLT + SAGA-UTFALL | `sekretessNiva='temporal'` + `sekretessHandelse`, `partsModell='objektsärende'`, `leverantorskontroll`-hook, säkerhetsskydd→avvisa |
| **10. Säkerhet/Beredskap** | NYTT FÄLT + SAGA-UTFALL | `sekretessNiva='sakerhetsskydd'`, `retentionAnkare` (kamera/lagstadgad ≠ commit), `höjd_beredskap`-regimbyte, `frendsModul=null\|diarium`, **terminerande** preSagaHook |
| **11. Kommunjurist/Vissel** | NYTT FÄLT + SAGA-UTFALL | `frendsModul='diarium'`, `forstaAtgard='menprovning'\|'radgivning'`, temporal ACL, `diariePlikt='bedoms'`, utlämnandeprövning (utåtriktad axel), vissel→isolera |
| **12. Medborgarservice/KC** | NYTT FÄLT + HOOK | `forstaAtgard='karantan_vidarebefordra'` (anti-saga), transit-/handover-SoR (Hubs *är* legitimt SoR för audit-spåret), `partsModell='anonym_begaran'`, betingad diarieplikt |

**Sammanräkning: 0 av 12 roller är ren config rakt av.** Kärntesen (datadriven config, ej kod-fork) **bekräftas** — men config-radens *vokabulär* var underspecificerad.

### 2.2 Δ-FÄLT 1 — `systemOfRecord` (ersätter implicit "alltid Treserva") — löser A1

```
systemOfRecord   enum   — facksystem | diarium | crm_kontaktcenter | extern_etjanst
                          | triage_forward | extern_myndighet | ingen | karantan
sorFallback      enum   — diarium | e_arkiv | ingen_route | null
inflodeVia       enum   — funktionsadress | e_tjanst | muntlig_kontakt
                          (e_tjanst = informationen går FÖRBI Hubs-mellanlager — roll 4)
```

Modellens `frendsModul`-enum (`ifo_barn|…|familjeratt`) antar att commit alltid landar i ett socialtjänst-facksystem. Roll 1/8/11 committar till **diariet** (Public360/W3D3/ByggR/Ecos), roll 12 till **CRM** (Artvise/Lime), roll 5/9 har **inget SoR** (`ingen` + `sorFallback='diarium'`), roll 12/6 är **triage-only**. `inflodeVia='e_tjanst'` är roll 4:s avgörande insikt: när medborgaren lämnar årsräkningen via e-Wärna äger facksystemet *både* inlämningskanal och SoR → Hubs får **inte** dubbel-lagra.

### 2.3 Δ-FÄLT 2 — `sekretessGrund[]` + temporalitet (ersätter skalär `sekretessNiva`) — löser A3 (delvis A2)

```
sekretessGrund[]  struct[] — { grund:  'OSL_26'|'OSL_39'|'OSL_19_3_anbud'|'OSL_31_16_affar'
                                       |'OSL_32_4_of'|'OSL_18_brott_skydd'|'OSL_25_PDL'
                                       |'PDL_HSL'|'GDPR_art9'|'sakerhetsskydd'|'TF_offentlig',
                               omfattning: 'hel_akt'|'delfalt',          ← delfält = anmälaridentitet (roll 8)
                               temporal:  { utgangsTrigger, utgangsDatum } | null }
sekretessNiva     enum     — offentlig_tills_menprovad | normal | hog | restricted | sakerhetsskydd
```

Tre brutna antaganden samtidigt: **temporal sekretess** (absolut anbudssekretess OSL 19:3 gäller *tills tilldelningsbeslut*, sedan flippar offentligheten — roll 1,8,9,11; spegelvänt: arbetsmaterial som *blir* allmän handling vid beslut — roll 3,11); **sekretessgrund-bredd** (OSL 39 HR, 19:3/31:16 affär, 32:4 ÖF, 18 brott/skydd, 25/PDL HSL, GDPR art. 9); **`restricted`-nivå** ovanför `hog` och **`sakerhetsskydd`** som binär regim utanför OSL. `offentlig_tills_menprovad` (roll 8,11,12) löser **A2** — deny-by-default vore *fel* för bygglov/KC.

### 2.4 Δ-FÄLT 3 — `riskKlass` (risknivå ortogonalt mot ärendetyp)

```
riskKlass   enum  — normal | forhojd | hog | kritisk
                    (härleds ur flaggor + sekretessgrund; styr aggregat-synlighet,
                     audit-skärpa och eskaleringsväg — INTE routing)
```

Fångar användarens krav att kategorierna måste bära **både ärendetyp OCH risknivå**. En härledd, ortogonal axel (à la flaggorna) — ersätter inte primärkategorin.

### 2.5 Δ-FÄLT 4 — `externMyndighetMottagare` + `commitMode` (commit-mål utanför kommunen)

```
externMyndighetMottagare  enum|null — IMY | MSB | IVO | Lansstyrelsen | polis | sakerhetspolis
                                      | tingsratt | mark_miljodomstol | forsakringskassan | null
commitMode                enum      — commit | referens_lank | las_konsument | extern_anmalan
                                      (las_konsument = Pascal/NPÖ: Hubs får ALDRIG skriva — roll 2)
```

R7-commit antar ett *internt* facksystem. För flera roller är målet en **extern myndighet** (IMY 72h, MSB NIS2, IVO Lex Maria/Sarah, Länsstyrelse, polis) — sällan maskin-API → `commitMode='extern_anmalan'` (Hubs *förbereder*, människa lämnar in). `commitMode='las_konsument'` är roll 2:s principiella skydd: kommunsjuksköterskan är *konsument* av läkarens Pascal-ordination — Hubs får länka/läsa men aldrig skriva.

### 2.6 Δ-FÄLT 5 — `verksamhetsgren` + `sekretessMur` (mur, inte gradient)

```
verksamhetsgren  enum  — utbildning | emi_hsl | sol | hsl | personaladm | of_tillsyn | ...
sekretessMur     struct — { delningKraverMenprovning: bool, getsEjBlandasMed: enum[] }
```

`aclProfil` (en *gradient*: snäv/normal/bred) kan inte uttrycka en **mur** mellan två *självständiga verksamhetsgrenar* i samma fysiska person/akt: EMI/HSL-journal (PDL, OSL 25) ↔ skoldokumentation (OSL 23) hos roll 6; SoL (OSL 26) ↔ HSL (PDL) hos roll 2/7. Utan detta fält **route:ar modellen HSL-innehåll in i en SoL-akt — en sekretessincident inbyggd i datamodellen.** Den enda *hårda* nya fält-utökningen som inte kan uttryckas som flaggvariant.

### 2.7 Δ-FÄLT 6 — `partsModell` utökad + `joinNyckel`

```
partsModell  enum  — enskild_klient | flerpartsärende | vardnadshavare_godman
                     | uppdrag | objektsärende | anonym_begaran | myndighetssamverkan | ingen_part
joinNyckel   enum  — ssn | barnRef | objektRef | upphandlingsRef | avtalsRef | conversationId
```

Modellens `partsModell` och `barnRef`-SSN-matchning antar **personärenden**. Men roll 8/9 är **objektärenden** (`objektRef`=fastighetsbeteckning / `upphandlingsRef`) → SSN-steget i `ArendeMatchService`-kaskaden är meningslöst; roll 7 är **uppdrag** (samverkande parter med åtskild insyn); roll 12/1 är **anonym** (TF 2:18 förbjuder att efterfråga begärarens identitet) → SSN-steget måste **stängas av** kategori-specifikt; roll 10 är **myndighetssamverkan** (polis/region/länsstyrelse som scoped externa parter).

### 2.8 Δ-FÄLT 7 — `karantanKravs` / säkerhetsskydds-grind (terminerande utfall) — löser A4

```
karantanKravs        bool  — true → kör terminerande preSagaHook FÖRE R2
sakerhetsskyddRegim  enum  — nej | mojlig | klassificerad
                             (klassificerad = får EJ ligga i moln/normal-IT → avvisa)
```

**Detta är A4 — det enda som rör motorn, inte bara config.** 9 av 12 roller flaggar säkerhetsskyddsklassificerad information som en **hård gräns**: enligt säkerhetsskyddslagen (2018:585) får sådan information ofta inte ligga i ett normalt molnsystem alls. Sagan (R1–R10) antar att *alla* ärenden *skapas*. Här krävs ett **negativt utfall**: en terminerande `preSagaHook='avvisa_sakerhetsskydd'` som kör *före* R2, **vägrar mint:a register-rad med innehåll, vägrar skapa Spreed-rum/Groupfolder**, och lämnar bara ett spårbart avvisningskvitto. Roll 10 skärper: avvisningen måste kunna ske **retroaktivt** (felmottaget → radera ur index/tagg-DB/Groupfolder *efter* mottagning), och själva upptäckten kan vara en säkerhetsskyddsincident. Utökar `InnehallsKlassService` med ett **`avvisa/isolera`-utfall** vid sidan av klassad/föreslagen/oklassad.

> **`absolutSekretessTom` (anbud, temporal):** realiseras inte som eget fält utan som `sekretessGrund[].temporal = {utgangsTrigger:'tilldelningsbeslut'}` (Δ-FÄLT 2) + post-commit-hooken `slapp_anbudssekretess`. Den **bakåtverkande** läckan (roll 9) är central: när sekretessen släpper blir *även Spreed-historiken och Deck-korten* potentiellt utlämningsbara → temporal ACL måste degradera hela `case:`-klustret, inte bara akten.

**Fält-delta:** 7 nya/ombyggda fält — **6 rena datafält**, **1 (`karantanKravs`) berör motorn**. Breddningen är ~85 % data, ~15 % avgränsad motor-utökning.

### 2.9 Cross-role-routing — ärenden som spänner över roller

Tre arketyper återkommer (mönster, inte kantfall):

- **Arketyp A — Eskaleringskedja:** orosanmälan (skola, roll 6) → socialtjänst (roll 1-domänen). Skolan äger inget ärende; den är anmälningsskyldig (14 kap. 1 § SoL) och vidareförmedlar.
- **Arketyp B — Parallell flermottagare:** *samma* händelse föder *flera* commits till *flera* SoR med *olika* sekretessregim. Roll 5:s ransomware-med-PU = NIS2-incident (MSB, 24h) **och** PU-incident (IMY, 72h) samtidigt.
- **Arketyp C — Felmottaget men känsligt (transit):** roll 12/1 — känsligt landar fel, ska vidare *oöppnat*; roll 11 — visselblåsning läcker in på `juridik@`.

Lösning — lyft dubbelmärkningen (primärkat + flaggor) till cross-role på **instansen**, inte typen:

```
primarRoll          text       — ägande roll/ärendetyp (styr process-mall, SoR, retention)
medmottagare[]      struct[]   — { roll, sekretessGrund, commitMode, aclScope:'egen_kerna' }
                                 ← varje medmottagare får EGEN ACL-krets + EGEN commit; akterna slås ALDRIG ihop
felmottaget_men_kansligt  bool — triggar karantän-vidarebefordra-grind (Δ12) + retroaktiv isolering
korsAxel            enum       — eskalering | parallell_flermottagare | transit
```

**Tre designregler:** (1) primärroll + medmottagare är ortogonala (undviker kombinatorisk explosion av "en kategori per roll-kombination"); (2) medmottagares akter får **aldrig** slås ihop (sekretessmur, Δ-FÄLT 5 — delat `hubsCaseId` för koordinering, separata commit-mål med olika ACL); (3) `felmottaget_men_känsligt` måste **fail-closed på svaga signaler** och kunna isolera **retroaktivt** (opt-out ur `ArendeMatchService` *innan* SSN-ankring, stoppa propagering, riva redan satta taggar — knyter till GAP-063).

> **Visselblåsning är inte en cross-role-route utan en NON-route.** Roll 3/9/11 är eniga: visselblåsning (lag 2021:890) får *inte ens passera sagan* — `ConsolidateMailboxesService`, `ArendeMatchService` och reconciliation-loopen är direkt oförenliga med isoleringskravet. Den är ett **separat restricted SoR utanför Hubs domän** (Lantero/WhistleB); Hubs roll är att *avvisa och isolera* felmottaget, aldrig route:a. Samma hårda gräns som säkerhetsskydd.

### 2.10 Behörighetsmatris / inre sekretess som skalar till 12+ roller

ACL vilar på **GAP-058 tre-lagers-koherens** (`case:`-tagg ∩ Groupfolder-ACL ∩ Tables-vy = samma sanning) och **Circles** som kanonisk medlemskapssanning (`GroupLifecycleListener`/`GroupMembershipListener`/`UserLifecycleListener`, synkad team→ACL→Talk-deltagare, readiness-matris rad 459). Grunden **bär** breddningen men måste generaliseras:

**`aclProfil` blir ett bibliotek, inte en gradient:**

| aclProfil (bibliotek) | Form | Drivande roller |
|---|---|---|
| `socialtjanst_deny_default` | deny-by-default, snäv krets | 1(soc-domän), 2, 7 |
| `offentlig_tills_menprovad` | **allow-by-default**, maskning av delfält | 8, 11, 12 |
| `vissel_isolerad` | deny-all utom oberoende krets, **döljs även för gruppledare**, ingen aggregat-synlighet, **ur matchningsmotorn** | 3, 9, 11 |
| `partsatskillnad` | motparter ser ej varandras inlagor (AG↔anställd, förälder↔förälder, kommun↔motpart) | 3, 8, 11 |
| `verksamhetsgrens_mur` | hård mur mellan självständiga grenar i samma akt | 2, 6, 7 |
| `extern_part_scoped` | extern myndighet/region/utförare scoped till rummet, ej hela akten | 5, 7, 10 |
| `dso_oberoende` | avskild krets **även gentemot egen IT-chef** (intressekonflikt om IT *är* incidenten) | 5 |
| `temporal_degraderbar` | ACL *lättar* vid extern händelse (tilldelning) | 1, 8, 9, 11 |

**Skalningsmekaniken (Circles):** roll-/enhets-Circles instansieras per funktionsadress (`registrator@`, `dataskydd@`, `mas@`, `vissel@`, `bygglov@` …) — `ConsolidateMailboxesService` är redan korg-/funktionsadress-scopad, så detta är **konfiguration, inte ny kod**. `aclProfil`-id väljer vilket Circle-mönster R4 instansierar; `dso_oberoende`/`vissel_isolerad` skapar Circles som *avsiktligt utesluter* den ägande enhetens gruppledare. **Jäv-baserad intra-Circle-exkludering** (roll 11) är en ny ACL-dimension (`exkludera_uid[]` på instansen) som GAP-058-koherenstestet måste täcka. `skyddade_personuppgifter`-flaggan **överstyr** alla profiler till hårdare; `sakerhetsskydd` är *inte* en profil utan ett **avvisnings-utfall**.

**Tre skalnings-risker att flagga:** (1) **juristkontot som högvärdesmål** (roll 11) korsar OSL-gränser legitimt → mest privilegierade profilen; varje åtkomst måste avge `CriticalActionPerformedEvent` (audit finns, rad 460) — medveten bred profil, men den enda. (2) **ACL-default per roll, inte globalt** (A2). (3) **aggregat-läckage åt två håll** (roll 1): server-aggregatet (`/api/v2/inflode-summary`) måste klara *både* socialtjänstens döljande och diariets publika sökbarhet utan att läcka det diariet inte publicerar — GAP-058-koherensen måste fungera symmetriskt.

---

## 3. PER-ROLL-ANALYS (12 roller, A–G)

> Format per roll: A. Kategorier × ArendeTyp-fit · B. SoR-typ (a–e) + verkliga systemnamn · C. Integrationsbehov · D. Inget-bra-SoR + princip-stress-test · E. Nya flaggor/frister/sekretessgrunder · F. Roll-specifika modell-utökningar · G. Blind fläck. SoR-typer: **(a)** tydlig enkel · **(b)** fragmenterad · **(c)** inget bra SoR · **(d)** triage-only · **(e)** separat restricted.

### ROLL 1 — Registrator / Nämndsekreterare

Registratorns "facksystem" är e-diariet — men flera kategorier lever i Word/Excel/mejl *innan* diarieföring, och nämnd-/visselblåsarspåren har egna SoR-frågor. **Huvudtyp: (b) FRAGMENTERAD** med tre kategorier som tippar i (c) och en i (e).

**A.** Begäran allmän handling (`forstaAtgard='sekretessprovning'`, **skyndsam utlämnande-frist**, anonymitetsskydd TF 2:18) · Nämndärenden m. sekretessbilagor (`partsModell='handling_med_sekretessbilaga'` — en handling, två sekretessnivåer) · Överklaganden (`preSagaHook='diariefor_direkt'`, rättidsprövning 3 v FL 44 §) · Klagomål/synpunkter (triage, inget eget SoR) · Personalärenden (fragmenterad: HR + diarium) · Upphandling (**temporal anbudssekretess** OSL 19 kap.) · Säkerhet/skyddsvärt (`pliktGrind='sakerhetsskydd_avvisa'` → karantän).

**B.** Primär SoR = e-diariet: **Public360** (TietoEVRY), **W3D3/Ciceron/Platina** (Formpipe), **Evolution/LEX** (Sokigo), **Castor**. + e-arkiv (**Sydarkivera**/FGS) som andra commit-destination. + nämndmodul (Evolution/Platina/Netpublicator). Visselblåsning = **(e)** separat restricted (Lantero/WhistleB, 2021:890). Säkerhetsskydd = utanför normal SoR helt.

**C.** e-diarium-konnektor (**hög, GAP-019-klass ×5 produkter**) · e-arkiv FGS (`postCommitHook='arkivera_fgs'`) · upphandlingssystem (trigger: tilldelning → flip temporal sekretess) · domstol/förvaltningsrätt (frist-spegling, manuell) · visselblåsarsystem (**medvetet INGEN integration**).

**D.** (1) Diarieföringspunkten själv: handling före registreringsbeslut har inget SoR — Hubs ÄR till för denna fas, men registratorns *icke-diarieföringsbeslut* måste tvingas vara en spårad åtgärd (annars otillåten gallring av allmän handling, TF). (2) Klagomål = triage-only. (3) Säkerhetsskydd = hård gräns → avvisa/karantänsätt.

**E.** Frister: `skyndsam_utlamnande` (timmar/samma dag, JO), `rattidsprovning_3v` (ankare=delgivning), `justeringsfrist`. Sekretess: **temporal** anbudssekretess, säkerhetsskyddsklassificering, anonymitetsskydd (sökandens identitet är *inte* en partsuppgift).

**F.** `sekretessNiva` → struct med temporalitet · nya `forstaAtgard`-värden (sekretessprövning, rättidsprövning, triage, karantänsätt) · nytt saga-utfall `avvisad` · `partsModell='handling_med_sekretessbilaga'` · dubbel commit-destination (diarium→e-arkiv) · `diariePlikt='registreringsbeslut'` (mot tyst gallring).

**G.** Begäran om allmän handling är *själv* en allmän handling (rekursiv) · anonymitetskravet (TF 2:18) **stänger av** matchningskaskadens SSN-steg · tyst icke-diarieföring = otillåten gallring · diariets offentlighet vs Hubs-aggregatens sekretess (ACL-koherens båda hållen) · visselblåsarkanalen får ej konsolideras in · justeringens händelse-trigger ≠ kalenderdygn.

### ROLL 2 — Kommunsjuksköterska / HSL

Den kommunala HSL-journalen är tydlig SoR för *journal*, men flödet splittras över **minst fem ägande system**. **Huvudtyp: (b) FRAGMENTERAD.** Bryter principen på två punkter (avvikelse-utredning; samtyckesbeslut = typ c).

**A.** Inskrivning/utskrivning/SIP (`koordinering`, **dubbel-commit** journal + samordning) · Medicinska underlag · Ordinationer/läkemedel/delegering (**frendsModul-trio** Pascal/MCSS/journal; delegering = tidsbegränsad behörighet) · Patientsäkerhet/avvikelser (`postCommitHook='lex_maria_bedomning'`, IVO extern) · Hemsjukvård/rehab/hjälpmedel (region-ägt `hjalpmedel`-SoR) · Smitta · Samtycke/menprövning (**inget bra SoR**, behörighets-gate).

**B.** Journal **(a)**: **Treserva HSL** (CGI), **Lifecare HSL** (TietoEVRY), **Viva/Combine/Procapita/PMO**. Läkemedel **(b)**: **Pascal** (Inera/e-HM — region äger), **NCS Cross/Sil**, **Appva MCSS** (Vitec). Översikt **(d/b)**: **NPÖ** (Inera, läsvy ej SoR), **SVOD**, **SITHS**, samordning **Link/Cosmic Link/Prator/Meddix**. Samtycke/behörighet **(c)**.

**C.** HSL-journal commit (medel) · **Pascal läs-konsument** (hög — ingen skriv-API; Hubs får ALDRIG skriva) · Appva MCSS (signering är MCSS:s SoR) · NPÖ/SVOD (hög — SITHS; **aldrig cacha annan vårdgivares data**) · samordningssystem (dubbelriktad, frist-ankare) · IVO Lex Maria (extern) · hjälpmedel (region) · SITHS.

**D.** (1) MAS-avvikelseutredningen mellan registrering och IVO lever i Word/Excel — fallback diarium/e-arkiv vid formalisering. (2) Samtyckes-/menprövningsbeslut = behörighets-attribut, Hubs aldrig auktoritativt samtyckesregister. Princip böjs (mellanlager längre), bryts inte.

**E.** Flaggor: `samtycke_informationsdelning`, `smitta_anmälningspliktig`, `delegering_aktiv/utgår`, `palliativ_livets_slut`, `god_man_anhorigbehorighet`. Frister: `delegering_omprövning` (1 år), `utskrivningsklar` (extern, ekonomiskt skarp). Grunder: patientdatalagen + inre sekretess, SVOD-lagen, läkemedels-/smittskyddssekretess (OSL 25 kap.).

**F.** **Multi-commit** (`commitPlan[]` / `frendsModulPrimar`+`frendsModulSekundar[]`) — största strukturella utökningen · **`frendsModulMode = commit | referens_lank | las_konsument`** · `partsModell='vardnadshavare_godman'` · hooks `lex_maria_bedomning`, `samtycke_kontroll`, `delegering_omprovning`.

**G.** **HSL och SoL = två rättsliga regimer i samma person** (modellen riskerar route:a HSL-innehåll i SoL-akt) · Pascal-glappet (läs-vs-skriv-asymmetri måste vara UI-explicit) · delegering = behörighets-livscykel ej dokument · NPÖ får aldrig cachas · avvikelser måste vara icke-bestraffande + separerade · utskrivningsklar är ekonomiskt skarp · MAS/MAR-systemansvar är ett organisationslager modellen saknar.

### ROLL 3 — HR / Chef (rehab & känsliga personalärenden)

Arbetsgivarutövning mot egen personal → fyra modell-brott: SoR = HR/lön + rehab (ej Treserva); sekretess = **OSL 39 kap.**; chefen ofta part/motpart; flera kategorier saknar facksystem. **Huvudtyp: (b) FRAGMENTERAD** med (c) och (e).

**A.** Sjukfrånvaro/rehabplan (`hr_rehab`, **`30d_rehabplan`** 30 kap. 6 § SFB, GDPR art. 9) · Arbetsanpassning/omplacering · Arbetsmiljö/OSA (**inget rent SoR**, partsåtskillnad anmälare↔utpekad) · Disciplin/LAS (`diariefor_direkt`) · MBL (**inget bra SoR**, `mbl_frist`) · Privat ekonomi/utmätning (`hr_lon` tydlig) · Säkerhetsprövning (Säpo; klassat→avvisa) · Visselblåsning (**separat restricted**, `vissel_7d`).

**B.** HR/lön: **Heroma** (CGI), **Personec P/Publitech** (Visma). Rehab: **Adato/LISA**. FHV = extern part. Säkerhetsprövning = Säpo. Visselblåsning = isolerat (Lantero/WhistleB/&frankly). SoR-klassning per kategori: 3.1/3.2 (b) · 3.3 (c) · 3.4 (a/b) · 3.5 (c) · 3.6 (a) · 3.7 (b/e) · 3.8 (e).

**C.** Heroma commit (hög, per-kund) · Personec/Visma alternativ-SoR (hög, per-kund-bytbar) · Adato/LISA · FK-koordinering (CalDAV, dag-30) · FHV-samverkan (extern part scoped) · Säpo (manuellt; säkerhetsskyddsgräns) · visselblåsarsystem (isolerat) · KFM löneavdrag.

**D.** OSA-utredning (3.3) och MBL-protokoll (3.5) saknar facksystem → diarium/e-arkiv fallback. Övergångszon där HR-ärendet aldrig når facksystem (informella tillsägelser) → tvinga explicit beslut (eskalera-commit eller gallra), aldrig "ligga kvar". Säkerhetsskyddsklassat → avvisa/karantän.

**E.** Frister: `30d_rehabplan`, `mbl_frist`, `vissel_7d` (bekräfta ≤7 dgr, återkoppla ≤3 mån), `as_24h` (AML 3:3a). Grunder: **OSL 39 kap.** (primär), GDPR art. 9, visselblåsarlagens identitetsskydd, säkerhetsskyddslagen. Flaggor: `chef_är_part`, `repressalierisk`, `facklig_part_inkopplad`.

**F.** Ny `frendsModul`-familj `hr_*` (rehab/las/lon/personalakt/vissel), per-kund-bytbar · `aclProfil='vissel_isolerad'` · `sekretessNiva='restricted'` · `partsModell='flerpartsärende'` med AG↔anställd/AG↔fack-axel · hooks `diariefor_direkt`, `sakerhetsskydd_grind`, `fk_plan_utlamning` · `retentionMall` per DHP (anställningshandlingar bevaras, rehab-hälsodata gallras snävare).

**G.** **Chefen är inne i sitt eget ärende** (default-ACL "ägande enhet får insyn" läcker → `chef_är_part` + tvingad eskalering ur linjen) · hälsodata = GDPR art. 9 ej bara OSL · FHV-data ska aldrig in · visselblåsning får ej matchas (SSN→anmäldes personalärende = katastrof) · säkerhetsskydd = avvisa · "personalakt" ofta ingen produkt · temporal sekretess (disciplinunderlag→allmän handling).

### ROLL 4 — Överförmyndarhandläggare

Egen kommunal tillsynsmyndighet (FB 19 kap.), dokumentcentrerad, granskningsdriven. **Per kommun ETT moget facksystem → (a) enkel SoR** *inom* kommun, men marknaden 3-delad. Ett (c)-undantag.

**A.** Anordnande god man (`of_anordnande`, tingsrätt beslutar) · Medicinska/sociala underlag (art. 9-känsliga) · **Årsräkning/granskning** (`of_granskning`, **`arsrakning_cykel`** massfrist 1 mars; e-tjänst matar in) · Tillstånd (fastighet/spärrat konto, affärsfrist) · Klagomål/tillsyn (`diariefor_direkt`) · Domstol/Länsstyrelse · Rekrytering/lämplighetskontroll (**glapp**, restricted).

**B.** Per kommun ETT av: **e-Wärna Go** (Explizit — *ej* CGI), **Provisum** (Sambruk), **Gö** (Sokigo). Extern: Länsstyrelsen (tillsyn), tingsrätt. Lämplighetskontroll = **(c) inget bra SoR**. **2028-skiftet:** Prop. 2025/26:92 inför nationellt ställföreträdarregister (2028) + central myndighet → flyttar behörighets-SoR ut ur kommunen.

**C.** Frends → e-Wärna/Provisum/Gö (hög, **3 konkurrerande system, `frendsMappning` per produkt ×3**) · **e-tjänst-inflöde** (ställföreträdaren lämnar via e-Wärna — *går förbi Hubs*) · tingsrätt/Länsstyrelse (hög, manuell) · belastningsregister/KFM (restricted) · nationellt register (2028, reservera nu).

**D.** (c1) Rekrytering/lämplighetskontroll lever i Word/Excel — behandla som **(e) separat restricted hållplats** tills 2028-registret löser uppströms. (c2) Granskningskommentarer (arbetsmaterial) = klassiskt mellanlager, commit vid formellt beslut. Bekräftar aldrig-SoR för 5/7 kategorier, böjer för lämplighetskontroll, bryter inte.

**E.** Flaggor: `anmalningsplikt_brott` (skärps 2026-07-01 — **bryter sekretess, öppnar kanal till polis**), `huvudman_skyddsbehov`, `intressekonflikt_stallforetradare`, `e_tjanst_inkommet` (provenans: gick förbi Hubs). Frister: `arsrakning_cykel`, `overklagande_3v`. Grund: **OSL 32 kap. 4 §**.

**F.** `frendsModul`-enum utökas (`of_*`) · **nytt fält `sorProdukt`** (ewarna_go|provisum|go_sokigo) · **nytt fält `inflodeVia`** (funktionsadress|e_tjanst — avgör om Hubs ska mellanlagra alls) · `postCommitHook='arsrakning_granskningsutfall'` · restricted-profil `of_lamplighet_restricted`.

**G.** **E-tjänsten konkurrerar med Hubs som mellanlager** (rita gränsen: Hubs äger bara funktionsadress-inflöde) · "klienten" är inte huvudmannen (trippel partsmodell, ställföreträdaren = misstänkt motpart vid tillsyn) · anmälningsplikten **öppnar** sekretess (default deny får ej blockera) · 2028 flyttar SoR ut · massvolym-säsong 1 mars (frist-färger röda en masse) · spärrade konton = extern affärsfrist.

### ROLL 5 — IT / Informationssäkerhet / Dataskydd

Hårdast stress-test av aldrig-SoR. **Huvudtyp: (c) INGET BRA SoR** med (e) och (b). Dataskyddsregistren (PU-incident, DPIA, RoPA, klassning) lever ofta i Word/Excel — Hubs riskerar bli SoR genom passivitet.

**A.** PU-incident (`72h_imy` ankare=kännedom; ev. `dubbelanmalan_imy_msb`) · Cyberincident NIS2 (**`nis2_24_72_30`** multi-milstolpe) · Behörighet/loggar (SIEM/IAM = SoR för rådata; Hubs länkar aldrig speglar) · Registrerades rättigheter (`1man_gdpr` förlängbar) · DPIA (**inget bra SoR**) · Informationsklassning (**policy-axel ej ärende**) · PuB-avtal/RoPA · Säkerhetsskydd/NIS2-kontinuitet (`sakerhetsskydd_karantan`).

**B.** **(c)** dominerande. ITSM: ServiceNow/Jira SM/Artvise/Easit. Dataskydd: Draftit/GDPR Hero/DPOrganizer/OneTrust (*om köpt*). Logg/IAM: Sentinel/Splunk/Entra. Diarium = fallback. **(e)** säkerhetsskydd.

**C.** Frends→ITSM (medel) · Frends→Draftit/GDPR Hero (hög/osäker, API saknas ofta) · Frends→IMY (72h, assisterad) · Frends→MSB/CERT-SE (NIS2, ny portal 2026) · läs ur SIEM/IAM (**får ej spegla rådata**) · diarium/e-arkiv (fallback).

**D.** Hårdast brott. DPIA/klassning ofta SoR-löst → fallback e-arkiv/diarium med tvingande exit-krav. PU-incident: commit-pliktigt (dataskyddsverktyg → diarium → e-arkiv). Säkerhetsskydd: **avvisa/karantänsätt** (`preSagaHook='sakerhetsskydd_karantan'` stoppar saga före R2). Kräver `sorFallback`-fält så destinationen blir datadriven och granskningsbar.

**E.** Frister: `72h_imy` (ankare=kännedom, ej inkom), `nis2_24_72_30` (multi-milstolpe), `1man_gdpr` (förlängbar). Flaggor: `nis2_betydande`, `sakerhetsskyddsklassificerad` (karantän, starkare än skyddade PU), `dubbelanmalan`. Grunder: OSL 21:7, OSL 18 kap., säkerhetsskyddslagen, cybersäkerhetslagen (2025:1506, i kraft 15 jan 2026), GDPR art. 33–36/12–22.

**F.** `fristPolicy` multi-milstolpe (`milstolpar[]`, `forlangningsbar`) · nytt `sorFallback`-fält · karantän-utfall i sagan · klassning som `infoKlassRef` policy-input (ej ArendeTyp-rad).

**G.** **Hubs själv är system-i-scope** (PU-incident i Hubs → out-of-band-väg) · 72h-ankaret = kännedom ej inkom · NIS2 vs GDPR = olika myndigheter/frister/samma händelse (två commits) · **DSO:ns oberoende** (art. 38 — avskild krets även mot egen IT-chef) · loggrådata får aldrig speglas · anbudssekretess temporal · säkerhetsskyddsklassificerat ≠ skyddade PU.

### ROLL 6 — Skola / Förskola / Elevhälsa / Rektor

**Dubbel sekretessmur** (EMI/HSL 25 kap. ↔ övriga skolan 23 kap.) stress-testar ACL. **Huvudtyp: (b) FRAGMENTERAD** med tungt (c) och (d).

**A.** Elevhälsa medicinskt/psykosocialt (**`sekretessMur`** EMI = egen verksamhetsgren) · Särskilt stöd/ÅP (ofta Word/PDF, (c)-risk) · Frånvaro/hemmasittande ((c) delvis) · Kränkande behandling (**inget SoR**, eskalering personal→rektor→huvudman, `anmal_huvudman`-hook) · Orosanmälan→socialtjänst (**(d) triage-only**) · Vårdnad/skyddade uppgifter (partsåtskillnad förälder↔förälder) · Skolplacering/skolskjuts (**(a)** Vega/Optiplan, överklagande 3 v) · Nyanlända.

**B.** **(a)** endast placering: **Sokigo Vega/Optiplan**. **(b)** EMI-journal **PMO (CGM)/ProReNata** ↔ skoladmin **IST/Edlevo** (TietoEVRY). **(c)** kränkande/frånvaro/ÅP (Word/PDF, Visma Draftit om köpt). **(d)** orosanmälan. **(e)** EMI/HSL-journal (patientdatalag, isoleras — mur ej gradient).

**C.** skolplacering (medel) · skoladmin (**hög, SS12000-standard som hävstång**) · **EMI-journal = LÄSGRÄNS ej commit** (mycket hög; pekare/notis ej innehåll) · orosanmälan ut (SSBTEK) · skyddade PU (spärr över alla moduler) · incidentmodul (Visma Draftit).

**D.** Kränkande behandling = lagstadgad eskaleringskedja utan utpekat system → diarium/e-arkiv fallback + `anmal_huvudman`. Frånvaroutredning/ÅP → fallback. Principen håller **bara om kommunen aktivt utser fallback-SoR** för incident/utredning — annars tyst gap.

**E.** Flaggor: `emi_hsl_sekretess`, `anmalningsplikt_sol`, `huvudman_eskalering`, `vardnadshavare_partsmotsattning`. Frister: överklagande 3 v, **`skyndsamt_odefinierat`** (varningston utan deadline). Grunder: **23 kap.** (utbildning) + **25 kap. + patientdatalag** (EMI) som två parallella murar.

**F.** **`sekretessMur`-fält** (enda hårda utökningen — verksamhetsgrens-isolering, ej gradient) · `anmal_huvudman`-hook · `forstaAtgard='triage_vidareformedla'` + `frendsModul=null` · `skyndsamt_odefinierat`/`overklagande_3v`.

**G.** Vårdnadshavares insynsrätt = aktiv routing-parameter (två föräldrar som kan vara motparter) · förskolan glöms i sekretessresonemanget (`forskola@` separat) · EMI-journal får ej *passera* Hubs som innehåll (avvisa-liknande) · Skolinspektionen/BEO som externa parter · "skyndsamt utan dagantal" är en fälla för dagbaserad frist-motor.

### ROLL 7 — Omsorgsutförare / Enhetschef (ÄO, LSS, socialpsykiatri)

Utföraren **beslutar inte** — verkställer ett redan fattat beslut. Sagan körs *baklänges* (föds ur inkommande uppdrag, ej inflöde). Avslöjar att grunddokumentets kat 7 var underspecificerad. **Huvudtyp: (b) FRAGMENTERAD** — mest SoR-splittrade rollen.

**A.** Beställning/uppdrag/GFP (`ta_emot_uppdrag`, `verkstallighet`, partsmodell uppdragsgivare↔utförare) · Social dokumentation · Lex Sarah (**(e)-nära restricted**, `ivo_anmalan`-hook) · Avvikelser (**`vardgivarSpar` SoL≠HSL**) · Anhöriga/god man · **Begränsningsåtgärder/samtycke** (**inget bra SoR**, `frihetsinskränkning_risk`) · Bemanning (hålls **utanför** ärendemodellen).

**B.** Verkställighet: **Treserva/Lifecare utförardel, Sekoia, Pulsen Combine, Magna Cura (CGI), Viva**. HSL-signering: **Appva MCSS** (Vitec). Tid/tillsyn: **Phoniro/TES/IntraPhone/Kompanion**. Lex Sarah = (e)-nära + IVO extern. Begränsningsåtgärder = **(c)**. Bemanning = (b/e) separat HR-domän (Medvind/Time Care/Heroma).

**C.** **Inkommande uppdrag myndighet→utförare** (hög — spegelvänt mot createCase) · GFP-commit (**mycket hög, per-produkt** — GAP-019 ×utförarmarknad) · Appva MCSS (vårdgivargräns) · Phoniro/TES (besökskvittens, `utebliven_insats`-risksignal) · Lex Sarah→IVO (extern, restricted) · schema (**integrera INTE** in i ärende-SoR).

**D.** Begränsningsåtgärder/samtycke (c): IVO-tillsyn visar samtycke ofta odokumenterat → `begransningsatgard`-register **bevis-för-frivillighet-orienterat**, slutlagring tvingad till social journal/e-arkiv. Lex Sarah intern utredning (e): restricted, isolerat från brukarrummet. HSL/SoL-avvikelse (b): får ej slås ihop — två system, två sekretessregimer.

**E.** Flaggor: `frihetsinskränkning_risk`, `lex_sarah_rapport`, `hsl_spar`, `utebliven_insats`, `företrädare_behörighet`. Frister (**avtals-/föreskriftsbaserade ej domstol**): `gfp_upprattande`, `gfp_omprovning`, `lex_sarah`, `avvikelse_analys`, `delegering_giltighet`. Grunder: **vårdgivargränsen SoL↔HSL**, uppdragsgivargränsen myndighet↔utförare, PU-ansvarsgränsen brukare↔personal.

**F.** Ny `frendsModul='verkstallighet'` per-produkt · `forstaAtgard='ta_emot_uppdrag'` + `preSagaHook='spegla_myndighetsbeslut'` (sagan föds ur inkommande beslut) · **nytt fält `vardgivarSpar`** (SoL|HSL|bada) · `partsModell='uppdrag'` · hooks `ivo_anmalan`, `samtyckesgrind` · restricted-register `begransningsatgard` · bemanning som *attribut* aldrig part.

**G.** **Modellen är myndighetscentrerad — utföraren var eftertanke** (privata utförare på andra produkter än myndigheten) · **vårdgivargränsen saknas helt i grundmodellen** (route:ar HSL-data i SoL-akt) · begränsningsåtgärder = juridisk tomhet (registret får ej legitimera tvång) · Lex Sarah pekar inåt mot personal · Phoniro-kvittens = realtids-risksignal modellen ej utnyttjar · avtalsfrister ≠ lagfrister (`bindande:'avtal'|'lag'`).

### ROLL 8 — Bygglov / Plan / Miljö- & hälsoskydd / Livsmedel

PBL + miljöbalken + livsmedelslag. **Partsinsyn som norm**, affärs-/anbudssekretess, `frendsModul` byter målfamilj (ByggR/Ecos ej Treserva). **Huvudtyp: (b) FRAGMENTERAD** med (a) per kategori, (c)- och (e)-fickor.

**A.** Bygglov (**`pbl_10veckor`** ankare=komplett ansökan; `byggr`) · Tillsyn PBL & MB (splittras byggr↔ecos, `diariefor_direkt`) · Klagomål (**asymmetrisk sekretess** — `anmalare_anonymitet` delfält) · Hälsoskydd (`ecos`) · Livsmedel (`affarssekretess`, `akut_folkhalsa` RASFF) · Företagshemligheter (temporal anbud) · Skyddsvärda objekt (**avvisa/karantän**) · Överklaganden (`yttrande_overinstans`, kommunen blir part).

**B.** PBL: **ByggR→Nova Bygg (Sokigo)**, EDP ByggReda. MB+livsmedel: **Ecos 2 (Sokigo)**, EDP MiljöReda. e-arkiv: **iipax ags (Ida Infront)**. **(b)** samma objekt → PBL i ByggR + MB i Ecos = två SoR. **(c)** affärssekretess-status, samordnad tillsyn. **(e)** säkerhetsskyddsklassade objekt (vattenverk). `TreservaCommitService` = fel namn → `FacksystemCommitService`.

**C.** ByggR/Nova (hög, GAP-019) · Ecos 2 (hög, tre delmoduler) · frist-spegling (hög, ankare=komplett ansökan) · Länsstyrelse/MMD (**mycket hög/manuell**, kommunen blir part) · fastighets-/GIS (medel) · e-arkiv · e-tjänsteportal.

**D.** Samordnad tillsyn PBL↔MB: gemensamt samarbete har inget eget SoR → **mellanlagrets legitima domän** (Spreed = samordningsyta), men **två commits utan akt-sammanslagning**. Affärs-/anbudssekretess temporal → `sekretessTemporal` + ACL-flip. Säkerhetsskyddsklassade objekt → **avvisa/karantänsätt** (fail-closed).

**E.** Flaggor: `anmalare_anonymitet` (asymmetrisk), `affarssekretess`, `anbudssekretess_temporal`, `akut_folkhalsa`, `sakerhetsskydd_skyddsvart_objekt`. Frister: `pbl_10veckor` (ankare=komplett, förlängbar), `mb_skyndsamhet` (mjuk), `kontrollfrekvens` (recurring). Grunder: OSL 30:23 (affär), 19:3/31:16 (anbud temporal), 32 kap. (anmälare), säkerhetsskyddsklass.

**F.** `frendsModul` byts (byggr/nova/ecos_*) + `FacksystemCommitService` · `fristPolicy.ankare='komplett_ansokan'` + `forlangningsbar` · `sekretessProfil` per-fält/per-grund (ej skalär) · **pre-hook `karantan_avvisa`** (avbryter sagan) · post-hook `yttrande_overinstans` · `samordnad_tillsyn` (två frendsModul/rum) · `diariePlikt='direkt'` default.

**G.** **Sekretessens default är OMVÄND** (offentlighet norm, sekretess undantag — deny-by-default vore fel) · partsinsyn/kommunicering = first-class-process modellen saknar primitiv för · **fastighet/objekt som matchningsnyckel ej person** (`objektRef`) · avgift/debitering = facksystemets · samma besök → flera regelverk → sekretessbrytande regel (bekvämlighet får ej bli läckage) · "skyddsvärt objekt" ≠ "skyddad person".

### ROLL 9 — Upphandling / Inköp / Ekonomi / Avtalscontroller

Till stor del **objekt-/processärenden** (ej personärenden). Två nya egenskaper saknas helt: **temporal sekretess** och **säkerhetsskydds-karantän**. **Huvudtyp: (b) FRAGMENTERAD** med (c), (e).

**A.** Anbud (**absolut anbudssekretess temporal** OSL 19:3, flippar vid tilldelning) · Affärshemligheter (`menprovning`) · Avtal/tvister (**`avtalslivscykel`** bevakar framtida datum) · Säkerhets-/IT-upphandling (**`sakerhetsskydd_grind`** SUA) · Fakturor (ERP tydlig SoR) · **Jäv/korruption** (**(e) `visselblasning_isolerad`**) · Leverantörskontroll (`leverantorskontroll`-hook).

**B.** Upphandling: **Mercell TendSign, Kommers/Primona, e-Avrop, Opic**. Avtal: modul/Excel (glapp). Ekonomi: **Unit4 UBW, CGI Raindance, Visma Proceedo, Inyett**. **(e)** visselblåsarsystem. Faktura ≈ (a), anbud/avtal (b), säkerhetsupphandling (c)+gräns, jäv (e).

**C.** Frends→upphandlingssystem (hög; **tilldelnings-event speglas tillbaka → ACL-flip**) · Frends→ERP (medel-hög) · Proceedo/Inyett (Inyett-flagga→oegentlighet) · externa register Skatteverket/Bolagsverket (källor ej SoR) · avtalsdatabas/e-arkiv · visselblåsarsystem (**medvetet isolerad**).

**D.** Avtalsbevakning i Excel (verkligt c-gap) → avtalsmodul om finns, annars diarium fallback + **flagga uttryckligen att det är fallback**. Anbudssekretess temporal = modellgap ej SoR-gap → tilldelning-callback flippar ACL. Säkerhetsskyddsklassificerad upphandling = **avvisa/karantän** (principen ska INTE böjas).

**E.** Flaggor: `anbudssekretess_aktiv` (rensas vid tilldelning), `affärshemlighet_leverantör`, `oegentlighet_jäv_misstanke`, `säkerhetsskydd_klassificerad`, `avtalsbevakning_aktiv`. Frister: `avtalslivscykel` (framtida datum), vissel 7 dgr/3 mån, **avtalsspärr/överprövning 10 dgr**. Grunder: OSL 19:3 2 st (absolut), 19:1+31:16, säkerhetsskyddslagen, 2021:890 + OSL 32:3 b.

**F.** **`sekretessNiva='temporal'` + `sekretessHandelse`** (största utökningen — Frends-callback bär sekretess-flip) · `fristPolicy.typ='avtalslivscykel'` · `preSagaHook='sakerhetsskydd_grind'` (utfall *avvisa*) · `preSagaHook='leverantorskontroll'` · `forstaAtgard='menprovning'` · `aclProfil='visselblasning_isolerad'` · **`partsModell='objektsärende'`** (`upphandlingsRef`/`avtalsRef`).

**G.** **ACL-flippen läcker bakåt** (Spreed-historik/Deck under sekretessperiod blir utlämningsbar) · tilldelning ≠ avslut (avtalsspärr/överprövning egen frist) · felmottaget anbud = brott mot 19:3 (hårdare karantän) · **jäv pekar ofta på egna handläggaren** (korg-scopad ACL duger ej → isolering utanför rollens krets) · leverantörs-PU (enskild firma) vs företagsuppgift (AB) · ERP äger attestkedjan (Hubs får ej duplicera attest).

### ROLL 10 — Säkerhetssamordnare / Beredskap / Räddningstjänst

Hårdast stress-test av **båda** principerna: för säkerhetsskydd får Hubs varken vara SoR *eller* mellanlager. **Huvudtyp: (b) FRAGMENTERAD** med tungt (e) och (c).

**A.** Hot/hat/personskydd (**(b/d)** triage→polis/Säpo) · RSA & kontinuitet (**(c) inget SoR**, `cyklisk_rapportering` 2-årscykel) · **Säkerhetsskydd/skyddsvärda objekt** (**(e)** `sakerhetsskydd`→karantänspår) · Krisledning/lägesbild (`koordinering`, WIS/Rakel) · Räddningstjänst/olycksundersökning (**(a) Daedalos**) · LSO-tillsyn · Samverkan (**(d) triage-only**) · Larm/kamera (`kamerabevakning` — gallring per kamerabevakningslagen ej commit).

**B.** **(a)** **Daedalos (Sokigo)** — räddningstjänstens "Treserva". **(b)** krisledning: **WIS (MSB), Rakel, diarium**. **(c)** RSA (Word/Excel/SharePoint). **(d)** samverkan. **(e)** säkerhetsskydd (FM-godkänd krypto/signalskydd krävs).

**C.** Frends→Daedalos (medel) · WIS (hög, MSB-ägt, manuell/länk) · Rakel/RIB (referens ej data-SoR) · diarium/e-arkiv (kritisk fallback för c-fall) · MSB olycksrapport IDA (sekundär, **från Daedalos ej Hubs**) · kamera/passer (metadata ej video) · polis/Säpo (hög, manuell).

**D.** RSA (c, uppåt): inget facksystem → Hubs hanterar *processen* (cykelpåminnelse, versionsspår) + committar formella leveranser till diarium/e-arkiv. Säkerhetsskydd (e, nedåt): **Hubs får ej ens vara mellanlager** → DETEKTERA→AVVISA/KARANTÄN (`preSagaHook='avvisa_sakerhetsskydd'` vägrar mint:a, vägrar Spreed-rum). Hot/hat (d) triage-only.

**E.** Flaggor: `säkerhetsskydd_klassificerat` (hårdare än beredskap — karantän/abort), `höjd_beredskap`/`krig` (regimbyte), `kamerabevakning`, `samverkanspart_extern`. Frister: `cyklisk_rapportering` (RSB vartannat år). Grunder: OSL 18 kap., **säkerhetsskyddslagen (2018:585) + förordning (2021:955)** (egen regim utanför OSL), kamerabevakningslagen.

**F.** `sekretessNiva` med tredje icke-OSL-värde `sakerhetsskydd` · **avvisande preSagaHook** (terminerande, ej additiv — abort+kompensering) · `frendsModul = null|diarium|ingen` · **`retentionAnkare = commit|lagstadgad_tid|vidaresant_kvittens`** · `partsModell='myndighetssamverkan'`.

**G.** **Den farligaste vägen är inkommande** (felmottaget säkerhetsskydd → retroaktiv karantän ur index/Spreed/Groupfolder/tag-DB; upptäckten = säkerhetsskyddsincident) · "Hubs får ej vara SoR" och "får ej vara mellanlager" = två gränser, modellen kan bara första · **höjd beredskap kan göra hela Hubs olämpligt** (regimbyte flyttar kategorier) · Rakel ej en integration · gallring av kamera/RSA bryter R8 · Daedalos äger redan MSB-rapporteringen (ingen dubblett).

### ROLL 11 — Kommunjurist / Visselblåsarfunktion / Klagomålsfunktion

Tre väsensskilda regimer. **Första rollen där "ett generiskt flöde" inte räcker rakt av.** Huvudtyp varierar per kategori: (b)/(c)/(e).

**A.** Sekretessprövning/utlämnande (`menprovning`, **skyndsamt** icke-numerisk, `frendsModul='diarium'`) · Skadestånd (`partsModell='flerpartsärende'` kommun vs motpart) · Arbetsrätt/diskriminering (gräns mot HR) · **Visselblåsning** (**(e)** — får EJ passera sagan, `avvisa/isolera`) · Avtal/leverantörstvist (**temporal anbudssekretess**) · Brott/polisanmälan (förundersökningssekretess).

**B.** **(b)** diarium: **Public360/W3D3/Platina/Ciceron/Evolution**. + e-arkiv, avtal (TendSign/Kommers), HR (Visma/Heroma), försäkring. **(c)** **juridisk rådgivning/PM** (Word/Outlook/mappar — kärnan i rollen, inget facksystem). **(e)** visselblåsning: **Lantero/WhistleB/Draftit Whistle/&frankly**.

**C.** **Diarium-konnektor (`frendsModul='diarium'`)** — viktigaste nya, per-produkt GAP-019 · avtal/upphandling (temporal sekretess läser tilldelningsdatum) · HR (peka ej spegla) · **visselblåsarsystem = integration ska medvetet UTEBLI** · e-arkiv.

**D.** Bryter aldrig-SoR på tre sätt: (c1) juridisk rådgivning utan facksystem → `forstaAtgard='radgivning'` + `diariePlikt='bedoms'` + `frendsModul='ingen'` (tvingar beslut). (e) visselblåsning får ej passera sagan — `felmottaget_men_känsligt` + isolera. (c2) tvistehandläggning → diarium fallback (grovt schema).

**E.** Flaggor: `anbudssekretess_aktiv{utlopsdatum}` (temporal), `repressalieskydd`, `partsstallning_anstalld`, `motpartsforhallande`. Frister: **`skyndsamt`** (icke-numerisk, TF 2:16), vissel 7 dgr/3 mån, avtalsspärr 10 dgr. Grunder: **TF/OSL utlämnandeprövning** (utåtriktad axel — ny), förundersökningssekretess, affärssekretess temporal.

**F.** `frendsModul` bortom socialtjänst (`diarium`, `avtal`, `ingen`) — största utökningen · `forstaAtgard='menprovning'`/`'radgivning'` · **temporal ACL** (`postCommitHook='slapp_anbudssekretess'`) · `diariePlikt='bedoms'` · **karantän-utfall i `InnehallsKlassService`** · `partsModell='flerpartsärende'` med motpartssemantik.

**G.** **Jäv/informationsmur** (juristen kan vara motpart till kollega — intra-Circle-exkludering modellen saknar) · visselblåsning som läcker in fel kanal (fail-closed på svaga signaler, stoppa propagering retroaktivt) · **utlämnande vs inre sekretess = olika riktningar** (utåtriktad axel; avslag är överklagbart → sekundärärende) · **juristen ser allt** (korsar OSL-gränser legitimt → mest privilegierade profilen, högvärdesmål, audit `CriticalActionPerformedEvent`) · arbetsmaterial byter rättslig status över tid · säkerhetsskydd överlappar.

### ROLL 12 — Medborgarservice / Kontaktcenter / Reception / Växel

Äger **inget eget facksystem-SoR** — primärfunktion är **triage + säker vidarebefordran**. Skarpaste brottet mot aldrig-SoR: Hubs måste vara SoR för transit-/handover-provenansen. **Huvudtyp: (d) TRIAGE-ONLY** med (c).

**A.** Felriktade sekretessärenden (**`karantan_vidarebefordra`** — aldrig öppna, `preSagaHook='sekretess_overlamning'`) · Akuta signaler (`akut_fara` eskalera NU, triggar socialtjänsts saga) · Begäran allmän handling (**`skyndsamhet`** TF 2:15–16, `frendsModul='diarium'`) · Klagomål/synpunkter (CRM, `diariePlikt='villkorlig'`) · Skyddade PU (hård ACL-override) · E-tjänststöd (servicekontakt, ofta gallras).

**B.** **(d)** dominerande (4/6) — Hubs-mellanlaget ÄR funktionen; SoR = mottagarens facksystem. **(c)** synpunkter: **Artvise Kundtjänst, Lime CRM, Freshdesk, Sokigo e-förslag**; allmän handling: **W3D3/Public360/Ciceron/Evolution**. *Seed-korrigering:* "Vision (Sokigo)" ej verifierat — Sokigos produkter är Nova/Evolution/e-förslag.

**C.** **Säker handover-brygga** → funktionsadresser (låg-medel; grind+audit, GAP-063) · CRM (Artvise/Lime/Freshdesk, medel) · diarium/dokumentsystem (medel-hög, skyndsamhetsklocka speglas) · e-tjänst/identitet (LOA-resolver) · **eskaleringsbrygga → socialjour** (mottagningskvittens-krav).

**D.** Fyra brott/gap: (1) felmottag-handover — **Hubs SoR för handover-provenansen** (vem/vad/till vem/när; gallras ej med ärendet). (2) Begäran allmän handling — diariet SoR, men KC sitter *före* diariet med skyndsamhetsklockan (mellanlager tills commit). (3) Synpunkter (c) — CRM som SoR, `diariePlikt='villkorlig'` uppgraderar vid lagöverträdelse. (4) Akuta signaler i transit — **eskalerings-kvittens som SoR** ("skickat" ≠ "mottaget"). **Verdikt:** aldrig-SoR för *verksamhetsdata* håller, men Hubs måste vara SoR för transit-/handover-/eskaleringsprovenansen.

**E.** Flaggor: `felmottaget_men_känsligt` (primär hemvist), `vidarebefordrad_oöppnad`. Frister: **`skyndsamhet`** (ankare=inkom, ingen fast dag), `sla_policy` (aldrig röd rättslig chip). Grunder: **TF 2 kap.** (offentlighet *default* — motsatt socialtjänst), `sekretessNiva='offentlig_tills_menprovad'`, `diariePlikt='villkorlig'`.

**F.** `forstaAtgard='karantan_vidarebefordra'` + `preSagaHook='sekretess_overlamning'` (anti-saga) · `frendsModul` öppen enum (`crm_kontaktcenter`, `diarium`) · `partsModell='anonym_begaran'`/`ingen_part` (TF tillåter anonym) · `postCommitHook='handover_kvittens'` (saga stänger på handover-ack ej dnr).

**G.** **KC = kommunens vanligaste plats för obehörig sekretess-spridning** (gör handläggning strukturellt svårare än vidarebefordran) · säkerhetsskydd/anbudssekretess via telefon (KC vet sällan vad de håller på med — `säkerhetsskydd_beredskap` stoppar routing helt) · anonymitetskollision med skyddade PU · **CRM som skugg-diarium** (aktiv uppgraderingsregel) · telefon/växel lämnar inga handlingar (ingen `conversationId` för muntlig kontakt) · `hör_till`-matchning svagare (SSN-matchning = KC:s vanligaste fall → strängare tröskel).

---

## 4. VERKSAMHETSSYSTEM- & INTEGRATIONSBEHOV-MATRIS

### 4.1 Den stora matrisen

| Roll | Verksamhetssystem (produkt + leverantör) | SoR-typ | Integrationsmönster | Svårighet/risk | Volym |
|---|---|---|---|---|---|
| **1 Registrator** | Public360 (TietoEVRY) · W3D3/Ciceron/Platina (Formpipe) · Evolution-LEX (Sokigo) · Castor · Sydarkivera/FGS · Lantero/WhistleB | **(b)** +1→(e) | Frends→e-diarium (`{hubsCaseId→dnr}`) + `arkivera_fgs`; upphandling→flip; **INGEN** visselblås | Hög — e-diarium = Treserva-klass **×5**; temporal sekretess | Mkt hög |
| **2 Kommunsjuksköterska** | Treserva HSL (CGI) · Lifecare HSL (TietoEVRY) · Viva/Combine/PMO · **Pascal/NCS/Appva MCSS** · NPÖ/SVOD (Inera) · Link/Prator | **(b)** | Frends→HSL-journal; **läs-konsument** Pascal/NPÖ; dubbelriktad samordning; manuell Lex Maria→IVO | Hög — **multi-commit**; läs-vs-skriv-asymmetri; HSL/SoL-vägg | Hög |
| **3 HR / Chef** | Heroma (CGI) · Personec P/Publitech (Visma) · Adato/LISA · FHV · Säpo registerkontroll | **(b)**+(c)+(e) | Frends→Heroma/Visma (`hr_*`); API Adato; FK dag-30; manuell Säpo; separat visselblås | Medel-hög — `chef_är_part`; GDPR art. 9; säkerhetsskyddsgräns | Medel |
| **4 Överförmyndare** | **e-Wärna Go (Explizit) · Provisum (Sambruk) · Gö (Sokigo)** · tingsrätt · Lst · belastningsreg./KFM · **nat. register 2028** | **(a)** + (c)-ficka | Frends→ÖF (**per-produkt ×3**); **e-tjänst förbi Hubs**; cyklisk årsräkning; manuell tingsrätt | Medel — e-tjänst konkurrerar; partsbyte | Medel + **säsongstopp 1 mars** |
| **5 IT/Infosäk/Dataskydd** | Draftit/OneTrust/DPOrganizer/GDPR Hero · ServiceNow/Jira/Artvise · Sentinel/Splunk · Entra · **IMY · MSB/CERT-SE** | **(c)**-kärna +(b)(d)(e) | Frends→GDPR-verktyg *ELLER* diarium-fallback; API-läs SIEM (**länk ej spegla**); manuell IMY/MSB; karantän | **Mkt hög** — bryter aldrig-SoR hårdast; 24h/72h/30d; **ankare=vetskap** | Medel (hög konsekvens) |
| **6 Skola/Elevhälsa** | PMO/ProReNata (EMI) · IST/Edlevo/Extens (**SS 12000**) · Sokigo Vega/Optiplan · Visma Draftit · →socialtjänst | **(b)** + (c) + (d) | Frends→skoladmin (SS 12000); **read/notify EMI** (PDL); `anmal_huvudman`; **triage** oros→IFO; diarium-fallback | Hög — **EMI↔skola dubbelmur**; skyddade PU | Mkt hög |
| **7 Omsorgsutförare** | Sekoia · Magna Cura/Viva (CGI) · Combine · Treserva/Lifecare utförardel · Appva MCSS · Phoniro/TES/IntraPhone · IVO | **(b)**+(c)+(e) | Frends→verkstallighet (**per-produkt**); `spegla_myndighetsbeslut`; läs Appva/Phoniro; `ivo_anmalan` (**maskar PII**) | Hög — myndighet→utförare SoR-omkastning; **HSL/SoL-axel** | Hög |
| **8 Bygglov/Miljö** | **ByggR→Nova Bygg (Sokigo)** · EDP ByggReda · **Ecos 2 (Sokigo)** · Lantmäteriet/GEOSECMA · Lst/MMD · iipax ags | **(b)** 2 starka (a) +(c)+(e) | Frends→**ByggR + Ecos** (två commit-mål, ingen sammanslagning); två-fas-frist (ankare=**komplett ansökan**); `karantan_avvisa` | Hög + **INVERTERAD sekretess** (offentlighet-by-default) | Hög |
| **9 Upphandling/Ekonomi** | Mercell TendSign · Kommers/Primona · e-Avrop · Unit4 UBW · Raindance (CGI) · Visma Proceedo/Inyett · BV/Skv/KFM | **(b)** 3 silos +(c)+(e) | Frends→3 moduler; **`anbudssparr_tills_tilldelning`** (omvänd hook); API kvalificering; isolerat visselblås | Hög — temporal absolut sekretess; **felöppning > felkoppling** | Medel/Hög |
| **10 Säkerhet/Beredskap** | **Daedalos (Sokigo)** · Core/Alaros · WIS (MSB, manuell) · Rakel/RIB · RSA (Word) · Tutus | **(b)**+(a)+(c)+(e) | Frends→Daedalos; manuell WIS; incident-notis CCTV; **`sakerhetsskydd_grind`** (saga-abort); cyklisk RSA→diarium | Hög — objekt-centrerad; kamera-retention ≠ commit | Låg-medel (hög konsekvens) |
| **11 Kommunjurist/Vissel** | **Lantero/WhistleB/Draftit Whistle** (INGEN integr.) · Public360/W3D3/Platina/Ciceron · JO/JK/domstol | **(e)**+(d)+(b)+(c) | **INGEN** visselblås (undantas `ConsolidateMailboxes`); Frends→**diarium** (ny familj); `menprovning`; **temporal ACL-flip**; manuell JO/JK | Hög — 3 regimer i en roll; juristkonto = högvärdesmål | Låg-medel |
| **12 Medborgarservice/KC** | **Artvise · Lime CRM · Freshdesk** · W3D3/Public360 · Open ePlatform/Abou · telefoni→alla | **(d)** triage + (c) | **Hubs-mellanlager ÄR funktionen**; säker handover-grind (oöppnat + audit); Frends→CRM/diarium; eskaleringsbrygga | Medel — enorm volym; **felmottaget/skyddade PU passerar lätt** | Mkt hög |

SoR-typer: **(a)** tydlig enkel · **(b)** fragmenterad · **(c)** inget bra SoR · **(d)** triage-only · **(e)** separat restricted.

### 4.2 Konnektor-gruppering — var EN konnektor återanvänds

| Kluster | Leverantör | Roller | Hävstång |
|---|---|---|---|
| **e-diarium/e-arkiv** (Public360, W3D3, Ciceron, Platina, Evolution, Castor, Sydarkivera/FGS, iipax) | TietoEVRY · Formpipe · Sokigo · Ida Infront | **1, 3, 8, 9, 10, 11** + fallback för **5, 6, 7** | **HÖGST** — primär-SoR för R1/R11 OCH laglig fallback för varje (c)-zon. EN konnektor avlastar 9 roller |
| **Sokigo-fack** (ByggR/Nova, Ecos 2, Daedalos, Evolution, Vega) | Sokigo | **8, 10** (+1, +4-Gö, +12) | JA delvis — gemensam transport, per-produkt schema |
| **TietoEVRY-fack** (Lifecare, Lifecare HSL, IST/Edlevo, Public360) | TietoEVRY | **1, 2, 6, 7** | JA — Treserva/Lifecare-konnektorn (GAP-019) = referensmall |
| **CGI-fack** (Treserva, Magna Cura, Viva, Heroma, Raindance) | CGI | **1, 2, 3, 7, 9** | Delvis — en transportadapter, olika fältscheman |
| **Visma/Unit4/Mercell** (Personec, UBW, Proceedo, TendSign) | Visma · Unit4 · Mercell | **3, 5, 9** | Delvis |
| **Appva MCSS** (signering/delegering HSL+SoL) | Vitec | **2, 7** | **JA** — identisk read/notify i båda |
| **Pascal/NPÖ/SVOD/samordning** (Inera, SITHS) | Inera/e-HM | **2** (+7 läs) | **Läs-only** — annan vårdgivares SoR, committar aldrig |
| **CRM/kontaktcenter** (Artvise, Lime, Freshdesk) | — | **12** (+5) | Smal — KC:s enda commit-mål |
| **Visselblåsarsystem** (Lantero/WhistleB/&frankly) | — | **1, 3, 9, 11** | **Medveten ICKE-integration** (2021:890) |

### 4.3 Prioriterad integrations-roadmap

- **P0 (bär flest roller):** (1) **Generaliserad e-diarium/e-arkiv-konnektor** — täcker R1/3/8/9/10/11 + fallback-SoR för varje (c)-roll = enskilt största hävstången. (2) **Treserva/Lifecare-konnektorn (GAP-019)** generaliserad till mall för alla TietoEVRY-moduler.
- **P1:** (3) Sokigo-kluster (ByggR+Ecos+Daedalos) R8/R10. (4) Externa myndighets-commit-klassen (§4.4). (5) Appva/MCSS read/notify R2/R7.
- **P2:** HR (R3) · Överförmyndare (R4) · SIEM/IAM/ITSM-läs (R5) · Ekonomi (R9).
- **ICKE-bygg medvetet:** visselblåsarsystem (isolering) · säkerhetsskydd (karantän/avvisning) · WIS (manuell referens).

### 4.4 Externa myndighets-"commits" — egen integrationsklass (≠ facksystem)

**Princip:** rapport till tillsyn ≠ commit till facksystem. **Ingen dnr-callback · ankare = vetskap (ej inkom-datum) · ofta PII-maskning.** `commitDestination.typ='extern_myndighet'`; flippar **inte** `retentionState`.

| Myndighets-commit | Frist/ankare | PII | Roller |
|---|---|---|---|
| **IMY** PU-incident (GDPR 33) | **72h** ur vetskap | payload-prep, människa lämnar | 5 (+förgrening) |
| **MSB/CERT-SE** NIS2 | **24h→72h→30d** ur vetskap | — | 5, 10 |
| **IMY+MSB samtidigt** | två klockor från **en** händelse | två commits | 5 (ransomware m. PU) |
| **IVO** Lex Sarah | rapport "utan dröjsmål" → utredning ≤**5v** | **maska** | 7 |
| **IVO** Lex Maria | "snarast" | **maska** | 2, 7 |
| **Försäkringskassan** plan dag-30 | **30d** ur första sjukdag | hälsa art.9 | 3 |
| **Visselblåsarfunktion** (2021:890) | bekräfta **≤7 dgr** · återkoppla **≤3 mån** | identitetsskydd | 1, 3, 9, 11 |
| **Länsstyrelsen** tillsyn | cyklisk/per beslut | beror | 4, 8, 10 |
| **Polis/åklagare** | skyndsam; **anmälningsplikt bryter sekretess** | beror | 4, 7, 10, 11 |
| **Domstol** (FR/MMD/TR) | T-7/T-3/T-0; överklagande 3v | partsinsyn vs OSL | 1, 4, 6, 8, 11 |
| **Nat. ställföreträdarregister** | **fr.o.m. 2028** | — | 4 (reservera nu) |

### 4.5 GAP-019: per-modul → per-system-mappning

Den verifierade motorn har EN väg: `TreservaCommitService::commitToTreserva()` → `POST .../treserva/commit`, med `frendsModul ∈ {ifo_barn|…|familjeratt}` + per-modul `frendsMappning` (hårdkodad stubb). Bredden visar att `frendsModul` är fel abstraktionsnivå:

- **Tjänst:** `TreservaCommitService` → **`FacksystemCommitService`** (väljer konnektorfamilj).
- **Mappning:** per-modul → **per-system** — samma modul (`ediarium`) har olika schema per produkt (Public360 ≠ W3D3 ≠ Platina). Biblioteket blir **2-dim: (modul × produkt)**.
- **Kardinalitet:** en `frendsModul` → **`frendsModul[]`** (R2 journal+samordning, R7 verkställighet+Appva, R8 ByggR+Ecos — flera mål, ingen akt-sammanslagning).
- **Riktning:** + `frendsModulMode ∈ {commit · referens_lank · las_konsument}` (Pascal/NPÖ = läs-only).
- **Callback:** + sekretess-flip (R9 tilldelning→släpp anbud) + kvittens-callback (extern myndighet, ingen dnr).

Treserva-konnektorn byggs först **som referensimplementation**; e-diarium-konnektorn (P0) är andra instansen som bevisar att mappningen är (modul × produkt).

---

## 5. NO-SoR-ANALYS + ALDRIG-SoR-PRINCIPEN

### 5.1 Fallinventering — alla (c) och (e) över de 12 rollerna

**Typ** = c (inget bra SoR) eller e (separat/restricted). **Rek** = utfall (i)–(iv) från §0.3.

| # | Roll | Fall | Typ | Idag | Rek |
|---|---|---|---|---|---|
| F1 | R1 | Diarieföringspunkten själv (handling före registreringsbeslut) | c | Mejl/Word | (i) |
| F2 | R1 | Klagomål/synpunkter (triage) | c→d | Vidareförmedlas | (i)/(ii) |
| F3 | R1 | Säkerhetsskydd/skyddsvärda handlingar | e | Ska ej i normal-IT | **(iv)** |
| F4 | R1 | Visselblåsning (angränsande) | e | Isolerad kanal | (ii)+isolering |
| F5 | R2 | MAS-avvikelseutredning (registrering→IVO) | c | Word/Excel/SharePoint | (i)→(ii) |
| F6 | R2 | Samtyckes-/menprövningsbeslut (NPÖ/SVOD) | c | Journalanteckning/spärr | (ii) |
| F7 | R2 | Avvikelsespåret (icke-bestraffande) | (e)-nära | Egen ACL-domän | (i)+isolering |
| F8 | R3 | Arbetsmiljö/OSA-utredning | c | Word + Outlook | (i) |
| F9 | R3 | Fackliga/MBL-protokoll | c | Word/diarium | (i) |
| F10 | R3 | Informella tillsägelser/samtal | c | Ingenstans | **(iii)** el. gallra |
| F11 | R3 | Säkerhetsprövning (Säpo) | e | Säpo äger | **(iv)** för klassat |
| F12 | R3 | Visselblåsning | e | Separat restricted | (ii)+isolering |
| F13 | R4 | Rekrytering/lämplighetskontroll | c | Word/Excel/mejl-silo | **(iii)**→(ii) 2028 |
| F14 | R4 | Granskningskommentarer (arbetsmaterial) | c | Mejl/Excel | (iii)→(i) |
| F15 | R5 | PU-incident (72h) utan verktyg | c | Excel | (i)→(ii) |
| F16 | R5 | DPIA/riskbedömning | c | Word | (i)/(ii) |
| F17 | R5 | Informationsklassning (K/R/T) | c | Excel — **policy-axel** | (ii) uppslagskälla |
| F18 | R5 | Säkerhetsskydd/NIS2-kontinuitet | e | Ej i normal-IT | **(iv)** |
| F19 | R6 | Kränkande behandling (eskalering) | c | Mejl/Word; Draftit om köpt | (i) |
| F20 | R6 | Frånvaro/hemmasittande-utredning | c | Word/mejl | (i) |
| F21 | R6 | Pedagogisk utredning/ÅP + SVA | c | PDF/Word i elevakt | (i) |
| F22 | R6 | EMI/HSL-journal | e | PMO/ProReNata — **mur** | (ii)+pekare |
| F23 | R6 | Orosanmälan → socialtjänst | d | SoR = IFO | (i) kvittens |
| F24 | R7 | Begränsningsåtgärder/samtycke | c | Pärm/Word; ofta ej alls | **(iii)**→(i) |
| F25 | R7 | Lex Sarah intern utredning | e | Word→diarium; IVO slut | (i)+isolering |
| F26 | R7 | HSL- vs SoL-avvikelse | b/glapp | Två system, två regimer | (i)×2 separerat |
| F27 | R8 | Samordnad tillsyn (PBL↔MB) | c | Mejl/Word | **(iii)** + (i)×2 |
| F28 | R8 | Affärs-/anbudssekretess (temporal) | c | Akt/Word/mejl | (i)+temporal ACL |
| F29 | R8 | Säkerhetsskyddsklassade objekt | e | Ej i facksystem | **(iv)** |
| F30 | R9 | Avtalsbevakning (löptid/option/vite) | c | Excel/pärm/mejl | (i)→(ii) |
| F31 | R9 | Anbudssekretessens temporala natur | c (modellgap) | Statisk sekretess | (i)+temporal flip |
| F32 | R9 | Säkerhetsskyddsklassificerad upphandling (SUA) | e | Ej i moln | **(iv)** |
| F33 | R9 | Jäv/korruption/oegentligheter | e | Separat visselblås | (ii)+isolering |
| F34 | R10 | RSA & kontinuitet (LEH) | c | Word/Excel; 2-årscykel | (i)+(iii) |
| F35 | R10 | Säkerhetsskydd/skyddsvärda objekt (SäkL) | e | Ej i normal-IT | **(iv)** |
| F36 | R10 | Hot/hat & samverkan (triage) | d | polis/Säpo/region | (i) kvittens |
| F37 | R10 | Krisledningsmaterial under höjd beredskap | e (regim) | Kan bli klassat | **(iv)** vid regimbyte |
| F38 | R11 | Juridisk rådgivning/PM/internremiss | c | Word/Outlook/mappar | **(iii)** el. (i) |
| F39 | R11 | Tvistehandläggning | c | Diariet "diverse" | (i) grovt schema |
| F40 | R11 | Visselblåsning | e | Lantero/WhistleB | (ii)+isolering, **ej passera sagan** |
| F41 | R11 | Säkerhetsskyddsavtal/SUA-juridik | e | Ej i moln | **(iv)** |
| F42 | R12 | Felmottag-/handover-provenans | c (ny smal SoR) | Ingen logg äger händelsen | **(iii)** Hubs = audit-SoR |
| F43 | R12 | Begäran allmän handling (KC före diariet) | c→a | Mejl/Word | (i) |
| F44 | R12 | Synpunkter/klagomål | c | Artvise/Lime/Excel | (ii) CRM, villkorlig (i) |
| F45 | R12 | Akut signal i transit (mottagningskvittens) | c (ny smal SoR) | "Skickat" ≠ "mottaget" | **(iii)** eskaleringskvittens-SoR |
| F46 | R12 | Säkerhetsskydd via telefon/disk | e | KC vet sällan vad de håller i | **(iv)** |

**Mönster i tvärsnittet:** Säkerhetsskydd **(iv)** i **8 roller** (mest universella hårda gränsen). Visselblåsning **(e)** i **5 roller** (alltid: separat restricted + opt-out ur matchningsmotorn). "Utredning/arbetsmaterial mellan registrering och beslut" = vanligaste (c)-mönstret (oftast lösbart med (i)). Temporal sekretess = **modellgap ej SoR-gap**. **Två genuint nya smala SoR-roller för Hubs** (F42, F45): handover-/eskaleringsprovenans — det enda stället Hubs *legitimt* måste vara SoR, men bara för audit-spåret.

### 5.2 Fall-kluster + risk

- **Kluster A — utredning/arbetsmaterial mellan registrering och beslut** (F5,8,9,14,16,19,20,21,34,38,39): risk = otillåten gallring av allmän handling, sekretessläckage i delad SharePoint, förlorad spårbarhet, frist-miss.
- **Kluster B — behörighets-/samtyckesbeslut utan register** (F6,24,17): risk = skör behörighets-gate, rättslig tomhet (begränsningsåtgärder), frihetsinskränkning utan dokumenterat samtycke.
- **Kluster C — separat restricted** (F4,7,12,25,33,40): risk = identitetsläckage, matchningsmotorn som fiende (case-tagg→conversationId→SSN), konsolidering som fiende.
- **Kluster D — säkerhetsskydd** (F3,11,18,29,32,35,37,41,46): risk = lagbrott (SäkL 2018:585), retroaktiv kontamination.
- **Kluster E — temporal sekretess** (F28,31): risk = sekretessbrott om för tidigt släppt; historik-läckage bakåt.
- **Kluster F — triage + handover/eskaleringsprovenans** (F2,23,36,42,43,44,45): risk = "föll mellan stolarna", mottagningsgaranti saknas.

### 5.3 Princip-stress-test: rekommendation per kluster

- **Kluster A → (i) Fallback-SoR, principen behålls.** Hubs legitimt mellanlager under utredningsfasen. **Tvingande exit-krav:** retention får aldrig flippa till "klart" förrän handlingen committats ELLER ett uttryckligt icke-diarieföringsbeslut registrerats. `sorFallback`-fält gör destinationen datadriven.
- **Kluster B → (ii) Nytt register / behörighets-attribut.** Hubs aldrig auktoritativt samtyckesregister. Informationsklassning (F17) = policy-axel (`infoKlassRef`), ej ärende. Begränsningsåtgärder = bevis-för-frivillighet, eskalera till Lex Sarah, normalisera aldrig tvång.
- **Kluster C → (ii)+isolering.** Visselblåsarsystemet ägs/slukas ej av Hubs. **Tre hårda opt-outs:** ur `ArendeMatchService`, ur `ConsolidateMailboxesService`, egen ACL-domän `vissel_isolerad` (döljs även för gruppledare). Får inte ens passera sagan (`avvisa/isolera`-utfall, fail-closed på svaga signaler).
- **Kluster D → (iv) AVVISA/KARANTÄN** (se §5.4).
- **Kluster F → blandat (i) + (iii) smal audit-SoR.** Ren triage: mottagarens SoR äger; Hubs loggar vidareförmedling, raderar aldrig tyst. Handover-/eskaleringsprovenans: **Hubs legitimt SoR för audit-spåret** (`postCommitHook='handover_kvittens'` — saga stänger på bekräftad mottagningskvittens, ej facksystem-commit).
- **Kluster E → modellgap.** `sekretessNiva='temporal'` + `sekretessHandelse{trigger,datum}`; tilldelnings-callback som R7-liknande verifierad händelse flippar ACL. **Bakåt-läckage:** historiken i mellanlagret under sekretessperioden är potentiellt utlämningsbar.

### 5.4 SÄKERHETSSKYDD-GRÄNSEN som hård arkitektonisk regel

Den viktigaste enskilda regeln. Gäller **8 roller**, kvalitativt annorlunda än all annan sekretess.

> **Säkerhetsskyddsklassificerat ≠ skyddade personuppgifter.** Skyddade PU → snävare ACL *inom* systemet. Säkerhetsskyddsklassificerat → **får inte vara i systemet alls.** Att behandla det förra som det senare läcker; det senare som det förra är **lagbrott** (säkerhetsskyddslagen 2018:585 + förordningen 2021:955).

**Vad Hubs INTE får ta emot/lagra:** säkerhetsskyddsklassificerade uppgifter i internetexponerad/molnburen Nextcloud/Spreed utan FM-godkänd kryptering/signalskydd — inkl. skyddsvärda objekt (R8/R10), SUA-upphandling (R9), säkerhetsprövningsdata (R3), säkerhetsskyddsavtal (R11), klassad RSA/krisledning under höjd beredskap (R10).

**Detektion/avvisning — fail-closed gatekeeper FÖRE sagan:** `preSagaHook='sakerhetsskydd_grind'/'avvisa_sakerhetsskydd'` körs som **led -1, FÖRE R1 (mint)**. Alla befintliga hooks körs *inom* sagan och *lägger till* steg; denna **avbryter**. Negativt sagautfall `avvisad_sakerhetsskydd`. Utfall: **ingen Groupfolder, ingen Tables-rad med innehåll, ingen case-tagg, inget Spreed-rum, ingen Frends-commit** — bara ett spårbart avvisningskvitto. Deterministiskt fail-closed (samma disciplin som `MessageTypeService` kastar Exception på okänt suffix); default på säkerhetsskydds-indikator = **avvisa**.

**Retroaktiv karantän vid felmottagning** (det svåraste): den farligaste vägen är inkommande. Karantän måste kunna ske **retroaktivt** — radera ur index, Spreed, Groupfolder, tag-DB. Triggas på **svaga** signaler (fail-closed), stoppar propagering retroaktivt (extra svårt när taggen redan spridits trådbrett, R11). Själva **upptäckten kan vara en säkerhetsskyddsincident** att rapportera (strängare än PU-incidentens 72h).

**Regimskifte (R10):** `höjd_beredskap`/`krig` kan flytta **hela kategorier** från normal regim till säkerhetsskydd-regim. Modellen behöver ett **läges-/regimbyte**, inte bara per-ärende-flaggning.

### 5.5 Beslut som måste fattas (per kommun) — inte i kod

1. **Fallback-SoR per (c)-kategori** (allmän handling→diarium ELLER arbetsmaterial→(iii) tidsbegränsat mellanlager med gallringsregel).
2. **Köp av fackverktyg vs diarium-fallback** (Draftit/DPOrganizer för DPIA; avvikelsemodul för MAS; incidentmodul för kränkande behandling).
3. **Begränsningsåtgärder:** bekräfta att registret är bevis-för-frivillighet, inte legitimering av tvång.
4. **Visselblåsar-isolering:** verifiera opt-out ur Match + Consolidate + aggregat.
5. **Säkerhetsskydds-detektion:** vilka funktionsadresser är högrisk för felmottagning; är retroaktiv karantän testad?
6. **2028-skiftet (R4):** reservera integrationspunkt mot nationellt ställföreträdarregister.

---

## 6. DET DU BORDE FRÅGAT — prioriterade blinda fläckar

Vad rollerna *tillsammans* avslöjar men ingen enskild roll äger. Sorterat efter hur hårt det slår mot arkitektur/bygge.

**P1 — Säkerhetsskydd är ett *terminerande* utfall, inte en sekretessnivå (HÅRD GRÄND).** Roller 1,3,4,5,8,9,10,11,12. Hela motorn antar att varje inflöde *ska* hitta en route. Konsekvens om ej byggt: klassificerat material indexeras/taggas/hamnar i Spreed *innan* klassningen hinner — i sig en säkerhetsskyddsincident. Kräver `sekretessNiva='sakerhetsskydd'` som *terminerar*, `preSagaHook` med **abort-semantik**, **retroaktiv karantän**.

**P2 — PuB-/laglig-grund-/ändamålskarta saknas (PuB-footprint).** Alla 12, ingen äger frågan. Hubs mellanlagrar känsliga PU under *olika lagliga grunder per roll* (OSL 26/39/32:4/23/25/19/30/31, PDL, GDPR art. 9). Utan en **PuB-/ändamålsmatris i config** (`lagligGrund`, `personuppgiftsansvarig`, `andamaalsbegransning`, `gdpr_art9` per rad) blir Hubs juridiskt osäljbart (PuB-avtal oundertecknbart, RoPA omöjlig) och regim-blandning (HSL i SoL-akt) blir inbyggd sekretessincident. Behövs `vardgivarSpar`/`regimAxel` som håller isär regimerna fysiskt.

**P3 — Temporal sekretess som *flippar*.** Roller 1,5,8,9,11. Modellen har bara statiska nivåer. Dyker upp i 5 roller oberoende → genuint modellgap. ACL-flippen **läcker bakåt** (historik i mellanlagret blir utlämningsbar). Glöm ej avtalsspärr/överprövningsfönstret.

**P4 — `frendsModul` antar Treserva; halva rollserien committar annorstädes.** Roller 1,3,4,5,6,7,8,9,10,11,12. `TreservaCommitService` är fel abstraktion → `FacksystemCommitService` med **konnektorfamilj** + `frendsMappning` per produkt × kund. **Tyngsta integrationsbördan.**

**P5 — Sagan kan bara *föda*; flera roller behöver "vägra", "ta emot uppdrag", "triage-only".** Roller 4,5,7,8,9,10,11,12. Tre utfall saknas: avvisa/karantän (P1), ta emot redan fattat beslut (R7 baklänges), triage-only utan commit (R12/R6/R10). `forstaAtgard` behöver `ta_emot_uppdrag`, `karantan_vidarebefordra`, `menprovning`, `triage_vidareformedla`; `retentionAnkare` måste lösgöras från commit.

**P6 — "Hubs blir SoR genom passivitet" — det tysta gallrings-/retention-hålet.** Roller 1,2,3,5,6,7,9,11. När ingen facksystem-commit sker blir Hubs-rummet *de facto* sista lagringsplatsen. För R1 = otillåten gallring av allmän handling. Kräver `sorFallback` + tvingande exit-krav + spårat `diariePlikt='bedoms'/'registreringsbeslut'`. Konfigurationskrav per kommun, ej kod.

**P7 — Visselblåsning får ej röra matchningsmotorn/konsolideringen.** Roller 1,3,4,7,9,11. `aclProfil='visselblasning_isolerad'` + explicit *icke*-integration (delar ej join-nyckel/korg/motor). Samma klass: jäv där handläggaren är part (isolering utanför rollens krets).

**P8 — Externa frister med olika ankare och multi-milstolpar.** Roller 2,5,7,1,8,11,3. 72h från *kännedom*, NIS2 multi-milstolpe (24h→72h→30d), sub-dygn/skyndsamt (icke-numerisk), komplett-ansökan-ankare (PBL), avtalslivscykel (framtida datum), säsongsklump (1 mars). `fristPolicy{typ, ankare, milstolpar[], forlangningsbar, bindande:'lag'|'avtal'}`.

**P9 — PU-incident om/i Hubs själv (72h) — självrefererande blind fläck.** R5 äger, träffar alla. Verktyget som ska logga incidenten är självt drabbat → out-of-band-väg.

**P10 — Avsändar-/org-register måste täcka ALLA externa avsändare med rätt LOA.** Roller 1,2,4,5,8,9,10,11. Domstol/IVO/IMY/MSB/FK/Länsstyrelse/region(SITHS)/leverantörer/Säpo/banker — olika LOA-krav. `loaKrav` per avsändarkategori, brett från start.

**P11 — Multi-tenant: små kommuner slår ihop roller; SoR-produkt varierar.** Roller 2,4,7,8. `sorProdukt` per kund + per-tenant-konfigurerbara ACL-defaults och funktionsadresser.

**P12 — ACL-default är OMVÄND mellan domäner.** Roller 8,12 (omvänt mot 1–7), 1 (diariets dubbelriktning). Per-roll/per-ärendetyp konfigurerbar; `aclProfil` måste hantera *asymmetrisk* sekretess (delfältsmaskning) och *murar mellan verksamhetsgrenar*.

**P13 — Skyddade PU som tvärgående gate i ALLA aggregatvyer.** Alla 12. GAP-058 tre-lagers-ACL måste gälla även aggregeringslagret (ingen läcka via räknare/frist-färg). Distinkt från säkerhetsskydd (P1).

**P14 — Volym/last-asymmetri: registrator + KC + ÖF är hög-volym-triage med säsongstoppar.** Roller 1,4,12. Server-aggregaten måste tåla volym-toppar; frist-modellen skilja säsongsbetingad rödhet (mars) från verklig eskalering.

**P15 — Cross-role medmottagare + felmottaget + e-arkiv/FGS per verksamhet.** Roller 1,8,5/9,12. Sagan antar EN `frendsModul` per `hubsCaseId`. Utan `commitPlan[]`/multi-destination tappas ena anmälan eller akter slås ihop över sekretessgränser. E-arkiv = andra commit-destination i serie (`postCommitHook='arkivera_fgs'`, per-verksamhet).

**Kärnverdikt — de 15 punkterna kollapsar till fyra arkitektur-beslut FÖRE bygget:** (1) sagan behöver tre nya terminerande/icke-commit-utfall + abort-semantik i hooks; (2) `sekretessNiva` och `fristPolicy` måste bli strukturer ej skalärer; (3) `frendsModul` + ACL-default + SoR-produkt måste vara öppna, per-tenant-konfigurerbara axlar; (4) en PuB-/ändamåls-/regim-matris måste ligga i config från dag ett.

---

## 7. KONSEKVENSER + PRIORITERAD INTEGRATIONS-/BYGGPLAN (delta mot bygglistan)

Delta mot §7.2/§4-bygglistan i `HUBS-INTERNALS-ARENDEMOTOR.md`:

| Δ | Tillägg | Beror på / utökar | Risk |
|---|---|---|---|
| **Δ9** | `ArendeTyp`-config-raden: 7 nya fält (§2) | Utökar Δ1 (GAP-056) — *data*, ej motor | Låg — fält i befintlig tabell |
| **Δ10** | **Terminerande saga-utfall** `avvisad`/karantän + retroaktiv isolering | **Ny motor-semantik** — sagan måste *vägra föda* + kompensera bakåt | **Hög — enda genuina motor-utökningen; säkerhetskritisk** |
| **Δ11** | `InnehallsKlassService` `avvisa/isolera`-utfall | Utökar Δ2; fail-closed mot isolering på svaga signaler | Medel — sekretesskritisk tröskel |
| **Δ12** | Cross-role: `primarRoll` + `medmottagare[]` + multi-commit | Utökar dubbelmärkning (§4) + GAP-019 | Medel — flera commits, en saga |
| **Δ13** | Temporal ACL-degradering på extern händelse | Utökar GAP-058; post-commit-hook + bakåtverkan på `case:`-klustret | Hög — historik-läcka (roll 9) |
| **Δ14** | `aclProfil`-bibliotek (8 profiler) + Circles-mönster per profil + jäv-exkludering | Utökar GAP-058; Circles finns (rad 459) | Medel — koherenstest per profil |
| **Δ15** | `frendsMappning` ×N facksystem (Public360/W3D3/ByggR/Ecos/Heroma/Daedalos/e-Wärna/Artvise/CRM…) | **Multiplicerar GAP-019** | **Mycket hög — per-produkt-kod bakom config-id** |
| **Δ16** | `fristPolicy`-struct (multi-milstolpe, ankare, forlangningsbar, bindande) | Utökar GAP-018 frist-spegling | Medel — frist-motor-utökning |
| **Δ17** | PuB-/ändamåls-/regim-matris i config (`lagligGrund`, `gdpr_art9`, `vardgivarSpar`, `sekretessMur`) | Ny axel — juridisk förutsättning | Hög — utan den är systemet osäljbart |

**Tyngsta nya risken är Δ15, inte Δ9.** Rollserien avslöjar att `frendsModul` inte längre pekar på en handfull Treserva-moduler utan på **en hel marknad** — diariesystem (5+), facksystem per domän (ByggR, Ecos, Daedalos, Heroma, e-Wärna ×3, Magna Cura, Sekoia, Combine…), CRM (Artvise/Lime) och externa myndighets-e-tjänster (IMY, MSB, IVO). Config-raden *pekar* på mappningen (generiskt), men varje mappning är riktig integrationskod per produkt — **`TreservaCommitService` → `FacksystemCommitService`** där `systemOfRecord` + `frendsModul` väljer konnektorfamilj. Detta är 5–10× socialtjänstens GAP-019.

**Prioriterad ordning (sammanvägd med §4.3-roadmap):**
1. **P0-säkerhet:** Δ10 + Δ11 (terminerande utfall + `avvisa/isolera`) — säkerhetskritiskt, blockerar allt känsligt inflöde.
2. **P0-integration:** Δ15 startpunkt — generaliserad e-diarium/e-arkiv-konnektor (bär 9 roller) + Treserva/Lifecare som referensmall.
3. **P1:** Δ9 + Δ17 (config-fält + PuB-matris) · Δ13 + Δ16 (temporal ACL + frist-struct) · Δ12 (cross-role multi-commit) · Δ14 (aclProfil-bibliotek).
4. **P2:** Sokigo-kluster, HR, Överförmyndare, SIEM/IAM-läs, Ekonomi.

---

## 8. SLUTSATS

**ArendeTyp-modellen (EN motor + config-rad per typ + ortogonala flaggor) HÅLLER kommun-brett — men måste UTÖKAS, inte bara konfigureras.** Kärntesen bekräftas: skillnaderna mellan 12 roller är **data, inte kontrollflöde**, ryggraden R1–R9 är oförändrad i alla 12. Men:

1. **Config-raden var underspecificerad** → **7 nya fält** (`systemOfRecord`/`sorFallback`/`inflodeVia`, `sekretessGrund[]`+temporal, `riskKlass`, `externMyndighetMottagare`+`commitMode`, `verksamhetsgren`+`sekretessMur`, utökad `partsModell`+`joinNyckel`, `karantanKravs`). Sex är rena data; ett rör motorn.
2. **Sagan behöver ETT nytt utfall den helt saknar: "föds inte"** (avvisa/karantän för säkerhetsskydd + visselblåsning, terminerande och retroaktivt). Den enda genuina motor-utökningen — säkerhetskritisk.
3. **Cross-role-routing är ett mönster, inte ett kantfall** — `primarRoll + medmottagare[]` med **separata commits och separata ACL-kretsar** (akter slås aldrig ihop).
4. **Behörighetsmatrisen skalar via Circles + ett `aclProfil`-bibliotek** (8 namngivna profiler) — men ACL-defaulten måste bli **per-roll**, inte hårdkodad deny-by-default.
5. **De fyra brutna grundantagandena** (A1–A4) — facksystem finns alltid, sekretess är default, sekretessnivån statisk, sagan föder alltid — bryts alla kommun-brett. Att exponera och hantera dem, snarare än att låta Hubs tyst bli SoR genom passivitet, route:a säkerhetsskydd, eller blanda sekretessregimer — är breddningens kärna.

**En tvingande invariant:** varje config-rad MÅSTE ha icke-null `commitDestination` (facksystem · diarium · e-arkiv · extern_myndighet · triage_forward · karantan) — annars blir Hubs tyst de facto-SoR (skuggregister utan gallringsklocka), det farligaste utfallet. **Tydliga undantag:** säkerhetsskydd = utanför systemet (avvisa); visselblåsning = restricted (isolera, ej passera sagan); no-SoR = beslut krävs per kommun (aldrig genom passivitet).

---

**Grundande filer (absoluta sökvägar):**
- `C:/Users/fredrik.jonasson/Cursor/Nextcloud-vanilla/hubs_start/docs/ARENDETYPER-FLODESANALYS.md` (config-radens 18 fält §2.3; ryggrad R1–R9 §2.1; dubbelmärkning §4; klassningskaskad §5 — behöver `avvisa/isolera`-utfall)
- `C:/Users/fredrik.jonasson/Cursor/Nextcloud-vanilla/hubs_start/docs/HUBS-INTERNALS-ARENDEMOTOR.md` (saga med kompensering §1.2.3; matchningskaskad §2.3; single-writer §1.1.3; GAP-019 frendsMappning; GAP-056/057/058/060/063; Circles-synk rad 459; juristkonto/audit rad 460)
