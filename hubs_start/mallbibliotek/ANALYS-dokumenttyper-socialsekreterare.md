---
titel: Analys — dokumenttyper i socialsekreterarens dagliga arbete (barn & familj)
profession: Socialsekreterare – barn och familj (IFO)
syfte: Grundlig analys som mallbiblioteket bygger på
version: 1.0
datum: 2026-07-06
---

# Analys — vilka dokument använder en socialsekreterare (barn & familj) i sitt dagliga arbete?

Denna analys är underlaget till mallbiblioteket. Den går tillbaka till
grundkravställningen och härleder **vilka dokumenttyper** en socialsekreterare inom
barn och familj (IFO) faktiskt producerar, **i vilken ordning** och **på vilken
rättslig grund**. Varje dokumenttyp motsvaras av en mall i
[`socialsekreterare-barn-familj/`](socialsekreterare-barn-familj/).

## 1. Varför just denna profession först

Kravställningen pekar entydigt ut socialsekreteraren barn & familj som **primär
byggpersona** (K-1.13 i `docs/HUBS-KRAVSTALLNING-TOTAL.md`): "referensimplementationen
mot vilken motor, UI och Treserva-konnektorn först byggs. Övriga 11 = config-profiler."
Det är också den mest utvecklade vyn i Hubs Start
(`src/components/socialsekreterare/`) och den persona hela UX-specen är skriven mot.

Mallbiblioteket följer samma logik: bygg den djupast för barn & familj, låt den bli
mönstret för de övriga professionerna.

## 2. Källor i grundkravställningen

| Källa | Vad den ger analysen |
|---|---|
| `docs/ARENDETYPER-FLODESANALYS.md` §1.2, §3.2 | De 8 innehållskategorierna och per-kategori-logik; kat 1 (oros/skydd) = arketypen med skyddsbedömning + 14-dgr-förhandsbedömning + BBIC-struktur |
| `docs/SOCIALSEKRETERARE-WALKTHROUGH-V2.md` (43 steg) | Det konkreta dagsflödet: skyddsbedömning (Steg 10), beslut inleda inom 14 dgr (Steg 16), BBIC-mallar ur kunskapsbanken (Steg 25), samtycke (Steg 30), commit till Treserva (Steg 35), beslut+signering+delgivning (Steg 36–38), kommunicering/överklagandefrist (Steg 39) |
| `docs/HUBS-ARKITEKTUR-SOCIALTJANST.md` | Ärenderummet, ACL, hur handlingar committas |
| `docs/PERSONA-DASHBOARD-SPEC.md` (widget `kunskapsbank`, `mallarSamtycke`) | Att mallbiblioteket ska ligga i **Collectives** och lista "BBIC-/rehab-/granskningsmallar, gallringsplaner, samtyckesmallar"; namngivna mallar: samtyckesblankett, plan för återgång, SIP-samtycke, kallelser |
| `docs/HUBS-KRAVSTALLNING-TOTAL.md` §1.3 | K-1.13 primär persona; barnrätts- och sekretessprinciper |

## 3. Arbetsflödet (BBIC-livscykeln) och dokumenten per fas

Dokumenten följer barnavårdsärendets livscykel. Ordningen är den bärande strukturen i
mallbiblioteket (filernas löpnummer speglar den).

### Fas A — Aktualisering & förhandsbedömning
En orosanmälan kommer in. Enligt kat 1 i flödesanalysen "plockas anmälan, sagan föder
`hubsCaseId`, skyddsbedömningen sätts som pliktmarkör … 14-dgrs-förhandsbedömningen binds
till inkom-datum."

| # | Dokument | Funktion | Rättslig grund (verifiera) |
|---|---|---|---|
| 01 | **Mottagen orosanmälan** | Dokumentera inkommen anmälan om barn som far illa | Anmälan enligt SoL `[verifiera §; tidigare 14 kap. 1 §]` |
| 02 | **Omedelbar skyddsbedömning** | Bedöma om barnet behöver skydd *samma dag* | Skyddsbedömning enligt SoL `[verifiera §; tidigare 11 kap. 1 a §]` |
| 03 | **Förhandsbedömning + beslut att inleda/inte inleda utredning** | Ta ställning inom 14 dagar om utredning ska inledas | SoL `[verifiera §; tidigare 11 kap. 1 §]` |

### Fas B — Utredning (BBIC)
När utredning inletts öppnas "arbetslagret runt Treserva-ärendet: ärenderummet med ACL
och BBIC-mallar" (Walkthrough V2, Akt IV). BBIC-triangeln (barnets behov · föräldrarnas
förmåga · familj och miljö) strukturerar utredningen.

| # | Dokument | Funktion | Rättslig grund (verifiera) |
|---|---|---|---|
| 04 | **Utredningsplan (BBIC)** | Planera utredningens frågeställningar, kontakter och tid | SoL utredning + BBIC |
| 05 | **Barnavårdsutredning enligt 11 kap. SoL (BBIC)** | Själva utredningsdokumentet — huvudhandlingen | SoL `[verifiera §; tidigare 11 kap. 1 §]`; 4 mån utredningstid `[verifiera]` |
| 06 | **Journalanteckning / löpande dokumentation** | Dokumentera handläggning och åtgärder löpande | SoL dokumentationsskyldighet `[verifiera §; tidigare 11 kap. 5 §]`; SOSFS 2014:5 |
| 07 | **Samtals-/mötesanteckning** | Dokumentera samtal med barn, vårdnadshavare, nätverk | SOSFS 2014:5 |
| 08 | **Barnets inställning & delaktighet** | Säkerställa att barnet kommit till tals och att inställningen dokumenterats | Barnkonventionen art. 12 (lag 2018:1197) |

