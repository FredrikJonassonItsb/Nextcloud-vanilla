# Digest: Writing, Voice & Content — Open Skills (Unlock AI / Nate B. Jones)

Source: https://unlock-ai.natebjones.com/open-skills/writing-voice-content
Source file: `natebjones/text/open-skills__writing-voice-content.md`
Site: "Unlock AI by Nate B. Jones". Page is one category in the "Open Skills" directory (site nav: Open Engine, Guides, Open Skills, Benchmarks, Image Arena). Page links back to "the Skills directory" and forward to "runbook compositions."

## Category definition

**Category name:** Writing, Voice & Content
**Category purpose (verbatim):** "Skills that make agent writing specific: a real voice, a real audience, current facts, branded images, and publishing formats that keep content from sliding back into generic AI prose."
**Skill count:** 4 skills.
**Listed order:** Personal Voice Skill, New Release Briefing, Audience-Calibrated Content System, Branded Image Prompting Guide.

**Usage model of the page (how the category is meant to be used):**
- "Read this page like a triage menu." Find the skill that removes a repeated explanation, a fragile manual step, or a quality bar the agent keeps missing. Then copy the setup prompt and let the agent adapt it to your tools, files, accounts, and standards.
- "Install the primitive only when you can name the workflow it will improve."
- Recommended entry point: "If you are building this lane from scratch, start with Personal Voice Skill. Otherwise, skip directly to the skill that matches today's bottleneck."
- "The prompt is the starting point; the installed skill should reflect your real workflow."

**Common pattern across all 4 skills:** each entry has (a) a description of what the skill does, (b) a "Why build it" rationale, (c) a "What you need" prerequisites line, and (d) a full copy-paste setup prompt wrapped in `<prompt><task>...</task></prompt>` XML tags. Every setup prompt follows the same skeleton:
1. `Create a new skill for my AI coding agent called "<kebab-case-name>", stored wherever my harness loads skills from.`
2. A one-sentence statement of the skill's job.
3. A pre-build step: interview/ask the user for specific inputs (and in one case, review analysis with the user before finalizing).
4. A numbered "The skill must include" list — always containing trigger conditions plus skill-specific structure/rules.
5. A post-build verification step: test the skill on a concrete task and let the user grade/judge the output.

---

## Skill 1: Personal Voice Skill

**Installed skill name (exact):** `my-voice`
**Storage:** "stored wherever my harness loads skills from."

**Purpose:** Encode how the user actually writes — across contexts, not as a single tone preset. Models the user's voice along multiple registers (direct/instructional, warm/relational, analytical, business-formal) with real samples of each, plus rules for when to use which: when to be blunt, when to soften, what words and constructions are never used, how emails differ from posts. Result: the agent "writes drafts that need light edits instead of rewrites."

**Why build it (rationale, key claims):**
- "Generic AI prose is the most recognizable writing style on the internet right now, and 'write this in a friendly tone' doesn't fix it."
- A voice skill built from actual writing samples with explicit anti-patterns (examples given verbatim: "never open with 'I hope this finds you well'", "never use 'delve'") is "the difference between an agent that drafts for you and one that drafts as you."
- "Consistently one of the highest-leverage skills for anyone who publishes or sends a lot of words."

**Inputs / prerequisites ("What you need"):** 5–10 samples of the user's real writing across different contexts (emails, posts, docs, messages).

**Triggers (required in the skill):** "whenever I ask you to write, rewrite, or review something in my voice."

**Build process (from the setup prompt):**
1. Before writing the skill, ask the user for 5–10 real writing samples across different contexts (emails, posts, documentation, casual messages).
2. Analyze the samples and propose four things:
   (1) the user's distinct registers (e.g. directive, relational, analytical, business) with what distinguishes each;
   (2) sentence-level patterns the user actually uses;
   (3) anti-patterns — words, openers, and constructions the user never uses, plus common AI-prose tells to explicitly avoid;
   (4) rules for when to use which register based on audience and stakes.
3. Review the analysis with the user before finalizing the skill.

**Required skill contents:**
- Trigger conditions (as above).
- The register model with one short sample of each register.
- The anti-pattern list.
- A rule that "for technical content, accuracy beats voice — never bend facts to sound like me."

**Verification:** After writing the skill, test it by drafting one short email and one short post on topics the user supplies, and let the user grade them.

**Boundaries:** Accuracy > voice for technical content (never bend facts). Not a single tone preset — must model register-switching.

**Relationships:** Named prerequisite/enhancer for the other two writing skills (New Release Briefing "makes the output dramatically better"; Audience-Calibrated Content System uses it "if the publication has a named author voice"). It is the recommended starting skill for the whole lane.

