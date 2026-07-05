# ITSL Open Stack — Masterplan & arkitektur (konsoliderad)

**Datum:** 2026-07-05 · **Status:** arkitektur fastställd, konsolidering påbörjad
**Ersätter inte** [SKILLS-RUNBOOKS-COMPANION-DESIGN-2026-07-05.md](SKILLS-RUNBOOKS-COMPANION-DESIGN-2026-07-05.md) (säkerhetsdetaljen) — detta är paraplyet ovanför den.

Detta dokument väver ihop **alla kodspår** vi diskuterat till EN arkitektur: Nates Open Stack (grund), companion-access, ingestion från Kunskapsbanken, människa↔agent via Hubs, samt de tre MacMini-gränssnitten (CRM, VLS, KB-artiklar). Styrande princip: **Nates tankar styr arkitektur, brains, hur skills konstrueras och hur vi promptar — allt byggs upp i runbooks — anpassat till ITSL:s förhållanden.**

---

## 0. Agenter (detta skede)

Fyra personliga companion-agenter — **Johan utesluts i detta skede**:

| Agent | Människa | Domän (preliminär) | Brain |
|---|---|---|---|
| **Reb** | Rebecca | (ersätter Johans tidigare CTO-yta — roll att bekräfta) | brain-reb |
| **Atlas** | Fredrik | Ledning/strategi | brain-atlas |
| **Ada** | Sandra | Ekonomi & marknad | brain-ada |
| **Marvin** | Mattias | **Support & drift (äger Zammad-kunskapen)** | brain-marvin |
| *(team)* | — | delad org-kunskap + router | brain-team |

**Öppet:** Johans tidigare CTO/dev-domän — absorberas av Reb, delas, eller vilar. Bekräfta Rebeccas roll.

---

## 1. Grundarkitektur (Nate → ITSL)

**Två-tiers-primitiv (Nate):** *Skill* gör EN sak pålitlig; *Runbook* gör ett HELT arbetsflöde pålitligt genom att namnge kedjan, överlämningarna och människo-grinden. "Primitiven är enheten; runbooken är produktionslinjen."

**Hur det bärs i ITSL-stacken (byggt):**
- **Per-person-brains** — `openbrain-svc` (pgvector, OB1-kärnan), en per agent + en team-brain. `POST /ingest`, semantisk `search_thoughts`, dedup via `content_fingerprint`, PII-brandvägg (422).
- **Arbetskö på Deck** (i st.f. Linear) — `agent_engine` NC-app: atomisk claim, kvitto-vokabulär (AGENT CLAIMED/DONE/BLOCKED/HUMAN HOLD…), ledger, webhooks.
- **Capture via Talk** (i st.f. Slack) — `capture-bot`.
- **Runner** — headless `claude -p` driver `queue-run.md` (Nates 20-stegsloop), en task per körning, staggered cron + HMAC-wake.

**Skills — Nates modell, konstruktion & promptning (fastställd):**
- `SKILL.md` med **enrads `description`** (≤1024 tecken, trigger-fraser) — flerrad bryter Claude Codes routing tyst. + CI-lint.
- **Progressiv disclosure:** frontmatter alltid i kontext, body laddas on-demand. `references/` öppnas bara när grenen nås → minsta blast radius.
- **Per-agent-aktiverbar loader** materialiserar bara *prenumererade* skills till agentens `~/.claude/skills/`. `itsl-guardrails` laddas alltid först.
- **`metadata.json`** per skill (category/requires/requires_skills/tags).

**Runbooks — förstklassig artefakt:** `runbooks/<namn>.md` = titel · chain (`skillA → skillB`) · trigger · per-stage tool-scope + artefakt · human gates · payoff. Multi-stegs-runbook = kedja av **länkade Deck-kort** avancerade över körningar (bevarar one-task-per-run).

---

## 2. Brains & kunskap (ingest + router)

**Ingestion (Kunskapsbanken → nativt, byggt):** `source-connector-contract` + `ingest-zammad/talk/mail/meetings/gitlab` + `pii-pseudonymize` + `evidence-normalize` (skill-katalog i `skills/ingestion/`). Native Node-tjänst `ingestion/` (Zammad-konnektor klar, testad, deployad). Kontrakt: inkrementell cursor, rådata bevaras, allt → enhetligt Evidence → personens brain. Runbook `fyll-personhjarna` kedjar dem.

