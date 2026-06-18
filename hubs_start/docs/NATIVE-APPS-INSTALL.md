<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Native NC-appar — installation, vad de driver, och prerequisites för det som inte kan wire:as fullt ut

> **Syfte:** ge driften en konkret lista över vilka Nextcloud-appar som ska installeras bakom
> Hubs-dashboardens widgetar, med `occ`-kommandon, vad varje app driver, och — för de tunga
> beroendena (BankID-e-underskrift, SDK, recording/transkribering, Collabora, lokal LLM) — exakt vad
> som krävs för att gå från "renderas i UI" till "skarpt i drift".
>
> **Plattform:** server v32 (Hub 25 Autumn). **Brand-regel:** i UI säger vi aldrig "Nextcloud"/"Talk";
> här namnges app-id för drift. **Arkitektur:** Hubs är mellanlagring — slutlagringen är facksystemet;
> apparna nedan är Hubs interna staging-lager. Datum: 2026-06-13.
>
> Alla `occ`-kommandon antar att du kör som webbserveranvändaren, t.ex.
> `sudo -u www-data php occ …` (eller, i Docker/AIO, `occ` via container-exec).

---

## Nivå 1 — wirebart idag på ren v32 (community-/core-appar, installeras direkt)

Dessa driver ~70 % av widgetkatalogen och kan demas utan extra backend.

```bash
# Uppgifts-/bevakningsmodulen (bevakningar, minaUppgifter, granskningsko, todolista)
occ app:install deck
occ app:install tasks            # CalDAV VTODO, native VALARM-påminnelser

# Kalender + bokningsbar tid + (P2P) säkert möte (dagensMoten, bokningsbaraTider)
occ app:install calendar         # inkl. Appointments-bokning
occ app:install spreed           # Talk/spreed — P2P räcker för demo; HPB för grupp (se Nivå 3)

# Säkra filer / ärenderum (arenderum, senasteFiler) + gallring (arkivGallring)
occ app:install groupfolders     # Team folders + avancerad ACL
occ app:install files_retention  # regelstyrd gallring (restricted-tagg)
# files + files_versions + workflowengine (automated tagging) finns i core

# Strukturerade register (osynlig motor bakom nytta, orosanmalningar, samverkansavvikelser,
# arsrakningar, uppdragskontroll, incidentrapporter, complianceStatus, registreraFordela, fristStrip)
occ app:install tables

# Säkert formulär / samtycke (mallarSamtycke, internt led i orosanmalningar)
occ app:install forms

# Kunskapsbank (kunskapsbank)
occ app:install collectives      # kräver circles (Team), finns i core
occ app:install circles          # om ej redan aktiv

# Spårbarhet (sakerhetshandelser, loggSparbarhet, complianceStatus, senaste-händelser i arenderum)
occ app:enable activity          # core

# Aktivera installerade appar (om app:install inte redan aktiverat)
occ app:enable deck tasks calendar spreed groupfolders files_retention tables forms collectives
```

**Vad var och en driver (widget → app):**

| App-id | Driver dessa widgetar | Roll i mellanlagring |
|---|---|---|
| `deck` | `bevakningar`, `granskningsko`, `todolista`, delad-kö-lager bakom `rehabarenden`/`orosanmalningar`/`utskrivningsbevakning` | Delade köer/boards, status via stacks, due dates, tilldelning |
| `tasks` | `minaUppgifter`, påminnelse-motorn bakom `bevakningar`/`fristStrip` | Personlig VTODO-lista med native påminnelser (det Deck saknar) |
| `calendar` | `bokningsbaraTider`, `dagensMoten` | Appointments + auto-videorum (med spreed) |
| `spreed` | `dagensMoten`, `bokningsbaraTider` (mötesrum), `identitetsBadge` (lobby) | Säkert möte; P2P idag, HPB för grupp/inspelning/transkription |
| `groupfolders` | `arenderum`, dokumentdelen av alla ärende-personor | Ärenderum = en Groupfolder per dnr/barn/uppdrag med ACL + gallringstagg |
| `files_retention` | gallrings-countdown i `arenderum`, `arkivGallring` | Operationaliserar dokumenthanteringsplanen i Hubs-lagret |
| `tables` | `nytta`, `samverkansavvikelser`, `incidentrapporter`, `uppdragskontroll`, status/frist bakom flera | Strukturerat statusregister + regel-/flaggningslogik (renderas som widget, aldrig rå tabell) |
| `forms` | `mallarSamtycke`, internt led i `orosanmalningar` | Internt strukturerat samtycke (ej publik e-tjänst — saknar fildropp/förgrening) |
| `collectives` | `kunskapsbank` | On-prem wiki för rutiner/mallar/gallringsplaner |
| `activity` | `sakerhetshandelser`, `loggSparbarhet`, `complianceStatus`, händelsedelen av `arenderum` | Spårbarhetskälla (filtreras/översätts till ärendekontext, ej rå feed) |

