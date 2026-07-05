# Digest: Video & Media Production — Open Skills (Unlock AI, Nate B. Jones)

Source: https://unlock-ai.natebjones.com/open-skills/video-media-production
Scraped file: `open-skills__video-media-production.md`
Category: "Video & Media Production" — part of the "Open Skills" directory on Unlock AI by Nate B. Jones.
Skill count on page: **3 skills** — Radio Edit, B-Roll Pipeline, AI Editing Assistant (NLE Integration).

## Category framing (page-level guidance)

- Tagline: "Skills for the expensive parts of media work: transcript-first editing, motion graphics, timeline assembly, and NLE control. Build these after the simpler media primitives are working."
- Usage model: read the page "like a triage menu" — find the skill that removes a repeated explanation, a fragile manual step, or a quality bar the agent keeps missing; then copy the setup prompt and let the agent adapt it to your tools, files, accounts, and standards.
- Rule: "Install the primitive only when you can name the workflow it will improve."
- Ordering guidance ("How to use this category"): if building this lane from scratch, **start with Radio Edit**; otherwise skip to the skill matching today's bottleneck. "The prompt is the starting point; the installed skill should reflect your real workflow."
- All three skills depend on a prerequisite skill from another category: **Media Transcription** (word-level timestamps).
- Each skill entry has the structure: name, description, "Why build it", "What you need", and a copyable setup prompt wrapped in `<prompt><task>...</task></prompt>` XML tags.
- Page footer links: "Back to the Skills directory" and "continue into runbook compositions."
- Site nav context: Unlock AI / Open Skills, with sections Open Engine, Guides, Open Skills, Benchmarks, Image Arena.

---

## Skill 1: Radio Edit

**Skill name (exact, as the agent should store it):** `radio-edit`
**Storage location:** "wherever my harness loads skills from" (harness-agnostic phrasing used verbatim in the prompt).

### Purpose
Produce a transcript-driven "radio edit": a rough cut of talking-head footage where the **spoken narrative is fixed before any visuals are touched**. From a timestamped transcript, the agent identifies false starts, repeated takes, filler, tangents, and flubbed lines; chooses the best take of each repeated section; and produces two deliverables:
1. A human-reviewable **paper edit** (what's kept, what's cut, why) — delivered BEFORE the timeline file.
2. A **timeline file (FCXML or EDL)** the user's editing software imports directly, with all cuts already placed.

### Why build it (rationale, verbatim spirit)
The first hours of editing talking-head footage are mechanical (find good takes, cut failures, tighten flow) — exactly what a transcript-literate agent does well. The paper edit lets the human review editorial choices before opening the editing app. "Going from raw recording to a cuts-placed timeline without scrubbing footage by hand changes the economics of producing video."

### Triggers (skill trigger conditions to encode)
When the user asks for a **rough cut**, **paper edit**, or **cleaned-up edit** of a recording.

