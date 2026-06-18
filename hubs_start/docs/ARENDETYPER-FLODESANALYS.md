<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Ärendetyper & flödesanalys — ett generiskt ärende-flöde eller åtta separata?

**Intern arkitektur-/utvecklartext.** Syntes av fyra tekniska PM, verifierad mot körande kod i `hubs-code/sdkmc/sdkmc-main` och designdokumenten i `hubs_start/docs`. Prövar hypotesen: **ETT** generiskt ärende-flöde parameteriserat per ärendetyp **+** cross-cutting flaggor — **inte** åtta separata kodflöden, **inte** ett odifferentierat flöde.

**Ärlighetsmarkörer genomgående:**
`[FINNS]` = verifierad, körande kod läst i sdkmc/hubs_start idag.
`[BYGGS]` = designat, ingen kod existerar.
`[KONFIG]` = befintlig app/mekanism, kräver deklarativ konfiguration (Flow, ACL, IMAP, schema).

> **Terminologi & varumärke:** Facksystem = Treserva/Lifecare/Viva (system of record, slutlagring). Hubs = mellanlagring. Tekniska app-namn (Talk/Tables/Deck) används fritt i denna utvecklartext; i UI-citat aldrig leverantörsnamn. Funktionsadresser (`barn-familj@`, `ekonomi@`, `familjeratt@`) är routing-mål, inte personliga korgar.

---

## 0. Sammanfattning + VERDIKT

**VERDIKT i en mening:** Det är **ETT generiskt ärende-flöde** — en motor, en saga — **parameteriserat av en datadriven `ArendeTyp`-registry (en config-rad per ärendetyp) plus ortogonala cross-cutting flaggor**, med ~3 deklarerade hooks för de kategorier (6, 8, delvis 5) som närmar sig eget delflöde; **inte** åtta separata kodflöden, **inte** ett odifferentierat flöde.

De 8 verksamhetskategorierna delar **en och samma motor och en och samma saga**. Skillnaderna mellan dem är **inte kontrollflöde — de är data.** Varje variationsaxel (routing, första åtgärd, frist, ACL, retention, facksystem-modul) besvaras genom att *läsa ett fält ur en config-rad*, inte genom `if (kategori === 6)`. Att lägga till en nionde ärendetyp = lägga till en config-rad, inte skriva, testa och deploya ny PHP.

**Verdikt-formel:** **en motor · en saga · N config-rader · ~3 deklarerade hooks · M cross-cutting flaggor.**

**Var hypotesen böjs (inte bryts):** ren config räcker för kat 1–5 och 7. Två kategorier kräver en *deklarerad pre/post-hook* utöver config-fälten — men fortfarande inom samma motor:
- **Kat 6 (rättsliga/tvång, LVU/LVM)** vänder ordningen: formell diarieföring *direkt*, ingen förhandsbedömning-grind; domstols-/överklagandefrist speglad ur facksystemet; nämnd/delegat/jurist-routing → `preSagaHook='diariefor_direkt'`.
- **Kat 8 (familjerätt)** är gränsfallet: egen partsmodell (två föräldrar som ofta är motparter, ej "barnRef-som-klient"), egen avsändar-/sekretessprofil, tingsrätts-livscykel → `partsModell='flerpartsärende'` + `postCommitHook='familjeratt_yttrande'`.

Ingen av de åtta når den sista nivån (eget kodflöde). Den invarianta ryggraden (R1–R9, §2) är oförändrad i alla åtta fall.

---

## 1. Frågan & grundprincipen + de 8 kategorierna

### 1.1 Grundprincipen — kategorisera efter FÖRSTA ÅTGÄRD, inte efter kanal

Det finns två helt olika klassningsfrågor som inte får blandas ihop:

- **KANAL** (*hur kom det in?*) — redan löst deterministiskt i koden. `MessageTypeService::getMessageTypeFromEmail()` `[FINNS]` mappar adress-suffix → **5 kanaltyper** (`sdk_message`, `fax_message`, `sms_message`, `internal_message`, `secure_email`), plus IMAP-headers `X-Sdk`/`X-MessageType`. Noll tolkning, fail-closed (okänt suffix till funktionsadress → `throw Exception`). **Ingen LLM.**
- **INNEHÅLL / FÖRSTA ÅTGÄRD** (*vad ska hända, och i vilken facksystem-modul hamnar det?*) — detta är de 8 verksamhetskategorierna. De är ett **klassningslager OVANPÅ** de 5 kanaltyperna och **finns inte i koden** — `[BYGGS]`.

Principen är att de 8 kategorierna definieras av **första handläggningsåtgärd** (skyddsbedömning vs behovsbedömning vs registrering vs koppla-till-befintligt), eftersom det är första åtgärd — inte kanalen — som avgör process-mall, mål-enhet, frist-policy, ACL och vilken Treserva-modul commiten landar i.

### 1.2 De 8 innehållskategorierna (kort)

| # | Kategori | Arketypisk första åtgärd | Facksystem-modul |
|---|---|---|---|
| **1** | Orosanmälan & akut skydd | Skyddsbedömning (11 kap. 1 a § SoL) → förhandsbedömning | IFO barn & familj |
| **2** | Ansökan/begäran om insats | Behovsbedömning + behörighetskontroll (god man/ombud) | ÄO · LSS · IFO vuxen · ek. bistånd · barn (per insats) |
| **3** | Ekonomi / försörjning / boende | Ekonomiskt bistånd-prövning (hög volym, repetitivt) | Ekonomiskt bistånd |
| **4** | Komplettering i pågående ärende | Koppla till pågående — startar **aldrig** nytt ärende | Ärver värd-ärendets modul |
| **5** | Vård/omsorg/samverkan (kommun↔region) | Tidskritisk koordinering (SIP, utskrivning) | ÄO · LSS · IFO vuxen |
| **6** | Rättsliga / tvång / brott (LVU/LVM) | Formell diarieföring **direkt** + frist-bevakning | IFO barn/vuxen + nämnd-/delegationsspår |
| **7** | Verkställighet / placering / uppföljning | Följa upp beslutad insats — alltid attach | Samma modul som beslutet |
| **8** | Familjerätt & relationsrätt | Familjerättslig utredning / yttrande / avtal | Familjerätts-modul (egen) |

**Två ortogonala axlar (avgörande):** **Axel C ärendekoppling** (nytt→band 1a / hör_till→1b / ej_kopplat→1c) styr *var raden visas* i UI. **Innehållskategorin** är en *annan* axel som styr *parametrarna* (första-åtgärd, frist, ACL, facksystem). De är korrelerade men ortogonala: kat 4 & 7 är i praktiken alltid `hör_till` (1b); kat 1 & 2 oftast `nytt` (1a) — men en orosanmälan KAN röra ett barn med pågående ärende → `hör_till`. Banden ändras aldrig av kategorin.

---

## 2. Den gemensamma ryggraden vs variationsaxlarna + `ArendeTyp`-mönstret

### 2.1 Ryggraden — det invarianta som ALLA 8 delar

Detta är **`ArendeService`-sagan oförändrad** (`HUBS-INTERNALS-ARENDEMOTOR.md` §1.2.3). Varje kategori, utan undantag, passerar exakt dessa led. Det är detta som gör att *en* motor är möjlig.

