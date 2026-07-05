# Digest: Open Skills Runbooks, Skills Directory Organization, and Guides Index (Unlock AI / Nate B. Jones)

Sources digested:
- `open-skills__runbooks.md` — https://unlock-ai.natebjones.com/open-skills/runbooks ("Open Skills Runbooks | Unlock AI")
- `open-skills.md` — https://unlock-ai.natebjones.com/open-skills ("Open Skills | Unlock AI", the overview/landing page)
- `open-skills__skills.md` — https://unlock-ai.natebjones.com/open-skills/skills ("Open Skills Directory | Unlock AI")
- `guides.md` — https://unlock-ai.natebjones.com/guides ("Living guides | Unlock AI")

Site chrome common to all pages: top nav reads "Unlock AI / Open Skills · Open Engine · Guides · Open Skills · Benchmarks · Image Arena"; footer reads "Unlock AI by Nate B. Jones". Each page has a generated 2.39:1 masthead image. The runbooks and directory pages have a back-link "← Open Skills overview"; the runbooks page ends with "Back to the Skills directory."

---

## 1. Core concepts and framing (verbatim-critical vocabulary)

- **Skill** = "a compact operating procedure your agent loads on demand: when to use a method, which tools it calls, what standards matter, and what proof it owes you before it says done." Skills are personal — they "carry your taste, requirements, dependencies, and hard-won decisions." "A good skill turns a one-off good session into repeatable behavior."
- **Runbook** = "A skill makes one kind of agent behavior reliable. A runbook makes a whole workflow reliable. It names the chain, the handoffs, and the point where a human still needs to approve, choose, or judge."
- Slogan: "The primitive is the unit. The runbook is the production line you build from those units."
- Counts stated on the pages: **40 skills, 8 categories, 10 runbooks** (overview page: "40 skills8 categories10 runbooks"; directory page: "40 skills8 categories"; runbooks page: "10 runbooks").
- Three numbered value props on the overview page:
  - 01 "Stop re-explaining the work." — The skill remembers the procedure: inputs, tools, defaults, boundaries, and verification.
  - 02 "Make taste operational." — "Taste is not a vibe when it is written down as decisions." Voice rules, visual standards, QA bar, publishing habits become defaults.
  - 03 "Build small, then compose." — One primitive per job (transcription / voice / publish); runbooks chain them "without turning every skill into a giant unreadable manual."
- Entry guidance ("Start from the failure you keep repeating."): "If the agent is close but unreliable, build a skill. If a workflow already uses several skills, study the runbooks. You can copy the prompts, adapt the primitives, or steal only the architecture."
- SKILLS vs RUNBOOKS routing: use the directory "when you know the kind of capability you want to make repeatable: research, writing, publishing, media, QA, or agent operations." Use runbooks "when the real value is the chain: multiple skills handing work forward until a voice memo becomes a page, a release becomes a briefing, or a rough recording becomes a finished asset."
- Runbook page's three structural pillars (section headers with labels):
  - **Chain** — "Named skills, in order." "The chain tells the agent what to load first, what each stage produces, and where the next skill picks up the work."
  - **Handoff** — "Humans keep the judgment." "Runbooks should move mechanical work forward, but approvals, taste calls, secrets, accounts, and final judgment still belong to the person running the system."
  - **Payoff** — "The result is concrete." "A good runbook ends in an artifact you can inspect: a page, a briefing, a timeline, a verified deploy, or a better operating map."
- Recombination principle: "Same primitives, recombined into useful outcomes." — "You can swap, refine, and reuse the same primitives across different workflows instead of rebuilding the whole operation every time something changes."
- Runbooks-page framing sentence: "This is where Open Skills starts to look less like a library and more like leverage: a voice memo becomes a page, a release becomes a briefing, a rough recording becomes a finished video, and the discoveries from one run make the next run faster."

---

## 2. The 10 runbooks (exact names, chains in order, mechanics, payoffs)

