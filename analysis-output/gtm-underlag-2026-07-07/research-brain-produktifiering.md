**BEDÖMNING: "Träna en brain åt en kund" — avstånd från säljbar produkt**
*Underlag: docs/MASTERPLAN-2026-07-05.md, PRODUKTIONSPLAN-2026-07-07.md, KRAVSTALLNING-SKILLS-RUNBOOKS-GUI-2026-07-06.md, PII-HALLNING-ANALYS-2026-07-07.md, KARTLAGGNING.md, CONTRACTS.md samt README för openbrain-svc/ingestion/skills/runbooks i C:\Users\fredrik.jonasson\Cursor\itsl-open-stack*

---

**1. Vad en "brain" ÄR tekniskt idag — och vad den inte är**

En brain är en per-person minnestjänst: en egen Postgres-databas med pgvector, frontad av `openbrain-svc` (Node 22, portad från Nate B. Jones OB1). Innehållet är "thoughts": råtext + vektorembedding + JSONB-metadata, deduplicerade via `content_fingerprint`. Åtkomst sker via MCP-verktyg (`search_thoughts`, `capture_thought`, `fetch` m.fl.) så att valfri MCP-klient — Claude, ChatGPT, Cursor — kan läsa och skriva. Fyllningen sker via ingestionsmotorn: fem konnektorer (Zammad, Talk, GitLab, Fireflies, Outlook) som inkrementellt normaliserar allt till ett enhetligt Evidence-schema och POST:ar till hjärnan genom en PII-brandvägg. Atlas hjärna bär idag ~38 700 evidence-poster.

**Detta är retrieval, inte träning.** Ingen modellvikt ändras någonsin. Arkitekturen är RAG (retrieval-augmented generation): kundens data ligger i en sökbar databas, och en hyrd modell får relevanta utdrag i sitt kontextfönster vid frågetillfället. Nates egen formulering, citerad i KARTLAGGNING: *"Rented intelligence on top, owned context underneath."* Skillnaden mot vad VD-formuleringen "träna LLM:er med enterprisedata" antyder:

- **Retrieval/context (det vi har):** data i databas, modell oförändrad, uppdaterbar i nära realtid, varje svar spårbart till källposter.
- **Fine-tuning (det vi INTE gör):** justera vikter (LoRA/SFT) på kunddata — dyrt, statiskt, och kunddata bakas *in i vikterna* där den inte kan gallras.
- **Träning från grunden:** inte ens på bordet för någon i vår storleksklass.

Den hederliga — och faktiskt starkare — säljformuleringen är: *"Vi bygger ett ägt, suveränt kunskapslager som gör varje LLM expert på er verksamhet — utan att er data någonsin lämnar er eller bakas in i en modell."* Det är ett GDPR-argument, inte en brist: en brain-post kan raderas (art. 17), TTL-gallras (B18: 24 mån rullande) och auditeras; en fine-tunad vikt kan aldrig "glömma". Att sälja detta som "träning" vore både tekniskt fel och juridiskt kontraproduktivt. Ordet "träna" kan behållas i marknadsföring endast som metafor med omedelbar precisering.

**2. Återanvändbart för kundleverans**

Förvånansvärt mycket är generiskt:

- **Ingestionsmotorn** — `source-connector-contract` (inkrementell cursor, rådata bevaras, allt → Evidence → `/ingest`), 5 färdiga konnektorer, 31 gröna tester. Kontraktet gör nya källor till avgränsade byggen.
- **PII-apparaten** — det mest differentierande. Stance-matrisen (strict/pseudonym/workcontacts/open) som instansinställning, golv-brandvägg (personnummer/secrets/case-id → 422, före all extern egress), saltade hashar, wipe+refill-procedur, TTL-cron och GDPR-mallpaketet (registerförteckning, LIA, gallringsbeslut, art. 13–14) från P2e. Ingen konkurrent i "lägg-din-data-i-en-RAG"-klassen levererar detta.
- **Aktiveringsmodellen** (M-A+M-B, byggd och verifierad): skills/runbooks-katalog, per-agent-loader, admin-tillgänglighetsmatris + ägar-godkännande med kvitton — dvs. governance över vad agenten får göra, per person.
- **Tvåtiers-säkerheten** — Tier 1 untrusted-origin med snävt verktygstak, Tier 2 ägarbunden med EGRESS-brandvägg och BankID-grade re-auth (designad, M-D ej byggd).
- **Driftpaketet** — compose-stack, deploy.sh, smoke-sviter 01–12, backup/restore-övning, healthwatch, kostnadstak per agent. PROD-RUNBOOK (P7) är i praktiken embryot till en kundinstallations-runbook.

**3. Vad som saknas för att SÄLJA — grovt i personmånader**