| # | Invariant ryggrads-led | Var det bor | Status |
|---|---|---|---|
| **R1** | **`hubsCaseId`-identitet** (UUID v4) — enda joinnyckeln, född i registret, lever utan dnr | `ArendeService` mintar; `hubs_arenden` PK | `[BYGGS]` |
| **R2** | **Register-rad** i `hubs_arenden` (Tables), single writer = sdkmc | rad-shape §1.1.2 | `[BYGGS]` |
| **R3** | **`case:{hubsCaseId}`-tagg propagerad** över meddelande/fil/Deck/Talk/kalender | `ItslTagService->tagMessage()` (meddelande) + `TagFileController` (fil) | `[FINNS]` (bärare) / `[BYGGS]` (propagering) |
| **R4** | **Ärenderum + ACL** (Groupfolder, least-permission, tre-lagers-koherens) | groupfolders-API + Flow Automated Tagging | `[BYGGS]` |
| **R5** | **Spreed-rum** (ALL chatt här, aldrig i dashboarden) — bundet av `talkToken`-pekare | `TalkController` `[FINNS]`; rum-skapande `[BYGGS]` | blandat |
| **R6** | **Provenans** — `conversationId` som ankare, 14-dgr-klockan startar på *inkom-datum* ej `now()` | `MessageThread` (conversationId) `[FINNS]` | blandat |
| **R7** | **Frends-commit-mönster** — `commitToTreserva({hubsCaseId, payload})` → verifierad callback `{hubsCaseId, dnr}` | `TreservaCommitService` | `[BYGGS]` |
| **R8** | **Retention på verifierad commit** — `retentionState='gallras_efter_commit'` sätts *enbart* av callbacken, aldrig av kryssruta (GAP-007) | `MailboxRetentionService`/`ExpungeJob` `[FINNS]`; bindningen `[BYGGS]` | blandat |
| **R9** | **Kvittens** — `MessageReceipt` + provenans-flip + dnr-alias-tagg | `MessageReceipt(Controller)` `[FINNS]` | blandat |

**Slutsats R:** ryggraden är **kanal-agnostisk och kategori-agnostisk**. Den vet inget om "orosanmälan" vs "familjerätt" — den vet bara *skapa identitet → rum → tagg → klocka → commit → gallra → kvittera*. De 5 kanaltyperna sitter *under* ryggraden; de 8 innehållstyperna sitter *ovanpå* som klassningslager — ingen ändrar leden R1–R9.

### 2.2 Variationsaxlarna — vad som SKILJER (varje axel är en parameter, inte en gren)

| Axel | Vad som varierar | Realiseras som (config-fält) |
|---|---|---|
| **(a) Routing / mål-enhet** | vilken enhet/funktionsadress äger ärendet | `defaultEnhet` + funktionsadress-mappning (`ConsolidateMailboxesService` `[FINNS]`) |
| **(b) Första åtgärd** | skyddsbedömning vs behovsbedömning vs registrering vs koppla | `forstaAtgard`-enum → mall + `pliktGrind` |
| **(c) Nytt vs ATTACH** | skapar saga nytt `hubsCaseId` eller hänger på befintligt | `kopplingDefault` = Axel C-prior |
| **(d) Frist-policy** | vilken klocka, om någon | `fristPolicy{typ, ankare, speglasUrTreserva}` |
| **(e) Sekretess/ACL-profil** | inre sekretess — vilka roller får se | `aclProfil`-id + `sekretessNiva` → ACL-mall vid R4 |
| **(f) Diarieföring-timing** | förhandsbedömning *först* vs formell diarieföring *direkt* | `diariePlikt`-enum (`forhandsbedomning_forst` \| `direkt`) |
| **(g) Retention / handlingstyp** | gallringsfrist + handlingstyp ur DHP | `dhpHandlingstyp` + `retentionMall` |
| **(h) Facksystem-modul** | vilken Treserva-modul commiten landar i | `frendsModul` + `frendsMappning`-id |
| **(i) Cross-cutting flaggor** | akut / våld / skyddade-PU / barn-berörs | `flaggor[]` på *instansen*, **inte** på typen (§4) |

**Kritiskt om axel (i):** flaggorna är **inte** en kategori-skillnad. En kat 7-månadsrapport som signalerar akut fara dubbelmärks kat 7 + kat 1. Om "akut" vore en del av ärendetypen skulle dubbelmärkning kräva en ny kategori-kombination — kombinatorisk explosion. Genom att hålla flaggorna **ortogonala mot `ArendeTyp`** undviks det. Därför specificerar hypotesen "parameteriserad per ärendetyp **+** cross-cutting flaggor" som två separata mekanismer.

### 2.3 `ArendeTyp`-mönstret — datadriven config, inte kod-fork

**Påståendet:** varje ärendetyp är en **rad i en registry-tabell (`hubs_arende_typ`), inte en klass i koden.** Den generiska sagan läser raden och parameteriseras av den. Detta är symmetriskt med det som redan FINNS: `MessageTypeService` är en deterministisk, datadriven tabell-mappning (adress-suffix → kanaltyp). `ArendeTyp`-registryn är exakt samma mönster ett lager upp.

**Process-mallens shape (config-radens fält) `[BYGGS]`:**

```
ArendeTyp (process-mall) — en rad i hubs_arende_typ
─────────────────────────────────────────────────────────────────
arendeTypId        text     — 'orosanmalan' | 'ansokan_bistand' | ...  (PK, 8 seed-rader)
displayName        text     — UI-etikett (aldrig leverantörsnamn)
kanalHint          text[]   — vilka av de 5 kanaltyperna som typiskt bär denna

# AXEL a — routing
defaultEnhet       text     — ägande funktionsadress ('barn-familj@')
funktionsadress    text     — svars-/avsändaradress

# AXEL b — första åtgärd
forstaAtgard       enum     — skyddsbedomning | behovsbedomning | registrering | koppla_befintligt
forstaAtgardMall   text     — id på noterings-/utredningsmall som öppnas
pliktGrind         bool     — måste första åtgärd kvitteras innan steppern flyttas? (kat 1 = true)

# AXEL c — koppling
kopplingDefault    enum     — nytt | hor_till | ej_kopplat   (kat 4,7 = hor_till; kat 1,2 = nytt)

# AXEL d — frist
fristPolicy        obj      — { typ: '14d_forhandsbedomning'|'4man_utredning'|'domstol'|'koordinering'|'ingen',
                                ankare: 'inkom_datum'|'beslut_datum'|'speglad_treserva',
                                speglasUrTreserva: bool }                # GAP-002/018

# AXEL e — ACL/sekretess
aclProfil          text     — id på ACL-mall (snäv/normal/bred) → R4
sekretessNiva      enum     — hog | normal

# AXEL f — diarieföring
diariePlikt        enum     — forhandsbedomning_forst | direkt   (kat 6,8 = direkt)

# AXEL g — retention
dhpHandlingstyp    text     — handlingstyp ur dokumenthanteringsplanen
retentionMall      text     — gallringsfrist-id (bunden till verifierad commit, R8)

# AXEL h — facksystem
frendsModul        enum     — ifo_barn | ifo_vuxen | ao | lss | ek_bistand | familjeratt
frendsMappning     text     — payload-mappnings-id (per-modul-fältschema)

# HOOKS (det som gör kat 5/6/8 möjliga utan fork — §2.5)
preSagaHook        text|null — id på deklarerad pre-step (null = ingen)
postCommitHook     text|null — id på deklarerad post-step (null = ingen)

# partsmodell (för kat 8)
partsModell        enum     — enskild_klient | flerpartsärende   (kat 8 = flerpart)
```