Naming convention: "Runbook NN · Title" (middle dot separator). Chains are written with "→" between skill names. Payoff line convention: "The payoff: …".

### Runbook 01 · Talk to Published
**Chain (in order):** Media Transcription → Brain Dump Processor → Personal Voice → HTML Artifact Builder → Personal Site Publisher
**Input:** a voice memo recorded on a walk. **Output:** a published page at a clean URL with a proper link preview.
**Stage mechanics:**
1. Media Transcription — turns the memo into clean text.
2. Brain Dump Processor — "separates and evaluates the ideas in it."
3. Human handoff: "you pick the one worth writing" (idea selection is the human judgment point).
4. Personal Voice ("the Voice skill") — "drafts the piece as you'd write it."
5. HTML Artifact Builder — "lays it out."
6. Personal Site Publisher — "ships it to a clean URL with a proper link preview."
**Payoff:** "A voice memo becomes a published page."

### Runbook 02 · Release Day
**Chain:** Current-Information Search → New Release Briefing → Branded Image Prompting → Image Generation Gateway → Personal Site Publisher → Stakeholder Update Email
**Input/trigger:** "Something big ships in your field at 10am and you want an accurate, on-brand briefing live by noon." **Output:** published same-day briefing + notification email.
**Stage mechanics:**
1. Current-Information Search — "gathers primary-source facts with dates (this step is what keeps you from publishing training-data hallucinations)." This is the verification/accuracy anchor of the runbook.
2. New Release Briefing — "packages them into your standard format."
3. Branded Image Prompting + Image Generation Gateway — "the image skills produce a matching branded thumbnail" (two skills: prompt authoring, then generation).
4. Personal Site Publisher — "ships it."
5. Stakeholder Update Email — "tells your list or team it's live."
**Payoff:** "An accurate, on-brand briefing published the same day with speed that never costs correctness."

### Runbook 03 · The Video Production Line
**Chain:** Media Transcription → Radio Edit → B-Roll Pipeline → AI Editing Assistant → Stakeholder Update Email
**Input:** raw talking-head footage. **Output:** "finished video with motion graphics."
**Stage mechanics:**
1. Media Transcription — "produces the timestamped foundation everything else reads."
2. Radio Edit — "fixes the spoken narrative and hands you a paper edit to approve — the editorial decisions happen here, on paper, where they're cheap to change." (Human approval gate: the paper edit.)
3. B-Roll Pipeline — "scouts the approved cut for graphic-worthy moments and generates consistent animated overlays."
4. AI Editing Assistant (referred to as "The NLE Assistant") — "assembles it in your editor."
5. Stakeholder Update Email — "tells your editor or client it's ready for review."
**Payoff:** "A raw video becomes a finished, graphics-laden edit with the editorial work front-loaded and cheap to change."

### Runbook 04 · Ship a Page You Can Trust
**Chain:** Frontend Taste System → Personal Site Publisher → Browser Automation QA → Testing Runbook Creator
**Input:** a page to build/ship. **Output:** a live, instrument-verified page plus a repo-local testing runbook.
**Stage mechanics:**
1. Frontend Taste System — "builds it well."
2. Personal Site Publisher — "takes it live."
3. Browser Automation QA — "verifies the live page with instruments rather than vibes — screenshots across breakpoints, Core Web Vitals, console and network checks." (Explicit verification instrumentation list.)
4. Testing Runbook Creator — "everything QA learned about testing this page lands in the repo's runbook, so the next deploy verifies in minutes." (Knowledge-banking step.)
**Framing:** "The difference between shipping a page and shipping a page you'd bet on."
**Payoff:** "A personal site with a regression-test habit — every page shipped with verified quality."

