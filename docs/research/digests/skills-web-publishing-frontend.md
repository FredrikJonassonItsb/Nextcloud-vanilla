# Digest: Open Skills — Web Publishing & Frontend category

Source: https://unlock-ai.natebjones.com/open-skills/web-publishing-frontend ("Unlock AI by Nate B. Jones", Open Skills directory)
Source file: `.../scratchpad/natebjones/text/open-skills__web-publishing-frontend.md`

## Category overview

- Category name: **Web Publishing & Frontend**
- Category purpose (verbatim intent): "Skills for turning agent output into public, inspectable web work: better frontend taste, clean publishing, share previews, comparison pages, and verification before anything is called shipped."
- Skill count: **4 skills**, in this order: Frontend Taste System, Personal Site Publisher, Image Model Comparison Arena, Essay Illustration Gallery.
- Usage model of the page: "Read this page like a triage menu. Find the skill that removes a repeated explanation, a fragile manual step, or a quality bar the agent keeps missing. Then copy the setup prompt and let your agent adapt it to your tools, files, accounts, and standards."
- Installation philosophy: "Install the primitive only when you can name the workflow it will improve."
- Recommended order: "If you are building this lane from scratch, start with Frontend Taste System. Otherwise, skip directly to the skill that matches today's bottleneck. The prompt is the starting point; the installed skill should reflect your real workflow."
- Delivery format: each skill is delivered as a copy-pasteable **setup prompt** wrapped in `<prompt><task>...</task></prompt>` XML tokens. The prompt instructs the user's own AI coding agent to CREATE the skill (interview the user, write the skill file(s), then self-test). All prompts share the phrasing "stored wherever my harness loads skills from."
- Page cross-links: "Back to the Skills directory or continue into runbook compositions." Site nav: Unlock AI / Open Skills — Open Engine, Guides, Open Skills, Benchmarks, Image Arena.

---

## Skill 1: Frontend Taste System