**Löpande spegel:** varje persons brain hålls som "summan av allt som hänt människan" tvärs källor (mail/Talk/portal/Zammad/möten), nära realtid.

**Central "vem-vet-vad"-router (från VLS/MacMini-idén, ny design):** per ny brain-post → en **2-menings-sammanfattning** till team-brain: `{person, summary, topics, source, pekare}`. Låter en central agent veta *ungefär* vad varje person vet och **fråga rätt person/agent** — utan att råinnehåll lämnar personhjärnan. **Öppet:** ser routern bara sammanfattningar (rek.) eller mer (§4-beslut i companion-doc).

---

## 3. Säkerhet — två trust-tiers (se companion-doc)

- **Tier 1 (default) untrusted-origin:** arbetskort → BOUNDARIES_V1, INGRESS-PII-brandvägg, snävt runner-verktygstak. Skyddar människan från fientligt kort.
- **Tier 2 (opt-in) owner-trusted:** agent bunden till EN verifierad människa läser den människans egna källor; brandväggen flippar till **EGRESS** (egna PII in, blockeras ut); read-only-verktygsvidgning; writes draft-only. **Ägarbindning = BankID-grade re-auth per grant** (beslutat). Ingen verifierad grant ⇒ faller alltid till Tier 1.

---

## 4. Människa ↔ agent via Hubs (GUI, kommunikation, inställningar)

**Kommunikationsyta = Deck + Talk (byggt/designat):**
- **Människokortet** på egen tavla är människans enda yta: intake = tilldela bot-användaren; 3 labels (hos-agenten/agent-fråga/agent-klar); levande statuskommentar; BLOCKED-svar + review-verdikt från eget kort; recall = unassign.
- **"Min agent"-dashboardwidget** (M7.5, byggt) — daglig överblick.
- **Talk** — capture + notiser + `!queue`/`!status`.

**Inställningar / aktivering (adminyta i Hubs — att bygga, M-B):**
- AdminSettings "Agent Engine": per-agent **skill/runbook-toggle-grid** + **routing-map-editor**; toggle skriver `AGENT SKILL SUBSCRIBED/DECLINED`-kvitto → loaderns prenumerationsset.
- PersonalSettings "Min agent": per-människa **companion-samtycke** (grant/inspect/revoke per källa, med BankID-grade re-auth).

**GUI-visualisering:** dashboard-widget + (kommande) en översiktsvy "vad vet mitt team / vem-vet-vad" ovanpå routern.

---

## 5. Externa gränssnitt i Hubs (CRM, VLS, KB-artiklar)

**Ja — externa webbappar kan lyftas in i Hubs** via Nextclouds **External Sites**-app (iframe-inbäddad post i Hubs-navigationen, per-grupp-synlighet). Strategi per modul:

| Modul | Status på MacMini | Plan |
|---|---|---|
| **CRM** | Fungerade **mycket bra** | **Porta som-den-är** → kör som extern webbapp, bädda in i Hubs via External Sites. **Koden finns EJ i repona jag har** → måste lokaliseras på MacMini och migreras in. SSO/identitet mot Hubs att lösa. |
| **KB-artiklar** | Fungerade **bra** (`kb-pipeline/web/review.html`, 53 kB single-file) | **Bevara + utveckla.** Bädda in som External Site (servern serverar `review.html` bakom åtkomstkontroll). Matas av ingestion-korpusen. |
| **VLS** | **Fungerade aldrig bra** | **Omtag.** Behåll de bra idéerna ur `vls-core` (org-VLS, PDCA, KPI:er, ISO-compliance-as-code, org-brain-aggregering) men designa om. Kandiderar för att bli en NC-app/adminvy + Deck snarare än en fristående JSX-app på Mac. Egen fas (M-VLS). |

---

## 6. Kodkonsolidering → ETT repo, GitHub-hanterat

**Nuläge (utspritt):**
- `Nextcloud-vanilla` (GitHub: FredrikJonassonItsb/Nextcloud-vanilla) — innehåller `openstack-itsl/` + Hubs-apparna. **Aktivt.**
- `vls-core` (GitHub: FredrikJonassonItsb/vls-core) — separat.
- `Kunskapsbanken/kb-pipeline` — eget lokalt git, ej på gemensam GitHub.
- **CRM** — MacMini, okänt repo.