### Runbook 05 · The Research Engine
**Chain:** Heavy File Ingestion → Current-Information Search → Assumption Checker → Meeting Synthesis → HTML Artifact Builder → Reading Pack Builder
**Input:** "real research questions with messy inputs: a folder of PDFs, some meeting recordings, and a claim you're not sure you believe." **Output:** a styled research artifact plus, when needed, an ordered human reading pack.
**Stage mechanics:**
1. Heavy File Ingestion — "converts the heavy sources into clean artifacts first (this ordering is the whole trick — analysis over converted text is faster, cheaper, and reusable)." Ordering rule: ingest before analyzing.
2. Current-Information Search — "fills the gaps with current information."
3. Assumption Checker — "runs adversarially against the emerging conclusions — a separate skill with a skeptic's posture, not the same conversation grading its own homework." (Adversarial verification by a separated context.)
4. Meeting Synthesis — (handles the meeting-recording inputs; listed in chain between Assumption Checker and artifact output).
5. HTML Artifact Builder — "the output ships as a styled artifact."
6. Reading Pack Builder — "when the material needs human review, the Reading Pack presents it in order."
**Payoff:** "Research with a chain of custody: every claim traceable to an artifact, every conclusion stress-tested."

### Runbook 06 · Delegate and Verify
**Chain:** Session Operating Map → Goal Prompt Generator → Visible Delegation → Self-Authored PR Merge → Stakeholder Update Email
**Input:** engineering tasks to run in parallel lanes. **Output:** merged, verified PRs with stakeholders informed.
**Stage mechanics:**
1. Session Operating Map — "records what each lane owns, so no session needs you to explain the project."
2. Goal Prompt Generator — "packages a task with a definition of done and verification gates."
3. Visible Delegation — "runs it in a watchable session; when the delegate finishes, its work is verified against the gates it was given — the goal prompt is also the acceptance test." (Verification principle: acceptance criteria are authored up front in the goal prompt.)
4. Self-Authored PR Merge ("The PR Merge skill") — "reviews and lands it."
5. Stakeholder Update Email — "closes the loop with whoever's waiting."
**Framing:** "How one person runs parallel engineering lanes without becoming the bottleneck."
**Payoff:** "Parallel engineering lanes with you only touching the two decisions that need you: what 'done' means, and whether the diff is good."

### Runbook 07 · The Flywheel
**Chain:** Session-to-Skill Extractor → Testing Runbook Creator → Page Testing Memory → Session Operating Map
**Nature:** explicitly "different: it's not a pipeline you run, it's a posture that runs under every other runbook." (A meta/background runbook.)
**Stage mechanics:**
1. Session-to-Skill Extractor — "watches your sessions for patterns worth keeping and drafts new skills from them."
2. Testing Runbook Creator — "banks every testing discovery in the repo it belongs to." (Repo-local storage of testing knowledge.)
3. Page Testing Memory — "keeps the global/local boundary clean as both libraries grow." (Boundary-management between global skill library and repo-local runbooks.)
4. Session Operating Map — "preserves coordination state across sessions."
**Payoff:** "No useful discovery dies in chat — the mechanism by which a skill library compounds."

### Runbook 08 · Claim Appeal Packet
**Chain:** PDF / Document Ingestion → Document Chunking and Tagging → Case Data Normalization → SQLite Case Store → Deterministic Retrieval Map → Citation Guard → Packet Export → Human Gate
**Input:** a denied insurance claim: plan documents, the denial letter, the EOB (Explanation of Benefits). **Output:** "an editable, cited appeal packet a human can review and send" (a review folder).
**Stage mechanics:**
1. PDF / Document Ingestion — "converts plan docs and the denial into citeable artifacts."
2. Document Chunking and Tagging — "makes the plan sections addressable."
3. Case Data Normalization — "extracts dates, denial reason, claim lines, and deadline, then reconciles each claim's fields across the denial letter and EOB: codes, dates, and amounts; mismatches become named review questions in the packet." (Reconciliation rule: mismatch → named review question, not silent resolution.)
4. SQLite Case Store — "keeps the evidence queryable."
5. Deterministic Retrieval Map — "maps denial type to plan language before the agent drafts." (Retrieval is deterministic and precedes drafting.)
6. Citation Guard — "rejects unsupported claims and blocks export until every failure is fixed or converted to a named review question." (Hard gate: export blocked while any citation failure is unresolved.)
7. Packet Export — "produces the review folder."
8. Human Gate — "stops before filing or sending." (Terminal human boundary.)
**Payoff:** "A denial letter becomes an editable, cited appeal packet a human can review and send."