**Cross-cutting flaggorna lever INTE här** — de sitter på *ärendeinstansen* (`hubs_arenden.flaggor[]`), eftersom de är ortogonala (§4).

### 2.4 Varför detta är rätt

1. **Underhåll.** En motor att testa, inte åtta. Sagans kompenseringslogik (GAP-057) skrivs och verifieras *en* gång. Åtta kodflöden = åtta kompenseringskedjor som kan driva isär.
2. **Ny ärendetyp = ny config-rad.** SoL ändras (nya SoL skiljer redan insatser *utan* vs *med* individuell behovsprövning) → det blir två `forstaAtgard`-värden på två rader, inte en kodändring. En kommun som vill ha en nionde kategori seedar en rad.
3. **Juridisk spårbarhet.** Registry-raden *är* dokumentationen av handläggningsregeln ("14-dagarsfrist från inkom-datum, snäv ACL, IFO-barn-modul, gallras enligt DHP-handlingstyp X") — reviderbart i en tabell, versionerbart, granskningsbart av kommunjurist, i stället för utspritt i `if`-satser.
4. **Symmetri med det som FINNS.** Vi bygger med kornet (deterministisk config-mappning à la `MessageTypeService`), inte mot det.
5. **Per-kund utan kodändring.** Matchningsvikter/modulmappningar kan ligga som Windmill-config medan kärnsagan förblir hårdkodad i sdkmc för transaktionsgaranti.

### 2.5 Hur en config-rad driver sagan

| Saga-steg (R) | Generiskt led | Parameter ur config-raden |
|---|---|---|
| R1 mint | alltid samma | — |
| R2 register-rad | alltid samma | `defaultEnhet`, `kopplingDefault`, `flaggor[]` (från instans) |
| R3 case-tagg | alltid samma | — |
| R4 rum + ACL | samma mekanism | `aclProfil`, `sekretessNiva` |
| R5 Spreed-rum | alltid samma | deltagare = ACL-krets ur `aclProfil` |
| R6 klocka | samma mekanism | `fristPolicy` (typ+ankare) |
| — första åtgärd | mall öppnas | `forstaAtgard`, `forstaAtgardMall`, `pliktGrind` |
| R7 Frends-commit | samma mekanism | `frendsModul`, `frendsMappning` |
| R8 retention | alltid bunden till callback | `retentionMall`, `dhpHandlingstyp` |
| R9 kvittens | alltid samma | — |
| pre/post | **deklarerad hook** | `preSagaHook`, `postCommitHook` |

Samma tio led för alla åtta. Skillnaden är vilka *strängvärden* sagan läser. Det är definitionen av datadriven.

---

## 3. Per-kategori-matrisen + handläggningslogik + gemensamt-vs-unikt

> **Läsanvisning.** Kategorin styr *vad som händer* (process-mall, mål-enhet, frist, retention); Axel C-bandet styr *var raden visas*. `[BYGGS]` = kategori-lagret finns inte i koden; `[FINNS]` = byggsten verifierad i sdkmc.

### 3.1 Matrisen — 8 kategorier × 9 variationsdimensioner

| # · Kategori | Första åtgärd | Mål-enhet | Nytt vs attach (band) | Frist-policy | ACL-profil (inre sekretess) | Diarieföring | Retention (DHP) | Facksystem-modul | Typiska flaggor |
|---|---|---|---|---|---|---|---|---|---|
| **1. Oros/skydd** | Skyddsbedömning som **pliktmarkör** → förhandsbedömning | `barn-familj@` (mottagning) | Oftast **NYTT→1a**; `hör_till`→1b vid recidiv | **14 dgr** bunden till **inkom-datum** | **Mycket hög** (anmälare kan vara skyddad, 26 kap.) deny-by-default | Förhandsbedömning **före** diarieföring | Ej-utredning → gallras enl. DHP; utredning → barnakt, bevaras | **IFO barn** (BBIC) | `akut_fara`, `barn_berörs`, `våld/hot`, `skyddade_pu` |
| **2. Ansökan/insats** | Behovsbedömning + behörighet (god man/ombud); **kräver sub-klass** | **Beror på insats**: `aldreomsorg@`·`lss@`·`vuxen@`·`ekb@`·`barn-familj@` | Oftast **NYTT→1a**; `hör_till` vid förnyad ansökan | Förvaltningsrättslig skyndsamhet; speglas ur Treserva | Hög; behörighetskontroll central | Diarieförs vid ärendestart | Insatsberoende akttyp; bevaras enl. modulens DHP | **Per insats**: ÄO·LSS·vuxen·ek.bistånd·barn | `barn_berörs`, `god_man/ombud`, `samtycke_saknas` |
| **3. Ekonomi/boende** | Ekonomiskt bistånd-prövning; akut boende → omedelbart | `ekb@` | **Hög andel `hör_till`→1b** (månadsärende) | Akut boende/avhysning = extern frist; övrigt = månadscykel | Medel; hög volym → ACL på enhetsnivå; tredjepart maskeras | Diarieförs per ärende; löpande aktbildning | Ek.bistånd-akt (ofta kortare frist); beslut bevaras | **Ekonomiskt bistånd** | `akut_boende`, `avhysning`, `barn_berörs`, `våld` |
| **4. Komplettering** | **Lägg till i pågående** + relevans-/sekretessprövning; **aldrig nytt** | **Ärver** från matchat ärende | **ALLTID ATTACH→1b** | **Ärver** ärendets frist | Ärver matchat ärendes ACL; felkoppling = sekretessincident (spegla **vid bekräftelse**, GAP-043/060) | Tillförs befintligt dnr | Ärver ärendets retention | **Samma modul** som värd-ärendet | `relevans_oklar`, `skyddade_pu` (ärvd), `barn_berörs` (ärvd) |
| **5. Vård/samverkan** | **Tidskritisk koordinering** (SIP/utskrivning) — kalender, **ej frist** | `aldreomsorg@`·`lss@`·`vuxen@` | Oftast `hör_till`→1b; NYTT→1a vid första region-kontakt | **Koordinerings-deadline ≠ myndighetsfrist** (CalDAV/SIP, ej FristChip) | Hög; **delad sekretess kommun↔region** (samtycke/menprövning); extern part scoped i rummet | Diarieförs i omsorgsärendet; SIP-dok som handling | Omsorgsakt (ÄO/LSS); bevaras enl. DHP | **ÄO·LSS·IFO vuxen** | `tidskritisk`, `utskrivningsklar`, `SIP`, `samtycke_informationsdelning` |
| **6. Rättsligt/tvång** | **FORMELL diarieföring DIREKT** (allmän handling, OSL 5:1) + frist-bevakning + jurist/nämnd | Enhet **+ jurist/nämndsekreterare/delegat** | **NYTT (1a)** eller `hör_till` (1b) (yttrande i pågående) | **HÅRDA frister** (domstol/inställelse, LVU-omprövning 6 mån, LVM); T-7/T-3/T-0 | **Hög sekretess MEN handlingen är allmän direkt**; partsinsyn vs sekretess (10 kap.) | **Omedelbar** formell diarieföring — **omvänd ordning** mot kat 1 | Bevaras (rättsprocess); LVU/LVM-akt, lång tid | **IFO barn/vuxen** + nämnd-/delegationsspår (ev. egen dnr-serie) | `tvång`, `domstolsfrist`, `LVU/LVM`, `offentligt_biträde`, `barn_berörs` |
| **7. Verkställighet** | **Följa upp** beslut; **DUBBELMÄRKNING:** akut fara → **även kat 1** | **Ärver** → ansvarig utredare/uppföljning | **ALLTID ATTACH→1b** | Uppföljnings-/omprövningsintervall (`tidsbegransat`) | Ärver ärendets ACL; utförare/HVB/familjehem = **extern part** scoped | Tillförs befintligt dnr (uppföljningshandling) | Ärver ärendets handlingstyp | **Samma modul** som beslutet | `dubbelmärkt→kat1`, `akut_fara`, `placering`, `avvikelse/risk` |
| **8. Familjerätt** | Familjerättslig utredning/yttrande/avtal — **egen process, egna avsändare** | **Familjerättsenheten** (`familjeratt@`) | **NYTT (1a)** eller `hör_till` (1b) (yttrande i vårdnadsmål) | **Domstolsfrister** (yttrande till tingsrätt); speglas | **Egen profil; parterna ofta motparter** → strikt partsåtskillnad i ACL | Diarieförs i familjerättens egen ärendeserie | Familjerätts-akt; bevaras enl. egen DHP-sektion | **Familjerätts-modul** (egen) | `domstolsfrist`, `vårdnadstvist`, `partsmotsättning`, `barn_berörs` (alltid) |

