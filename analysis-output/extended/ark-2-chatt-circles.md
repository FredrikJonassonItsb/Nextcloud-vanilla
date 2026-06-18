<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Ark-varv 2 — Säker chatt (Teams-likt) i socialsekreterarens löpande arbete

> **Vad detta är:** ett arkitektur- och designvarv som svarar på frågan *hur vi premierar och lyfter
> chattarbetet naturligt i socialsekreterarens vardag* — utan att chatten blir "ännu en inkorg". Hubs
> stora konkurrensfördel mot en ren facksystems-värld är **säker chatt som Teams** (`spreed`, on-prem) plus
> **grupper/team** (`circles`). Vi designar tre lager: **(a) ärende-chatt** knuten till ett ärendekort,
> **(b) team-/enhets-chatt** via Circles/Teams (mottagningsgruppen, barn-familj-enheten), **(c) 1:1 +
> omnämnanden + närvaro** — och hur allt surfas i "Mina ärenden" utan inkorgs-känsla. Plus sekretess: **är
> ärende-chatt allmän handling, och hur committas relevanta beslut ur chatten till Treserva?**
>
> **Persona:** `socialsekreterare` (barn & familj, SoL 2025:400, BBIC) · **System of record:**
> Treserva/Lifecare/Viva/Combine (socialakten/BBIC-journalen). **Plattform:** server v32 (Hub 25 Autumn).
> **Datum:** 2026-06-14.
>
> **Bärande arkitektur (oförändrad):** Hubs är **mellanlagring**; facksystemet äger originalet. Det gäller
> chatten lika hårt som filer — och är *själva poängen* med chattlagret: chatten är arbetsytan där beslut
> mognar, men det *beslut* som faller ut **committas till Treserva via Frends**; chatten i sig gallras.
>
> **Antagande (per uppdrag):** alla blockerare lösta — Treserva-commit via **Frends** (verifierad
> återkoppling), **Inera Underskriftstjänst**, lokal **transkribering**, **Retention-paus**.
>
> **Varumärkesregel (enforced):** i produkt-/UI-text säger vi aldrig "Nextcloud"/"Talk"/"Circles". Vi säger
> **säker chatt, ärendechatt, enhetschatt, team, närvaro, omnämnande**. App-id (`spreed`, `circles`,
> `deck`, `groupfolders`, `flow`/`workflow_engine`, `activity`, `files_retention`) nämns bara i byggnoteringar.

---

## 0. Designtes: chatten ska *upplevas* som en del av ärendet, inte som en kanal

Den största risken med att lägga in chatt är att vi återskapar Teams: en parallell, gränslös ström som drar
uppmärksamhet *bort* från ärendet och blir en andra inkorg. Hela "Mina ärenden"-redesignen byggde bort
inkorgskänslan genom att göra **ärendet** (inte meddelandet) till den bärande enheten. Chatten måste följa
samma lag, annars river den ned vinsten.

**Tesen i en mening:** *Chatt i Hubs är inte en plats man "går till" — det är en flik på ärendekortet och en
lugn närvaro-/omnämnande-signal i Dagspulsen. Man chattar **om ett barn**, sällan "i en kanal".*

Tre konsekvenser styr resten av dokumentet:

1. **Ärende-först, inte kanal-först.** Den primära chatten är **ärende-chatten** (lager a), inbäddad i
   ärendekortets flik "Meddelanden/Diskussion". Team-chatten (lager b) är sekundär och kontextuell — den
   används för fördelning och stöd, inte för ärendeinnehåll.
2. **En aviseringsväg, inte två.** Chatt-omnämnanden surfas i **samma Dagspuls-räknare-logik** som resten
   (ny `💬 omnämnanden`-räknare), inte i ett separat chatt-badge-ekosystem. Olästa team-meddelanden
   *räknas inte* lika hårt som ett omnämnande som väntar på just mig (se §6).
3. **Sekretess som förstklassig egenskap.** Varje chattyta bär en synlig sekretess-/deltagar-rad
   ("3 deltagare · alla i barn-familj-enheten · OSL 26 kap.") och chatt-innehåll committas aldrig
   automatiskt till Treserva — människa väljer, `CommitGrind` för över (se §7–8).

---

## 1. Varför chatt är Hubs hävstång här (och vad svensk socialtjänst faktiskt gör idag)

Socialtjänstens barn- och familjeenhet är **organiserad i grupper**: en **mottagningsgrupp** (ofta 6–9
socialsekreterare + en **gruppledare/1:e socialsekreterare**) som tar emot och förhandsbedömer, och en eller
flera **utredningsgrupper** (~9 sekreterare + gruppledare) dit ärendet **överlämnas om utredning inleds**.
Gruppledarna **fördelar ärenden tillsammans** för jämn arbetsbelastning, och i varje team finns ofta en
sekreterare med **specialist-/mentorsfunktion** för nyanställda (källor nedan). Det dagliga arbetet är
genomsyrat av *löpande, kort, sekretesskänslig samordning*: "kan du ta den här?", "hur tänker du kring
skyddsbedömningen?", "ska vi dra den på fördelningsmötet?", "jag är osäker på lagrummet — kan gruppledaren
titta?".

