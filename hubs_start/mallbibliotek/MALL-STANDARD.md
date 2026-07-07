---
mall: mall-standard
titel: Mallstandard — hur en Hubs-mall är uppbyggd
profession: Gäller alla professioner i mallbiblioteket
version: 1.0
---

# Mallstandard för Hubs mallbibliotek

Detta dokument är **kontraktet** som varje mall i biblioteket följer. Syftet är att en
handläggare ska känna igen sig oavsett vilken mall hen öppnar, och att mallarna ska gå
att instansiera direkt in i ett ärenderum (Groupfolder) från Collectives (jfr
Walkthrough V2, Steg 25).

Skriv nya mallar mot detta kontrakt. Avvik bara med god anledning.

---

## 1. Filformat & placering

- **Markdown (`.md`) är källan**, en fil per dokumenttyp. UTF-8, svenska. Lätt att
  granska och versionshantera; utgör även handboken (Collectives-skyltfönstret).
- Filnamn: `NN-kort-slug.md` (tvåsiffrigt löpnummer som speglar arbetsflödets ordning).
- En profession = en mapp. Källmallar ligger i `<profession>/`.
- **De riktiga mallarna är `.docx`** och genereras ur Markdown-källan med
  `scripts/build-docx.sh` (pandoc, **`-smart` avstängt** — typografiska citattecken
  bryter fyllningsmotorns strängexakta matchning) till `Mallar/<profession>/NN Titel.docx`.
  De läggs i Nextclouds **mallmapp** (Filer → + Ny → Ny fil från mall) via
  `scripts/setup-template-folder.sh`. Redigera alltid Markdown-källan och bygg om — inte
  .docx:en direkt.

## 1b. Myndighetsblankett-transformen (Konsumentverket-standard)

Efter pandoc kör `build-docx.sh` automatiskt `scripts/restyle-docx.php` v2 (idempotent
via markör, docker composer:2) som bygger om .docx:en till officiell blankett
(referens: Konsumentverkets blanketter; se `ANALYS-BLANKETTSTANDARD.md`):

- **Fälttabeller:** konsekutiva fältstycken (`**Etikett:** [token]` och fristående
  fritext-tokens) byggs om till **kantlinjerade tabeller** — etikett + tom skrivyta
  per cell; korta etikettfält paras två per rad, långa/fritext får helradscell.
- **Dold token:** ifyllnadstokens är **osynliga** (`w:vanish`) — en utskriven blank
  blankett visar tomma celler, men `DocxFyllningsMotor` hittar och ersätter
  fortfarande, och gör värdet **synligt** vid ifyllnad (vanish + ev. markering
  strippas från fyllda runs). Undantag: kryssrutor `[ ]`/`[x]` orörda;
  **`[verifiera …]`-markörer förblir SYNLIGA** med grå ruta (redaktionella flaggor
  som ska lösas av jurist); tokens i blå instruktionstext är exempel och lämnas.
- **Inramad ingress:** första blockcitatet (*Om mallen* — bär lagrummet) får **ram i
  normal storlek och STÅR KVAR** i handlingen (myndighetsblankettens rättsliga
  ingress). Endast *Så här fyller du i* + kursiv handledning är blå 8 pt-klipptext;
  sidfoten undantas.
- **Sidhuvud/sidfot:** vänster `[Kommunens namn]` (brand-slot — bild per kommun är
  konfig senare), höger version + byggdatum + "Sida X (Y)"; sidfot: mallnamn +
  "Ärende: `[hubsCaseId / Treserva-dnr]`" (dold token — motorn fyller
  ärendereferensen vid generering; motorn bearbetar även header-/footer-parts).
- Befintliga tabeller (underskrift m.fl.) får kantlinjer och dolda tokens.

I Markdown-källan ändras ingenting — konventionerna i §3 är oförändrade; hela
blankett-transformen är ett byggsteg.

## 2. Obligatorisk struktur (i denna ordning)

1. **YAML-frontmatter** — maskinläsbar metadata:
   ```yaml
   ---
   mall: <slug>                     # = filens slug utan nummer
   titel: <Dokumentets titel>
   profession: Socialsekreterare – barn och familj (IFO)
   kategori: <arbetsflödesfas, t.ex. "Utredning (BBIC)">
   version: 1.0 (utkast)
   handlingsstatus: <allmän handling | arbetsmaterial | blir allmän handling vid ...>
   rattslig_grund: <lagrum — MÅSTE verifieras, se §5>
   ---
   ```
