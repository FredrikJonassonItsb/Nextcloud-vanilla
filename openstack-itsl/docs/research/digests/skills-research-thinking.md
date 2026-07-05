# Digest: Open Skills — "Research & Thinking" category (Unlock AI / Nate B. Jones)

Source: `open-skills__research-thinking.md` (scrape of https://unlock-ai.natebjones.com/open-skills/research-thinking)
Site attribution: "Unlock AI by Nate B. Jones". Site nav sections: Open Engine, Guides, Open Skills, Benchmarks, Image Arena.

## Category overview

- Category name: **Research & Thinking**
- Category tagline (verbatim): "Skills for turning messy input into thinking you can review: voice notes, meetings, document piles, weekly noise, and assumptions that need to be challenged before they become plans."
- Usage instruction from the page: read the page "like a triage menu" — find the skill that removes a repeated explanation, a fragile manual step, or a quality bar the agent keeps missing; then copy the setup prompt and let the agent adapt it to your tools, files, accounts, and standards. "Install the primitive only when you can name the workflow it will improve."
- Skill count: **5 skills**: Brain Dump Processor, Meeting Synthesis, Weekly Signal Diff, Assumption Checker, Reading Pack Builder.
- Recommended entry point ("How to use this category"): "If you are building this lane from scratch, start with Brain Dump Processor. Otherwise, skip directly to the skill that matches today's bottleneck. The prompt is the starting point; the installed skill should reflect your real workflow."
- Delivery format: each skill is presented as a **setup prompt** (a `<prompt><task>…</task></prompt>` XML block) the user pastes into their own AI coding agent. Every setup prompt follows the same pattern:
  1. "Create a new skill for my AI coding agent called \"<kebab-case-name>\", stored wherever my harness loads skills from."
  2. A one-line statement of the skill's job.
  3. (Usually) a pre-write **interview step**: "Before writing it, interview me for: …" (Assumption Checker is the only skill with NO interview step.)
  4. A numbered list of 5 required components ("The skill must include: (1) … (5) …").
  5. A **verification step**: "After writing it, test it on …" (real data, not synthetic).
- Cross-references at page end: "Back to the Skills directory or continue into runbook compositions."

---

## Skill 1: Brain Dump Processor

- **Skill id (as created by the setup prompt):** `brain-dump-processor`
- **Purpose:** Process messy, multi-topic input — voice memo transcripts, brain dumps/stream-of-consciousness notes, long rambling drafts — into cleanly separated, evaluated ideas ("pans for gold"): extract each distinct idea, separate them cleanly, evaluate which threads are worth pursuing, and file the results to a consistent destination so ideas accumulate instead of evaporating.
- **Rationale ("Why build it"):** Best ideas arrive mixed with worst ones, usually while walking; without a procedure, voice notes get transcribed and never read again. The agent becomes the filter: user talks for ten minutes, gets back ~five separated ideas with an honest evaluation of each. Paired with a transcription skill, it turns "rambling into your phone" into a legitimate ideation pipeline.
- **Triggers (required in the built skill):** whenever the user shares a voice transcript, a brain dump, or says **"process this"**.
- **Inputs:** voice memo transcripts, brain dumps, rambling notes (multi-topic text).
- **Prerequisites ("What you need"):** Nothing required; pairs naturally with the **Media Transcription** skill for voice memos.
- **Interview before writing (2 questions):**
  1. Where processed ideas should be filed — options given: one inbox file, a folder of dated notes, or a tool the user uses.
  2. What the user tends to ramble about, so evaluation criteria fit their work.
- **Required components (verbatim numbering from the setup prompt):**
  1. Trigger conditions — voice transcript / brain dump / "process this".
  2. **Extraction format per idea** (4 fields): (a) the idea in one sentence; (b) surrounding context; (c) an honest assessment of whether it's worth pursuing and why; (d) a concrete suggested next step.
  3. Rule: separate genuinely distinct ideas rather than summarizing the whole dump into mush.
  4. Rule: flag contradictions with things the user said earlier **in the same dump**.
  5. The filing destination and format (from the interview).
- **Verification:** "After writing it, test it on a real note or transcript I give you."

## Skill 2: Meeting Synthesis

- **Skill id:** `meeting-synthesis`
- **Purpose:** Turn a meeting recording or transcript into a structured, actionable synthesis: key takeaways, decisions made (with who made them), action items (with owners and deadlines where stated), open questions, and reusable/durable context worth keeping beyond the meeting. Enforces separation of what was actually said vs. what the agent inferred, so the synthesis stays trustworthy.
- **Rationale:** Hand-done meeting notes are either too thin to be useful or too long to be read. A fixed synthesis format produces the same reliable artifact from every meeting; the decisions/actions/questions split lets output plug directly into a task system instead of becoming another unread document.
- **Triggers:** any meeting transcript, recording, or a **"what happened in this meeting"** request.
- **Inputs:** meeting transcripts or recordings.
- **Prerequisites:** Nothing required; pairs with **Media Transcription** if starting from recordings.
- **Interview before writing (2 questions):**
  1. Where syntheses should be saved.
  2. Whether action items should also go somewhere specific — options given: task tool, file, email draft.
- **Required components:**
  1. Trigger conditions (above).
  2. **Fixed output structure** (5 sections): takeaways; decisions (with who decided); action items (with owner and deadline where stated); open questions; durable context worth keeping.
  3. **Hard rule** separating what was said from what the agent inferred — inferences get marked as such.
  4. Rule: preserve **exact quotes** for anything contentious or commitment-shaped.
  5. Multi-topic meeting handling: **synthesize per topic, not chronologically**.
- **Verification:** "After writing it, test it on one real transcript and show me the synthesis."

## Skill 3: Weekly Signal Diff

- **Skill id:** `weekly-signal-diff`
- **Purpose:** On a recurring basis (weekly is the natural cadence), review a defined set of inputs — notes, a folder, feeds, project state, saved searches — and report **only what meaningfully changed since the last run**: new signals, shifted assumptions, dead threads, emerging patterns. Maintains a small **state file** recording what it saw last time; the state file is what makes a true diff possible instead of a weekly summary that repeats itself.
- **Rationale:** The hard part of staying current isn't gathering information, it's noticing change. A diff against last week's state surfaces exactly the delta (what's new, what moved, what quietly died) and ignores the stable background. Explicitly framed as a "gentle introduction to **stateful skills**": the state-file pattern (skill remembers its last run) unlocks a whole class of recurring workflows.
- **Triggers:** run on demand by the user ("when I run it"); recurring/weekly cadence implied but no automatic scheduling specified in the prompt.
- **Inputs:** a defined watch-list: folders, notes files, topics to search, project states, feeds, saved searches.
- **Prerequisites ("What you need"):** A defined set of inputs to watch; pairs well with **Current-Information Search** skill for external signals.
- **Interview before writing (3 questions):**
  1. Which inputs to watch (folders, notes files, topics to search, project states).
  2. What counts as "meaningful" in the user's work.
  3. Where the report should go.