Viktigt: multi-tenancy i klassisk mening behövs inte — "en stack per kund i kundens drift" ÄR produkten och matchar suveränitetsprofilen. Det som saknas är:

- **Slutför v1-svepet (P1–P9).** Kritiskt: idag är PII-brandväggen AV och rå text går till OpenRouter (US) för embedding — inkonsekvens C i PII-analysen. Innan P2 (lokala embeddings, stance-flip, brandvägg på) är körd är suveränitetsargumentet inte sant om vår egen drift. ~0,5 pm (redan planerat).
- **Avhårdkoda instansen.** CONTRACTS hårdkodar Reb/Atlas/Ada/Marvin; provisioning måste bli parametriserad (agenter/bottar/rum/routing genererade ur kundens organisation) + installer/uppgraderingsväg + hantering av Deck-patchen (måste omappliceras vid Deck-uppgradering — en supportskuld att lösa). ~1,5–2 pm.
- **Käll-onboarding-GUI.** Tokens läggs idag i `.env` för hand; kund behöver en admin-yta för konnektorkonfiguration + status. ~1–1,5 pm.
- **Kundens källor.** Vår palett speglar ITSL (Zammad, Fireflies…). Kommun/enterprise vill ha M365/Teams/Exchange/SharePoint, ev. Jira. Grundpaket ~1–2 pm, därefter ~0,5–1 pm per ny källa.
- **Juridik & paketering.** GDPR-dokumenten är uttryckligen "ingenjörsmallar, inte färdig juridik": DPIA, biträdesavtal, prissättning, licens. ~0,5–1 pm ingenjörstid + extern jurist.
- **Support/utbildning.** Kom-igång-material finns (P6) men för internt bruk; kundversion + SLA-process. ~1 pm.

**Summa: ~6–9 personmånader till en paketerad, säljbar leverans** — men en *konsultledd designpartner-pilot* (Fredrik/teamet installerar och kör själva, mot betalning) är realistisk inom **~2–3 pm** efter v1-svepet. Det är den naturliga vägen: sälj piloten före produkten.

**4. LLM-beroenden idag och vägen till suverän intelligens**

Tre beroenden på hyrd intelligens finns:

1. **Embeddings + metadata-extraktion:** OpenRouter (`text-embedding-3-small`, `gpt-4o-mini`). **Redan på väg bort** — beslut B6 migrerar alla hjärnor till lokal TEI (e5-base, 768 dim) med `META_EXTRACT_EXTERNAL=0`; lokal LLM för metadata är post-v1. Detta lager blir suveränt i P2.
2. **Agent-runnern:** `claude -p` (Claude Code headless) mot Anthropic API (claude-sonnet-4-5, $10/agent/dag). Detta är det **hårda** beroendet — hela 19-stegsloopen, verktygssandlådan och kvittodisciplinen är byggda på Claude Codes semantik.
3. **Chattkanalen:** samma runner-beroende.

För självhostade open source-modeller: OB1-basen är redan förberedd (`EMBEDDING_API_BASE`/`CHAT_API_BASE` pekbara mot valfri OpenAI-kompatibel endpoint — Ollama/llama.cpp/vLLM, noterat i KARTLAGGNING). Teknisk trappa:

- **Steg 1 (nästan klart):** suveräna embeddings + metadata — lokal TEI + liten lokal modell. Ingen kunddata lämnar huset för lagring/sökning.
- **Steg 2 (måttligt):** suveräna RAG-svar — chat/sök-svar via självhostad modell (vLLM + t.ex. Qwen/Llama/Mistral-klass) bakom OpenAI-kompatibel gateway. Kräver GPU i kunddrift (~70B-klass för bra svenska: 1–2 datacenter-GPU:er) eller mindre modell med kvalitetskompromiss. ~1–2 pm.
- **Steg 3 (svårast):** suverän agent-loop. Antingen proxy:a Claude CLI via gateway (LiteLLM-mönster) mot öppen modell — tool-calling-pålitligheten över 40 turer måste då bevisas mot våra egna smokes (särskilt smoke-08, fientligt kort) — eller bygga modell-agnostisk runner. ~2–4 pm plus benchmarking, med ärlig förväntan om kvalitetstapp mot frontier.

**Hederlig slutsats för erbjudandet:** sälj *suverän data nu, suverän intelligens som trappa*. Lagret som rör kunddata i vila (brain, ingestion, PII, embeddings) kan vara 100 % suveränt inom veckor; resonemangslagret erbjuds i tre nivåer — frontier-API (bäst kapacitet), EU-hostad modell, eller helt självhostad open source (högst suveränitet, lägre kapacitet). Att kunna erbjuda hela trappan, med PII-brandväggen som golv i alla lägen, är exakt "spegelvänd vallgrav"-positionen från Palantir-analysen — och ingen del av den kräver att vi någonsin säger ordet "träna" om något vi inte gör.