### Fas C — Samtycke, kommunikation & kallelser
Delad sekretess och samverkan kräver samtycke/menprövning (kat 5, samt Walkthrough V2
Steg 30 "Inhämta samtycke till mötet"). Widget `mallarSamtycke` listar dessa uttryckligen.

| # | Dokument | Funktion | Rättslig grund (verifiera) |
|---|---|---|---|
| 09 | **Samtycke till informationsinhämtning/samverkan** | Klientens samtycke till kontakt med skola, BUP, region m.fl. | OSL (2009:400) sekretessbrytande samtycke / menprövning |
| 10 | **Kallelse till möte/samtal** | Kalla vårdnadshavare/barn/nätverk | Förvaltningsrättslig ordning |
| 11 | **Begäran om uppgifter/handräckning från annan myndighet** | Inhämta underlag från annan myndighet | OSL uppgiftsskyldighet `[verifiera]` |

### Fas D — Planer
Vid vård/insats krävs vård- och genomförandeplan; vid samverkan kommun↔region en SIP.

| # | Dokument | Funktion | Rättslig grund (verifiera) |
|---|---|---|---|
| 12 | **Vårdplan** | Mål och innehåll för vård utanför hemmet / insats | SOSFS 2014:5 |
| 13 | **Genomförandeplan** | Hur en beviljad insats konkret ska genomföras | SOSFS 2014:5 |
| 14 | **Samordnad individuell plan (SIP)** | Samordna insatser kommun↔region | SoL + HSL (2017:30) `[verifiera §; tidigare SoL 2:7 / HSL 16:4]` |

### Fas E — Beslut & avslut
"Beslutet förbereds och läggs i signeringskön … Delge medborgaren säkert … Kommunicering
& överklagandefrist som bevakning" (Walkthrough V2 Steg 36–39).

| # | Dokument | Funktion | Rättslig grund (verifiera) |
|---|---|---|---|
| 15 | **Beslut om bistånd/insats (bifall/avslag)** | Myndighetsbeslut, delegation, motivering | SoL bistånd `[verifiera §; tidigare 4 kap. 1 §]`; delegationsordning |
| 16 | **Kommunicering inför beslut** | Ge part möjlighet att yttra sig innan beslut | FL (2017:900) 25 § |
| 17 | **Underrättelse/delgivning av beslut + överklagandehänvisning** | Meddela beslut och hur det överklagas | FL (2017:900) 33 §, 43–44 §§ |
| 18 | **Avslutning av utredning/ärende + gallrings-/arkivnotering** | Avsluta och hantera handlingarnas bevarande/gallring | SoL + arkivlag / dokumenthanteringsplan `[verifiera]` |

## 4. Angränsande dokument som medvetet lämnas till nästa omgång

För att hålla första omgången fokuserad och djup (endast barn & familj) utelämnas nu:
LVU-specifika handlingar (ansökan om vård, omedelbart omhändertagande, umgängesbegränsning),
familjerättens dokument (vårdnadsutredning, samarbetssamtal, yttrande till tingsrätt — kat 8,
egen enhet), ekonomiskt bistånd (kat 3, egen dokumentmängd), samt de 11 övriga
professionernas mallar. Dessa byggs enligt samma mönster i kommande omgångar.

## 5. Genomgående principer i alla mallar

1. **Barnrättsperspektiv** — barnets bästa och barnets rätt att komma till tals genomsyrar
   mallarna (egen mall för barnets inställning, avsnitt i skyddsbedömning/utredning).
2. **Sekretess & dataminimering** — mallarna påminner om menprövning vid informationsdelning
   och om att bara nödvändiga uppgifter dokumenteras. PII visas bara för behörig handläggare.
3. **Handling vs arbetsmaterial** — varje mall anger handlingsstatus; commit till facksystem
   (Treserva) är det som gör arbetsmaterial till en förvarad allmän handling
   (jfr "Gör detta till en handling" → CommitGrind, Walkthrough V2 Steg 15/35).
4. **Lagrum måste verifieras** — se README och `MALL-STANDARD.md` §5: SoL (2001:453) →
   SoL (2025:400) har ändrad numrering. Mallarna namnger institutionen och flaggar §.

## 6. Koppling till produkten

Mallbiblioteket är innehållet i **Collectives**-widgeten `kunskapsbank` ("Kunskapsbank &
mallar", `docs/PERSONA-DASHBOARD-SPEC.md`). Handläggaren öppnar biblioteket från
ärenderummets fot (Zon 5), väljer mall och instansierar den in i ärenderummets Groupfolder
(Walkthrough V2 Steg 25). Importskriptet i [`scripts/`](scripts/) skapar Collective:t och
sidträdet automatiskt.