### Runbook 09 · Tax Prep Packet
**Chain:** PDF / Document Ingestion → Document Chunking and Tagging → Case Data Normalization → SQLite Case Store → Open Brain Case Store (optional · OB1 path) → Deterministic Retrieval Map → Citation Guard → Packet Export → Human Gate
**Input:** "a pile of tax documents" — forms, receipts, CSVs. **Output:** "a CPA-ready review packet": summaries, ledgers, questions, and PDF.
**Stage mechanics:**
1. PDF / Document Ingestion — "converts forms, receipts, and CSVs."
2. Document Chunking and Tagging — "makes the form boxes, receipt lines, and statement rows addressable evidence."
3. Case Data Normalization — "merges receipt and bank evidence so one transaction is one ledger row, cross-checks every W-2 and 1099 against deposits, and turns unmatched payers into named missing-document items." (Dedup rule: one transaction = one ledger row; cross-check rule: income forms vs deposits; gap rule: unmatched payer → named missing-document item.)
4. SQLite Case Store — "the beginner store."
5. Open Brain Case Store — "the OB1 path for people with durable context already running." Marked "(optional · OB1 path)" in the chain. (Two-tier storage: SQLite = beginner; Open Brain = advanced/durable-context path.)
6. Deterministic Retrieval Map — "maps income, expense, and missing-document types to the right review rules."
7. Citation Guard — "keeps tax-rule claims grounded and blocks export while any claim fails."
8. Packet Export — "creates summaries, ledgers, questions, and PDF."
9. Human Gate — "stops before filing."
**Payoff:** "A messy tax folder becomes a structured prep packet with evidence, questions, and clean handoff artifacts."

### Runbook 10 · Email Follow-Up Packet
**Chain:** PDF / Document Ingestion → Document Chunking and Tagging → Case Data Normalization → SQLite Case Store → Open Brain Case Store (optional · OB1 path) → Deterministic Retrieval Map → Citation Guard → Packet Export → Human Gate
(Same 9-stage chain as Runbook 09, retargeted at email.)
**Input:** "a mailbox export" — an mbox export, Sent folder included. **Output:** "a commitments ledger and a folder of cited drafts."
**Stage mechanics:**
1. PDF / Document Ingestion — "converts an mbox export, Sent folder included, into citeable messages anchored by message-id." (Evidence anchor: message-id.)
2. Document Chunking and Tagging — "strips quoted history, signatures, and disclaimers so evidence anchors to the message where a sentence first appeared." (Attribution rule: anchor to first occurrence.)
3. Case Data Normalization — "reconstructs threads from headers, resolves sender identities, and extracts commitments and waiting-on rows with owners and due dates, then reconciles restated promises into single rows and closes any loop the Sent mail already answered." (Data model: commitments rows + waiting-on rows, each with owner and due date; dedup: restated promise → single row; closure: Sent-mail answers close loops.)
4. SQLite Case Store / Open Brain Case Store (optional · OB1 path) — evidence storage, same two-tier model as Runbook 09.
5. Deterministic Retrieval Map — "maps thread state, dropped commitment, waiting-on-them, or dispute, to the evidence a draft needs." (Thread-state taxonomy: dropped commitment / waiting-on-them / dispute.)
6. Citation Guard — "rejects any draft claim that lacks an anchoring message."
7. Packet Export — "produces drafts with full headers, every one marked pending." (Draft status convention: pending.)
8. Human Gate — "the send boundary: fixture runs hold no mail credentials, the live loop ingests through a read-and-draft connector with no send verb, and an ignored draft means no." (Three-layer send-safety: (a) fixture/test runs have no mail credentials at all; (b) the live connector is read-and-draft only — the send verb does not exist in the toolset; (c) silence is refusal — "an ignored draft means no.")
**Payoff:** "A neglected inbox becomes an urgency-ordered ledger and ready-to-send cited drafts, and nothing sends itself."