### 3.2 Per-kategori handläggningslogik — [RYGGRAD] vs [SÄRLOGIK]

Notationen delar varje kategori i **[RYGGRAD]** (delar saga-flödet/matchningskaskaden/commit oförändrat) och **[SÄRLOGIK]** (kräver `ArendeTyp`-config eller cross-cutting-flagga). Detta är beviset för hypotesen.

**Kat 1 — Orosanmälan & akut skydd.** Den arketypiska "skapa ärende"-vägen: anmälan plockas i band 1a, sagan föder `hubsCaseId`, skyddsbedömningen sätts som pliktmarkör som blockerar stepper-flytt (fas-spärr, GAP-001). 14-dgrs-förhandsbedömningen binds till inkom-datum, inte plock. Syskon-fall → **ett `hubsCaseId` per barn** med delad `conversationId`.
- **[RYGGRAD]** Hela sagan steg 1–10; matchningskaskaden (case-tagg→conversationId→SSN); Frends-commit.
- **[SÄRLOGIK]** `ArendeTyp:'orosanmalan'` injicerar skyddsbedömnings-plikten, 14-dgr-klockan, BBIC-struktur, starkaste ACL-defaulten. **Ren config.**

**Kat 2 — Ansökan/insats.** Skiljer sig i **routing-osäkerheten** — kategorin är inte komplett förrän en **sub-klassificering** av insatstypen valts (ÄO/LSS/vuxen/ekb/barn), eftersom det avgör mål-enhet, modul OCH om nya SoL:s väg "utan individuell behovsprövning" gäller. Behörighetskontroll (god man/ombud/samtycke) är första-åtgärd före behovsbedömning.
- **[RYGGRAD]** Samma skapande-saga; samma register-shape; samma commit.
- **[SÄRLOGIK]** En **routing-/sub-typ-väljare** (parameter → 5 mål-enheter, 5 moduler). "Utan vs med behovsprövning" = en mall-variant, inte eget flöde. **Ren config** (med sub-nyckel).

**Kat 3 — Ekonomi/boende.** **Hög volym, repetitivt** — domineras av `hör_till` (band 1b): inkommande underlag (FK-beslut, hyresavi, KFM) faller löpande in i ett pågående månadsärende. Normalfallet är inte skapande utan **påfyllnad**. Undantaget akut boende/avhysning bär en *faktisk* extern frist (KFM/hyresvärd).
- **[RYGGRAD]** Matchningskaskaden och tagg-/koppla-vägen är befintlig; commit till ek.bistånd-modulen via samma kontrakt.
- **[SÄRLOGIK]** Default-band lutar mot `hör_till`; en **akut-frist-undertyp** (`akut_boende`/`avhysning`). Volymen är ett *prestanda*-argument *för* en motor. **Ren config.**

**Kat 4 — Komplettering i pågående.** **Inget eget skapande-flöde alls.** Per definition band 1b: kategorin *är* matchningskaskaden + relevans-/sekretessprövning + koppla. Det enda som händer är `ItslTagService->tagMessage(case:{id})` + append `conversationId` + spegla bilaga till rätt rum **vid bekräftelse**. Felkoppling är en sekretessincident (GAP-060).
- **[RYGGRAD]** Ärver allt (mål-enhet, frist, ACL, modul, retention) från värd-ärendet. Enbart befintliga `[FINNS]`-byggstenar.
- **[SÄRLOGIK]** Nästan ingen — routning av bilaga till rätt ärenderum + relevansgrind. **Beviset** att kat 4 inte motiverar eget kodflöde — det *är* attach-vägen. **Ren config.**

**Kat 5 — Vård/samverkan.** Tidskritisk men på ett **kalendersätt**, inte ett frist-bevaknings-sätt: utskrivningsplanering/SIP/hemgång hanteras som CalDAV-händelse + Spreed-koordinering, inte en röd FristChip. Bär **delad sekretess** kommun↔region som kräver samtycke/menprövning — en ACL-dimension de andra saknar.
- **[RYGGRAD]** Skapande-/attach-sagan och commit oförändrade; ärenderum + Spreed + kalender är sagans steg 4–7.
- **[SÄRLOGIK]** `kopplingDefault='hor_till'` + `fristPolicy.typ='koordinering'` (prioriterar i UI, triggar *ingen* retention/diarieföring). ACL-profilen släpper in **extern samverkanspart** scoped. **Ren config** — tidskritiken *ser* ut som en frist men hanteras som en annan `fristPolicy.typ`.

**Kat 6 — Rättsligt/tvång.** **Vänder ordningen.** Där kat 1 gör förhandsbedömning *före* diarieföring kräver kat 6 **omedelbar formell diarieföring** (handlingen är allmän direkt, OSL 5:1) parallellt med hårda externa frister (domstol, LVU-omprövning, LVM). Engagerar jurist/nämnd/delegat. LVU/LVM är särskild handläggning ovanpå.
- **[RYGGRAD]** Registret, `case:`-taggen, frist-speglingen och Frends-commit är samma maskineri; T-7/T-3/T-0 är samma påminnelse-motor.
- **[SÄRLOGIK]** `diariePlikt='direkt'` + `preSagaHook='diariefor_direkt'` (kör formell registrering som led 0 före R2), `fristPolicy.typ='domstol'` med `speglasUrTreserva=true`, extra aktörsroller i ACL, ev. egen dnr-serie. **Config + en deklarerad pre-hook.** Sagans kompensering är oförändrad.

