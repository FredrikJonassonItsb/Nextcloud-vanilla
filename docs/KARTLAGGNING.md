# Kartläggning: Nate B. Jones "Open Stack"

**Referensdokument för ITSL-teamet** (Fredrik, Rebecca, Sandra, Mattias)
**Sammanställt:** 2026-07-04
**Underlag:** Samtliga sidor på unlock-ai.natebjones.com (Open Stack Field Guide, Open Engine-guiden, alla 8 Open Skills-kategorisidor, runbooks-sidan, guides-indexet, byggguiderna, Agent Maintenance Loop), OB1-repot på GitHub (kärndokumentation, server, schemas, integrations, skills, recipes, primitives, dashboards) samt öppen källforskning (Substack, YouTube, tredjepartsanalyser).

**Syfte:** Fullständig karta över systemet som grund för en egen ITSL-anpassning. Engelska tekniska tokens (AGENT CLAIMED, Agent Todo, pass/needs_review/fail m.fl.) behålls på engelska — de är protokollvokabulär.

---

## Innehåll

1. [Översikt: vad Open Stack är](#1-översikt-vad-open-stack-är)
2. [Open Skills i detalj](#2-open-skills-i-detalj)
3. [Open Brain / OB1 i detalj](#3-open-brain--ob1-i-detalj)
4. [Open Engine i detalj](#4-open-engine-i-detalj)
5. [Underhållsloopen och självförbättringsmönstren](#5-underhållsloopen-och-självförbättringsmönstren)
6. [Hur delarna komponerar](#6-hur-delarna-komponerar)
7. [Källor och länkar](#7-källor-och-länkar)

---

# 1. Översikt: vad Open Stack är

## 1.1 Grundtesen

Nates kärnformulering: **"Rented intelligence on top, owned context underneath."** Du hyr frontier-modellerna (Claude, GPT, Gemini — utbytbara via MCP); du äger de tre lagren under dem:

> "Open Brain holds your memory, Open Skills holds your method, Open Engine moves the work."
> — Substack, 2026-07-01

| Primitiv | Vad den är | Löser flaskhalsen | Substans |
|---|---|---|---|
| **Open Skills** | Återanvändbara operativa procedurer som lär agenten hur ett visst slags arbete ska göras | **Capability** — agenten är nära men inte pålitlig | Textfiler/prompts (SKILL.md), ingen databas |
| **Open Brain (OB1)** | Beständigt minnes-/kontextlager: en databas som alla AI-klienter läser/skriver via MCP | **Context** — samma saker förklaras om och om igen | Postgres + pgvector + Edge Functions, öppen källkod |
| **Open Engine** | Arbetsförflyttningslagret: uppgifter, ägarskap, statusar, godkännanden, kvitton, överlämningar | **Work movement** — arbete försvinner mellan chattar | Ett protokoll ovanpå Linear (issue-trackern), inte kod du hostar |

Viktigt: det finns ingen produkt som heter "Open Stack". De tre komponenterna lanserades och underhålls separat; "Open Stack" är namnet på **fältguiden** (routing-guiden) som binder ihop dem.

## 1.2 Filosofin: diagnostisera flaskhalsen först

Fältguidens kärnbudskap (verbatim): *"Most AI stack advice starts in the wrong place. It asks which tools to install before it asks where your work is leaking time."* Open Stack vänder på ordningen:

> **"If the agent cannot do the job, give it a skill. If it keeps losing context, give it a brain. If work disappears between chats, give it an engine."**

- **Du saknar en förmåga** → Open Skills
- **Du återförklarar samma kontext** → Open Brain
- **Arbete och överlämningar tappas bort** → Open Engine
- **Osäker?** → låt en agent intervjua dig och klassificera flaskhalsen som capability / context / work movement (fältguiden innehåller intervjuprompten)

**Anti-mönstret som pekas ut explicit: "The one-click magic button is the trap."** Ett system kan inte vara personligt om ingen frågar vad ditt arbete är, vad din agent får göra, vilken kontext som spelar roll och var den mänskliga godkännandegränsen går. *"The primitives are reusable. The application is personal."* Två personer som utgår från samma bibliotek slutar med helt olika system — det är poängen.

## 1.3 Ordningen: Skills → Brain → Engine

Rekommenderad standardordning, med explicit brytregel:

1. **Open Skills först** — en enda användbar skill kan ge utdelning innan man bygger databas eller kö. Välj ETT jobb, gör det repeterbart, testa på riktigt arbete.
2. **Open Brain sedan** — beständig kontext gör varje framtida körning bättre.
3. **Open Engine sist** — en kö är mest användbar när man vet vilka förmågor och vilken kontext som ska röra sig genom den. *"A queue adds ceremony. It earns that ceremony when work gets lost without it."*

**Brytregel:** *"Break that order when your bottleneck gives you a better answer."* Är smärtan förlorade uppgifter — börja med Engine. Är den upprepad kontext — börja med Brain.

## 1.4 Den minsta funktionella enheten

Från stack-filosofiposten: den minsta enheten som låter en agent agera åt dig utan att gissa är en **fem-delars loop**:

**memory, method, boundary, receipt, judgment**
(minne, metod, gräns, kvitto, omdöme)

*"One loop beats a whole assistant."* Problemet har flyttat *"from capability to intent"*.

## 1.5 Människa/agent-gränsen är en del av systemet

Skrivs ner INNAN automationen blir intressant. Fast fördelning i alla guider:

- **Människan äger:** konton, auth, secrets, webbläsarinställningar, fakturering, publicering, destruktiva ändringar, kundvända handlingar, slutligt go/no-go-omdöme.
- **Agenten gör:** läser dokumentation, inspekterar repo, skriver setup-filer, kör säkra lokala kommandon, utkast, diagnos från loggar, verifiering — och **stannar vid godkännandegrindar**.

## 1.6 Adoptionsprincip: "It is fine to steal only the concepts"

Direkt relevant för ITSL:s anpassning. Fältguiden säger uttryckligen att man kan använda alla tre, två, en, modifierade versioner eller bara koncepten: skills-katalogen med egna skills; Brain-mönstret med annan databas; Engine-kvittovokabulären i GitHub Issues, Notion, Trello eller en lokal markdown-kö. **Men disciplinen måste behållas:**

> *"A queue without receipts is a prettier inbox. Memory without review becomes folklore. Skills without verification become vibes in markdown."*

## 1.7 Tidslinje

| Datum | Händelse |
|---|---|
| 2026-02-24 | Open Brain setup-guide + promptkit publiceras |
| 2026-03-02 | Open Brain Substack-lansering ("Every AI you use forgets you") |
| ~2026-03-11 | OB1-repot släpps på GitHub (259 stjärnor på ~5 dagar; ~4,1k idag) |
| 2026-03-13 | Extensions-post ("two-door"-principen) |
| 2026-06-16 | Agent Maintenance Loop-guiden |
| 2026-06-19 | Open Skills lanseras ("Why Claude Skills Don't Travel to Codex") |
| 2026-06-26 | Open Engine lanseras (guide + Substack) |
| 2026-07-01 | Stack-filosofin ("build 80% of your own AI memory by talking to the agent") + Open Stack Field Guide (uppdaterad) |

---

# 2. Open Skills i detalj

## 2.1 Vad en skill är

Kanonisk definition (verbatim):

> **"A skill is a compact operating procedure your agent loads on demand: when to use a method, which tools it calls, what standards matter, and what proof it owes you before it says done."**

Skills är personliga — de bär *"your taste, requirements, dependencies, and hard-won decisions"*. *"A good skill turns a one-off good session into repeatable behavior."*

Problemet de löser: *"most agent wins disappear after the chat ends"* och skills byggda hos en leverantör flyttar inte med — *"The prompt copies over. The intention copies over. The skill does not."* **Ägandetestet:** en ägd skill ska vara *"visible, movable, inspectable, testable, and available wherever you work."*

**Leveransformat — viktigt att förstå:** Biblioteket levererar INTE färdiga skill-filer. Varje skill är en **setup-prompt** (inbäddad i `<prompt><task>…</task></prompt>`-XML) som man klistrar in i sin egen kodagent. Agenten intervjuar användaren, skriver skill-filen anpassad till användarens verktyg/filer/konton/standarder, och testar den live. *"The prompt is the starting point; the installed skill should reflect your real workflow."* Det finns inget dedikerat Open Skills-repo på GitHub.

## 2.2 Gemensamma konventioner (alla 40 skills)

1. **Namn:** kebab-case (`image-gateway`, `citation-guard`, `my-voice` …).
2. **Lagring:** "stored wherever my harness loads skills from" — harness-agnostiskt; konkreta exempel `~/.claude/skills/<name>/SKILL.md` och `~/.codex/skills/<name>/SKILL.md`.
3. **Intervju först:** agenten intervjuar användaren INNAN den skriver skillen (undantag: assumption-checker som inte behöver användarspecifika inställningar).
4. **Anatomi:** en numrerad "must include"-lista där punkt (1) alltid är **trigger conditions**; sedan procedur/regler; alltid minst en hård gräns.
5. **Test på riktigt material:** varje prompt slutar med ett obligatoriskt litet live-test (ett verkligt dokument, en verklig PR, en 2–3 min video …) — ofta med människan som betygsätter.
6. **Secrets:** API-nycklar läses alltid från env-fil, aldrig inskrivna i skill-filen.
7. **Komponerbarhet:** skills är delade primitiver — de anropar varandra i stället för att återimplementera (t.ex. anropar ≥3 skills `image-gateway`; `image-model-arena` "must never reimplement" gateway eller publisher).
8. **API-shape capture:** flera skills finns delvis för att spika fast ett fungerande API-anrop (fält, modell-ID:n, kostnader, fallgropar) EN gång centralt, så att en API-ändring fixas en gång och alla arbetsflöden ärver fixen.
9. **Urvalsregel:** *"Install the primitive only when you can name the workflow it will improve."* / *"Choose by bottleneck, not by novelty."*

## 2.3 De 8 kategorierna (40 skills totalt)

| # | Kategori | Antal | Beskrivning (översatt kärna) |
|---|---|---|---|
| 1 | Core Infrastructure | 5 | Grundlagret: bildgenerering, aktuell sökning, transkription, filkonvertering, HTML-artefakter |
| 2 | Context Engineering | 9 | Ostrukturerat pappersarbete → strukturerad case-fil: ingestera, normalisera, lagra, hämta deterministiskt, validera citat, exportera människogranskat paket |
| 3 | Research & Thinking | 5 | Rörig input → granskningsbart tänkande: röstanteckningar, möten, dokumenthögar, veckobrus, antaganden |
| 4 | Writing, Voice & Content | 4 | Specifik agentskrift: riktig röst, riktig målgrupp, aktuella fakta, varumärkta bilder |
| 5 | Web Publishing & Frontend | 4 | Agentoutput → publikt, inspekterbart webbarbete med verifiering |
| 6 | Video & Media Production | 3 | De dyra delarna av mediearbete: transkript-först-redigering, motion graphics, NLE-styrning |
| 7 | Testing & Quality | 3 | Trovärdigt agentbyggt arbete: repeterbar QA, webbläsarbevis, repo-lokal testminne |
| 8 | Agent Operations | 7 | Meta-skills för att köra agenter utan att själv bli flaskhalsen |

## 2.4 Alla 40 skills

### Kategori 1: Core Infrastructure (5)

| Skill | Syfte | Nyckelregler / beroenden |
|---|---|---|
| `image-gateway` | Generera/redigera bilder via ETT API (OpenRouter rekommenderas) med sparade preferenser (standardmodell, output-mapp, storlek) | Delad primitiv — ≥3 andra skills anropar den; kostnadsnoter per bild; nyckel från env-fil |
| `current-info-search` | Routa webbresearch genom sök-API för aktuell information (Perplexity kanonisk) i stället för träningsdata | Regel: **sökresultat vinner över träningsdata**; datum + primärkällor obligatoriska; kan kopplas som hook för alla webbsökningar |
| `media-transcription` | Lokal ljud/video → komplett transkriptionspaket via AssemblyAI: MD-transkript + ord-nivå-timestamp-JSON + semantiska kapitel + talarlabels | ffmpeg för ljud ur video; konsekventa filnamn = kontrakt med nedströms skills; "universal input format for media work" |
| `heavy-file-ingestion` | Tunga filer (stora PDF:er, decks, kalkylblad) → lätta MD/CSV-artefakter + indexfil i `_ingested/` FÖRE analys | Hård regel: **analysera aldrig originalfilen direkt** — bara konverterade artefakter; per-filtyps-recept |
| `html-artifacts` | Tät output (planer, rapporter, jämförelser, diagram) → EN självständig HTML-fil i husets stil | En fil, inline CSS/JS, noll externa beroenden, offline; layoutmönster: report, comparison table, timeline, diagram, dashboard; rendera-verifiera före klar |

### Kategori 2: Context Engineering (9) — case-fil-pipelinen

Kedjeordning: ingest → chunk/tag → normalize → store → retrieve → validate → export → human gate.

| Skill | Syfte | Nyckelregler |
|---|---|---|
| `pdf-document-ingestion` | PDF/skanningar/formulär/CSV → lätta markdown-artefakter med **stabila källankare** (chain-of-custody) | ETT kanoniskt ankarschema per case (PDF-sida/region, CSV-radnr, formulärruta); samma schema i ingesterad text OCH citat — "two numbering schemes in one artifact is a defect"; original bevaras orörda; index med konverteringskonfidens |
| `document-chunking-tagging` | Ingesterade dokument → adresserbara chunks med normaliserad metadata | Chunkschema: `chunk_id, case_id/plan_id, document_type, section_label, domain_tags, source_anchor, granularity, effective_date, content`; struktur före semantisk gissning; två-nivås granularitet (`page` + `clause`); **innehållsförteckning/försättsblad utesluts ur bevis-chunks** |
| `case-data-normalization` | Röriga fakta → normaliserad **case-ledger** (datum, parter, belopp, koder, konfidens, review-status) | Källbackade fakta lagras separat från agentklassificeringar; fält-nivå-sanity-checks (namn ser ut som namn; datum parsas absolut med days-remaining; belopp stämmer mot radsummor); en verklig händelse = en rad; mismatch → `needs_review` med konkret fråga, aldrig default-`pending` |
| `sqlite-case-store` | Lokal SQLite-databas som standard-startbackend | Sex tabeller: `source_documents, chunks, normalized_records, retrieval_mappings, run_outputs, validation_results`; skript: migrate, inspect, query-by-section, export-case; inga secrets i fixtures |
| `open-brain-case-store` | Samma logiska schema mappat på Open Brain för OB1-användare | SQLite = startväg, Open Brain = uppgraderingsväg; migreringsnot krävs; **"Do not imply Open Brain is required for the beginner path"** |
| `deterministic-retrieval-map` | Explicita uppslagstabeller case-typ → dokumentsektioner/kategorier | Hämtning via vanliga queries mot taggar/labels, **inte embeddings**; kompakt bevispaket (chunk-ID:n + ankare + innehåll); flagga saknade sektioner FÖRE utkast; semantisk sökning endast som senare fallback, "never the v1 foundation" |
| `citation-guard` | Verifierar att varje substantiellt påstående i utkast citerar bevis som **faktiskt stödjer det** | Verdikt: `pass` / `needs_review` / `fail`; EN maskinkontrollerbar citatsyntax, t.ex. `[record:case-42:expense:adobe_feb]` eller `[chunk:eoc-017]`; citat som resolvar men inte stödjer = fail; **exit nonzero vid fail** (CI-grindning); tvåsidigt test: ren draft passerar (exit 0) OCH seedat fabricerat citat faller som DEN namngivna felposten |
| `packet-export` | Granskade outputs → redigerbar paketmapp + PDF | **Vägrar exportera medan guard rapporterar fail**; mappform: `packet/` med `draft.md, packet.pdf, citation-map.json, checklist.md, unresolved-questions.md, sources/`; markdown = sanningskälla, PDF = leverans; PDF via headless Chrome `--print-to-pdf` (verifiera fil på disk, inte exit-status); **"Never transmit, submit, sign, file, or send"** |
| `human-gate` | Definierar stopplinjen i högriskflöden | Tillåtna agenthandlingar: **organize, draft, validate, summarize, export**. Förbjudna: **sign, send, file, submit, authorize, pay, transmit sensitive data**. Granskningschecklista i varje paket; arbetsflödet stannar vid export; "the human gate is not a missing automation feature — it is the product boundary" |

### Kategori 3: Research & Thinking (5)

| Skill | Syfte | Nyckelregler |
|---|---|---|
| `brain-dump-processor` | Röriga flertema-dumpar (röstmemon, anteckningar) → separerade, utvärderade idéer | Per idé: (a) idén i en mening, (b) kontext, (c) ärlig värd-att-driva-bedömning, (d) konkret nästa steg; trigger "process this"; flagga motsägelser inom samma dump |
| `meeting-synthesis` | Mötestranskript → strukturerad syntes | Fast struktur: takeaways / decisions (med VEM som beslutade) / action items (ägare + deadline) / open questions / durable context; **hård regel: sagt vs härlett separeras**; exakta citat för allt löftesartat; per ämne, inte kronologiskt |
| `weekly-signal-diff` | Återkommande genomgång av bevakningslista → rapportera BARA vad som ändrats | **State-fil** från förra körningen möjliggör äkta diff; ordnat efter ändringens vikt; ingen-ändring är giltigt svar — padda aldrig; max 3 uppföljningar; introduktionen till "stateful skills"-mönstret |
| `assumption-checker` | Adversariell granskning av plan/argument | Postur: **skeptiker, inte kollaboratör** — mjuka inte upp fynd; varje antagande betygsatt load-bearing + evidensgrad; farligaste antagandet överst; körs som EGEN skill med egen postur, inte i samma konversation som skrev planen; avslutas med de 3 frågor som mest minskar risk |
| `reading-pack-builder` | Dokumenthög → självständigt offline-HTML-läspaket | Indexsida med sammanfattningar + motiverad läsordning; ett-dokument-i-taget-navigation (prev/next); läst/oläst-markering; ärver `html-artifacts`-konventioner |

### Kategori 4: Writing, Voice & Content (4)

| Skill | Syfte | Nyckelregler |
|---|---|---|
| `my-voice` | Kodar hur användaren faktiskt skriver — flera register (direkt, varm, analytisk, formell), inte en tonpreset | Byggs från 5–10 riktiga skrivprov; explicit anti-mönsterlista ("never open with 'I hope this finds you well'", "never use 'delve'"); regel: **för tekniskt innehåll slår korrekthet röst**; högst hävstång för alla som publicerar |
| `release-briefing` | Släppdata → publiceringsklart briefing-paket i fast format | Trigger "brief me up on \<release\>"; varje faktapåstående bär datum + källa; **paketerar bara — saknas/gammal research: stoppa och kör current-info-search först**; 2–3 thumbnail-bildprompts |
| `audience-content-system` | Innehåll för en definierad publikation/målgruppsnivå | "Audience contract": knowledge floor + ceiling, bannad jargong med ersättningar; mall per format; batch-planeringsläge; kalibreringskoll: "would my least technical reader follow every step?" |
| `branded-image-prompting` | Komplett promptguide för bilder i användarens visuella varumärke | Varumärke i prompt-form (hex-färger, typografi, stil); NL- och JSON-promptmönster; 10+ mallbibliotek som växer; driftkorrigeringsrecept; generering routas via `image-gateway` |

### Kategori 5: Web Publishing & Frontend (4)

| Skill | Syfte | Nyckelregler |
|---|---|---|
| `frontend-taste` | Ersätter agentens default-designinstinkter med ett starkare smaksystem (kärn-skill + nästlade sub-skills) | Layoutregler (ingen hero-plus-tre-kort-default), typografiskala, färgåterhållsamhet; **obligatorisk visuell loop: screenshot → inspect → fix → repeat** |
| `site-publisher` | Färdig sida/artefakt → publicerad på egen sajt, end-to-end | **Endast på explicit begäran, aldrig auto-triggad**; slug, 1200x630 OG-bild (via image-gateway), indexeringskontroller (public/unlisted/noindex), lokal verifiering före deploy, post-publish-kontroller; "final step of half the runbooks in this library" |
| `image-model-arena` | Publicerade jämförelsesidor för bildmodeller från EN konfigfil | **Komponerar** image-gateway + site-publisher, "must never reimplement either"; modellregister med kostnad + policyegenheter; inkrementell regenerering (ny modell kräver inte omgörning) |
| `essay-illustration-gallery` | Färdig essä → ~15–20 stil-låsta illustrationer + galleri + social not | Momentval över HELA essäns båge; en stil-deskriptor prepend:as till varje prompt; per-bild-captions; publicering endast på begäran |

### Kategori 6: Video & Media Production (3)

| Skill | Syfte | Nyckelregler |
|---|---|---|
| `radio-edit` | Transkriptdriven grovklippning av talking-head-material | Ljud/narrativ först; **paper edit (varje klipp med timecode + motivering) levereras FÖRE tidslinjefilen**; export FCXML/EDL med frame-handles; revisionsloop; test end-to-end på <5 min inspelning inkl. import i NLE |
| `broll-pipeline` | Färdig video + transkript → animerade motion-graphics-overlays | Tre delar: **SCOUT**-subagent (väljer moment, densitets-/avståndsregler, skriver manifest), **BUILDER**-subagent (2–3 manifestposter åt gången → Remotion/React-komponenter mot **ett delat visuellt kontrakt = EN TypeScript-fil**), **ORCHESTRATOR** (render → ffmpeg-komposit; **återupptagbar pipeline-state-fil**); byggs stegvis: kontrakt → 1 handgjord referensgrafik → scout → builder → render; "the most complicated skill in the library" |
| `nle-assistant` | Styr DaVinci Resolve live via Python-scripting-API | **Hård säkerhetsregel: ALLTID duplicera tidslinjen, aldrig röra original eller radera media**; anslutningsfellägen dokumenterade; varje kärnoperation verifieras individuellt; test i slängprojekt först |

### Kategori 7: Testing & Quality (3)

| Skill | Syfte | Nyckelregler |
|---|---|---|
| `testing-runbook-creator` | VARJE test/QA/debug-aktivitet lämnar en repo-lokal runbook-post | Plats t.ex. `docs/testing-runbook.md`; fält: sida/flöde, steg-för-steg, säkra vs destruktiva handlingar, setup/seed, cleanup, exakta verifieringskommandon med förväntad output; **read-first** (kolla runbooken före test), **fix-in-session**, **record-as-you-go**; "testing discoveries must not die in chat" |
| `page-testing-memory` | Global sido-QA-process + strikt kunskapssplit | Process: states (empty/loaded/error/loading), formulär (valid/invalid/edge), auth-gränser, breakpoints, screenshots som bevis; **global skill = process, repo-runbook = fakta** (selektorer, testkonton, seed) — aldrig projektdetaljer i globala skillen |
| `browser-qa` | Instrumenterad webb-QA via Chrome DevTools MCP | Receptmappning: layoutändring → screenshots desktop/tablet/mobile; prestanda → Core Web Vitals (LCP, INP, CLS) mot angivna trösklar; nya features → konsol-/nätverksfel under skriptad genomgång; **bevisregel: inget oevidensierat "looks fine"**; fynd om HUR sidan testas → repo-runbooken |

### Kategori 8: Agent Operations (7)

| Skill | Syfte | Nyckelregler |
|---|---|---|
| `goal-prompt-generator` | Luddig plan → avgränsat autonomt mål för en agent | Obligatorisk struktur: mål i ett stycke + **DEFINITION OF DONE-checklista** + repo-constraints (får/får inte röras) + **verification gates** (exakta kommandon + förväntade resultat) + **stop conditions** (halta-och-fråga); självständighetsregel: mottagande session har noll kontext; kvalitetskoll: "could a competent agent with zero context execute this?" |
| `visible-delegation` | Orkestrera annan agent i **synlig** tmux-session | Namngiven session, människan kan attacha och se; interventionstriggers: **stuck loops, scope drift, destructive commands**; **orkestratorn kör själv verification gates** innan framgång rapporteras; sessioner stängs, inte överges; par med goal-prompt-generator |
| `session-operating-map` | Repo-lokal karta över parallella agentsessioner | `docs/operating-map.md`: per lane {namn, mål, ägande session, tillstånd, blockers}; tillstånd: start/block/handoff/done; en lane per angelägenhet; uppdatera vid meningsfull ändring, inte som dagbok; klara lanes → done-sektion med enradsutfall; **read-first: varje ny session läser kartan** |
| `self-pr-merge` | Disciplinerad granska-och-merga för egna PR:ar | Äkta review-pass FÖRST (läs hela diffen); regel: **"finding nothing must be a conclusion, never a default"**; CI/mergeability-kontroller; ärlig not om self-approval-begränsningen; worktree-säker branch-städning; **fail eller olöst fynd stoppar mergen** |
| `stakeholder-update-email` | Kort, sant statusmejl efter shippat arbete | Grind: **inget synligt ändrat → säg det och skicka inget**; mottagarens vokabulär, inte implementation; aldrig "done" utan verifiering; format: what changed / what it means for them / what's next; **sändning kräver explicit bekräftelse** |
| `session-to-skill-extractor` | Kontinuerlig lärande-loop för skill-biblioteket | Trigger: "wrap up", "anything worth keeping?"; hög ribba — mönstret måste vara **RECURRING + NON-OBVIOUS + CODIFIABLE**, de flesta sessioner ger inget (korrekt); täcker befintlig skill 80 % → uppdatera i stället för ny; utkast landar för granskning, **aldrig tyst i live-biblioteket**; sanera projektspecifika detaljer |
| `agentic-harness-designer` | Designgranskning av agentdrivna system | Fast designpromenad i ordning: verktygskontrakt → permissionsmodell (autonomt/godkännande/förbjudet) → workflow-state & hållbarhet → kontext/minnesstrategi → utvärdering → observerbarhet; failure-killers: **missing approval gates, non-durable state, unbounded context growth, no evals, invisible execution**; output: designdok + fasad plan där varje fas är självständigt shippbar |

## 2.5 De 10 runbooks

**Runbook** (definition, verbatim): *"A skill makes one kind of agent behavior reliable. A runbook makes a whole workflow reliable. It names the chain, the handoffs, and the point where a human still needs to approve, choose, or judge."* Slogan: *"The primitive is the unit. The runbook is the production line you build from those units."*

Tre strukturella pelare: **Chain** (namngivna skills i ordning), **Handoff** (människan behåller omdömet), **Payoff** (konkret inspekterbar artefakt).

| # | Runbook | Kedja | Mänsklig grind | Payoff |
|---|---|---|---|---|
| 01 | **Talk to Published** | Media Transcription → Brain Dump Processor → Personal Voice → HTML Artifact Builder → Personal Site Publisher | Du väljer idén värd att skriva | Röstmemo → publicerad sida med korrekt länkförhandsvisning |
| 02 | **Release Day** | Current-Information Search → New Release Briefing → Branded Image Prompting → Image Generation Gateway → Personal Site Publisher → Stakeholder Update Email | (Sökningen är korrekthetsankaret) | Korrekt, on-brand briefing live samma dag; "speed that never costs correctness" |
| 03 | **The Video Production Line** | Media Transcription → Radio Edit → B-Roll Pipeline → AI Editing Assistant → Stakeholder Update Email | Du godkänner paper edit:en (billigt att ändra på papper) | Rå video → färdig grafiktung klippning |
| 04 | **Ship a Page You Can Trust** | Frontend Taste System → Personal Site Publisher → Browser Automation QA → Testing Runbook Creator | QA-granskning | Live-sida verifierad med instrument + repo-runbook så nästa deploy verifieras på minuter |
| 05 | **The Research Engine** | Heavy File Ingestion → Current-Information Search → Assumption Checker → Meeting Synthesis → HTML Artifact Builder → Reading Pack Builder | Läspaketet granskas av människa | "Research with a chain of custody: every claim traceable, every conclusion stress-tested"; ingest-först är hela tricket; Assumption Checker = "not the same conversation grading its own homework" |
| 06 | **Delegate and Verify** | Session Operating Map → Goal Prompt Generator → Visible Delegation → Self-Authored PR Merge → Stakeholder Update Email | Du rör bara två beslut: vad "done" betyder och om diffen är bra | Parallella engineering-lanes utan att du blir flaskhalsen; **goal-prompten är också acceptanstestet** |
| 07 | **The Flywheel** | Session-to-Skill Extractor → Testing Runbook Creator → Page Testing Memory → Session Operating Map | — (posture, inte pipeline: "runs under every other runbook") | "No useful discovery dies in chat" — mekanismen som gör biblioteket självförökande |
| 08 | **Claim Appeal Packet** | PDF/Document Ingestion → Chunking/Tagging → Normalization → SQLite Case Store → Deterministic Retrieval Map → Citation Guard → Packet Export → Human Gate | Human Gate: stannar före inskick | Avslagsbrev → redigerbart, citerat överklagandepaket |
| 09 | **Tax Prep Packet** | Samma kedja som 08 + **Open Brain Case Store (optional · OB1 path)** | Human Gate: stannar före deklaration | Rörig skattemapp → strukturerat CPA-klart paket |
| 10 | **Email Follow-Up Packet** | Samma 9-stegskedja som 09, riktad mot mbox-export | Human Gate = sändgränsen (trippelskydd, se 2.6) | Försummad inkorg → urgency-ordnad ledger + citerade utkast, "and nothing sends itself" |

Skills som återanvänds mest: Personal Site Publisher (01, 02, 04), Stakeholder Update Email (02, 03, 06), Media Transcription (01, 03), hela 08–10-stacken delad.

## 2.6 Det dokumentgrundade byggmönstret (runbooks 08–10 + byggguiderna)

Detta är den mest genomarbetade delen av hela systemet — en återanvändbar arkitektur som instansieras per vertikal (sjukvårdsöverklagande, skatt, e-post). Bägge byggguiderna öppnar med identisk tes:

> *"People lose high-friction paperwork fights because their information is scattered, unstructured, uncited, and incomplete. The fix is to own the context: collect the mess, normalize it, ground it in source documents, and produce the next human-reviewed action."*

### Den kanoniska 10-stegs primitivkedjan (verbatim ur master-prompten)

1. **Ingest** dokument till markdown/text med råa källkoordinater som ankare (PDF-sida/region, CSV-radnummer, formulärruta). Samma ankarschema bäddas in i texten som citaten senare använder. *"Keep one numbering scheme end to end."*
2. **Chunk + tag** källbevis efter struktur.
3. **Normalize** case-fakta till en ledger.
4. **Coverage gate** (hårt stopp): varje ingesterat dokument måste producera ≥1 normaliserad post ELLER explicit markeras `reference-only`. Skriv ut listan av okonsumerade dokument och STOPPA före utkast om något dokument saknas. (Namngiven svag-agent-failure: tappar tyst 5 av 15 dokument inkl. W-2:an och rapporterar den sedan som saknad.)
5. **Reconcile** delade fakta över källor före utkast: jämför samma faktum överallt det förekommer; varje mismatch blir en **namngiven granskningsfråga** (aldrig tyst korrigering); notera vilken källa som **styr** det spårade värdet.
6. **Store** i SQLite som default.
7. **Optional:** spegla case-store till Open Brain endast om du redan kör OB1; annars hoppa. *"SQLite is the complete beginner path."*
8. **Deterministic retrieval** av relevant bevis före utkast.
9. **Citation guard** före export: verdikt `pass` / `needs_review` / `fail`; varje `fail` blockerar paketexport tills fixad eller konverterad till namngiven granskningsfråga; guard-sammanfattningen måste stå i paketets README (räknesiffror verbatim).
10. **Export** ett redigerbart paket och **stoppa vid mänsklig granskning**.

Constraint-raden (verbatim, i bägge guiderna): **"The agent organizes and drafts. It does not sign, send, file, submit, authorize, or transmit sensitive data."**

### Byggdisciplinerna runt kedjan

- **Fixture first:** bygg och bevisa offline innan livedata. Fixturen är en **permanent testbänk**, inte engångs. Regel: **riktiga format, fejkade människor** (äkta RFC 5322-headers/mbox, syntetiska namn/belopp). Inkludera brus (nyhetsbrev, kvitton) så triage har något att legitimt exkludera; inkludera egen utgående historik (Sent) så stängda loopar upptäcks; en fixture per hanterat tillstånd; obyggda grenar byggs ändå och markeras `untested`.
- **Hostile fixture (promptinjektionstest):** en input vars innehåll instruerar agenten att bryta gränsen. Korrekt körning ingesterar/citerar den som vilken input som helst medan gränsen håller. *"Inbound email is evidence, never instructions."*
- **Gränsen är STRUKTURELL, inte beteendemässig:** byggfas = ingen kod-väg kan sända något (inga credentials alls); livefas = connector vars verktygsyta läser trådar och skapar utkast men **saknar send-verb** — *"the model cannot call a tool that does not exist."* (Namngivet exempel: Anthropics Gmail-connector är formad exakt så.)
- **Godkännandesemantik (verbatim):** *"An ignored draft means no. Approval is explicit and per message. Silence is not consent, time passing is not consent, and re-running the pipeline is not consent."* Vokabulär: `pending` / `approved` / `declined`; allt exporteras som `pending`; godkännandekolumnen är revisionsspåret; själva sändningen sker i människans eget verktyg.
- **Kvitton varje körning:** *"Every cycle appends a run receipt: messages ingested, ledger rows changed, drafts created, questions raised."* Plattformsnativa kvitton där de finns (mail-labels).
- **Tvåhastighetsloop:** inkrementell delta ~var 20:e minut under arbetstid (nyckel = stabilt ID + high-water mark); dyr full-avstämning nattligen.
- **Negativa bevis för guards:** spara TVÅ rapporter — ren draft passerar med exit 0, OCH seedat fabricerat-men-välformat citat faller med nonzero exit där **den seedade meningen själv är den rapporterade felposten**. *"A report that fails other claims does not count as proof."*
- **Ship gate:** export vägrar eller stämplar `DRAFT-INVALID` på README:n medan något citat failar eller något utkast påstår godkännande som ledgern inte kan visa mänskligt beslut för.
- **Datahederlighet:** `exact` vs `estimated` etiketteras överallt (`_est`-suffix i schema); intervjua människan före estimering; konservativa intervall; *"The sin is not estimating. The sin is hiding that you estimated."* Totaler måste handräknas ihop före ship.
- **Statusvokabulär (samlad):** `pass` / `needs_review` / `fail` (guard), `ok` vs `needs_review` (ledger), `reference-only`, `excluded-pending-review`, `untested`, `DRAFT-INVALID`, `URGENT` (<14 dagar till deadline), `pending`/`approved`/`declined` (godkännande).

## 2.7 Katalogens användningsdoktrin

Verbatim från skills-katalogsidan: *"Do not browse this like an app store. Start with the repeated failure … Then choose the smallest primitive that fixes that failure."* — *"The useful skill is the one that removes a repeated explanation."* — *"If you cannot name the workflow where a skill will run next week, wait."*

---

# 3. Open Brain / OB1 i detalj

## 3.1 Vad det är

**"One database, one AI gateway, one chat channel — any AI plugs in. No middleware, no SaaS."** (GitHub-tagline). Ett beständigt AI-minnessystem: tankar lagras som råtext + 1536-dimensionell vektorembedding + strukturerad JSONB-metadata i **Supabase Postgres + pgvector**; en enda Supabase Edge Function (`open-brain-mcp`) är MCP-servern; alla MCP-klienter (Claude Desktop/Code, ChatGPT, Cursor, Codex, Gemini …) läser OCH skriver.

Positionering:

- **INTE** en anteckningsapp, INTE en Obsidian-ersättare — *"a memory layer for your AI"*, en databas med vektorsökning. Explicit kontrast mot Karpathys markdown-wiki-ansats; inga CLAUDE.md-minnesfiler i Nates eget arbetsflöde.
- Rådata och embeddings lagras separat → vektorindexet kan byggas om när bättre embeddingmodeller kommer, utan att röra källdata.
- Anti-brus-strategi: siloning via metadata-kontext, inte "glömska". Retrievallagret spelar större roll än lagringslagret.
- Tillväxtmodell: ingen finetuning — ackumulering + vana. *"The more you put in, the better retrieval gets."*
- **Kostnad:** Supabase free tier + ~5 USD OpenRouter-krediter ("lasts months"); ~0,10–0,30 USD/mån i drift; ~30–45 min setup, noll kodvana förutsatt (en kodagent gör bygget — *"The build is a conversation now"*).
- **Licens: FSL-1.1-MIT** (Functional Source License; inga kommersiella derivat under FSL-perioden, konverterar till MIT).

## 3.2 Kärndatamodellen: `thoughts`

Fullständigt schema (verbatim ur getting-started):

```sql
create table thoughts (
  id uuid default gen_random_uuid() primary key,
  content text not null,
  embedding vector(1536),
  metadata jsonb default '{}'::jsonb,
  created_at timestamptz default now(),
  updated_at timestamptz default now()
);
create index on thoughts using hnsw (embedding vector_cosine_ops);
create index on thoughts using gin (metadata);
create index on thoughts (created_at desc);
```

Plus: `update_updated_at()`-trigger; sökfunktionen `match_thoughts(query_embedding vector(1536), match_threshold float = 0.7, match_count int = 10, filter jsonb = '{}')` som returnerar `id, content, metadata, similarity, created_at` (cosinus, `similarity = 1 - (embedding <=> query)`, JSONB-containment-filter `metadata @> filter`); RLS med policy "Service role full access"; **obligatoriskt GRANT** `select, insert, update, delete` till `service_role` (nya Supabase-projekt granular inte längre automatiskt — utan detta: "permission denied for table thoughts").

**Deduplicering:** kolumn `content_fingerprint` (SHA-256-hex av lowercase, whitespace-kollapsad, trimmad text) + unikt partiellt index + funktionen `upsert_thought(p_content, p_payload)` — vid duplikat uppdateras `updated_at` och metadata mergas (`||`), ingen andra rad.

**Datamodelleringsvägledning:** embeddingsenhet = en återhämtbar idé per rad (Zettelkasten-artat); långa dokument chunkas (parent-dokument + chunk-tabell, hybrid: metadata-filter först, vektorsimilaritet inom filtrerad mängd); varje källtyp självtaggar (`source: "slack"`, `"garmin"`, `"calendar"` …); semantisk sökning "sparse, not broken" under ~20–30 rader; miljontals rader OK med HNSW.

**Schemaguardrail:** *"Never modify the core `thoughts` table structure"* — lägga till kolumner OK, ändra/droppa inte.

## 3.3 Embedding-/metadatapipelinen

- **Gateway: OpenRouter** (ett konto/nyckel för alla modeller; valt framför direkta OpenAI-nycklar för framtidssäkring).
- **Embeddingmodell:** `openai/text-embedding-3-small` (1536 dim, ~0,02 USD/M tokens). **Metadatamodell:** `openai/gpt-4o-mini` med `response_format: json_object`.
- **Capture-flödet:** klient anropar `capture_thought` → servern kör embedding OCH LLM-metadatautvinning **parallellt** (`Promise.all`) → en rad i `thoughts` → bekräftelse med extraherad metadata.
- **Metadatautvinningens systemprompt (verbatim, används identiskt i MCP-servern, Slack-boten och k8s-varianten):**

```
Extract metadata from the user's captured thought. Return JSON with:
- "people": array of people mentioned (empty if none)
- "action_items": array of implied to-dos (empty if none)
- "dates_mentioned": array of dates YYYY-MM-DD (empty if none)
- "topics": array of 1-3 short topic tags (always at least one)
- "type": one of "observation", "task", "idea", "reference", "person_note"
Only extract what's explicitly there.
```

Fallback vid parsefel: `{ topics: ["uncategorized"], type: "observation" }`. Metadata är best-effort — embeddingen driver retrieval oavsett metadatakvalitet. (Produktions-dashboarden utökar typvokabulären till `task, idea, observation, reference, person_note, decision, lesson, meeting, journal`.)

## 3.4 MCP-servern (`open-brain-mcp`)

En enda Deno Edge Function (Hono + `@hono/mcp` StreamableHTTPTransport + MCP SDK + Zod + supabase-js med service-role). Deployas `--no-verify-jwt` (servern gör egen nyckelauth).

**Verktyg (6):**

| Verktyg | Typ | Funktion |
|---|---|---|
| `capture_thought` | skriv (bounded, non-destructive) | Spara tanke: parallell embed + metadata → `upsert_thought` → uppdatera embedding |
| `search_thoughts` | läs | Semantisk sökning (query, limit=10, threshold=0.5), människoläsbar output `--- Result N (XX.X% match) ---` |
| `list_thoughts` | läs | Bläddra senaste (filter: type/topic/person/days) |
| `thought_stats` | läs | Total, datumintervall, topp-10 types/topics/people |
| `search` | läs (ChatGPT-alias) | Exakt read-only `search`-form som ChatGPT:s begränsade ytor kräver; returnerar `{results:[{id,title,url}]}` |
| `fetch` | läs (ChatGPT-alias) | Hämta ett dokument `{id,title,text,url,metadata}`; citat-URL:er via `OPEN_BRAIN_CITATION_BASE_URL` |

ChatGPT-detalj (lastbärande): verktyg utan `readOnlyHint`-annotation behandlas som skrivhandlingar av ChatGPT; alla läsverktyg annoteras `readOnlyHint: true`, `capture_thought` med `readOnlyHint:false, openWorldHint:false, destructiveHint:false`.

**Operativa mönster värda att stjäla:**

1. **Stateless per-request:** en FÄRSK `McpServer` + transport byggs för VARJE request; `mcp-session-id`-headern raderas ur svaret (ingen sessionsaffinitet, ingen singleton-korruption över edge-isolates).
2. **Auth två vägar:** query-param `?key=<64-hex>` (Claude Desktop/Web/ChatGPT kan inte skicka egna headers) ELLER header `x-brain-key` (Claude Code, mcp-remote). En delad nyckel för hela Open Brain, lagrad som Supabase-secret `MCP_ACCESS_KEY`.
3. **Auth-fel som JSON-RPC-fel över HTTP 200** (kod `-32001`, "Unauthorized: missing or invalid authentication.") — strikta MCP-hosts (Codex CLI, Claude Code) river anslutningen vid nakna HTTP 4xx.
4. **Accept-header-patch:** Claude Desktop skickar inte `Accept: text/event-stream`; servern skriver om requesten.
5. **Secret-rotationsgotcha:** Edge Functions cachar env vid cold start — efter `supabase secrets set` måste man alltid **redeploya**.

**Klientanslutningar:** Claude Desktop = custom connector med Connection URL (`…?key=`); ChatGPT = Developer Mode (stänger av inbyggda Memory) + app med "No Authentication"; Claude Code = `claude mcp add --transport http open-brain <url> --header "x-brain-key: …"`; Codex = `mcp-remote` via `~/.codex/config.toml` med `startup_timeout_sec = 30`; stdio-klienter = `supergateway`/`mcp-remote`-brygga.

## 3.5 Capture-vägar

1. **MCP** — `capture_thought` från valfri klient.
2. **Slack-bot** (`integrations/slack-capture`, Edge Function `ingest-thought`): Slack Events-webhook → filter (endast rätt kanal, ej bot, ej subtype) → dedup på `slack_ts` i metadata (Slack retry:ar webhooks >3 s) → parallell embed + metadata → direkt DB-insert med `source: "slack"` → trådad bekräftelse "Captured as *type* — topics…". Kräver bot-scopes `channels:history`, `groups:history`, `chat:write` och BÅDA event-prenumerationerna `message.channels` + `message.groups`.
3. **REST-ingest** (`open-brain-rest/ingest`): POST med `{text, source_label, source_type, auto_execute, import_key}` — används av Claude Code-Stop-hooken (se 5.2). `import_key` ger idempotens.
4. **Recipes/bulkimport:** Gmail-import (OAuth, per label + tidsfönster, städar signaturer/citerade svar), ChatGPT-konversationsexport, community-importers (Google Takeout, X/Twitter, Obsidian/Notion).
5. **Companion prompts** (vanorna): Memory Migration (kör först — hämta vad dina AI:er redan vet om dig), Second Brain Migration, Open Brain Spark (daglig capture), Quick Capture Templates, The Weekly Review. *"Your Open Brain is infrastructure. These prompts are the habits that make it compound."*

## 3.6 Agent Memory — sidecar-schemat och evidence-vs-instruction-policyn

Detta är OB1:s mest ITSL-relevanta subsystem: den **styrda mekanismen för hur agenter "tränas" över tid**. `thoughts` förblir innehållslagret; agent-memory-tabellerna adderar proveniens, konfidens, scope, use-policy, review-status, recall-spår och revision.

### Trustmodellen (kärnprincipen)

> **Agentskrivet minne är bevis (evidence) som standard. Instruktionsgradigt minne kräver mänsklig bekräftelse eller betrodd import.**

Tre booleaner per minne: `can_use_as_instruction` (default **false**), `can_use_as_evidence` (default **true**), `requires_user_confirmation` (default **true**). Verkställs på **lagringsnivå** med en DB CHECK-constraint:

```sql
CHECK (can_use_as_instruction = false OR provenance_status IN ('user_confirmed','imported'))
```

Övriga fastslagna defaults (verbatim): write-back kräver idempotens + content-hash-dedup; råtranskript, resoneringsspår, secrets, stora kodblock och kunddumpar blockeras eller flaggas; projektscope är default; personligt/kanal-minne auto-promoveras aldrig till team/workspace-scope.

### De 8 tabellerna

| Tabell | Roll |
|---|---|
| `agent_memories` | Huvudposten: scope (workspace/project/channel), `visibility` (personal/channel/project/workspace/organization), `memory_type` (decision/output/lesson/constraint/open_question/failure/artifact_reference/work_log), summary+content, `lifecycle_status` (active/stale/superseded/disputed/rejected), `provenance_status` (observed/inferred/user_confirmed/imported/generated/superseded/disputed), confidence 0–1, runtime/model-identitet, de tre policybooleanerna, `review_status` (pending/confirmed/evidence_only/restricted/rejected/stale/merged), idempotency_key, content_hash |
| `agent_memory_source_refs` | Proveniensbevis (kind, uri, title, timestamp) |
| `agent_memory_artifacts` | Artefaktreferenser (PR-länkar, Linear-issues …) |
| `agent_memory_relations` | related_to / supersedes / superseded_by / conflicts_with / merged_into |
| `agent_memory_review_actions` | Mänsklig granskningslogg med before/after-snapshots |
| `agent_memory_recall_traces` | En rad per recall-request (full request-payload) |
| `agent_memory_recall_items` | Per returnerat minne: rank, similarity, ranking_score, used/ignored + reason, use-policy-snapshot |
| `agent_memory_audit_events` | 10 händelsetyper: recall_requested, memory_returned, memory_used, memory_ignored, memory_written, memory_confirmed, memory_edited, memory_rejected, memory_superseded, memory_disputed |

### API:et (`agent-memory-api`, Edge Function med Hono/Zod)

| Endpoint | Funktion |
|---|---|
| `POST /recall` | Hämta scopade minnen före arbete. Flöde: embedda query → `match_thoughts` (threshold 0.25) → scope-gate (workspace-match; project_only; stale/superseded utesluts; **pending utesluts om inte `include_unconfirmed`**; `personal` läcker aldrig uppåt) → **styrningsviktad ranking**: `similarity + proveniensbonus (user_confirmed +0.3, imported +0.22, observed +0.15, generated +0.05) + policybonus (instruction +0.2, evidence +0.08, annars −0.2) + reviewbonus (confirmed +0.15, evidence_only +0.05, pending −0.08, annars −0.25) + confidence×0.15` → trace + audit → svar med `use_policy` per minne som runtimen MÅSTE respektera |
| `POST /writeback` | Kompakt operativt minne efter arbete. `memory_payload`-buckets: decisions/outputs/lessons/constraints/unresolved_questions/next_steps/failures/artifacts. **Regex-brandvägg** (HTTP 422, inget lagras): privata nycklar, API-nycklar (`sk-…`), credential-liknande strängar, stora kodblock, transkript-liknande text (>15000 tecken eller >8 rollprefixade rader). Dedup via idempotency_key + content_hash. Landar som `pending`/evidence-only — enda vägen till instruction-grade vid skrivning är betrodd import (`user_confirmed`/`imported` + `requires_review:false`) |
| `POST /recall/:id/usage` | Rapportera vilka minnen som användes/ignorerades (+skäl) — lärande-återkopplingen |
| `GET /memories`, `/memories/review`, `/memories/:id` | Inspektion; review-kön = pending-minnen |
| `PATCH /memories/:id/review` | Mänskliga styrningsåtgärder: `confirm` (→ instruction-grade, provenance=user_confirmed), `evidence_only`, `reject` (förlorar även evidence-status), `mark_stale`, `dispute`, `restrict_scope`, `edit`, `merge`, `supersede` — alla med before/after-snapshot |
| `GET /recall-traces/:request_id` | Debug: vad hämtades, hur rankades det, användes det |

Schemakontrakt: `openbrain.agent_memory.recall.v1` / `recall_response.v1` / `writeback.v1` / `writeback_response.v1` (+ `openbrain.openclaw.*`-alias). Runtime-neutralt: OpenClaw är lanserings-runtimen, "not the product boundary" — Codex, Claude Code, lokala agenter och n8n stöds.

### Flaggskeppsrecepten

- **Code Review Memory:** recall före PR-granskning (repo-konventioner, tidigare lärdomar, återkommande buggmönster, riskabla filer, maintainer-preferenser, kända falska positiver) → granska → writeback av kompakta lärdomar + artefaktreferenser (aldrig hela diffen). Acceptans: inferred lessons förblir evidence tills bekräftade; maintainer-korrigeringar kan supersede:a äldre lärdomar; falska positiver lagras som vägledning, inte permanenta förbud; recall-traces visar vilka minnen som påverkade granskningen. *"Repo-specific lessons compound across repeated reviews."*
- **TaskFlow Work Log:** agent-till-agent-överlämning. Agent A recallar tidigare försök → gör ett steg → skriver kompakt arbetslogg → Agent B recallar (med `include_unconfirmed: true` — överlämningar behöver pending-anteckningar) och fortsätter **utan att läsa råtranskriptet**. *"The handoff lives in OB1 as compact operational memory, not in one model's context window."*

## 3.7 Extensions-/recipes-/skills-ekosystemet

**Repostruktur:** `extensions/` (kuraterad lärväg, 6 byggen: Household Knowledge, Home Maintenance, Family Calendar, Meal Planning, Professional CRM, Job Hunt — tillsammans 40 MCP-verktyg + 2 bryggor), `primitives/` (återanvändbara konceptguider, kräver ≥2 extensions som referens), `recipes/` (fristående byggen, öppna för community), `schemas/`, `dashboards/`, `integrations/`, `skills/`, `docs/`.

**Skills vs recipes:** skills = installerbara beteenden (SKILL.md-promptpaket, "install the file, reload your client, reuse"); recipes = fullare byggen (setup, schemaändringar, automationskoppling); recipes kan kräva skills via `requires_skills`. Gränsdisciplin: *skills är sanningskällan för beteende; recipes förklarar bara sekvensering och överlämningar.*

**SKILL.md-format:** YAML-frontmatter (`name`, `description`, `author`, `version`) + body (`## Problem`, `## Trigger Conditions`, `## Process`, `## Output`, `## Notes`). **Kritiska caveats:** (1) description måste vara **EN RAD, ≤1024 tecken**, packad med triggerfraser — flerradiga `description: |`-block bryter Claude Codes skill-routing tyst; (2) Anthropic reserverar skillnamn som innehåller "claude"/"anthropic" (därav claudeception → **aiception**). Install: `~/.claude/skills/<name>/SKILL.md` (eller projektets `.claude/skills/`); andra klienter: Cursor `.cursorrules`, Windsurf `.windsurfrules`, Codex `AGENTS.md`. Konventioner: `variants/` per klient, `references/` för behovsladdade dokument, `metadata.json` per paket, "Credential Tracker"-ifyllnadsblock i varje recept.

**Tool audit-läran (docs/05):** en MCP-verktygsdefinition kostar 150–400 tokens och laddas varje meddelande; 40 verktyg ≈ 6–16k tokens stående overhead. Under ~10 verktyg fine, över 20 optimera. Mönster: unified CRUD (`manage_x` med action-param), read/write-split, generisk entity-manager; merga ALDRIG högfrekventa kärnverktyg (`capture_thought`, `search_thoughts`). **Tre-server-scoping:** capture-server (skrivtung, 5–8 verktyg) / query-server (lästung, 8–12) / admin-server (sällan ansluten) — samma databas, scoping styr verktygssynlighet, anslut selektivt per konversation.

**Primitives:** deploy-edge-function (mönstret `supabase functions new` → ladda ner index.ts+deno.json → `deploy --no-verify-jwt`; uppdatering = ny nedladdning + redeploy, URL/nyckel oförändrade), remote-mcp (per-klient-anslutning), rls (tre policy-mönster: user-scoped `auth.uid()`, team/household via medlemskaps-subquery, public+private; caveat: service_role förbigår RLS), **shared-mcp** (team-delningsmönstret: separat Edge Function + separat access-nyckel + begränsad DB-roll = hela tenancy-gränsen; boundary-testskript ska visa `thoughts: BLOCKED`; återkallelse = rotera den delade nyckeln), troubleshooting.

## 3.8 Självhostad variant (utan Supabase)

Community-bidraget `integrations/kubernetes-deployment` ersätter Supabase helt:

- **Pod med två containrar:** `ankane/pgvector:v0.5.1` (Postgres + pgvector, `init.sql` skapar `thoughts` + `match_thoughts`) + Deno 2.3.3 MCP-server (samma Hono/verktygsyta, rå SQL i stället för Supabase-klient). *"All MCP tools and the Hono HTTP layer are preserved; only the data access layer is changed."*
- **Env-styrda modellbaser:** `EMBEDDING_API_BASE`/`CHAT_API_BASE` default OpenRouter men pekbara mot valfri OpenAI-kompatibel endpoint (Ollama, llama.cpp) → helt lokal drift möjlig. Justera `vector(1536)` om embeddingdimensionen skiljer.
- **Dashboards:** Next.js-dashboard (kanban-workflow `new → planning → active → review → done`, audit, dubblettgranskning, Agent Memory-inspektör; `output: "standalone"` = Docker-klar men kräver REST-gatewayen `open-brain-rest`); SvelteKit-dashboard (pratar MCP direkt men regex-parsar textoutput; Supabase Auth måste bytas).
- **Generiskt capture-bot-kontrakt** (för att ersätta Slack med t.ex. Nextcloud Talk): (1) ta emot meddelandeevents begränsade till EN capture-kanal; (2) ignorera bot-/egna/tomma/redigerade meddelanden; (3) dedup på plattformens meddelande-ID i metadata; (4) parallell 1536-dim-embedding + LLM-metadata med verbatim-prompten; (5) INSERT i `thoughts` med `source: "<plattform>"`; (6) trådad bekräftelse "Captured as \<type\> — …".
- Kända avvikelser: k8s-schemat använder BIGSERIAL-id (produktion: UUID) och saknar produktionens extra kolumner (`type, source_type, importance, quality_score, sensitivity_tier, status …`); `progress_task`-verktyget (kanban via chatt) finns bara i produktionsservern.

## 3.9 Community och kritik (nyckelfynd för egen bedömning)

- OB1: ~4,1k stjärnor, 784 forks, 20+ mergade community-PR:ar, 3 externa maintainers, Discord.
- **MindStudio-kritiken:** chunking utan sektionsgränser, embedding-drift i blandade index, för breda MCP-permissions, saknade revisionsspår, ingen reranking ("vector similarity ≠ mest relevant").
- **Implementerarfriktion:** OAuth-fel i claude.ai-connector, HTTP 500 på stora transkript-payloads, UUID/bigint-mismatch som gav tyst föräldralösa skrivningar, tidszonbuggar.
- Schwartzer: ~80 % metadataklassificeringsträff med gpt-4o-mini; *"the harder challenge is building the capture habit and resisting the urge to over-engineer."*
- OpenClaw avslog en begäran att göra OB1 till förstaklassigt minnesmål ("not planned") — integrationer är Nates egna, inte ekosystemets.

---

# 4. Open Engine i detalj

## 4.1 Vad det är

**"Open Engine turns Linear into a shared operating surface for agents."** Det är INTE en agent-harness eller modell-runtime — det är ett **koordinationsprotokoll** ovanpå Linear (issue-trackern): Linear blir delad kö, tillståndslager och revisionsspår; agenter läser tilldelade issues, flyttar statusar och lämnar kvitton. Problemet som löses: *"the integration layer is you"* — människor som manuellt bär tillstånd mellan AI-verktyg. En fungerande engine har: **en kö, ett privat setup-kontext, en statusliggare, stående uppdateringar, en repeterbar runner, återupptagbara blockeringar, human-thread-holds, delegerad uppföljning och en smoke-testad uppgift.**

Runtimes är utbytbara MCP-klienter — Codex, Claude Code, Claude Desktop, Cursor m.fl. — anslutna till **Linears officiella MCP-server**. Ingen runtime har privilegierad roll.

## 4.2 Före start: namngivningsbesluten

*"Most failed first runs come from mismatched team names, labels, statuses, issue titles, or agent codes."*

1. Välj/skapa Linear-team som äger agentarbetet (t.ex. **Agent Engine**).
2. Skapa ETT projekt: **Personal Agent Engine** (en operatör) eller **Team Agent Engine**.
3. Skapa exakt labeln **`agent-instructions`** — runnern filtrerar på denna stavning.
4. Välj stabila **agentkoder** per runtime: `alex-codex`, `alex-claude`, `sam-codex`.
5. Namnge privata setup-issuen och statusliggaren INNAN automation finns, så alla prompts pekar på samma sanningskälla.

## 4.3 Steg 1: Anslut agenten till Linear (MCP)

```
# Codex
codex mcp add linear --url https://mcp.linear.app/mcp
codex mcp login linear
# (~/.codex/config.toml: [features] rmcp_client = true vid första remote-MCP)

# Claude Code
claude mcp add --transport sse linear-server https://mcp.linear.app/sse
# därefter /mcp och Linear-auth i webbläsaren
```

Verifiera före fortsättning: agenten kan lista workspace/team/projekt; identifiera anslutet konto; kommentera på en slänge-issue (`[agent instructions][connection-test][task] Verify Linear MCP access` med kommentaren `AGENT CONNECTION TEST`); flytta status endast på testissuen. *"Do not touch any real work issues during this test."*

## 4.4 Steg 2: De sex workflow-statusarna

Skapas i teamet, i denna ordning:

| Status | Betydelse |
|---|---|
| **Standing** | Varaktig setup: status, skills, routingkartor, SOP:ar, brand-guider — versionerad kontext, inte uppgifter att stänga |
| **Agent Todo** | Finita tilldelade uppgifter som väntar på måloperatörens agent |
| **Agent Working** | **Claim-låset.** En agent har tagit issuen och ska lämna AGENT CLAIMED |
| **Agent Needs Input** | Pausad issue som väntar på svar (på Linear eller i människans egen agenttråd) |
| **Agent Review** | Färdigt arbete som fortfarande behöver mänskligt omdöme/QA/godkännande |
| **Agent Done** | Färdigt finit arbete med kvitto, ingen vidare granskning. **Måste vara en completed-kategori-status** i Linear |

## 4.5 Titelkonventionen

`[agent instructions][<andra-bracket>][<typ>] <utfall>`

- Bracket 1: alltid `[agent instructions]` (markören).
- Bracket 2: **agentkod** (`[alex-codex]`) för uppgifter riktade till en specifik runtime, eller `[all agents]` för stående kontext som gäller alla.
- Bracket 3: typ — `[task]`, `[standing_skill]`, `[standing_status]`.

Exempel: `[agent instructions][alex-codex][task] Say hello from the queue` · `[agent instructions][all agents][standing_status] Open Agent Engine status ledger` · `[agent instructions][all agents][standing_skill] Install Open Agent Engine core context v1`.

Behörighetskrav för att en uppgift ska vara körbar (eligibility): rätt **assignee** (operatören), labeln `agent-instructions`, `[agent instructions]` i titeln, och runtimens agentkod som andra bracket.

## 4.6 Steg 3: Det privata kontextpaketet

*"The public guide teaches the method. Your private packet teaches the actual engine."* Två delar:

**(a) Lokal privat kontextfil per runtime** (t.ex. `~/.codex/skills/open-agent-engine/SKILL.md`) med: engine-version, agentkod, Linear-team/projekt, label, tillåtna källor, statusliggarens issue-ID, ev. skill-katalogens issue-ID, prenumererade optional skills, säkerhetsgränser. Starter-mallens regler (kärnpunkterna, lätt kondenserade):

- Processa max EN körbar task-issue per körning; endast issues tilldelade denna operatör; endast rätt label + titelmarkör + agentkod i andra bracket ( `[all agents]`-standing gäller alla).
- Före taskarbete: kontrollera obligatoriska standing-kontextversioner; kontrollera ENDAST prenumererade optional skills; bläddra/installera aldrig optional skills under rutinkörningar.
- Claima genom att flytta till Agent Working och lämna AGENT CLAIMED; **läs om issuen efter claim**.
- Klart utan mänskligt omdöme → AGENT DONE + Agent Done. Klart men kräver review/QA/godkännande → AGENT DONE + Agent Review.
- Saknat svar hör hemma på Linear → EN specifik fråga + AGENT BLOCKED + Agent Needs Input. Frågan gäller lokala permissions/skill-installation/kontoauktoritet/privat kontext → fråga i människans EGEN agenttråd + AGENT HUMAN HOLD + Agent Needs Input.
- **Fråga alltid före:** publicering, e-post, offentliga inlägg, deploy, faktureringsändringar, credential-ändringar, destruktiv radering, kundvända ändringar.
- Utökad förmåga/auktoritet/verktygsåtkomst/runtime-byte kräver färskt godkännande.

**(b) Privat Standing setup-issue:** `[agent instructions][all agents][standing_skill] Install <Engine Name> core context v<version>`, label `agent-instructions`, status Standing. Innehåll: vad enginen är till för; statusliggarens ID; routingkartans ID; optional-skill-katalogens ID; privata kontextpaket att installera; regeln att optional skills är upptäckbara men inte installeras vid setup; setup-steg per runtime; krav på **AGENT AUTOMATION READY**-kvitto efter install + smoke test. **Privacy:** org-scheman, brand-guider, kundkontext, secrets, kontodetaljer och privata skill-kroppar stannar i privata issuen eller lokal runtime-kontext.

Kvittot **AGENT APPLIED** lämnas först efter att en runtime FAKTISKT installerat/adapterat målversionen lokalt.

## 4.7 Optional Standing Skills (katalog utan auto-install)

- En katalog-issue listar valbara delade förmågor; **standard-setup registrerar bara VAR katalogen finns** — installerar inget, aktiverar inga verktyg, ger ingen ny auktoritet.
- En kanonisk Standing-issue per optional skill: syfte, runtime-stöd, install-källa, version, update-kanal, godkännanderegler, kvittomallar.
- När människan ber om att bläddra: agenten läser katalogen och sammanfattar. **Första install/adaption kräver mänskligt godkännande i den runtimens egen agenttråd/app.**
- **Godkännande skapar prenumeration:** samma godkännande täcker framtida buggfixar och same-scope-uppdateringar för den skillen i den runtimen. **Scope-utökning frågar igen** (nya permissions, nya externa handlingar, nya verktyg, annan runtime-gräns).
- Rutinkörningar kontrollerar endast redan prenumererade skills för same-scope-uppdateringar.
- Första exemplet i katalogen: `visible-grok-claude-delegation` (Codex koordinerar Grok + Claude Code synligt, med kvitton).

## 4.8 Steg 4: Statusliggaren

En **Standing**-issue (inte en uppgift att stänga): `[agent instructions][all agents][standing_status] Open Agent Engine status ledger`, label `agent-instructions`. **Varje agent äger exakt EN toppnivåkommentar** som börjar exakt `AGENT STATUS` och uppdateras **på plats** varje körning (aldrig nya heartbeat-kommentarer). Format (verbatim):

```
AGENT STATUS
Agent: <agent-code>
Human/operator: <name or unknown>
Runtime: <Codex | Claude | Grok | other>
Automation: <automation name or manual>
Automation state: <installed | manual-required | blocked | paused>
Last heartbeat: <ISO8601 timestamp>
Last queue result: <checking | none | observed ISSUE-123 | claimed ISSUE-123 | completed ISSUE-123 | blocked ISSUE-123 | holding ISSUE-123 | resumed ISSUE-123 | failed ISSUE-123>
Last successful run: <ISO8601 timestamp or unknown>
Local context: <engine version>; <routing map version>
Optional skills: <none or skill-id@version subscribed>
Notes: <none or short blocker>
```

Semantik: `blocked ISSUE-ID` = Linear-besvarbar blockering; `holding ISSUE-ID` = human-thread-hold; `completed ISSUE-ID` endast när uppgiften faktiskt är klar.

## 4.9 Steg 5: Kökörarens (queue runner) exakta ordning

Runnern är EN instruktion agenten upprepar. En körning ("heartbeat") = en exekvering av prompten — manuellt triggad eller schemalagd (runtimens scheduler/cron). Stegen i ordning (från runner-prompten, komplett):

1. Identifiera runtimens agentkod.
2. Öppna statusliggaren; hitta denna agents toppnivå-AGENT STATUS-kommentar.
3. Uppdatera den **på plats** med `Last queue result: checking` + aktuell timestamp.
4. **Obligatorisk standing-preflight:** jämför målversioner för delade skills, SOP:ar, routingkartor, röstguider, säkerhetsregler före nytt taskarbete.
5. **Optional-skill-preflight** endast för prenumererade skills; applicera same-scope-uppdateringar automatiskt och lämna AGENT SKILL UPDATED endast efter en verklig lokal uppdatering; bläddra/installera inget nytt.
6. **Kontrollera AGENT HUMAN HOLD-issues:** om en hållen issue nu visar AGENT HUMAN ANSWERED → flytta tillbaka till Agent Working, lämna AGENT RESUMED, slutför, stoppa efter denna enda issue.
7. **Kontrollera AGENT BLOCKED-issues:** om svaret nu finns på samma issue → Agent Working, lämna AGENT UNBLOCKED sedan AGENT RESUMED, slutför, stoppa.
8. **Kontrollera delegerade issues** som denna agent routat till någon annan; lämna AGENT FOLLOW-UP om något ändrats.
9. Om ingen hold/blockering är redo: hitta den **äldsta** körbara Agent Todo-uppgiften tilldelad denna operatör (eligibility-kraven i 4.5).
10. Finns ingen: uppdatera liggaren `Last queue result: none` och stoppa.
11. Finns en: flytta till Agent Working, lämna **AGENT CLAIMED**.
12. **Läs om issuen efter claim.**
13. Gör ENDAST det scopade arbetet.
14. Klart utan mänskligt omdöme → **AGENT DONE** + Agent Done. Klart men review/QA/godkännande/publicering behövs → **AGENT DONE** + Agent Review.
15. Saknat svar hör hemma på Linear → EN specifik fråga, **AGENT BLOCKED**, Agent Needs Input, liggaren `blocked ISSUE-ID`, stoppa.
16. Svaret hör hemma i människans egen agenttråd/app → fråga där, **AGENT HUMAN HOLD**, Agent Needs Input, liggaren `holding ISSUE-ID`, stoppa.
17. Oväntat exekveringsfel → **AGENT FAILED** med sista säkra steget + antal försök.
18. Uppdatera liggaren med completed/blocked/holding/resumed/failed/observed/claimed + issue-id.
19. **Stoppa efter exakt EN task-issue.**

Boundaries-blocket (verbatim): *"Never publish, email, Slack-post, deploy, delete, change billing, change credentials, or make outward-facing changes unless the issue explicitly grants that approval."*

## 4.10 Kvittovokabulären — komplett, ordagrant

*"Receipts are the short status comments agents leave on issues and the ledger. Use these exact tokens so every runtime and human reads the loop the same way."*

| Token | Betydelse |
|---|---|
| **AGENT CLAIMED** | Postas direkt efter flytt till Agent Working. Claim-låset som hindrar en annan runtime från att ta samma uppgift. |
| **AGENT DONE** | Det scopade arbetet är färdigt. Paras med Agent Done (ingen review behövs) eller Agent Review (människa måste bedöma). |
| **AGENT BLOCKED** | Det saknade svaret hör hemma på denna Linear-issue. Ställ EN specifik fråga och flytta till Agent Needs Input. |
| **AGENT UNBLOCKED** | Postas när en blockerad issues svar anlänt på samma issue, omedelbart före AGENT RESUMED. |
| **AGENT HUMAN HOLD** | Svaret hör hemma i människans egen agenttråd/app: permissions, installationer, kontoauktoritet. Flytta till Agent Needs Input. |
| **AGENT HUMAN ANSWERED** | Postas på issuen när människan besvarat en hold i sin egen tråd, vilket frigör arbetet. |
| **AGENT RESUMED** | Postas när en pausad issue återupptas, efter AGENT UNBLOCKED eller AGENT HUMAN ANSWERED. |
| **AGENT FAILED** | Endast oåterkalleligt fel. Registrera sista säkra steget och antal försök, stoppa sedan. |
| **AGENT APPLIED** | Postas av en runtime efter att den FAKTISKT installerat/adapterat en standing-kontextversion lokalt. |
| **AGENT SKILL SUBSCRIBED** | Postas när en människa godkänner första install/adaption av en optional standing skill. Godkännandet täcker även framtida same-scope-uppdateringar för denna runtime. |
| **AGENT SKILL INSTALLED** | Postas efter att runtimen faktiskt installerat/adapterat den optionella skillen lokalt. |
| **AGENT SKILL UPDATED** | Postas efter att en prenumererad optional skill fått en same-scope-lokal-uppdatering. |
| **AGENT SKILL DECLINED** | Postas när människan avböjer eller skjuter upp en optional standing skill. |
| **AGENT FOLLOW-UP** | Postas på en delegerad issue som denna agent routat till någon annan, när den issuens tillstånd ändrats. |
| **AGENT STATUS** | Den enda toppnivå-liggarkommentaren varje agent äger och uppdaterar på plats varje körning. |

(Därtill omnämns **AGENT AUTOMATION READY** som obligatoriskt kvitto i setup-issuen efter install + smoke test, och **AGENT CONNECTION TEST** i anslutningsverifieringen.)

## 4.11 Task-issue-mallen

```
Titel:    [agent instructions][<agent-code>][task] <utfall>
Label:    agent-instructions
Status:   Agent Todo
Assignee: människan/operatören vars lokala agent ska utföra ticketen

Body:
  Requester            — vem som frågar och hur man följer upp
  Desired outcome      — det konkreta resultatet
  Context              — varför det spelar roll, bakgrund
  Sources              — länkar, filer, issue-ID:n, eller none
  Do                   — steg-för-steg-instruktioner
  Acceptance criteria  — observerbara framgångsvillkor
  Output/handoff       — var svaret/artefakten/PR:en/kommentaren ska landa
  Boundaries           — vad agenten får göra, vad som kräver godkännande, vad som är utanför scope
```

Regeln för routade uppgifter: skriv så att målagenten kan läsa den **kall** (utan konversationskontext).

## 4.12 Steg 6: Smoke tests

*"The smoke test should be tiny. You are testing the loop, not the agent's intelligence. Do not trust the engine until claim, done, blocked-resume, and human-hold behavior have all worked."*

1. **Basic hello-world:** `[agent instructions][<din-agentkod>][task] Say hello from the queue`. Verifiera AGENT CLAIMED → AGENT DONE → Agent Done → liggaren `completed ISSUE-ID`.
2. **Blocked-resume:** skapa avsiktligt ofullständig issue (t.ex. utelämnat datumintervall). Körning 1 → AGENT BLOCKED + Agent Needs Input; svara på samma issue; nästa körning → AGENT UNBLOCKED + AGENT RESUMED → AGENT DONE.
3. **Human-hold:** be agenten begära lokal runtime-permission i den aktuella agenttråden. Verifiera AGENT HUMAN HOLD (inte BLOCKED), liggaren `holding`, AGENT HUMAN ANSWERED efter ditt svar, sedan slutförande.
4. **Optional skill directory check:** fråga vilka optional Standing Skills som finns. Verifiera att agenten sammanfattar katalogen **utan att installera något**.

Plus: runnern stoppar efter exakt en task-issue.

## 4.13 Teamvägen (Team Engine)

Samma system med EN extra regel: **routa arbete till människan som äger målagenten.** *"Do not assign another person's agent task to yourself and expect their automation to see it."*

1. Privat **routingkarta** (Standing-issue): per människa — Linear-assignee, runtime(s), agentkod(er), ansvarsområde. Exempel: "Alex Example — agent codes alex-codex, alex-claude — Route to Alex for: engineering, local repo work, QA".
2. Onboarda EN teammedlem i taget: installera kontext, skapa deras AGENT STATUS-kommentar, kör ett litet smoke test tilldelat dem.
3. Tilldela korsagent-arbete till människan som äger målagenten.
4. Skriv varje routad uppgift så målagenten kan läsa den kall (mallen i 4.11).
5. EN standing-uppdaterings-issue per delad kontextfamilj; agenter jämför målversion mot lokal version under preflight (uppdatera version + changelog på plats — inte nya duplikat-tickets).
6. Kartregler: om målagenten inte är online i statusliggaren, säg det innan du litar på överlämningen; en olistad agent får föreslå en unik agentkod och be sin människa fylla i routingdetaljer som kommentar på kart-issuen; mänskligt godkännande krävs för publicering och kundvända ändringar.

## 4.14 Felsökning (alla 10 fall ur guiden)

| Symptom | Åtgärd |
|---|---|
| Agenten säger att ingen issue finns | Kontrollera assignee, `agent-instructions`-labeln, Agent Todo-status, `[agent instructions]`-titelmarkören, och att andra bracket matchar runtimens agentkod |
| Agent claimar men en annan agent arbetar också på den | Flytta status till Agent Working FÖRE taskarbete, lämna AGENT CLAIMED, läs om issuen. Statusflytten är det synliga låset. Två egna runtimes under en operatör → scopa pickup på agentkod-bracketen |
| Liggaren fylls av heartbeat-kommentarer | Hitta toppnivå-AGENT STATUS-kommentaren för agentkoden och uppdatera på plats via kommentar-id |
| Blockerade issues plockas aldrig upp igen | Behandla AGENT BLOCKED som paus; leta efter svaret på samma issue, lämna sedan AGENT UNBLOCKED + AGENT RESUMED |
| Agenten ställer runtime-permissionsfrågor i Linear | Använd AGENT HUMAN HOLD: fråga i människans egen tråd/app, behåll issuen i Agent Needs Input, sätt liggaren `holding ISSUE-ID` |
| Varje standing-uppdatering skapar en hög duplikat-tickets | EN standing-issue per kontextfamilj; uppdatera version + changelog på plats; agenter jämför versioner i preflight |
| Agenten installerar en optional skill under setup | Separera katalogen från installation: setup registrerar bara katalogen; första install kräver explicit mänskligt godkännande i runtimens tråd |
| Installerade optional skills får inga fixar | Kontrollera lokal prenumerationsmarkör, kanonisk skill-issue och AGENT SKILL SUBSCRIBED-kvittot; prenumererade skills ska auto-uppdatera same-scope i preflight |
| Arbete tilldelat annan agent går ingenstans | Tilldela issuen till människan som äger målagenten; kontrollera routingkarta, målets heartbeat, label, titelmarkör, status |
| Agenten försöker publicera/mejla/posta/deploya/fakturera/radera | Lägg explicita fråga-först-gränser i privat kontext och task-body; externa/destruktiva handlingar kräver godkännande på issue-nivå |

---

# 5. Underhållsloopen och självförbättringsmönstren

## 5.1 The Agent Maintenance Loop

En repeterbar inspektion för agenter som gått från experiment till riktigt arbete. Slutar alltid i ETT skriftligt beslut: **keep, change, pause eller retire.**

### Harnessen — det du underhåller

*"You are not maintaining a prompt. You are maintaining the whole harness around delegated work."* Harness = allt som gör en modell till en arbetare (8 delar): instruktionerna; källorna/exemplen den läser; minnet mellan körningar; verktygen; permissions; modellen + inställningar; den mänskliga granskningen; evals som kontrollerar den. Diagnostisk omram: en driftande agent låter fortfarande flytande — frågan är inte "är outputen välskriven" utan **"is this fluent output still doing the current job."**

### De sju ytorna (inspektionsvokabulären)

| Yta | Fråga | Trasig när | Fix |
|---|---|---|---|
| **Job** | Har arbetet tyst växt förbi jobbmeningen? | Körningar innehåller uppgifter meningen aldrig nämnde | Snäva om jobbet eller dela ut nytt arbete till egen agent |
| **Diet** | Är allt den läser aktuellt och korrekt? | Citerar gammal policy, lutar sig mot inaktuellt exempel | **Uppdatera/peka om källorna — INTE en regel som säger "use the latest version"** |
| **Memory** | Bär den ett faktum som inte längre är sant? | Föråldrat sparat antagande dyker upp i aktuellt arbete | Rensa/korrigera lagrat minne |
| **Tools** | Når den rätt handling utan att snubbla på fel? | Verktygsuppsättningen så bred/överlappande att fel verktyg väljs | **Ta bort verktygen den inte behöver** |
| **Reach** | Kan den röra mer än ägaren hinner granska? | Makten att sända/spendera/ändra/publicera överstiger människans upptäcktsförmåga | Snäva räckvidden tills varje riskabel handling passerar en person |
| **Proof** | Kan en människa kontrollera arbetet, eller bara lita? | Output ser färdig ut men visar inga källor/resonemang | Kräv citat/visa arbetet |
| **Value** | Agerar någon på outputen? | Plausibelt men ignorerat — omskrivet, skippat, oläst | **Ändra jobbet eller pensionera agenten. Mer polish hjälper inte** |

### Triggers — aldrig kalenderstyrt

Kör loopen när något ändras, INTE på schema och INTE bara när något går sönder. Fyra triggerfamiljer (en räcker): **Upstream change** (ny modellversion, ändrat verktyg, uppdaterad sanningskälla) · **Scope creep** (används bortom ursprungsjobbet, ber om mer åtkomst) · **Rising human cost** (samma sak fixas om och om igen, review tar längre än arbetet) · **Quiet failure** (nästan-miss, eller output ingen längre använder). Scopingregel: **en agent + en signal per pass**; hittas mer — kör klart detta pass först.

### De sex stegen

1. **Namnge det aktuella jobbet** med fem-delarsmallen (verbatim): *"This agent's job is to [produce this work] from [these sources] for [these users], with [this human review] before [this consequence]."* Kan meningen inte fullbordas är DET första fyndet. Hållbarhetsregel: "Draft refund replies for billing tickets under $100 …" är underhållbart; "Handle support" är det inte.
2. **Granska de senaste ~10 körningarna** (bara bevisinsamling): användes outputen eller skrevs den om/tappades? vad ändrade människan och varför? vilken källa/vilket verktyg? vad kunde den inte verifiera? var tog review för lång tid? **Tröskelregel: en engångsfix är brus; samma korrigering i 3+ körningar är signal** — harnessen lär ut felet.
3. **Inspektera de sju ytorna i fast ordning** (Job → Diet → Memory → Tools → Reach → Proof → Value) så orsaken fixas, inte symtomet. Verdikt per yta: **ok / drifting / broken**. Output: (yta, problem, trolig fix)-tripplar. Fixa inget än.
4. **Bygg ett replay pack:** 5–20 fall med kända rätta svar ur verklig historik, **inklusive minst ett högriskfall där enda rätta draget var att stoppa och eskalera**. Poängsätt **processen, inte bara svaret**: rätt källa? rätt verktyg? inom jobbet? visade bevis? stannade när den skulle? tog review mindre tid än arbetet? Kör en gång FÖRE ändringar = baslinjen som fixarna måste slå.
5. **Delete before you add:** *"Most harnesses rot because every fix is one more instruction. Try subtraction first."* Åtta raderingsfrågor: matas den av en inaktuell källa? lär ett dåligt exempel? för brett verktyg? för vagt jobb? gammalt minne som spelas upp? högre reach än nödvändigt? saknas proof? är modellen numera bra nog att en gammal workaround stör? Ny instruktion tillåts först när raderingarna är uttömda OCH replay-packet **failar utan den och passerar med den**.
6. **Besluta exakt ett av Keep / Change / Pause / Retire** och skriv underhållsposten (7 fält): trigger, aktuell jobbmening, körningsmönstret, ändrade ytor, replay-resultat, beslutet, villkoret som ska trigga nästa granskning. **Lagras hos agentens config/dokumentation, inte i en chattlogg.**

Explicit utpekade anti-mönster: redigera prompten som reflex; lägga till "use the latest version"-regler; addera instruktioner för att det "känns säkrare"; polera output ingen använder; bedöma flyt i stället för jobbpassning; kalenderrevision; governance-review i stället för en-pass-fix.

## 5.2 Självförbättringsmönstren

### Auto-Capture (OB1-skill + Claude Code-adapter)

**Basprincipen:** sessionsslut är ett capture-ögonblick — "the write side of the Open Brain flywheel". Vid "wrap up"/"park this"/sessionsslut: identifiera **ACT NOW**-poster + EN sessionssammanfattning; dedup-kolla mot Open Brain (`search_thoughts`) före capture; varje ACT NOW-post är en självständig tanke med idén i starkaste form + varför den spelar roll + 2–3 konkreta nästa steg + proveniens. Hoppa över råtranskript, parkerade/dödade poster och dubbletter. Om capture-verktyget failar: **hitta inte på framgång**.

**Adaptern** (`auto-capture-claude-code`): en Claude Code **Stop-hook** som kör ett Node-skript vid varje sessionsslut och POST:ar transkriptet till `open-brain-rest/ingest`. Mekanik värd att kopiera: hård timeout 25 s (hooken får aldrig blockera avstängning); minst 3 användarturer annars skip; idempotensnyckel `cc:<sessionId>:<sha8>`; retry-kö + dead-letter-mapp; 4xx = permanent (retry:a inte — maskerar återkallade nycklar); promptinjektionsskydd (transkriptinnehåll wrappas i `<thought_content>` och escapes); dispositionslogg per körning; skriptet exit:ar alltid 0.

### Aiception (f.d. Claudeception) — "skills that create other skills"

Kontinuerligt lärande: extrahera återanvändbar kunskap ur arbetssessioner till nya SKILL.md-filer, med Open Brain som dedup-/upptäcktslager.

- **Fem upptäcktstyper:** icke-uppenbara lösningar; felupplösning (vilseledande felmeddelande → verklig rotorsak); verktygsintegrationskunskap dokumentationen inte täcker; arbetsflödesoptimeringar; projektspecifika mönster.
- **Fyra kvalitetskriterier (alla krävs):** Reusable · Non-trivial · Specific · Verified.
- **Sju extraktionssteg:** sök Open Brain först → kolla lokala skill-kataloger (samma trigger + samma fix = uppdatera; samma trigger + annan orsak = ny + korslänk) → researcha best practice vid behov → strukturera enligt mall (enrads-description med exakta triggers/felmeddelanden) → spara → `capture_thought` ("New skill created: … Trigger: … Location: …") → kvalitetsgrind (inga credentials/interna URL:er; OB sökt före, capturat efter).
- **Retrospektivläge** `/aiception` vid sessionsslut: lista kandidater, extrahera topp 1–3.
- **Auto-triggers:** lösning krävde >10 min undersökning utanför dokumentation; vilseledande-fel-bugg fixad; workaround funnen genom experiment; avvikande konfiguration; flera ansatser innan framgång.
- **Anti-mönster:** överextraktion; vaga descriptions ("Helps with React" ytnar aldrig); overifierade lösningar; dokumentationsduplicering; skill-hamstring (vid 30+ skills: granska de 5 minst nyligen ändrade för depreciering). Förväntad takt ~1–3 nya skills per aktiv utvecklingsvecka; *"not every session produces one, and that's correct."*

### Panning for Gold (brain-dump-processorn, mest stridstestade prompten i OB1)

Fasstruktur: **Fas 0** spara råinput till permanent fil FÖRE analys → **Fas 0.5** talarkonsolidering (auto-talarlabels är aktivt vilseledande; ankarrader per person; scenbaserad omattribuering) → **Fas 1** extrahera ALLA trådar (läs varje rad; tangenter är features; sammanfattningar först, transkript sedan — sparar 10–20k tokens) → **Fas 2** utvärdera (triage: max 3–5 ACT NOW-kandidater får full utvärdering; max 5 bakgrundsutvärderare; alla skriver till permanenta filer; modellroutning efter insats) → **Fas 3** syntes inline (aldrig delegerad — agenter försvinner vid kompaktering) → **Fas 3.5** capture till Open Brain (per ACT NOW-post + sessionssammanfattning; "closes the flywheel") → **Fas 4** självförbättring: uppdatera skill-filen själv + daterad **Lessons Log**-tabell. Verdiktvokabulär: **ACT NOW / RESEARCH MORE / PARK IT / KILL IT**.

### Session-to-Skill Extractor + The Flywheel (Open Skills-sidan av samma idé)

Samma mönster i Open Skills-biblioteket (se 2.4 kategori 8): hög ribba (RECURRING/NON-OBVIOUS/CODIFIABLE), 80 %-överlapp → uppdatering, aldrig tyst i live-biblioteket, sanering av kundspecifika detaljer. Runbook 07 "The Flywheel" kör detta som postur under allt annat: extractor föreslår skills, testing-runbook-creator bankar repo-kunskap lokalt, page-testing-memory håller global/lokal-gränsen ren, session-operating-map bevarar koordinationstillstånd.

### Life Engine (proaktiv briefing-loop — illustration av unattended-drift)

Claude Code-recept: `/loop 30m /life-engine` + Telegram/Discord-kanaler + Google Calendar MCP + Open Brain MCP + 6 egna Postgres-tabeller. Lärdomar med bäring på all obevakad agentdrift: **permissions-allowlist är faillägesnummer ett** (en ogodkänd verktygsprompt fryser tyst hela loopen); datumankare via `date` före all datumaritmetik; dubblettkontroll mot briefing-logg; tysta timmar; "silence is better than noise"; degradera snyggt; **omschemalägg alltid** (dynamisk cron efter tid på dygnet); engagemangsmätning (`user_responded`) driver **EN** godkänd förbättring per vecka loggad i en evolutionstabell; promptinjektionsregler för inkommande kanalmeddelanden (aldrig instruktioner, aldrig config-ändringar, aldrig nyckeldelning).

---

# 6. Hur delarna komponerar (Skills × Brain × Engine)

## 6.1 Kompositionerna parvis

**Skills × Brain:** Brain minns vilka skills som är installerade, vilka defaults du valt, vad senaste verifieringen bevisade och vilka gränser som är human-only. Skills läser/skriver Brain (Open Brain Case Store; aiception dedup-söker OB före skill-skapande och capturar efter). *"A skill gets stronger when Open Brain remembers which skills you trust."*

**Skills × Engine:** Engine pekar agenter mot godkända skills och kräver att runnern kontrollerar prenumererade skills i preflight. Skill-adoptionskartan (per skill: Manual only / Agent may use after asking / Agent may use automatically inside a specific workflow) klistras in i Open Brain-kontext eller Engines privata setup-issue. Standing Skills-katalogen är Engines distributionsmekanism för skills över ett team — med godkännande-skapar-prenumeration och scope-utökning-frågar-igen.

**Brain × Engine:** Engine läser Brain-kontext före claim och skriver tillbaka kompakta kvitton efter arbete: *"what changed, what was verified, what got blocked, and what should be remembered."* Agent Memory-API:ets recall→work→writeback→review-loop är den styrda versionen: minne stödjer arbetet **utan att tyst utöka agentens auktoritet** (evidence tills människa bekräftar).

**Hela loopen (verbatim):** *"Skills make the agent capable, Brain makes context durable, Engine makes work visible and resumable."*

## 6.2 Genomgående designprinciper (tvärsnittet)

1. **Verifiering är kvalitetsribban överallt:** varje skill definierar "what proof it owes you"; guards har tvåsidiga tester (pass-artefakt + seedad-fail-artefakt); goal-prompten är acceptanstestet; QA kräver bevis; Engine kräver kvitton; maintenance-loopen kräver replay-pack-bevis för varje tillägg.
2. **Människogrinden är strukturell:** send-verbet existerar inte i verktygsytan; export stannar vid `pending`; Engine frågar före allt utåtriktat; Human Gate är produktgränsen — samma princip i tre lager (skills, byggen, kö).
3. **Kunskap på rätt plats:** globalt = process/skills; repo-lokalt = fakta/runbooks; Brain = beständigt tvärgående minne; Engine = arbets- och koordinationstillstånd. (Page Testing Memory:s split, session-operating-map, Agent Memory-scopes.)
4. **Kvitton och spårbarhet:** AGENT-tokens i Engine; run receipts i byggena; recall traces + audit events i Brain; Lessons Logs i skills. Allt arbete lämnar hållbara artefakter.
5. **Evidence vs instruction:** agentskrivet innehåll är bevis tills en människa promoverar det — i Brain (DB-constraint), i skills-biblioteket (extractor-utkast landar för review), i Engine (optional skills installeras aldrig utan godkännande).
6. **Små vokabulärer, exakta tokens:** sex statusar, ~16 kvitton, tre guard-verdikt, fyra idéverdikt, fyra underhållsbeslut — allt maskinläsbart och människoläsbart samtidigt.
7. **Inkrementell adoption:** varje primitiv står själv; komposition är frivillig tills arbetet kräver den.

## 6.3 30-dagarsmönstret

Fältguidens adoptionsplan-mall: vecka för vecka, en konkret setup-/anpassningsuppgift + ett verifieringssteg + ett skäl per vecka; standardordning Skills → Brain → Engine; ordningen bryts om flaskhalsen säger annat.

---

# 7. Källor och länkar

## 7.1 Primärkällor — unlock-ai.natebjones.com

| Sida | URL |
|---|---|
| Open Stack Field Guide (routing-guiden) | https://unlock-ai.natebjones.com/guides/open-stack/open-stack-field-guide |
| Open Engine (komplett setup-guide) | https://unlock-ai.natebjones.com/open-engine |
| Open Skills-översikt | https://unlock-ai.natebjones.com/open-skills |
| Skills-katalogen (40 skills, 8 kategorier) | https://unlock-ai.natebjones.com/open-skills/skills |
| Runbooks (10 st) | https://unlock-ai.natebjones.com/open-skills/runbooks |
| Kategorisidor | …/open-skills/core-infrastructure · context-engineering · research-thinking · writing-voice-content · web-publishing-frontend · video-media-production · testing-quality · agent-operations |
| Guides-index ("Living guides") | https://unlock-ai.natebjones.com/guides |
| The Agent Maintenance Loop | https://unlock-ai.natebjones.com/guides/agents/maintenance |
| Build a Healthcare Claim Appeals Agent | https://unlock-ai.natebjones.com/guides/build-a-healthcare-claim-appeals-agent |
| Build a Tax Prep Organizer Agent | https://unlock-ai.natebjones.com/guides/build-a-tax-prep-organizer-agent |
| Build an Email Follow-Up Agent | https://unlock-ai.natebjones.com/guides/build-an-email-follow-up-agent |
| Build your own token-burn dashboard | https://unlock-ai.natebjones.com/guides/build-your-own-token-burn-dashboard |
| Agent-discovery-filer | /llms.txt · /agents.txt · starter-kit manifest.json (SHA-256-checksummor) |

## 7.2 OB1-repot

| Resurs | URL / plats |
|---|---|
| Repo (kärnan) | https://github.com/NateBJones-Projects/OB1 — **Licens: FSL-1.1-MIT** (inga kommersiella derivat under FSL-perioden) |
| Getting started (schema-SQL, setup) | `docs/01-getting-started.md` |
| AI-assisterad setup | `docs/04-ai-assisted-setup.md` (kickoff-prompt: "Read docs/01-getting-started.md and walk me through building my Open Brain step by step.") |
| MCP-serverkod | `server/index.ts` + `server/deno.json` |
| Tool audit-guiden | `docs/05-tool-audit.md` |
| Agent Memory | `schemas/agent-memory/schema.sql` · `integrations/agent-memory-api/` · `recipes/openclaw-agent-memory/` (JSON Schema-kontrakt) · `recipes/openclaw-code-review-memory/` · `recipes/openclaw-taskflow-work-log/` |
| Capture/import | `integrations/slack-capture/` (ingest-thought) · `recipes/email-history-import/` · `recipes/chatgpt-conversation-import/` |
| Skills/recipes | `skills/` (18 paket inkl. auto-capture, aiception, panning-for-gold) · `recipes/` (life-engine, daily-digest, research-to-decision-workflow m.fl.) |
| Självhostning | `integrations/kubernetes-deployment/` (pgvector-container + Deno-MCP-server, Supabase-fritt) |
| Dashboards | `dashboards/open-brain-dashboard-next/` (Next.js) · `dashboards/open-brain-dashboard/` (SvelteKit) |
| Primitives | `primitives/` (deploy-edge-function, remote-mcp, rls, shared-mcp, troubleshooting) |
| Community | Discord https://discord.gg/Cgh9WJEkeG |

## 7.3 Substack-nyckelposter (natesnewsletter.substack.com)

- Open Brain-lansering: */p/every-ai-you-use-forgets-you-heres* (2026-03-02)
- Extensions/"two-door": */p/you-built-an-ai-memory-system-now* (2026-03-13)
- Open Skills-lansering: */p/claude-codex-agent-skills* (2026-06-19)
- Open Engine-lansering: */p/ai-agent-handoffs* (2026-06-26)
- Stack-filosofin ("build 80 % by talking to the agent"): */p/build-your-own-ai-memory* (2026-07-01)
- DB-inte-markdown-positioneringen: */p/your-ai-re-derives-everything-it*

## 7.4 Kända luckor i underlaget

- Den exakta implementationen av `open-brain-rest`-gatewayen, `smart-ingest` och dashboardens serverkontrakt är rekonstruerade från klientkod, inte digesterade från källa.
- Citation Guardens implementation (hur claims matchas mot chunks) är specificerad som kontrakt (verdikt + exitkoder), inte som kod.
- Fullständig SQLite-DDL för case-store (kolumnnivå) ges inte — bara tabellnamn och logiska fält.
- Vissa kontraktsavvikelser i Agent Memory v1 (usage-report-format, visibility-vokabulär, ej verkställd `max_tokens`) är dokumenterade som öppna frågor i OB1.
- Discord-capture är en stub utan kod; Nextcloud Talk-ersättning byggs mot det generiska capture-bot-kontraktet (3.8).

---

*Slut på kartläggningen. Nästa steg (separat dokument): ITSL-anpassad arkitektur och byggplan.*
