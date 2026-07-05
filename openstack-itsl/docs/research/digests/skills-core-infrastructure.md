# Digest: Open Skills — Core Infrastructure (Unlock AI, Nate B. Jones)

Source: https://unlock-ai.natebjones.com/open-skills/core-infrastructure
Scraped copy: `scratchpad/natebjones/text/open-skills__core-infrastructure.md`

## Page purpose and framing

- Category page in the "Open Skills" directory of the "Unlock AI" site by Nate B. Jones. Site nav: Unlock AI / Open Skills, Open Engine, Guides, Open Skills, Benchmarks, Image Arena.
- Category tagline: "The foundation layer: image generation, current search, transcription, ingestion, and artifacts. Build these when other workflows keep re-solving the same tool and packaging problems."
- Usage guidance given verbatim on the page:
  - "Read this page like a triage menu. Find the skill that removes a repeated explanation, a fragile manual step, or a quality bar the agent keeps missing. Then copy the setup prompt and let your agent adapt it to your tools, files, accounts, and standards."
  - "Install the primitive only when you can name the workflow it will improve."
  - "If you are building this lane from scratch, start with Image Generation Gateway. Otherwise, skip directly to the skill that matches today's bottleneck. The prompt is the starting point; the installed skill should reflect your real workflow."
- Skill count: **5 skills**, listed in this order: Image Generation Gateway, Current-Information Search, Media Transcription, Heavy File Ingestion, HTML Artifact Builder.
- Each skill entry consists of: name, a description paragraph, a "Why build it" paragraph, a "What you need" line, and a full copyable setup prompt (some in XML-tag format, some as plain-text task instructions).
- Page footer links: "Back to the Skills directory or continue into runbook compositions." Byline: "Unlock AI by Nate B. Jones".

---

## Skill 1: Image Generation Gateway

**Installed skill name (exact):** `image-gateway`

**Purpose:** Generates or edits images through a single API (OpenRouter is the recommended choice) with one command and zero per-call setup. Stores saved preferences — default model, output directory, default size — so "generate an image of X" just works. Captures the current request shape of the API: which fields the endpoint expects, which model IDs are live, what each model costs per image, and the gotchas already hit. Other skills reference this one instead of writing their own API code.

**Why build it:** Image APIs change constantly; every agent session that improvises an API call repeats old mistakes. Centralizing image generation in one skill means an API change is fixed once and every workflow that generates images inherits the fix. Described as "the clearest example of a skill as a shared primitive: at least three other skills in this library call it rather than reimplementing it."

**What you need:** An OpenRouter account and API key (or any image API — "the pattern is identical").

**Setup prompt structure (XML-tag format, verbatim structure):**
- `<task>`: Create a new AI coding-agent skill called "image-gateway".
- `<storage>`: Store the skill wherever the harness loads skills from, e.g. `~/.claude/skills/image-gateway/SKILL.md` or `~/.codex/skills/image-gateway/SKILL.md`.
- `<job>`: Generate or edit images through the OpenRouter API with one command; use saved preferences so routine image requests need no per-call setup.
- `<inputs_to_collect>` (interview the user):
  1. Preferred default image model.
  2. Default output directory.
  3. Where the OpenRouter API key lives — "It must be read from an env file, never written into the skill."
- `<requirements>` (6 items):
  1. Define trigger conditions for direct image-generation requests AND for other skills that need image generation.
  2. Document the current OpenRouter image API request shape.
  3. Include a working curl or script example that reads the key from the env file.
  4. Store the collected preferences in the skill.
  5. Include per-image cost notes for the selected default model.
  6. Tell other skills to call this skill instead of writing their own image API code.
- `<verification>`: "Generate one image with the saved defaults, show me the result, then update the skill with anything learned from the test."

**Triggers:** direct image-generation requests; invocation by other skills needing images.
**Boundaries:** API key never hardcoded into the skill file — always read from env file.
**Cross-skill role:** shared primitive; at least three other skills in the library call it.

---

## Skill 2: Current-Information Search

**Installed skill name (exact):** `current-info-search`

