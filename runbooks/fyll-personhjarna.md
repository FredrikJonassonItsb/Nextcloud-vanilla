# Runbook 01 · Fyll personhjärna

**Chain:** `ingest-zammad → ingest-talk → ingest-mail → ingest-meetings → ingest-gitlab → evidence-normalize → pii-pseudonymize → skriv-till-brain`

**Trigger:** nattlig inkrementell sync (cron), eller på begäran när en persons brain ska fyllas/uppdateras med allt som hänt hen sedan förra körningen.

**Payoff:** personens openbrain innehåller en spårbar, deduplicerad, aktuell spegel av hens interaktioner tvärs alla källor — "summan av allt som hänt människan" — redo för recall.

## Stegkedja (per person, per körning)
Varje steg är inkrementellt (cursor) och idempotent (`content_fingerprint`). Ett steg som felar fäller inte de andra (se [source-connector-contract](../skills/ingestion/source-connector-contract/SKILL.md)).

1. **ingest-zammad** → nya support-artiklar (kräver personens Zammad-scope).
2. **ingest-talk** → nya rumsmeddelanden (kräver medlemskap; exkludera 1:1-rum).
3. **ingest-mail** → nya SentItems (kräver delegerad brevlåda; draft-only).
4. **ingest-meetings** → nya mötesturer (kräver Fireflies-deltagande).
5. **ingest-gitlab** → ny utvecklingshistorik.
   → varje post genom **evidence-normalize** (enhetlig form) och skrivs `POST ${BRAIN_URL}/ingest`.
6. **Skrivgrind:** brain-brandväggen (`422`) är final; dedup via `content_fingerprint` (created/merged).

## Human gates (per companion-säkerhetsmodellen)
- Källåtkomst som vidgar räckvidd (ny brevlåda, nytt rum, ny person) → `AGENT HUMAN HOLD` + **verifierad ägargrant (Tier-2, BankID-grade re-auth)**.
- Inget lämnar personens brain utan **pii-pseudonymize** + egress-kontroll (owner-trusted → default-deny egress).

## Central router (valfritt, efter skrivning)
Per ny/uppdaterad post: generera en **2-menings-sammanfattning** och skriv till den centrala routern (team/org-brain) som `{person, summary, topics, source, pekare}`, så "vem-vet-vad" hålls aktuell **utan att råinnehåll lämnar personhjärnan** (routern ser sammanfattningar, inte råtext — om inte annat beslutats). Öppen designfråga, se companion-design-doc §5 + `SKILLS-RUNBOOKS-COMPANION-DESIGN-2026-07-05.md`.

## Bevis före "klart"
Alla cursors framflyttade; poster spårbara (`source_url`+`raw_ref`); 422:or respekterade; ev. router-sammanfattningar skrivna; en `RUN_RESULT`-rad med antal per källa.