2. **H1-titel** — samma som `titel`.
3. **Om-mallen-block** (blockcitat direkt under H1):
   > **Om mallen** — en till två meningar: när används den, vem fyller i, vad blir resultatet.
   >
   > ⚖️ **Lagrum:** … · ⏱️ **Tidsfrist:** … (om relevant) · 📄 **Handlingsstatus:** …
4. **Ifyllnadsnot** (Markdown-callout):
   > [!NOTE] **Så här fyller du i**
   > Ersätt text i `[hakparentes]`. Rader i _kursiv_ är handledning — radera dem i den
   > färdiga handlingen. Kryssrutor: sätt `x` i `[ ]`. Verifiera lagrum mot din kommuns
   > rutin och tillämplig SoL-version (se README).
5. **Numrerade sakavsnitt** (`## 1.`, `## 2.` …) med fält och handledning (se §3).
6. **Underskrift/dokumentinformation** — vem, när, roll, ev. delegation/diarienr.
7. **Sidfot** — versions- och granskningsdisclaimer (se §6).

## 3. Fält, handledning och kryssrutor

- **Ifyllnadsfält:** `[Beskrivande fältnamn]` — hakparentes med vad som ska stå.
  Exempel: `**Barnets namn:** [För- och efternamn]`.
- **Handledning:** _kursiv_, kort, konkret. Förklarar vad avsnittet ska innehålla eller
  vilken bedömning som ska göras. Ska kunna raderas utan att lämna hål.
- **Val/checklistor:** Markdown-kryssrutor `- [ ] Alternativ`.
- **Upprepningsbara block** (t.ex. flera barn, flera kontakter): markera med
  `_(kopiera blocket vid behov)_`.
- Undvik tabeller för fritext; använd dem bara för strukturerad data (datum, parter).

## 4. Ton och innehåll

- Myndighetssvenska, klarspråk. Du-tilltal i handledningen, sakligt i mallfälten.
- **Barnrättsperspektiv** genomgående för barn- och familjemallar: barnets bästa,
  barnets rätt att komma till tals (Barnkonventionen, lag 2018:1197).
- **Sekretess- och dataminimering:** mallen ska påminna om att bara nödvändiga
  personuppgifter dokumenteras, och att inre sekretess/menprövning gäller vid
  informationsdelning. PII visas bara för behörig handläggare — aldrig i avidentifierade
  referenser (jfr enhetschatt).
- Ingen mall får uppfinna lagrum, SOSFS-nummer, rättsfall eller siffror som inte är
  belagda. Osäkert lagrum → skriv `[verifiera lagrum]`.

## 5. Lagrum — den viktiga varningen

Kravdokumenten refererar historiskt till **SoL (2001:453)** (t.ex. 11 kap. 1 a §
skyddsbedömning, 4 kap. 1 § bistånd, 14 kap. 1 § anmälan). Sedan **1 juli 2025** gäller
en **ny socialtjänstlag (2025:400)** där kapitel- och paragrafnumreringen är ändrad.

**Regel för mallarna:**
- Referera institutionen vid namn (t.ex. "omedelbar skyddsbedömning",
  "utredning enligt socialtjänstlagen") — namnet är stabilt.
- Ange paragrafhänvisning i `rattslig_grund` och i ⚖️-raden, men **markera att den ska
  verifieras**: `SoL [verifiera kap./§ mot 2025:400]`.
- Lagar utanför SoL-recodifieringen är stabilare och kan anges med större säkerhet men
  fortfarande med verifieringsnot: **FL (2017:900)**, **OSL (2009:400)**,
  **HSL (2017:30)**, **LVU (1990:52)**, **Barnkonventionen (2018:1197)**,
  **SOSFS 2014:5** (dokumentation), **förvaltningslagens** kommunicerings- och
  överklaganderegler.

## 6. Obligatorisk sidfot

Varje mall avslutas med:

```
---
*Hubs mallbibliotek · <profession> · Mall v1.0 (utkast).*
*Lagrumshänvisningar ska verifieras mot tillämplig socialtjänstlag (SoL 2025:400) och
kommunens egna rutiner innan produktiv användning. Granskas av verksamhetsjurist.*
```

## 7. Länkning i biblioteket

- Länka till relaterade mallar med relativ länk: `[Vårdplan](12-vardplan.md)`.
- Översiktssidan (`00-oversikt.md`) är navet — varje mall länkas därifrån i
  arbetsflödesordning.
