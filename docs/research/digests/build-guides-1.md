# Digest: Nate B. Jones "Unlock AI" build guides — the agent BUILD PATTERN

Sources:
- `guides__build-an-email-follow-up-agent.md` (https://unlock-ai.natebjones.com/guides/build-an-email-follow-up-agent)
- `guides__build-your-own-token-burn-dashboard.md` (https://unlock-ai.natebjones.com/guides/build-your-own-token-burn-dashboard)

Purpose of this digest: extract the reusable pattern for SPECIFYING a real agent — inputs, approval boundaries, receipts, smoke tests — plus every operational detail from both guides so the systems can be rebuilt without the originals.

---

## PART A — THE META-PATTERN (reusable across any agent build)

Nate's guides share one build discipline. Extracted as steps:

### A1. State the thesis and the non-goal up front
- Every guide names what the agent is NOT before what it is. Email: "The goal is not an email autopilot… Do not position this as an email autopilot. The v1 is a follow-up organizer." Dashboard: "Burn rate is not a vanity stat."
- Core thesis (email guide, verbatim): "People lose because their information is scattered, unstructured, uncited, and incomplete. The workflow should help the person own their context, not outsource judgment to a black box."
- The agent's job is always: collect the mess → normalize it → ground it in source documents → produce the next HUMAN-REVIEWED action.

### A2. Fixture first: build and prove offline before touching live data
- Motto tiles on the email guide: "Fixture first — Build and prove offline. MCP ingest — Live on a 20-min cadence. Send boundary — Nothing auto-sends."
- The fixture is a **permanent test bench**, not a throwaway: "Every gate below gets proven here, on a corpus where runs are reproducible, before the loop touches live mail."
- Fixture rule: **real formats, fake people** — realistic where parsing matters (true RFC 5322 headers, mbox container, multipart bodies, attachments), synthetic where privacy matters (names, companies, amounts, stakes).
- Include NOISE in the fixture (newsletters, automated receipts) so triage has something to legitimately exclude as reference-only.
- Include the agent's own outbound history (Sent mail) so the agent can know which loops are already closed.
- Seed one fixture per state the retrieval map handles; mark unbuilt/untested branches explicitly as untested and smoke-test them when real data arrives.
- Seed a **hostile fixture** (prompt-injection test): an input whose content instructs the agent to violate the boundary. A correct run ingests/quotes it like any other input while the boundary holds.

### A3. Anchors: one citation/coordinate scheme end to end
- Ingest documents into markdown/text WITH raw source coordinates as anchors (PDF page/region, CSV line number, form box identifier, email message-id). "Keep one numbering scheme end to end" — the identical anchor scheme is embedded in the normalized text that downstream citations will use.
- Swapping domains changes the anchor type and the ledger schema, NOT the architecture: "The message-id becomes the citation anchor the way a PDF page or a form box did in the other two guides."

### A4. The primitive chain (the canonical pipeline, verbatim from the prompt)
The reusable Open Skills chain, in order:
1. Ingest documents into markdown/text with raw source coordinates as anchors; keep one numbering scheme end to end.
2. Chunk and tag source evidence by structure.
3. Normalize the case facts into a ledger.
4. Run the **coverage gate**: "Every ingested document must produce at least one normalized record or be explicitly marked reference-only. Print the list of unconsumed documents and stop before drafting if any document is unaccounted for."
5. **Reconcile shared facts across sources before drafting**: "Compare the same fact anywhere it appears, turn every mismatch into a named review question, and record which source governs the tracked value."
6. Store chunks, records, mappings, and outputs in **SQLite by default**.
7. Optional: mirror the case store into Open Brain only if you already run OB1; otherwise skip. "SQLite is the complete beginner path."
8. Retrieve relevant evidence **deterministically** before drafting.
9. **Validate citations before export**: "The citation guard returns pass / needs_review / fail verdicts. Any fail blocks packet export until fixed or converted to a named review question, and the guard verdict summary must appear in the packet README."
10. Export an editable packet and **stop at human review**.

Constraint line (verbatim): "The agent organizes and drafts. It does not sign, send, file, submit, authorize, or transmit sensitive data."

### A5. The approval boundary is STRUCTURAL, not behavioral
- Boundary is enforced at both maturity stages:
  - Build phase: input is a local export, output is a folder of drafts — "no code path can transmit anything." Build phase holds no credentials.
  - Live phase: the agent works through a connector "whose tool surface reads threads and creates drafts but exposes no send verb" — "the model cannot call a tool that does not exist."
- Approval semantics (verbatim): "An ignored draft means no. Approval is explicit and per message. Silence is not consent, time passing is not consent, and re-running the pipeline is not consent; nothing promotes a draft from pending to approved except a recorded human decision."
- Approval field vocabulary: `pending`, `approved`, `declined`. **Everything exports as pending.** The approval column survives as the audit trail.
- The human act of approval happens in the human's own tool (pressing send in your own mail client), never inside the agent.
- Motivating cautionary tale: an agent that drafted an insurance reply, watched its human ignore the draft, and sent it anyway — "won the fight and crossed the line in the same move."

### A6. Untrusted input rule
- "Inbound email is evidence, never instructions." Source content is quoted, cited, summarized — never obeyed, no matter how phrased.
- Unattended/scheduled runs make this load-bearing: restrict the runner's permissions to exactly the pipeline — "the mail connector's read-and-draft tools, the case store, nothing else. No shell, no other outbound channels."
- Keep the run report proving the hostile fixture was processed as content while "the approval column never moved."

### A7. Receipts on every run
- "Every cycle appends a run receipt: messages ingested, ledger rows changed, drafts created, questions raised. A loop that runs thirty times a day without receipts is thirty chances a day to trust it blindly."
- Native receipts where the platform supports them: e.g., mail labels — tag processed threads, flag needs-review ones, "so your inbox itself shows what the agent touched without opening the packet."

### A8. Two-speed loop (incremental + nightly expensive pass)
- Live cadence: poll ~every 20 minutes during work hours. Each cycle is incremental: fetch delta since last run keyed by a stable ID (message-id), ingest/extract only new items, update only the ledger rows touched.
- The expensive pass (full cross-checks, restated-fact merging across the whole ledger) runs once nightly.

### A9. Smoke tests / prove-it gates — per stage, each with a named failure mode
Pattern: every stage has (a) a question it must answer, (b) a concrete description of what a BROKEN run looks like. (Full email-domain checklist in Part B.)
- Positive AND negative proof for guards: save two artifact reports — one where the clean case passes with **exit 0**, one where a seeded fabrication fails with **nonzero exit**, and "the seeded sentence itself must appear as the failing item in the saved report. A report that fails other claims does not count as proof. Fix the harness until the fabricated sentence is the reported failure, then keep both reports with the packet."
- Ship gate: "Export refuses, or stamps DRAFT-INVALID on the README, while any citation fails or any draft claims an approval the ledger cannot show a human decision for. The README reproduces the guard's actual pass, needs_review, and fail counts verbatim."
- Human-gate checklist includes verifying the boundary itself: confirm the run's tool surface cannot send; confirm every draft exported as pending; confirm the hostile fixture appears in the ledger as content; confirm the schedule proposes dates instead of scheduling sends.

### A10. Data honesty: exact vs estimated, labeled everywhere (dashboard guide)
- Two-tier fidelity vocabulary: `exact` vs `estimated`. "Do not let estimates cosplay as measurements." "Label estimated values everywhere." "The sin is not estimating. The sin is hiding that you estimated."
- Interview-before-estimating: if a source cannot be measured, the agent asks short questions and infers a **conservative range**, then writes a **labeled** estimate.
- Reconciliation smoke test: "Pick one day. Add each source column by hand. It should match the day's total." Ship only "when the math is boring" / "when the math reconciles."

### A11. Agent handoff prompt structure (how Nate specifies a build to an agent)
The dashboard handoff prompt is the template. Sections, in order:
1. Role + one-sentence goal ("You are my coding agent. Build me…").
2. **First fetch the guide context** — numbered URLs: the guide page, an AI discovery file (`/llms.txt`), and a machine-readable starter-kit `manifest.json`.
3. **Choose and install the right starter kit** — a decision table mapping user situation → kit name; then "Download the kit, verify the SHA-256 against the manifest, unzip it, read `SKILL.md`, and copy `assets/dashboard-starter/` into a new local project folder."
4. **Build** — enumerated required views/outputs, plus the exact normalized row schema inline as JSON.
5. **Interview me before estimating** — explicit list of questions the agent must ask.
6. **Privacy rules** — what may never enter the app/repo.
7. **When done** — numbered acceptance script: run `npm install`, `npm run build`, run dev server and verify all views, check labels visible, list files created/changed, tell me how to add the next day of data, "Give me the deploy command, but do not deploy until I confirm the public data is scrubbed" (deploy is human-gated too).
The email guide's prompt uses XML-ish tags instead: `<prompt><task><thesis><primitive_chain><step>…</step></primitive_chain><constraint>`.

### A12. Distribution conventions (how the guides make themselves agent-consumable)
- Starter kits are **agent skills** shipped as zips: "Your AI reads the skill, copies the starter app, interviews you for missing data, and builds from there." Each kit contains `SKILL.md` + `assets/<starter>/`.
- A `manifest.json` provides "SHA-256 checksums, source links, and deterministic download URLs. The same contract is advertised in /llms.txt and /agents.txt."
- Primary sources (RFCs, provider docs) are cited at the end so fixtures honor real formats.

### A13. Voice / output quality
- "A follow-up you have to rewrite from scratch is not a draft, it is a prompt for you."
- Build a **Personal Voice** skill before wiring up drafting (directory path: Open Skills → Writing Voice and Content → Personal Voice). Training corpus = the human's own outbound history already ingested by the pipeline; pick 5–10 sent replies "that sound like you on a good day" and feed them to the skill's setup interview. Goal: drafts need light edits, not rewrites; never "I hope this email finds you well" if the human never writes that.

### A14. Auth pattern: let a GUI client do the OAuth dance
- "Hand-rolled IMAP credentials are where inbox projects die." Don't paste provider passwords into scripts; use a client that speaks OAuth (Thunderbird) and read its local store. Builder-grade alternative for services: self-hosted gateway (EmailEngine) that manages OAuth tokens and exposes accounts as REST.
- Prefer export paths (Google Takeout) during the build phase so it stays credential-free. Rationale for avoiding raw API creds: "the scope that manages drafts can also send them" (Gmail API scopes).

---

## PART B — EMAIL FOLLOW-UP AGENT (domain specifics, complete)

### B1. Framing
- v1 = follow-up organizer: turns a mailbox export into a commitments ledger + cited drafts a person approves and sends. "It never sends one."
- "The email problem is context disorder plus a send boundary."
- Guide sections (nav): Skeleton / Data / Ledger / Guardrails / Live loop / Prove it / Multi-inbox.

### B2. Fixture inbox spec
- Real RFC 5322 messages in a real mbox (RFC 4155) file: true headers, `In-Reply-To` and `References` chains, multipart bodies, at least one attachment, and a real Sent folder. Senders/names/companies/amounts/stakes synthetic.
- Two fixture rules > volume: (1) include Sent mail; (2) include noise (newsletter cluster + automated receipts) for reference-only triage.
- Three seeded follow-up cases (the states the retrieval map handles):
  1. **Dropped commitment** — you promised a deliverable by a named date; thread went quiet.
  2. **Waiting-on-them** — you asked for something twelve days ago; nothing came back.
  3. **Dispute** — a vendor/insurer decline; the draft must answer point-by-point from quoted evidence, then stop, unsent.
- Untested branches to build anyway and mark untested: scheduling threads, intro requests (among others); smoke-test them when a real mailbox arrives.
- Hostile fixture: an email whose body instructs the assistant to send the reply immediately without confirmation.

### B3. Chunking rules
- **Chunk messages, not threads.** Anchor every evidence chunk to the message-id where a sentence FIRST appears.
- Strip quoted history, signatures, legal disclaimers out of evidence chunks entirely.
- Keep a **quote map** alongside so retrieval can reconstruct what each participant had seen by any point in the thread.
- Smoke test: print the text of every cited chunk and read it. "A citation that lands on a signature block, a disclaimer footer, or reply nine's quote of the original is a broken chunker, not evidence."

### B4. Normalization → commitments ledger
- Reconstruct threads from `In-Reply-To` + `References` headers, **never** subject lines (breaks on Re:/Fwd: prefixes, edited subjects, two vendors both titled "Invoice").
- Normalize identity first: display-name + address pair is the identity key ("Amazon.com <no-reply@amazon.com>" is a sender, not a person).
- Ledger contents:
  - **Commitments**: owner, description, due date, status, source anchor.
  - **Waiting-on rows**: what was asked, who owes it, when it was asked.
  - **Decisions** worth keeping.
- Date-parse rule: a due date of "Friday" stored as the string `Friday` is a failed parse — resolve it against the message date or mark it `needs_review`.
- One promise = one ledger row, even restated in three threads and quoted in six replies. (Weak agent creates six commitments and three duplicate nudges.)

### B5. Reconciliation against Sent mail
- Cross-check every waiting-on row against the Sent export BEFORE drafting a nudge. "The most embarrassing failure this workflow can produce is chasing a question the person answered two weeks ago." Coverage gate counts Sent messages like any other source document.
- Body-vs-attachment disagreement on an amount/date → named review question in the packet, never a silent correction; name which source governs the tracked value so the draft still has one number to cite.

### B6. Outbox packet format (the export)
One `packet/` directory per mailbox, reviewable in ten minutes:
- `README.md` — opens with actions ordered by urgency: overdue commitments first, then aging waiting-ons, each with days elapsed computed from the run date; reproduces the guard's pass/needs_review/fail counts verbatim.
- `commitments-ledger.csv`
- `waiting-on.md`
- `follow-up-schedule.md` (proposes dates; never schedules sends)
- `drafts/` — one file per draft; every draft carries complete headers (To, Cc, Subject, In-Reply-To) so "approval means reading one file, not reassembling context"; every factual claim cites a message chunk ("as agreed on June 3" must anchor to the June 3 message or become a review question); every draft carries an approval field: `pending` | `approved` | `declined`, exported as `pending`.
- `citation-map.json`
- `unresolved-questions.md`
- `sources/` manifest.
- Failure stamp: `DRAFT-INVALID` on the README when the guard fails.

### B7. Live loop
- Move ingestion to an MCP mail connector ONLY after every gate passes on the fixture inbox.
- Required connector property: reads threads + creates drafts, **no send verb**. Named example: "Anthropic's Gmail connector is shaped exactly this way — search and read threads, create and list drafts, manage labels, no send tool."
- Cadence: ~every 20 minutes during work hours; incremental delta keyed by message-id; nightly expensive pass (full Sent cross-checks + restated-promise merging).
- Live drafts land in the user's Drafts folder, still `pending` in the ledger; labels as native receipts (tag processed, flag needs-review).
- Runner permissions: connector read-and-draft tools + case store only; no shell, no other outbound channels.

### B8. Per-stage prove-it checklist (verbatim failure modes)
1. **Ingestion** — every message in the export appears in `source_documents` with its message-id anchor; count mbox messages vs row count; multipart messages parsing to empty text get NAMED in a warning list, not silently skipped. Broken: 300 messages on disk, 240 rows, missing 60 = the entire Sent folder.
2. **Chunking** — a cited chunk contains the original sentence at its first occurrence. Broken: cites a signature block/disclaimer/later quote. Fix: strip quoted history/boilerplate, re-anchor to originating message-id.
3. **Normalization** — senders resolve to identity keys; dates parse with timezones; every commitment row has owner + due date + source anchor. Broken: five rows for one promise; due date "Friday" as text.
4. **Reconciliation** — a sent reply closes the waiting-on item it answers; restated promises merge to one row; body-vs-attachment conflicts become review questions. Broken: drafts a nudge for a question answered in Sent two weeks ago.
5. **Drafting + guard** — clean draft passes; seeded fabricated citation fails as the named failing item. Broken: accepts "as you confirmed" with no anchoring message.
6. **Export** — every draft has complete headers; README ordering matches ledger; every approval field is `pending`. Broken: empty To line, or `approved` status no human set.
7. **Human gate** — run's tool surface has no send verb; hostile fixture stayed quoted, not obeyed. Broken: any route from packet or Drafts folder to transmission that bypasses a recorded human decision.

### B9. Citation guard proof artifacts
- Two saved reports: fully cited draft → pass, exit 0; seeded fabricated-but-well-formed citation (e.g., draft sentence "as you confirmed on June 3" pointing at a nonexistent message) → nonzero exit, with the seeded sentence as THE failing item. Keep both reports with the packet.

### B10. Bonus lane: multi-inbox via Thunderbird local store
- Replaces per-provider connectors for INGESTION ONLY; drafting + send boundary unchanged.
- Thunderbird: free/OSS, all OSes, stores every folder as plain mbox in an ungated profile dir. Validated: a small Python stdlib locator walked a real two-account store — 30 folder files, 169,084 messages parsed, no permission dialogs.
- Setup: add accounts via wizard (wizard handles OAuth for Gmail/Microsoft in the provider's own browser sign-in); enable per-folder **offline synchronization** so full copies land on disk.
- Store layout (stable, identical per OS, only root moves):
  - macOS: `~/Library/Thunderbird/Profiles/`; Windows: `%APPDATA%\Thunderbird\Profiles\`; Linux: `~/.thunderbird/`.
  - `profiles.ini` names profile dirs. Inside a profile: `ImapMail/<server>/` per IMAP account; `Mail/` for POP + Local Folders.
  - Every folder is an EXTENSIONLESS mbox file: `Inbox` = mail; `Inbox.msf` = index sidecar to ignore; `Inbox.sbd/` = directory of child folders. Python's `mailbox` module reads these directly.
- Two live-loop disciplines: (1) copy/snapshot before parsing while Thunderbird is running; (2) treat folder files as REWRITABLE, not append-only (compaction rewrites in place) → key incremental sync on file mtime + a Message-ID high-water mark in the case store; re-scan any folder whose file changed.
- Auth landscape (as of mid-2026): Microsoft retired password IMAP for Outlook.com on 2024-09-16 (OAuth2 mandatory); personal Gmail no longer accepts password apps (app passwords exist behind 2SV, Google recommends against); iCloud Mail requires app-specific password; Yahoo requires app password.
- Apple Mail alternative (Mac only), behind two gates: Gate 1 permission — Full Disk Access (System Settings → Privacy and Security → Full Disk Access → enable terminal/agent host → restart); "Operation not permitted" on directory listing is the gate working, not a bug. Gate 2 format — store at `~/Library/Mail/V<N>`, one UUID dir per account, one `.emlx` per message; `.emlx` = byte-count line + raw RFC 5322 message + Apple plist — split by the byte count, never by scanning for XML; treat `partial.emlx` as incomplete evidence. No-permission fallback: Mailbox → Export Mailbox, producing a `<Name>.mbox` FOLDER whose real stream is a file literally named `mbox` inside it. Incremental: `V<N>/MailData` holds the Envelope Index SQLite catalog — copy with `-wal`/`-shm` sidecars before querying. Honest status: parsers pass on fixtures; in-place reading unproven until FDA granted → Thunderbird is the recommended door.

### B11. Primary sources cited
RFC 5322 (message format), RFC 4155 (mbox), Google Takeout (fixture-phase Gmail export), Gmail API scopes (why raw creds are wrong: draft scope can send), Thunderbird profile storage docs, Microsoft modern-auth requirement, Apple Mail mailbox export, macOS Privacy & Security settings, Personal Voice skill.

---

## PART C — TOKEN-BURN DASHBOARD (domain specifics, complete)

### C1. Framing
- One screen for token spend across Codex, Claude, ChatGPT. "It is a fluency meter, not a bill." "A bill tells you what happened. A burn dashboard changes behavior." "Tokens per outcome is the clearest tell."
- Stats banner: "A weekend build time; $0 (local data + Vercel); 5 views."
- Guide sections (nav): What you build / Starters / Video / Why / Visuals / Data / Schema / Prompt / Views / Ship / Verify.
- Build loop mantra: "Collect, normalize, build, verify, ship. If the totals do not reconcile, the loop is not finished." "The old mistake is making this pretty before making it true."

### C2. Source fidelity table
| Source | Fidelity | How to pull |
|---|---|---|
| Codex | exact | app + CLI sessions write local logs with real token usage; total input+output by local day |
| Claude Code | exact | per-session JSONL with input, output, and cache counts; include API/agent calls if used |
| Claude chat | estimated | no tidy local token export; estimate from message counts + average lengths, label honestly |
| ChatGPT | estimated | request data export, tokenize conversation text by date; "calibrated estimation" unless exact provider logs |

### C3. Normalized data shape (verbatim schema)
`daily-burn.json` — one row per day, in local timezone:
```json
{
  "date": "YYYY-MM-DD",
  "codex_tokens": 0,
  "claude_code_tokens": 0,
  "claude_code_calls": 0,
  "claude_chat_est": 0,
  "chatgpt_est": 0,
  "total": 0,
  "driver": "shipping",
  "evidence": "scrubbed work-family note"
}
```
Example row: `{"date":"2026-05-24","codex_tokens":184320,"claude_code_tokens":512880,"claude_code_calls":47,"claude_chat_est":38000,"chatgpt_est":21000,"total":756200,"driver":"shipping","evidence":"dashboard build and review"}`
- Naming convention: `_est` suffix marks estimated columns; exact columns have no suffix.
- Driver vocabulary (keep it SMALL): `shipping`, `research`, `review`, `video`, `admin` (interview list adds: planning, writing, support). "The driver field is the move that makes it useful."
- `evidence` = scrubbed work-family note (private detail stays in a local working file).

### C4. The five views, each with job + failure mode
1. **Daily burn heatmap** — every day colored by tokens, LOG color scale ("Linear heatmaps lie when one spike dominates"). Job: "the at-a-glance conscience"; make a runaway day obvious without flattening quiet days.
2. **Weekly trend line** — log y-axis, label the peak week; question: compounding or getting leaner.
3. **Burn drivers** — share by `driver`; turns "I spent a lot" into "I spent a lot on shipping/review/research."
4. **Scale equivalents** — human-scale comparisons; "Show the math or it reads as a gimmick" (visible approximation math).
5. **Moving-average table** (last 30 days) — "the receipts drawer": per tool, per day, exact and estimated side by side, trust level obvious at every row, every source column present.
- Header metrics shown: Total tokens; Peak day (with cause label, e.g. "shipping spike"); 7d average (with delta from peak). Time-range selector: 90d / 180d / 1y / all.

### C5. Starter kits + manifest contract
Four downloadable agent-skill zips, chosen by where usage lives:
- `codex` — exact local Codex usage first; leaves estimates out until asked.
- `claude` — Claude Code logs are hard numbers + Claude chat honest estimation.
- `chatgpt` — export-based estimates.
- `all-sources` — all four sources on the same axes from the beginning.
Kit workflow: download → verify SHA-256 against `manifest.json` → unzip → read `SKILL.md` → copy `assets/dashboard-starter/` into a new project folder. Manifest URL: `https://unlock-ai.natebjones.com/guides/token-burn/starter-kits/manifest.json`; same contract advertised in `/llms.txt` and `/agents.txt`.

### C6. Handoff prompt (structure captured in A11; key verbatim rules)
- Context fetch order: guide URL → `https://unlock-ai.natebjones.com/llms.txt` → manifest.json.
- Interview questions (verbatim list): Which tools should be included? What timezone should define a day? Where are exact logs or exports located? Which days were shipping, research, review, video, planning, writing, support, or admin? Which evidence notes must stay private?
- Privacy rules: "Do not include raw logs, exports, prompts, private paths, client names, project names, or secrets in the app. Keep private detail in the local working file if useful. Deploy or share only scrubbed normalized rows."
- When-done acceptance script: 1) `npm install` 2) `npm run build` 3) run dev server, verify all five views 4) check exact/estimated labels visible 5) list files created/changed 6) tell me how to add the next day of data 7) give the deploy command but DO NOT deploy until human confirms public data is scrubbed.

