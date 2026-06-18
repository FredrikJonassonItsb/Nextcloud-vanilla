# Quilt-App Workflow — Agent Rules

Read before modifying a quilt app (`mail`, `calendar`, …). This is the agent-specific discipline and
the pitfalls that have actually bitten. It **extends** the [`README.md`](../README.md) — that doc owns
the mechanics (overlay-vs-patches, the four assemble modes, the make targets); this doc owns the
judgment. It complements the always-on rules in [`CLAUDE.md`](../CLAUDE.md) (containers-only, never push,
the trademark rule, `make quilt` interactive) — those aren't repeated here. Platform internals:
[`architecture.md`](architecture.md).

When this doc and the code disagree, the code is right — and the disagreement is a finding: flag it.

**First, confirm you're in a quilt app:** `apps/<app>/upstream/` must exist. Standalone apps (e.g.
`sdkmc`) have no patches/overlay layering, so the **patch-craft rules don't apply** — you edit their
source directly and commit. The operating rules below (run in-container, lint, don't push) still do.

## Operating rules

1. **Go through `make`; never invoke platform internals or run `quilt` directly.** `make assemble` /
   `make quilt` / `make diff` set the environment, take the lock, and use the container's quilt.
   Running `quilt push`/`pop`/`refresh` by hand in `.build/`, or calling `scripts/*.py` /
   `scripts/assemble.sh` directly, produces wrong-format patches (function-context hints, `.build.orig/`
   paths) or skips the lock — one stray run inflates a clean patch into hundreds of noise lines.

2. **Resetting `.build/` destroys unsaved work — know which gesture does what.** `make assemble
   MODE=discard` and `MODE=force` **silently throw away uncommitted `.build/` edits**; `make quilt`
   first if you want them. (Never `rm -rf .build` — `CLAUDE.md` covers why; and there's no `.build-ready`
   file or `FORCE=1` flag to reach for, pre-rebuild habits notwithstanding.)

3. **`make quilt` is interactive — drive it in a real terminal** (the IDE-attach terminal or tmux);
   `CLAUDE.md` covers why never to fake a TTY. If you can't drive it interactively, stage the work so
   each pass is one concern routed interactively, rather than reaching for a non-interactive form (there
   isn't one).

4. **Run tests in the container** (the host has no toolchain). Use the IDE-attach integrated terminal,
   or wrap explicitly. The *platform* unit-test files under `tests/` carry their exact invocation in the
   file docstring (`APP_ROOT="$PWD/apps/<app>" bash scripts/host/dc-run.sh python3
   /platform/tests/<file>.py`); the state-integrity chaos arc runs via `bash tests/run_state_integrity.sh`.
   *app* tests run via the app's own runner inside the container (e.g. `npx vitest run src/itsl/tests/`
   from `.build/`). Never run `npx`/`eslint`/`python3` on the host.

5. **Offset/fuzz warnings are harmless drift, not corruption.** `Hunk #N succeeded at M (offset X)`
   means the patch applied but its header line-numbers drifted (an earlier patch or an upstream bump
   shifted lines). The file state is correct. It clears the next time you legitimately edit and re-save
   that patch via `make quilt` — don't chase it, and never `quilt refresh` directly (Rule 1).

6. **Lint overlay files normally; don't `eslint --fix` mid-feature.** Overlay symlinks are tracked in
   `.build/`'s baseline, so ESLint sees them by default — no `--no-ignore` flag (that was a pre-rebuild
   workaround). `eslint --fix` mixes unrelated autofix drift (arrow-parens, import-sort) into your
   commit; run it separately or revert the unrelated drift.

7. **An HMR red overlay does not mean the app is broken.** webpack-dev-server paints a full-screen
   overlay on any runtime error; the app is usually mounted and functional behind it. Dismiss it and
   verify the actual UI before reporting breakage. Production has no overlay.

## Patch craft (the heart of this work)

8. **Patches reference implementation; they do not contain it.** A patch adds a minimal seam that hands
   control to code under `overlay/src/itsl/`. Patches must not carry error-handling logic, i18n strings,
   `instanceof` checks against ITSL classes, multi-step business logic, or anything that reads as
   "implementation." If you're writing more than ~5–6 lines of new body inside a patch, extract it to
   `overlay/` and patch in only the delegation call. **The exceptions override the line count** — props
   on upstream Vue components, genuine upstream bugfixes (which can be long and *belong* in the patch),
   and minimal backend hooks the overlay consumes. Flag them; they prove the rule.

9. **A patch represents one concern** — what someone reverting it six months from now is undoing. Tests:
   state its purpose in one sentence (an "and" joining unrelated clauses → two concerns); imagine
   reverting it (does that produce a coherent change?); don't split on layer (backend/frontend is not a
   concern — "tag substitution" is one concern with both halves). **Patch names lie** — read the actual
   hunks before routing into a patch; a misnamed patch gets renamed or split, never added to. Hunk count
   is not a reason to split (a 41-hunk coherent patch is one concern; a 2-hunk grab-bag is two).

