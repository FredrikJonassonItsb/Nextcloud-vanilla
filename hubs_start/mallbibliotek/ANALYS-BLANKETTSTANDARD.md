---
titel: Analys — myndighetsblankett-standard för mallbiblioteket
status: v1.2 (2026-07-07) — S1+S2+S3+S-ingress+S4 IMPLEMENTERADE o deployade (motor v0.13.0, hubs_start v1.6.0, dev15). MOMENTET KOMPLETT.
referens: Konsumentverkets blankett "Underrättelse om olycka/tillbud inom tjänsteområdet" (v1.3)
relaterat: MALL-STANDARD.md §1b, ANALYS-FORIFYLLNAD-FALTKARTLAGGNING.md, scripts/restyle-docx.php
---

# Analys — vad krävs för äkta myndighetsblankett-standard?

Fredriks styrning (2026-07-07, med Konsumentverkets blankett som referens):
mallarna ska vara **proffsigare och mer myndighetsbetonade**, med **rutor för
ifyllnad** — och den exporterade PDF:en får **inte** visa gråa rutor där fält
fyllts i automatiskt.

## 1. Referensstandarden dekonstruerad (Konsumentverket-blanketten)

| # | Designelement | Så ser det ut |
|---|---|---|
| R1 | **Sidhuvud** | Myndighetslogotyp vänster · sidnummer "1 (2)" + "Version 1.3" + datum höger (kursiv, diskret) |
| R2 | **Dokumenttitel** | Stor fetstil, vänsterställd |
| R3 | **Rättslig ingress i RAM** | Inramad textbox med lagrumstexten ("Enligt 23 § Produktsäkerhetslagen (2004:451) ska…") — normal textstorlek, **står kvar i den ifyllda handlingen** |
| R4 | **Ifyllnadsinstruktion** | Kort rad utanför ram ("Blanketten fylls enklast i digitalt. Cellerna expanderar…") |
| R5 | **Sektionsrubriker** | Fetstil ("Uppgifter om anmälande företag") |
| R6 | **FÄLTTABELLER** | Kantlinjerade tabeller; varje cell = **etikett + tom skrivyta** ("Datum:", "Företagsnamn:"); tvåkolumnslayout för korta fält, helradsceller för fritext; Ja/Nej-frågor med följdfråga i högercellen |
| R7 | **Rent vitt** | Inga skuggningar — vita celler, tunna kantlinjer; tomma fält är TOMMA (inga platshållartexter i utskrift) |

## 2. Nulägesgap (vår genererade docx/PDF vs referensen)

| Element | Nuläge | Gap |
|---|---|---|
| R1 sidhuvud | Saknas helt | Ingen logotyp/version/sidnummer — största enskilda "myndighetskänsle"-gapet |
| R2 titel | ✅ H1 finns | — |
| R3 ingress | *Om mallen*-blocket är **blå 8pt klipp-text** | Ska vara **inramad ingress i normal storlek som står kvar** (den bär lagrummet!) |
| R4 instruktion | *Så här fyller du i* som blå 8pt | ✅ rätt tänkt (klipps/ignoreras) — kan förbli blå eller bli R4-rad |
| R5 rubriker | ✅ numrerade H2 | — |
| R6 fälttabeller | Fält ligger **inline i löptext** ("**Barnets namn:** `[ruta]`") | Ska vara **tabellceller med etikett + skrivyta** — strukturell layout, inte teckenstil |
| R7 rent vitt | Grå skuggade rutor med synlig platshållartext; **ifyllda värden behöll rutan** | Ifyllnad: **FIXAT** (S1, motor v0.11.2). Tomma fält: platshållaren syns i utskrift — ska vara tom skrivyta (löses av S2 dold token) |

## 3. Lösningsspår — vad som krävs

### S1 — Ren ifyllnad ✅ IMPLEMENTERAD (motor v0.11.2, denna session)
`DocxFyllningsMotor::stadaFyldaRuns()`: när en platshållare ersätts strippas
fält-markeringen (`w:bdr` + `w:shd`) från den ifyllda runnen — värdet blir ren
dokumenttext i PDF-exporten. Ofyllda fält behåller sin ruta (de ÄR fält).
Idempotent, fail-safe (preg-fel ⇒ orört dokument), regressionstestad.

### S2 — Blankett-tabeller (kärnan i myndighetslooken)
**Vad:** post-processorn (`restyle-docx.php`) konverterar fältmönstren till
riktiga `w:tbl`-strukturer i Konsumentverket-stil:

- Styckesekvenser av mönstret `**Etikett:** [platshållare]` → tabellrader med
  kantlinjer; korta par läggs i tvåkolumnsceller (Datum | Kontaktperson-mönstret).
- Fritextfält (`[Beskriv …]` under en rubrik) → helradscell med etikettrad +
  tom skrivyta (fast cellhöjd ~3 rader; cellen expanderar vid ifyllnad).
- Kryssgrupper → cell med kryssraderna (fungerar redan som text).