### Cross-runbook observations
- Runbooks 08–10 share an identical "document-grounded case packet" architecture: Ingestion → Chunking/Tagging → Normalization → Case Store → (optional Open Brain) → Deterministic Retrieval Map → Citation Guard → Packet Export → Human Gate. Only the domain rules inside Normalization and the Retrieval Map mappings change per domain. Runbook 08 lists SQLite only; 09 and 10 add the optional Open Brain path.
- Citation Guard is the universal verification gate in 08–10: it blocks Packet Export while any claim fails; failures must be fixed or converted to "named review questions."
- Human Gate is always terminal and always pre-action (before filing/sending); it never drafts or exports — it stops.
- Skills reused across runbooks: Media Transcription (01, 03); Personal Site Publisher (01, 02, 04); Stakeholder Update Email (02, 03, 06); HTML Artifact Builder (01, 05); Current-Information Search (02, 05); Testing Runbook Creator (04, 07); Session Operating Map (06, 07); the entire 08–10 shared stack.
- Human judgment points named per runbook: pick the idea (01); n/a explicit for 02 beyond list ownership; approve the paper edit (03); implicit QA review (04); reading pack review (05); define "done" + judge the diff (06); n/a for 07 (background posture); Human Gate (08, 09, 10).

---

## 3. Skills directory organization (8 categories, 40 skills)

Directory page usage doctrine (verbatim-critical):
- "Do not browse this like an app store. Start with the repeated failure: the agent keeps forgetting a tool shape, writing in the wrong voice, losing verification knowledge, mishandling media, or needing the same setup speech every session."
- "Then choose the smallest primitive that fixes that failure. Each entry tells you what the skill does, why it is worth building, what it depends on, and gives your agent a setup prompt to adapt the primitive to your stack and taste." (Per-skill entry schema: what it does / why worth building / dependencies / adaptable setup prompt.)
- "The useful skill is the one that removes a repeated explanation."
- Section header: "Choose by bottleneck, not by novelty." — "If you cannot name the workflow where a skill will run next week, wait. Open Skills works because each primitive is attached to a real recurring job." "The point is not to install everything. The point is to find the drawer that matches the work you keep doing twice."

The 8 categories with skill counts and exact descriptions (counts sum to 40):

| # | Category | Skills | Description (verbatim) |
|---|----------|--------|------------------------|
| 1 | Core Infrastructure | 5 | "The foundation layer: image generation, current search, transcription, ingestion, and artifacts. Build these when other workflows keep re-solving the same tool and packaging problems." |
| 2 | Context Engineering | 9 | "Skills for turning scattered, uncited paperwork into a structured case file: ingest documents, normalize records, store evidence, retrieve deterministically, validate citations, and export a human-reviewed packet." |
| 3 | Research & Thinking | 5 | "Skills for turning messy input into thinking you can review: voice notes, meetings, document piles, weekly noise, and assumptions that need to be challenged before they become plans." |
| 4 | Writing, Voice & Content | 4 | "Skills that make agent writing specific: a real voice, a real audience, current facts, branded images, and publishing formats that keep content from sliding back into generic AI prose." |
| 5 | Web Publishing & Frontend | 4 | "Skills for turning agent output into public, inspectable web work: better frontend taste, clean publishing, share previews, comparison pages, and verification before anything is called shipped." |
| 6 | Video & Media Production | 3 | "Skills for the expensive parts of media work: transcript-first editing, motion graphics, timeline assembly, and NLE control. Build these after the simpler media primitives are working." |
| 7 | Testing & Quality | 3 | "Skills that make agent-built work trustworthy: repeatable QA, browser evidence, repo-local testing memory, and the habit of leaving verification knowledge where the next session can use it." |
| 8 | Agent Operations | 7 | "Meta-skills for running agents without becoming the bottleneck: goal prompts, visible delegation, operating maps, merge discipline, stakeholder updates, and skill-library compounding." |

