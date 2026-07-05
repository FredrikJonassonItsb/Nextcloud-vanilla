# DIGEST: The Agent Maintenance Loop (Unlock AI / Nate B. Jones)

Source: `guides__agents__maintenance.md` — scraped from https://unlock-ai.natebjones.com/guides/agents/maintenance
Page metadata: "June 16, 2026 Last updated · 6 steps Maintenance loop · 1 prompt Full-loop audit"
Author/site: "Unlock AI by Nate B. Jones"

---

## 1. WHAT THE GUIDE IS

The Agent Maintenance Loop is a **repeatable inspection pass for agents that have moved from experiment into real work**. It audits recent runs, identifies which surface of the harness is drifting, and ends with one of four written decisions — **keep, change, pause, or retire** — before the next production cycle.

Page section structure (as listed in the on-page nav): `Harness · Run it when · Job · Last 10 · 7 surfaces · Replay pack · Delete first · Decision · Examples · Prompt`

Numbered sections in the source:
- 01 / Harness — what you are maintaining
- 02 / Trigger — when to run the loop
- 03 / Step 1 — Name the current job
- 04 / Step 2 — Check the last ten runs
- 05 / Step 3 — Inspect the seven surfaces
- 06 / Step 4 — Build a replay pack
- 07 / Step 5 — Delete before you add
- 08 / Step 6 — Decide keep, change, pause, or retire
- 09 / Examples — apply the loop to real agent types
- 10 / Prompt — run the maintenance prompt (single paste-in audit)

---

## 2. SECTION 01 — THE HARNESS (what you are maintaining)

**Core thesis: "You are not maintaining a prompt. You are maintaining the whole harness around delegated work."**

Definition of **harness** — everything that turns a model into a worker:
1. The instructions
2. The sources and examples it reads
3. The memory it carries between runs
4. The tools it can call
5. The permissions it has
6. The model and its settings
7. The human review before its output is used
8. Any evals that check it

Key diagnostic reframe: a drifting agent usually still *sounds fluent*, so the useful question is not "is this output well-written" but **"is this fluent output still doing the current job."** You can only answer that by looking at the whole harness.

Human/AI split for this section:
- **You do:** Write down the concrete parts of this agent's harness as they exist now: which instructions, which sources, which tools, who reviews it, and what it is allowed to do.
- **The AI does:** Inventory the harness from its config and docs, and flag any part you cannot point to: an unnamed source, an unclear review step, a tool nobody remembers granting.

### The seven surfaces (vocabulary — verbatim names)

Each surface is a place an agent can quietly drift; together they cover the whole system. Running example throughout the guide: a **refund-reply agent**.

| Surface | Definition (with refund-agent example) |
|---|---|
| **Job** | The one sentence of work it owns, like "draft refund replies for billing tickets." |
| **Diet** | What you feed it each run: the refund policy, past approved replies, and the ticket it is answering. |
| **Memory** | What it carries between runs, like the saved fact "this account is on the legacy plan." |
| **Tools** | The actions it can take: search the policy, draft a reply, tag the ticket. |
| **Reach** | What it can do without a human. Here it can draft only; it cannot send the reply or issue the refund. |
| **Proof** | The evidence it shows, like the policy clause it cited, so a human can check the work instead of trusting it. |
| **Value** | Whether the drafts actually get sent, or get rewritten from scratch every time. |

Rule: before starting, confirm you can fill in all seven for your agent. **"If you cannot name one of the seven for your agent, that gap is already a finding. Write it down and keep going."**

---

## 3. SECTION 02 — TRIGGER (when to run the loop)

**Cadence rule: run the loop when something changes — NOT on a schedule and NOT only when something breaks.** ("You do not audit on a calendar. You run the loop when a trigger fires.")

### The four trigger families (any single one is reason enough)

1. **Upstream change.** Something the agent depends on moved: a new model version, a changed tool or connector, or an updated source of truth like a revised policy.
2. **Scope creep.** The agent is being used beyond its original job, or it keeps asking for more access to keep up.
3. **Rising human cost.** People keep fixing the same thing, review takes longer than the work it saves, or cost and latency have climbed.
4. **Quiet failure.** A near miss that almost shipped, or output that nobody uses anymore.