Idag sker den samordningen i **osäkra eller olämpliga kanaler**: korridorsnack, vanlig e-post, sms, ibland
Teams (som inte är byggt för socialtjänstsekretess och vars data hamnar utanför driftmiljön). Det är exakt
det glapp Hubs säkra chatt fyller: **on-prem, sekretessmärkt, ärendekopplad** samordning som ersätter
Teams/sms/e-post för det interna pratet — utan att data lämnar kommunens server.

Plattformen är dessutom mogen för det just nu. **Hub 25 Autumn (v32)** gör chatten "tystare och vassare":
**trådade konversationer** (sidodiskussioner knyts till ursprungsmeddelandet så kontext inte tappas), en
**chatt-hemvy/dashboard** som samlar *kommande möten, nya meddelanden, mina omnämnanden och mina
påminnelser*, en automatisk **🔴 Upptagen-närvaro** under möten, samt påminn-på-meddelande. Det är i praktiken
en färdig **omnämnande-/närvaro-motor** vi kan surfa i Dagspulsen i stället för att bygga själva.

**Säljvärdet:** facksystemet (Treserva) har ingen bra intern-samordningsyta — det är ett journalsystem, inte
ett samarbetsverktyg. Hubs lägger den **säkra Teams-ersättaren ovanpå**, ärendekopplad, och stänger gapet
"var pratar vi om barnet utan att det läcker eller hamnar i ett journalfält det inte hör hemma i".

---

## 2. De tre chatt-lagren — översikt

| Lager | Vad | Backing | Var det syns i "Mina ärenden" | Primärt syfte |
|---|---|---|---|---|
| **(a) Ärende-chatt** | En säker tråd per ärende (dnr). Deltagare: tilldelad + ev. medhandläggare, gruppledare, ev. specialist/mentor; vid samverkan en avgränsad extern tråd. | `spreed` group-conversation, läst-/skrivstyrd, kopplad till ärendet via Hubs mappningstabell | **Flik "Diskussion" på `ArendeKort`** (Quick View). Olästa/omnämnanden visas som liten chip på kortet. | Löpande samordning *om ett specifikt barn*: bollplank, frågor till gruppledare, beslutsberedning. |
| **(b) Team-/enhets-chatt** | Stående konversationer knutna till **team** (Circles): `mottagningsgruppen`, `utredningsgrupp-1`, `barn-familj-enheten`. | `circles` team → `spreed` conversation kopplad till teamet | **Zon V-/Dagspuls-ingång "Enhetschatt"** + en lugn panel i foten, *inte* ett ärendekort. | Fördelning, morgonmöte, generella frågor, stöd/mentorskap, "vem är inne idag". |
| **(c) 1:1 + omnämnanden + närvaro** | Direktmeddelande person↔person; `@namn`-omnämnanden i (a)/(b); närvarostatus (inne/upptagen/möte). | `spreed` one-to-one + mentions + user status | **Dagspulsen `💬`-räknare** (mina omnämnanden) + närvaroprick på avatarer i lobby/deltagarlistor. | Snabb, riktad kontakt och "väntar något på *mig*?". |

Designprincipen mellan lagren: **ärendeinnehåll hör hemma i (a)**, **organisation/flöde i (b)**, **person i
(c)**. Det håller isär "prat om barnet" (potentiellt allmän handling, se §7) från "prat om jobbet".

---

## 3. Lager (a) — Ärende-chatt: en säker tråd knuten till ärendet

### 3.1 Hur den föds och var den bor

Ärende-chatten **skapas inte manuellt** — den orkestreras i samma ETT-klick som ärenderummet. När en
triage-rad blir ett ärende ("Ta emot & starta förhandsbedömning", se redesignen) skapar orkestrerings-
endpointen, utöver Groupfolder + ACL + BBIC-mall + frist-klocka, **en ärende-konversation** (`spreed` group
room) med:

- **Deltagare = ärenderummets ACL-krets** (tilldelad: skriv; gruppledare: läs/skriv; ev. medhandläggare).
  Chattens deltagarlista speglar Groupfolderns ACL så sekretessgränsen är *en* gräns, inte två (jfr GAP-051).
- **Namn = ärendereferens, aldrig PII:** "Diskussion · Barn 2026-0142 · dnr 2026-IFO-0142".
- **`listable` = endast deltagare** (konversationen är inte sökbar/öppen för enheten) och **lobby/gäst av**
  för den interna tråden.

**Viktig ärlig byggdetalj (constraint).** Talk Conversation API:s `objectType`/`objectId` stöder i dagsläget
i praktiken bara `room` (breakout-room-förälder) — det finns *ingen* native "bind denna konversation till dnr
X"-objekttyp. **Slutsats:** kopplingen ärende↔chatt **bär Hubs själv** i sin mappningstabell
(`dnr ↔ conversationToken`, samma tabell som redan måste lösa `dnr ↔ groupfolderId ↔ Treserva-dnr`, jfr
GAP-005/GAP-041). Vi förlitar oss alltså inte på en native objektbindning som inte finns; vi använder
`spreed` som ren chatt-motor och äger relationen i mellanlagrings-modellen. Det är samma mönster som för allt
annat: Hubs orkestrerar, native-appen levererar primitiven.

