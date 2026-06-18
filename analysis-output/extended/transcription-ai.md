# Mötestranskribering & lokal AI-sammanfattning för Hubs

*Fördjupningsanalys — inspelning av säkra videomöten, tal-till-text (med svenskt fokus), lokal LLM-sammanfattning av anteckningar/transkript, och flödet record → transcribe → summarise → spara i ärende. Underlag för Hubs (ITSL), den Nextcloud-baserade säkra kommunikationssviten för svensk offentlig sektor.*

> **Brand-regel (gäller all produkt-/UI-text):** i produktnära text säger vi aldrig "Nextcloud" eller "Talk". Vi säger **säkert möte / säkert videomöte**, **mötesanteckningar**, **transkribering**, **AI-sammanfattning (lokal)**, **ärenderum**. I denna interna analys används plattforms- och appnamnen (spreed-itsl, HPB/signaling, recording server, `live_transcription`, `stt_whisper2`, `llm2`, `call_summary_bot`, Assistant) för precision och för att kunna wire:a flödet.

> **Arkitektonisk ram (kund):** Hubs är **mellanlagring** — det säkra rummet där mötet sker, spelas in, transkriberas och förbearbetas. **Slutlagring (system of record) är alltid verksamhetens ärendehanteringssystem**: socialtjänst (Treserva, Lifecare/Procapita, Viva, Combine), HSL (Lifecare, Cosmic, Treserva HSL), överförmyndare (Provisum/Aider/Wärna), registratur/nämnd (W3D3, Public360, Ciceron, Platina, Evolution, LEX), HR (Visma, Heroma, Personec). En **handläggargodkänd** sammanfattning committas in i ärendet; rå-inspelningen och rå-transkriptet behandlas som korttidsmellanlagring med gallring.

---

## Sammanfattning (TL;DR)

- **Hela kedjan finns redan som on-prem-byggblock i plattformen** och kräver ingen molntjänst: inspelning via **recording server** (kräver HPB/signaling), live-textning via ExApp **`live_transcription`** (Vosk), efterhands-transkribering via ExApp **`stt_whisper2`** (faster-whisper), call-/chatt-sammanfattning via **`call_summary_bot`** + **Assistant**, och lokal LLM-sammanfattning via ExApp **`llm2`** (llama.cpp, GGUF). Allt körs i kundens egen miljö → **0 tredjelandsöverföringar**, vilket är själva Hubs-pitchen.
- **Svensk språkkvalitet är den avgörande tekniska detaljen.** OpenAI:s `whisper-large-v3` är svag på svenska. **KB-Whisper (KBLab / Kungliga biblioteket)** är finjusterad på 50 000 h svenskt tal, **Apache-2.0**, och sänker WER med ~47 % mot large-v3; t.o.m. `kb-whisper-small` slår `whisper-large-v3` på svenska. KB-Whisper finns i faster-whisper/CTranslate2- och whisper.cpp/GGML-format → **drop-in i `stt_whisper2`** via `/nc_app_stt_whisper2_data`. **Detta är Hubs viktigaste konfigurationsval och ett konkret upphandlingsargument.**
- **Den hårda begränsningen är inte tekniken utan juridiken.** En **videoinspelning/ljudinspelning av ett möte är en handling** (potentiellt allmän handling) och faller under TF/OSL/arkivlagen. För sekretessbelagda möten (socialtjänst, HSL, rehab) är det rättsligt riskabelt att *behålla* rå-inspelning och rå-transkript. Branschens linje (t.ex. Kalmar/Digitala Samtal, IMY-dialog) är **"compliance first, sharp deployment second"**. Designkonsekvens: **transient inspelning, human-in-the-loop-godkännande, gallra rå-data, bevara bara den godkända sammanfattningen i ärendet.**
- **Realistiskt att demoa nu:** inspelning + KB-Whisper efterhands-transkript + lokal `llm2`-sammanfattning på en GPU-maskin, för **interna/icke-sekretessbelagda** möten (t.ex. nämndberedning, internt rehab-processmöte utan klartext-personuppgifter). **Realistiskt att dokumentera (inte skarpt köra på känsliga uppgifter än):** transkribering av klientsamtal/SIP/utredningssamtal — invänta IMY/SKR/Socialstyrelsen-vägledning och kör med tydlig rättslig grund, samtycke och gallring.