### Scoping rule: "Start with the smallest loop that catches the drift"

- Do NOT inspect everything every time. Run the loop against **one agent and one signal** (the single trigger that brought you here).
- Goal: find what changed **while the failure is still small enough to fix in one pass** — not to launch a governance review.
- If the trigger touches several agents or surfaces: **finish this pass first, then repeat the loop for the next one.**

Human/AI split:
- **You do:** Pick one agent and the one trigger. Write the trigger in a sentence; it is the lead you follow through Steps 1–3.
- **The AI does:** Restate that trigger as a **specific, checkable question**, e.g. "did the June 1 policy update break the refund agent's citations?", so the inspection stays pointed.

---

## 4. SECTION 03 — STEP 1: NAME THE CURRENT JOB

Write the job the agent does **today**, not the one it launched with. The job sentence is the anchor every later step is judged against.

### The five-part job-sentence template (verbatim token template)

```
This agent's job is to [produce this work] from [these sources] for [these users], with [this human review] before [this consequence].
```

The five things it must name: (1) the work it produces, (2) the sources it uses, (3) the user it serves, (4) the human review in the path, (5) the consequence of its output.

Failure-as-finding rule: **if you cannot complete the sentence, that is your first finding** — the sources are vague, or there is no clear review step, or you cannot name the consequence. "A job you cannot state in one sentence is a job the agent cannot reliably hold."

The guide includes a copyable prompt for this step (verbatim):

```
<prompt>
  <context>
    The job sentence anchors every later maintenance step.
  </context>
  <task>
    Complete the sentence naming all five required parts.
  </task>
  <deliverables>
    <deliverable>This agent's job is to [produce this work] from [these sources] for [these users], with [this human review] before [this consequence].</deliverable>
  </deliverables>
</prompt>
```

### Narrowness rule: "Keep the job narrow enough to maintain"

A narrow job is one you can actually check the agent against; a broad one gives drift room to hide. Verbatim contrast pairs:

- **Maintainable:** "Draft refund replies for billing tickets under $100, from the refund policy, for a support agent to approve before sending."
- **Not maintainable:** "Handle support."
- **Maintainable:** "Prepare first-pass backlog packets for the product team to refine."
- **Not maintainable:** "Help product."

If your sentence reads like the broad versions, narrow it NOW. You can run a second loop for a second job, but **each loop needs one clear job to test against.**

---

## 5. SECTION 04 — STEP 2: CHECK THE LAST TEN RUNS

"Do not judge the agent in the abstract. Read what it actually did, and find what humans keep fixing."

- Pull the agent's recent real runs — **ten is a guide, not a rule** — and read them against the job sentence from Step 1.
- This step is **evidence gathering only** — do not fix anything yet.

### Per-run scoring questions (same questions for every run)

For each run, answer:
1. Was the output used, or rewritten or dropped?
2. What did the human change, and why?
3. Which source did the agent rely on?
4. Which tool did it call?
5. What did it say it could not verify?
6. Where did reviewing it take longer than expected?

Recording format: capture answers in a **simple list**. "You do not need a dashboard; a column of notes is enough to see the pattern."

### The repetition threshold rule

- **A one-off fix is noise. The same correction across three or more (3+) runs is signal:** the harness is teaching that mistake, and editing individual outputs will never end it.
- When you spot a repeated correction, **do not fix it yet**. Name the pattern in a few words — examples given: "cites the old refund threshold", "invents a ticket field" — and carry each one into Step 3 to find which surface produces it.

---

## 6. SECTION 05 — STEP 3: INSPECT THE SEVEN SURFACES

Take each repeated problem to the surface behind it. **Walk the seven in a fixed order** (Job → Diet → Memory → Tools → Reach → Proof → Value) "so you fix the cause, not the symptom" — the order keeps you from "piling new instructions onto a problem that lives somewhere else."

For each surface: ask its question, look for its symptom in the runs from Step 2, note the likely fix.

### Surface-by-surface checklist (question / broken-when / fix)