**Kat 7 — Verkställighet.** Som kat 4 ett **attach-flöde** (alltid `hör_till`), men med en unik twist: **dubbelmärkning**. En månadsrapport från HVB/familjehem är kat 7, men signalerar den akut fara triggar den **även** kat 1:s skyddsväg — en rad bär två kategorier samtidigt och startar en parallell skydds-saga utan att lämna uppföljningsärendet.
- **[RYGGRAD]** Attach-vägen (tagg + koppla + spegla) och uppföljnings-FristChip (`tidsbegransat`) är befintliga.
- **[SÄRLOGIK]** **Dubbelmärknings-regeln** — en cross-cutting-flagga (`akut_fara`) på en kat-7-rad förgrenar till en kat-1-skyddsbedömning utan att ändra kat-7-tillhörigheten. Plus extern-part-ACL. Dubbelmärkningen är det starkaste argumentet för att kategori är en **flagg-/etikett-axel**, inte en exklusiv switch. **Ren config + flaggor.**

**Kat 8 — Familjerätt.** Den **mest fristående** kategorin: egen enhet, egna avsändare (tingsrätt/föräldrar/ombud), egen process (vårdnadsutredning, yttrande, avtal, samarbetssamtal) och **egen facksystem-modul**. "Klienten" är inte *ett barn* (`barnRef`) utan ett *flerpartsförhållande*; parterna är ofta motparter (vårdnadstvist) → strikt partsåtskillnad i ACL som ingen annan kategori har.
- **[RYGGRAD]** Token-modellen, `case:`-taggen, register-pekarna, Spreed-rum och commit-mönstret gäller fullt ut.
- **[SÄRLOGIK]** `partsModell='flerpartsärende'` + `aclProfil='familjeratt_inre_sekretess'` + `postCommitHook='familjeratt_yttrande'`. `barnRef` blir pseudonym för *ärendet*. **Config + partsmodell-flagga + en post-hook.** Gränsfallet — men kostnaden (egen kompenseringskedja) överstiger nyttan så länge partsmodellen kan uttryckas som ett fält.

### 3.3 Syntes — gemensamt vs unikt

**GEMENSAMT (ryggraden alla 8 delar — oförändrad kod):** kanonisk `hubsCaseId` + identitets-trippeln; den atomära skapande-sagan med kompensering; `case:`-tagg-propagering via `ItslTagService` `[FINNS]`; matchningskaskaden (case-tagg→conversationId→SSN→konfidens); register-rad-shape; ärenderum+ACL+Deck+Spreed+kalender; Frends-commit + dnr-paring + commit-bunden retention; reconciliation; all chatt i Spreed; hubs_start lagrar inget. **Ingen kategori behöver en egen motor.**

**UNIKT (kräver `ArendeTyp`-config eller cross-cutting-flagga, INTE eget kodflöde):**

| Variationsaxel | Vilka kategorier driver särlogiken |
|---|---|
| **Routing/modul som parameter** | Kat 2 (5-vägs sub-typ), kat 5 (ÄO/LSS/vuxen), kat 8 (egen modul) |
| **Skapande vs attach** | Kat 1/2 skapande (1a); **kat 4 & 7 rent attach** (1b); kat 3 lutar attach |
| **Frist-policy** | Kat 1 (14-dgr ur inkom-datum); kat 6 & 8 (hårda domstolsfrister); kat 5 (kalender ej frist); kat 3 (extern akut-frist); kat 4/7 (ärver) |
| **Registrerings-ORDNING** | **Kat 6 vänder ordningen** (diarieför direkt) vs kat 1 (förhandsbedömning först) |
| **ACL-profil** | Kat 1 (starkast), kat 5 (delad kommun↔region), kat 6 (extra aktörer), kat 8 (partsåtskillnad), kat 4/7 (ärvd + extern part) |
| **Cross-cutting-förgrening** | **Kat 7 dubbelmärkning→kat 1** — beviset att kategori är etikett-/flagg-axel |

**Tre nyanser:** (1) Kat 4 & 7 är inte skapande-flöden utan **rena attach-vägar** som redan bärs av befintlig tagg-/matchnings-kod — minst ny kod. (2) Kat 6 är den enda som **kastar om sekvensen** (diarieföring före ärenderum) → kräver tidig `provenanceState` + pre-hook. (3) Kat 8 är den bredaste parametriseringen (egen modul/stepper/avsändare/partsåtskillnad) men ryms ändå som ett `ArendeTyp`-kluster på samma motor.

| Kategori | Ren config? | Hook krävs? | Närmar sig delflöde? |
|---|---|---|---|
| 1 Oros/skydd | ✅ | — | nej |
| 2 Ansökan/bistånd | ✅ | — | nej |
| 3 Ekonomi | ✅ | — | nej |
| 4 Komplettering | ✅ | — | nej |
| 5 Vård/samverkan | ✅ | — | nej (tidskritik = annan `fristPolicy.typ`) |
| 6 Rättsligt/tvång | ⚠️ | pre-saga-hook (`diariefor_direkt`) | delvis — direkt-diarieföring + domstolsfrist |
| 7 Verkställighet | ✅ | — | nej (dubbelmärkning = flaggor) |
| 8 Familjerätt | ⚠️ | partsmodell + post-hook | **mest** — egen partsmodell, gränsfallet |

---

## 4. Dubbelmärkning & cross-cutting flaggor

### 4.1 Modellen: EN primär kategori + ett SET oberoende flaggor

Kategorierna är **inte ömsesidigt uteslutande**. Att pressa in varje meddelande i exakt en av 8 lådor bryter mot verkligheten (en månadsrapport KAN bära en akut faroindikation). Modellen:

- **EN primär kategori** (1–8) — styr process-mall, default-routing och facksystem-mappning. Exakt en, alltid.
- **Ett SET cross-cutting flaggor** — oberoende booleans, vilket antal som helst kan vara satta samtidigt, beräknas **parallellt** med primärkategorin och **oberoende** av den:

| Flagga | Triggas av | Effekt |
|---|---|---|
| `akut_fara` | nyckelord/blankettfält som indikerar omedelbar risk | **routing-override** → mottagning/jour oavsett kategori; höjer prioritet till topp; trigga parallell skyddsbedömning |
| `barn_berörs` | barn-PII/skolavsändare/blankettfält | aktiverar barnskyddsspår + BBIC; påverkar facksystem-modul (IFO barn) |
| `våld_hot` | nyckelord/blankett | höjer prioritet; ev. säkerhetsrutin för handläggare |
| `skyddade_personuppgifter` | markering i SDK-fält/register | **behörighets-gate** → snävare ACL, deny-by-default, döljs i aggregatvyer |
| `okänd_vistelseort` | blankett/innehåll | påverkar handläggningsåtgärd (efterforskning) |
| `tidigare_ärende` | `ArendeMatchService`-träff (axel C) | korrelerar med `hör_till`; drar in historik |
| `frist_kritisk` | handlingstyp med lagstadgad frist (kat 6/8) | höjer prioritet; aktiverar fristbevakning |

**Arkitektoniskt:** primärkategorin är **vilken process-mall** (parametern in i det generiska flödet), flaggorna är **modifierare** på den mallen. Detta bekräftar hypotesen: ETT generiskt flöde parametriserat per ärendetyp + cross-cutting flaggor.

### 4.2 Kat-7-exemplet (det centrala dubbelmärknings-fallet)

> En **månadsrapport från ett HVB-hem** kommer in. Avsändartyp = utförare ⇒ primär **kat 7** (verkställighet/uppföljning). Rapporten innehåller en passage som indikerar **akut fara** för den placerade.

Klassningen producerar:

```
primärKat = 7                       (styr: uppföljningsspår, IFO-modul, hör_till band 1b)
flaggor   = [ akut_fara, barn_berörs ]
```