**BESLUTAT (Fredrik 2026-07-05):** ETT nytt dedikerat GitHub-repo **`itsl-open-stack`** (privat) — Open Stack är en egen produkt skild från Hubs-apparna (renare historik, CI, behörigheter).

**Migrationsplan (M-REPO), historikbevarande:**
1. Skapa privat repo `FredrikJonassonItsb/itsl-open-stack`.
2. **openstack-itsl** → repo-roten, historik bevarad via `git subtree split -P openstack-itsl` (från Nextcloud-vanilla) → push som `main`. (Denna sessions arbete ligger på gren `feat/openstack-ingestion` — merga den först, så följer den med.)
3. **vls-core** → `legacy/vls-core/` via subtree-merge (historik bevarad från FredrikJonassonItsb/vls-core).
4. **kb-pipeline** → `kb-pipeline/` via subtree (dess lokala git pushas först till GitHub, sedan subtree-merge; historik bevarad). KB-UI (`web/review.html`) följer med.
5. **CRM** → `crm/` när koden lokaliserats på MacMini.
6. openstack-itsl i Nextcloud-vanilla: lämnas kvar tills nya repot verifierats, tas sedan bort (undvik dubbelunderhåll).

**Val att bekräfta före körning:** (i) bevara full historik per källa (rek.) vs färsk start; (ii) köra nu vs efter merge av `feat/openstack-ingestion`.

---

## 7. Uppdaterad fasplan (konsoliderad)

Ordning efter beroende och värde. Fetstil = kan börja nu utan blockerare.

| Fas | Innehåll | Beroende |
|---|---|---|
| **M-REPO** | Beslut mono-repo (a/b) + migrera vls-core, kb-pipeline, CRM, KB-UI in; GitHub + CI. | — |
| **M-A** | Skills-loader + enrads-descriptions + CI-lint (gör skills per-agent-aktiverbara). | — |
| **M-B** | Admin-aktiverings-UI (skill/runbook-toggle + routing-editor) + companion-samtyckesyta. | M-A |
| **M-C** | Förstklassig runbook-exekvering + case-packet-runbooken. | M-A |
| **M-ING** | Fler native-konnektorer: **Talk (körbar nu, NC nåbart)** → Mail → Meetings → GitLab → Zammad (VPN-blockad, senare). | contract (klar) |
| **M-ROUTER** | Central 2-menings-router (vem-vet-vad) + översiktsvy. | brains fylls |
| **M-D** | Companion Tier-2-kärna (provenance-flagga, EGRESS-firewall, verifierad ägarbindning). | — |
| **M-CRM** | Lokalisera CRM-kod → porta som-den-är → External Site i Hubs + SSO. | M-REPO |
| **M-KB** | KB-artikel-UI (`review.html`) som External Site + vidareutveckling. | M-REPO |
| **M-VLS** | Omtag VLS: org-VLS/PDCA/KPI/compliance som NC-app/adminvy + Deck. | M-REPO, M-B |
| **M-GUI** | Vidare GUI-visualisering (team-överblick, vem-vet-vad). | M-ROUTER |

---

## 8. Öppna beslut (behöver dig)

1. **Mono-repo:** (a) under Nextcloud-vanilla, eller **(b) nytt dedikerat `itsl-open-stack`-repo** (rek.)?
2. **CRM-kod:** var ligger den på MacMini? (repo/sökväg) — behövs för M-CRM.
3. **Rebeccas roll** (Reb) nu när Johan utesluts — tar hon dev/CTO-domänen?
4. **VLS-omtag:** hur mycket av `vls-core`-idéerna (108 processer, SoA, KPI, PDCA, riskregister) ska med i första VLS-versionen?
5. **Router-åtkomst:** ser central router bara 2-menings-sammanfattningar (rek.) eller mer?
6. **Zammad-token:** rotera (chatt-exponerad); VPN-väg dev15→Zammad fixas när?

---

## 9. Denna sessions leverabler (klart)
- Web-verktyg (WebSearch/WebFetch) i runnern — deployat+verifierat.
- Companion-säkerhetsdesign (2-tier) — doc.
- Ingestion skill-katalog (8 skills) + runbook — nativt mot Hubs.
- Native ingestion-tjänst + Zammad-konnektor (11 tester, dry-run mot skarp Zammad, deployad; live-skrivning väntar på VPN).
- Allt committat + pushat: `feat/openstack-ingestion`.
