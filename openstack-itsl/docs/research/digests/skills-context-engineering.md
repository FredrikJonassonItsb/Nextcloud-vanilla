# Digest: Open Skills — Context Engineering (Unlock AI / Nate B. Jones)

Source: `natebjones/text/open-skills__context-engineering.md` (scraped from https://unlock-ai.natebjones.com/open-skills/context-engineering)

## Category overview

- **Category name:** Context Engineering (one category within the "Open Skills" directory of "Unlock AI by Nate B. Jones"; sibling site sections: Open Engine, Guides, Open Skills, Benchmarks, Image Arena).
- **Category purpose (verbatim):** "Skills for turning scattered, uncited paperwork into a structured case file: ingest documents, normalize records, store evidence, retrieve deterministically, validate citations, and export a human-reviewed packet."
- **Skill count:** 9 skills.
- **How the page says to use it:** Read like a "triage menu." Find the skill that removes a repeated explanation, a fragile manual step, or a quality bar the agent keeps missing; then copy the setup prompt and let your agent adapt it to your own tools/files/accounts/standards. "Install the primitive only when you can name the workflow it will improve."
- **Starting order:** If building the lane from scratch, start with **PDF / Document Ingestion**; otherwise jump straight to the skill matching today's bottleneck. "The prompt is the starting point; the installed skill should reflect your real workflow."
- **Page footer link:** back to Skills directory or "continue into runbook compositions" (implies a related "runbook compositions" page).

## Pipeline / lane order (the 9 skills, in listed order)

1. PDF / Document Ingestion
2. Document Chunking and Tagging
3. Case Data Normalization
4. SQLite Case Store
5. Open Brain Case Store
6. Deterministic Retrieval Map
7. Citation Guard
8. Packet Export
9. Human Gate

The lane forms a chain-of-custody pipeline: ingest → chunk/tag → normalize → store (SQLite by default, Open Brain as upgrade) → retrieve deterministically → validate citations → export packet → stop at human gate.

Each skill entry on the page has: a description, a "Why build it" rationale, a "What you need" prerequisites list, and a copyable XML-shaped setup prompt (`<prompt>` with `<task>`, `<job>`, optional `<schema>`, `<requirements>`, `<verification>`). The `<task>` line always reads: `Create a new skill called "<kebab-case-name>".`

---

## Skill 1: PDF / Document Ingestion (skill name: `pdf-document-ingestion`)

**Description:** Converts PDFs, scans, forms, CSVs, and loose source files into lightweight markdown or tabular artifacts with stable source anchors. Ingestion is treated as a **chain-of-custody** step: every paragraph, row, or form field needs a path back to the original file, page, heading, row, or box, using **one canonical anchor scheme** that downstream citations reuse verbatim.

**Why build it:** Most bureaucratic workflows fail before reasoning starts because evidence is trapped in documents the agent cannot cite cleanly. Ingestion makes the file system usable: original stays intact, converted text is greppable, later drafts can point back to source instead of hand-waving.

**What you need:** A local folder of source documents · PDF/text conversion tools available to the agent · A chosen output folder convention such as `work/<case-id>/ingested`.

**Setup prompt — job:** "Turn heavy or messy documents into lightweight artifacts with stable source anchors before analysis begins."

**Requirements (all, near-verbatim):**
1. Preserve original files unchanged.
2. Convert PDFs and forms into markdown or structured text.
3. Attach source anchors for page, heading path, row, box, or file location.
4. Choose **one canonical anchor convention per case**: raw source coordinates such as PDF page and region, CSV line number, or form box label.
5. Embed that identical anchor scheme in the ingested markdown and downstream citations; "two numbering schemes in one artifact is a defect because a citation must resolve without a translation table."
6. Write an index listing each converted artifact, source path, document type, and conversion confidence.
7. Never analyze the original heavy file directly when an ingested artifact exists.

**Verification:** Run the skill on one sample document and prove that a converted paragraph can be traced back to its source anchor.

---

## Skill 2: Document Chunking and Tagging (skill name: `document-chunking-tagging`)

**Description:** Splits ingested documents into addressable sections; tags each chunk with document type, normalized section label, domain relevance, source anchor, effective date, and content. Favors **structure-first tagging over vector similarity** when source documents have known sections.

**Why build it:** "A good chunk is reusable by any model. A bad chunk is a landmine." Chunking/tagging is the seam letting healthcare appeals, tax prep, contract review, and grant packets share the same retrieval discipline while keeping domain labels separate.

**What you need:** Ingested markdown/text artifacts · A domain-specific section-label map · A target store such as SQLite or Open Brain.

**Setup prompt — job:** "Split ingested documents into addressable chunks and apply normalized metadata."

**Chunk schema (exact field list, in order):**
- `chunk_id`
- `case_id or plan_id`
- `document_type`
- `section_label`
- `domain_tags`
- `source_anchor`
- `granularity`
- `effective_date`
- `content`

**Requirements:**
1. Use headings, form boxes, table rows, and known document structure **before** semantic guessing.
2. Keep chunks small enough to retrieve directly, but large enough to preserve the clause or table meaning.
3. For long documents, use **two-tier granularity**: page-level chunks for whole-document citability, plus clause-level chunks for sections named by the retrieval map; record the tier in `granularity`.
4. **Exclude table of contents and front matter pages from evidence chunks**; a chunk cited as evidence must contain operative language, not headings or dotted page listings.
5. Flag unclassified sections for review instead of inventing labels.

**Verification:** Show a query that pulls one chunk by `section_label` and returns its `source_anchor`. Print the text of every chunk a draft cites and read it; "a TOC line offered as coverage evidence means the chunking failed."

---

## Skill 3: Case Data Normalization (skill name: `case-data-normalization`)

**Description:** Turns messy facts into a normalized **case ledger**: dates, parties, amounts, codes, categories, document links, confidence, and review status. Separates extracted facts from inferred classifications so a human can see which fields came from the page vs. which need judgment.

**Why build it:** "Scattered facts are how institutions keep the advantage." Once facts become a ledger, the agent can compare, query, audit, and draft against them. "Normalization is the move from a pile of paperwork to a case file."

**What you need:** A target case type · Source artifacts with anchors · A schema for records and review flags.

**Setup prompt — job:** "Extract messy document facts into a structured, reviewable ledger."

**Requirements:**
1. Define the minimum schema for the current domain **before** extraction.
2. Store source-backed facts **separately** from agent classifications.
3. Track `confidence` and `review_status` for every normalized record.
4. Preserve evidence links to source chunks, pages, rows, boxes, or files.
5. Run **field-level sanity checks** against the source: names must look like names ("a street address in a name field" is the canonical failure); dates must parse to real absolute dates with **days remaining computed for deadlines**; amounts must reconcile against line-item sums.
6. When two documents state the same fact, compare **field by field**: denial letter CPT code against EOB row, receipt against bank line, and similar duplicate evidence.
7. Represent **one real-world event as one record** citing all supporting sources; mismatches become `needs_review` unresolved questions naming which source governs the tracked value.
8. Records failing sanity checks get `review_status` = `needs_review` with a **concrete unresolved question**, never a default `pending` status.
9. Produce `unresolved_questions` when required fields are missing or contradictory.

**Verification:** Normalize one sample case and show which fields came directly from source evidence.

---

## Skill 4: SQLite Case Store (skill name: `sqlite-case-store`)

**Description:** Creates a local SQLite database for source documents, chunks, normalized records, retrieval mappings, run outputs, and validation results. It is the **default starter backend** — local, inspectable, portable, "enough for one person's case file."

**Why build it:** "A case file needs durable structure, not another chat transcript." SQLite gives a real query surface "without creating infrastructure theater." Inspectable with standard tools, copyable with the repo, swappable later "when the system earns more complexity."

**What you need:** SQLite available locally · A schema migration folder · A convention for case IDs and generated work folders.

**Setup prompt — job:** "Stand up a local SQLite store for document-grounded case workflows."

**Requirements:**
1. Create tables for: `source_documents`, `chunks`, `normalized_records`, `retrieval_mappings`, `run_outputs`, `validation_results` (exact six table names).
2. Keep original document paths and source anchors in the database.
3. Provide scripts for: `migrate`, `inspect`, `query-by-section`, `export-case`.
4. Do not store secrets or real private data in committed fixtures.

**Verification:** Run a migration, insert sample chunks, and demonstrate a `WHERE section_label` query.

---

## Skill 5: Open Brain Case Store (skill name: `open-brain-case-store`) — the memory-layer bridge

**Description:** Adapts the **same case-file schema** to **Open Brain** for **OB1** users who want normalized records, chunks, and source anchors inside their **durable personal context layer** instead of a local SQLite file.

**Why build it / relation of skills to the memory layer:**
- SQLite is the **right starter path**; Open Brain is the **upgrade path** for people already running OB1.
- With Open Brain, "the same primitives become part of a larger memory and retrieval system instead of staying trapped in one folder."
- I.e. Open Brain (OB1) is Nate Jones's durable personal memory/context system; this skill maps the case-store data model onto it so case evidence lives in the memory layer alongside everything else, rather than being an isolated per-folder database. The relationship is: skills define workflow logic and schema; the store (SQLite or Open Brain) is a swappable backend; Open Brain adds persistence-across-projects/memory integration.

**What you need:** An existing Open Brain / OB1 setup · A project or case namespace · A mapping from starter schema fields to Open Brain primitives.

**Setup prompt — job:** "Map document-grounded case workflows onto Open Brain instead of SQLite."

**Requirements:**
1. Start from the **same logical schema** used by the SQLite case store.
2. Define where source documents, chunks, normalized records, retrieval mappings, and run outputs **live in Open Brain**.
3. Preserve source anchors and provenance.
4. Include a **migration note** for moving a local SQLite starter case into Open Brain.
5. **Do not imply Open Brain is required for the beginner path.**

**Verification:** Write one sample case record and show how it would be queried back by `case_id` and `section_label`.

---

## Skill 6: Deterministic Retrieval Map (skill name: `deterministic-retrieval-map`)

**Description:** Builds **explicit lookup tables** mapping a case type to the document sections or record categories that matter. Retrieves by known structure first, then lets the agent reason over a small, cited packet.

**Why build it:** "Vector search is not the first move when the documents already have known structure." Deterministic retrieval "keeps v1 boring in the best way": denial type → plan sections, expense type → tax categories, clause type → contract sections. "The strong model gets the right evidence instead of a haystack."

**What you need:** Chunked/tagged documents · A domain mapping table · A query path for the selected store.

**Setup prompt — job:** "Retrieve evidence by explicit case-type to section/category mappings."

**Requirements:**
1. Define the domain case types and the section labels each type requires.
2. Implement retrieval with **ordinary queries against tags and labels** (not embeddings).
3. Return a **compact evidence packet** with chunk IDs, source anchors, and content.
4. Flag missing expected sections **before drafting starts**.
5. Use semantic search **only as a later fallback, never as the v1 foundation**.

**Verification:** Run all case types through the mapping and show the retrieved chunk IDs.

---

## Skill 7: Citation Guard (skill name: `citation-guard`)

**Description:** Checks generated drafts for substantive claims and verifies each cites evidence that **actually supports it** — the cited chunk or record must (a) exist in the case store AND (b) match the claimed amount, date, or language. A citation that resolves but does not support its claim **fails**. Verdicts are **three-state: `pass`, `needs_review`, `fail`**.

**Why build it:** "The whole promise of context ownership collapses if the final artifact invents around gaps. Citation Guard is the trust layer: claims either point to evidence, ask for confirmation, or get cut."

**What you need:** A generated draft · A citation map or source registry · A validation command that can fail the run.

**Setup prompt — job:** "Validate that substantive draft claims are grounded in known evidence."

**Requirements:**
1. Define what counts as a **substantive claim** for the current domain: amounts, dates, deadlines, coverage or rule statements, and counts.
2. Prescribe **ONE machine-checkable citation syntax** with a worked example — exact example tokens from the prompt: a claim line ending with `[record:case-42:expense:adobe_feb]` or `[chunk:eoc-017]`.
3. Resolve every citation against the case store; a citation that does not resolve is a **fail**.
4. **Verify support, not just existence**: compare the claimed amount, date, or quoted language against the cited record's stored values; a resolvable citation that does not support the claim is a fail.
5. Use three verdicts — `pass`, `needs_review`, `fail` — and write a **validation report listing every claim under its verdict**. `needs_review` is only for claims whose underlying record is itself flagged for review — **not** a soft pass for unsupported claims.
6. Save **both sides of the verification test** as artifacts, and make sure the seeded fabricated sentence itself appears as the failing item in the failure report; "a report that fails other claims does not prove the guard works."
7. **Exit nonzero when any claim fails**, so the guard can gate downstream steps (CI-style gating).
8. Allow general disclaimers and process labels without citations **only when they are boilerplate from the runbook**.

**Verification (two-sided test):**
- Run the guard on a fully-cited draft → prove it **PASSES (exit 0)**.
- Run it on a copy with **one seeded fabricated-but-well-formed citation** (a plausible record ID that does not exist) → prove it **FAILS (nonzero exit)**.
- Save both reports as evidence.

---

## Skill 8: Packet Export (skill name: `packet-export`)

**Description:** Packages reviewed outputs into an editable folder plus PDF: draft letter or summary, citation map, checklist, supporting documents list, source manifest, and any unresolved questions. **Editable markdown is the source of truth; PDF is the delivery artifact.**

**Why build it:** "The work is not done when the agent writes a decent draft." The human needs a packet they can inspect, edit, send to a professional, or file manually. "Packet export turns agent output into something operational."

**What you need:** Validated markdown outputs · A source manifest · A local PDF export path.

**Setup prompt — job:** "Turn validated case outputs into an editable packet folder and PDF."

**Requirements:**
1. **Refuse to export while the citation guard reports any failing claim**; a packet ships only when every claim is `pass` or `needs_review`, and the guard's verdict summary appears in the packet README.
2. Keep markdown, JSON, and CSV outputs **editable** in the packet folder.
3. Create a **source manifest** with original files and citation anchors.
4. Export through a **concrete PDF path**: markdown → HTML, then **headless Chrome with `--print-to-pdf`**; "markdown needs the HTML intermediate."
5. Operational warning: headless Chrome can keep the process alive **about 2 minutes** after the PDF is written; **verify the file appears on disk instead of trusting exit status**, and use `--disable-background-networking` or a **poll-then-kill wrapper**.
6. Produce **one combined rendered PDF** for handoff while keeping individual drafts editable in the packet folder.
7. **Expected folder shape (exact example):** `packet/`: `draft.md`, `packet.pdf`, `citation-map.json`, `checklist.md`, `unresolved-questions.md`, `sources/`.
8. Include unresolved questions and missing documents **instead of hiding them**.
9. **Never transmit, submit, sign, file, or send the packet.**

**Verification:** Export a sample packet, open the PDF, confirm page count is sane ("single or low double digits for one case"), and confirm tables render with no raw markdown artifacts.

---

## Skill 9: Human Gate (skill name: `human-gate`)

**Description:** Defines the **stop line** for high-stakes workflows: the agent may organize, draft, validate, and export, but a **human** reviews, signs, sends, files, or submits. The skill "writes this boundary into the workflow instead of relying on vibes."

**Why build it:** Healthcare, taxes, legal, finance, and identity workflows all have a point where agency matters. "The human gate is not a missing automation feature. It is the product boundary" keeping the person in charge and preventing irreversible agent action with sensitive data.

**What you need:** A list of allowed actions · A list of forbidden actions · A review checklist for the handoff.

**Setup prompt — job:** "Enforce the review-and-submit boundary in high-stakes agent workflows."

**Requirements:**
1. **Allowed agent actions (exact list):** organize, draft, validate, summarize, export.
2. **Forbidden agent actions (exact list):** sign, send, file, submit, authorize, pay, or transmit sensitive data.
3. Add an **explicit review checklist to every packet**.
4. **Stop the workflow at export** unless the human starts a **separate approved sending workflow**.
5. Use domain-specific disclaimers **without burying the actual next step**.

**Verification:** Run the skill against a sample packet and show where the workflow stops.

---

## Cross-cutting conventions and design principles (extracted)

- **Setup-prompt format:** XML-ish blocks: `<prompt><task>Create a new skill called "NAME".</task><job>…</job>[<schema>…</schema>]<requirements><requirement>…</requirement>…</requirements><verification>…</verification></prompt>`. Prompts are templates the user's agent adapts; not turnkey installs.
- **Canonical anchor scheme:** chosen once per case at ingestion; reused verbatim in chunks, records, and citations. Anchor forms: PDF page+region, CSV line number, form box label, heading path, row, box, file location.
- **Shared status vocabulary:** `needs_review` (records and claims), `pass` / `needs_review` / `fail` (citation verdicts), `review_status`, `confidence`, `unresolved_questions`.
- **Citation token syntax examples (verbatim):** `[record:case-42:expense:adobe_feb]`, `[chunk:eoc-017]`.
- **Store abstraction:** the same logical schema runs on SQLite (starter/default) or Open Brain (memory-layer upgrade for OB1 users). Six logical entities: source documents, chunks, normalized records, retrieval mappings, run outputs, validation results.
- **Retrieval philosophy:** deterministic/structural first, semantic/vector only as later fallback — never the v1 foundation.
- **Gating chain:** Citation Guard exit code gates Packet Export; Packet Export never transmits; Human Gate stops everything at export pending human review + a separate approved sending workflow.
- **Verification culture:** every skill ships with a concrete verification step, several adversarial (seeded fabricated citation must be the failing item; two-sided pass/fail proof; TOC-line-as-evidence = chunking failure).
- **Folder conventions mentioned:** `work/<case-id>/ingested` (ingestion output), `packet/` with the six-item shape above.
- **Target domains named:** healthcare appeals (denial letters, CPT codes, EOB rows, plan sections/EOC), tax prep (receipts, bank lines, expense categories), contract review (clause types), grant packets, identity/finance/legal workflows generally.

## Open Brain / memory layer relationship (summary)

- Open Brain (a.k.a. **OB1** when referring to the running setup) is presented as Nate Jones's durable personal context/memory layer product.
- Within this category it appears only in Skill 5 (Open Brain Case Store): the case-file schema (documents, chunks, normalized records, retrieval mappings, run outputs) can be relocated from local SQLite into Open Brain namespaces so case evidence joins "a larger memory and retrieval system instead of staying trapped in one folder."
- The mapping requires: a project/case namespace, a field-to-primitive mapping from the starter schema to Open Brain primitives, preserved anchors/provenance, and a documented SQLite→Open Brain migration note.
- Explicit positioning rule: Open Brain must **not** be implied as required for the beginner path; SQLite remains the sanctioned starting point.
- The page does not define Open Brain's internal primitives — it assumes the reader already has an "existing Open Brain / OB1 setup."

## OPEN QUESTIONS

1. **Open Brain internals:** The page never specifies what Open Brain's "primitives" are (nodes? memories? documents? collections?) or how querying by `case_id` and `section_label` is actually expressed in OB1 — that mapping is left to the skill builder.
2. **"Runbook compositions":** The footer references continuing "into runbook compositions" — presumably a sibling page composing these skills into end-to-end runbooks — but its content is not in this source.
3. **Skill install mechanics:** "Copy prompt" implies pasting the `<prompt>` block into an agent (likely Claude Code / a skill-creator flow), but the exact install target/format (e.g., SKILL.md layout) is not stated on this page.
4. **`case_id or plan_id`:** The chunk schema lists this as a single alternating field; whether it is one column with two possible semantics or two columns is not specified.
5. **SQLite schema detail:** Table names are given but column definitions, key relationships, and the migration-folder layout are left to the implementer.
6. **Citation Guard "runbook" boilerplate:** Requirement 8 exempts "boilerplate from the runbook" — which runbook (the composition pages?) is not defined here.