---

## 1. Arkitektur: var kommer datat ifrån, var hamnar det

```
[Säkert videomöte]                         Hubs = MELLANLAGRING
  spreed-itsl  ──signaling──►  HPB (signaling + WebRTC SFU)
       │                              │
       │ (moderator startar inspelning, recording_consent)
       ▼                              ▼
  recording server  ──────────►  WebM (audio/video) sparas som FIL
  (egen maskin, Selenium/browser)       i ärenderummet (Groupfolder)
       │                              
       ▼  (a) LIVE                    (b) EFTERHANDS
  live_transcription (Vosk)       stt_whisper2 (faster-whisper + KB-Whisper)
       │  live textremsa i mötet         │  transkript (.txt/.vtt) i ärenderummet
       ▼                                 ▼
  call_summary_bot  ◄── transcript ──►  Assistant "Summarize"
       │                                 │
       └──────────►  llm2 (llama.cpp, GGUF)  ◄──────────┘
                        │  UTKAST till sammanfattning + beslut + att-göra
                        ▼
            [HANDLÄGGARE GRANSKAR & GODKÄNNER]   ← human-in-the-loop (GDPR/OSL)
                        │
        ┌───────────────┴───────────────────────────┐
        ▼                                            ▼
  Gallra rå-inspelning + rå-transkript        Godkänd sammanfattning committas
  (transient mellanlagring, kort retention)   till SLUTLAGRING = ärendesystemet
                                              (Treserva/Lifecare/Viva/Combine,
                                               W3D3/Public360/Ciceron, Provisum/Aider…)
```

**Brytpunkten Hubs↔system-of-record:** Hubs producerar ett **godkänt textunderlag** (mötesanteckning/sammanfattning, ev. med beslut och åtgärdslista). Det är detta — inte WebM-filen, inte rå-transkriptet — som journalförs/diarieförs i verksamhetssystemet. Hubs ska därför ha en **"Spara till ärende"-åtgärd** (manuell kopiering i demo; integration mot facksystem på sikt) och en **gallringsklocka** på rå-artefakterna.

---

## 2. Inspelning — recording server + HPB (signaling)

**App-id / komponenter:** `spreed` (säkert möte) → **High-Performance Backend (HPB)** = signaling-server + WebRTC-gateway (SFU) → **recording server** (separat tjänst som joinar mötet som en osynlig browser via Selenium och spelar in).

**Hårda prerequisites:**
- **HPB krävs för inspelning.** Den inbyggda P2P-signaleringen räcker inte; recording servern ansluter via HPB:ns signaleringsprotokoll. HPB rekommenderas dessutom för möten med 4+ deltagare (SIP-möten, nämndberedningar).
- **Recording servern ska köra på en EGEN maskin** — kontinuerlig video­inspelning är CPU-tung. Riktvärde: ~4 CPU-kärnor + rejält RAM per parallell inspelning.
- **Konfiguration:** recording servern behöver HPB-domän, Nextcloud-domän, `recording_secret` och `internal_secret`. URL-schema `https://` (inte `wss://`) i admin-inställningarna.
- **Output:** inspelningen sparas som **WebM** (ljud och/eller video) **tillbaka som fil i Nextcloud** (default i `Talk/Recordings`; för Hubs ska den styras in i **ärenderummet/Groupfolder** så den ärver ACL + Retention). Filen lagras **inte permanent** på recording-backenden.
- **Moderatorn** får en avisering när filen är klar och uppladdad.

**Samtycke till inspelning (`recording_consent`)** — kritiskt för svensk myndighet:
- Admin-flagga i `/settings/admin/talk`: *"The consent to be recorded will be required for each participant before joining every call."* Tre nivåer: av / påtvingat systemomfattande / moderator beslutar per möte.
- Capability `recording-consent`. När det är på måste varje deltagare **kryssa i samtycke innan de släpps in** — annars nekas inträde. Deltagare får dessutom **en notis i mötet om att inspelning pågår.**
- **Hubs-konsekvens:** för Hubs ska `recording_consent` sättas **påtvingat** (eller minst moderator-styrt med default på) och samtyckes-tidsstämpeln loggas som åtkomst-/samtyckeshändelse — det är en del av den rättsliga grunden och spårbarheten.