**Purpose:** Routes web research through a search API built for discovering new information (Perplexity's API is "the canonical choice") instead of the harness's built-in search. Defines when to use it — recent releases, pricing, anything that may contradict the model's training data — and carries the exact API call shape, default model choice, and key location. Optionally can be wired in as a **hook** so all web searches redirect automatically.

**Why build it:** "Agents are confidently out of date." The single most common failure mode in AI-assisted research is the model "confirming" stale training data instead of discovering what changed last week. A dedicated search skill turns "search for current info" from a hope into a procedure — and makes every other research-flavored skill in the library more trustworthy.

**What you need:** A Perplexity API key (or another search API with real-time results).

**Setup prompt (plain-text task format), key contents:**
- Create skill `current-info-search`, stored wherever the harness loads skills from.
- Job: when asked about anything that changes quickly — AI model releases, pricing, software versions, news, APIs — call the Perplexity API directly instead of relying on training data or default web search.
- Interview first for: (a) where to store the Perplexity API key (env file); (b) which Perplexity model to default to (the agent should suggest one).
- Skill must include (4 items):
  1. Trigger conditions — any question about recent or fast-moving information, and any time the user's claim or the agent's knowledge might be stale.
  2. A working curl example for the Perplexity chat completions endpoint that reads the key from the env file.
  3. A rule to cite dates and primary sources in answers built from search results.
  4. A rule that when search results contradict the model's training data, **the search results win**.
- Verification: after writing, the agent asks itself one question about something released in the last month, runs the search, and shows the answer with sources.

**Triggers:** fast-moving topics (AI model releases, pricing, software versions, news, APIs); staleness risk.
**Boundaries:** search results override training data; answers must cite dates and primary sources; key from env file.
**Optional integration:** hook that redirects all web searches automatically.

---

## Skill 3: Media Transcription

**Installed skill name (exact):** `media-transcription`

**Purpose:** Transcribes local audio or video files with a transcription API (AssemblyAI is "a strong default") and packages output into reusable artifacts: a clean readable Markdown transcript, word-level timestamps, semantic chapters, and speaker labels. Captures the current API request shape — "including newer fields the docs bury" — so transcription work never repeats old API mistakes. Output artifacts are deliberately designed to feed other skills: editing workflows, research synthesis, content generation.

**Why build it:** "Transcripts are the universal input format for media work." Once a recording is a timestamped transcript the agent can edit, summarize, fact-check, extract clips, and generate graphics from it. "Almost every media runbook in this library starts here." Getting the packaging right once — consistent filenames, chapters, timestamps — is what makes downstream skills composable.

**What you need:** An AssemblyAI API key (or comparable transcription API) · **ffmpeg** installed for audio extraction from video.

**Setup prompt (plain-text task format), key contents:**
- Create skill `media-transcription`, stored wherever the harness loads skills from.
- Job: take a local audio or video file path and produce a complete transcription package using the AssemblyAI API.
- Interview first for: (a) where the AssemblyAI API key should live (env file); (b) where transcription outputs should be saved (the agent should suggest a folder convention next to the source media).
- Skill must include (5 items):
  1. Trigger conditions — any time the user gives a media file and asks for a transcript, captions, chapters, or "make this searchable".
  2. The current AssemblyAI request shape **including the speech model field**, with a working script that reads the key from the env file.
  3. A standard output package: readable Markdown transcript, word-level timestamp JSON, semantic chapters, and speaker labels, all with consistent filenames.
  4. An ffmpeg step to extract audio from video first when needed.
  5. A note that these artifacts are inputs for editing and research skills, so the format must stay consistent.
- Verification: test on a short audio file and show the output package.

**Triggers:** media file + request for transcript/captions/chapters/"make this searchable".
**Output artifact set (4 files):** Markdown transcript, word-level timestamp JSON, semantic chapters, speaker labels — consistent filenames required.
**Boundaries:** output format stability is a contract with downstream skills; key from env file.

---

## Skill 4: Heavy File Ingestion

**Installed skill name (exact):** `heavy-file-ingestion`

**Purpose:** Converts heavy, "agent-hostile" files — large PDFs, slide decks, spreadsheets, CSVs, long Word docs — into lightweight Markdown and CSV artifacts plus an index file, BEFORE any analysis begins. Enforced discipline: **never analyze a heavy file directly in context; convert to lean text artifacts first, then analyze those.** Includes per-file-type conversion recipes (which tools to use per file type) and the index format so a folder of converted material stays navigable.

**Why build it:** "Heavy files silently destroy agent sessions." A 200-page PDF or a 40-tab spreadsheet read directly into context burns the context window, degrades reasoning, and leaves nothing reusable behind. "Ingest-first means you pay the conversion cost once and every future session works from clean, greppable text." Described as "the foundational skill of the research runbook."

**What you need:** Nothing beyond standard conversion tools the agent can install (e.g. pdf-to-text utilities); no accounts.

**Setup prompt (plain-text task format), key contents:**
- Create skill `heavy-file-ingestion`, stored wherever the harness loads skills from.
- Job: when handed a heavy file (big PDF, slide deck, spreadsheet, CSV dump, long doc), convert it into lightweight Markdown/CSV artifacts plus an index BEFORE doing any analysis — never analyze the heavy file directly.
- Interview first for: (a) where converted artifacts should live — suggested convention: an `_ingested/` folder next to the source; (b) which file types the user handles most often.
- Skill must include (5 items):
  1. Trigger conditions — any heavy or binary document shared, or any analysis request that touches one.
  2. Per-file-type conversion recipes using tools available on the machine, installing what's missing.
  3. A standard index file listing each artifact with a one-line summary.
  4. The rule that analysis always reads the converted artifacts, never the original.
  5. Chunking guidance for very large sources so each artifact stays comfortably readable.
- Verification: test on one real PDF or deck supplied by the user and show the artifact folder and index.

**Triggers:** any heavy/binary document shared; any analysis request touching one.
**Conventions:** `_ingested/` folder next to source (suggested); index file with one-line summary per artifact.
**Boundaries:** hard rule — analysis reads converted artifacts only, never the original heavy file.

---

## Skill 5: HTML Artifact Builder

**Installed skill name (exact):** `html-artifacts`

**Purpose:** Turns dense agent output — implementation plans, research explainers, code review summaries, comparison tables, walkthroughs, diagrams, interactive reports — into a single self-contained HTML file with consistent, polished styling. Carries the user's visual conventions (fonts, colors, layout patterns, dark/light preference) so every artifact "looks like it came from the same shop," and enforces self-containment: one file, inline CSS/JS, no external dependencies, openable anywhere.

**Why build it:** "Long chat responses are where good analysis goes to die." A complex comparison or plan rendered as a styled, scrollable, sometimes interactive HTML page is dramatically more useful — readable, shareable, keepable. Once the agent has a house style, "make this a page" becomes a one-line request, and "the publishing skill (below)" can take any artifact public. (Note: the referenced publishing skill is not on this page — it apparently lives elsewhere in the library, likely a runbook/composition page.)

**What you need:** Nothing. "This is pure agent capability plus your taste."

**Setup prompt (plain-text task format), key contents:**
- Create skill `html-artifacts`, stored wherever the harness loads skills from.
- Job: render dense or visual output — plans, reports, research explainers, review summaries, comparisons, diagrams, walkthroughs — as a single self-contained HTML file with the user's house style, instead of a long chat response.
- Interview first for: (a) visual preferences — typeface direction, color palette or a brand color, dark or light default; (b) where artifact files should be saved.
- Skill must include (5 items):
  1. Trigger conditions — whenever output would be dense, visual, interactive, or worth keeping/sharing, offer or produce an HTML artifact.
  2. Hard rules: one file, inline CSS and JS, no external dependencies, works offline.
  3. House style tokens (type, spacing, colors) defined once at the top so every artifact matches.
  4. Layout patterns for the common cases: report, comparison table, timeline, diagram, dashboard.
  5. A rule to open or screenshot the result and verify it renders before declaring it done.
- Verification: the agent converts its own setup summary of this skill into an artifact and shows it.

**Triggers:** dense/visual/interactive/keep-worthy output.
**Hard boundaries:** one file, inline CSS/JS, zero external dependencies, offline-capable; render-verify before done.
**Design tokens:** typography, spacing, colors defined once at the top of each artifact.
**Layout pattern vocabulary:** report, comparison table, timeline, diagram, dashboard.

---

## Cross-cutting patterns across all 5 skills

1. **Storage convention:** skills stored "wherever the harness loads skills from," with concrete examples `~/.claude/skills/<name>/SKILL.md` and `~/.codex/skills/<name>/SKILL.md` (given explicitly only in the image-gateway prompt; the other four say "stored wherever my harness loads skills from").
2. **Interview-first pattern:** every prompt instructs the agent to interview the user for preferences/locations BEFORE writing the skill.
3. **Secrets policy:** API keys always read from an env file; never written into the skill file (explicit in image-gateway, current-info-search, media-transcription).
4. **Verify-by-doing:** every prompt ends with a live self-test — generate an image, run a real search, transcribe a real file, ingest a real PDF, or render an artifact — show the result to the user, and (in image-gateway) update the skill with lessons learned.
5. **Composability:** skills are explicitly designed as shared primitives. Image Gateway is called by ≥3 other library skills; transcription artifacts feed editing/research/content skills; heavy-file ingestion is "the foundational skill of the research runbook"; HTML artifacts feed a downstream publishing skill.
6. **API-shape capture:** three skills (image, search, transcription) exist partly to pin down a current, working API request shape (fields, model IDs, costs, gotchas, buried fields) so it is fixed once, centrally.
7. **Prompt formats:** skill 1 uses structured XML tags (`<prompt><task><storage><job><inputs_to_collect><input><requirements><requirement><verification>`); skills 2–5 use `<prompt><task>` wrapping a numbered plain-text brief. Each has "Copy prompt" / "Show the full setup prompt" UI affordances on the page.
8. **Default vendor choices:** OpenRouter (images), Perplexity (current search), AssemblyAI (transcription) — each explicitly swappable ("the pattern is identical" / "or another search API with real-time results" / "or comparable transcription API"). Ingestion and HTML skills need no accounts.

## OPEN QUESTIONS

1. The HTML Artifact Builder text references "the publishing skill (below)" that can take artifacts public, but no publishing skill appears on this page — it presumably lives on another category or runbook page not captured here.
2. "At least three other skills in this library call" the Image Generation Gateway — which three is not stated on this page.
3. The Current-Information Search mentions optional wiring "as a hook so all web searches redirect automatically," but gives no hook configuration details (harness-specific, left to the implementer).
4. Exact API request shapes (OpenRouter image endpoint fields, Perplexity chat completions curl, AssemblyAI speech model field) are to be captured at skill-creation time; the page intentionally does not embed them.
5. The page mentions "runbook compositions" as a continuation link; its content is not on this page.