Sum check: 5+9+5+4+4+3+3+7 = 40. ✓

The full per-skill list is NOT present in these source files (the directory page text captured here contains only category-level summaries). However, skill names appearing in the runbook chains can be tentatively mapped to categories from the category descriptions:
- Core Infrastructure (5, described as image generation / current search / transcription / ingestion / artifacts): likely Image Generation Gateway, Current-Information Search, Media Transcription, Heavy File Ingestion, HTML Artifact Builder.
- Context Engineering (9): likely PDF / Document Ingestion, Document Chunking and Tagging, Case Data Normalization, SQLite Case Store, Open Brain Case Store, Deterministic Retrieval Map, Citation Guard, Packet Export, Human Gate — exactly 9 names appear in runbooks 08–10, matching the category count.
- Research & Thinking (5): likely Brain Dump Processor, Meeting Synthesis, Assumption Checker, Reading Pack Builder, + 1 more ("weekly noise" suggests a weekly-review skill not named in the runbooks).
- Writing, Voice & Content (4): likely Personal Voice, New Release Briefing, Branded Image Prompting, + 1 more (audience-related per description).
- Web Publishing & Frontend (4): likely Frontend Taste System, Personal Site Publisher, + 2 more ("share previews, comparison pages").
- Video & Media Production (3): likely Radio Edit, B-Roll Pipeline, AI Editing Assistant (aka NLE Assistant).
- Testing & Quality (3): likely Browser Automation QA, Testing Runbook Creator, Page Testing Memory.
- Agent Operations (7): likely Goal Prompt Generator, Visible Delegation, Session Operating Map, Self-Authored PR Merge, Stakeholder Update Email, Session-to-Skill Extractor, + 1 more.
Distinct skill names appearing across all 10 runbook chains: 31 (mapping above is inference — see OPEN QUESTIONS).

---

## 4. Guides index (Living guides page)

Page title: "Living guides | Unlock AI". Organized into 3 sections with per-section guide counts. Each entry has a title, a one-line description, and an "Open guide" link.

### Agents (1 guide)
1. **The Agent Maintenance Loop** — "A repeatable loop for inspecting an agent after launch across job, diet, memory, tools, reach, proof, and value, then deciding whether to keep, change, pause, or retire it." (Seven inspection dimensions: job, diet, memory, tools, reach, proof, value. Four dispositions: keep, change, pause, retire.)

### Codex (4 guides)
2. **The Ultimate Guide to Codex** — "Install OpenAI's Codex app, set up real projects, and run an AI-first workflow where every move ships with a copy-paste prompt."
3. **Codex side panel deep-dive** — "Files, Side chat, Review, Terminal, and Browser: the panel that turns Codex from a chat box into a workbench."
4. **Codex browser & annotations** — "Click any element, attach a note or screenshot, and steer Codex from inside the page you are building."
5. **Codex threading & child threads** — "Threads, child threads, steering versus queueing, and the workspace habits that keep long-running agent work sane."

### General (5 guides)
6. **Open Stack** — "A practical routing guide for Open Skills, Open Brain, and Open Engine: diagnose the bottleneck, choose where to start, and personalize the stack." (Names the three-product stack: Open Skills / Open Brain / Open Engine.)
7. **Build your own token-burn dashboard** — "Track where your tokens go across Codex, Claude, and ChatGPT, then use the burn rate as a fluency signal."
8. **Build a Healthcare Claim Appeals Agent** — "Assemble Open Skills primitives into an agent-operated starter that turns public plan documents and synthetic denials into a cited appeal packet." (Companion guide to Runbook 08; note: uses public plan documents and SYNTHETIC denials for the starter.)
9. **Build a Tax Prep Organizer Agent** — "Use the same document-grounded primitives to turn synthetic taxpayer records and real IRS sources into a structured tax-prep packet." (Companion to Runbook 09; synthetic taxpayer records + real IRS sources.)
10. **Build an Email Follow-Up Agent** — "Point the same document-grounded primitives at your inbox: a live MCP ingest loop that reconstructs threads, keeps a commitments ledger, and drafts cited follow-ups that stop at the send boundary." (Companion to Runbook 10; live ingest is via MCP; confirms the "send boundary" concept.)