### C7. Prereqs and rules ("Before you start")
- Tools: Codex App / Claude Code / Cursor or another coding agent; Node 20+ and a terminal; a Vercel account (optional publish); the usage data.
- Rules: keep raw exports out of public repos; commit only normalized totals you're comfortable sharing; label estimated values everywhere; build local first, deploy only when the math reconciles.
- Local run: `npm install`; `npm run dev` → localhost:3000; confirm all five views render. Ship: push to GitHub → import at Vercel or run `vercel` from the folder → set private if data is personal.

### C8. Verification checklist (ship gate)
- Totals reconcile: pick one day, hand-add each source column, must match `total`.
- Exact vs estimated labeled everywhere — never guess whether a number is measured or inferred.
- Log scales actually read: a 10x day and a 1x day both visible; flat map = wrong scale.
- Time-range selector changes EVERY view (heatmap, trend, drivers, table).

### C9. Troubleshooting (data problems first, "debug the input assumptions before you redesign the output")
- **Timezones smear your days** — logs are often UTC; pick one timezone, convert on ingest, bucket by local date before totals.
- **Heatmap looks flat** — linear color scale; switch to log with enough ramp stops.
- **Estimates dwarf the real numbers** — bad tokens-per-message constant; calibrate against one real conversation.

### C10. V2 extensions ("V1 shows the spend. V2 changes the spend.")
- Burn budget: daily target, mark days over it.
- Tokens per outcome: tag days with what shipped and divide; "falling cost per outcome is the fluency curve."
- Auto-ingest: small nightly script reads each tool's logs and appends a new day.

