# Digest: Open Skills — Agent Operations category (natebjones / Unlock AI)

Source: `open-skills__agent-operations.md` (scrape of https://unlock-ai.natebjones.com/open-skills/agent-operations)
Site attribution: "Unlock AI by Nate B. Jones". Site nav areas: Open Engine, Guides, Open Skills, Benchmarks, Image Arena.
Related navigation: "Back to the Skills directory or continue into runbook compositions."

## Category framing (verbatim-critical points)

- Category tagline: "Meta-skills for running agents without becoming the bottleneck: goal prompts, visible delegation, operating maps, merge discipline, stakeholder updates, and skill-library compounding."
- Usage guidance: "Read this page like a triage menu. Find the skill that removes a repeated explanation, a fragile manual step, or a quality bar the agent keeps missing. Then copy the setup prompt and let your agent adapt it to your tools, files, accounts, and standards."
- Installation rule: "Install the primitive only when you can name the workflow it will improve."
- Ordering guidance: "If you are building this lane from scratch, start with Goal Prompt Generator. Otherwise, skip directly to the skill that matches today's bottleneck. The prompt is the starting point; the installed skill should reflect your real workflow."
- Count: **7 skills**, in this order: Goal Prompt Generator, Visible Delegation, Session Operating Map, Self-Authored PR Merge, Stakeholder Update Email, Session-to-Skill Extractor, Agentic Harness Designer.
- Page format per skill: title, descriptive paragraph, "Why build it" paragraph, "What you need" line (prerequisites), and a full copyable setup prompt wrapped in `<prompt><task>...</task></prompt>` XML. Each setup prompt begins with the pattern: `Create a new skill for my AI coding agent called "<skill-slug>", stored wherever my harness loads skills from.` Each setup prompt ends with a live test instruction ("After writing it, test it by/on ...").
- Recurring structural convention across ALL setup prompts: item (1) is always **trigger conditions**; the middle items are the skill's procedure/rules; the final line is always a real-world test of the freshly written skill.

---

## Skill 1: Goal Prompt Generator (slug: `goal-prompt-generator`)

**Purpose:** Transform a fuzzy implementation plan into a bounded, autonomous objective for an agent — a goal prompt with explicit definition of done, repo constraints (what may be touched / what must not be), verification gates to pass before claiming completion, and stop conditions (halt-and-ask rather than improvise). Output is a prompt handed to a fresh agent session — or a different agent entirely — that can be pursued without supervision and checked cleanly afterward.

**Why build it:** "The difference between an agent that works autonomously and one that wanders is almost entirely in how the objective is specified." The specification pattern that works = "Definition of done + constraints + verification gates"; this skill makes producing it a procedure instead of an art. It is the natural bridge between agents: one agent plans, this skill packages, another agent executes.

**What you need:** Nothing.

**Setup-prompt required contents (exact spec):**
1. Trigger conditions — when the user asks to package work for another session, write a goal prompt, or prepare a task for autonomous execution.
2. Required structure for EVERY goal prompt:
   - the objective in one paragraph;
   - an explicit **DEFINITION OF DONE** as a checklist of verifiable statements;
   - repo constraints (files/areas that may be modified, files/areas that must NOT be touched);
   - **verification gates** — the exact commands to run and expected results before claiming completion;
   - **stop conditions** — situations where the agent must halt and ask instead of improvising.
3. Self-containment rule: the receiving session has none of the conversation context, so the prompt must include exact paths and all needed background.
4. Quality check before delivering, verbatim: "could a competent agent with zero context execute this and could I verify the result without re-deriving the plan?"

**Test step:** "After writing it, test it by packaging the next real task I describe into a goal prompt."

---

## Skill 2: Visible Delegation (slug: `visible-delegation`)

**Purpose:** One agent orchestrates another while keeping the delegated work visible — running the delegate in a shared terminal session (**tmux is the proven mechanism**) instead of a hidden background process, so the human can watch, interrupt, and course-correct in real time. Covers launching the delegate with a packaged goal prompt, monitoring progress, when the orchestrator should intervene vs wait, and how results get verified on the way back.

**Why build it:** "Multi-agent setups usually fail on supervision: hidden background agents drift for twenty minutes before anyone notices." Keeping delegation observable preserves parallel-agent leverage without surrendering oversight; watching a delegated session go wrong is also how you learn to write better goal prompts. Explicit pairing: "Pairs directly with Goal Prompt Generator: one packages the work, this runs and supervises it."

**What you need:** tmux (or equivalent) · Two agent harnesses or two sessions of one.

**Setup-prompt pre-work:** Before writing the skill, check tmux is installed (install if not) and confirm which agent CLI(s) the user uses for delegate sessions.

**Setup-prompt required contents:**
1. Trigger conditions — when the user asks to delegate, run something in parallel, or hand work to another agent.
2. Launch procedure: create a **named tmux session**, start the delegate agent in it, and pass a goal prompt (built with the `goal-prompt-generator` skill if available) — then tell the user how to attach and watch.
3. Monitoring rules: check the session at sensible intervals; define what warrants intervention (**stuck loops, scope drift, destructive commands**) versus patience.
4. Results protocol: when the delegate claims completion, **the orchestrator runs the verification gates from the goal prompt itself** before reporting success to the user.
5. Cleanup — "sessions get closed, not abandoned."

**Test step:** "test it by delegating one small real task end to end while I watch."

---

## Skill 3: Session Operating Map (slug: `session-operating-map`)

**Purpose:** Set up and maintain a per-project map of parallel agent sessions: which session/thread owns which lane of work, naming conventions so lanes are identifiable at a glance, where coordination state lives (a small repo-local map file), how blockers between lanes get recorded, and rules for archiving finished lanes and promoting durable lessons into the project's docs or skills. "It's the answer to 'which conversation was that in?'"

**Why build it:** "Past two or three concurrent sessions on a project, you become the bottleneck — re-explaining state, losing decisions in closed threads, duplicating work across lanes." A repo-local operating map externalizes coordination so any session (or any future you) can read what's in flight, what's blocked, what's been decided. "This is project management for agent work, kept lightweight enough to actually maintain."

**What you need:** Nothing; matters once you run multiple concurrent sessions per project.

**Setup-prompt required contents:**
1. Trigger conditions — when the user starts parallel workstreams in a project, asks "what's in flight," or asks to set up coordination for a repo.
2. The map file: a **single repo-local doc (suggested path: `docs/operating-map.md`)** listing each lane with: a short name, its objective, owning session, current state, and blockers.
3. Lane discipline: **one lane per concern**, named so its purpose is obvious.
4. Update rules: a lane's entry gets updated when its state **meaningfully changes — start, block, handoff, done — not as a journal**.
5. Archive rules: finished lanes move to a **done section with a one-line outcome**; lessons worth keeping get **promoted into the project's docs or skills** rather than dying with the lane.
6. **Read-first rule:** any session joining the project reads the map before starting work.

**Test step:** "set up the map for my current project and populate it with what's actually in flight."

**Lane record schema (implied by item 2):** { short name, objective, owning session, current state, blockers } + a Done section entries { lane, one-line outcome }.
**Lane state vocabulary (from update rule):** start / block(ed) / handoff / done.

---

## Skill 4: Self-Authored PR Merge (slug: `self-pr-merge`)

**Purpose:** A clean workflow for reviewing and merging PRs you authored yourself — the daily reality of solo developers and agent-heavy workflows, which GitHub's approval model doesn't accommodate (**you can't approve your own PR**). Runs a genuine self-review pass (diff inspection with fresh eyes, not a rubber stamp), checks CI status and mergeability, merges with the right strategy, and finishes with branch and **worktree-safe** cleanup.

**Why build it:** "Solo shipping needs more review discipline, not less — nobody else is going to catch the bug." Encoding review-check-merge-cleanup as a skill means it happens the same way every time, including the steps skipped when moving fast (actually reading the diff; actually deleting the branch). It handles GitHub's self-approval friction "honestly instead of pretending the approval model works differently than it does."

**What you need:** The GitHub CLI (`gh`) authenticated.

**Setup-prompt pre-work:** Confirm the gh CLI is authenticated; ask the user for merge strategy preference (**squash, merge, rebase**) and branch cleanup preference.

**Setup-prompt required contents:**
1. Trigger conditions — when the user asks to merge their own PR or review-and-merge something they wrote.
2. A genuine review pass FIRST: read the full diff with fresh eyes, list anything questionable (**bugs, debug leftovers, missing tests, scope creep**) and show findings before merging — verbatim rule: "finding nothing must be a conclusion, never a default."
3. Pre-merge checks: CI status, mergeability, conflicts, and "an honest note about the self-approval limitation rather than working around it."
4. The merge with the user's preferred strategy.
5. Cleanup: delete the remote branch per preference; if local worktrees are involved, use **worktree-safe removal — never plain branch deletion under a worktree**.
6. Stop rule: **any failing check or unresolved review finding halts the merge** and comes back to the user.

**Test step:** "test it on my next real PR."

---

## Skill 5: Stakeholder Update Email (slug: `stakeholder-update-email`)

**Purpose:** After work ships, send (or draft) a short, truthful update email to the person who needs to know — a client, a producer, a collaborator, the team. Encoded discipline: updates go out only when something stakeholder-visible actually changed; the email describes shipped behavior in the recipient's vocabulary, not implementation details; nothing unverified gets called done; consistent format (what changed, what it means for you, what's next); user is CC'd or shown a draft first, per preference.

**Why build it:** "Communication is the half of client and team work that agent workflows usually drop." The skill's real content isn't email mechanics — it's the rules: **only when shipped, only what's true, only in their language**. "A consistent, honest update cadence after real changes builds more trust than any amount of polish."

**What you need:** An email path the agent can use — a sending API like **Resend**, your mail provider's API, or just draft-for-you mode (no setup at all).

**Setup-prompt pre-work (interview the user for):** who the recurring stakeholders are and what each cares about; whether to send directly (and through what — e.g. a **Resend API key in an env file**) or always draft for review; whether the user should be CC'd on sends.

**Setup-prompt required contents:**
1. Trigger conditions — when work merges or ships with visible impact for a stakeholder, or when the user asks for an update email.
2. A gate: **if nothing stakeholder-visible changed, say so and send nothing**.
3. Writing rules: describe shipped behavior in the recipient's vocabulary, not implementation detail; **never call anything done that wasn't verified**; if something shipped partially, say which part.
4. Consistent short format: **what changed / what it means for them / what's next**.
5. Send/draft mechanics per preference, with **send requiring explicit user confirmation**.

**Test step:** "test it by drafting an update for the most recent thing I shipped."

---

## Skill 6: Session-to-Skill Extractor (slug: `session-to-skill-extractor`)

**Purpose:** A continuous-learning loop for the skill library: at the end of substantial work sessions, review what happened and ask whether any pattern is worth preserving — a workflow you'd repeat, a hard-won API discovery, a debugging path, a decision procedure. When a pattern clears the bar, draft a new skill or update an existing one, then file it for user review. "Your library grows out of your actual work instead of requiring dedicated authoring time."

**Why build it:** "Every skill in this library started as a session where someone solved a problem and refused to let the solution evaporate. This skill automates that refusal." High bar built in — most sessions yield nothing, and that's correct. "Six months of extraction produces a library shaped precisely like your work. This is the skill that makes all the other skills self-multiplying."

**What you need:** Nothing — "except a willingness to review what it proposes rather than auto-accepting."

**Setup-prompt required contents:**
1. Trigger conditions — when the user says **"wrap up"**, **"anything worth keeping?"**, or at the natural end of a session where something was solved non-trivially.
2. High extraction bar, stated explicitly; the pattern must be (exact vocabulary, capitalized in source):
   - **RECURRING** (I'll plausibly need it again),
   - **NON-OBVIOUS** (a fresh session wouldn't just derive it),
   - **CODIFIABLE** (it can be written as a procedure)
   — most sessions yield nothing, and "nothing worth extracting" is a good answer.
3. Check against the existing skill library first: **if an existing skill covers 80% of the pattern, propose an update, not a new skill**.
4. Drafts follow the user's skills' standard format **with trigger conditions**, and land somewhere for review — **never silently into the live library**.
5. Sanitize rule: extracted skills **generalize the pattern and strip project/client specifics, which stay in repo-local runbooks**.

**Test step (self-referential):** "test it on THIS session: evaluate whether our setup work contains an extractable pattern, and show me your reasoning either way."

---

## Skill 7: Agentic Harness Designer (slug: `agentic-harness-designer`)

**Purpose:** A design-review skill for building agent-powered products/systems. When the problem is "how should this AI system actually work," it walks the real architecture questions — tool-use design, permission and approval models, workflow state and durability, context and memory strategy, evaluation approach, observability, and operator visibility — and produces a phased implementation plan. Core principle: most "AI product" problems are **agent-system problems: the model matters less than the harness around it**.

**Why build it:** "the failure modes live in the harness: missing approval gates, no durable state, no evaluation plan, no way to see what the agent did. A skill that forces those questions in order, every time, is the difference between a demo and a system." Called "the most conceptual skill in the library, and for builders, frequently the most valuable."

**What you need:** Nothing.

**Setup-prompt required contents:**
1. Trigger conditions — designing, evaluating, or debugging any AI-agent-powered product, tool, or serious automation.
2. The **design walk, in order** (fixed sequence):
   1. what tools the agent gets and their **exact contracts**;
   2. the permission model (**what's autonomous, what needs approval, what's forbidden**);
   3. workflow state and durability (**what survives a crash or restart**);
   4. context and memory strategy (**what the agent knows, from where, and what it must not accumulate**);
   5. evaluation (**how we'll know it works — concrete checks, not vibes**);
   6. observability (**what's logged, what the operator can see mid-run**).
3. Failure-mode review against the common killers (exact list): **missing approval gates, non-durable state, unbounded context growth, no evals, invisible execution**.
4. Output: a **design doc with decisions and rationale**, plus a **phased implementation plan where each phase is independently shippable and testable**.

**Test step:** "test it by reviewing an agent system or automation I describe — or one we've already built — and showing me the design doc."

---

## Cross-cutting conventions for rebuilding this system

- **Skill storage:** every prompt says "stored wherever my harness loads skills from" — location is harness-agnostic by design.
- **Skill anatomy convention:** every skill definition must contain trigger conditions as its first element; procedures/rules follow; skills are tested immediately on a real task after authoring.
- **Prompt packaging format:** setup prompts are wrapped in `<prompt><task>...</task></prompt>`.
- **Skill-to-skill composition:** visible-delegation explicitly consumes goal-prompt-generator output; session-to-skill-extractor feeds the library that all others live in; session-operating-map's archive rule promotes lessons "into the project's docs or skills" (i.e., feeds the same library).
- **Verification-first ethos across the category:** goal prompts carry verification gates; delegation re-runs those gates orchestrator-side; PR merge halts on failing checks; stakeholder email never claims unverified work; harness designer demands concrete evals. Nothing is claimed done without a checked gate.
- **Human-in-the-loop boundaries:** delegate work stays visible (tmux, attachable); drafts land for review, never silently into the live library; email sends require explicit confirmation; merges halt on findings; stop conditions make agents ask instead of improvise.
- **Separation of generic vs specific knowledge:** generalizable patterns → skills; project/client specifics → repo-local runbooks; coordination state → repo-local `docs/operating-map.md`.

## Relevance mapping to a queue-runner design (interpretive, flagged as such)

- Goal Prompt Generator = the job-specification schema for queued tasks (objective, DEFINITION OF DONE checklist, allowed/forbidden paths, verification-gate commands + expected results, stop conditions, self-containment).
- Visible Delegation = worker execution model (named observable sessions, monitoring intervals, intervention triggers: stuck loops / scope drift / destructive commands, orchestrator-side gate re-verification, mandatory cleanup).
- Session Operating Map = the queue's shared state file (lanes = jobs; states start/blocked/handoff/done; done-section archival; read-first on join).
- Self-Authored PR Merge = the merge/landing stage with halt-on-failure semantics.
- Stakeholder Update Email = the notification stage with a "only if visible change" gate.
- Session-to-Skill Extractor = post-run learning pass with RECURRING/NON-OBVIOUS/CODIFIABLE bar.
- Agentic Harness Designer = the design checklist to review the queue runner itself.

## OPEN QUESTIONS

1. The page references "runbook compositions" as a follow-on page ("continue into runbook compositions") — its content is not in this source; unknown how these 7 skills compose into named runbooks.
2. "What you need" for Visible Delegation says "tmux (or equivalent)" but names no equivalent; the acceptable non-tmux mechanisms are unspecified.
3. The map file path `docs/operating-map.md` is explicitly only a suggestion ("suggest docs/operating-map.md"); no mandated schema/markdown layout for the file beyond the field list.
4. "sensible intervals" for delegation monitoring is never quantified.
5. The "80% coverage" threshold for update-vs-new-skill in the Extractor has no stated measurement method.
6. The skills' "standard format" referenced by the Extractor (item 4) is assumed to exist per-user; the page never defines a canonical skill file format beyond "trigger conditions" being required.
7. No versioning, review-queue location, or approval workflow is specified for where extractor drafts "land somewhere for my review."