---

## Skill 2: New Release Briefing

**Installed skill name (exact):** `release-briefing`
**Storage:** "stored wherever my harness loads skills from."

**Purpose:** When something significant ships in the user's field (new AI model, major tool release, platform change), turn gathered release data into a publish-ready briefing package: a structured summary of what actually changed, an analysis post in the user's voice, a standardized title/subtitle, and image prompts for a matching thumbnail. Explicitly assumes research happened upstream (via a "Current-Information Search" skill) — this skill only transforms raw release material into a publishable artifact with "a consistent format readers learn to expect."

**Why build it (rationale, key claims):**
- "Release-day content is a race where accuracy usually loses."
- The skill encodes a quality bar — primary sources, dated claims, a fixed structure — "so speed stops costing correctness."
- "The consistent package format is the compounding part: your tenth briefing looks like your first, and your audience knows exactly what they're getting."

**Inputs / prerequisites ("What you need"):**
- Current-Information Search (or equivalent research input) — a separate skill referenced by name, presumably from another category of the same directory.
- Personal Voice Skill "makes the output dramatically better" (optional but strongly recommended).
- Image Generation Gateway for thumbnails (another named skill from the directory).

**Interview before building (user context to collect):** where the user publishes (newsletter, blog, internal doc), the audience's sophistication level, and title/format conventions if any exist.

**Required skill contents (numbered, from the setup prompt):**
1. Trigger conditions — when the user says "brief me up on <release>" or hands the agent release research to package.
2. A fixed package structure:
   - what actually changed (facts with dates and sources),
   - why it matters for the user's audience,
   - what to do about it,
   - a standardized title and subtitle,
   - 2–3 thumbnail image prompts matched to the subject's brand colors.
3. A rule that every factual claim carries a date and source, and unverified claims are labeled as such.
4. If a voice skill exists, write the post through it.
5. A rule that "this skill packages — if the research is missing or stale, stop and run current-info search first."

**Verification:** Test the skill on the most recent significant release in the user's field.

**Boundaries:**
- Packaging-only: does not do research itself; must refuse/stop and route to current-info search when research is missing or stale.
- Every fact must be dated and sourced; unverified claims must be explicitly labeled.

**Trigger phrase (exact template):** "brief me up on <release>".

---

## Skill 3: Audience-Calibrated Content System

**Installed skill name (exact):** `audience-content-system`
**Storage:** "stored wherever my harness loads skills from."

**Purpose:** Generate content for a specific publication targeting a specific audience level (example given: a beginner-focused newsletter). Encodes the publication's content formats (examples: a quick "snack," a concept explainer, a step-by-step tutorial), the audience's assumed knowledge floor and ceiling, banned jargon with required substitutions, and the weekly cadence. Given a theme, it plans and drafts a full content batch in the right voice at the right level.