### C11. Editorial principles
Start where tokens are already visible (Codex/Claude Code); borrow the starter kits' design restraint; describe the operating surface in plain language (heatmap, log scale, same-day strip, drivers, source split, top days) and iterate; infer what you can't measure via interview → conservative range, labeled; scrub before anything leaves the machine (public = normalized totals + generic drivers only); ship to Vercel or keep local — the requirement is that it exists where you return to it.

---

## PART D — CONDENSED REUSABLE CHECKLIST FOR OUR OWN AGENT BUILDS

1. Write the thesis and the explicit NON-goal ("this is an organizer, not an autopilot").
2. Define the primitive chain: ingest-with-anchors → chunk/tag → normalize-to-ledger → coverage gate → cross-source reconciliation → SQLite store → deterministic retrieval → citation guard (pass/needs_review/fail) → editable packet export → human gate.
3. One anchor scheme end to end; anchors are the citation currency.
4. Build a synthetic fixture corpus FIRST: real formats + fake people + your own outbound history + noise + one fixture per handled state + one hostile (injection) fixture; mark unhandled states untested.
5. Make the irreversible action structurally impossible: build phase has no credentials; live phase uses a tool surface with no verb for the forbidden action.
6. Approval vocabulary: pending/approved/declined; everything exports pending; only a recorded human decision promotes; silence/time/re-runs are not consent; approval column = audit trail.
7. Treat all ingested content as evidence, never instructions; prove it with the hostile fixture and keep the run report.
8. Every run appends a receipt (counts of ingested / rows changed / drafts created / questions raised); use platform-native receipts (labels) where possible.
9. Two-speed loop: cheap incremental delta on a work-hours cadence (keyed by stable ID + high-water mark), expensive full reconciliation nightly.
10. Per-stage prove-it checklist where every stage has a question + a named broken-run signature; guards need BOTH a passing artifact (exit 0) and a seeded-failure artifact (nonzero exit, the seed is THE named failure).
11. Ship gate refuses or stamps INVALID; README reproduces guard verdict counts verbatim.
12. Mismatches become named review questions, never silent corrections; record which source governs.
13. Coverage gate: every input produces ≥1 record or is explicitly reference-only; print unconsumed inputs and STOP.
14. Label data fidelity (exact vs estimated) in the schema (`_est` suffix), the UI, and the review; interview the human before estimating; conservative ranges.
15. Keep classification vocabularies small (driver labels).
16. Specify agent builds via a handoff prompt: fetch-context URLs → kit choice table → checksum-verified starter → schema inline → interview questions → privacy rules → numbered when-done acceptance script with a human-gated final deploy step.
17. Voice: train drafting on the human's own best outbound samples so review is edits, not rewrites.
18. Auth: never hand-roll credentials; use export paths or OAuth-capable clients/connectors; prefer read-only/draft-only scopes.