**Platshållar-strategin (nyckelbeslutet):** tokenen flyttas in i värdecellen som
**dold text** (`w:vanish`):
- Utskriven tom blankett = **tomma celler** (R7 uppfylls — inga klamrar syns).
- Motorn hittar och ersätter fortfarande exakt (dold text är vanlig text i XML)
  och tar bort `w:vanish` vid ifyllnad (samma mönster som S1-städningen).
- Handläggare som fyller manuellt skriver i den tomma cellen.
- Alternativ (enklare men sämre): behåll synlig token — då visar en utskriven
  blank blankett `[För- och efternamn]` i cellerna. **Rekommendation: dold.**

**Krav:** tabellbyggare i restyle-docx.php (styckegruppering → w:tbl/w:tr/w:tc
med tblBorders), motor-tillägg (`w:vanish`-borttagning vid fyllnad — 5 rader),
uppdaterade XML-audits i verifieringen. **Risk:** pandoc-styckestrukturens
variation mellan mallar (hanteras: mönstren är standardiserade via MALL-STANDARD);
Collabora renderar enkla tabeller väl — undvik nästlade tabeller. **Effort:**
det största spåret — men deterministiskt och testbart per mall.

### S3 — Sidhuvud/sidfot (billigast, störst omedelbar effekt)
**Vad:** injicera `word/header1.xml` + `word/footer1.xml` (+ rels,
[Content_Types], sectPr-referens) i varje mall:
- Vänster: **kommunnamn/logotyp-platshållare** (text nu; bild per kommun = konfig, fas 2).
- Höger: "Version {mallens version}" + genereringsdatum + **sidnummer "X (Y)"**
  (PAGE/NUMPAGES-fält).
- Sidfot: dokument-id (mallslug + kortRef vid generering — spårbarhet).

**Krav:** header/footer-mall i post-processorn + tre XML-delar per docx.
Motorn kan fylla `[kortRef]`/datum i sidfoten vid generering (tokens funkar i
header/footer-parts också — motorn måste då läsa fler parts än document.xml:
**liten motor-utökning**, iterera word/header*.xml + footer*.xml). **Effort:** litet.

### S4 — Mallen som data ✅ IMPLEMENTERAD (motor v0.13.0 + hubs_start v1.6.0)
Levererat (pragmatisk S4 — mallarna ÄR nu data):
- **Malldefinitioner autogenereras vid varje bygge** (`generera-malldefinitioner.php`
  → `Mallar/<profession>/Definitioner/<mall>.json`: mallId, titel, tokens[], falt[]) —
  skannade ur de byggda blanketterna (document + header/footer-parts) ⇒ alltid i synk.
- **Per-mall-filtrerat utkast:** `byggUtkast($ref, $mallId)` filtrerar fälten mot
  definitionens tokens — förhandsdialogen visar bara fält mallen faktiskt har
  (kallelsen: 4 fält i stället för 11). Graceful: saknad definition ⇒ ofiltrerat.
- **Konfig-driven branding:** nytt fält `kommunNamn` (app-config `blankett_kommun`,
  källa `konfig`) auto-mergeas vid generering och fyller sidhuvudets brand-slot —
  per-instans branding utan mallombyggen.
- StatusService visar folkbokforing-porten (kvarpunkt stängd).

**Medvetet framflyttat till native-Filer-fasen (Strategi A'):** native content
controls (w:sdt) — Collaboras sdt-stöd är ojämnt och NC:s egen template-fields-
mekanik (BeforeGetTemplates/FileCreatedFromTemplate) gör lyftet där; definitions-
filerna är förberedda som schema. Logotyp som BILD per kommun = konfig-utbyggnad
(text-slot fylls redan).

### S-ingress — *Om mallen* blir inramad ingress (liten justering i S2)
Blockquote #1 (*Om mallen*, med lagrum/frist/handlingsstatus) ändras från blå
8pt-klipptext till **inramad ingress i normal storlek** (Konsumentverkets R3) —
den STÅR KVAR i den färdiga handlingen och bär lagrumshänvisningen. Endast
blockquote #2 (*Så här fyller du i*) förblir blå klipp-/instruktionstext.
Teknik: paragraph borders (`pBdr`) på BlockText-stycke #1 per mall.

## 4. Rekommenderad ordning

| Steg | Spår | Motiv |
|---|---|---|
| 1 | **S1** ✅ klar | Fredriks konkreta PDF-klagomål — fixat i motor v0.11.2 |
| 2 | **S3 sidhuvud/sidfot** | Litet, ger omedelbart "riktig blankett"-intryck |
| 3 | **S2 fälttabeller + dold token + S-ingress** | Kärnan — kräver mest bygge |
| 4 | **S4 generator** | När S2-mönstren satt sig; återanvänder fältkartläggningen |

## 5. Beslutspunkter (Fredrik)

1. **Dold token i cellerna** (ren utskrift, rekommenderas) vs synlig klammer?
2. **Ingressen:** inramad + kvar i handlingen (rekommenderas — bär lagrummet) vs klipp-text som idag?
3. **Handledningen i övrigt:** blå 8pt som idag, eller flytta till Word-kommentarer när tabellerna ändå byggs om? (blå rekommenderas fortsatt — renderas lika överallt)
4. **Per-kommun-logotyp:** text-platshållare nu och bild via konfig senare (rekommenderas), eller bild direkt?