**Job**
- Ask: has the work quietly grown past the sentence from Step 1?
- Broken when: runs include tasks that sentence never mentioned.
- Fix: re-narrow the job, or split the new work into its own agent.

**Diet**
- Ask: is everything it reads still current and correct?
- Broken when: the agent cites an old policy, leans on a stale example, or retrieves the wrong document.
- Fix: **update or repoint the sources, NOT adding a rule that says "use the latest version."**

**Memory**
- Ask: is it carrying a fact that is no longer true?
- Broken when: an outdated saved assumption shows up in current work.
- Fix: clear or correct the stored memory.

**Tools**
- Ask: can it reach the right action without tripping over the wrong one?
- Broken when: the toolset is so broad or overlapping that the agent picks a wrong or unsafe tool.
- Fix: **remove the tools it does not need for this job.**

**Reach**
- Ask: can it touch more than its owner can review?
- Broken when: its power to send, spend, change, or publish outruns the human's ability to catch a mistake.
- Fix: narrow reach **until every risky action passes a person.**

**Proof**
- Ask: can a human check the work, or only trust it?
- Broken when: output looks finished but shows no sources, no reasoning, and no way to verify.
- Fix: require it to cite or show its work.

**Value**
- Ask: does anyone act on the output?
- Broken when: the work is plausible but ignored — rewritten, skipped, or filed unread.
- Fix: **change the job or retire the agent. More polish will not help.**

Output of Step 3: a list of `(surface, problem, likely fix)` triples. **Do NOT apply the fixes yet** — build the replay pack in Step 4 first, so you can prove each one works.

---

## 7. SECTION 06 — STEP 4: BUILD A REPLAY PACK

Definition: **a small, fixed set of cases with known-right answers — your before-and-after test for any change.**

Timing rule: assemble the pack **before you change anything**. Run it now to confirm the Step 3 problems (baseline), and again in Step 5 to confirm fixes helped without breaking something else.

### Case selection rules

- A replay case = an input where you already know how the agent should behave, **so any deviation is obviously wrong**.
- Size: **5 to 20 cases** — "enough to cover the ways this agent matters, few enough to re-run by hand."
- Good cases come from **real history**. Verbatim example list:
  - support tickets with a known correct routing
  - old backlog packets where the product decision is settled
  - code changes with passing tests and files that must not be touched
  - research questions with a known source trap
  - drafts that previously came out in the wrong voice
  - **at least one high-risk case where the only correct move was to stop and escalate**
- Include the problems you found in Step 3 (e.g., if the agent cites an old policy, include a ticket that exposes exactly that).

### Scoring rule: "Score the run, not just the answer"

Do not only check whether the final answer is right — **check how it got there, because that is what predicts the next failure.** For every case ask:
1. Did it use the right source?
2. Did it choose the right tool?
3. Did it stay inside the job?
4. Did it show its proof?
5. Did it stop when it should have?
6. Would a human have spent less time reviewing it than doing the work themselves?

**Run the pack once now to get a baseline score. That is the number your fixes in Step 5 have to beat.**

---

## 8. SECTION 07 — STEP 5: DELETE BEFORE YOU ADD

Core principle: **"Most harnesses rot because every fix is one more instruction. Try subtraction first."** — "Most agent failures are caused by something that is already there, not something that is missing."

Procedure: for each Step 3 problem, try removing or narrowing before adding anything new. **Re-run the replay pack after each change**, keeping what helps and reverting what does not.

### The deletion questions (run each problem through these first)

1. Is a stale source feeding it?
2. Is a bad example teaching it?
3. Is a tool too broad?
4. Is the job too vague?
5. Is an old memory being replayed?
6. Is its reach higher than it needs?
7. Is proof missing?
8. Is the model now good enough that an old workaround is getting in the way?

A "yes" to any of these = **fix by deletion**: remove or correct that thing, then re-run the replay pack. Reach for a new instruction **only after the deletions are exhausted**.

### The addition-proof rule: "Add only what the replay pack proves you need"