**Why build it (rationale, key claims):**
- "Writing down a sophistication level is much harder than it looks — expertise leaks in as unexplained jargon and skipped steps."
- Encoding the audience contract once (what they know, what they don't, what formats serve them) means every piece starts calibrated instead of needing a "make this simpler" revision pass.
- "For anyone running a publication with a defined audience, this turns content production from artisanal to systematic."

**Inputs / prerequisites ("What you need"):**
- A defined publication and audience.
- Personal Voice Skill if the publication has a named author voice.

**Interview before building (user context to collect):**
- The publication and its audience (who they are, what they already know, what they definitely don't).
- The user's content formats (e.g. short tip, concept explainer, tutorial) with length and structure for each.
- Publishing cadence.
- 2–3 examples of pieces that landed well.

**Required skill contents (numbered, from the setup prompt):**
1. Trigger conditions — planning or drafting anything for this publication.
2. The audience contract: knowledge floor, knowledge ceiling, banned jargon with plain-language substitutions.
3. A template per content format.
4. A batch-planning mode: given a theme, propose a full week/cycle of pieces across formats before drafting.
5. A calibration check before delivering any draft: "would my least technical reader follow every step?"

**Verification:** Test by planning one content batch on a theme the user gives, and drafting the shortest piece from the plan.

**Boundaries:**
- Publication-scoped: triggers only for content aimed at this specific publication.
- Plan-before-draft in batch mode (propose full week/cycle across formats before drafting anything).
- Mandatory pre-delivery calibration check against the least technical reader.

**Key vocabulary:** "audience contract", "knowledge floor", "knowledge ceiling", "snack" (quick content format), "batch-planning mode".

---

## Skill 4: Branded Image Prompting Guide

**Installed skill name (exact):** `branded-image-prompting`
**Storage:** "stored wherever my harness loads skills from."

**Purpose:** A complete prompting guide for generating images in the user's visual brand — colors, typography direction, composition style, and recurring formats (thumbnails, infographics, diagrams, photoreal scenes, UI mockups). Includes brand guidelines applied automatically, techniques for both natural-language and JSON-structured prompting on current image models, a library of proven prompt templates for common formats, and corrective prompting recipes for when models drift off-brand.

**Why build it (rationale, key claims):**
- "Image models can hold a brand — but only if the brand is written down in prompt-shaped form."
- Without the skill, "every image is a fresh negotiation and your visual output looks like ten different people made it."
- With it, "'make me a thumbnail about X' returns something on-brand on the first try, and the prompt library compounds: every prompt that works gets added."

**Inputs / prerequisites ("What you need"):**
- Image Generation Gateway (or any image model access) — another named skill from the directory.
- The user's brand basics: colors, type direction, visual references.

**Interview before building (user context to collect):**
- Brand colors (hex values).
- Typography direction.
- Overall visual style (with reference images if available).
- Most common image formats (thumbnails, diagrams, infographics, social images, mockups).

**Required skill contents (numbered, from the setup prompt):**
1. Trigger conditions — any branded or recurring-format image request.
2. Brand guidelines in prompt-ready language the agent applies by default.
3. Both natural-language and JSON-structured prompt patterns for current image models, with notes on when each works better.
4. A starter library of 10+ prompt templates covering the user's common formats.
5. Corrective prompting recipes for typical drift (wrong colors, mangled text, off-style).
6. A rule to route actual generation through the user's image-gateway skill and add successful prompts back to the library.

**Verification:** Test by generating one thumbnail and one diagram in the user's brand and let the user judge them.

**Boundaries:**
- Prompting/guidance skill, not a generator: actual generation must be routed through the image-gateway skill.
- Self-improving library: successful prompts must be added back to the template library.

---

## Cross-skill conventions and dependency graph

**Naming convention:** installed skill names are kebab-case: `my-voice`, `release-briefing`, `audience-content-system`, `branded-image-prompting`.

**Universal setup-prompt skeleton (rebuildable template):**
```
<prompt>
  <task>
    Create a new skill for my AI coding agent called "<name>", stored wherever my
harness loads skills from.

The skill's job: <one-sentence job statement>.

Before writing it, [ask me for samples / interview me for: <inputs>].

The skill must include: (1) trigger conditions — <triggers>; (2..n) <skill-specific
structure, templates, and rules>.

After writing it, test it by <concrete small test> and let me [grade/judge] the output.
  </task>
</prompt>
```

**Mandatory elements in every skill:** explicit trigger conditions; at least one hard rule/boundary; a concrete post-build test with human grading.

**Dependency graph (skills referenced by name, some living in other directory categories):**
- `my-voice` (Personal Voice Skill): no dependencies; feeds `release-briefing` (rule 4: write the post through the voice skill if it exists) and `audience-content-system` (if publication has a named author voice).
- `release-briefing`: depends upstream on "Current-Information Search" (research input; hard stop-rule if research missing/stale) and downstream on "Image Generation Gateway" (thumbnails). Enhanced by `my-voice`.
- `audience-content-system`: needs a defined publication + audience; enhanced by `my-voice`.
- `branded-image-prompting`: depends on "Image Generation Gateway" (or any image model access) for actual generation routing.

**Recurring design principles across the category:**
- Skills encode contracts/quality bars once (voice registers, audience contract, source-and-date rule, brand-in-prompt-form) so outputs are calibrated by default rather than fixed in revision.
- Anti-patterns are explicit and named (AI-prose tells; "delve"; "I hope this finds you well").
- Separation of concerns: research vs. packaging (release-briefing), prompting guidance vs. generation (branded-image-prompting).
- Compounding: consistent formats and growing prompt libraries are called out as the long-term value.
- Every skill ends with a human-graded acceptance test.

## OPEN QUESTIONS

- "Current-Information Search" and "Image Generation Gateway" are referenced as named skills but defined elsewhere in the Open Skills directory; their specs are not on this page.
- "Stored wherever my harness loads skills from" is intentionally harness-agnostic — no concrete file path, file format (e.g. SKILL.md frontmatter), or schema for the installed skills is given on this page.
- The page mentions "runbook compositions" as the next section of the site, but no composition details are included here.
- The masthead image and skill-drawer visual are described in alt text only ("Generated 2.39:1 Open Skills category masthead showing categorized skill drawers on the right side"); no further visual spec exists.
- No versioning, licensing, or update cadence for the skill prompts is stated.