- **Required components:**
  1. A **state file** the skill maintains, recording what it observed each run, so diffs are real rather than re-summaries.
  2. The input list and **how to check each one**.
  3. Output format **ordered by importance of change, not by source**.
  4. Rule: **no-change is a valid and short answer — never pad a quiet week**.
  5. Closing section suggesting **at most three follow-ups** based on the diff.
- **Verification / bootstrap:** "After writing it, do an initial baseline run to populate the state file, and tell me what you recorded." (Note: first run is a baseline, not a diff.)

## Skill 4: Assumption Checker

- **Skill id:** `assumption-checker`
- **Purpose:** Adversarially audit a plan, argument, or strategy document for world-model problems: unstated assumptions, missing evidence, internal contradictions, and gaps between what the document claims and what it actually demonstrates. Output is a structured diagnostic: each assumption listed, rated for how **load-bearing** it is and how **well-supported/well-evidenced**, with the **single most dangerous assumption** flagged/called out at the top.
- **Rationale:** Agents are excellent at making plans *sound* coherent, which is precisely the danger. A dedicated adversarial pass — run **as its own skill with its own posture, not as an afterthought in the same conversation that produced the plan** — reliably catches "we assumed the API does X" and "this only works if users behave like Y" failures before they cost a week.
- **Triggers:** when the user asks to **check, stress-test, or red-team** a plan or document.
- **Inputs:** a plan, argument, or strategy document (optionally with access to the underlying sources or code).
- **Prerequisites:** Nothing.
- **Interview before writing:** NONE — this is the only skill in the category with no interview step.
- **Required components:**
  1. Trigger conditions (check / stress-test / red-team).
  2. **Posture rule:** "in this mode you are a skeptic, not a collaborator — do not soften findings or balance them with praise."
  3. Output format: each assumption stated plainly, rated for how load-bearing it is and how well-evidenced, with the single most dangerous assumption called out at the top.
  4. Rule: check claims **against the actual sources or code when they're available**, not just against internal consistency.
  5. Closing section: **the three questions that would most reduce risk if answered**.
