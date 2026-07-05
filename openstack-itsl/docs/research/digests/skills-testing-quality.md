# Digest: Open Skills — Testing & Quality (Unlock AI, Nate B. Jones)

Source: `open-skills__testing-quality.md` (scrape of https://unlock-ai.natebjones.com/open-skills/testing-quality)
Site branding: "Unlock AI by Nate B. Jones". Site nav sections: Open Engine, Guides, Open Skills, Benchmarks, Image Arena.

## Category overview

- Category name: **Testing & Quality** — part of a larger "Open Skills" directory (link back: "Skills directory"; onward link: "runbook compositions").
- Category tagline (verbatim): "Skills that make agent-built work trustworthy: repeatable QA, browser evidence, repo-local testing memory, and the habit of leaving verification knowledge where the next session can use it."
- Usage model: the page is a "triage menu". Each entry is not a downloadable skill file but a **copy-paste setup prompt** the user gives their own AI coding agent; the agent then writes/installs the skill adapted to the user's tools, files, accounts, and standards ("The prompt is the starting point; the installed skill should reflect your real workflow").
- Installation rule (verbatim): "Install the primitive only when you can name the workflow it will improve."
- Recommended order: if building the lane from scratch, start with **Testing Runbook Creator**; otherwise jump to whichever skill matches today's bottleneck.
- Skill count: 3 — Testing Runbook Creator, Page Testing Memory, Browser Automation QA.
- Stack framing (from skill 3's "Why"): the three form a complete QA stack — **process** (Page Testing Memory) + **institutional memory** (Testing Runbook Creator) + **instrumentation** (Browser Automation QA).
- Prompt format convention: every setup prompt is wrapped in `<prompt><task> ... </task></prompt>` XML tokens, shown in a fenced code block. Each prompt ends with a built-in acceptance test ("After writing it, test it by ...").
- Standard prompt phrasing for install location (verbatim, appears in all three): "stored wherever my harness loads skills from."

---

## Skill 1: Testing Runbook Creator

- **Installed skill name (exact):** `testing-runbook-creator`
- **Purpose:** Enforce one rule — "testing discoveries must not die in chat." Whenever the agent tests/QAs/smoke-tests/verifies anything in a repo, it must leave behind a repo-local runbook entry. The runbook lives in the repo, accumulates over time, and every future agent session reads it before re-testing.
- **Why build it (rationale):** Without it, every session rediscovers the app from scratch (which test account, which route, which actions are safe) and discoveries evaporate at session end. With it, testing knowledge compounds — "session twenty inherits everything sessions one through nineteen learned." Called "the single highest-leverage habit-skill in the library" and "the purest expression of the principle that agent work should leave durable artifacts."
- **Prerequisites ("What you need"):** Nothing. Works in any repo from day one.
- **Triggers (from the prompt, requirement 1):** ANY testing or verification activity in a repo — test, verify, smoke-test, QA, or debug — not just when the user says "runbook".
- **Runbook location (requirement 2):** a standard repo-local file; suggested default `docs/testing-runbook.md` "or similar".
- **Entry format (requirement 2, exact fields):**
  1. the page/workflow/feature
  2. how to test it step by step
  3. safe actions vs. destructive actions
  4. setup/seed requirements
  5. cleanup steps
  6. exact verification commands **with expected output**
- **Behavioral rules (requirements 3–5):**
  - **Read-first rule:** before testing anything, check whether the runbook already covers it and follow the existing recipe.
  - **Update rule:** when reality differs from the runbook, fix the runbook in the same session.
  - **Record-as-you-go rule:** record discoveries as you go, not as an end-of-session afterthought.
- **Verification/acceptance step (in prompt):** after writing the skill, test it by smoke-testing one workflow in a project the user points to and showing the runbook entry it produces.
- **Boundaries:** repo-local knowledge only — the runbook is a per-repo artifact, not global.

### Full setup prompt (verbatim)

```
<prompt>
  <task>
    Create a new skill for my AI coding agent called "testing-runbook-creator", stored
wherever my harness loads skills from.

The skill's job: whenever you test, verify, smoke-test, QA, or debug anything in a
repo, capture what you learned as a repo-local runbook entry so future sessions don't
rediscover it.

The skill must include: (1) trigger conditions — ANY testing or verification activity
in a repo, not just when I say "runbook"; (2) a standard runbook location (suggest
docs/testing-runbook.md or similar) and entry format: the page/workflow/feature, how
to test it step by step, safe actions vs. destructive actions, setup/seed
requirements, cleanup steps, and exact verification commands with expected output;
(3) a read-first rule: before testing anything, check whether the runbook already
covers it and follow the existing recipe; (4) an update rule: when reality differs
from the runbook, fix the runbook in the same session; (5) a rule to record
discoveries as you go, not as an end-of-session afterthought.

After writing it, test it by smoke-testing one workflow in a project I point you to
and showing me the runbook entry it produces.
  </task>
</prompt>
```

---

## Skill 2: Page Testing Memory

- **Installed skill name (exact):** `page-testing-memory`
- **Purpose:** The "architectural partner" to Testing Runbook Creator. Encodes a knowledge split: the **global skill** teaches the page-QA *process* (how to approach testing any web page — routes, states, forms, auth, responsive checks), while **page-specific facts** (selectors, test accounts, magic URLs, cleanup quirks) belong in repo-local runbooks. Teaches the agent which knowledge goes where, keeping global skills lean/portable and repo knowledge with the repo.
- **Why build it (rationale):** The most common failure mode in a growing skill library is global skills bloated with project specifics (e.g., selectors from one client's app baked into a skill loaded in every session). This skill is the antidote; the **global-process / local-facts split** it teaches generalizes to the whole skill library. "It's a skill about how to structure skills, disguised as a QA skill."
- **Prerequisites ("What you need"):** Testing Runbook Creator — "they're designed as a pair." The prompt explicitly says "It partners with my testing-runbook-creator skill."
- **Triggers (requirement 1):** QA or verification of any web page or UI.
- **General QA process taught (requirement 2, exact checklist):**
  1. identify the page's states: **empty, loaded, error, loading**
  2. test forms with **valid / invalid / edge** input
  3. verify **auth boundaries**
  4. check **responsive behavior at standard breakpoints**
  5. **capture screenshots as evidence**
- **Knowledge split, stated explicitly (requirement 3):** process lives in this (global) skill; **selectors, routes, test accounts, seed data, and cleanup quirks** live in the repo's testing runbook — "never in this skill."
- **Immediate-write rule + self-check heuristic (requirement 4):** when the agent learns a page-specific fact during QA, it goes into the repo runbook **immediately**; and "if you find yourself wanting to add a project detail to THIS skill, that's the signal it belongs in the repo instead."
- **Verification/acceptance step (in prompt):** after writing, test it by QAing one page in a project the user chooses, showing **both** the QA findings **and** what got written to the repo runbook.
- **Boundaries:** absolute prohibition on project-specific data in the global skill.

### Full setup prompt (verbatim)

```
<prompt>
  <task>
    Create a new skill for my AI coding agent called "page-testing-memory", stored
wherever my harness loads skills from. It partners with my testing-runbook-creator
skill.

The skill's job: teach the general page-QA process globally, while keeping all
page-specific knowledge in repo-local runbooks — never in this skill.

The skill must include: (1) trigger conditions — QA or verification of any web page or
UI; (2) the general process: identify the page's states (empty, loaded, error,
loading), test forms with valid/invalid/edge input, verify auth boundaries, check
responsive behavior at standard breakpoints, capture screenshots as evidence; (3) the
knowledge split, stated explicitly: process lives here; selectors, routes, test
accounts, seed data, and cleanup quirks live in the repo's testing runbook; (4) a rule
that when you learn a page-specific fact during QA, it goes into the repo runbook
immediately — and if you find yourself wanting to add a project detail to THIS skill,
that's the signal it belongs in the repo instead.

After writing it, test it by QAing one page in a project I choose, and show me both
the QA findings and what got written to the repo runbook.
  </task>
</prompt>
```

---

## Skill 3: Browser Automation QA

- **Installed skill name (exact):** `browser-qa`
- **Purpose:** Professional-grade web testing through browser automation. The stated "proven route" is the **Chrome DevTools Protocol exposed to agents via MCP**. Capabilities: performance traces and **Core Web Vitals measurement (LCP, INP, CLS)**, network request monitoring, console error capture, device emulation for responsive testing, accessibility checks, and scripted multi-page workflows — with screenshots and metrics as evidence. The skill encodes which checks to run for which kind of change, and what "passing" means for the user's projects.
- **Why build it (rationale):** "'It looks fine' is not verification." Upgrades the agent from eyeballing to measuring; because it produces evidence (metrics, screenshots, console output), reports can be trusted without re-checking. Completes the QA stack with the other two skills (instrumentation layer).
- **Prerequisites ("What you need"):** Chrome plus a **DevTools MCP server** connected to the harness. The agent can set this up itself — that is Step 1 of the prompt.
- **Setup Step 1 (in prompt):** set up a Chrome DevTools MCP server for the harness so the agent can drive a real browser — navigate, screenshot, read console and network activity, run performance traces, and emulate devices. The agent must walk the user through installation steps it can't do itself, and **verify the connection works before writing the skill**.
- **Triggers (Step 2, requirement 1):** when the user asks to verify a web change, check performance, audit a page, or test responsive behavior.
- **Check recipes by change type (requirement 2, exact mapping):**
  - **Layout changes** → screenshots at **desktop / tablet / mobile** breakpoints
  - **Performance-relevant changes** → a trace with Core Web Vitals (**LCP, INP, CLS**) **against stated thresholds**
  - **New features** → **console-error and failed-request checks during a scripted walkthrough**
- **Evidence rule (requirement 3):** every finding ships with its screenshot, metric, or log excerpt — "no unevidenced 'looks fine'."
- **Report format (requirement 4, standard short report):** what was checked; what passed **with evidence**; what failed **with reproduction steps**.
- **Integration rule (requirement 5):** findings about *how to test a page* get written to the repo's testing runbook per the `testing-runbook-creator` skill.
- **Verification/acceptance step (in prompt):** after writing, test it by auditing one live page of the user's and showing the report.
- **Tools:** Chrome browser; Chrome DevTools Protocol via an MCP server; screenshot/trace/console/network/device-emulation capabilities of that server.
- **Boundaries:** evidence-backed claims only; thresholds must be "stated" (agreed) for CWV pass/fail; page-testing know-how discovered during audits flows to the repo runbook, not into this skill.

### Full setup prompt (verbatim)

```
<prompt>
  <task>
    Set up browser automation QA for my AI coding agent, then create a skill called
"browser-qa" that uses it, stored wherever my harness loads skills from.

Step 1: Set up a Chrome DevTools MCP server for my harness so you can drive a real
browser — navigate, screenshot, read console and network activity, run performance
traces, and emulate devices. Walk me through any installation steps you can't do
yourself, and verify the connection works before writing the skill.

Step 2: The skill must include: (1) trigger conditions — when I ask to verify a web
change, check performance, audit a page, or test responsive behavior; (2) check
recipes by change type: layout changes get screenshots at desktop/tablet/mobile
breakpoints; performance-relevant changes get a trace with Core Web Vitals (LCP, INP,
CLS) against stated thresholds; new features get console-error and failed-request
checks during a scripted walkthrough; (3) an evidence rule: every finding ships with
its screenshot, metric, or log excerpt — no unevidenced "looks fine"; (4) a standard
short report format: what was checked, what passed with evidence, what failed with
reproduction steps; (5) integration: findings about how to test a page get written to
the repo's testing runbook per my testing-runbook-creator skill.

After writing it, test it by auditing one live page of mine and showing me the report.
  </task>
</prompt>
```

---

## Cross-skill relationships and conventions (summary)

| Skill | Installed name | Role in stack | Depends on | Knowledge location |
|---|---|---|---|---|
| Testing Runbook Creator | `testing-runbook-creator` | Institutional memory | none | repo-local runbook (suggested `docs/testing-runbook.md`) |
| Page Testing Memory | `page-testing-memory` | Process | `testing-runbook-creator` (designed as a pair) | global skill = process only; repo runbook = facts |
| Browser Automation QA | `browser-qa` | Instrumentation | Chrome + DevTools MCP server; integrates with `testing-runbook-creator` | evidence in reports; test-how-to facts → repo runbook |

Shared conventions across all three prompts:
- Skill install location phrase: "stored wherever my harness loads skills from."
- Prompt wrapper tokens: `<prompt><task>...</task></prompt>`.
- Numbered "The skill must include: (1) ... (2) ..." requirement lists, always starting with trigger conditions.
- Every prompt ends with a live acceptance test on a real project of the user's ("After writing it, test it by ...").
- Recurring design principles: agent work should leave durable artifacts; global-process vs. repo-local-facts split; evidence over assertion; read-before-test, update-when-wrong, record-as-you-go.

## OPEN QUESTIONS

1. The page never specifies an exact runbook file schema beyond the six entry fields — heading structure, ordering, and markdown formatting of `docs/testing-runbook.md` are left to the installing agent.
2. "Stated thresholds" for Core Web Vitals are referenced but no numeric values are given (no LCP/INP/CLS targets); the user is expected to supply them.
3. No specific Chrome DevTools MCP server implementation is named (e.g., `chrome-devtools-mcp`); "your agent can set this up" is the only guidance.
4. "Runbook compositions" is referenced as a follow-on page but its content is not in this source.
5. The page mentions "accessibility checks" in Browser Automation QA's description, but the setup prompt itself contains no accessibility requirement — the description is broader than the prompt.
6. The scrape includes no author-provided skill file contents — all three skills exist only as generation prompts; final skill text depends on the installing agent.
