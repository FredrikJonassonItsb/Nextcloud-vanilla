# Hubs mallbibliotek — Kunskapsbank & mallar

Mallbibliotek för kommunala handläggare i **Hubs**. Två delar med tydliga roller:

- **Maskinrummet — Filer + inbyggda mallfunktionen.** De riktiga, ifyllbara
  dokumentmallarna är **`.docx`** i mappen [`Mallar/`](Mallar/). De görs tillgängliga
  via en **delad mallmapp** så att handläggaren skapar nya dokument direkt med
  **+ Ny → Ny fil från mall** i Filer. Biblioteket är **live** — ändra en mall, alla ser
  ändringen.
- **Skyltfönstret — Collectives (valfritt).** En snyggt formaterad **handbok** som
  beskriver varje mall (när den används, lagrum, rubriker) och länkar till mallmappen.
  Collectives lagrar **inte** Office-mallarna (dess sidor är Markdown) — det är översikten.

> [!IMPORTANT] **Lagrum måste verifieras**
> Sedan **1 juli 2025** gäller en ny **socialtjänstlag (SoL 2025:400)** med ändrad
> kapitel- och paragrafnumrering jämfört med tidigare **SoL (2001:453)**. Mallarna
> namnger den rättsliga institutionen (stabil) men **markerar alla §-hänvisningar med
> `[verifiera]`**. Innan biblioteket används skarpt ska lagrummen verifieras mot
> tillämplig lag och kommunens rutiner, och mallarna granskas av verksamhetsjurist.
> Mallarna är **utkast (v1.0)**.

## Struktur

| Fil / mapp | Roll |
|---|---|
| [`socialsekreterare-barn-familj/`](socialsekreterare-barn-familj/) | **Källa/handbok** — 18 mallar i Markdown (se [översikten](socialsekreterare-barn-familj/00-oversikt.md)). Lätt att granska och versionshantera. |
| [`Mallar/`](Mallar/) | **De riktiga .docx-mallarna** — genereras ur Markdown-källan, läggs i Nextclouds mallmapp. |
| [`ANALYS-dokumenttyper-socialsekreterare.md`](ANALYS-dokumenttyper-socialsekreterare.md) | Den grundliga analysen — vilka dokument professionen använder, i vilken ordning, på vilken rättslig grund. |
| [`MALL-STANDARD.md`](MALL-STANDARD.md) | Husstandarden mallar följer. |
| [`scripts/`](scripts/) | `build-docx.sh` (Markdown→.docx) · `setup-template-folder.sh` (mallmappen, live) · `publish-handbook.sh` (Collectives-handbok). Se [`scripts/README.md`](scripts/README.md). |

## Så rullar du ut biblioteket

```bash
cd hubs_start/mallbibliotek/scripts

# 1) Bygg .docx-mallarna ur Markdown-källan (kräver pandoc):
./build-docx.sh                       # -> Mallar/<profession>/NN Titel.docx

# 2) MASKINRUMMET: gör mallmappen tillgänglig (live, delad mapp):
./setup-template-folder.sh --dry-run                      # se vad som händer
./setup-template-folder.sh --container nextcloud-app \
    --group mallar-anvandare --users "anna eva"           # dela + peka mallmapp

# 3) SKYLTFÖNSTRET (valfritt): publicera handboken i Collectives:
./publish-handbook.sh --container nextcloud-app
```

Efter steg 1–2 skapar handläggaren nya dokument via **Filer → + Ny → Ny fil från mall**.
Mallväljaren läser mallmappen **live och rekursivt**, så alla `.docx` i undermapparna dyker
upp. Uppdatera en mall = kör om steg 1 och lägg tillbaka filen — ändringen syns direkt.

### Två fallgropar (verifierade i NC-koden, undvikna av skripten)
- En mallmapp **inuti en Group folder/Teammapp** triggar inte mallförslagen tillförlitligt
  → skripten använder en **vanlig delning** i stället.
- **`skeletondirectory` / systemmallmapp** (`templatedirectory`) seedar bara **nya**
  användare vid kontoskapande → olämpligt för ett bibliotek som utvecklas. Därför:
  **delad mapp som mallmapp** = live.

## Professioner

Omgång 1 är byggd **på djupet för socialsekreterare barn & familj** — primär byggpersona
(K-1.13). Samma mönster (analys → rika mallar → .docx → mallmapp) återanvänds för de övriga
rollerna (K-1.12): registrator, HSL, HR/chef, överförmyndare, IT/dataskydd, skola/elevhälsa,
omsorgsutförare, bygglov/miljö, upphandling, säkerhet/beredskap, kommunjurist/vissel,
medborgarservice.

## Principer

- **Rika mallar** — ifyllbara fält `[…]`, kursiv handledning som raderas, lagrum,
  strukturerade rubriker (BBIC).
- **Barnrättsperspektiv & sekretess** — barnets bästa och barnets röst; dataminimering
  och menprövning vid informationsdelning.
- **Handling vs arbetsmaterial** — commit till facksystem gör arbetsmaterial till
  förvarad allmän handling.

---
*Hubs mallbibliotek · v1.0 (utkast) · 2026-07-06. Kräver granskning av verksamhetsjurist
innan produktiv användning.*