**Demo-realism:** inspelning + lagring i ärenderum är fullt demobar idag på en testmiljö med HPB + recording server. Den dyra/sköra delen är recording servern (browser-baserad, kräver egen host). För en *teknik*demo kan man visa kedjan på ett internt möte; för *skarp* drift på känsliga möten gäller juridiken i avsnitt 5.

---

## 3. Transkribering — två vägar

### 3a. Live-textning under mötet — `live_transcription` (Vosk)

- **App-id:** `live_transcription` (ExApp via AppAPI). Ger **realtidstextremsa** i pågående säkert möte.
- **Modeller:** Vosk (alphacephei.com/vosk), auto-nedladdas vid installation.
- **Prerequisites:** Talk + **HPB (senaste, eller släppt efter sep 2025)**; Deploy Daemon i AppAPI; env `LT_HPB_URL`, `LT_INTERNAL_SECRET`. Lagring ~2,8 GB container + ~6,0 GB modeller i volym `nc_app_live_transcription_data`.
- **Hårdvara:** GPU-väg NVIDIA ≥10 GB VRAM, CUDA ≥12.4.1; CPU-väg x86_64, 4 trådar (+2 per parallellt möte), 16 GB RAM räcker för 1–2 parallella möten. (Dokumentationen noterar att i praktiken är endast CPU-transkribering aktiv.)
- **🚩 Showstopper för svenska:** Vosk-listan stödjer **arabiska, tyska, engelska, franska, italienska, spanska … men INTE svenska.** `live_transcription` är därför **inte** användbar för svenska klient-/SIP-/rehabmöten i nuläget. Slutsats: **live-textning dokumenteras som "kommer/villkorad av svensk Vosk-modell", men byggs inte in i svenska persona-flöden nu.** Den svenska kvaliteten kommer via efterhands-Whisper (3b).

### 3b. Efterhands-transkribering — `stt_whisper2` + **KB-Whisper** (Sverige-kärnan)

- **App-id:** `stt_whisper2` ("Local Whisper Speech-To-Text"). Fungerar som **STT-backend för Assistant, för säkra möten (call recording → transcript) och för alla appar som använder core Speech-To-Text/Translation-API:t.** Kör **bara öppna modeller, helt on-prem.**
- **Prerequisites:** Nextcloud ≥28, **AppAPI ≥2.3.0**, Docker (ExApp), volym `nc_app_stt_whisper2_data` för egna modeller. AIO-kompatibel.
- **Hårdvara:** NVIDIA GPU **min 4 GB VRAM**, CUDA ≥12.2; eller CPU (rekommenderat 10–20 kärnor, "tar alla kärnor" → kör på egen maskin), ~4 GB RAM för appen.
- **Modeller:** default OpenAI `whisper-large-v2/v3` (multilingual). **Appen tillåter valfri *faster-whisper*-modell** (Systran-format på Hugging Face) lagd i datavolymen.

**Det Sverige-kritiska valet — byt ut large-v3 mot KB-Whisper:**

| Modell (KBLab) | WER FLEURS | WER CommonVoice | WER NST | vs OpenAI large-v3 |
|---|---|---|---|---|
| kb-whisper-tiny | 13,2 | 12,9 | 11,2 | (large-v3: 59,2 / 67,8 / 85,2) |
| kb-whisper-base | 9,1 | 8,7 | 7,8 | |
| **kb-whisper-small** | **7,3** | **6,4** | **6,6** | **slår large-v3 (7,8/9,5/11,3) trots 6× mindre** |
| kb-whisper-medium | 6,6 | 5,4 | 5,8 | |
| **kb-whisper-large** | **5,4** | **4,1** | **5,2** | **~47 % lägre WER i snitt** |