- **Installed skill name (exact):** `frontend-taste` (a skill *bundle*: core skill + nested sub-skills)
- **Purpose:** Replace the agent's default frontend design instincts with a stronger taste system applying to "all websites, apps, landing pages, and UI work." Fixes the recognizable "agent-generated frontend" look (same hero-and-three-cards page every time).
- **Structure (architecture pattern):** Core philosophy skill that ROUTES to specialized nested sub-skills based on task — explicitly presented as the alternative to "one unloadable mega-document." Sub-skill directions listed on the page: minimalist editorial UI, data-dense dashboard UI, premium landing pages, mobile app concepts, redesigning existing projects. (Note: the setup prompt itself lists only four sub-skills — minimalist/editorial UI, data-dense dashboard UI, premium marketing/landing pages, and redesigning existing projects without breaking them — the "mobile app concepts" sub-skill appears only in the descriptive text.)
- **Triggers:** "all frontend design and implementation work" (the core skill's trigger condition #1).
- **Core skill must include (numbered requirements from the prompt):**
  1. Trigger conditions — all frontend design and implementation work.
  2. Layout rules: deliberate variance, no default hero-plus-three-cards pattern, real grids, generous whitespace used intentionally.
  3. Typography rules: a real type scale, restrained pairings, no default-stack sloppiness.
  4. Color rules: restrained palettes, one accent doing real work, no purple-gradient-on-white clichés.
  5. Mandatory visual verification: screenshot the result, inspect it critically, fix what's weak, repeat before calling it done. (Loop vocabulary: "screenshot, inspect, fix, repeat".)
- **Inputs / interview:** "Interview me first for my taste references — 2–3 sites or apps whose design I admire and why."
- **Tools / prerequisites ("What you need"):** Nothing required; a screenshot-capable browser tool (most harnesses have one) for visual verification.
- **Verification / self-test after creation:** "test it by building one landing page section and running your own visual verification loop on it."
- **Boundaries:** Redesign sub-skill scope is "redesigning existing projects *without breaking them*." No external dependencies on other skills.

---

## Skill 2: Personal Site Publisher

- **Installed skill name (exact):** `site-publisher`
- **Purpose:** Take a finished page or artifact and publish it to the user's personal/company website as a real, share-ready URL, end to end. Encodes the site's stack and conventions "so publishing is a procedure, not a project." Covers everything separating "an HTML file" from "a published page": design language, clean slug and URL route, page-specific Open Graph preview image (**1200×630** — prompt writes it `1200x630`), share title and description, indexing controls (public vs. unlisted / noindex flags), local verification before deploy, and the deploy itself.
- **Why it matters (positioning):** "This skill is the final step of half the runbooks in this library." It is a composition target for other skills (see skills 3 and 4).
- **Triggers (exact boundary, requirement #1):** "ONLY when I explicitly ask to publish/ship/put something on the site, **never auto-triggered**."
- **Pre-write exploration / interview inputs:** explore the website repo and interview for: (a) repo path and stack, (b) how routes/pages are added, (c) deploy command and any verification steps, (d) design language (or which existing pages to match), (e) default indexing preference for one-off share pages (public vs. unlisted).
- **Skill must include (numbered requirements):**
  1. Trigger conditions (explicit-ask only, never auto-triggered).
  2. Full procedure: clean slug, page creation matching site conventions, a page-specific 1200x630 Open Graph image ("route generation through my image-gateway skill if I have one"), share title and description, indexing controls.
  3. Local verification before deploy — build and view the page.
  4. The deploy procedure.
  5. Post-publish checks: live URL loads, OG preview renders correctly.
- **Tools / prerequisites ("What you need"):** "A website you control with a deploy path your agent can run (static site, framework site, or hosting CLI)."
- **Verification / self-test after creation:** "test it by publishing one unlisted test page end to end, then walk me through cleaning it up or keeping it."
- **Composition hooks:** OG image generation optionally delegates to an **image-gateway** skill (Image Generation Gateway, from another category) if the user has one.

---

## Skill 3: Image Model Comparison Arena

- **Installed skill name (exact):** `image-model-arena`
- **Purpose:** Build and publish comparison test pages for image-generation models: one review page per model (same prompts, that model's outputs, cost and behavior notes) plus a shared side-by-side comparison viewer — all generated from a **single config file**. Adding a new model = add a config entry and re-run. Handles generation, image optimization, page builds, and publishing. Maintains a registry of model costs and content-policy quirks discovered along the way.
- **Architecture (explicit composition rule):** "This skill COMPOSES two skills I already have: image generation goes through my image-gateway skill, and publishing goes through my site-publisher skill. **It must never reimplement either.**" It owns exactly one thing: the comparison methodology and page generation. Presented as "the library's best example of composition as architecture."
- **Triggers (requirement #1):** when the user wants to (a) test a new image model, (b) compare models, or (c) add a model to an existing comparison.
- **Interview inputs:** (a) standard test prompt set — "help me design 6–10 prompts covering photorealism, text rendering, diagrams, people, and style range"; (b) where comparison configs and generated images should live.
- **Skill must include (numbered requirements):**
  1. Trigger conditions (test new model / compare models / add model to existing comparison).
  2. A single config format defining models, prompts, and page metadata.
  3. The pipeline: generate via image-gateway → optimize images for web → build per-model pages and the shared comparison viewer → publish via site-publisher.
  4. A model registry tracking per-image cost and content-policy quirks observed.
  5. Regeneration support — "adding one model must not require redoing the others."
- **Tools / prerequisites ("What you need"):** Image Generation Gateway and Personal Site Publisher **built first**; budget for generation costs ("typically a few dollars per model").
- **Verification / self-test after creation:** "test it with two models on a 3-prompt subset before running anything at full scale."
- **Boundaries:** No image generation of its own; no publishing of its own; regeneration must be incremental (per-model isolation).
- **Value claim:** "When a new image model drops, you can have a published, evidence-based comparison the same afternoon."

---

## Skill 4: Essay Illustration Gallery

- **Installed skill name (exact):** `essay-illustration-gallery`
- **Purpose:** Turn a finished essay or long post into a complete illustration package: read the piece, select ~15–20 image-worthy moments across the essay's FULL arc (not just the obvious opener), lock a single illustration style so every frame is visually consistent, generate the images, write a short "why this moment" caption per frame, assemble everything into a gallery page, plus a ready-to-paste social note announcing it in the author's voice.
- **Why it matters:** "The hard part of illustrating an essay isn't generating images — it's editorial judgment (which moments deserve images) and consistency (twenty images that look like one artist made them)." A study in multi-skill composition packaged as one skill: analysis, style-locking, generation, captioning, gallery assembly, and publishing in a single repeatable pipeline.
- **Composition:** composes the **image-gateway** skill for generation and the **site-publisher** skill for publishing ("if I ask for the gallery to go live"). Social note uses the user's voice skill (Personal Voice Skill) if present.
- **Triggers (requirement #1):** "when I share an essay and ask for illustrations, images, or a gallery."
- **Interview inputs:** (a) preferred illustration style direction (examples given: hand-drawn editorial cartoon, photoreal, watercolor) — "help me write a precise style descriptor we lock per gallery"; (b) how many frames a typical essay should get.
- **Skill must include (numbered requirements):**
  1. Trigger conditions (essay shared + ask for illustrations/images/gallery).
  2. Moment selection: choose frames across the FULL arc of the piece, each tied to a specific passage, with a one-line rationale.
  3. Style lock: one detailed style descriptor **prepended to every prompt** so all frames match.
  4. Per-frame captions explaining why that moment was chosen.
  5. Gallery assembly as a single page — "use my html-artifacts conventions" (references an html-artifacts skill/conventions the user presumably has).
  6. A short ready-to-paste social note announcing the gallery, "in my voice if I have a voice skill."
- **Tools / prerequisites ("What you need"):** Image Generation Gateway; Personal Site Publisher if the gallery should be published; Personal Voice Skill for the social note.
- **Verification / self-test after creation:** "test it on one essay with a reduced frame count (5–6 frames) first."
- **Boundaries:** Publishing only happens on explicit request ("if I ask for the gallery to go live"); frame count target ~15–20 in production, 5–6 for the test run.

---

## Cross-cutting conventions (rebuild-relevant)

1. **Prompt envelope token (verbatim):** every setup prompt is wrapped as:
   ```
   <prompt>
     <task>
       ...instructions to the agent...
     </task>
   </prompt>
   ```
2. **Shared storage phrase (verbatim):** `stored wherever my harness loads skills from` — skills are harness-agnostic.
3. **Standard skill anatomy imposed by every prompt:** numbered "must include" list that always begins with *(1) trigger conditions*, followed by procedure/rules, and ends with a mandatory **self-test at reduced scale** immediately after the skill is written (one landing-page section / one unlisted test page / 2 models × 3 prompts / 5–6 frames).
4. **Interview-first pattern:** every prompt instructs the agent to interview the user BEFORE writing the skill (taste references; repo/stack/deploy/indexing; test-prompt set and storage locations; style direction and frame count).
5. **Composition graph (dependency order):**
   - `frontend-taste` — standalone (build first if starting from scratch).
   - `site-publisher` — standalone; optionally calls `image-gateway` for OG images.
   - `image-model-arena` — REQUIRES `image-gateway` + `site-publisher`; must never reimplement either.
   - `essay-illustration-gallery` — REQUIRES `image-gateway`; optionally `site-publisher` (go-live) and a personal voice skill (social note); references `html-artifacts` conventions.
   - `image-gateway` (Image Generation Gateway), the Personal Voice Skill, and `html-artifacts` conventions are defined OUTSIDE this category page.
6. **Verification culture:** frontend work uses a mandatory visual loop (screenshot → inspect → fix → repeat); publishing uses local build-and-view before deploy plus post-publish live-URL and OG-preview checks.
7. **Key constants:** OG image size 1200×630; test prompt set 6–10 prompts covering photorealism, text rendering, diagrams, people, style range; essay galleries ~15–20 frames (test at 5–6); arena test = 2 models × 3 prompts; taste interview = 2–3 admired sites/apps.

## OPEN QUESTIONS

1. Sub-skill mismatch in Frontend Taste System: the descriptive blurb lists five sub-skill directions (including "mobile app concepts"), but the setup prompt creates only four nested sub-skills (mobile app concepts is absent). Unclear which is authoritative.
2. "Image Generation Gateway" (`image-gateway`), "Personal Voice Skill", and "html-artifacts conventions" are referenced as prerequisites/conventions but are not defined on this page — their specs live elsewhere in the Open Skills library (other category pages).
3. The config file format for `image-model-arena` (schema for models/prompts/page metadata) is intentionally left for the agent to design; no concrete schema is given.
4. "runbook compositions" is linked as a continuation but its content is not in this source.
5. No exact file layout for the `frontend-taste` bundle (core + nested sub-skills) is specified beyond "nested"; directory structure is harness-dependent.