Effekt:
1. **Primärkategorin förblir 7** — ärendet ÄR en uppföljning; processmallen och facksystem-mappningen är kat 7:s.
2. **`akut_fara` är en routing-override** — raden eskaleras parallellt till mottagning/jour och prioriteras högst, **oavsett** att primärkategorin är 7. En **parallell skyddsbedömning** (kat-1-åtgärden, 11 kap. 1 a § SoL) startas vid sidan av uppföljningsspåret.
3. **Resultatet är INTE "omklassa till kat 1".** Det är "kat 7 primärt **+** kör kat-1:s akutåtgärd parallellt via flaggan". Båda spåren lever; ingen information tappas genom att tvinga ett enda kategorival.

### 4.3 Hur flaggor påverkar prioritet, routing och behörighet

- **Prioritet:** `akut_fara`, `våld_hot`, `frist_kritisk` höjer radens prioritet *ovanpå* kategorins default. Akut slår alltid igenom.
- **Routing-override:** `akut_fara` routar till mottagning/jour **oavsett kategori** — en hård override, inte ett komplement.
- **Behörighet/ACL:** `skyddade_personuppgifter` är en **behörighets-gate** som snävar in ACL och döljer raden i aggregat/belastningsvyer (OSL 26 kap. — gruppledaren ser tal+frist-färg, aldrig innehåll). Knyter an till tre-lagers-ACL-koherensen (GAP-058): flaggan måste reflekteras i `case:`-tagg ∩ Groupfolder-ACL ∩ Tables-vy.

**Kodmässigt bärs flaggor som taggar:** primärkategori och varje flagga blir egna `imap_label`-taggar via den befintliga, email-scopade `ItslTagService->tagMessage()` `[FINNS]` (t.ex. `kat:7`, `flag:akut_fara`). Eftersom taggmotorn är trådbred och funktionsadress-scopad delas klassningen av alla handläggare med korgen — exakt vad ett delat ärende kräver.

---

## 5. Innehålls-klassificeringsmotorn

### 5.1 Ställningstagande — DETERMINISTISK regelkaskad + konfidens FÖRST

**Innehållsklassningen är SVÅRARE än kanalklassningen — och måste därför vara MER försiktig, inte mer ambitiös.** Kanalklassningen (`MessageTypeService`, `[FINNS]`) är ett rent suffix-/header-uppslag: 5 utfall, noll tolkning, fail-closed. Innehållsklassningen ska avgöra *första handläggningsåtgärd* och *vilken facksystem-modul* ärendet hör till — en bedömning som rör sekretessens placering. **Felklassning här är inte ett UX-fel utan en sekretessincident.**

- **(a) Människo-bekräftat alltid** när klassningen påverkar vart sekretess routas. Över tröskel: auto-applicera primärkategori men låt den vara **redigerbar** i triagen; under tröskel: `föreslagen`, människa bekräftar.
- **(b) Medborgar-PII och innehåll lämnar aldrig huset.** Ingen extern LLM ser ärendetext. Eventuell modell-assist körs **lokalt** (samma röd-zon-villkor som KB-Whisper, GAP-052) och endast på människo-begäran.
- **(c) LLM är ett valfritt, människo-bekräftat förslagslager** — aldrig autonomt/skarpt på sekretessbelagt innehåll (GAP-052/060). Avvisade förslag loggas i `activity` så att tyst felrouting blir spårbar.

### 5.2 Signalstyrka — fallande deterministisk ordning (stannar på första säkra träffen)

| Lager | Signal | Konfidens |
|---|---|---|
| **(a)** | **Strukturerade SDK-fält / X-headers** — `enhanceMessages()` `[FINNS]` läser redan `X-Sdk`/`X-MessageType`. Ärendetyp-/handlingskod i SDK-kuvertet kräver ingen tolkning. | **Exakt (1.0)** om koden finns |
| **(b)** | **Avsändartyp / organisation** mot konfigurerbart organisationsregister (`[KONFIG]`, à la DIGG-synk i `UpdateAddressBookService` `[FINNS]`). LOA3-stärkt (`Check/Loa3.php` `[FINNS]`) → org-påståendet är inte fritextmatchat. | **Hög** men heuristisk |
| **(c)** | **Blankett-/formulärtyp** — stabila markörer (titel, formulär-id, fältmönster). Orosanmälningsblankett→kat 1; SIP-kallelse→kat 5; LVU/LVM-handling→kat 6. | Medel-hög |
| **(d)** | **Nyckelord / handlingstyp** i ämne/innehåll — svagaste deterministiska signalen; **får aldrig ensam** ge auto-applicering på sekretess. | Låg-medel |
| **(e)** | **LLM-förslag** — utanför den deterministiska kaskaden. Lokal, avstängbar, **alltid under tröskel**, alltid `föreslagen`, aldrig auto, aldrig på sekretess utan människa. Loggas. | — |

**Avsändartyp → primär kandidat (exempel):** domstol/åklagare/KV/SiS/biträde→**6**; region/sjukhus/psykiatri→**5**; skola+barn-indikation→**1**; KFM/FK/AF/hyresvärd/bank→**3**; HVB/familjehem/utförare→**7**; tingsrätt+familjerätt→**8**. *Org-typ är en stark prior, inte ett facit (en region kan skicka en orosanmälan).*

**Tröskelprincip:** tröskeln är **server-side policy** (granskad, per kund), **inte** klientlogik — ärver samma krav som auto-kopplingströskeln (GAP-060), aldrig en demo-konstant (`≥0.9`) i klienten. Default vid otillräcklig/motstridig signal är **`oklassad`** (fail mot människa), aldrig en gissad primärkategori.

| Konfidens | Tillstånd | Vad händer |
|---|---|---|
| ≥ tröskel | **klassad** | Primärkategori auto-applicerad men **redigerbar**; flaggor satta som taggar |
| < tröskel, kandidat finns | **`föreslagen`** | Mänsklig triage bekräftar/korrigerar; bilaga speglas **vid bekräftelse, inte vid förslag** |
| noll / motstridig | **`oklassad`** | Band 1c / klassnings-bekräftelse; manuell triage; **aldrig** gissad routing |

### 5.3 Var klassningen exekveras — samspel kanal/innehåll/ärendekoppling

Triagen klassar varje inkommande rad längs **tre ortogonala axlar** i **en** server-side passage:

| Axel | Frågan | Mekanism | Status |
|---|---|---|---|
| **A — KORG** | Vem får se? (behörighet) | `ProvisionPersonligAccountsService`, `ConsolidateMailboxesService` | `[FINNS]` |
| **B — KANALTYP** | Hur kom det in? → 5 typer | `MessageTypeService` (suffix + X-headers), **deterministisk, ingen LLM** | `[FINNS]` |
| **B′ — INNEHÅLLSKATEGORI** | Vilken första åtgärd? → 1 av 8 → vilken modul | **`InnehallsKlassService`** | `[BYGGS]` |
| **C — ÄRENDEKOPPLING** | Nytt / hör till / ej kopplat → band 1a/1b/1c | `ArendeMatchService` | `[BYGGS]` |

```
MessageReceivedEvent  (server-side, vid inflöde)  [BYGGS]
   ├─ Axel B   MessageTypeService     → kanalTyp (sdk/fax/sms/internal/secure)        [FINNS]
   ├─ Axel B′  InnehallsKlassService   → { primärKat 1–8, konfidens, flaggor[] }       [BYGGS]
   └─ Axel C   ArendeMatchService      → arendekoppling (nytt/hör_till/ej_kopplat)      [BYGGS]
```