- **Licens: Apache-2.0** (fri kommersiell on-prem-användning — inget moln, ingen tredjepart).
- **Format:** Hugging Face Transformers, **faster-whisper/CTranslate2**, **whisper.cpp/GGML** (även kvantiserat), ONNX → **direkt drop-in i `stt_whisper2`** (faster-whisper) via `/nc_app_stt_whisper2_data`. Körs t.ex. `WhisperModel("KBLab/kb-whisper-large", device="cuda", compute_type="float16")` (eller `int8` om lite VRAM).
- **Varianter:** `revision="standard"` (default), `revision="subtitle"` (kondenserat), `revision="strict"` (mer ordagrant). För myndighetsanteckning är `standard`/`strict` lämpligast (ordagrannhet > komprimering inför en sekretessprövning).

**Rekommendation:** kör **`kb-whisper-large` på GPU** där VRAM finns (bäst kvalitet), annars **`kb-whisper-small/medium` på CPU/liten GPU** (fortfarande bättre svenska än large-v3). Detta är Hubs **default-STT-modell** och ska skrivas in i drift-/upphandlingsunderlaget som "svensk-tränad, KB/statlig härkomst, Apache-2.0, on-prem".

---

## 4. AI-sammanfattning — lokal LLM (`llm2`) + Assistant + `call_summary_bot`

**Flödet "record → transcribe → summarise":**
1. Inspelning (WebM) → `stt_whisper2`/KB-Whisper → **transkript** i ärenderummet.
2. **Sammanfattningen** produceras av en lokal LLM och *föreslås* som utkast.

**Komponenter:**
- **`llm2`** (ExApp): textbearbetnings-backend för **Assistant** via core Text Processing API. **llama.cpp**, valfri **GGUF**-modell (default Llama 3.1 8B Instruct; även 70B). Egna modeller läggs i `/nc_app_llm2_data` med matchande `.json` (prompttemplate, kontextfönster, max tokens). **Prerequisites:** AppAPI ≥3.1.0, AIO-stöd. **Hårdvara:** NVIDIA ≥8 GB VRAM + ≥12 GB system-RAM (CUDA ≥12.4, CPU med AVX/AVX2); CPU-väg 10–20 kärnor + ≥12 GB RAM.
  - **⚠️ Kontextfönster-begränsning:** exempel-configar visar **4096–8000 tokens kontext, max ut 2048–4000**. Ett långt mötestranskript (>~1 h tal) **överskrider lätt kontextfönstret** → kräver **chunkning/map-reduce-sammanfattning** (summera per avsnitt, sedan summera summorna). Detta är den enskilt viktigaste tekniska bygg-uppgiften i sammanfattningslagret.
- **`call_summary_bot`** (Talk-bot): läggs till i ett säkert möte; **vid mötets slut** sammanfattar den samtalet och listar **deltagare + uppgifter** som ett markdown-meddelande i tråden. Default: bara möten ≥60 s sammanfattas. Använder de LLM-providers (llm2) som finns konfigurerade.
- **Assistant**: "Summarize" / fri prompt ovanpå transkript; kan skicka **call summary till frånvarande deltagare**.

**Modellval & suveränitet (Ethical-AI):** håll linjen från `research-personalisering.md` — endast **lokala, grön-ratade** modeller. Default-Llama är gångbar; för en helt öppen, grön-ratad modell kan **OLMo 2** användas (samma resonemang som `lokalAiPrioritering`/`llm2` i `personaConfig.js`). Allt körs lokalt → ingen AI-as-a-Service, ingen tredjelandsöverföring.

**Vad sammanfattningen ska innehålla (myndighetsanpassat utkast):** kort sammanfattning · **fattade beslut** · **åtgärds-/att-göra-lista med ansvarig** · närvarande/frånvarande · ev. flagga "innehåller känsliga uppgifter — sekretessprövas". Allt som **utkast** — aldrig auto-committat.

---

## 5. Juridik & sekretess — den verkliga begränsningen

Detta avsnitt avgör vad som får köras skarpt. Tekniken är löst; juridiken är inte det.

