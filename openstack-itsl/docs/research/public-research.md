# Nate B Jones's "Open Stack" — Synthesis of Public-Source Research

**Scope:** Everything below comes from public sources (Nate's GitHub, Substack, unlock-ai.natebjones.com, promptkit.natebjones.com, YouTube, and third-party coverage). Compiled 2026-07-04 from six independent research passes. Inferences and unresolved conflicts are flagged inline.

**Core thesis (his words):** "Rented intelligence on top, owned context underneath." You rent frontier models; you own the three layers beneath them:

> "Open Brain holds your memory, Open Skills holds your method, Open Engine moves the work."
> — Substack, "You can build 80% of your own AI memory by talking to the agent already on your computer" (2026-07-01), https://natesnewsletter.substack.com/p/build-your-own-ai-memory

**Naming caveat (resolved conflict):** Two researchers found no page where Nate brands the umbrella "Open Stack" (both `/open-stack` and `/open-brain` on unlock-ai 404; his own phrase is "an open personal agent stack"). However, a third researcher located the **"Open Stack Field Guide"** at https://unlock-ai.natebjones.com/guides/open-stack/open-stack-field-guide — so "Open Stack" *is* used by Nate, but only as a guide title under `/guides/`, not as a top-level product/nav item. There is no single "Open Stack" product; the three components are branded and shipped separately.

---

## 1. What each layer is

### 1.1 Open Brain (OB1) — the memory/context layer

- **What it is, verbatim:** "The infrastructure layer for your thinking. One database, one AI gateway, one chat channel — any AI plugs in. No middleware, no SaaS." (GitHub tagline, https://github.com/NateBJones-Projects/OB1) and "a database-backed, MCP-connected knowledge system you own outright, where any AI you use — Claude, ChatGPT, Cursor, whatever ships next month — can query your accumulated context through a single open protocol" (Substack launch post, https://natesnewsletter.substack.com/p/every-ai-you-use-forgets-you-heres).
- **Problem solved:** every AI platform keeps its own memory silo (vendor lock-in); Open Brain makes memory portable and vendor-neutral.
- **Architecture** (repo + setup guide, https://promptkit.natebjones.com/20260224_uq1_guide_main and https://github.com/NateBJones-Projects/OB1/blob/main/docs/01-getting-started.md):
  - Supabase-hosted **PostgreSQL + pgvector** (HNSW index); a `thoughts` table + `match_thoughts` function.
  - Each captured "thought" gets a **1536-dim embedding AND LLM-extracted metadata** (type, topics, people, action items, dates) generated **in parallel** via Supabase Edge Functions, in under ~10 seconds per capture.
  - **OpenRouter** as the universal AI gateway for embeddings/LLM calls.
  - An **MCP server** (implemented as a Supabase Edge Function) exposing roughly three retrieval tools — semantic search by meaning, recent-thoughts browsing, pattern statistics — plus capture; clients include Claude Desktop, Claude Code, ChatGPT (dev mode), Cursor, Gemini, VS Code Copilot.
  - **Capture paths:** Slack (or Discord) bot channel, REST API, MCP `capture_thought`, and bulk-import "recipes" (ChatGPT history, Gmail, Obsidian, Notion, X/Twitter, Instagram, Google Activity).
  - **Key design choice:** raw data and embeddings are stored separately in SQL, so the vector index can be rebuilt when better embedding models ship without touching source data (repo; also highlighted by MindStudio, https://www.mindstudio.ai/blog/open-brain-open-source-ai-memory-system-sql-embeddings-mcp).
- **Explicitly NOT markdown files:** per his FAQ it is "a database with vector search... a memory layer for your AI" (https://promptkit.natebjones.com/20260224_uq1_guide_02). He published a direct critique of Karpathy's markdown/folders "LLM wiki" approach as the contrast case (https://natesnewsletter.substack.com/p/your-ai-re-derives-everything-it). No CLAUDE.md-style memory files appear in his own workflow.
- **Cost/effort claims:** ~45-minute no-code build (a coding agent does the setup — "The build is a conversation now"), ~$0.10–0.30/month on Supabase's free tier.
- **Growth model:** no fine-tuning — accumulation + habit. Companion prompt kit ships five prompts: Memory Migration, Second Brain Migration, Open Brain Spark (daily capture), Quick Capture Templates, Weekly Review. "The more you put in, the better retrieval gets." (https://promptkit.natebjones.com/20260224_uq1_promptkit_1)
- **Extensions** on the same database: Household Knowledge Base, Home Maintenance Tracker, Family Calendar, Meal Planning, Professional CRM, Job Hunt Pipeline, plus an Agent Memory schema with provenance/use-policy governance. Design principle: the "two-door" shared surface — "Your agent enters through one. You enter through the other... both sides read the same data, both sides write to it" (https://natesnewsletter.substack.com/p/you-built-an-ai-memory-system-now).

### 1.2 Open Skills — the method/procedures layer

- **Canonical definition:** "A skill is a compact operating procedure your agent loads on demand: when to use a method, which tools it calls, what standards matter, and what proof it owes you before it says done." (https://unlock-ai.natebjones.com/open-skills)
- **Problem solved:** "most agent wins disappear after the chat ends" and skills built inside one vendor don't travel — "The prompt copies over. The intention copies over. The skill does not." Launch essay: "Why Claude Skills Don't Travel to Codex (and How to Fix It)", subtitle "Your skills are leaving your hands. Don't let a rent-a-brain keep them." (2026-06-19, https://natesnewsletter.substack.com/p/claude-codex-agent-skills). His framing: "Open Brain was about memory leaving our heads... [Open Skills is] the same fight, one level up."
- **Ownership test:** an owned skill must be "visible, movable, inspectable, testable, and available wherever you work."
- **Format:** plain-text, portable artifacts — "prompts, SKILL.md files, runbooks, scripts, MCP configs, permission boundaries, and agent workflows." Delivered on the members site as copy-paste **setup prompts** your agent executes to build each skill locally; there is **no dedicated Open Skills GitHub repo** (his org has only OB1 and ringer). The SKILL.md-with-YAML-frontmatter format matches Anthropic's Agent Skills convention (partly inference; example in the ecosystem: https://github.com/NateBJones-Projects/OB1/blob/main/skills/claudeception/SKILL.md).
- **Library:** ~40 skills in 8 categories + 10 runbooks (https://unlock-ai.natebjones.com/open-skills/skills). Categories: Core Infrastructure (5), Context Engineering (9 — incl. Citation Guard, Packet Export, Human Gate, Open Brain Case Store), Research & Thinking (5), Writing Voice & Content (4), Web Publishing & Frontend (4), Video & Media Production (3), Testing & Quality (3), Agent Operations (7 — incl. Session-to-Skill Extractor, Goal Prompt Generator, Visible Delegation).
- **Runbooks** chain named skills into end-to-end workflows (https://unlock-ai.natebjones.com/open-skills/runbooks), e.g. Talk to Published (Media Transcription → Brain Dump Processor → Personal Voice → HTML Artifact Builder → Personal Site Publisher); Delegate and Verify; The Flywheel ("no useful discovery dies in chat"). The three case-file runbooks (Claim Appeal Packet, Tax Prep Packet, Email Follow-Up Packet) all end **Citation Guard → Packet Export → Human Gate**.
- **Taste and verification are first-class:** "Taste is not a vibe when it is written down as decisions"; every skill defines "what proof it owes you before it says done" — verification is "the real quality bar." Concrete enforcement: Citation Guard returns pass/needs_review/fail with nonzero exit on failure; Human Gate lists allowed actions (organize, draft, validate, export) vs forbidden (sign, send, file, submit, transmit) (https://unlock-ai.natebjones.com/open-skills/context-engineering).
- **Self-improvement loop:** the Session-to-Skill Extractor reviews work sessions with a "high extraction bar" ("most sessions should yield nothing worth extracting") and drafts new skills — "never silently into the live library."

### 1.3 Open Engine — the execution/work-movement layer

- **What it is:** NOT an agent harness or model runtime. It is a **coordination protocol that "turns Linear into a shared operating surface for agents"** — Linear (the issue tracker) becomes the shared queue, state store, and audit trail; agents read assigned issues, move statuses, and leave receipts (primary guide, 2026-06-26: https://unlock-ai.natebjones.com/open-engine).
- **Problem solved:** "the integration layer is you" — humans manually carrying state between AI tools. Agents don't simply become autonomous; "the bottleneck moves to handoffs, state, receipts, and review." Open Engine makes "Claude, Codex, ChatGPT, OpenClaw, Hermes, and other agents act less like isolated subscriptions and more like a system you can operate" (https://natesnewsletter.substack.com/p/ai-agent-handoffs).
- **Concrete spec** (unlock-ai guide):
  - Runtimes are interchangeable MCP clients — "Codex, Claude Code, Claude Desktop, Cursor, or another MCP-capable client" — connected to **Linear's official MCP server** (Claude Code: `claude mcp add --transport sse linear-server https://mcp.linear.app/sse`; Codex: `codex mcp add linear --url https://mcp.linear.app/mcp`, with browser OAuth). Claude Code has no privileged role.
  - Linear team (e.g. "Agent Engine") + project ("Personal Agent Engine" / "Team Agent Engine"); exact label **`agent-instructions`** (the runner filters on this spelling); task title pattern `[agent instructions][agent-code][task]`.
  - **Six workflow statuses** in order: Standing → Agent Todo → Agent Working → Agent Needs Input → Agent Review → Agent Done (completed-category).
  - Stable per-runtime **agent codes** (alex-codex, alex-claude, sam-codex); a **private setup-context issue** (skills, boundaries, config versions agents verify before new work); a **status-ledger issue** ("[agent instructions][all agents][standing_status] Open Agent Engine status ledger") where each agent owns exactly one AGENT STATUS comment updated in place.
  - **Receipt vocabulary** of standardized tokens: AGENT CLAIMED / AGENT DONE / AGENT BLOCKED, plus (per one researcher's fuller list) UNBLOCKED, RESUMED, HUMAN HOLD, HUMAN ANSWERED, FAILED, APPLIED, FOLLOW-UP, STATUS.
  - **Queue runner:** a repeatable instruction loop run per heartbeat (manually or via cron/scheduler): check standing updates → resume paused/blocked work → follow delegated tasks → process **exactly one** eligible assigned task per run.
  - A working engine also includes resumable blockers, human-thread holds, delegated follow-up, and smoke tests (hello-world, blocked-resume, human-hold). Full guide is a 13-step setup; a lighter ~30-min version uses a **seven-part copy-paste task record** that carries a job across tools.
- **First-person usage:** "I've been running a working version to get content out, organize my life, move houses, and coordinate with my team." (Substack, 2026-06-26).

---

## 2. How the layers connect

- **The triad:** "Open Brain (holds memory), Open Skills (holds method), Open Engine (moves the work)" — "rented intelligence on top, owned context underneath" (https://natesnewsletter.substack.com/p/build-your-own-ai-memory). *Note:* two researchers could only find this phrasing in search snippets; a third attributes it verbatim to that Substack post (partially paywalled). Treat wording as near-verbatim.
- **The smallest functional unit is a five-part loop:** **memory, method, boundary, receipt, judgment** — "the smallest unit that lets an agent act for you without guessing"; "One loop beats a whole assistant." The problem has moved "from capability to intent" (same post).
- **Bottleneck routing** (Open Stack Field Guide, https://unlock-ai.natebjones.com/guides/open-stack/open-stack-field-guide): "If the agent cannot do the job, give it a skill. If it keeps losing context, give it a brain. If work disappears between chats, give it an engine." Capability bottleneck → Open Skills; context bottleneck → Open Brain; work-movement bottleneck → Open Engine.
- **Cross-references between layers:** Open Skills includes an "Open Brain Case Store" skill (Skills reads/writes Brain); Open Engine's setup-context issue lists which skills each agent has (Engine loads Skills); Open Engine hands one AI's output to the next AI as a task with receipts (Engine moves work that Skills define and Brain informs).
- **Layer boundaries:** Brain is a database, not files; Skills are files/prompts, not a database; Engine is a protocol on a third-party SaaS (Linear), not code you host. Intelligence (the models) stays rented and swappable at every layer via MCP.
- **Timeline** (from post dates/URL stamps; all 2026): Open Brain guide/prompt kit dated 2026-02-24; Substack launch 2026-03-02; GitHub release ~2026-03-11 (per SimpleNews.ai — minor date conflict with the Feb-24 promptkit stamp, likely guide-before-repo); extensions post 2026-03-13; Open Skills 2026-06-19; Open Engine 2026-06-26; stack-level framing + field guide late June/early July 2026. Per a third-party summary, Open Brain replaced his earlier OpenClaw-based memory setup (secondary source, unconfirmed).

---

## 3. Concrete tooling Nate uses / recommends

### His documented personal stack (Nov 2025 video, transcript-verified: https://www.youtube.com/watch?v=lY6voDZpu3Y + https://natesnewsletter.substack.com/p/my-ai-stack-what-im-actually-using)

| Tool | Role |
|---|---|
| ChatGPT (GPT-5 Thinking) | Analysis/thought partner — "for thinking, not for writing" |
| Claude Sonnet 4.5 | Writing, Excel, Word/PowerPoint |
| Kimi K2 | PowerPoint on non-sensitive data (China-hosting caveat) |
| Perplexity | Search / Research / Labs |
| Grok 4 | Social sentiment (X/Reddit) |
| Comet | Main agentic browser |
| Atlas | ChatGPT-brain browser for code/GitHub/Lovable |
| Claude Code | Terminal agent — "I can tie in cloud [Claude] skills... my local files... the MCP servers that I want to... it goes and does tasks and checks back in"; warns it "has a very strong bias for action" |
| Codex | "I go to Codex almost daily... an extraordinary strategic thinker in the command line" |

His framing (2026-06-10, https://natesnewsletter.substack.com/p/claude-code-vs-codex-agents): "Claude teaches you to steer agents. Codex teaches you to dispatch them."

### Stack-component tooling

- **Open Brain:** Supabase (Postgres + pgvector + Edge Functions), OpenRouter, Slack/Discord for capture, MCP for retrieval; optional SvelteKit/Next.js dashboards.
- **Open Engine:** Linear + Linear's official MCP server; runtimes Claude Code, Codex, Claude Desktop, Cursor; cron/scheduler for the queue runner.
- **Open Skills:** whatever agent you run (Claude Code, Codex, Cursor) + plain-text SKILL.md/prompt files.

### Recommended-for-others (not necessarily his own daily setup)

- No-code second brain (2026-01-09 episode): Slack = capture, Notion = storage, Zapier = automation (Make as cheaper alternative), Claude or ChatGPT = intelligence; Obsidian discussed but not recommended for non-engineers.

### Not confirmed in any public source

- No explicit "I pay for Claude Max" statement (inference markers only: April 2025 "~a hundred bucks a month" complaint; Jan 2026 Claude Cowork demo which was Max-only alpha).
- No named voice-input/dictation tool (Wispr Flow, superwhisper, etc.) across 431 archived transcripts (May 2024–Jan 2026, https://github.com/kani3894/nate-jones-transcripts).
- No markdown memory-files (CLAUDE.md-style) in his own workflow.

---

## 4. How he says to build it (order and steps)

### Order

- **Default build order: Skills → Brain → Engine** — but "Break that order when your bottleneck gives you a better answer" (Open Stack Field Guide). The field guide is diagnostic-first routing, not a setup manual: start from where work is leaking, not from a tool list.
- **Start smallest:** "Start with the smallest one that solves the real bottleneck"; verify each primitive **on real work** before scaling; compose primitives only when the work demands integration.
- **Agent-driven build:** "the build is a conversation now" — pick one recurring task with friction, then "point a coding agent at the Open Stack guide" and let the agent itself work out whether the bottleneck is skills, memory, or work. Claim: ~80% of an AI memory system can be built by talking to the coding agent already on your computer (https://natesnewsletter.substack.com/p/build-your-own-ai-memory).
- **Anti-pattern called out:** one-click/generic setups — "A system cannot be personal if nobody asks what your work is, what your agent may do, what context matters, or where the human approval line sits." Mantra: "The primitives are reusable. The application is personal."
- **Write the human/agent boundary down:** humans keep accounts, auth, secrets, billing, destructive changes, publishing, final judgment; agents do preparation, safe local work, documentation. Memory governance distinguishes "evidence" from "instruction."

### Per-layer build steps

**Open Brain (~45 min, no-code — coding agent does the work):** create Supabase project → run migrations (thoughts table, pgvector, HNSW) → deploy Edge Functions (ingestion gateway: parallel embedding + LLM metadata via OpenRouter) → connect Slack/Discord capture bot → deploy the MCP server Edge Function → connect clients (Claude Desktop/Code, ChatGPT, Cursor) → run the five habit prompts (Memory Migration frontloads what your current AIs already know about you; Second Brain Migration imports Notion/Obsidian; then daily capture + Weekly Review). "Your Open Brain is infrastructure. These prompts are the habits that make it compound."

**Open Skills:** copy the setup prompt for a skill from the directory → your agent builds it locally as SKILL.md + scripts/configs → guidance: "If an agent is close but unreliable, build a skill"; if a workflow already chains several skills, study the runbooks and "copy the prompts, adapt the primitives, or steal only the architecture."

**Open Engine (13-step full / ~30-min light):** create Linear team + project → create the `agent-instructions` label and six statuses → connect each runtime via Linear MCP with OAuth → assign stable agent codes → create the private setup-context issue and the status-ledger issue → install the queue-runner prompt (one task per run, receipts) → smoke-test: hello-world task, blocked-resume, human-hold. Light version: just use the seven-part copy-paste task record to carry a job across tools.

---

## 5. Community implementations & critiques

Almost all independent activity centers on **Open Brain**; no third-party implementation write-ups of Open Engine or Open Skills were found. (Beware name collisions: numman-ali/openskills and instavm/open-skills are unrelated projects.)

### Implementations

- **OB1 itself is a real community project:** ~4.1k stars, 784 forks, 660 commits, ~70 open issues, 69 PRs, FSL-1.1-MIT license, three named community maintainers, a Discord, 20+ merged community PRs (provenance tracking, dashboards, cost optimization, data imports) (https://github.com/NateBJones-Projects/OB1). 259 stars within ~5 days of launch (https://www.simplenews.ai/news/ob1-open-brain-offers-dollar010month-personal-knowledge-infrastructure-for-ai-agents-ssf5).
- **Scott Nichols** — extended MIT-licensed self-hosted implementation, "Original architecture by Nate B Jones · Extended & self-hosted by Scott Nichols"; adds Ollama/OpenRouter/Azure embedders, REST + MCP APIs, signed-envelope provenance (https://srnichols.github.io/OpenBrain/).
- **Daniel Schwartzer** — deep technical walkthrough (Medium, 2026-03-22): ~750 lines TypeScript, 2 Edge Functions, claim-first idempotency for Slack webhook retries ("Insert first, process second. The UNIQUE constraint on idempotency_key is the lock"), <$0.30/month (https://medium.com/@danielschwartzer/open-brain-under-the-hood-the-technical-walkthrough-25a817fbacc5).
- **Ken Kousen** — own variant (GPT-5.5 + Obsidian + GitHub backup + Claude Exporter); works but underused (https://kenkousen.substack.com/p/tales-from-the-jar-side-gpt-image).
- **Craig Calder** — n8n + Slack + Notion variant; capture/auto-categorization "genuinely offloaded the mental burden," Notion upkeep stayed hard (https://software-leadership.medium.com/i-built-an-ai-second-brain-and-used-it-for-a-week-heres-what-actually-happened-5c3fdfee599c).
- **Jonatan Mata** — mapped Nate's 8-block second-brain framework onto his own site, blocks 1–4 done (https://www.jonmatum.com/en/notes/second-brain-2026-takeaways).
- Multiple personal forks (knibals, keithmackay, future-elle, RedondoK); a third-party MCP packaging on LobeHub (unverified, page 403'd).

### Critiques and failure modes

- **MindStudio analysis** (2026-05-03): praises rebuildable indexes ("raw data is permanent, embeddings are derived") but flags chunking without section boundaries, embedding drift in mixed indexes, overly broad MCP permissions, missing audit trails, no reranking ("vector similarity... not necessarily the most relevant") (https://www.mindstudio.ai/blog/open-brain-open-source-ai-memory-system-sql-embeddings-mcp).
- **Implementer friction** (OB1 issues, Jun–Jul 2026): claude.ai connector OAuth failures (#340), HTTP 500 on transcript payloads (#380), silently orphaned writes from UUID/bigint mismatch (#379), stale Claude Desktop connector UI (#346), timezone bug (#335) (https://github.com/NateBJones-Projects/OB1/issues).
- **Schwartzer:** ~80% metadata-classification accuracy with GPT-4o-mini; "the harder challenge is building the capture habit and resisting the urge to over-engineer."
- **Kousen on Nate:** "a bit too dramatic for my taste, and he speaks at approximately Warp 3, but there's a fair amount of signal in the noise"; dislikes the "Open Brain" name.
- **Ecosystem rejection:** OpenClaw closed a request to make OB1 a first-class memory target as "not planned" (https://github.com/openclaw/openclaw/issues/70262).
- **Discussion venues:** no Hacker News stories (HN Algolia: 0 hits) and no Reddit threads surfaced; discussion lives in Nate's Discord, Substack comments, LinkedIn, and the GitHub tracker (absence of evidence, not proof).
- **Paywall caveat:** several Substack deep-dives are partially paywalled; the exact nine diagnostic questions (Open Engine) and full field-guide prompt text were not retrievable.

---

## 6. Full source list

### Primary — Nate's own sites/repos

| Source | URL | What it establishes |
|---|---|---|
| OB1 GitHub repo | https://github.com/NateBJones-Projects/OB1 | Open Brain tagline, architecture, license, community stats, extensions, skills/ folder |
| OB1 getting-started doc | https://github.com/NateBJones-Projects/OB1/blob/main/docs/01-getting-started.md | thoughts table, 1536-dim embeddings, match_thoughts, MCP tools, client list |
| Example SKILL.md (community) | https://github.com/NateBJones-Projects/OB1/blob/main/skills/claudeception/SKILL.md | SKILL.md YAML-frontmatter format in his ecosystem |
| Open Brain setup guide | https://promptkit.natebjones.com/20260224_uq1_guide_main | Full 45-min build: Supabase, pgvector, OpenRouter, parallel ingestion |
| Open Brain FAQ | https://promptkit.natebjones.com/20260224_uq1_guide_02 | "a database with vector search"; HNSW; retrieval compounds |
| Open Brain prompt kit | https://promptkit.natebjones.com/20260224_uq1_promptkit_1 | The five habit prompts |
| Open Brain launch post | https://natesnewsletter.substack.com/p/every-ai-you-use-forgets-you-heres | Canonical definition; $0.10/45-min claims (2026-03-02) |
| Karpathy-wiki critique | https://natesnewsletter.substack.com/p/your-ai-re-derives-everything-it | DB-not-markdown positioning (paywalled) |
| Extensions post | https://natesnewsletter.substack.com/p/you-built-an-ai-memory-system-now | Six extensions; two-door principle (2026-03-13) |
| Open Skills launch essay | https://natesnewsletter.substack.com/p/claude-codex-agent-skills | Rent-a-brain framing; portability; ownership test (2026-06-19) |
| Open Skills page | https://unlock-ai.natebjones.com/open-skills | Skill definition; 40 skills / 8 categories / 10 runbooks |
| Skills directory | https://unlock-ai.natebjones.com/open-skills/skills | Category names and counts |
| Runbooks directory | https://unlock-ai.natebjones.com/open-skills/runbooks | All 10 runbooks with skill chains |
| Agent Operations category | https://unlock-ai.natebjones.com/open-skills/agent-operations | 7 skills incl. Session-to-Skill Extractor; setup-prompt delivery |
| Context Engineering category | https://unlock-ai.natebjones.com/open-skills/context-engineering | Citation Guard, Human Gate enforcement detail |
| Core Infrastructure category | https://unlock-ai.natebjones.com/open-skills/core-infrastructure | Foundation-layer skills |
| Open Engine guide | https://unlock-ai.natebjones.com/open-engine | Full Linear spec: statuses, label, ledger, receipts, runner, MCP commands (2026-06-26) |
| Open Engine launch post | https://natesnewsletter.substack.com/p/ai-agent-handoffs | Seven-part task record; "I've been running a working version..." (2026-06-26) |
| Open Stack Field Guide | https://unlock-ai.natebjones.com/guides/open-stack/open-stack-field-guide | Bottleneck routing; build order Skills→Brain→Engine; boundary principles |
| Guides index | https://unlock-ai.natebjones.com/guides | Field guide's official description |
| Stack philosophy post | https://natesnewsletter.substack.com/p/build-your-own-ai-memory | Triad one-liner; five-part loop; 80%-by-conversation claim (2026-07-01) |
| Nov 2025 personal stack video | https://www.youtube.com/watch?v=lY6voDZpu3Y | 9-tool personal stack, transcript-verified |
| Personal stack Substack | https://natesnewsletter.substack.com/p/my-ai-stack-what-im-actually-using | Companion to the stack video |
| Claude Code vs Codex | https://natesnewsletter.substack.com/p/claude-code-vs-codex-agents | Steer vs dispatch (2026-06-10) |
| Issue-tracker infrastructure post | https://natesnewsletter.substack.com/p/issue-trackers-agent-infrastructure | Linear as agent control plane, analytical (2026-05-02) |
| Open Brain build video | https://www.youtube.com/watch?v=2JiMmye2ezg | "$0.10 System That Replaced My AI Workflow" |
| LinkedIn launch post | https://www.linkedin.com/posts/natebjones_introducing-open-brain-because-your-memories-activity-7434421015503441920-Wl_s | "your memories are yours" framing |
| Podcast (Acast) | https://shows.acast.com/ai-news-strategy-daily-with-nate-b-jones | "isolated subscriptions → a system you can operate" |
| Podcast episode description (iVoox) | https://www.ivoox.com/en/why-claude-skills-don-t-travel-to-codex-and-audios-mp3_rf_175916343_1.html | "OpenBrain gives agents the context; OpenSkills gives them the repeatable way to work" |

### Secondary — third-party

| Source | URL | What it establishes |
|---|---|---|
| Transcript archive | https://github.com/kani3894/nate-jones-transcripts | 431 verbatim transcripts May 2024–Jan 2026; verification base |
| aifor.dev summary | https://aifor.dev/sources/summary-nate-b-jones-openbrain-architecture | Architecture video summary; "replaced prior OpenClaw setup" (unconfirmed) |
| MindStudio analysis | https://www.mindstudio.ai/blog/open-brain-open-source-ai-memory-system-sql-embeddings-mcp | Praise + failure-mode critique |
| MindStudio explainer | https://www.mindstudio.ai/blog/what-is-openbrain-personal-ai-memory-database | Second OB1 explainer |
| SimpleNews.ai | https://www.simplenews.ai/news/ob1-open-brain-offers-dollar010month-personal-knowledge-infrastructure-for-ai-agents-ssf5 | Release date ~2026-03-11; 259 stars in ~5 days |
| Scott Nichols implementation | https://srnichols.github.io/OpenBrain/ | Extended self-hosted variant |
| Schwartzer walkthrough | https://medium.com/@danielschwartzer/open-brain-under-the-hood-the-technical-walkthrough-25a817fbacc5 | Technical depth; idempotency; 80% accuracy |
| Ken Kousen | https://kenkousen.substack.com/p/tales-from-the-jar-side-gpt-image | Variant build + style critique |
| Craig Calder | https://software-leadership.medium.com/i-built-an-ai-second-brain-and-used-it-for-a-week-heres-what-actually-happened-5c3fdfee599c | n8n/Slack/Notion variant, week-long test |
| Jonatan Mata | https://www.jonmatum.com/en/notes/second-brain-2026-takeaways | 8-block framework mapping |
| OB1 issue tracker | https://github.com/NateBJones-Projects/OB1/issues | Implementer friction Jun–Jul 2026 |
| OpenClaw issue #70262 | https://github.com/openclaw/openclaw/issues/70262 | OB1 integration request closed "not planned" |
| Fork example | https://github.com/knibals/NateBJones-OpenBrain1 | Fork activity (also keithmackay, future-elle, RedondoK) |
| LobeHub MCP listing | https://lobehub.com/mcp/blanxlait-openbrain | Third-party MCP packaging (unverified, 403) |
| Public Services Alliance | https://publicservicesalliance.org/2025/11/10/nate-b-joness-personal-ai-stack/ | Secondary summary of Nov 2025 stack |
| Digital Project Manager | https://thedigitalprojectmanager.com/productivity/nate-jones/ | His taught agentic PM pipeline (Linear MCP → GPT-5 → ChatPRD) |
| Fiddler podcast | https://www.fiddler.ai/podcasts/agent-wars-with-nate-b-jones | Guest appearance, July 2025 |
| Second Brain 2026 transcript | https://podprose-blog-577986351357.australia-southeast1.run.app/post/339/ai-news-strategy-daily-nate-b-jones-why-2026-year-build-second-brain-transcript | Recommended Slack+Notion+Zapier stack (2026-01-09) |
| Thusie coverage | https://thusie.com/nate-jones-open-brain-ai-memory-system-10-cents.html | Cost claim + MCP-as-USB-C framing |
| Skool post | https://www.skool.com/cliefnotes/nate-jones-open-skills-released?p=ef957719 | Independent Open Skills release confirmation |