De tre körs oberoende, ingen skriver över de andra; tillsammans ger de raden dess fulla triage-shape: `{ korg, kanalTyp, primärKat, flaggor[], arendekoppling, konfidens }`. **Korrelerade men ortogonala:** kat 4 & 7 ⇒ nästan alltid `hör_till`; kat 1 & 2 ⇒ oftast `nytt` — klassningen får **utnyttja** korrelationen som svag signal men aldrig hårdkoda den.

**Mönstret finns redan.** `MessageImportantClassifiedListener::handle()` `[FINNS]` är den exakta mallen: tar emot event, drar `getMessageId()` (IMAP-strängen, **inte** DB-int), anropar `tagService->tagMessage(...)`. Ny `lib/Service/InnehallsKlassService.php` `[BYGGS]` följer mallen, anropad från `lib/Listener/MessageReceivedListener.php` `[BYGGS]` på `MessageReceivedEvent` `[BYGGS]`. Ingen klient-fan-out — dashboarden tar emot färdigt klassningsresultat via server-aggregat `GET /api/v2/inflode-summary` `[BYGGS]` (OCS-prefix `/api/v2/` verifierad konvention).

---

## 6. App-/kodpåverkan — FINNS vs BYGGS

**Huvudslutsats:** Koden är **redan byggd kring en enda saga.** `treserva.js` → `skapaArende()` `[FINNS]` mintar `hubsCaseId`, sätter `steg='forhandsbedomning'`, startar 14-dgr-klocka, skapar register-pekare — identisk shape oavsett innehåll. State-machinen `arendeFlow.js` `nastaFor()` `[FINNS]` är redan **data-driven** (läser `a.steg`/`a.plikt`/`a.vantar`/`a.nastaAtgard`). Ingen kategori är hårdkodad. Att lägga till en `ArendeTyp`-parameter fyller en redan existerande lucka. **Nyansering:** idag är värdena hårdkodade till kat 1 (`skapaArende()` sätter alltid `plikt:skyddsbedomning` + 14 dgr).

### 6.1 Klassificeringslagret `[BYGGS]`
`InnehallsKlassService` ovanpå `MessageTypeService` (kanal, `[FINNS]`). Resultatet bärs som `kat:{n}`-tagg via **befintliga** `ItslTagService->tagMessageWithMetadata()` `[FINNS]` — ingen ny taggmekanik, bara en ny label-konvention. **Netto:** ny klass + ny listener + ny label-konvention. Taggväg, IMAP-spegling, event-brygga, fail-closed **finns**.

### 6.2 `ArendeTyp`-registret `[BYGGS]` — KÄRNAN
Där hypotesens "parameterisering" bor. En config-struktur (egen Tables-tabell `hubs_arende_typ` **eller** `IAppConfig`-nyckel — `ConsolidateMailboxesService` använder redan `OCP\AppFramework\Services\IAppConfig` `[FINNS]`, så app-config-vägen har en etablerad läsare). `ArendeService::createCase()` tar `arendetyp` som input och **parameteriserar varje sagasteg** ur registret i stället för att hårdkoda. **En `createCase()`, åtta beteenden, noll grenar i kod.** `nastaFor()` behöver inte ändras — den läser redan `a.nastaAtgard` som nu kommer ur process-mallen. **Det enda strukturellt nya** — men ersätter 8 potentiella kodflöden med en datatabell.

### 6.3 Routing `[delvis FINNS]`
Korg-/behörighetsmaskineriet är moget: `ConsolidateMailboxesService::calculateEffectiveUsers()` + `syncMailboxAccess()` + `syncAssignmentTags()` `[FINNS]`. Det som BYGGS är **regeln** "kategori → mål-enhet" (uppslag i `ArendeTyp.defaultEnhet`), kat 2:s **sub-klassificering** (utan/med behovsprövning → olika mål-enhet), och att flaggor kan **flytta** routingen (`skyddade_pu`→skyddad krets, `akut`→prioritetskö).

### 6.4 UI-påverkan (minimal — 3 band OFÖRÄNDRADE)
**De 3 banden ändras INTE.** De styrs av Axel C `arendekoppling` via `MinaArenden.vue` `zonOf()` (`hör_till`→1b, `ej_kopplat`→1c, annars→1a). Kategorin läggs till som **etikett/parameter**, aldrig som ny sorteringsdimension.

| Komponent | FINNS idag | BYGGS (tillägg) |
|---|---|---|
| `InflodeRad.vue` | Renderar typ-chip (`messageTypeLabel`), `KopplingBadge`, `FristChip` | **`KategoriBadge`** *bredvid* typ-chippen — samma chip-grammatik. Ett komplement-chip, inte en ny rad. |
| `NastaAtgardKnapp.vue` / `arendeFlow.js` | `nasta()` → `nastaFor()` `[FINNS]`, redan data-driven | **Process-mall-driven via datan. Noll komponent-ändring** — den eleganta poängen. |
| `KorgValjare.vue` | Korg-filter ∩ typ-filter (`valjTyp`, 8 typer) | Kategori-filter som komplement, eller återanvänd `aktivTyp`-axeln. |
| Dubbelmärknings-flaggor | Identitets-badge med varning-ton (`--anonym`) som mall | Diskreta varningsmarkörer (akut/skyddade-pu/barn-berörs). Kat 7 *även* kat 1 = **två chips, inte två rader**. |
| `KopplingBadge.vue` / `FristChip.vue` | Oförändrade | **Inga ändringar** — `FristChip` renderar `frist.tone`/`daysLeft` generiskt. |

### 6.5 Frist-motorn `[BYGGS]`
`FristChip.vue` renderar redan generiskt (`frist.tone`→färg, `daysLeft`). Problemet: fristvärdena är hårdkodade (`skapaArende()` sätter alltid 14 dgr). `ArendeTyp.fristPolicy` blir källan: kat 1→14 dgr ur inkom-datum (GAP-002); kat 5→koordinering (kort tröskel); kat 6→domstolsfrist T-7/T-3/T-0. I prod speglas `fristDue` ur Treserva via Frends (GAP-018). `FristChip` ändras inte.

### 6.6 ACL/sekretess-profiler `[BYGGS]` (knyter till GAP-058)
`ConsolidateMailboxesService` är redan en effektiv-behörighets-motor som avger `CriticalActionPerformedEvent` (audit) `[FINNS]`. Det som byggs: en **per-kategori inre-sekretess-profil** (`ArendeTyp.aclProfil`) → Groupfolder-ACL-mall som `createCase()` steg R4 instansierar. Exakt **GAP-058 tre-lagers-ACL**: `kat:`-tagg ∩ Groupfolder-ACL ∩ Tables-vy = samma sanning, deny-by-default. `skyddade_pu`-flaggan **överstyr** profilen till hårdare ACL oavsett kategori.

### 6.7 Frends/Treserva per-kategori `[BYGGS]`
`treserva.js` `commitHandling(payload)` är en **stateful stub** med rätt mönster: registrerar handling, returnerar verifierat kvitto, **startar retention först på verifierad callback** (`{state:'gallras_efter_commit'}`). Den verkliga `TreservaCommitService` + Frends-konnektor måste vara **per-kategori-mappad** — olika kategorier hamnar i olika moduler. `ArendeTyp.frendsModul` väljer **vilket Frends-flöde** `commitToTreserva` anropar. Payloaden behöver bara ett fält till: `arendetyp`. Mönstret (verifierad callback → provenans-flip → retention) byggs ut, inte om.