### Inputs
- A video **plus** its word-level timestamped transcript (produced by the user's separate `media-transcription` skill — word-level timestamps are stated as essential).

### Dependencies / tools required
- Media Transcription skill (word-level timestamps essential).
- An NLE that imports FCXML or EDL: DaVinci Resolve, Premiere, or Final Cut named explicitly.

### Setup-interview questions (the skill-creation prompt instructs the agent to interview the user BEFORE writing the skill, on):
1. Editing software — to choose the right timeline format (FCXML or EDL).
2. Default cut aggressiveness — "tight vs. conversational".
3. Anything that must ALWAYS be cut — profanity, specific phrases, names.

### Required skill contents (5 numbered requirements from the prompt, exact):
1. **Trigger conditions** — when the user asks for a rough cut, paper edit, or cleaned-up edit of a recording.
2. **Edit-decision rules**: detect false starts, repeated takes (keep the best, **with reasoning**), filler, dead air, and tangents.
3. **Paper edit document** for review: every cut with timecodes, what was removed, and why — delivered **BEFORE** the timeline file.
4. **Timeline export** in the user's NLE format with cuts placed, **including a small handle of frames on each cut for finesse**.
5. **Revision loop**: user marks up the paper edit; agent regenerates the timeline.

### Verification (post-build test, from the prompt)
Test end to end on a **short recording (under 5 minutes)**, **including importing the timeline into the user's editor**.

### Boundaries
- Audio/narrative-first: fixes spoken flow only; no visual work.
- Human review gate: paper edit precedes timeline generation.

### Full setup prompt (verbatim)
```
<prompt>
  <task>
    Create a new skill for my AI coding agent called "radio-edit", stored wherever my
harness loads skills from.

The skill's job: produce a transcript-driven rough cut of talking-head footage — fix
the spoken flow first, before any visual work.

This depends on my media-transcription skill: input is a video plus its word-level
timestamped transcript.

Before writing it, interview me for: my editing software (for the right timeline
format — FCXML or EDL), how aggressive cuts should be by default (tight vs.
conversational), and whether anything must always be cut (profanity, specific
phrases, names).

The skill must include: (1) trigger conditions — when I ask for a rough cut, paper
edit, or cleaned-up edit of a recording; (2) edit-decision rules: detect false starts,
repeated takes (keep the best, with reasoning), filler, dead air, and tangents;
(3) a paper edit document for my review: every cut with timecodes, what was removed,
and why — delivered BEFORE the timeline file; (4) timeline export in my NLE's format
with cuts placed, including a small handle of frames on each cut for finesse;
(5) a revision loop: I mark up the paper edit, you regenerate the timeline.

After writing it, test it on a short recording (under 5 minutes) end to end, including
importing the timeline into my editor.
  </task>
</prompt>
```

---

## Skill 2: B-Roll Pipeline

**Skill name (exact):** `broll-pipeline` — a skill **plus two subagents**.
**Storage location:** "wherever my harness loads skills from".

### Purpose
End-to-end automated pipeline that turns a finished talking-head video (plus timestamped transcript) into one with **animated motion-graphic overlays** composited at the right moments:
- A **scout agent** analyzes the transcript and selects the moments deserving a graphic, applying density and spacing rules "so the video isn't wallpapered".
- A **builder agent** generates animated graphic components **in code** — **Remotion (React-based video) is the proven stack** — against a strict shared visual contract so every graphic matches.
- Clips are rendered, then composited onto the source video at the right timestamps with **platform-appropriate titling**.
- One orchestrator skill runs the whole flow and **tracks pipeline state so a multi-hour job can resume after interruption**.

### Why build it
Called "the most complicated skill in the library and the strongest proof of the skills thesis": motion-graphics work that costs an editor days happens in a supervised pipeline run. It teaches **three advanced patterns**:
1. **Subagent decomposition** — scout selects, builder builds; "neither does the other's job".
2. **Contract-first generation** — the shared visual API is what keeps fifty generated graphics consistent.
3. **Resumable state** — long pipelines must survive interruption.
Advice: build the earlier media skills first; build this "when you're ready for the payoff".

### Architecture (three pieces, exact from the prompt)
1. **SCOUT subagent**: reads the **chaptered** transcript; selects which moments deserve a graphic; enforces density and spacing rules (a **target of graphics-per-minute** and a **minimum gap between them**); writes a **manifest**: timestamp in/out, concept, and the data or text each graphic should show.
2. **BUILDER subagent**: takes **2–3 manifest entries at a time**; generates **Remotion (React video) components** for them against a **SHARED VISUAL CONTRACT** — **one TypeScript file** defining the palette, typography, animation primitives, and layout components every graphic must use. "The contract is what keeps all graphics consistent."
3. **ORCHESTRATOR skill**: runs scout → builder batches → render each clip → composite clips onto the source video at manifest timestamps with **ffmpeg** → final output. Keeps a **pipeline state file** so a long run can resume **from any stage** after interruption.

### Inputs
- A finished talking-head video plus its timestamped transcript (chaptered transcript for the scout).

### Manifest schema (as specified)
Per entry: timestamp in/out · concept · the data or text the graphic should show.

### Dependencies / tools required
- Media Transcription skill.
- **Node.js with Remotion**.
- **ffmpeg** for compositing.
- "Real patience for the initial build — this one is a project, and worth it."

### Setup-interview questions (before building):
1. Brand palette and typography (for the visual contract).
2. Target graphic density.
3. Output specs — resolution, platforms.

### Build/verification plan (staged, each stage verified before moving on — exact order):
1. Contract first.
2. Then **one hand-written reference graphic approved together** (user + agent).
3. Then the scout.
4. Then the builder (**validated against the contract**).
5. Then rendering and compositing.
6. Test the **full pipeline on a short video (2–3 minutes) before any real footage**.

### Boundaries
- Scout and builder have strictly separated responsibilities (neither does the other's job).
- All graphics must be generated against the shared visual contract (one TypeScript file); no ad-hoc styling.
- Density/spacing rules cap graphics-per-minute with a minimum gap (avoid "wallpapering").
- Long-running job must be resumable via the pipeline state file.

### Full setup prompt (verbatim)
```
<prompt>
  <task>
    Create a skill (plus two subagents) for my AI coding agent called "broll-pipeline",
stored wherever my harness loads skills from.

The job: an end-to-end pipeline that takes a finished talking-head video plus its
timestamped transcript and produces animated motion-graphic overlays composited onto
the video at the right moments.

Architecture — three pieces:
1. A SCOUT subagent: reads the chaptered transcript and selects which moments deserve
   a graphic, enforcing density and spacing rules (a target of graphics-per-minute and
   a minimum gap between them), and writes a manifest: timestamp in/out, concept, and
   the data or text each graphic should show.
2. A BUILDER subagent: takes 2–3 manifest entries at a time and generates Remotion
   (React video) components for them against a SHARED VISUAL CONTRACT — one TypeScript
   file defining the palette, typography, animation primitives, and layout components
   every graphic must use. The contract is what keeps all graphics consistent.
3. The ORCHESTRATOR skill: runs scout → builder batches → render each clip → composite
   clips onto the source video at manifest timestamps with ffmpeg → final output. It
   keeps a pipeline state file so a long run can resume from any stage after
   interruption.

Before building, interview me for: my brand palette and typography for the visual
contract, my target graphic density, and output specs (resolution, platforms).

Build it in stages and verify each before moving on: contract first, then one
hand-written reference graphic we approve together, then the scout, then the builder
(validated against the contract), then rendering and compositing. Test the full
pipeline on a short video (2–3 minutes) before any real footage.
  </task>
</prompt>
```

---

## Skill 3: AI Editing Assistant (NLE Integration)

**Skill name (exact):** `nle-assistant`
**Storage location:** "wherever my harness loads skills from".

### Purpose
Connect the agent **directly to the video editing software** via its scripting API — **DaVinci Resolve is the proven target** (it has a Python scripting API; the free version includes it) — so the agent operates **inside the editor**: analyzing transcripts, removing silences, extracting subclips, making editorial decisions, and building timelines programmatically **in the user's real project** rather than handing over files to import. Contrast stated on page: "Where Radio Edit produces a timeline file, this skill manipulates the editor live."

### Why build it
"The deepest level of media automation — agent as editing assistant rather than file generator." Example utterances become one-sentence commands: "Cut the silences out of this interview," "pull every clip where she mentions pricing," "build a rough timeline of the best moments." Also teaches the **general pattern of driving any scriptable desktop application from an agent**, "which extends far beyond video."

### Triggers (skill trigger conditions to encode)
Editing requests that should happen **inside the editor**: remove silences; extract clips matching a description; build a rough timeline from footage.

### Inputs
- A running DaVinci Resolve instance with an open project.
- Transcripts from the user's `media-transcription` skill (transcripts drive the edits).

### Dependencies / tools required
- **DaVinci Resolve** (free version includes the scripting API; its Python scripting API ships with the app) or another **scriptable NLE**.
- Media Transcription skill.
- "Comfort letting an agent operate real software (the skill must work on duplicated timelines, never originals)."

### Pre-build verification (from the prompt)
Before building anything else: **verify the agent can connect to a running instance and read a project**.

### Required skill contents (5 numbered requirements, exact):
1. **Trigger conditions** — editing requests that should happen inside the editor: remove silences, extract clips matching a description, build a rough timeline from footage.
2. **The connection procedure and its failure modes** (app not running, project not open).
3. **A hard safety rule: ALWAYS duplicate the timeline and work on the copy — never modify an original timeline or delete media.**
4. **Core operations, each verified individually**: import media, read/mark clips, cut at timecodes, assemble timelines from a transcript-derived edit list.
5. **Integration with the media-transcription skill** so transcripts drive the edits.

### Verification (post-build test, from the prompt)
Test against a **throwaway project**: duplicate a timeline, remove silences from one clip, and **show the user the result in the app** before touching anything real.

### Boundaries (safety)
- Never modify an original timeline; never delete media; always work on a duplicated timeline.
- Handle connection failure modes explicitly (app not running, project not open).
- Only operate on real projects after the throwaway-project demonstration.

### Full setup prompt (verbatim)
```
<prompt>
  <task>
    Create a new skill for my AI coding agent called "nle-assistant", stored wherever my
harness loads skills from.

The skill's job: operate my video editing software directly through its scripting API
to do transcript-driven editing — silence removal, subclip extraction, and timeline
building — inside my real projects.

Before writing it, check what's available: I use DaVinci Resolve (its Python scripting
API ships with the app). Verify you can connect to a running instance and read a
project before building anything else.

The skill must include: (1) trigger conditions — editing requests that should happen
inside the editor: remove silences, extract clips matching a description, build a
rough timeline from footage; (2) the connection procedure and its failure modes (app
not running, project not open); (3) a hard safety rule: ALWAYS duplicate the timeline
and work on the copy — never modify an original timeline or delete media; (4) core
operations, each verified individually: import media, read/mark clips, cut at
timecodes, assemble timelines from a transcript-derived edit list; (5) integration
with my media-transcription skill so transcripts drive the edits.

After writing it, test against a throwaway project: duplicate a timeline, remove
silences from one clip, and show me the result in the app before touching anything
real.
  </task>
</prompt>
```

---

## Cross-cutting conventions (apply to all skills in this category / directory)

- **Skill naming:** lowercase kebab-case identifiers passed verbatim to the agent (`radio-edit`, `broll-pipeline`, `nle-assistant`).
- **Storage phrasing:** every prompt says "stored wherever my harness loads skills from" — deliberately harness-agnostic.
- **Prompt format:** setup prompts are wrapped in `<prompt><task>...</task></prompt>` XML.
- **Interview-first pattern:** every prompt instructs the agent to interview the user for preferences/environment BEFORE writing the skill.
- **Explicit trigger conditions** are a required section of each written skill.
- **Test-after-build pattern:** every prompt ends with a concrete, small-scale end-to-end verification step (sub-5-minute recording; 2–3 minute video; throwaway project) before real use.
- **Dependency chain:** all three depend on a `media-transcription` skill (word-level timestamps) defined elsewhere in the Open Skills directory; the category page says to build "the simpler media primitives" first.
- **File formats named:** FCXML, EDL (timeline interchange); TypeScript (visual contract); Remotion/React components (graphics); Python (Resolve scripting API).
- **Command-line tools named:** ffmpeg (compositing), Node.js (Remotion runtime).
- **NLEs named:** DaVinci Resolve (proven target, free version includes scripting API), Adobe Premiere, Final Cut.

## OPEN QUESTIONS

1. The `media-transcription` prerequisite skill is referenced but defined on a different page of the Open Skills directory — its exact spec (name, triggers, output transcript format, chaptering) is not in this source.
2. "Platform-appropriate titling" (B-Roll Pipeline) is mentioned in the description but not elaborated in the setup prompt — no titling rules or platform list are given.
3. The pipeline state file format for `broll-pipeline` is unspecified (only its purpose — stage-level resumability — is stated).
4. The manifest file format (JSON? YAML?) for the scout output is unspecified beyond its fields (timestamp in/out, concept, data/text).
5. "A small handle of frames" for radio-edit cuts has no numeric default (frame count left to implementer/interview).
6. The page mentions "runbook compositions" as a follow-on section but gives no detail here.
7. The exact chaptering mechanism ("chaptered transcript") assumed by the scout subagent is not defined in this source — presumably produced by the media-transcription skill.