---

## Nivå 2 — kräver EN backend-komponent (planera in)

### 2.1 Collabora / OnlyOffice (samredigering i ärenderum)

Krävs för on-prem samredigering i `arenderum`/`rehabarenden`/`namndcykel` (plan för återgång, utredningstext, nämndunderlag).

```bash
# Collabora Online (rekommenderat on-prem): kör Collabora-servern (CODE) som egen container,
# installera anslutnings-appen och peka ut WOPI-värden.
occ app:install richdocuments
occ config:app:set richdocuments wopi_url --value="https://collabora.example.se"
# (OnlyOffice-alternativ: app:install onlyoffice + DocumentServer-container)
```
**Prerequisite:** separat Collabora CODE- (eller OnlyOffice DocumentServer-) container nåbar över WOPI; reverse proxy med TLS.

### 2.2 Whiteboard-WebSocket-backend (SIP-/planeringstavla i mötet)

```bash
occ app:install whiteboard
occ config:app:set whiteboard collabBackendUrl --value="https://whiteboard.example.se"
occ config:app:set whiteboard jwt_secret_key --value="<delad hemlighet>"
```
**Prerequisite:** `whiteboard_server` (WebSocket-backend) som egen tjänst. Grundläge funkar utan, men inte flerpartslive. Använd som möteskomplement — inte ensam bärare av beslut (WCAG).

### 2.3 LibreSign — internt/lågrisk-signeringsspår (`attSignera`/`skickatForSignering`/`justeringAnslag`)

```bash
occ app:install libresign
occ libresign:install --all     # drar in cfssl (CA), Java + JSignPDF, pdftk
occ libresign:configure:cfssl   # generera lokal rot-CA (eller via Admin → LibreSign)
```
**Prerequisite + viktig begränsning:** `occ libresign:install` kräver `cfssl` (eller OpenSSL-motorn i
nyare versioner), **Java + JSignPDF** och **pdftk**. LibreSign signerar mot en **egen självsignerad
rot-CA** — identitetsfaktorerna är konto/e-post/SMS/klick (SES/svag AES), **ingen native
BankID/Freja/SITHS**. → Använd LibreSign **bara för internt lågrisk-"Godkänn"** och märk identiteten
ärligt i UI ("konto/SMS, ej BankID"). Den primära BankID-vägen är Inera/Sweden Connect (se §3.4).

---

## Nivå 3 — tung backend ("wow men dyrt"), detaljerade prerequisites

### 3.1 Talk High-Performance Backend (HPB) — förutsättning för grupp, inspelning, transkription

**Vad:** signaling-server (Spreed) + Janus SFU (WebRTC-gateway) + NATS. Krävs för stabila gruppmöten
(4+ deltagare, SIP-möten, nämndberedningar) och är **förutsättning** för recording + live-transkription.

**Prerequisites:**
- Dedikerad host/bandbredd/CPU (separat drift).
- `signaling`-server konfigurerad i Talk-inställningarna (`/settings/admin/talk`).
- coturn (TURN/STUN) för NAT-traversal.
- `occ talk:signaling:add <url> <secret>` för att registrera HPB:n.

Utan HPB: bara P2P (~3–5 deltagare) — räcker för demo av enkla säkra samtal, men inte för wow-flödena.

### 3.2 Talk recording server — inspelat säkert möte (`motesanteckningar`)

**Vad:** separat tjänst som joinar mötet som osynlig browser (Selenium) och spelar in → **WebM** tillbaka som fil.

**Prerequisites:**
- **HPB krävs** (recording-servern ansluter via HPB:ns signaleringsprotokoll).
- **Egen maskin** — kontinuerlig videoinspelning är CPU-tung (~4 kärnor + rejält RAM per parallell inspelning).
- Konfig: HPB-domän, Nextcloud-domän, `recording_secret`, `internal_secret`; URL-schema `https://`.
- Styr output-WebM **in i ärenderummet/Groupfolder** (ärver ACL + Retention) i stället för default `Talk/Recordings`.
- **`recording_consent` = påtvingat** (eller moderator + default på) i `/settings/admin/talk`; samtyckes-tidsstämpeln loggas som åtkomst-/samtyckeshändelse (del av rättslig grund + spårbarhet).