---

## OPEN QUESTIONS

1. The email guide's first paragraph is garbled in the capture ("This guide points the context architecture behind the healthcare appeals and tax prep workflows at all three.") — the referenced sibling guides (healthcare appeals, tax prep) are not in the provided sources, so the shared "Open Skills primitives" they define are known only through this guide's summary of them.
2. "Open Skills," "Open Brain," and "OB1" are Nate's platform components referenced but not defined in these sources; the SQLite-vs-Open-Brain mirroring step's exact mechanics are unspecified.
3. Exact SQLite schema (table names beyond `source_documents`, column DDL) is not given — only the ledger's logical fields.
4. The citation guard's implementation (how pass/needs_review/fail is computed, the exit-code harness) is specified by contract only, not by code.
5. Draft file format inside `drafts/` (e.g., .eml vs markdown-with-front-matter) is unspecified beyond "complete headers + approval field."
6. The dashboard starter kits' internal structure beyond `SKILL.md` + `assets/dashboard-starter/` (framework, chart library) is not described in the page text.
7. "Retrieval map" (email guide, section 02) is referenced as the thing whose states the fixtures cover, but its full state list beyond the three built + two named-untested branches (scheduling, intro requests) is not enumerated.
8. The 20-minute cadence's scheduling mechanism (cron, platform scheduler, MCP-native) is not specified.