**5.1 Inspelning/transkript = handling.** En video- eller ljudinspelning av ett möte, och dess transkript, är en **handling** och kan bli **allmän handling** (TF). Då aktualiseras **OSL** (sekretessprövning vid varje begäran — sekretessmarkering i ärendesystemet är *ingen garanti* för maskning), **arkivlagen (1990:782)** + **arkivförordningen** (bevaras som huvudregel; gallring kräver stöd i Riksarkivets/kommunal myndighets föreskrifter — aldrig godtyckligt), och **förvaltningslagen**. **Konsekvens:** rå-inspelning och rå-transkript ska **inte** samlas på hög "för säkerhets skull" — de ska ha en **beslutad, kort gallringsfrist** och en dokumenttyp i dokumenthanteringsplanen. Den **godkända sammanfattningen** är det som bevaras/journalförs i system-of-record.

**5.2 Sekretessbelagda möten (socialtjänst, HSL, rehab).** Här är det rättsligt känsligast. Den svenska branschlinjen (Kalmar kommun via Digitala Samtal; dialog med **IMY**) är uttryckligen **"vi väntar / compliance first"** tills IMY/SKR/Socialstyrelsen gett tydlig vägledning. Återstående frågor de pekar på: **rättslig grund** för inspelning + automatisk utskrift; **sekretess när biträden/moln är inblandade**; **tredjelandsöverföring** (kräver EU-förankrade lösningar — Hubs on-prem **löser** denna punkt); **kvalitet/hallucination** (AI missar nyanser → handläggaren måste äga och aktivt godkänna texten); **spårbarhet** (vem godkände vad, när). Uppdatering 2026: IMY har börjat bekräfta att rättslig grund kan finnas — men **human-in-the-loop + loggade godkännanden + dataminimering/gallring** är förutsättningar, inte tillval.

**5.3 HSLF-FS 2016:40 & GDPR.** Mötesdata med personuppgifter i vård/omsorg kräver kryptering + stark autentisering (LOA3) — uppfyllt av Hubs säkra rum. GDPR gäller oavsett om analysen görs manuellt eller av AI: **rättslig grund, dataminimering, begränsad lagring, säkerhet, samtycke** (där det är grunden). **GDPR art. 22** (linje från `research-personalisering.md`): AI får **föreslå** sammanfattning, aldrig fatta beslut — människan committar.

**5.4 Designregler som följer av juridiken (bygg in dessa):**
- **`recording_consent` påtvingat** + samtycke loggat (avsnitt 2).
- **Transient rå-data:** WebM + rå-transkript får kort, satt **Retention/gallringsfrist** i ärenderummet; klocka + notis innan radering (återanvänd `arkivGallring`-mönstret).
- **Human-in-the-loop obligatoriskt:** sammanfattning är **utkast** tills handläggaren redigerat och **tryckt "Godkänn"** (loggad händelse). Inget auto-commit till ärendesystemet.
- **"Spara till ärende"** committar bara den godkända texten till slutlagringen; rå-artefakter följer inte med som standard.
- **Suveränitetsmarkör:** "Transkribering & sammanfattning sker lokalt · 0 tredjelandsöverföringar · KB-Whisper (Apache-2.0) + lokal LLM" — synlig i mötesvyn, samma `dataSuveranitet`-mönster som övriga Hubs.

---

## 6. Realistiskt att DEMOA vs DOKUMENTERA

| Steg | Demoa nu (skarpt) | Dokumentera/villkora |
|---|---|---|
| **Inspelning + HPB + recording server** | ✅ Ja, på testmiljö (egen recording-host). Visa `recording_consent`-flödet + fil i ärenderum. | Drift-skörhet (browser-baserad recording-host), skalning till många parallella möten. |
| **Live-textning (`live_transcription`/Vosk)** | ❌ Inte på svenska (ingen Vosk-svenska). | "Kommer när svensk Vosk-/Whisper-streaming finns." |
| **Efterhands-transkript (KB-Whisper i `stt_whisper2`)** | ✅ Ja — **flaggskeppsdemon**. KB-Whisper-large på GPU, svenskt möte → läsbart transkript. | GPU-budget (≥4 GB VRAM small/medium, mer för large), batch-tider på CPU. |
| **Lokal sammanfattning (`llm2` + Assistant)** | ✅ Ja, för korta möten inom kontextfönstret. | Chunkning/map-reduce för långa transkript; promptmall för myndighetsformat (beslut/åtgärder). |
| **Call summary i mötet (`call_summary_bot`)** | ✅ Ja, deltagare + uppgifter i markdown. | Kvalitetsgranskning av bot-output innan den når ett ärende. |
| **Skarp körning på SEKRETESSBELAGDA klientsamtal/SIP** | ⚠️ **Nej — dokumentera, kör inte skarpt än.** | Invänta IMY/SKR/Socialstyrelsen; rättslig grund + samtycke + gallring + human-in-the-loop som villkor. On-prem löser tredjelandsfrågan men inte hela OSL/arkiv-frågan. |