### 3.3 Transkribering + lokal AI (`motesanteckningar`) — KB-Whisper + llm2

**AppAPI + Deploy Daemon (Docker) krävs för alla ExApps nedan:**
```bash
occ app:install app_api
# Registrera en Deploy Daemon (Docker socket) via Admin → AppAPI, eller occ app_api:daemon:register
```

**Efterhands-transkribering — `stt_whisper2` med KB-Whisper (Sverige-kärnan):**
```bash
occ app_api:app:register stt_whisper2   # via AppAPI/Deploy Daemon (Docker, ExApp)
# Lägg KB-Whisper-modellen i datavolymen och peka ut den:
#   volym: nc_app_stt_whisper2_data  → modell: KBLab/kb-whisper-large (faster-whisper/CTranslate2)
```
**Prerequisites:** NC ≥28, **AppAPI ≥2.3.0**, Docker. GPU NVIDIA **min 4 GB VRAM** (large mer), CUDA ≥12.2;
eller CPU 10–20 kärnor (egen maskin). **Modellval (Hubs viktigaste STT-konfig):** byt ut OpenAI
`whisper-large-v3` mot **KB-Whisper** (KBLab/Kungliga biblioteket, **Apache-2.0**, ~47 % lägre WER på
svenska; t.o.m. `kb-whisper-small` slår large-v3). Rekommendation: `kb-whisper-large` på GPU, annars
`kb-whisper-small/medium` på CPU/liten GPU. Skriv in i upphandlingsunderlaget: "svensk-tränad, KB/statlig
härkomst, Apache-2.0, on-prem".

**Live-textning — `live_transcription` (Vosk):**
```bash
occ app_api:app:register live_transcription
```
**Prerequisites:** HPB (släppt efter sep 2025), NVIDIA-GPU **≥10 GB VRAM**, CUDA ≥12.4.1. **🚩 Showstopper:
Vosk stödjer INTE svenska** → använd **inte** i svenska persona-flöden nu; svensk kvalitet kommer via
efterhands-KB-Whisper. Dokumenteras som "kommer/villkorad av svensk modell".

**Lokal LLM — `llm2` (sammanfattning):**
```bash
occ app_api:app:register llm2           # llama.cpp, GGUF
occ app:install assistant               # UI-lager ovanpå text-providern
# Lägg grön-ratad GGUF i nc_app_llm2_data (Llama 3.1 8B default; OLMo 2 för grön-suveränitetsargument)
```
**Prerequisites:** **AppAPI ≥3.1.0**; NVIDIA **≥8 GB VRAM + ≥12 GB system-RAM** (CUDA ≥12.4), eller CPU
10–20 kärnor + ≥12 GB RAM. **⚠️ Kontextfönster 4–8k tokens** → långa mötestranskript kräver
**chunkning/map-reduce** (summera per avsnitt, sedan summera summorna) — den enskilt viktigaste
bygguppgiften i sammanfattningslagret. Valfritt: `call_summary_bot` (deltagare + uppgifter i mötestråden).

**Doktrin (gäller all AI):** endast **lokala, grön-ratade** modeller, avstängbart, transparent ("varför"),
aldrig destruktivt; AI **föreslår** utkast men fattar aldrig beslut och skriver aldrig till facksystemet
(GDPR art. 22). **Human-in-the-loop obligatoriskt:** sammanfattning är utkast tills handläggaren redigerat
och **"Godkänt"** (loggat). Rå-WebM + rå-transkript får kort Retention-gallring; bara godkänd text committas
till facksystemet. **Sekretessbelagda klientsamtal: dokumentera, kör inte skarpt än** — invänta IMY/SKR/
Socialstyrelsen; on-prem löser tredjelandsfrågan men inte hela OSL/arkiv-frågan. **Demobart nu:** internt
icke-sekretessbelagt möte (t.ex. nämndberedning).

### 3.4 Skarp e-underskrift med svensk BankID/Freja-AES (INTE en NC-app)

`attSignera`/`skickatForSignering`/`justeringAnslag` ska byggas mot en **signeringsadapter** med två
backends bakom samma kö-UI:

- **LibreSign** (§2.3) — internt lågrisk-"Godkänn", ärligt etiketterat.
- **Inera Underskriftstjänsten — API-varianten** (primär väg vård/omsorg): verksamhetssystemet anropar
  API:t med **mutual TLS (mTLS)** + **SITHS funktionscertifikat** (bär aktörens HSA-id); signering via
  **SITHS eID / BankID / Freja eID Plus**; format **PDF + PAdES** (+ PDF/A-1 för Riksarkivets
  långtidsarkivering).
- **Egen Sweden Connect-nod** (Digg open source) för rena on-prem-kunder utan Inera-avtal.

**Bygg inte kryptokärnan.** Bygg arbetsytan + köerna + **bevarandepanelen "Giltig nu / Giltig då"**
(PAdES/PDF/A-1 + LTV + kvalificerad tidsstämpel, "Verifiera underskrift nu") — gapet ingen konkurrent
(Scrive/Assently/Visma Addo) säljer tydligt, och Riksarkivets tyngsta krav (arkivera *beviset* om
underskriften). Slutlagring: signerad handling + valideringsintyg → facksystemet/diariet.

### 3.5 Säker digital kommunikation (SDK) — `attHantera`, `kvittenser`, `funktionsbrevlador` m.fl.

`sdkmc`/`securemail`/`mail` är **ITSL:s egna appar**, inte community-NC-appar. De driver hela
kommunikations- och kvittenslagret.

**Prerequisites (skarp drift):** SDK-accesspunkt (AS4/eDelivery-profil + XHE-envelopering) org-till-org;
ITSL är Adda DIS-kvalificerad. SDK-loggretention **12/12 mån sökbar** (Diggs krav, utan
meddelandeinnehåll) → matar `loggSparbarhet`. Summary-endpoint
`/ocs/v2.php/apps/sdkmc/api/v1/summary` gör server-side kanalklassning bakom `attHantera`.

### 3.6 Facksystem-konnektorer (gör "för över till facksystemet" till ett klick)

Bygg mot **standarden** (Ena REST-API-profil / SDK-meddelandetyper / FGS 2.0), inte mot varje facksystem
en-och-en. Tre adaptrar + alltid-tillgänglig manuell:

- **A — API/REST** mot facksystem med öppet API (Treserva, Lifecare/Cosmic, Combine/Viva, ev. Provisum/Adato).
- **B — drag-to-case** mot diariet (W3D3, Public 360°, Platina, Ciceron) — som Formpipe "Teams för W3D3", fast on-prem.
- **C — FGS-export** (FGS Paketstruktur 2.0 = E-ARK CSIP/SIP; FGS Ärendehantering 2.0 = CITS ERMS) till e-arkiv (Sydarkivera) — bygg på `files_retention` + Groupfolders.
- **D — manuell** (ladda ner signerad PDF/A + kvittens, "Markera som överförd" loggat) — fungerar dag 1 utan integration.

**Destinationen modelleras alltid; leveransläget kan vara manuellt först.**

---

## Wiring-prioritering (sammanfattning)

| Nivå | Innehåll | Demo-utdelning |
|---|---|---|
| **1 — idag, ren v32** | deck, tasks, calendar, spreed (P2P), groupfolders, files_retention, tables, forms, collectives, activity | Hög — ~70 % av katalogen |
| **2 — en komponent** | Collabora/OnlyOffice (samredigering), whiteboard_server (SIP-tavla), LibreSign cfssl/JSignPDF (internt signeringsspår) | Medel |
| **3 — tung backend** | Talk HPB, recording server, `stt_whisper2`+KB-Whisper, `llm2`/Assistant (Docker/GPU); **Inera/Sweden Connect** (skarp BankID-AES); SDK-accesspunkt; facksystem-/MCF-/SIEM-/HR-konnektorer | Hög men dyr — wow-flödena |

**Headline:** ungefär 70 % kan demas på ren v32 idag. De tunga beroendena — **(1) HPB, (2) recording-server,
(3) GPU för STT/LLM, (4) Inera/Sweden Connect för BankID-AES, (5) SDK-accesspunkt + facksystem-konnektorer**
— avgör vilka skarpa produktionsflöden som är möjliga. Den suveräna signeringskärnan för svensk offentlig
sektor är **Inera Underskriftstjänst-API / egen Sweden Connect-nod**, inte LibreSign (komplement för internt
lågrisk). Den svenska transkriberingskärnan är **KB-Whisper** (Apache-2.0), inte Vosk/large-v3.