- **Verification:** "After writing it, test it on any plan or doc I give you — or on one of your own recent plans from this session."

## Skill 5: Reading Pack Builder

- **Skill id:** `reading-pack-builder`
- **Purpose:** Given a pile of local documents (docs, SOPs, change requests, research notes, review materials), build a controlled reading surface: a **self-contained local HTML reading pack** that presents one document at a time, in a deliberate order, with an index page and simple progress tracking. Replaces "here are 14 files, good luck" with a guided review experience the agent assembled.
- **Rationale:** "Review is a workflow, not a folder." When someone must read and sign off on a set of materials, structure matters: order, one-at-a-time focus, and a record of what's been covered. Also demonstrates that agent output doesn't have to be chat text — it can be a purpose-built interface, generated in seconds.
- **Triggers:** when the user has a pile of documents to review or asks for a **"reading pack"**.
- **Inputs:** a set of local documents to review.
- **Prerequisites:** Nothing; **builds on / inherits the HTML Artifact Builder skill's conventions if the user has it** (referred to in the interview as an "html-artifacts skill").
- **Interview before writing (2 questions):**
  1. Where reading packs should be saved.
  2. The user's visual preferences — only needed **if they don't already have an html-artifacts skill to inherit from**.
- **Required components:**
  1. Trigger conditions (pile of documents / "reading pack" request).
  2. Conversion of each source document to **clean HTML, preserving structure**.
  3. An **index page** with one-line summaries and a **suggested reading order with reasoning**.
  4. **One-at-a-time navigation (previous/next)** and a simple **read/unread marker stored locally**.
  5. **Self-containment** — everything works offline as local files.
- **Verification:** "After writing it, test it on 3 or more documents I point you to and open the result."

---

## Cross-cutting conventions (needed to rebuild the system)

1. **Skill naming:** kebab-case identifiers exactly as quoted in each prompt: `brain-dump-processor`, `meeting-synthesis`, `weekly-signal-diff`, `assumption-checker`, `reading-pack-builder`.
2. **Storage:** always "stored wherever my harness loads skills from" — harness-agnostic; no fixed path given.
3. **Prompt envelope:** every setup prompt is wrapped as `<prompt><task>…</task></prompt>`.
4. **Structure of every setup prompt:** name+storage line → job statement → (optional) interview → exactly 5 numbered "must include" requirements → real-data test instruction.
5. **Interview-first pattern:** 4 of 5 skills require interviewing the user before writing the skill, to bind the skill to the user's real filing destinations, tools, and standards. Assumption Checker skips it (needs nothing user-specific).
6. **Verification pattern:** every skill ends with a mandatory test on REAL user material (a real note/transcript, one real transcript, a baseline state-file run, a real plan or the agent's own recent plan, 3+ real documents opened in the result). Never ship untested.
7. **Anti-mush rules recur:** separate distinct ideas (Brain Dump), said-vs-inferred separation + exact quotes (Meeting Synthesis), true diff not re-summary + no padding (Weekly Signal Diff), skeptic-not-collaborator posture (Assumption Checker).
8. **Statefulness:** only Weekly Signal Diff is stateful (state file per run); the page explicitly names the "state file pattern" as a reusable primitive for recurring workflows.
9. **Companion skills referenced from other categories:** Media Transcription (pairs with Brain Dump Processor and Meeting Synthesis), Current-Information Search (pairs with Weekly Signal Diff), HTML Artifact Builder / "html-artifacts" (conventions inherited by Reading Pack Builder). These live elsewhere in the Open Skills directory, not on this page.
10. **Tools:** no concrete tool/CLI/API names are mandated anywhere on this page; all tooling is deliberately left to the user's harness and discovered via the interview step (e.g., "a tool I use", "task tool, file, email draft").

## OPEN QUESTIONS

- Exact page/skill content of the companion skills (Media Transcription, Current-Information Search, HTML Artifact Builder) is not in this source; only their names and pairing roles are known.
- Weekly Signal Diff: no scheduling mechanism is specified (cron vs. manual invocation) — the prompt says "when I run it"; weekly cadence is advisory only. State-file format/location is also unspecified (left to the built skill).
- The "runbook compositions" page referenced at the end is not included in this source.
- No licensing/usage terms for the prompts are stated in the scraped text.
- The masthead image alt-text ("Generated 2.39:1 Open Skills category masthead…") indicates AI-generated site art; no operational relevance.