**Säker demo-scen (icke-känsligt innehåll):** ett **internt nämnd-/process-/rehab-*berednings*möte utan klartext-personuppgifter** → spela in → KB-Whisper-transkript → `llm2`-sammanfattning med beslut + åtgärdslista → handläggaren redigerar → "Godkänn" → "Spara till ärende" (mock mot W3D3/Treserva). Det visar **hela kedjan och suveräniteten** utan att exponera sekretess.

---

## 7. Koppling till personas & widget-katalogen

- **Inget nytt widget-id krävs för MVP** — transkribering/sammanfattning hänger naturligt på **`dagensMoten`** (säkra möten) och landar i **`arenderum`** (transkript/sammanfattning som filer) + **`senasteFiler`**. Sammanfattnings-utkastet kan bli en **`minaUppgifter`/`bevakningar`**-post ("Granska & godkänn mötesanteckning").
- **Föreslagen ny modul (roadmap):** *"Mötesanteckningar & lokal AI-sammanfattning"* — status **föreslagen**, bygggrund recording server + `stt_whisper2`(KB-Whisper) + `llm2`/Assistant + `call_summary_bot`. Knyter an till befintliga `lokalAiPrioritering`/`llm2`-resonemanget (samma Ethical-AI-linje).
- **Personarelevans:** socialsekreterare (utrednings-/SIP-samtal — men *villkorat*, se §5), **hsl_skoterska** (SIP-möten/vårdplanering), **hr_chef** (avstämnings-/rehabmöten), **registrator** (nämndberedning/protokollunderlag — minst känsligt → bästa första skarpa användning).
- **Primär åtgärd (verb-först):** "Transkribera & sammanfatta möte (lokalt)" → producerar **utkast** → "Godkänn & spara till ärende".

---

## 8. Konkret prerequisite-lista (drift)

1. **HPB (signaling + WebRTC SFU)** — krävs för inspelning och för 4+ deltagare. Egen tjänst.
2. **Recording server** — **egen maskin** (~4 kärnor + RAM/parallell inspelning); `recording_secret`/`internal_secret`; `https://`-schema; output WebM → styr in i ärenderum-Groupfolder.
3. **`recording_consent` = påtvingat** (eller moderator + default på); logga samtycke.
4. **AppAPI + Deploy Daemon (Docker)** — för alla ExApps nedan.
5. **STT:** `stt_whisper2` (NC ≥28, AppAPI ≥2.3.0) **+ KB-Whisper-modell** (faster-whisper/GGML, Apache-2.0) i `nc_app_stt_whisper2_data`. GPU ≥4 GB VRAM (large mer) eller CPU 10–20 kärnor.
6. **LLM:** `llm2` (AppAPI ≥3.1.0) med grön-ratad GGUF (Llama 3.1 / OLMo 2); GPU ≥8 GB VRAM + ≥12 GB RAM eller CPU 10–20 kärnor. **Bygg chunkning** för långa transkript (kontext 4–8k tokens).
7. **Sammanfattning i möte:** `call_summary_bot` + Assistant (deltagare/uppgifter, summary till frånvarande).
8. **Gallring:** Retention-policy på rå-WebM + rå-transkript i ärenderummet; human-in-the-loop-godkännande loggat; "Spara till ärende" commit:ar bara godkänd text till system-of-record.
9. **GPU-not:** en gemensam NVIDIA-GPU (t.ex. ≥16 GB) kan dela STT + LLM i en demo; för parallell skarp drift separera STT- och LLM-last (båda "tar alla kärnor"/VRAM).

---

## Källor