Total: 10 guides across 3 sections (Agents 1, Codex 4, General 5).

---

## 5. Rebuild notes (operational summary for reconstructing the system)

1. Build 40 small single-purpose skills in 8 category "drawers"; each skill entry must document: what it does, why it's worth building, what it depends on, and a setup prompt the user adapts to their own stack/taste.
2. Compose skills into named runbooks with explicit ordered chains ("what to load first, what each stage produces, where the next skill picks up"), explicit human handoff points, and a concrete inspectable payoff artifact.
3. Verification patterns embedded in the runbooks:
   - Search-before-write for currency (Runbook 02) — primary-source facts with dates to avoid training-data hallucination.
   - Instrument-based browser QA (Runbook 04) — screenshots across breakpoints, Core Web Vitals, console and network checks; findings banked into a repo-local testing runbook.
   - Adversarial checking by a separate skill/context (Runbook 05 Assumption Checker) — "not the same conversation grading its own homework."
   - Goal-prompt-as-acceptance-test (Runbook 06) — definition of done + verification gates authored before delegation; delegate output verified against those gates.
   - Citation Guard as export blocker (Runbooks 08–10) — unsupported claims are rejected; export blocked until each failure is fixed or converted to a named review question.
   - Human Gate as terminal pre-action stop (08–10) — nothing files or sends without a human.
4. Safety architecture for the email runbook (10): fixture runs carry no mail credentials; live path uses a read-and-draft connector with no send verb; drafts are exported "marked pending"; ignored draft = no.
5. Compounding loop (Runbook 07) runs beneath everything: extract session patterns into new skills, bank testing knowledge repo-locally, keep the global/local memory boundary clean, persist coordination state in an operating map.
6. Storage tiers for case-file runbooks: SQLite Case Store (beginner default) vs Open Brain Case Store (optional "OB1 path" for users with durable context running).
7. The three General "Build a ... Agent" guides are the hands-on companions to Runbooks 08/09/10, using synthetic data for safe starters.

---

## OPEN QUESTIONS

1. The full list of 40 individual skill names is not present in the captured directory page — only the 8 category names, counts, and descriptions. The category-to-skill mapping in section 3 is inferred from the 31 distinct skill names in the runbook chains plus category descriptions; the remaining ~9 skills (and any naming differences between runbook labels and directory entries, e.g. "AI Editing Assistant" vs "NLE Assistant", "Heavy File Ingestion" vs "PDF / Document Ingestion" — possibly the same or different skills) are unconfirmed.
2. Per-skill setup prompts, dependency lists, and "what proof it owes you" specifications are referenced as existing on individual skill entries but are not included in these sources — no SQL, schemas, file formats, or exact commands appear anywhere in the four captured pages.
3. Whether "Open Brain Case Store (optional · OB1 path)" is one of the 40 skills or an external Open Brain product integration is ambiguous; "OB1" itself is undefined in these sources (presumably an Open Brain tier/version).
4. Runbook 08 omits the Open Brain optional stage that 09 and 10 include — unclear if intentional (healthcare data sensitivity?) or an editorial inconsistency.
5. "Human Gate" is listed as a chain stage in 08–10; whether it is a real loadable skill or a convention/placeholder for the human boundary is not stated (its presence would be needed to make the Context Engineering count of 9 work out, which suggests it IS a skill).
6. The runbooks page mentions each runbook "gives your agent a setup prompt" only on the skills directory page — whether runbooks themselves ship copy-paste prompts is not shown in the captured text.
7. Guide URLs behind each "Open guide" link were not captured.