- If a deletion or narrower scope fixes the behavior in the replay pack, **you are done**. Do not add a standing instruction on top "just because it feels safer" — every added rule is something the next maintainer must understand and the agent must weigh on every run.
- When you genuinely need to add something, **prove it: the replay pack should FAIL without it and PASS with it.** "If you cannot show that, you do not yet know the change is doing anything."

---

## 9. SECTION 08 — STEP 6: DECIDE (keep / change / pause / retire)

"End with **one written decision** and the evidence behind it, not a vague sense that the agent feels better." Back the call with the replay-pack result and the run evidence.

### The four outcomes (choose exactly one)

1. **Keep.** The agent still fits its job and the replay pack passes. Nothing changes but the next review date.
2. **Change.** You found and fixed specific surfaces. Record what you changed and the replay-pack result that backs it.
3. **Pause.** The agent is useful but currently unsafe or stale, and you cannot fix it in this pass. **Stop its risky actions until you can.**
4. **Retire.** The job changed, the value disappeared, or upkeep costs more than the agent saves. **Turn it off and reassign the work.**

### The maintenance record (schema — what to save so the next pass does not start from zero)

Capture:
1. The trigger that started this pass
2. The current job sentence
3. The run pattern you found
4. Which surfaces you changed
5. The replay-pack result
6. The decision
7. The condition that should trigger the next review

Storage rule: **"That record is part of the harness. Store it where the agent's config and docs live, not in a chat log you will lose."**

---

## 10. SECTION 09 — WORKED EXAMPLES (symptom → surface(s) → fix)

Read each as: symptom, then surface and why, then fix.

### Writing, content pipelines, and Codex
1. **Writing agent that sounds like an old version of you.** Drift = voice. Surfaces = **Diet + Proof** (stale examples; nothing flags an off-voice draft). Fix: refresh the voice examples it learns from, and add a check that catches off-voice drafts before they ship.
2. **Content pipeline that summarizes the video instead of building the article.** Surfaces = **Job + Value** (job slipped from "write the piece" to "recap the source", and nobody uses the recap). Fix: re-narrow the job sentence to the real deliverable, and stop scoring runs nobody publishes.
3. **Codex workflow that follows a stale ritual instead of the repo in front of it.** Surfaces = **Memory + Diet** (replaying old standing instructions; reading the wrong context). Fix: delete the outdated instructions, and make it read the current repo before it acts.

### Support, product, and revenue-risk agents
4. **Support agent citing old policy.** Surfaces = **Diet + Proof** (stale source; no citation to catch it). Fix: repoint at the current policy, and require a citation on every reply.
5. **Backlog agent overweighting one loud customer.** Surface = **Diet** (source-precedence problem: every input treated as equal weight). Fix: rank the sources so one noisy account cannot outvote the rest.
6. **Revenue-risk agent that cannot reconcile Stripe, Linear, and local desk state.** Surfaces = **Reach + Proof** (it can act across systems it cannot reliably verify). Fix: narrow what it is allowed to act on, and make it **stop and escalate when the sources disagree**.

---

## 11. SECTION 10 — THE FULL-LOOP AUDIT PROMPT (verbatim)

Usage rules:
- Use **after** you have named the job and skimmed recent runs yourself, so you can check the AI's reading against your own.
- Paste into a coding agent or AI workspace **that can see the harness**: its instructions, recent runs, source list, tool list, and review notes. "The more of the harness it can actually read, the better the audit."

Full prompt, verbatim:

```
<prompt>
  <task>
    You are helping me run an Agent Maintenance Loop on an existing agentic harness.

Goal:
Decide whether this agent is still fit for its current job, then recommend keep, change, pause, or retire, with evidence.

Work through these steps in order.

1. Name the current job
- Write one sentence:
  This agent's job is to [produce this work] from [these sources] for [these users], with [this human review] before [this consequence].
- If the job is vague, say so and propose a tighter version.

2. Check the last ten runs
- For each run: Was the output used, or changed or dropped? What did the human change? Which source did it rely on? Which tool did it call? What could it not verify? Where did review take too long?
- List any correction that repeats across three or more runs.

3. Inspect the seven surfaces
For each surface give a verdict (ok / drifting / broken), the evidence from the runs, and the fix:
- Job: has the work grown past the job sentence?
- Diet: are the sources, examples, or retrieved context stale or wrong?
- Memory: is an outdated saved fact being replayed?
- Tools: are tools too broad, overlapping, or risky?
- Reach: can it act beyond what its owner can review?
- Proof: does the output show evidence a human can check?
- Value: is the output actually used?

4. Build or revise the replay pack
- List 5 to 20 known cases, including ones that expose the problems above.
- For each, score source choice, tool choice, job fit, proof, review burden, and stop/escalate behavior.
- Give a baseline result before any change.

5. Delete before adding
- For each problem, name what to remove or narrow first: a stale source, a bad example, a broad tool, excess reach, a wrong memory, a vague job, or an old model workaround.
- Only propose a new instruction if the replay pack would fail without it.

6. Decide
- Return one decision: Keep, Change, Pause, or Retire.
- Include the evidence, the exact harness changes, the replay cases to re-run, and the condition that should trigger the next review.
  </task>
</prompt>
```

Note the per-surface verdict vocabulary embedded in the prompt: **ok / drifting / broken**.

---

## 12. CONDENSED OPERATING MODEL (rebuild-from-scratch summary)

- **Unit of maintenance:** the harness (instructions + sources/examples + memory + tools + permissions + model/settings + human review + evals), never just the prompt.
- **Inspection vocabulary:** 7 surfaces — Job, Diet, Memory, Tools, Reach, Proof, Value.
- **Cadence:** trigger-driven, not calendar-driven. 4 trigger families: Upstream change, Scope creep, Rising human cost, Quiet failure. One trigger is enough; scope each pass to one agent + one signal.
- **The 6-step loop:**
  1. Name the current job (5-part one-sentence template).
  2. Check the last ~10 runs (6 per-run questions; 3+ repeated corrections = harness signal).
  3. Inspect the 7 surfaces in fixed order; produce (surface, problem, likely fix) triples; verdicts ok/drifting/broken.
  4. Build a replay pack (5–20 known-answer cases from real history, incl. one stop-and-escalate case); score process (source, tool, job fit, proof, stop behavior, review burden), not just answers; take a baseline.
  5. Delete before you add (8 deletion questions); re-run replay pack after each change; any addition must fail-without/pass-with in the replay pack.
  6. Decide exactly one of Keep / Change / Pause / Retire; write the 7-field maintenance record and store it with the agent's config/docs.
- **Anti-patterns explicitly warned against:** editing the prompt as a reflex; adding a "use the latest version" rule instead of fixing sources; adding instructions because it "feels safer"; polishing output nobody uses; judging fluency instead of job fit; auditing on a calendar; launching a governance review instead of a one-pass fix; storing the maintenance record in a chat log.

---

## OPEN QUESTIONS

1. **Replay-pack scoring format is unspecified.** The guide says to "score" runs (baseline number that fixes "have to beat") but never defines a scale, rubric weighting, or pass/fail format — implementers must invent their own (e.g., pass/fail per question per case).
2. **"Last ten runs" sampling is loose by design** ("ten is a guide, not a rule") — no guidance on how to sample when an agent runs hundreds of times between triggers (most recent? random? failures-weighted?).
3. **No tooling/automation specified.** The loop is described as a manual, by-hand process ("few enough to re-run by hand", "a column of notes"); nothing about automating the replay pack as CI/evals, though "any evals that check it" is listed as a harness component.
4. **"Pause" mechanics undefined** — "stop its risky actions until you can" doesn't specify whether to disable the agent entirely, revoke specific tools/reach, or route all output to review.
5. **The masthead image and site nav (Open Engine, Guides, Open Skills, Benchmarks, Image Arena) are site chrome**, not loop content — excluded from the operational digest except as provenance.
6. **Relationship to a scheduled review date:** the Keep outcome mentions "nothing changes but the next review date", and the record includes "the condition that should trigger the next review" — slightly in tension with "not on a schedule". Best reading: reviews are trigger-conditioned, but Keep decisions still set an expiry/next-look condition; the guide does not reconcile this explicitly.
