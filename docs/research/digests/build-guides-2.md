# Digest: Nate B. Jones "Unlock AI" vertical agent build guides (batch 2)

Sources digested:
1. `guides__build-a-healthcare-claim-appeals-agent.md` — "Build a Healthcare Claim Appeals Agent" (https://unlock-ai.natebjones.com/guides/build-a-healthcare-claim-appeals-agent)
2. `guides__build-a-tax-prep-organizer-agent.md` — "Build a Tax Prep Organizer Agent" (https://unlock-ai.natebjones.com/guides/build-a-tax-prep-organizer-agent)

Focus of this digest: the BUILD PATTERN — shared structure, guardrails, human approval points, verification gates, and reusable patterns. The two guides are deliberately two instantiations of ONE reusable architecture ("guides assemble reusable Open Skills into a domain-specific workflow"); healthcare is the first vertical runbook, tax explicitly reuses the same skeleton.

---

## 1. The meta-pattern (thesis, verbatim)

Both guides open with the identical thesis sentence:

> "People lose high-friction paperwork fights because their information is scattered, unstructured, uncited, and incomplete. The fix is to own the context: collect the mess, normalize it, ground it in source documents, and produce the next human-reviewed action."

Product philosophy statements (both guides):
- "The workflow should help the person own their context, not outsource judgment to a black box."
- The agent drafts and organizes; the human reviews, edits, and decides what to send/file.
- "Every future guide should improve the same primitive library instead of rebuilding the pipeline from scratch." / "Guides grow together because each one improves the shared primitives beneath it."
- Do NOT position the tax version as "AI filing your taxes"; do NOT position the healthcare version as the agent becoming "an insurer, a lawyer, or a doctor."

Division of labor pattern (explicit "You do / The AI does" boxes):
- Healthcare — You do: "Bring the denial letter, plan docs, and supporting documents into the starter repo." The AI does: "Assemble a cited appeal packet from the reusable Open Skills chain."
- Tax — You do: "Drop synthetic tax forms, receipts, CSVs, and source rules into the starter repo." The AI does: "Normalize the tax-year ledger, flag gaps, draft CPA questions, and export the prep packet."

Badge/tagline trio pattern (top of each guide):
- Healthcare: "Real plan docs — Public sources | Synthetic claims — Private-safe data | Human gate — No sending"
- Tax: "Real IRS rules — Source layer | Synthetic taxpayer — Demo data | CPA-ready — Packet output"

## 2. Shared guide structure (identical section skeleton)

Both guides use the exact same five sections, in order, with a section nav labeled: `Skeleton | Data | Mapping | Guardrails | Prove it`

1. **01 / Shared skeleton** — "The guide is a runbook shell." (healthcare) / "Same runbook shell, different bureaucracy." (tax). Introduces the reusable primitive chain and the copyable master prompt.
2. **02 / Data strategy** — Real authoritative rules + synthetic private data. Healthcare: "Use real plan language and synthetic patient facts." Tax: "Use real IRS rules and synthetic taxpayer records."
3. **03 / Domain layer** — the ONLY part that changes per vertical: mapping tables, ledger schema, retrieval maps, output rules. Healthcare: "Map denial type to plan sections." Tax: "Map forms and expenses to review categories."
4. **04 / Sources and gates** — cite authoritative process sources; declare the human stop line as a product boundary. Healthcare: "Keep authority and agency visible." Tax: "Do not let the guide pretend to be a tax preparer."
5. **05 / Verification gates** — "Make done mean verified." / "Done means verified." Per-stage prove-it checklist + guard-as-shipping-gate.

Each guide ends with a "Primary sources for the starter" list (see §9) and links out to: "Open the Context Engineering skill primitives →" and a domain runbook ("Open the Claim Appeal Packet runbook →" / "Open the Tax Prep Packet runbook →").

## 3. The master prompt (VERBATIM, identical in both guides)

This XML prompt is the reusable core; only the domain data around it changes. Preserve exactly:

```
<prompt>
  <task>Build a document-grounded case workflow from reusable Open Skills primitives.</task>
  <thesis>
    People lose because their information is scattered, unstructured, uncited, and incomplete.
    The workflow should help the person own their context, not outsource judgment to a black box.
  </thesis>
  <primitive_chain>
    <step>Ingest documents into markdown/text with raw source coordinates as anchors. Use PDF page/region, CSV line number, or form box identifiers, and embed the identical anchor scheme in the markdown that downstream citations will use. Keep one numbering scheme end to end.</step>
    <step>Chunk and tag source evidence by structure.</step>
    <step>Normalize the case facts into a ledger.</step>
    <step>Run the coverage gate. Every ingested document must produce at least one normalized record or be explicitly marked reference-only. Print the list of unconsumed documents and stop before drafting if any document is unaccounted for.</step>
    <step>Reconcile shared facts across sources before drafting. Compare the same fact anywhere it appears, turn every mismatch into a named review question, and record which source governs the tracked value.</step>
    <step>Store chunks, records, mappings, and outputs in SQLite by default.</step>
    <step>Optional: if you already run OB1, mirror the case store into Open Brain; otherwise skip this step entirely. SQLite is the complete beginner path.</step>
    <step>Retrieve relevant evidence deterministically before drafting.</step>
    <step>Validate citations before export. The citation guard returns pass / needs_review / fail verdicts. Any fail blocks packet export until fixed or converted to a named review question, and the guard verdict summary must appear in the packet README.</step>
    <step>Export an editable packet and stop at human review.</step>
  </primitive_chain>
  <constraint>The agent organizes and drafts. It does not sign, send, file, submit, authorize, or transmit sensitive data.</constraint>
</prompt>
```

## 4. The 10-step primitive chain (canonical pipeline, restated operationally)

1. **Ingest** → markdown/text with raw source-coordinate anchors (PDF page/region, CSV line number, form box identifier). The SAME anchor scheme must be embedded in the markdown that downstream citations use — "Keep one numbering scheme end to end."
2. **Chunk + tag** source evidence by structure.
3. **Normalize** case facts into a ledger.
4. **Coverage gate** (hard stop): every ingested document must yield ≥1 normalized record OR be explicitly marked `reference-only`. Print the list of unconsumed documents and STOP before drafting if any document is unaccounted for. Tax adds a count check: "the inbox document count must equal the source_documents row count"; hard stop with a named-file list on mismatch. Failure mode named: a weak agent silently drops 5 of 15 documents including the W-2, then reports the W-2 as missing while it sits in its own ingested folder.
5. **Reconcile shared facts across sources** before drafting: compare the same fact everywhere it appears; every mismatch becomes a NAMED review question (never a silent correction); record which source GOVERNS the tracked value (so downstream still has one value).
6. **Store** chunks, records, mappings, outputs in **SQLite by default**.
7. **Optional Open Brain (OB1) mirror** — only if the person already runs OB1; otherwise skip entirely. "SQLite is the complete beginner path." (Two named paths: "Starter path: local files plus SQLite. Open Brain path: write the same chunks and normalized records into OB1.")
8. **Deterministic retrieval** of relevant evidence before drafting (retrieval starts from a domain structure map, not similarity search).
9. **Citation guard** before export — verdict vocabulary is exactly `pass` / `needs_review` / `fail`. Any `fail` blocks packet export until fixed or converted to a named review question. The guard verdict summary must appear in the packet README, reproducing "actual pass / needs_review / fail counts verbatim."
10. **Export an editable packet and stop at human review.** No sending/filing.

## 5. Data strategy pattern: "Real where rules matter; fake where people matter."

- **Real** (public/authoritative): insurer plan documents — SBC/EOC coverage language, exclusions, prior authorization rules, network rules, emergency services language, appeal instructions (healthcare); IRS publications, instructions, recordkeeping pages, form references (tax).
- **Synthetic** (privacy-sensitive): denial letters, patient identity, provider details, claim IDs, service dates, procedure details, medical facts (healthcare — "proves document-grounded drafting without touching real PHI"); taxpayer records — W-2s, 1099-NEC, 1099-MISC, interest/dividend statements, receipts, mileage logs, business bank CSVs, prior-year summaries (tax).
- **Seed cases** (healthcare): three synthetic denial cases — (a) administrative or coding error, (b) prior authorization missing, (c) medical necessity. First two produce appeal arguments from plan language + claim facts. Medical necessity must STOP at a doctor letter-of-medical-necessity template + packet assembly — "the agent does not invent clinical reasoning." Two retrieval branches remain untested by these seeds (service-not-covered, out-of-network): build them anyway, MARK THEM UNTESTED, smoke-test when real documents arrive. (Reusable pattern: build all branches, tag untested ones.)
- **Scope-first** (tax): v1 = one tax year, federal-only, single taxpayer or sole proprietor, common forms, Schedule C-style expense organization. Artifact = prep packet, not a filed return. Infer the tax year from evidence DENSITY across the inbox (form years, statement ranges, receipt dates, CSV periods, prior-year summaries); documents from other years become `reference-only` and stay out of the ledger. Missing-document expectations come from gaps/contradictions in THIS case's own documents (coverage windows, unmatched deposits, payer names, prior-year carryover notes), NOT from a generic fixture list.

## 6. Chunking rules (healthcare, reusable)

- **Two-tier chunking** for long plan documents: page-level chunks (whole document citable and auditable) + clause-level chunks for sections named in the retrieval map.
- Store a **`granularity` column** with values such as `page` and `clause` so retrieval can choose the right surface and the citation guard can explain what it checked.
- **Exclude** table of contents, cover pages, and front matter from evidence chunks. "A cited chunk must contain operative plan language, not headings or dotted page listings."
- Smoke test: print the text of EVERY cited chunk and read it before trusting the draft.
- Tax analog: a cited chunk must contain the actual form box or CSV row, not front matter; re-chunk around form boxes, table rows, receipt line items.

## 7. Domain layer (the only vertical-specific part)

### 7a. Healthcare: denial-type → plan-section retrieval map
| Denial type | Retrieves |
|---|---|
| Administrative or coding error | appeals process + claim/EOB line items |
| Service-not-covered | covered benefits + excluded services |
| Out-of-network | network + emergency services sections |
| Prior authorization | prior authorization sections |
| Medical necessity | covered benefits + triggers the doctor-template path |

Reconciliation (healthcare): for each claim number, compare denial-letter fields vs EOB rows FIELD BY FIELD — CPT code, service dates, amounts, provider. Also compare the plan-section citations named in the denial letter vs the real EOC table of contents. Any mismatch → `needs_review` unresolved question carried into the packet, never a silent correction. Governed-value example: the letter the patient received governs the appeal narrative; the EOB disagreement stays visible as a review question. Named failure mode: weak agent sees CPT 99214 in the letter, misses that the EOB says 99213, argues the wrong code.

### 7b. Tax: reconciliation + Schedule C category map
Reconciliation rules (before categorizing):
- One real-world transaction = one ledger row. Deduplicate by date + amount + vendor; a receipt and its matching bank CSV line MERGE into one row citing both pieces of evidence. If receipt and bank line disagree on amount → review question, NOT two expense entries.
- Corroboration vs primary evidence: bank deposits matching a 1099/invoice total become corroboration records cross-referencing that form; unmatched deposits stay primary income and generate a missing-1099 checklist item.
- Cross-check every W-2/1099 against deposits, and every business deposit against a 1099 or invoice by payer. Unmatched payers become NAMED missing-document checklist items with dates and amounts, e.g. verbatim: "Apex Consulting, $6,200 across 2 deposits, no 1099 on file."

Starter category set with explicit matching rules (verbatim labels):
- **Advertising** — vendor identity or receipt text shows paid promotion, sponsorship, listing fees, or ad platform spend.
- **Car and truck** — mileage logs and vehicle expenses tied to business use; a mileage log maps to ONE aggregate car-and-truck evidence record = total business miles × the IRS standard mileage rate, NEVER per-trip dollar lines. Source list links the IRS rate page.
- **Office expense** — workspace supplies, postage, printing, small office items with a business receipt.
- **Supplies** — materials consumed in the work product or client service delivery, supported by itemized receipts.
- **Software and subscriptions** — business tools, hosting, domains, AI tools, editing apps, SaaS accounts tied to the work.
- **Utilities** — business phone, internet, or workspace utility charges when the evidence shows business use.
- **Meals at 50%** — business meals with receipt, date, amount, participants or purpose, and 50% treatment flagged in the ledger.

Categorization guardrails:
- "Match on vendor identity and supporting evidence, never on substrings." Named failure mode: a weak keyword map files "PACIFIC GAS & ELECTRIC" under Car and truck because it matched "gas."
- Recurring personal-pattern charges (rent, groceries, streaming, restaurants) default to **excluded-pending-review**, never to a business category.
- **`needs_review` is the DEFAULT status** for any transaction lacking a matched receipt or documented business purpose. Only receipt-corroborated, single-category items enter the ledger as `ok`.
- Meals and mixed receipts ALWAYS start as review questions: meals need the 50% rule + business purpose; mixed receipts need a business/personal split before affecting totals.

## 8. Output packet specifications (exact file manifests)

Pattern: "Export a folder, not one mystery file" — one `packet/` directory per case/tax-year, editable drafts + rendered PDF + machine-readable maps + sources manifest.

### Healthcare appeal packet — `packet/` contains:
- `README.md` (must reproduce guard verdict counts verbatim; URGENT deadline flags at top)
- `appeal-letter.md` (editable)
- `appeal-letter.pdf` (the rendered appeal letter itself)
- `citation-map.json`
- `deadline-summary.md`
- `checklist.md` (supporting document checklist)
- `unresolved-questions.md`
- `sources/` manifest
- For medical-necessity cases: a BLANK doctor letter-of-medical-necessity template as a REQUIRED packet file. The appeal letter may quote the insurer's criteria and point to the template, but never asserts clinical criteria are met.

Deadline summary rules: normalize every appeal window to an ABSOLUTE date (stated days + notice date), compute days remaining from the run date, flag anything under 14 days as **URGENT** at the top of the packet README, order cases by deadline.

PDF rules: letter-formatted, sane page count, no browser artifacts; identity fields come from the NORMALIZED RECORD, not string slicing. Named failure modes: street address becoming the patient name; corrupted deadline like "y 27, 2026" copied instead of validated.

Grounding rule: "Every coverage claim must cite a chunk. Anything ungrounded becomes a review question."

### Tax prep packet — `packet/` per tax year contains:
- `income-summary.md`
- `expense-ledger.csv`
- `deduction-evidence-map.json`
- `missing-documents.md`
- `cpa-questions.md`
- `schedule-c-summary.md`
- `packet.pdf` (ONE combined rendered document for taxpayer/CPA; individual drafts stay editable in the folder)
- `sources/manifest.json`

Tax PDF rules: "sane low double digit page count" for one taxpayer-year, tables render AS tables, no raw markdown artifacts in the PDF.

Shared export rule (both): the packet reproduces the citation guard's actual `pass` / `needs_review` / `fail` counts VERBATIM. "Export refuses while any claim fails, or stamps **DRAFT-INVALID** on the cover" (if the workflow allows a draft handoff for review).

## 9. Authority sources (per guide, the "authority layer")

Healthcare (process facts + public plan fixtures; "Synthetic denial data never replaces source law, plan language, or human review."):
- HealthCare.gov internal appeals — internal appeal process and timing for denied claims
- 45 CFR 147.136 — federal claims and appeals process requirements
- KFF ACA denials and appeals — denial/appeal/overturn-rate framing for marketplace plans
- CareSource public plan documents — example public source for real SBC/EOC language

Tax (IRS pages as authority; synthetic taxpayer docs as fixtures; "The output is a review packet, not a filed return."):
- IRS Publication 17 — general federal income tax rules for individuals
- Schedule C instructions — sole proprietor income/expense instructions
- IRS recordkeeping guidance — what records to keep
- IRS Form 1099-NEC — nonemployee compensation reference
- IRS standard mileage rates — published per-mile rate to value business mileage from a log

## 10. Human approval points (the "human gate" — a product boundary, not a missing feature)

- Hard constraint (verbatim, both prompts): "The agent organizes and drafts. It does not sign, send, file, submit, authorize, or transmit sensitive data."
- The workflow STOPS at review/edit/export in both verticals.
- Healthcare: "Sending an appeal means a person signs and transmits health data to an insurer. That is a product boundary, not a missing automation feature." — "The human gate is a feature."
- Tax: "Filing taxes is a legal and financial act. The agent prepares the evidence packet and questions; the taxpayer or professional decides what goes on the return." — "The human or CPA files."
- Human-gate acceptance is itself a checklist item (tax): open `packet.pdf`, confirm page count, confirm expected sections present, confirm tables render, confirm the packet still stops at CPA/taxpayer review.
- Medical-necessity sub-gate: agent stops at doctor-template + packet assembly; a physician supplies clinical reasoning.

## 11. Verification pattern ("Prove it" / "Done means verified")

### 11a. Citation-guard negative test (proof artifact pattern; healthcare, applies to both)
- Save TWO reports as artifacts: (1) the fully cited draft — must PASS with **exit 0**; (2) a deliberately seeded "fabricated-but-well-formed citation" draft — must FAIL with **nonzero exit**, AND the seeded sentence itself must appear as THE failing item in the saved report.
- "A report that fails other claims does not count as proof. Fix the harness until the fabricated sentence is the reported failure, then keep both reports with the packet or test artifact bundle."
- The guard's verdict is a SHIPPING GATE: "export refuses while any claim fails validation." Passing claims ship into the review packet; `needs_review` flags stay honest review questions; failed claims block export until repaired or removed.

### 11b. Per-stage prove-it checklist (7 stages; each stage = question → named failure symptom → fix)

| Stage | Prove-it question | Broken-run symptom | Fix |
|---|---|---|---|
| Ingestion | Does every source/inbox document appear in the index with an anchor? | Files on disk never appear in source_documents; draft can ignore evidence without warning | Stop the run, list missing filenames, fix ingestion manifest, rerun coverage gate before normalization |
| Chunking | Does a cited chunk contain operative language / the actual form box or CSV row? | Chunk shows only headings, front matter, cover page, dotted page listings, or a document title while the claimed value lives elsewhere | Exclude the chunk from evidence; create a clause-level chunk from real text / re-chunk around form boxes, table rows, receipt line items |
| Normalization | Do names look like names; do dates parse (days-remaining computed); do amounts/ledger totals reconcile against source line-item sums? | Street address stored as patient name; corrupted date string; payer fields that look like categories; totals that do not foot | Mark the field `needs_review`, keep the source anchor, write the unresolved question; repair parsers; add a ledger-total reconciliation step |
| Retrieval (healthcare) | Did every expected section arrive; are missing sections flagged? | Branch returns only generic appeal instructions | Add the missing retrieval-map target or mark the branch untested until real documents arrive |
| Reconciliation (tax) | Is every receipt merged with its bank line; is every 1099/W-2 matched against deposits? | Double-counted receipts + bank rows; named payers left unmatched | Merge duplicate evidence into one row; produce missing-document questions for unmatched payers |
| Drafting + guard | Does a clean draft pass AND a seeded fabricated citation fail? | Guard passes both drafts, fails for unrelated claims, or accepts invented/vague citations | Require chunk-level anchors + a negative test before export; save the two reports; fix guard until the seeded sentence is the failing item |
| Export | Does the PDF open as the rendered letter/packet with sane page count, rendered tables? | Browser print artifact, blank file, huge export, blank tables, raw markdown | Regenerate from the letter renderer; recheck identity fields from the normalized record; block export when the PDF does not match folder drafts |
| Human gate | Does the packet stop at review with the checklist / CPA questions present? | Packet sends/files, presents as ready-to-file, or hides open questions | Stop at export; surface the checklist/questions; require a person-owned sign/transmit/file decision |

### 11c. Normalization QA rules (healthcare, "Refuse to ship invalid packets")
- "Normalization QA is not optional. Every extracted field carries a source anchor and confidence."
- Records with failed sanity checks become `needs_review` with a CONCRETE unresolved question — never left at "a default pending status."
- Packet export refuses to ship, or stamps `DRAFT-INVALID` on the cover, while the guard reports any FAIL.
- The packet README reproduces the guard's actual pass / needs_review / fail counts verbatim "so the reviewer sees the same verdict the pipeline saw."

## 12. Reusable patterns extracted (portable to any vertical)

1. **One primitive chain, many verticals.** Ingest→chunk→normalize→coverage-gate→reconcile→store(SQLite)→(optional OB1)→deterministic-retrieve→citation-guard→export+human-gate. Only labels, mappings, ledger schema, and output rules change per domain.
2. **Anchor discipline:** one source-coordinate scheme end to end (PDF page/region, CSV line, form box), embedded in ingested markdown and reused by citations.
3. **Coverage gate as hard stop:** count-match check (inbox count == source_documents rows) + every doc → ≥1 record or explicit `reference-only`; print named files and refuse to proceed.
4. **Reconcile-then-govern:** every cross-source mismatch → named review question; explicitly record the governing source so the pipeline keeps a single tracked value.
5. **Status vocabulary:** `pass` / `needs_review` / `fail` (guard verdicts); `ok` vs `needs_review` (ledger records); `reference-only` (out-of-scope docs); `excluded-pending-review` (personal-pattern charges); `untested` (unexercised branches); `DRAFT-INVALID` (cover stamp); `URGENT` (<14 days deadline flag).
6. **Negative-test proof artifacts:** seed a well-formed fabricated citation; done = clean draft exits 0 AND seeded sentence is THE reported failure with nonzero exit; keep both reports.
7. **Named weak-agent failure modes as spec:** each rule is justified by the concrete garbage a weak agent would ship (99214/99213 CPT mix-up, "PACIFIC GAS & ELECTRIC"→Car and truck, address-as-name, "y 27, 2026", silently dropped W-2). Write these into the checklist.
8. **Packet-not-action output:** folder of editable drafts + machine-readable maps (citation-map.json / deduction-evidence-map.json) + rendered PDF + sources manifest + unresolved questions; export gated by the guard.
9. **Real-rules/synthetic-people data split** to demo citation fidelity without PHI/financial exposure.
10. **Human gate framed as product boundary** — signing/sending/filing is never automated; the review checklist itself verifies the gate holds.
11. **Two storage tiers:** SQLite (beginner/default) vs Open Brain OB1 mirror (durable personal context, opt-in only).
12. **Two-tier chunk granularity** (`page` + `clause` in a `granularity` column) with front-matter exclusion and a print-and-read smoke test on cited chunks.
13. **Deadline normalization:** relative windows → absolute dates, days-remaining from run date, urgency threshold, deadline-ordered case list.
14. **Build untested branches anyway, mark untested** — architecture completeness over demo coverage, with honest labeling.

## 13. Named ecosystem components (proper nouns)

- **Unlock AI** — the site (by Nate B. Jones). Site nav: Open Engine, Guides, Open Skills, Benchmarks, Image Arena.
- **Open Skills** — the reusable primitive library the guides assemble (ingestion, chunking/tagging, normalization, storage, deterministic retrieval, citation validation, packet export, human gate). Linked as "Context Engineering skill primitives."
- **Open Brain / OB1** — optional durable personal-context store; SQLite mirror target.
- **Starter repo** — where users drop input documents; ships with synthetic fixtures.
- Domain runbooks: "Claim Appeal Packet runbook," "Tax Prep Packet runbook."
- Implied DB table name: `source_documents` (tax coverage checkpoint references its row count).

---

## OPEN QUESTIONS

1. The actual Open Skills implementations (code, CLI names, skill invocation syntax) are not in these pages — the guides link out to "Context Engineering skill primitives" and per-domain runbooks whose contents are not included here.
2. Exact SQLite schema is not specified beyond: tables for chunks, normalized records, mappings, outputs; a `granularity` column (`page`/`clause`); a `source_documents` table; per-field source anchor + confidence columns. Column-level DDL is unknown.
3. The citation guard's implementation (how it matches claims to chunks, what "well-formed" means for a fabricated citation, report format beyond pass/needs_review/fail counts and exit codes) is not detailed.
4. How "deterministic retrieval" is implemented (SQL lookups keyed by the retrieval map? tag filters? explicitly no embeddings?) is asserted but not specified.
5. The healthcare guide says exit 0 / nonzero exit for the guard test; whether the tax guide uses the same exit-code contract is implied ("negative test before export") but not restated.
6. `sources/` manifest format differs in wording: healthcare says "a sources/ manifest," tax says "a sources/ manifest.json" — unclear whether healthcare's manifest is also JSON.
7. Whether the combined tax `packet.pdf` and healthcare's single `appeal-letter.pdf` use the same renderer ("the letter renderer" is mentioned only in healthcare) is unspecified.
8. "Confidence" on extracted fields is mentioned once (healthcare normalization QA) with no scale or threshold defined.
9. The masthead-image lines and "Generated 2.39:1 ... masthead" text are page chrome, not build instructions — ignored as pattern content.