### 3.2 Hur den syns i ärendekortet

I `ArendeKort`s Quick View (flikarna *Dokument · Meddelanden · Möten · Bevakningar · Beslut* i redesignen)
**utökas/byter "Meddelanden" namn till en tvådelad flik**:

- **"Säkra meddelanden"** = den befintliga externa kommunikationen (sdkmc/securemail till klient/motpart,
  med `kvittenser`-tidslinje). *Utåtriktat, kvittensbärande.*
- **"Diskussion" (ny, intern chatt)** = ärende-chatten. *Inåtriktad, kollegial.*

Den distinktionen är medvetet skarp: **säker e-post/SDK = formell, kvitterad kontakt med part** (kan bli
allmän handling i akten), **ärende-chatt = internt arbetsprat** (arbetsmaterial som standard, se §7). Att
blanda dem vore exakt inkorgs-felet.

På det **kollapsade** kortet (Zon 2/3) surfas chatten minimalt: en liten **`DiskussionChip`** —
`💬 3` (olästa) eller, starkare, **`💬 @ 1`** (ett omnämnande som väntar på *mig*). Omnämnandet väger tyngre
än olästa (färgton + räknas i Dagspulsen); rena olästa är neutrala. Inga röda badge-moln — chatten ska
*inte* skrika.

### 3.3 Vad man gör i ärende-chatten (typiska handlingar)

- **Bollplank/beredning:** "Skyddsbedömningen — jag landar i *inget omedelbart skydd* men vill ha en andra
  blick. @Gruppledare?" → tråd (Hub 25 trådning) håller diskussionen samlad under det meddelandet.
- **Be om medsignering/medbedömning:** "@Karin kan du medbedöma inför beslut inleda?" → leder vidare till
  `attSignera`-medsignering, men *samtalet om det* sker här.
- **Dela en intern referens:** länk till en fil i ärenderummet (deltagaren har redan ACL → ingen ny
  exponering) eller till ett `bevakningar`-kort. Chatten lever *inuti* sekretessgränsen.
- **Lyfta till fördelning:** "Den här borde nog till utredningsgrupp 1 — tar upp på fördelningsmötet" →
  knapp **"Lyft till enhetschatt"** (kopierar en avidentifierad referens till team-chatten, lager b, utan
  att flytta sekretessinnehåll).

### 3.4 Samverkans-tråd (extern, avgränsad) — försiktigt

Ibland behövs **samverkan** med en annan myndighet (skola, region, polis) löpande, inte bara via formell
SDK. Då kan en **avgränsad samverkans-konversation** skapas *vid sidan av* den interna ärende-chatten — egen
deltagarkrets, **lobby + BankID/Freja/SITHS-verifiering** av externa, tydlig "EXTERN — samverkan"-märkning,
och **annan sekretessbedömning** (uppgifter till utomstående är begränsade under förhandsbedömning, GAP-006;
fas-spärren gäller även här). Den externa tråden är **inte** default — den skapas medvetet, och dess
innehåll är mer sannolikt allmän handling (kommunikation med extern part, jfr §7). Default för
socialsekreteraren är den *interna* tråden.

---

## 4. Lager (b) — Team-/enhets-chatt via Circles/Teams

### 4.1 Team = Circles, chatt = en konversation knuten till teamet

Den svenska grupporganisationen (mottagningsgrupp, utredningsgrupp, enhet) modelleras som **Team (`circles`)**.
Circles/Teams är det core-byggblock vars medlemskap **andra appar delar** (samma team som äger ärenderums-ACL
och kunskapsbanks-åtkomst). Varje stående team får en **enhetskonversation** (`spreed` group room med teamet
som deltagarkälla), så medlemskapet är *en* sanning: lägg någon i `utredningsgrupp-1`-teamet → de får
ärenderums-, kunskapsbanks- **och** chatt-åtkomst i ett svep (provisioneringshygien, jfr `provisionering`).

Stående team-chattar för personan:

- **`mottagningsgruppen`** — morgontriage, "vem tar nästa orosanmälan", akut skyddsbedömnings-second-opinion.
- **`utredningsgrupp-1` / `-2`** — fördelade ärenden, metodstöd, BBIC-frågor.
- **`barn-familj-enheten`** — hela enheten: rutiner, info, mentorskap, "någon som kan svenska för
  tolksamtal imorgon?".

### 4.2 Var den syns — och varför *inte* som ett ärendekort

Team-chatten är **organisatorisk, inte ärendebärande** → den får **inte** ligga som ett kort i ärendelistan
(det vore att blanda kanal och ärende igen). I stället:

- En **ingång i Zon V/foten: "Enhetschatt ▸"** med en liten olästa-/omnämnande-indikator. Klick öppnar en
  **lugn sidopanel** (eller en dedikerad vy) med teamens trådar — *aldrig* mitt i ärendeflödet.