10. **Minimise the hunk before saving.** Each hunk costs maintenance on every upstream bump. Reach for:
    inline `\OCP\Server::get(Class::class)->method(…)` at the call site instead of constructor-injecting
    an ITSL service (kills the use+property+ctor+assignment ceremony); `$onAction` / store-override in
    `initITSL.js` for upstream-action mutation; `NormalModuleReplacementPlugin` in the app's
    `overlay/webpack.common.js` for whole-file component swaps; grouping ITSL imports under an
    `eslint-disable` for `perfectionist/sort-imports` to stop per-bump shuffle. **"Cosmetic" is narrow**
    — blank-line, SPDX-header, comment-only, trailing-comma drift. CSS, `aria-label`s, and layout values
    are **not** cosmetic (a 2-line CSS rule may be a tuned breakpoint; an `aria-label` is a screen-reader
    user's only handle). Read every hunk before dropping it; when unsure, skip it at the prompt and ask.

11. **Rationale lives in the artifact, not git history.** The patch file is what future readers see; the
    commit message compacts away. Every patch gets a multi-paragraph header: a one-sentence theme, ticket
    refs (`Fix #N` / `See #N`), and design notes for non-obvious decisions (which minimisation pattern
    you picked and why). When a hunk's purpose isn't obvious from the diff, leave a code comment at the
    line. A one-line `Description:` is insufficient past a single-hunk patch.

## The save flow & its traps

`branch (in the app repo) → edit → make diff → make quilt → make assemble → test → commit`.

The traps that catch first-timers:

- **Symlink vs real vs copied.** Editing an overlay *symlink* in `.build/` saves straight to `overlay/`
  (no patch); editing a *real* file leaves the change in `.build/` until `make quilt`. A third category:
  a few overlay files (the dependency manifests + lockfiles, and the app's `webpack.common.js`) are
  *copied* into `.build/`, not symlinked, so edits to those don't flow back **and** don't route to a
  patch. Run `make diff` to see
  which is which **before** assuming where your edit went.
- **Generated overlay files are off-limits.** `overlay/package.json`, `overlay/composer.json`, and the
  lockfiles look like overlay files you may edit directly — but `make deps` generates them and overwrites
  hand edits. Change dependencies via `itsl-*-deps.d/` fragments + `make deps` (README → Dependencies).
- **Commits land in the app repo, not here.** Branch and commit in `apps/<app>/` (`patches/` +
  `overlay/`), never the hubs-apps root and never `.build/`. `git add -A` from the wrong directory is a
  real mistake.
- **Saving refreshes the series, not just your hunk.** Routing into an early patch can shift later
  patches' offsets — that's normal drift (Rule 5), not corruption; don't start "fixing" it.

If multiple unrelated concerns sit in `.build/` at once, save them in separate `make quilt` passes (one
concern each) rather than routing a grab-bag.

## When something surprises you — stop

The pattern that built the platform's worst debt was "session sees something odd, tweaks until it works,
moves on." Don't.

- **Conflict markers (`<<<<<<<`) in a `.build/` file after plain `make assemble`** mean your edit
  collided with a patch during the walk. Resolve the markers, then `make quilt` — **not** another
  `make assemble`, which re-stashes the marked-up tree and layers conflicts.
- **Unexpected exit code or behaviour from a `make` target** → run `make status`, read the platform
  code, surface to the user. Do **not** work around it by invoking internals or hand-editing patches.
  You may have found a real bug — report the command, the exit code, and what `make status` showed.
- **`make quilt` reports through its diagnostic message, not its exit code.** `make quilt` returns only
  `0` (success) or `2` (any failure — `make` collapses every error to its own `2`), so route recovery on
  what it printed. The underlying `quilt_v2.py` codes the message reflects: `2` missing prerequisite
  **or** run outside the dev-builder; `3` nothing to save / all skipped; `6` `.build/.lock` held (another
  op is running — wait, don't force it); `130` you aborted; `1` a genuine error but not always
  `MODE=recover`-able (an extract-phase failure left nothing to recover) — read the message.
- **A failed `make quilt`** that left recovery state → `make assemble MODE=recover` (then clean the app
  repo with `git -C apps/<app> checkout -- patches overlay` if the failed save wrote partial files).
- **Never delete `.build/.lock`** — the lock always has a live holder (`make status` names it), and
  unlinking it lets two writers collide.

## Verification checklist (before commit)

- [ ] `make diff` shows only the changes you intended.
- [ ] `make assemble` reports zero fuzz/offset/reject.
- [ ] `git diff patches/` hunk headers are plain `@@ … @@` and paths are `a/`…`b/` (Rule 1).
- [ ] ITSL tests pass and lint is clean — both run **in-container**.
- [ ] Each patch you touched reads as one concern, with a multi-paragraph header; you can state every
      saved hunk's concern in one sentence and it matches its patch.
- [ ] You committed in the app repo (`patches/`/`overlay/`), not the hubs-apps root.