**Inspelning / HPB / recording server / samtycke**
- https://nextcloud-talk.readthedocs.io/en/latest/quick-install/
- https://nextcloud-talk.readthedocs.io/en/latest/developer-setup/
- https://arnowelzel.de/en/nextcloud-talk-high-performance-backend-with-docker
- https://arnowelzel.de/en/nextcloud-talk-with-coturn-and-self-hosted-signaling-server-high-performance-backend
- https://gist.github.com/pojntfx/151d3fa0c76ada3a79cfa2b5dcf6e2f6/98a4fef7f92186850179b1a3b67f12084d0d62ac
- https://help.nextcloud.com/t/how-to-setup-nextcloud-talk-recording-backend/159145
- https://github.com/nextcloud/spreed/issues/10348 (Recording consent – overview)
- https://nextcloud-talk.readthedocs.io/en/stable/capabilities/
- https://docs.nextcloud.com/server/latest/user_manual/sv/talk/advanced_features.html

**Live-transkribering (`live_transcription`, Vosk)**
- https://docs.nextcloud.com/server/32/admin_manual/ai/app_live_transcription.html

**Efterhands-STT (`stt_whisper2`, faster-whisper)**
- https://docs.nextcloud.com/server/32/admin_manual/ai/app_stt_whisper2.html
- https://apps.nextcloud.com/apps/stt_whisper2/releases
- https://github.com/nextcloud/stt_whisper
- https://docs.nextcloud.com/server/27/developer_manual/digging_deeper/speech-to-text.html

**KB-Whisper (svensk Whisper, KBLab / Kungliga biblioteket)**
- https://kb-labb.github.io/posts/2025-03-07-welcome-KB-Whisper/
- https://huggingface.co/KBLab/kb-whisper-large/blob/main/README.md
- https://huggingface.co/KBLab/kb-whisper-small
- https://huggingface.co/KBLab/kb-whisper-medium
- https://arxiv.org/pdf/2505.17538 (Swedish Whispers; massive speech corpus for Swedish ASR)

**Lokal LLM-sammanfattning (`llm2`, Assistant, call/summary bot)**
- https://docs.nextcloud.com/server/latest/admin_manual/ai/app_llm2.html
- https://docs.nextcloud.com/server/latest/admin_manual/ai/app_summary_bot.html
- https://github.com/nextcloud/call_summary_bot
- https://nextcloud.com/assistant/
- https://nextcloud.com/blog/nextcloud-releases-assistant-2-0-and-pushes-ai-as-a-service/
- https://nextcloud.com/blog/top-10-tips-to-fight-virtual-meeting-fatigue-with-nextcloud-talks-2025-updates/
- https://nextcloud.com/blog/nextcloud-ethical-ai-rating/

**Juridik: sekretess, allmän handling, arkiv, AI i socialtjänst**
- https://digitalasamtal.se/blog/ai-transkribering-i-socialtjansten-mojligheter-risker-och-varfor-vi-vantar/
- https://www.socialstyrelsen.se/kunskapsstod-och-regler/regler-och-riktlinjer/juridiskt-stod-for-dokumentation/kommunicera-over-internet-eller-andra-oppna-nat/
- https://skr.se/integrationsocialomsorg/socialomsorg/digitaliseringinomsocialtjansten/dataskyddsforordningensocialtjanst/sekretessochgdpr.15553.html
- https://www.imy.se/privatperson/dataskydd/dina-rattigheter/automatiserade-beslut/
- https://riksarkivet.se/arkivera-och-forvalta/informationsvardering-och-gallring
- https://danskebank.se/privat/gdpr/onlinemote-med-transkribering

**Internt (Hubs-grund)**
- `analysis-output/extended/research-personalisering.md` (lokal AI, Ethical-AI/grön rating, GDPR art. 22, human-in-the-loop)
- `analysis-output/extended/research-esignering.md` (PAdES/PDF/A-bevarande, on-prem-linjen)
- `hubs_start/src/services/personaConfig.js` · `hubs_start/docs/PERSONA-DASHBOARD-SPEC.md` (`lokalAiPrioritering`/`llm2`, `dagensMoten`, `arenderum`, `arkivGallring`, `dataSuveranitet`)