- **Fördelnings-stöd:** team-chatten är där gruppledaren och sekreterarna *pratar om* fördelningen; själva
  fördelningen (tilldelning) sker på triage-raden/ärendekortet ("Ta emot", "Koppla", "Tilldela till …").
  Chatten är samtalet, tilldelningen är handlingen — håll isär.

### 4.3 Fördelningsmötet (morgonmöte) — där chatt + möte + triage möts

Gruppledarnas dagliga **fördelning** är ett naturligt nav: ett kort **säkert möte** (kan auto-skapas ur
team-konversationen, Hub 25:s "starta möte ur chatt") där dagens otriagerade inflöde (Zon 1 "Att ta emot")
gås igenom och tilldelas. Chatt-historiken i teamet blir den **löpande loggen mellan** mötena
("akut inkom 14:20, @Anna tar den"). Detta operationaliserar "gruppledarna fördelar ärenden tillsammans"
i ett säkert, on-prem-spår i stället för korridor/Teams.

---

## 5. Lager (c) — 1:1, omnämnanden och närvaro

- **1:1-direktmeddelande:** person↔person (`spreed` one-to-one), för snabb riktad fråga utan ärendekontext
  ("hinner du med en sak före lunch?"). Startas från en avatar var som helst (deltagarlista, lobby,
  team-medlem). **Regel:** sekretessbelagt ärendeinnehåll hör *inte* i 1:1 — det hör i ärende-chatten (a),
  där deltagarkretsen = ACL och spårbarheten finns. UI:t kan mjukt påminna ("Gäller det ett barn? Använd
  ärendechatten så hamnar det rätt.").
- **Omnämnanden (`@namn`):** den **enda** chatt-signal som lyfts till Dagspulsen som en räknare
  (`💬 omnämnanden`). Ett omnämnande = "någon vill ha *mig*" → samma vikt som "väntar-på-mig" i
  ärendesorteringen. Olästa utan omnämnande genererar *ingen* puls-siffra (annars inkorg).
- **Närvaro:** Hub 25:s **🔴 Upptagen** (auto under möten) + inne/borta visas som **prick på avataren** i
  lobbyn (kompletterar `identitetsBadge`s LOA-bock) och i deltagarlistor. Det svarar på "kan jag störa
  gruppledaren nu?" utan att man behöver fråga. Närvaro skrivs aldrig till facksystemet — ren arbetsyte-signal.

---

## 6. Hur chatt surfas i dashboarden utan att bli "ännu en inkorg"

Detta är frågans kärna. Tre regler, alla i linje med redesignens IA:

**Regel 1 — Omnämnanden, inte olästa, är valutan.** Dagspulsen får **en** ny räknare:
`💬 {n} omnämnanden` (ikon `At` + tal + text, aldrig bara färg). Den räknar *omnämnanden + 1:1 riktade till
mig*, **inte** rå olästa i team-chattar. Klick = filtrera/öppna "väntar på mig"-vy. Resultat: chatten kan
aldrig generera en växande röd siffra bara för att teamet är pratglatt. (Detta speglar Hub 25:s egen
chatt-dashboard som lyfter just *mentions*.)

**Regel 2 — Ärende-chatt syns *på ärendet*, inte i en kanal-lista.** Per-ärende-olästa lever som
`DiskussionChip` på kortet (§3.2). Man "går till" aldrig en chattinkorg för ärenden — man ser på ärendekortet
att det finns ny diskussion, i samma blick som frist och nästa åtgärd. **Ärende-chatt-aktivitet kan dessutom
höja ett kort till Zon 2 "Kräver åtgärd nu"** endast om det är ett *omnämnande till mig* (via
`zonOf`-utvidgning: `arende.diskussion.omnamnandeTillMig === true → 'het'`). Vanlig chatt-aktivitet rör inte
sorteringen → fristlogiken förblir kung.

**Regel 3 — Team-chatt är en lugn sidoyta, aldrig i ärendeströmmen.** En diskret "Enhetschatt"-ingång
(§4.2), inte kort, inte i Zon 1–3. Den som vill jobba ärende-fokuserat ser den knappt; den som ska fördela
går dit aktivt.

**Konkret placering (utökar redesignens zon-karta):**

```
ZON 0 — Dagspulsen:   ⏰ frister · 📹 möten · ✍ signera · 📥 nya · 💬 omnämnanden   ← chatt = EN räknare
ZON V — Verbingång:   … + lugn "Enhetschatt ▸"-ingång (team-chatt, lager b)
ZON 2/3 — Ärendekort: DiskussionChip (💬 n / 💬@1) på kortet; flik "Diskussion" i Quick View (lager a)
ZON 4 — Möten:        närvaro-/lobby-prickar (lager c) på deltagaravatarer
(sidopanel)           Enhetschatt-trådar (team), öppnas på begäran — inte i huvudflödet
```

Den som inte vill chatta märker knappt lagret; den som lever i det får allt ärendekopplat. **Ingen andra
inkorg uppstår** eftersom den enda push-signalen är "någon nämnde mig", och allt annat är pull (jag öppnar
ärendet / enhetschatten när jag vill).

---

## 7. Sekretess: är ärende-chatt allmän handling?

Detta är den juridiskt laddade frågan och svaret måste vara hederligt.

**Utgångspunkt (gällande linje).** Intern chatt mellan handläggare på samma myndighet är som huvudregel
**inte allmän handling** — den är *inte inkommen* utifrån och *inte upprättad/expedierad* i TF:s mening, utan
**arbetsmaterial / mellanprodukt** (jfr Lawline/Universitetsläraren-resonemanget och kommunala
hanteringsregler för Teams-chatt). Den **interna ärende-chatten (lager a)** behandlas därför som
**arbetsmaterial som default** — precis som utkast och versionshistorik i ärenderummet.

**Men fyra viktiga undantag/nyanser (måste byggas in):**

1. **Kommunikation med *extern* part är allmän handling.** Samverkans-tråden (§3.4) och varje chatt där en
   utomstående myndighet/person deltar lutar mot **allmän handling** (inkommen/expedierad). Därför märks den
   externa tråden separat ("EXTERN — samverkan") och dess innehåll hanteras strängare (sekretessprövas,
   kan behöva diarieföras/committas).

2. **Innehållet kan "tippa över" till handling oavsett etikett.** Om en chatt-tråd i praktiken *innehåller*
   ett beslut, en bedömning eller en uppgift som **tillför ärendet sakuppgift** (t.ex. själva
   skyddsbedömnings-resonemanget, en överenskommelse), då är den uppgiften **dokumentationspliktig** (SoL
   2025:400) och hör hemma i journalen — det är inte etiketten "chatt" som avgör, utan *att uppgiften tillför
   ärendet något*. Hubs får aldrig låta ett journalpliktigt beslut leva *enbart* i chatten (samma rotrisk som
   GAP-001 för skyddsbedömningen). → därav **§8 (commit-bryggan)** och **§9 (UI-disciplin)**.

3. **Metadata/loggar blir sannolikt allmän handling.** Vem chattade med vem, när — den maskinella loggen är
   med stor sannolikhet allmän handling även om innehållet är arbetsmaterial. Det är *bra*: `activity` +
   sdkmc-loggen ger spårbarhet (vem deltog i en ärende-tråd) som är revisions-/tillsynsvärde, och den ska
   kunna lämnas ut (utan innehåll) på begäran.

4. **Chattverktyg är inte byggda för att skilja allmän/icke-allmän eller arkivexportera.** Den kända kommunala
   invändningen (Göteborg/KTH) gäller även oss: ett chattlager *får inte* bli oreglerad slutlagring av
   handlingar. Hubs svar är **mellanlagrings-doktrinen tillämpad på chatt**: chatten är arbetsyta, det som är
   handling **committas till Treserva** (där allmän/icke-allmän och arkiv hanteras korrekt), och
   **chatt-tråden gallras** enligt en uttrycklig regel i dokumenthanteringsplanen (DHP) — `files_retention`-
   logik utsträckt till konversationer, med **Retention-paus vid utlämnandebegäran** (jfr GAP-031).

**Nettoposition:** ärende-chatt (intern) = **arbetsmaterial, gallras med ärenderummet**; det som *är* en
handling (beslut, bedömning, extern kommunikation) **lyfts ur chatten och committas** — chatten lagrar aldrig
ensam en allmän handling. Detta är en **policy/juridik-fråga per kommun** (förankras i DHP, kommunjurist) —
Hubs *möjliggör* rätt hantering men *bestämmer inte* gränsen.

---

## 8. Hur relevanta beslut committas ur chatten till Treserva

Chatten är där beslut **mognar**; den får aldrig vara där de **bor**. Bryggan är medveten, manuell och
verifierad — aldrig automatisk skörd av chatt-text (det vore både hallucinations-/feltolkningsrisk och
arkivrisk).

**Mekanismen: "Lyft ur diskussionen → committa".** På valfritt meddelande i ärende-chatten finns en åtgärd
**"Gör detta till en handling"** (jfr Hub 25:s "påminn/markera viktigt på meddelande", samma interaktionsmönster):

1. Handläggaren markerar det/de meddelanden som *är* en bedömning/beslut/överenskommelse.
2. Hubs öppnar en **mall-styrd notering** (BBIC-/journalmall ur kunskapsbanken) **förifylld** med det valda
   innehållet som *utkast* — handläggaren **redigerar och formulerar den slutliga journaltexten** (chatt-
   slang blir myndighetstext; human-in-the-loop, jfr GAP-029-disciplinen).
3. Hon trycker **"För till Treserva"** → **`CommitGrind`** (skickat → bekräftat API-svar via Frends →
   registrerat). Vid bekräftad commit: noteringen blir journalanteckning i akten, `ProvenansChip` flippar,
   och **ett systemmeddelande postas tillbaka i chatt-tråden**: "Bedömningen committad till Treserva
   2026-06-14, dnr 2026-IFO-0142" (så tråden visar att handlingen *lämnat* chatten och nu bor i akten).

**Pliktmarkör-kopplingen (kritisk, knyter till redesignen).** Om ärendekortets **röda pliktmarkör** (t.ex.
okvitterad skyddsbedömning, GAP-001) är öppen, och resonemanget *fördes i chatten*, kan kortet **inte avancera
steppern** förrän bedömningen committats via flödet ovan. Chatten blir alltså en *naturlig* plats att bereda
plikten, men **`CommitGrind` är den enda vägen ut** — Retention/stepper-framsteg binds till verifierad commit,
aldrig till att "det stod ju i chatten" (GAP-007).

**Vad som *inte* committas:** rent arbetsprat ("bra jobbat", "tar fika"), bollplank som inte tillförde
ärendet sakuppgift, koordinering. Det gallras med tråden. Gränsdragningen är handläggarens (stödd av §9).

---

## 9. Sekretess- och disciplin-stöd i UI (så gränserna inte vilar på minnet)

- **Sekretess-/deltagar-rad på varje chattyta:** "3 deltagare · alla i barn-familj-enheten · OSL 26 kap. ·
  intern (arbetsmaterial)" resp. för extern tråd "⚠ EXTERN samverkan · kan vara allmän handling ·
  sekretessprövas". Deltagarkretsen = ACL, alltid synlig (ingen tyst tredje part).
- **Fas-spärr även i chatt:** under förhandsbedömning varnar tillägg av utomstående part i en ärende-/
  samverkans-tråd ("Endast vårdnadshavare/anmälare/barn i denna fas", GAP-006).
- **Mjuk "fel-plats"-vägledning:** sekretessbelagt innehåll i en 1:1 eller team-chatt → diskret hint
  "Gäller det ett barn? Lägg det i ärendechatten." (styr innehåll till rätt sekretess-/spårbarhets-kontext).
- **Inga klartextcitat i aviseringar:** Dagspuls-/omnämnande-signalen visar *referens*, inte chatt-innehåll
  (GDPR-dataminimering, samma regel som triage-radernas korttext).
- **Aldrig auto-commit, aldrig auto-radering av enda kopian:** commit är människo-bekräftad (§8); gallring
  av en tråd kräver att handlingar lyfts ut först, och **Retention pausas vid registrerad utlämnandebegäran**
  (GAP-031).

---

## 10. Sammanfattande karta: var och hur chatten startas i "Mina ärenden"

| Vill göra | Var i vyn | Knapp/interaktion | Lager |
|---|---|---|---|
| Diskutera ett specifikt barn med kollega/gruppledare | `ArendeKort` → flik **"Diskussion"** | Skriv i tråden; `@gruppledare` för omnämnande | (a) |
| Se att det finns ny diskussion på ett ärende | Kollapsat `ArendeKort` | `DiskussionChip` 💬 n / 💬@1 (pull, ingen push utom @) | (a) |
| Lyfta ett ärende till fördelning | `ArendeKort` → "Lyft till enhetschatt" | Postar avidentifierad referens i team-chatt | (a)→(b) |
| Fördela/diskutera dagens inflöde med gruppen | Zon V/foten → **"Enhetschatt ▸"** → mottagningsgruppen | Team-tråd; ev. "Starta fördelningsmöte" | (b) |
| Snabb riktad fråga till en person | Avatar var som helst → **1:1** | Direktmeddelande (ej ärendeinnehåll) | (c) |
| Se om något väntar på *mig* | Dagspulsen **`💬 omnämnanden`** | Klick → "väntar på mig"-vy | (c) |
| Se om gruppledaren är störbar | Lobby/deltagarlista | Närvaro-prick (🔴 upptagen/inne) | (c) |
| Göra ett chatt-resonemang till journal | Chatt-meddelande → **"Gör detta till en handling"** | Mall-utkast → redigera → `CommitGrind` → Treserva | (a)→commit |

**Designvakten genomgående:** chatt = **en räknare i pulsen + en flik på kortet + en lugn enhetsyta**. Aldrig
en trettonde widget, aldrig en andra inkorg, aldrig en tyst lagringsplats för allmänna handlingar.

---

## Implementering

**Vilka appar:**
- **`spreed`** (säker chatt, on-prem) — group-konversation per ärende (lager a), per team (lager b),
  one-to-one (lager c). Hub 25-funktioner vi lutar oss på: **trådning**, **chatt-dashboard
  (mentions/möten/påminnelser)**, **🔴 upptagen-närvaro**, påminn-/markera-på-meddelande. Kräver **HPB** för
  skalning/grupp/lobby (samma backend-beroende som mötesinspelning); P2P räcker för demo av 1:1/små trådar.
- **`circles` (Team)** — modellerar mottagningsgrupp/utredningsgrupp/enhet; **en** medlemskapssanning som
  delas med ärenderums-ACL, kunskapsbank och team-chatt (provisioneringshygien).
- **`groupfolders` + ACL** — ärende-chattens deltagarkrets **speglar** ärenderummets ACL (en sekretessgräns).
- **`flow`/`workflow_engine`** — automation: posta systemmeddelande i ärende-chatt när en handling committas/
  en frist tippar (Flow kan posta i en Talk-konversation som åtgärd); auto-skapa enhets-/ärende-konversation
  vid team-/ärende-skapande; sätta gallrings-tagg på tråd. (Flow↔Talk-åtgärd "skicka meddelande till rum" är
  native; trigger-på-sdkmc-händelse är Hubs egen IEntity, jfr native-apps-map §14.)
- **`files_retention`-logik (utsträckt)** — gallringsregel för chatt-trådar per DHP; **Retention-paus** vid
  utlämnandebegäran.
- **`activity`** + sdkmc-logg — deltagar-/händelselogg per tråd (metadata = spårbarhet, sannolikt allmän
  handling; utlämnbar utan innehåll).
- **Inera/`libresign` & Frends** — oförändrat: chatten *bereder* beslut, signering sker i `attSignera`,
  commit till Treserva via **Frends** (`CommitGrind`).

**Vad i Flow:**
- Trigger *ärende skapat* → action: skapa group-konversation (namn = ärendereferens, deltagare = ACL-krets),
  registrera `dnr ↔ conversationToken` i Hubs mappningstabell.
- Trigger *team skapat* (Circle) → action: skapa stående enhetskonversation kopplad till teamet.
- Trigger *handling committad till Treserva* (Frends-callback) → action: posta systemmeddelande i
  ärende-chatten ("committad, dnr X") + sätt/uppdatera tråd-status.
- Trigger *frist tippar (T-0)* / *omnämnande till tilldelad* → action: höj `omnamnandeTillMig`-flagga
  (driver Dagspuls-räknare + ev. Zon 2-lyft). Ingen action skördar chatt-text automatiskt.

**Vad programmatiskt (Hubs-eget, ovanpå native):**
- **Mappningstabell `dnr ↔ conversationToken ↔ groupfolderId ↔ Treserva-dnr`** — eftersom Talk saknar native
  objektbindning till dnr (API:t stöder bara `objectType=room`); Hubs äger relationen (löser samtidigt
  GAP-005/041 för chatt).
- **`DiskussionChip`** + flik "Diskussion" i `ArendeKort`; `zonOf`-utvidgning
  (`diskussion.omnamnandeTillMig → 'het'`); Dagspuls-räknare `💬 omnämnanden` (räknar mentions/1:1, **ej** rå
  olästa).
- **"Gör detta till en handling"-flödet:** markera meddelande(n) → mall-förifylld notering → human-in-the-
  loop-redigering → `CommitGrind` (Frends) → journalanteckning + systemmeddelande tillbaka i tråd. **Hård
  spärr:** pliktmarkör/stepper-framsteg bundet till verifierad commit, aldrig till chatt-existens (GAP-007).
- **Sekretess-/deltagar-rad, fas-spärr i chatt, "fel-plats"-hint, klartext-fria aviseringar** (UI-disciplin).
- **Gallrings-/paus-orkestrering** för trådar (DHP-konfigurerbar; paus vid utlämnande).
- **Per kund/DHP (policy, ej kod):** gränsen arbetsmaterial vs allmän handling för chatt; vad som ska
  diarieföras; gallringsfrist för trådar — förankras med kommunjurist.

## UI i socialsekreterarvyn

- **Dagspulsen (Zon 0):** en ny räknare **`💬 {n} omnämnanden`** (ikon + tal + text). Räknar *mig-omnämnda +
  1:1*, aldrig rå olästa → chatten kan inte bli en växande röd inkorgs-siffra. Klick = filtrera "väntar på
  mig".
- **Ärendekort (Zon 2/3) — kollapsat:** liten **`DiskussionChip`** `💬 3` (olästa, neutral) eller
  **`💬 @1`** (omnämnande till mig, accent). Endast omnämnande kan höja kortet till "Kräver åtgärd nu".
- **Ärendekort — Quick View:** fliken **"Diskussion"** (intern ärende-chatt, lager a) bredvid **"Säkra
  meddelanden"** (extern, kvittensbärande). Överst i fliken: sekretess-/deltagar-rad
  ("3 deltagare = ärenderummets ACL · OSL 26 kap. · intern arbetsmaterial"). I tråden: Hub 25-trådning,
  `@`-omnämnanden, "Lyft till enhetschatt", och per meddelande **"Gör detta till en handling → För till
  Treserva"** (→ `CommitGrind`, systemkvitto tillbaka i tråden).
- **Enhetschatt (lager b):** diskret **"Enhetschatt ▸"-ingång** i Zon V/foten → lugn sidopanel med
  team-trådarna (mottagningsgruppen, utredningsgrupp, enheten) och "Starta fördelningsmöte". **Aldrig** ett
  kort i ärendeströmmen.
- **1:1 + närvaro (lager c):** direktmeddelande från valfri avatar; **närvaroprick** (🔴 upptagen/inne) på
  deltagar-/lobbyavatarer bredvid `identitetsBadge`s LOA-bock. 1:1 visar mjuk hint mot ärendechatt vid
  sekretessinnehåll.
- **Genomgående:** chatt = **flik + chip + pulsräknare + lugn sidoyta**, ärendekopplad och sekretessmärkt —
  så att Hubs säkra Teams-ersättare *premierar* samordningen utan att återföda inkorgen.

---

## Källor

**Nextcloud Talk (chatt) i Hub 25 Autumn / v32 — trådning, dashboard, närvaro, API**
- Hub 25 Autumn release (threaded Talk, live subtitles, ny UI): https://nextcloud.com/blog/nextcloud-hub25-autumn/
- Hub 25 Autumn sovereignty/press: https://nextcloud.com/blog/press_releases/hub-25-autumn/
- "The quiet work chat app" (trådar, dashboard, viktiga konversationer, 🔴 upptagen): https://nextcloud.com/blog/the-quiet-work-chat-app/
- Community: trådar + dashboard (mentions/möten/påminnelser): https://help.nextcloud.com/t/the-quiet-work-chat-app-threads-dashboard-and-important-conversations-in-nextcloud-talk/236165
- AlternativeTo: Hub 25 Autumn (threaded Talk m.m.): https://alternativeto.net/news/2025/9/nextcloud-hub-25-autumn-release-brings-new-ui-threaded-talk-office-enhancements-and-more
- Talk Conversation API (POST /room, roomType 1/2/3, objectType/objectId, readOnly, listable): https://nextcloud-talk.readthedocs.io/en/latest/conversation/
- Talk capabilities/constants (lobby, recording, presence): https://nextcloud-talk.readthedocs.io/en/latest/capabilities/

**Circles / Teams (grupper)**
- Teams (formerly Circles) — delat medlemskap för andra appar: https://github.com/nextcloud/circles/blob/master/README.md
- App Store — Teams (circles): https://apps.nextcloud.com/apps/circles
- Contacts/groups/teams/circles i NC32 (begreppsförhållande): https://help.nextcloud.com/t/understanding-contacts-groups-teams-circles-nc32/242195

**Flow / workflow_engine (automation, Talk-åtgärd, tagg/Retention)**
- Nextcloud Flow (event-baserad automation, Files/Talk-integration): https://nextcloud.com/flow/
- Flow: skicka Talk-meddelande som åtgärd vid filhändelse: https://nextcloud.com/blog/how-to-automate-business-processes-with-nextcloud-flow-your-workflow-automation-assistant/
- Automated tagging (driver Retention/access control): https://docs.nextcloud.com/server/stable/admin_manual/file_workflows/automated_tagging.html
- Tagging and Workflows (Portal): https://portal.nextcloud.com/article/Operations/Tagging-and-Workflows

**Svensk socialtjänst-organisation (mottagningsgrupp/utredningsgrupp, gruppledare/1:e ssk, fördelning)**
- Örebro IFO mottagningsgruppen (6 ssk + gruppledare; mottagning→överlämning till utredning): https://vakanser.se/jobb/socialsekreterare+till+ifo+mottagningsgruppen/
- Stockholm utredningsgrupp 0–20 år (9 ssk + gruppledare): https://vakanser.se/jobb/socialsekreterare+i+utredningsgrupp+0-20+ar+2/
- Stockholms stad — mottagningsgrupp (fördelning, specialist-/mentorsfunktion): https://jobba.stockholm/lediga-jobb/platsannonser/socialsekreterare-till-mottagningsgrupp-pa-kungsholmen-857838
- Danderyd — mottagningsgrupp socialförvaltningen: https://ledigajobb.se/jobb/a5232e/socialsekreterare-i-mottagningsgruppen-till-socialf%C3%B6rvaltningen-danderyds

**Sekretess: chatt som (icke) allmän handling, bevarande/gallring, diarieföring**
- Lawline — är chattar offentlig handling? (Teams-chatt i regel ej allmän handling): https://lawline.se/answers/ar-chattar-en-offentlig-handling
- Universitetsläraren — Teams/Zoom och insyn (extern kommunikation = allmän handling): https://universitetslararen.se/2021/02/11/teams-och-zoom-okar-allmanhetens-inblick/
- KTH — hanteringsregler Slack-kanaler (extern kommunikation = allmän handling): https://intra.kth.se/it/arbeta-pa-distans/chatt/slack/regler-och-rekommen/hanteringsregler-for-slack-kanaler-1.1155470
- Göteborg — bevarande och gallring av chattmeddelanden i Teams (chattverktyg ej byggt för allmän handling; mötesdok/chatt raderas ~6 mån): https://goteborg.se/wps/wcm/connect/f5876975-d75f-44e9-ad39-a302b4714f21/GBG-AN-00660-23-Chattmeddelanden.pdf
- CSN — offentlighetsprincipen (allmän handling-rekvisit): https://www.csn.se/om-csn/lag-och-ratt/offentlighetsprincipen.html

*Grundas i: `SOCIALSEKRETERARE-WALKTHROUGH.md`, `GAP-ANALYSIS.md`, `WIDGET-APP-MAP.md`, `native-apps-map.md`,
`arendehantering-map.md`, `persona-usage-socialsekreterare.md`, `UX-REDESIGN-SOCIALSEKRETERARE.md`,
`hubs_start/src/services/widgetApps.js`. Varumärkesregel: aldrig "Nextcloud"/"Talk"/"Circles" i UI-text.*