### 6.8 Demo (klient-sidigt, ingen backend)
1. Utöka inflöde-raderna i `demo/socialsekreterare.js` med `kategori:{nr, label, flaggor[]}` bredvid `messageType`.
2. Ny `demo/arendetyper.js` — 8 poster; `skapaArende()` läser den i stället för att hårdkoda → "skapa ärende" blir typ-parameteriserad (kat 3 ger annan första-åtgärd/frist än kat 1). Gör hypotesen **körbar och demonstrerbar**.
3. Inga nya komponenter — det räcker att utöka datan + ett chip + ett filtervärde.

---

## 7. Konsekvenser/risker + bygg-delta

### 7.1 Ärlighet & risk att bevaka
- **Det som FINNS** stödjer mönstret: deterministisk datadriven klassning (`MessageTypeService`), email-scopad single-writer-taggmotor (`ItslTagService`), retention-stack, Flow-registrering, effektiv-behörighets-motor (`ConsolidateMailboxesService`), data-driven state-machine (`arendeFlow.js`). `ArendeTyp`-registryn är samma disciplin ett lager upp.
- **Det som BYGGS:** `hubs_arende_typ`-tabellen + seed av 8 rader, `ArendeService`-sagan som *läser* raden, de ~3 namngivna hookarna (`diariefor_direkt`, `familjeratt_yttrande`, partsmodell-hantering), `InnehallsKlassService`, och `frendsMappning` per modul.
- **Tyngsta risken — `frendsMappning` per modul (GAP-019):** här möter "config" verklig integrationskomplexitet. Config-raden *pekar* på mappningen men mappningen själv (IFO-barn-fältschema vs ek.bistånd-fältschema) är riktig integrationskod per modul. Det motsäger inte verdiktet — det är *data om* var commiten ska, men payload-transformen är kod. Håll den bakom `frendsMappning`-id så att motorn förblir generisk.
- **Felklassning = sekretessincident.** Eftersom primärkategorin väljer facksystem-modul kan en tyst felklassning lägga sekretessbelagt innehåll i fel akt. Därför: över tröskel = redigerbar (inte låst), under tröskel = människo-bekräftad, noll = `oklassad`. Avvisade/korrigerade klassningar loggas i `activity`.

### 7.2 Bygg-delta (insticksdelta mot bygglistan i `HUBS-INTERNALS-ARENDEMOTOR.md` §4)
Den befintliga topp-9 (Frends GAP-019, registret GAP-056, `createArende` GAP-010, commit-bunden gallring GAP-007, ACL GAP-057/058) står kvar oförändrad. Taxonomin lägger till:

| Δ | Ny/ändrad del | Beror på / ändrar | Plats i listan |
|---|---|---|---|
| **Δ1** | **`ArendeTyp`-registret** (process-mall-config) | Förutsätter GAP-056-registret; *parameteriserar* GAP-010 `createArende` | **Direkt efter #2 (GAP-056)** — innan `createArende` |
| **Δ2** | **`InnehallsKlassService` + `MessageReceivedListener`** | Mall finns (`MessageImportantClassifiedListener`); oberoende av Frends | Parallellt, kan börja nu — låg risk, isolerad |
| **Δ3** | **Routing-regel kategori→enhet + kat 2 sub-klass** | Ovanpå `ConsolidateMailboxesService` `[FINNS]` | Efter Δ1 (läser `defaultEnhet`) |
| **Δ4** | **Per-kategori `fristPolicy`** i `ArendeService` | Del av GAP-018 frist-spegling; läser Δ1 | Slås ihop med GAP-018 |
| **Δ5** | **Per-kategori ACL-profil** | **Utökar GAP-058** (tre-lagers-ACL) — en dimension till | In i #5 (GAP-057/058) |
| **Δ6** | **Per-kategori Frends-modul-mappning** | **Utökar GAP-019** — `frendsModul` väljer flöde | In i #1 (GAP-019) |
| **Δ7** | **Hooks** (`diariefor_direkt` kat 6, `familjeratt_yttrande` + partsmodell kat 8) | Deklarerade pre/post-steps i sagan | Med Δ1, efter `createArende` |
| **Δ8** | **Demo: `arendetyper.js` + kategori på inflöde-rader** | Inga beroenden — ren klient | Kan göras omgående |

**Slutsats:** Taxonomin kräver **en strukturellt ny sak — `ArendeTyp`-registret (Δ1)** — som ersätter 8 hypotetiska kodflöden med en datatabell och därmed *minskar* byggytan jämfört med naiv per-typ-kodning. Allt annat är antingen fält i den tabellen (Δ4–Δ7, som glider in i redan-planerade GAP) eller tunna tillägg på kod som redan finns (Δ2–Δ3, Δ8). **Hypotesen bekräftas och nyanseras: ett parameteriserat flöde — en motor, en saga, N config-rader, ~3 hooks, M flaggor — inte åtta forks, inte ett odifferentierat flöde.**

---

**Relevanta filer (absoluta sökvägar):**
- FINNS — kanalklassning: `C:/Users/fredrik.jonasson/Cursor/Nextcloud-vanilla/hubs-code/sdkmc/sdkmc-main/lib/Service/MessageTypeService.php`
- FINNS — taggmotor (case:/kat:-bärare): `…/sdkmc-main/lib/Service/ItslTagService.php` (`tagMessage`, `tagMessageWithMetadata`)
- FINNS — korg/behörighet/IAppConfig: `…/sdkmc-main/lib/Service/ConsolidateMailboxesService.php`
- FINNS — klassnings-listener-mall: `…/sdkmc-main/lib/Listener/MessageImportantClassifiedListener.php`
- FINNS — LOA3-stärkning: `…/sdkmc-main/lib/Check/Loa3.php`
- FINNS — UI: `…/hubs_start/src/components/socialsekreterare/InflodeRad.vue`, `KorgValjare.vue`, `NastaAtgardKnapp.vue`, `KopplingBadge.vue`, `FristChip.vue`; `MinaArenden.vue` (`zonOf()`)
- FINNS — state-machine (data-driven): `…/hubs_start/src/services/arendeFlow.js` (`nastaFor`)
- FINNS — demo-seams: `…/hubs_start/src/services/demo/socialsekreterare.js`, `…/demo/treserva.js` (`skapaArende`, `commitHandling`)
- BYGGS (förslagen placering): `…/sdkmc-main/lib/Service/InnehallsKlassService.php`, `ArendeService.php`, `TreservaCommitService.php`; `ArendeMatchService.php`; `lib/Listener/MessageReceivedListener.php` + `lib/Event/MessageReceivedEvent.php`; `ArendeTyp`-register (Tables `hubs_arende_typ` eller `IAppConfig`); `GET /api/v2/inflode-summary`; demo `…/hubs_start/src/services/demo/arendetyper.js`
- Grund: `…/hubs_start/docs/HUBS-INTERNALS-ARENDEMOTOR.md` (§1.2 sagan, §2 tre axlar, §4 bygglista), `…/docs/HUBS-ARKITEKTUR-SOCIALTJANST.md` (§1.2 register-shape, §3 Flow vs programmatiskt)
