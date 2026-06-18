#!/usr/bin/env python3
"""State-integrity test for make quilt / make assemble / make diff.

A different shape from the rest of the test suite: one long-lived
.build/ across a 92-step arc with invariants checked between every
step. Silent state corruption (orphan commits in .build/.git, .pc/
desyncing from patches/, baseline-tag drift, .gitignore drift, stale
overlay symlinks) only surfaces under accumulated state — a pre-test
reset would destroy the evidence. Behaviour tests live elsewhere; this
one watches for structural corruption that the per-op outputs miss.

This test simulates one developer's workday in apps/mail. The dev edits
files, periodically saves them as quilt patches via `make quilt`,
occasionally screws up (drops scratch files, hand-edits a patch by
accident, gets killed mid-save, loses lock contention), recovers,
switches branches at end-of-feature, deletes things, calls it a day.
There is no "verification" operation in the script — the dev is just
working. invariants() between every step does the watching.

Three things make this shape distinct from the rest of the test suite:

  1. ONE long-lived .build/ across the whole 92-step arc. No clean()
     between steps. Silent state corruption (orphan commits in .build/.git,
     .pc/ desyncing from patches/, baseline-tag drift, .gitignore drift,
     stale overlay symlinks) only surfaces under accumulated state — a
     pre-test reset would destroy the evidence.

  2. Continuous narrative. Edit-save-undo-redo across files, scratch
     files dropped in .build/, debug prints scattered, long dirty
     periods, mid-flight failures + recovery, lock contention,
     parent-repo accidental violence (random files in
     apps/mail/{root,overlay,patches}, hand-edits to .patch files,
     half-committed parent state). The arc is one continuous workday;
     skipping or resetting any step makes the rest meaningless.

  3. Invariant battery between every step. Corruption modes accumulate
     silently; the only way to catch them is continuous structural
     assertion.

After the arc, two outer-loop checks:

  - DIFFERENTIAL EQUIVALENCE (Phase 9): snapshot .build/'s source-tree
    state at end-of-arc; without touching apps/mail/, run `make assemble
    MODE=force` in place; compare snapshot vs fresh-MODE=force bytes (source
    tree, baseline-tag content, .gitignore). Divergence = drift the arc
    accumulated.

  - CLEANUP (Phase 10): restore apps/mail/ to suite-start (git reset
    --hard + git clean -fd + drop test stashes + drop ARC_BRANCH).
    Then make assemble MODE=force to rebuild .build/ from scratch and
    post-FORCE invariants to confirm the rebuild worked.

Branch model:
  The arc runs on a temp branch (ARC_BRANCH, "state-integrity-arc")
  forked from whatever the operator's working branch was at suite-start
  (typically mail-patch-rework). Successful `make quilt` saves
  auto-commit patches/* and overlay/* to ARC_BRANCH (via
  commit_arc_progress hooked into make_quilt_interactive). This builds
  real history — the temp branch ends up several commits ahead of the
  original.

  Between phase 7 and phase 8, the test switches BACK to the original
  branch. Per the platform contract, branch swap MUST be followed by
  `make assemble` plain — patches/ now reflects the original branch's
  content and `.build/` needs to be brought back into line. If plain
  can't handle the re-sync, plain is broken and the test surfaces that
  as a real platform bug. Phase 8 then runs on the original branch with
  no further commits (commit_arc_progress's branch guard prevents
  moving the operator's branch tip).

Fail-fast: a failed check halts the arc immediately. Once a step goes
off-script, every downstream invariant and answer is operating against
an unintended state, and the cascade of failures produces noise that
masks the real failure. One root cause, one stop. main() catches the
StateIntegrityFailure and runs cleanup.

Failure-mid-flight modeling: instead of synthetic kill points (the
previous shape used STATE_INTEGRITY_KILL_AT + os._exit(137) hooks in
production code — test scaffolding shipped in production paths), the
arc triggers REAL failures by sabotaging quilt's inputs:
  - sabotage_patch_file: unlink an in-series .patch file (without
    stripping series). quilt push of that entry hits file-not-found
    mid-write-loop → write_patches raises → pending tag preserved →
    recovery on next run. The deliberate series-vs-patchfile mismatch
    IS the test condition; invariants() is told to expect it via
    expect_series_mismatch=True for the duration.
  - synthesize_pending_state: create the .build/.git state a kill-
    after-_capture_pending would leave, via direct git ops. Models
    the user-Ctrl-C / external-SIGKILL between capture and write-start
    case without instrumenting production code.
The platform handles both via the same write_patches → raise → caller
catches → pending tag preserved → next-run recovery flow it runs in
production. If the platform mishandles a corruption shape, the test
surfaces a real platform bug.

Pre-test guards and post-test verifications (no-leftover-arc-branch,
post-test branch tip equals suite-start, etc.) live OUTSIDE this
file in tests/run_state_integrity.sh. Per operator: keeping
operator-environment guards out of the test code itself avoids future
agents conflating the two. Run the test via the wrapper, not directly.

Suite-start prerequisites (enforced by capture_suite_start; the wrapper
script also guards against ARC_BRANCH leftovers):
  - operator has run `make assemble MODE=force` once (per Johan's
    "automatic fail" rule; the test does not run MODE=force at start);
  - apps/mail working tree clean against the original branch's HEAD
    (otherwise cleanup-at-end can't trust git reset to suite-start);
  - .build/ populated and clean against baseline.

Run wall-clock: roughly 10 minutes single-pass (3-5 min arc with
invariants between every step, plus ~2 min differential MODE=force, plus
~2 min cleanup MODE=force). The 30-min `timeout` wrapper around the test
is a canary, not a budget — no individual operation should take more
than a few seconds. If a run gets close to the 30-min ceiling, new
SCAR has crept in (slow recovery primitives, cascading retries, hung
prompts); the time itself is the signal.
"""

import fcntl
import os
import re
import shlex
import shutil
import subprocess
import sys
import tempfile
import time

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# Pull shared constants from the platform's scripts/ so cross-file
# contracts (notably RESOLVE_PROMPT_SIGNATURE — the substring the smart
# resolver counts in tmux pane history) stay single-sourced. Adding
# scripts/ to sys.path is a host-side concern (the test driver runs on
# the host); the imported names use only stdlib internally so the
# import is safe outside the dev-builder container.
sys.path.insert(0, os.path.join(REPO, "scripts"))
from quilt_common import RESOLVE_PROMPT_SIGNATURE as _RESOLVE_PROMPT_SIGNATURE
APP_ROOT = os.environ.get("APP_ROOT", os.path.join(REPO, "apps", "mail"))
BUILD = os.path.join(APP_ROOT, ".build")
PATCHES = os.path.join(APP_ROOT, "patches")
OVERLAY = os.path.join(APP_ROOT, "overlay")
LOCK_FILE = os.path.join(BUILD, ".lock")

# GIT_ENV: safe.directory='*' for working on the bind-mounted .build/
# under a different uid; user.email/name defaults so commits and
# stashes don't fail on a machine without global git identity (CI,
# fresh dev1 host). The test's git operations all flow through this.
GIT_ENV = {
    **os.environ,
    "GIT_CONFIG_COUNT": "3",
    "GIT_CONFIG_KEY_0": "safe.directory",
    "GIT_CONFIG_VALUE_0": "*",
    "GIT_CONFIG_KEY_1": "user.email",
    "GIT_CONFIG_VALUE_1": "state-integrity@local",
    "GIT_CONFIG_KEY_2": "user.name",
    "GIT_CONFIG_VALUE_2": "state-integrity",
}

SESSION = "state-integrity-tmux"

# Real mail file paths used deterministically across the chaos arc.
# Verified to exist in apps/mail/.build/ at the time of writing. If
# mail's upstream layout changes, these need updating — same coupling
# as the rest of the integration suite.
PATCHED_PHP_A = "lib/Account.php"
PATCHED_PHP_B = "lib/Service/MailManager.php"
PATCHED_PHP_C = "lib/Service/Sync/ImapToDbSynchronizer.php"
PATCHED_PHP_D = "lib/Service/DraftsService.php"
PATCHED_PHP_E = "lib/Service/MailTransmission.php"
# Persistent uncommitted edit target for the (d) addendum — a patched
# file that the arc never routes from. Touched by ai-processing-disabled
# (series pos 9) and tags-itsl (pos 11), so every plain-assemble's
# pop+push exercises those patches' apply with the persistent edit
# floating on top. Restore-skipped runs over this file at every save
# in phases 1-7; phase 7's manual cleanup reverts via git checkout
# baseline -- <file>.
PERSISTENT_PATCHED_FILE = "lib/Controller/PageController.php"
COPYFILE_COMPOSER = "composer.json"
# Files under .build/src/itsl/ are overlay-symlinked: writing through
# .build/<path> follows the symlink and lands in apps/mail/overlay/<path>.
# .build's git-baseline doesn't see the change (the symlink's target is
# unchanged); the parent repo's overlay/ tree picks it up. These
# edits are out of scope for `make quilt` — the dev's edit went
# straight to overlay/, no patch needed, no diff against baseline.
OVERLAY_SYMLINKED_VUE_A = "src/itsl/components/EnvelopeItsl.vue"
OVERLAY_SYMLINKED_VUE_B = "src/itsl/components/TagItem.vue"
OVERLAY_SYMLINKED_VUE_C = "src/itsl/components/TagPopover.vue"
NEW_FILE_EXISTING_DIR = "lib/Service/AccountThrottle.php"
NEW_FILE_NEW_DIR_TREE_A = "lib/AI/Throttle/RateLimiter.php"
NEW_FILE_NEW_DIR_TREE_B = "lib/AI/Throttle/policies/Default.php"
CHMOD_TARGET = "lib/functions.php"
DELETE_PATCHED_TARGET = "AUTHORS.md"  # upstream file; not in any patch
# OverlayDeletion / OverlayRevert test targets must be LEAF symlinks
# (per F1's audit). When upstream provides the parent directory,
# link-overlay creates per-file symlinks at the leaves; deleting one
# of those is what extract classifies as a deletion against an
# overlay-managed path via "+++ /dev/null" + the overlay-exists check.
# The previous targets (src/itsl/components/<x>.vue) sit under
# .build/src/itsl which is a single dir-tree symlink (upstream has no
# src/itsl/) — leaf .vue files there are regular files accessed
# through the parent symlink, so os.path.islink returns False and
# delete_overlay_symlink raised "fixture mismatch". Verified on disk
# at audit time:
#   .build/appinfo/info.xml         islink=True
#   .build/tests/phpunit.itsl.xml   islink=True
#
# the two targets also exercise the two upstream-counterpart
# branches of the OverlayDeletion/OverlayRevert classification:
#   appinfo/info.xml — upstream/appinfo/info.xml exists (Nextcloud's
#     mail manifest) → extract emits OverlayRevert, prompt is
#     "[r]estore from upstream  [s]kip:".
#   tests/phpunit.itsl.xml — ITSL-only file, no upstream counterpart
#     → extract emits OverlayDeletion, prompt is "[d]elete from
#     overlay  [s]kip:".
# Keeping both target shapes in the arc is load-bearing — losing
# either loses coverage of one classification branch.
DELETE_OVERLAY_SYMLINK_TARGET = "appinfo/info.xml"
DELETE_OVERLAY_SYMLINK_TARGET_B = "tests/phpunit.itsl.xml"

# Test-introduced scratch files in .build/. Populated dynamically by
# drop_scratch_file as the arc runs; consumed by differential_check
# (Phase 9) to exclude these paths from the byte-comparison — they're
# not reproducible from apps/mail/ source, they're test artifacts the
# test added intentionally. A hardcoded constant would silently drift
# whenever a new drop_scratch_file call landed without updating the
# list; the dynamic shape couples the two.
_DROPPED_SCRATCH_PATHS = []

# The chaos arc runs entirely on a temp branch created from the
# operator's actual working branch (typically mail-patch-rework). Saves
# during the arc commit to this branch, accumulating real history.
# Between phase 7 and phase 8, the test switches BACK to the original
# branch and runs `make assemble` plain — exercising the contract that
# branch motion + plain assemble correctly re-syncs `.build/` to the
# new branch's `patches/`. The temp branch is dropped at suite cleanup.
#
# Why a temp branch instead of committing on the operator's branch:
# the operator's branch tip is load-bearing (the R2 Stage A stash on
# mail-patch-rework, the spec's "don't move the branch tip" rule).
# All commits land on the temp ref; cleanup deletes the ref; the
# operator's branch is untouched.
ARC_BRANCH = "state-integrity-arc"

# Captured at suite-start. The .build/.gitignore byte-set should be
# constant across all chaos-arc points: write-baseline.sh writes
# nothing into .gitignore (write-baseline.sh's git add -A respects
# whatever's already there but never modifies it), so the file
# content is whatever upstream
# provides plus whatever was already on disk. Drift across chaos-arc
# steps means something is silently rewriting the file — the regression
# this guards against.
SUITE_START_GITIGNORE_BYTES = None

# The branch the operator was on when the test started (typically
# mail-patch-rework). Captured in capture_suite_start so the end-of-arc
# round-trip step can switch back to it without parameter-passing
# through chaos_arc's call chain.
ORIGINAL_BRANCH = None

# Counters for summary
STEP_NUM = 0
SUBSTEP_LETTER = ""
PASSED = 0
FAILED = 0


class StateIntegrityFailure(Exception):
    """Raised by check() when an assertion fails.

    Per operator's fail-fast directive: the chaos arc is one continuous
    workday narrative. Once a step goes off-script, every downstream
    invariant and answer is operating against an unintended state, and
    the failures it produces are noise. The first real failure stops
    the arc; main()'s except clause catches this and runs cleanup.

    Cleanup is best-effort — wraps its own check() calls in try/except
    via _cleanup_step() so a failure in (say) C03 doesn't prevent
    C04-C09 from trying. See cleanup_to_suite_start.
    """


# ---------------------------------------------------------------------------
# Output helpers
# ---------------------------------------------------------------------------

def step(label):
    """Print the step header and bump the step counter.

    Resets SUBSTEP_LETTER so check IDs in this step revert to the
    bare S{STEP_NUM:03d}-* form (any prior step's substeps don't
    leak into this one).
    """
    global STEP_NUM, SUBSTEP_LETTER
    STEP_NUM += 1
    SUBSTEP_LETTER = ""
    print(f"\n[STEP {STEP_NUM:03d}] {label}")


def substep(label):
    """Print a sub-step sharing the parent step's STEP_NUM with a
    letter suffix (b, c, d, ...). Parent step is implicitly 'a'.

    Use when adding test coverage to existing arc structure without
    shifting downstream STEP_NUM cross-references in narrative
    comments and content markers (e.g. an `edit_patched_file(...,
    "step75")` injection elsewhere). step() resets the suffix; each
    substep() bumps it.

    Check IDs in substep code should use
    f"S{STEP_NUM:03d}{SUBSTEP_LETTER}-..." (collision-free with the
    parent step's bare-form S{STEP_NUM:03d}-* IDs).
    """
    global SUBSTEP_LETTER
    SUBSTEP_LETTER = "b" if SUBSTEP_LETTER == "" else chr(ord(SUBSTEP_LETTER) + 1)
    print(f"\n[STEP {STEP_NUM:03d}{SUBSTEP_LETTER}] {label}")


def phase(label):
    """Print a phase banner."""
    bar = "=" * 70
    print(f"\n{bar}\n{label}\n{bar}")


def check(test_id, condition, detail=""):
    """Assert a condition; print PASS, or print FAIL and raise.

    Fail-fast per operator's Mark 2 directive: a failed check stops
    the arc. The cascade of "everything after the first failure also
    fails" that plagued Mark 1 produces noise that obscures the real
    failure. One root cause, one stop.
    """
    global PASSED, FAILED
    if condition:
        PASSED += 1
        print(f"    PASS {test_id}")
    else:
        FAILED += 1
        print(f"    FAIL {test_id}: {detail}")
        raise StateIntegrityFailure(f"{test_id}: {detail}")


def info(msg):
    """Print an informational message indented under the current step."""
    print(f"    {msg}")


# ---------------------------------------------------------------------------
# Subprocess helpers (host-side and via dc-run.sh)
# ---------------------------------------------------------------------------

def _run(cmd, *, cwd=None, env=None, check=False, timeout=120, input_text=None):
    """Run cmd as a subprocess, return CompletedProcess.

    Wrapper that defaults to capture_output=True + text=True for the
    test's needs, with check=False so the caller decides what's fatal.
    """
    return subprocess.run(
        cmd, cwd=cwd, env=env, check=check, timeout=timeout,
        input=input_text, capture_output=True, text=True)


def _make_env(**extra):
    """Return env dict suitable for `make X` and direct dc-run.sh invocations.

    DEVELOPER_UID/GID auto-detect from host (mirroring the Makefile's
    `?= $(shell id -u)` chain) so tests that invoke dc-run.sh directly
    (bypassing make) satisfy dc-run.sh's hard-require that DEVELOPER_UID
    and DEVELOPER_GID are set in the environment before it invokes
    `docker compose run` — without them the compose interpolation falls
    back to the `:-1000` default and the container writes land as the
    wrong UID for any non-1000 host.
    User-shell DEVELOPER_UID/GID still wins (os.environ overrides defaults).
    """
    env = {
        "DEVELOPER_UID": str(os.getuid()), "DEVELOPER_GID": str(os.getgid()),
        **os.environ,
        "APP_ROOT": APP_ROOT,
    }
    env.update(extra)
    return env


def make_assemble(mode=None, *, expect_rc=0, timeout=600):
    """Invoke `make assemble [MODE=force|discard|recover]` and return rc.

    expect_rc=None means "don't check"; any other value triggers a
    check entry. Timeout is generous because MODE=force's full reinstall
    can take minutes on a populated app.
    """
    args = ["make", "assemble"]
    if mode == "FORCE":
        args.append("MODE=force")
    elif mode == "DISCARD":
        args.append("MODE=discard")
    elif mode == "RECOVER":
        args.append("MODE=recover")
    elif mode is not None:
        raise ValueError(f"unknown assemble mode: {mode!r}")
    info(f"  $ {' '.join(args)}")
    r = _run(args, cwd=APP_ROOT, env=_make_env(), timeout=timeout)
    if expect_rc is not None:
        check(
            f"S{STEP_NUM:03d}{SUBSTEP_LETTER}-assemble-{mode or 'plain'}-rc",
            r.returncode == expect_rc,
            f"rc={r.returncode}, expected {expect_rc}; "
            f"stdout={r.stdout if r.stdout else ''}; "
            f"stderr={r.stderr if r.stderr else ''}")
    return r


def make_diff(*, expect_rc=0):
    """Invoke `make diff` and return rc.

    `scripts/diff.sh` always exits 0 in normal flow — it captures git
    diff output and prints it, falling through to `exit 0` regardless
    of whether changes are present (verified: every code path hits
    explicit exit 0 or end-of-script with no error). The only
    non-zero path is `[ ! -d .build/.git ]` exiting 1, which the
    chaos arc never triggers (.build/ is always present at suite-
    start and never wiped mid-arc). Pinning to expect_rc=0; if a
    future regression breaks this, the test surfaces it.
    """
    info("  $ make diff")
    r = _run(["make", "diff"], cwd=APP_ROOT, env=_make_env(), timeout=60)
    check(
        f"S{STEP_NUM:03d}{SUBSTEP_LETTER}-diff-rc",
        r.returncode == expect_rc,
        f"rc={r.returncode}, expected {expect_rc}; "
        f"stdout={r.stdout if r.stdout else ''}; "
        f"stderr={r.stderr if r.stderr else ''}")
    return r


# make_quilt_noninteractive removed: non-interactive UX is gone (per
# interface — the dev never invokes quilt directly). All make-quilt
# invocations from this test now use
# make_quilt_interactive (tmux + scripted prompt answers). The two
# specialised cases the old helper served:
#   - "no answers needed, just run and observe rc" (e.g. recovery flow
#     that exits before any prompt) → make_quilt_interactive([], ...)
#   - "die mid-flight to test recovery" → real-corruption setup
#     (sabotage_patch_file / synthesize_pending_state) before invoking
#     make quilt; the platform's natural failure path leaves the same
#     pending tag a kill would have, and recovery exercises the same
#     code on the next run.


# ---------------------------------------------------------------------------
# tmux harness for interactive `make quilt`
# Modeled on test_interactive.py's pattern (tmux_start / wait_for / send /
# kill). Generic enough to run scripted answer sequences for any prompt
# combination the chaos arc needs.
# ---------------------------------------------------------------------------

def tmux_kill():
    """Kill the named tmux session (no-op if absent)."""
    subprocess.run(["tmux", "kill-session", "-t", SESSION],
                   capture_output=True)


def tmux_start(extra_env=None):
    """Start a tmux session running `make quilt` with optional inline env.

    extra_env: dict of NAME -> value. Each gets prepended to the make
    invocation as `NAME=value`, which the bash recipe then exports into
    the dc-run.sh subshell. shlex.quote on values to defend against
    metacharacters (paths with spaces, header text with quotes, etc.).

    `set -m` enables job control inside the wrapper bash so make
    quilt runs in its own process group, separate from the wrapper
    bash itself. tmux's Ctrl-C sends SIGINT to the foreground
    process group of the tty — with `set -m` active that's make
    quilt's group, not the wrapper's. Necessary for the wrapper to
    have any chance of surviving the Ctrl-C and printing the
    `===EXITCODE:N===` sentinel + sleeping for the test to capture.

    Necessary but NOT sufficient on its own. POSIX shell rule:
    when a non-interactive shell's foreground command dies via
    signal-death (rather than exiting with a clean rc), the shell
    propagates the signal to itself. Empirically:
    - make → bash recipe → dc-run.sh → docker → python catching
      SIGINT and `sys.exit(130)` produces a clean rc through the
      whole chain (docker CLI catches SIGINT, exits with the
      container's rc; recipe-bash exits 130; make wraps to rc=2);
      wrapper sees clean rc=2 and survives.
    - But Ctrl-C sent into the wrapper's post-make `sleep 60` (the
      window after `make quilt` already returned) kills sleep via
      signal-death; the propagation rule kicks in; wrapper dies;
      tmux session ends; subsequent captures return empty pane.

    The latter case is handled at the test-code layer in
    `make_quilt_interactive`'s early-completion detection — see
    that function's per-answer `wait_for not in content and
    ===EXITCODE in content` check. Don't add shell-level signal
    trickery here on top of `set -m`; the test-code surface is
    where the right signal lives.
    """
    tmux_kill()
    env_prefix = ""
    if extra_env:
        env_prefix = " ".join(
            f"{k}={shlex.quote(v)}" for k, v in extra_env.items()) + " "
    inner = (f"set -m; "
             f"cd {shlex.quote(APP_ROOT)} && "
             f"APP_ROOT={shlex.quote(APP_ROOT)} "
             f"{env_prefix}make quilt; "
             f'echo "===EXITCODE:$?==="; sleep 60')
    subprocess.run(
        ["tmux", "new-session", "-d", "-s", SESSION,
         "-x", "200", "-y", "50", inner],
        cwd=APP_ROOT)
    time.sleep(2)


def tmux_send(*keys):
    """Send each key (followed by Enter) to the tmux session."""
    for key in keys:
        subprocess.run(["tmux", "send-keys", "-t", SESSION, key, "Enter"])
        time.sleep(0.8)


def tmux_capture():
    """Capture the visible tmux pane content (last 200 lines)."""
    r = subprocess.run(
        ["tmux", "capture-pane", "-t", SESSION, "-p", "-S", "-200"],
        capture_output=True, text=True)
    return r.stdout


def tmux_wait_for(text, timeout=30):
    """Wait up to timeout seconds for `text` to appear in the pane."""
    for _ in range(timeout * 2):
        content = tmux_capture()
        if text in content:
            return content
        time.sleep(0.5)
    return tmux_capture()


_SUCCESS_EXITCODE_SUBSTR = "===EXITCODE:0==="


def make_quilt_interactive(answers,
                           expect_exitcode_substr=_SUCCESS_EXITCODE_SUBSTR):
    """Run `make quilt` interactively via tmux with scripted answers.

    answers: list of (wait_for_text, response) tuples. For each tuple,
        wait for wait_for_text in the pane, then handle the response.
        Two response shapes:
          - str: sends the string (followed by Enter) via tmux
            send-keys. E.g. ("Add to:", "accessibility") sends
            "accessibility\\n" at the prompt. The early-completion
            check (see below) only fires for string responses — they
            assume the prompt fires; if it didn't, blindly sending
            the string into the wrapper's post-make `sleep 60` is
            either a no-op or a self-inflicted SIGINT (for "C-c").
          - callable: takes no arguments. Runs at the wait point;
            owns whatever sends it needs (tmux send-keys, file writes,
            etc.). Used for resolve-the-conflict-markers scenarios
            where the test has to mutate files between the prompt
            firing and the dev's "Enter to continue." Callables own
            the no-prompt-and-save-completed decision themselves;
            the early-completion check skips them.
        Pass [] for runs expected to exit before any prompt (recovery
        flow, plain "no changes", lock-held).

    expect_exitcode_substr: the substring to assert in the post-run
        pane content. tmux sees `make`'s exit code (which wraps any
        non-zero recipe exit to 2), so kill / abort / nothing-to-save
        scenarios all surface as "===EXITCODE:2===".

    EDITOR is always set to `true`. The post-save patch-header
    editor branch in quilt_v2._edit_patch_header still fires for new
    patches (the temp file gets initialised with `patch_name + "\\n"`,
    `true` exits without modifying, the file's still non-empty so
    `quilt header -r` still runs). True-as-editor is enough to exercise
    the branch end-to-end.

    Auto-commit on successful save: when expect_exitcode_substr is the
    default "success" sentinel AND this is not a recovery call, the
    function calls commit_arc_progress() to commit patches/+overlay/
    mutations to ARC_BRANCH. Recovery is detected via the pending tag
    being present at entry — if make-quilt's recovery path is about to
    run, we suppress the post-call commit so that uncommitted
    parent-repo state the test deliberately left dirty doesn't get
    silently absorbed into ARC_BRANCH. Skip-only / nothing-to-save /
    failed-save runs (caller passes "===EXITCODE:2===") don't trigger
    the commit at all.

    Returns the final captured pane content (for caller assertions).
    """
    info(f"  $ tmux make quilt (interactive, answers={len(answers)})")
    # Detect recovery: pending tag at entry means make-quilt's recovery
    # path will fire and exit 0 without performing a real save. The
    # post-call commit must be suppressed in that case — see docstring.
    pending_check = _run(
        ["git", "rev-parse", "--verify", "--quiet",
         "refs/tags/make-quilt-pending"],
        cwd=BUILD, env=GIT_ENV)
    is_recovery = (pending_check.returncode == 0)
    tmux_start(extra_env={"EDITOR": "true"})
    try:
        for wait_for, response in answers:
            content = tmux_wait_for(wait_for, timeout=30)
            # Early-completion detection (string responses only):
            # make quilt may have exited (printing `===EXITCODE:N===`)
            # without firing the awaited prompt — e.g., the test
            # expected a conflict-pause that didn't trigger because
            # the dev's hunk applied cleanly. The wrapper bash is
            # then sitting in the post-make `sleep 60`; blindly
            # invoking `tmux_send(string_response)` here would either
            # be lost or, for a Ctrl-C answer, kill the sleep via
            # SIGINT. Killing the sleep makes the non-interactive
            # wrapper bash propagate SIGINT to itself (POSIX shell
            # rule), wrapper dies, tmux session ends, all subsequent
            # captures return empty — producing an opaque "empty pane"
            # downstream symptom that hides the real cause (the
            # prompt didn't fire). Surface clearly instead, naming
            # the missing prompt and showing the actual exit-code
            # context.
            #
            # Callable responses are themselves designed to handle
            # the no-prompt-and-save-completed case (see
            # `_resolve_any_conflict_markers` for the canonical
            # example — its inner loop returns immediately when
            # `===EXITCODE` appears in pane scrollback, regardless
            # of whether the prompt fired this iteration). Skip the
            # detection for callables; they own the no-prompt
            # decision and tolerating the case is part of their
            # contract (the cascade resolver in particular is used
            # at sites where "cascade may or may not fire" is the
            # documented platform behavior).
            if (not callable(response)
                    and wait_for not in content
                    and "===EXITCODE" in content):
                check(
                    f"S{STEP_NUM:03d}{SUBSTEP_LETTER}-quilt-interactive-prompt",
                    False,
                    f"expected prompt {wait_for!r} before make quilt "
                    f"exited, but make quilt completed without firing "
                    f"it. The platform's behavior diverged from this "
                    f"answer set's expectation; either the test setup "
                    f"didn't trigger the conflict/prompt the answer "
                    f"set anticipates, or the platform stopped firing "
                    f"the prompt at this site. Pane (last 2000 chars):"
                    f"\n{content[-2000:]}")
                # check() raises StateIntegrityFailure on False; the
                # finally block's tmux_kill cleans up the (still-alive)
                # wrapper session. Never reached past here.
            if callable(response):
                response()
            else:
                tmux_send(response)
        # Wait for the EXITCODE marker the wrapper shell emits.
        content = tmux_wait_for("===EXITCODE", timeout=120)
        if expect_exitcode_substr not in content:
            # Failure path — surface as much pane content as we can
            # so the operator can see what actually happened.
            # `-S -` captures from the start of available scrollback
            # (capped at tmux's history-limit, default 2000 lines).
            # `tmux_capture`'s normal `-S -200` is fine for the
            # per-iteration polling — it returns whatever's in the
            # pane up to 200 lines above the visible top — but on
            # the failure path we want maximum context for diagnosing
            # what diverged. For long-running saves with hundreds of
            # lines of progress output, `-S -` carries the early
            # diagnostic lines that `-S -200` would have truncated.
            full = subprocess.run(
                ["tmux", "capture-pane", "-t", SESSION, "-p", "-S", "-"],
                capture_output=True, text=True).stdout
            check(
                f"S{STEP_NUM:03d}{SUBSTEP_LETTER}-quilt-interactive-exit",
                False,
                f"expected '{expect_exitcode_substr}'; "
                f"full pane:\n{full}")
        else:
            check(
                f"S{STEP_NUM:03d}{SUBSTEP_LETTER}-quilt-interactive-exit",
                True,
                "")
        # Commit only on successful real saves: the caller didn't
        # override the exit-substr to "===EXITCODE:2===" (failure /
        # skip-only path), AND this isn't a recovery call (pending tag
        # at entry — see is_recovery above). Recovery flows don't
        # mutate patches/+overlay/, but git add patches overlay would
        # still stage anything else dirty that the test deliberately
        # left there to be tested against; suppressing the commit on
        # recovery preserves that test setup.
        if expect_exitcode_substr == _SUCCESS_EXITCODE_SUBSTR and not is_recovery:
            commit_arc_progress(f"step{STEP_NUM:03d}")
        return content
    finally:
        tmux_kill()


# Cross-file coupling note: _RESOLVE_PROMPT_SIGNATURE is imported from
# scripts/quilt_common.py at the top of this file. Single-sourced
# constant; quilt_write.py emits it at both prompt sites (routed +
# cascade contexts) by interpolation, and the smart resolver below
# counts occurrences in the tmux pane to know when a NEW cascade
# prompt has fired vs an already-handled one earlier in the same save.


def _strip_conflict_markers_in_build():
    """Strip every <<<<<<< / ======= / >>>>>>> marker line from files
    in .build/ that contain them. Skips symlinks (overlay-managed).
    Prunes node_modules/vendor/etc. — never quilt-managed.

    Pure file-tree mutation; no tmux interaction. Returns the count of
    files modified (for observability if a caller wants it).
    """
    modified = 0
    for dirpath, dirnames, filenames in os.walk(BUILD, followlinks=False):
        dirnames[:] = [d for d in dirnames if d not in
                       {"node_modules", "vendor", "vendor-bin",
                        ".git", ".pc"}]
        for name in filenames:
            full = os.path.join(dirpath, name)
            if os.path.islink(full):
                continue
            try:
                with open(full, "rb") as f:
                    content = f.read()
            except OSError:
                continue
            if b"<<<<<<<" not in content:
                continue
            cleaned = b"\n".join(
                line for line in content.split(b"\n")
                if not (line.startswith(b"<<<<<<<") or
                        line.startswith(b"=======") or
                        line.startswith(b">>>>>>>"))
            )
            with open(full, "wb") as f:
                f.write(cleaned)
            modified += 1
    return modified


def _resolve_any_conflict_markers():
    """Strip conflict markers and Enter through any number of consecutive
    pause-and-continue prompts until the save completes (EXITCODE
    appears in pane) or no further prompt fires within timeout.

    Generic stand-in for a dev opening their editor, picking a side, and
    pressing Enter at each platform prompt. The exact resolved content
    doesn't matter for the platform contract; what matters is that the
    flow proceeds. Used as the default response for any "Resolve the
    markers" prompt across the arc.

    Multi-cascade-safe. The platform can pause multiple
    times in one save: once for routed-hunks-conflict (existing) and
    once per cascading-downstream-push-conflict (new). Each prompt
    needs its own strip+Enter. A single answer-entry calling this
    helper handles all of them by counting the prompt signature in
    pane scrollback: each successive iteration only proceeds when a
    NEW prompt has fired (count increased) — never racing ahead of
    the platform.

    Why not multiple ("Resolve the markers", _resolve_any_conflict_markers)
    answer entries: tmux_wait_for matches a substring in pane history,
    so a 2nd "Resolve the markers" entry would match instantly via
    the 1st cascade's residual prompt text without waiting for the
    2nd cascade to actually fire. The resolver would scan an empty
    tree (no markers yet), send Enter into the stdin buffer, and the
    2nd cascade's input() would consume the premature Enter — silent
    save corruption (refresh captures whatever the working tree
    currently looks like, including unresolved markers from the 2nd
    cascade). The count-based loop here avoids that race by tying
    iteration to platform progress.
    """
    # Capture full scrollback (not the default -200) so prev_count and
    # subsequent count() calls are comparable across deep cascades. Each
    # cascade's prompt prints ~15 lines; with -200, ~12 cascades fills
    # the window and earlier prompts scroll off, breaking the count
    # invariant. -S - captures from start-of-available-history (default
    # history-limit=2000 lines), comfortably covering any plausible
    # cascade depth.
    def _full_pane():
        return subprocess.run(
            ["tmux", "capture-pane", "-t", SESSION, "-p", "-S", "-"],
            capture_output=True, text=True, check=True).stdout

    while True:
        # Snapshot prompt count BEFORE this iteration's strip+Enter. The
        # current prompt is included in the count; after Enter, we wait
        # for either EXITCODE (save done) or count > prev_count (next
        # cascade fired its own prompt).
        prev_count = _full_pane().count(_RESOLVE_PROMPT_SIGNATURE)
        _strip_conflict_markers_in_build()
        # Send Enter to release the platform's input() at the current
        # prompt. subprocess.run directly because tmux_send appends an
        # extra Enter we'd then have to deal with.
        subprocess.run(
            ["tmux", "send-keys", "-t", SESSION, "Enter"],
            check=True)
        # Wait for next state. 30s budget per cascade is conservative —
        # the platform's per-iteration work (refresh + push next patches)
        # is sub-second normally; allow headroom for npm/composer-busy
        # systems.
        deadline = time.time() + 30
        while time.time() < deadline:
            content = _full_pane()
            if "===EXITCODE" in content:
                # Save completed (or failed); outer flow handles the
                # exit code.
                return
            if content.count(_RESOLVE_PROMPT_SIGNATURE) > prev_count:
                # New cascade prompt fired; outer loop iterates.
                break
            time.sleep(0.5)
        else:
            # Neither EXITCODE nor a new prompt within 30s. Most likely
            # the platform is doing slow work (e.g. quilt push of many
            # patches across the series); the outer make_quilt_interactive
            # loop's tmux_wait_for("===EXITCODE") with its own 120s
            # budget is the next layer to surface this. Return so
            # control flows back there.
            return


# ---------------------------------------------------------------------------
# Working-surface ops (.build/ — where the developer works)
# ---------------------------------------------------------------------------

def edit_patched_file(rel, marker):
    """Insert a marker comment at line 2 of .build/<rel>.

    The file may or may not currently be patched — for our purposes,
    what matters is the edit produces a diff against baseline that
    `git diff baseline` sees as a Hunk.
    """
    full = os.path.join(BUILD, rel)
    with open(full, "r", encoding="utf-8", errors="surrogateescape") as f:
        lines = f.readlines()
    if not lines:
        lines = ["\n"]
    lines.insert(1, f"// state-integrity: {marker}\n")
    with open(full, "w", encoding="utf-8", errors="surrogateescape") as f:
        f.writelines(lines)


def edit_overlay_symlinked(rel, marker):
    """Insert a marker through an overlay symlink in .build/<rel>.

    Writing through .build/src/itsl/<x> follows the symlink to
    apps/mail/overlay/src/itsl/<x>. .build/'s git-baseline
    diff doesn't see this — the symlink target is unchanged. The
    parent repo's overlay/ working tree DOES see it.
    """
    full = os.path.join(BUILD, rel)
    with open(full, "r", encoding="utf-8", errors="surrogateescape") as f:
        content = f.read()
    content = f"// state-integrity: {marker}\n" + content
    with open(full, "w", encoding="utf-8", errors="surrogateescape") as f:
        f.write(content)


def edit_copyfile(rel, marker):
    """Insert a marker into .build/<rel> where rel is copyfile-managed.

    composer.json is a copyfile (real file in .build/, copied from
    overlay by link-overlay's copyfile mechanism). Edits land in the
    real .build/<rel> file and are visible to `git diff baseline`.
    Routing this via FILE= in make quilt sends the edit back to overlay/.
    """
    full = os.path.join(BUILD, rel)
    with open(full, "r", encoding="utf-8") as f:
        content = f.read()
    # composer.json is JSON; insert the marker as a comment in a way
    # that survives parsing... actually JSON has no comments, so insert
    # via a fake top-level key. Tolerated by composer (warns) and visible
    # in the diff.
    if rel.endswith(".json") and content.lstrip().startswith("{"):
        idx = content.index("{") + 1
        injected = f'\n    "_state_integrity_{marker}": "marker",'
        content = content[:idx] + injected + content[idx:]
    else:
        content = f"// state-integrity: {marker}\n" + content
    with open(full, "w", encoding="utf-8") as f:
        f.write(content)


def new_file_in_existing_dir(rel, content):
    """Create a brand-new file at .build/<rel> in an existing dir.

    Extract classifies this as a NewFile (parent dir is tracked).
    """
    full = os.path.join(BUILD, rel)
    parent = os.path.dirname(full)
    if not os.path.isdir(parent):
        raise RuntimeError(
            f"new_file_in_existing_dir({rel!r}): parent {parent!r} not a "
            f"directory — fixture mismatch")
    with open(full, "w", encoding="utf-8") as f:
        f.write(content)


def new_file_in_new_dir_tree(rel, content):
    """Create a file in a brand-new directory tree under .build/.

    Extract classifies this as NewOverlayDir (parent dir doesn't exist
    in baseline). Creates the parent dirs as needed.
    """
    full = os.path.join(BUILD, rel)
    parent = os.path.dirname(full)
    os.makedirs(parent, exist_ok=True)
    with open(full, "w", encoding="utf-8") as f:
        f.write(content)


def delete_patched_file(rel):
    """Delete .build/<rel>. Produces a deletion hunk vs baseline."""
    full = os.path.join(BUILD, rel)
    if not os.path.exists(full):
        raise RuntimeError(
            f"delete_patched_file({rel!r}): file doesn't exist — "
            f"fixture mismatch")
    os.unlink(full)


def delete_overlay_symlink(rel):
    """Delete an overlay symlink under .build/<rel>.

    The target file (apps/mail/overlay/<rel>) is NOT deleted by this op
    — only the symlink in .build/. Visible to extract
    which classifies as OverlayDeletion (no upstream counterpart) or
    OverlayRevert (upstream has the same path).
    """
    full = os.path.join(BUILD, rel)
    if not os.path.islink(full):
        raise RuntimeError(
            f"delete_overlay_symlink({rel!r}): not a symlink — "
            f"fixture mismatch")
    os.unlink(full)


def chmod_file(rel, mode):
    """chmod .build/<rel>. Produces a PermissionChange item."""
    full = os.path.join(BUILD, rel)
    os.chmod(full, mode)


def drop_scratch_file(path, content=""):
    """Create an arbitrary scratch file. `path` is absolute.

    Records the path (relative to BUILD) in _DROPPED_SCRATCH_PATHS for
    later consumption by differential_check, which excludes scratch
    files from the byte-equivalence comparison since they're not
    reproducible from apps/mail/ source.
    """
    parent = os.path.dirname(path)
    if parent:
        os.makedirs(parent, exist_ok=True)
    with open(path, "w", encoding="utf-8") as f:
        f.write(content)
    if path.startswith(BUILD + os.sep):
        _DROPPED_SCRATCH_PATHS.append(os.path.relpath(path, BUILD))


def scatter_debug_prints(rels, marker):
    """Insert a line-2 debug-print marker into multiple files at once.

    Same shape as edit_patched_file (line-2 prepend). The previous
    end-of-file-append shape was deliberately chosen to avoid cascading
    mid-stack apply conflicts in phases 4-7 — but cascade conflicts are
    exactly the platform behavior the chaos arc should expose, not
    engineer around. With line-2 prepends, scatter contributions sit in
    line-2-area context; subsequent saves routing same-file edits into
    mid-stack patches hit cascade conflicts; the generic resolver
    handles them. The chaos arc surfaces real platform behavior under
    accumulated state instead of avoiding it.
    """
    for rel in rels:
        full = os.path.join(BUILD, rel)
        with open(full, "r", encoding="utf-8", errors="surrogateescape") as f:
            lines = f.readlines()
        if not lines:
            lines = ["\n"]
        lines.insert(1, f"// DEBUG state-integrity {marker}\n")
        with open(full, "w", encoding="utf-8", errors="surrogateescape") as f:
            f.writelines(lines)


def edit_then_revert(rel, marker):
    """Edit a file then write its original bytes back. mtime churns
    but git sees no semantic change.
    """
    full = os.path.join(BUILD, rel)
    with open(full, "rb") as f:
        original = f.read()
    with open(full, "w", encoding="utf-8", errors="surrogateescape") as f:
        f.write(f"// transient {marker}\n"
                + original.decode("utf-8", errors="surrogateescape"))
    # mtime tweak: write bytes back. Python's open+write may not change
    # mtime semantically but the content matches.
    with open(full, "wb") as f:
        f.write(original)


# ---------------------------------------------------------------------------
# Accidental-violence ops (apps/mail/ — parent repo)
# ---------------------------------------------------------------------------

def random_file_in_apps_mail(relpath, content=""):
    """Drop a file at apps/mail/<relpath> (creates parent dirs)."""
    full = os.path.join(APP_ROOT, relpath)
    parent = os.path.dirname(full)
    if parent:
        os.makedirs(parent, exist_ok=True)
    with open(full, "w", encoding="utf-8") as f:
        f.write(content)


def hand_edit_patch_file(patch_name, marker):
    """Hand-edit apps/mail/patches/<patch_name>.patch by appending a
    bogus line at the end. May or may not break the patch's apply
    semantics — that's the point of "accidental violence."

    Returns the path of the edited patch file so callers can clean up.
    """
    if not patch_name.endswith(".patch"):
        patch_name += ".patch"
    full = os.path.join(PATCHES, patch_name)
    if not os.path.exists(full):
        raise RuntimeError(
            f"hand_edit_patch_file({patch_name!r}): no such patch — "
            f"fixture mismatch")
    with open(full, "a", encoding="utf-8") as f:
        # Comment-style trailer; quilt patches sometimes carry trailing
        # content past the diff body. May or may not still apply cleanly
        # depending on whether quilt parses past the diff hunks.
        f.write(f"\n# state-integrity hand-edit: {marker}\n")
    return full


def hand_edit_overlay_via_parent(rel, marker):
    """Hand-edit apps/mail/overlay/<rel> directly (not through a .build/
    symlink). Produces uncommitted state in the parent repo.
    """
    full = os.path.join(OVERLAY, rel)
    if not os.path.exists(full):
        raise RuntimeError(
            f"hand_edit_overlay_via_parent({rel!r}): no such overlay file — "
            f"fixture mismatch")
    with open(full, "r", encoding="utf-8") as f:
        content = f.read()
    if rel.endswith(".json") and content.lstrip().startswith("{"):
        idx = content.index("{") + 1
        injected = f'\n    "_hand_edit_{marker}": "marker",'
        content = content[:idx] + injected + content[idx:]
    else:
        content = f"// hand-edit: {marker}\n" + content
    with open(full, "w", encoding="utf-8") as f:
        f.write(content)


def make_half_committed_parent(commit_path, dirty_path,
                               commit_marker, dirty_marker):
    """Create half-committed parent state.

    1. Hand-edit commit_path (a path under apps/mail/), git add + commit.
    2. Hand-edit dirty_path (also under apps/mail/), leave uncommitted.

    Both paths are relative to APP_ROOT. The commit lands on the current
    branch — typically ARC_BRANCH during the chaos arc. Cleanup C03's
    git reset --hard back to suite-start commit drops it (after
    switching to ORIGINAL_BRANCH); cleanup C06 drops ARC_BRANCH itself,
    eliminating any lingering reference to the commit.
    """
    # 1. Edit + commit. user.email/name come from GIT_ENV (set in module
    # scope); no need for `-c` overrides on the commit invocation.
    full_commit = os.path.join(APP_ROOT, commit_path)
    with open(full_commit, "a", encoding="utf-8") as f:
        f.write(f"\n# half-committed: {commit_marker}\n")
    _run(["git", "add", commit_path], cwd=APP_ROOT,
         env=GIT_ENV, check=True)
    _run(["git", "commit", "-m",
          f"state-integrity half-commit: {commit_marker}"],
         cwd=APP_ROOT, env=GIT_ENV, check=True)
    # 2. Edit + leave dirty.
    full_dirty = os.path.join(APP_ROOT, dirty_path)
    with open(full_dirty, "r", encoding="utf-8") as f:
        content = f.read()
    if dirty_path.endswith(".json") and content.lstrip().startswith("{"):
        idx = content.index("{") + 1
        injected = f'\n    "_half_committed_{dirty_marker}": "marker",'
        content = content[:idx] + injected + content[idx:]
    else:
        content = f"// half-committed dirty: {dirty_marker}\n" + content
    with open(full_dirty, "w", encoding="utf-8") as f:
        f.write(content)


def synthesize_pending_state(rel, marker):
    """Create the .build/.git state that a kill-after-_capture_pending
    would leave: HEAD = pending commit + tag, working tree at pending,
    baseline unchanged.

    Replaces the old STATE_INTEGRITY_KILL_AT="after-pending-commit"
    path. Models the real-world case "user Ctrl-C / SIGKILL between
    capture and write-start" without instrumenting production code.

    The recovery flow on next make quilt runs uniformly regardless of
    how the pending tag got there — so synthesizing the post-capture
    state via direct git ops exercises the same code as a real kill.
    """
    edit_patched_file(rel, marker)
    _run(["git", "-C", BUILD, "add", "-A"], env=GIT_ENV, check=True)
    _run(["git", "-C", BUILD, "commit", "-m", "make-quilt-pending"],
         env=GIT_ENV, check=True)
    _run(["git", "-C", BUILD, "tag", "make-quilt-pending"],
         env=GIT_ENV, check=True)


def sabotage_patch_file(patch_name):
    """Yank a patch file out of the way (unlink it) so quilt push of
    it fails with file-not-found.

    Replaces the old STATE_INTEGRITY_KILL_AT="mid-write" path. Models
    the real-world case "the patch file got deleted / corrupted /
    accidentally moved somehow" — same failure outcome as a kill
    landing inside the per-patch loop: write_patches' `quilt push`
    raises (rc != 0), caller catches, pending tag preserved, recovery
    on next run.

    Why unlink and not corrupt-in-place: corrupt-in-place is fragile
    (hunk-header malformations are sometimes tolerated by patch's
    forgiving parser; context conflicts route through the
    pause-and-continue prompt rather than raising; etc.). File-not-found
    is unambiguous.

    Why not also strip the entry from `series`: the deliberate
    series-vs-patchfile mismatch IS the test condition — quilt push
    needs to attempt the patch (and fail) to exercise write_patches'
    raise-on-failure path. Stripping series would make quilt skip the
    entry entirely; no failure, no test.

    Restoration: caller runs `git -C apps/mail checkout -- patches/<name>`
    to bring the file back from the index. The unlink is uncommitted
    (workspace-only); git's index still has the original content.

    Invariants caveat: while the sabotage is in effect (between this
    call and the post-restoration invariants() call), pass
    `expect_series_mismatch=True` to invariants() — INV-05 inverts
    and asserts the mismatch IS present (sanity check that sabotage
    took effect).

    Returns the path of the sabotaged patch file so the caller knows
    what to checkout.
    """
    if not patch_name.endswith(".patch"):
        patch_name += ".patch"
    full = os.path.join(PATCHES, patch_name)
    if not os.path.exists(full):
        raise RuntimeError(
            f"sabotage_patch_file({patch_name!r}): no such patch — "
            f"fixture mismatch")
    os.unlink(full)
    return full


def sabotage_patch_file_corrupt(patch_name):
    """Corrupt a patch file in-place by mangling the first hunk header.

    Different failure shape from sabotage_patch_file's unlink. The
    parser may tolerate (push succeeds with the bad header), apply
    incorrectly (push succeeds with garbage), or refuse cleanly
    (push fails with rc != 0). Whatever happens is the platform's
    actual behavior on malformed patches — sabotage_patch_file's
    docstring acknowledges corrupt-in-place was deliberately not
    tested for "test-author convenience"; this helper closes that
    coverage gap (per F6.4).

    Mangles "@@ -X,Y +A,B @@" to "@@ -X +A @@" — drops the line-count
    fields. patch's tolerance of this varies; the test surfaces what
    happens.

    Returns the path so the caller can restore via git checkout.
    """
    if not patch_name.endswith(".patch"):
        patch_name += ".patch"
    full = os.path.join(PATCHES, patch_name)
    if not os.path.exists(full):
        raise RuntimeError(
            f"sabotage_patch_file_corrupt({patch_name!r}): no such "
            f"patch — fixture mismatch")
    with open(full, "r", encoding="utf-8") as f:
        content = f.read()
    mangled = re.sub(
        r'@@ -(\d+),\d+ \+(\d+),\d+ @@',
        r'@@ -\1 +\2 @@',
        content,
        count=1)
    if mangled == content:
        raise RuntimeError(
            f"sabotage_patch_file_corrupt({patch_name!r}): no hunk "
            f"header found to mangle — fixture mismatch")
    with open(full, "w", encoding="utf-8") as f:
        f.write(mangled)
    return full


# ---------------------------------------------------------------------------
# Lock contention test
# ---------------------------------------------------------------------------

def lock_contention_test():
    """Hold .build/.lock externally; verify make quilt fails fast.

    Models the "two terminals, second one starts mid-first" scenario.
    fcntl.flock is kernel-level; the lock held by this process is
    visible to the container-side acquire (quilt_common.locked()'s
    flock on the same bind-mounted .build/.lock file).
    """
    fd = os.open(LOCK_FILE, os.O_CREAT | os.O_RDWR, 0o664)
    try:
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        # Lock held — now attempt make quilt and expect fail-fast.
        # We use a tracked-but-clean .build/ so if the lock weren't
        # held, make quilt would exit 3 ("no changes") rather than
        # doing real work; the lock test is just about "did the lock
        # contention path fire?". Direct dc-run.sh invocation to see
        # quilt's real exit code 6 (lock held).
        # Direct dc-run.sh call (bypasses make's exit-code-2 wrapping)
        # so we see quilt_v2's real exit code 6 (lock held). quilt_v2
        # acquires the lock before any prompt fires, so the lock-held
        # path exits before stdin/answers come into play — no need to
        # set up scripted answers.
        env = _make_env()
        cmd = ["bash", os.path.join(REPO, "scripts", "host", "dc-run.sh"),
               "python3", "/platform/scripts/quilt_v2.py"]
        info("  $ dc-run.sh python3 quilt_v2.py (lock contention attempt)")
        r = _run(cmd, cwd=APP_ROOT, env=env, timeout=30)
        check(
            f"S{STEP_NUM:03d}{SUBSTEP_LETTER}-lock-rc",
            r.returncode == 6,
            f"rc={r.returncode}, expected 6 (lock held); "
            f"stderr_tail={r.stderr if r.stderr else ''}")
        check(
            f"S{STEP_NUM:03d}{SUBSTEP_LETTER}-lock-msg",
            "already running" in (r.stdout + r.stderr),
            f"expected 'already running' in output; "
            f"stdout={r.stdout}, stderr={r.stderr}")
    finally:
        # Release lock + close fd. Lock file stays on disk to avoid a
        # TOCTOU between unlink and close (another process could open
        # the file between our unlink and close, lock the now-deleted
        # inode, and a third process could create a fresh file at the
        # path and lock that — two simultaneous holders). Same shape
        # as quilt_common.locked()'s deliberate non-unlink behaviour.
        try:
            fcntl.flock(fd, fcntl.LOCK_UN)
        finally:
            os.close(fd)


# ---------------------------------------------------------------------------
# Suite-start state capture
# ---------------------------------------------------------------------------

def capture_suite_start():
    """Record the state we need to restore at cleanup, plus the
    .build/.gitignore bytes for invariant 6.

    Captures:
      - the operator's current branch name + HEAD commit (for `git
        reset --hard <sha>` after switching back to that branch); the
        branch name is also stored at module scope as ORIGINAL_BRANCH
        so the end-of-arc round-trip can read it
      - stash list count (for "drop stashes from top until count matches")
      - tags in .build/.git (for cross-check; expect just `baseline`)
      - .build/.gitignore exact bytes (for invariant 6 —
        write-baseline.sh writes nothing into .gitignore (its
        `git add -A` respects whatever's already there), so the file
        should be deterministic across all
        chaos-arc points; drift means something is silently rewriting
        it).

    No byte-exact tree snapshots of patches/ or overlay/ — git history
    on the operator's branch is enough to roll back tracked content.
    Random files dropped during the test are untracked; cleanup uses
    `git clean -fd` to drop them.

    Worst case if cleanup fails: re-clone hubs-apps from origin.
    """
    global SUITE_START_GITIGNORE_BYTES, ORIGINAL_BRANCH

    state = {}
    state["mail_branch"] = _run(
        ["git", "rev-parse", "--abbrev-ref", "HEAD"],
        cwd=APP_ROOT, env=GIT_ENV, check=True).stdout.strip()
    ORIGINAL_BRANCH = state["mail_branch"]
    state["mail_commit"] = _run(
        ["git", "rev-parse", "HEAD"],
        cwd=APP_ROOT, env=GIT_ENV, check=True).stdout.strip()
    state["stash_count"] = len([
        l for l in _run(
            ["git", "stash", "list"],
            cwd=APP_ROOT, env=GIT_ENV, check=True
        ).stdout.splitlines() if l.strip()])
    state["build_tags"] = sorted([
        l.strip() for l in _run(
            ["git", "tag"],
            cwd=BUILD, env=GIT_ENV, check=True
        ).stdout.splitlines() if l.strip()])
    state["build_baseline"] = _run(
        ["git", "rev-parse", "baseline"],
        cwd=BUILD, env=GIT_ENV, check=True).stdout.strip()

    # Sanity: assert we're starting from a clean parent + populated .build/.
    # If suite-start preconditions don't hold, the test is meaningless.
    parent_status = _run(
        ["git", "status", "--porcelain"], cwd=APP_ROOT, env=GIT_ENV).stdout
    if parent_status.strip():
        print(f"FATAL: apps/mail working tree not clean at suite-start. "
              f"Aborting.\n{parent_status}", file=sys.stderr)
        sys.exit(2)
    build_diff = _run(
        ["git", "diff", "--quiet", "baseline"], cwd=BUILD, env=GIT_ENV)
    if build_diff.returncode != 0:
        print("FATAL: .build/ dirty against baseline at suite-start. "
              "Run `make assemble MODE=force` first.", file=sys.stderr)
        sys.exit(2)
    if "make-quilt-pending" in state["build_tags"]:
        print("FATAL: make-quilt-pending tag present at suite-start. "
              "Run `make quilt` to recover, then re-run the test.",
              file=sys.stderr)
        sys.exit(2)

    # .gitignore byte capture for invariant 6.
    gitignore_path = os.path.join(BUILD, ".gitignore")
    if not os.path.exists(gitignore_path):
        print(f"FATAL: .build/.gitignore missing at suite-start.",
              file=sys.stderr)
        sys.exit(2)
    with open(gitignore_path, "rb") as f:
        SUITE_START_GITIGNORE_BYTES = f.read()

    info(f"  suite-start: branch={state['mail_branch']}, "
         f"commit={state['mail_commit'][:8]}, "
         f"stashes={state['stash_count']}, "
         f"build_tags={state['build_tags']}, "
         f"baseline={state['build_baseline'][:8]}, "
         f"gitignore={len(SUITE_START_GITIGNORE_BYTES)} bytes")
    return state


def setup_arc_branch(state):
    """Create + switch to ARC_BRANCH at suite-start commit.

    The chaos arc runs on this temp branch so commits accumulate without
    moving the operator's actual working branch (mail-patch-rework or
    whatever's checked out at suite start). Cleanup deletes the branch.

    Pre-check (no leftover ARC_BRANCH from a crashed prior run) lives
    in the wrapper script tests/run_state_integrity.sh, not here — per
    operator's separation of test logic from operator-environment
    guards. If we reach this point with the branch already present, that
    means the operator skipped the wrapper or the wrapper's guard
    failed; fail loudly with a clear message rather than silently
    overwriting.
    """
    existing = _run(
        ["git", "rev-parse", "--verify", "--quiet", ARC_BRANCH],
        cwd=APP_ROOT, env=GIT_ENV)
    if existing.returncode == 0:
        print(
            f"FATAL: {ARC_BRANCH} already exists. A prior run crashed "
            f"before its cleanup ran. Inspect via "
            f"`git -C apps/mail log {ARC_BRANCH}` and delete with "
            f"`git -C apps/mail branch -D {ARC_BRANCH}` once you're "
            f"sure nothing valuable is there.",
            file=sys.stderr)
        sys.exit(2)
    _run(["git", "branch", ARC_BRANCH, state["mail_commit"]],
         cwd=APP_ROOT, env=GIT_ENV, check=True)
    _run(["git", "switch", ARC_BRANCH],
         cwd=APP_ROOT, env=GIT_ENV, check=True)
    info(f"  on temp branch {ARC_BRANCH} at "
         f"{state['mail_commit'][:8]} (forked from {state['mail_branch']})")


def commit_arc_progress(marker):
    """Commit apps/mail's patches/* and overlay/* to ARC_BRANCH.

    Called after every successful `make quilt` save (per operator's
    "after every successful save, commit specific files" cadence). The
    commit captures whatever the save touched (patch files, new patch
    creations, overlay copies, overlay deletions, new overlay files).
    Random-violence files at apps/mail's root and other paths stay
    uncommitted by construction — only patches/ and overlay/ are
    staged.

    Branch guard: only commits if the current branch is ARC_BRANCH.
    Phase 8 of the chaos arc runs on ORIGINAL_BRANCH after the
    end-of-feature round-trip; auto-committing there would move the
    operator's actual working-branch tip, which the spec explicitly
    forbids ("Don't push, don't move the branch tip" — the test must
    leave apps/mail at the same branch/commit it started at).
    Phase 8's saves stay uncommitted on ORIGINAL_BRANCH; cleanup's
    git reset --hard drops the dirt.

    No-op also when nothing is staged: covers
      - recovery flows (no parent-repo mutation, just .build/ restore)
      - second-make-quilt-after-recovery where the actual save did
        touch patches/ but the commit happens to be empty (shouldn't
        happen, but we tolerate it without producing an empty commit)
    """
    current_branch = _run(
        ["git", "rev-parse", "--abbrev-ref", "HEAD"],
        cwd=APP_ROOT, env=GIT_ENV, check=True).stdout.strip()
    if current_branch != ARC_BRANCH:
        # Phase 8 territory — don't move the operator's branch tip.
        return
    _run(["git", "add", "patches", "overlay"],
         cwd=APP_ROOT, env=GIT_ENV, check=True)
    diff_check = _run(
        ["git", "diff", "--cached", "--quiet"],
        cwd=APP_ROOT, env=GIT_ENV)
    if diff_check.returncode == 0:
        # Nothing staged — recovery or no-op save. Skip the commit
        # rather than producing an empty one.
        return
    _run(["git", "commit", "-m", f"state-integrity: {marker}"],
         cwd=APP_ROOT, env=GIT_ENV, check=True)


# ---------------------------------------------------------------------------
# Invariant battery — checked between every step
# ---------------------------------------------------------------------------

def invariants(*, expect_pending=False, run_pop_push=False,
               expect_series_mismatch=False, label=None):
    """Run the structural-invariant battery against current state.

    Eight invariants. Silent corruption modes accumulate across
    operations; the only way to catch them is continuous structural
    assertion between every step. Behaviour tests catch one op's
    output; these check shape. Cheap invariants run every call;
    expensive pop+push round-trip (INV-08) runs only when
    run_pop_push=True.

    expect_pending: True after a kill-mid-flight or when we deliberately
        left a pending tag in place. Affects invariants 2/3/4.

    expect_series_mismatch: True during a sabotage_patch_file span
        (between the sabotage and the git-checkout restoration). The
        sabotage IS a deliberate series-vs-patchfile mismatch — the
        platform-failure-mode under test. INV-05 inverts and asserts
        the mismatch IS present (sanity check that sabotage took
        effect); falsely-matching here would mean the sabotage didn't
        do what we expected.

    run_pop_push precondition: INV-08 invokes `quilt pop -a` + `push -a`
        via container quilt. quilt pop requires the working tree to
        match the per-patch .pc/<patch>/<file> snapshot for files the
        patch touches; any uncommitted content on top → "does not
        remove cleanly" refusal.

        Post-save states with skipped items leave WT dirty:
        restore-skipped (in verify_and_finalize) writes the user's
        original edits back into the working tree as uncommitted
        state against the new baseline, so the dev sees them again
        on the next make-quilt run. Pop refuses on those states. The
        chaos arc deliberately keeps persistent strays across saves
        in phases 1-7, so this guarantee fires on every save.

        Pass run_pop_push=True only at known-clean WT moments:
        - end-of-phase manual cleanups (after `git checkout baseline -- .`)
        - post-DISCARD or post-FORCE assemble
        - post-round-trip plain assemble
        - saves where every item was routed (no skips → no
          restore-skipped → WT clean)

        INV-1..7 always hold and run unconditionally; they catch
        fsck corruption, baseline drift, orphan commits, series
        desync, .gitignore bytes drift, and broken overlay symlinks
        regardless of WT state. INV-08 is the only conditional one.

    Failures don't abort — recorded via check() and the chaos arc
    continues so we see all failures in one run.
    """
    tag = label or f"S{STEP_NUM:03d}{SUBSTEP_LETTER}"

    # 1. git fsck on .build/.git — catches arbitrary repo corruption.
    r = _run(["git", "fsck", "--full", "--no-dangling"],
             cwd=BUILD, env=GIT_ENV)
    check(f"{tag}-INV-01-fsck", r.returncode == 0,
          f"git fsck rc={r.returncode}, "
          f"stderr={r.stderr if r.stderr else ''}")

    # 2. baseline tag exists.
    baseline_check = _run(
        ["git", "rev-parse", "--verify", "--quiet", "refs/tags/baseline"],
        cwd=BUILD, env=GIT_ENV)
    check(f"{tag}-INV-02-baseline-exists", baseline_check.returncode == 0,
          "baseline tag missing")

    # 3. make-quilt-pending tag iff expected.
    pending_check = _run(
        ["git", "rev-parse", "--verify", "--quiet",
         "refs/tags/make-quilt-pending"],
        cwd=BUILD, env=GIT_ENV)
    has_pending = (pending_check.returncode == 0)
    check(f"{tag}-INV-03-pending-tag-state",
          has_pending == expect_pending,
          f"pending tag {'present' if has_pending else 'absent'}, "
          f"expected {'present' if expect_pending else 'absent'}")

    # 4. No orphan commits between baseline and HEAD. The only
    # legitimate non-empty baseline..HEAD is when it contains exactly
    # the pending commit (synthesize_pending_state or an interrupted
    # save where capture ran but verify_and_finalize never dropped
    # the tag).
    #
    # Failure-recovery scenarios land HEAD in two shapes:
    #   - synthesize_pending_state / "user Ctrl-C after capture":
    #     HEAD = pending (the synthetic commit advanced HEAD;
    #     write_patches never ran its reset --hard).
    #   - sabotage_patch_file / write_patches raises mid-loop:
    #     HEAD = baseline (write_patches step 1's reset --hard baseline
    #     already ran; then quilt push raises before any commit).
    # Both are legitimate; the rule is "no commits in baseline..HEAD
    # other than the pending commit, if it exists."
    rev_list = [l for l in _run(
        ["git", "rev-list", "baseline..HEAD"],
        cwd=BUILD, env=GIT_ENV).stdout.strip().splitlines() if l.strip()]
    if has_pending:
        pending_sha = _run(
            ["git", "rev-parse", "make-quilt-pending"],
            cwd=BUILD, env=GIT_ENV).stdout.strip()
        # Allowed: rev_list empty (HEAD == baseline; pending is a sibling)
        # OR rev_list == [pending_sha] (HEAD == pending after capture).
        ok = (not rev_list) or (rev_list == [pending_sha])
        check(f"{tag}-INV-04-no-orphan-commits",
              ok,
              f"baseline..HEAD={[s[:8] for s in rev_list]}, "
              f"pending={pending_sha[:8]}; expected empty or [pending]")
    else:
        check(f"{tag}-INV-04-no-orphan-commits",
              not rev_list,
              f"baseline..HEAD={[s[:8] for s in rev_list]}, "
              f"expected empty (no pending tag)")

    # 5. series count == *.patch count in apps/mail/patches/.
    series_path = os.path.join(PATCHES, "series")
    if os.path.exists(series_path):
        with open(series_path) as f:
            series_lines = [l.strip() for l in f
                            if l.strip() and not l.startswith("#")]
    else:
        series_lines = []
    patch_files = sorted(
        f for f in os.listdir(PATCHES) if f.endswith(".patch"))
    series_matches = (sorted(series_lines) == patch_files)
    if expect_series_mismatch:
        # Sabotage in effect — assert the mismatch IS present (sanity
        # check that sabotage_patch_file took effect; if it matches,
        # the sabotage didn't do what the test expected).
        check(f"{tag}-INV-05-series-mismatch-as-expected",
              not series_matches,
              f"expected series-vs-patchfile mismatch (sabotage in "
              f"effect), but they match: series={sorted(series_lines)}")
    else:
        check(f"{tag}-INV-05-series-vs-patchfiles",
              series_matches,
              f"series={sorted(series_lines)}, "
              f"patch_files={patch_files}")

    # 6. .build/.gitignore exact-bytes match against suite-start
    # capture. write-baseline.sh writes nothing into .gitignore (its
    # `git add -A` respects whatever's already there) — upstream's
    # content is what's there. The file should be deterministic
    # across all chaos-arc points, including
    # after MODE=force (which re-rsyncs upstream's gitignore unchanged).
    # Drift means something is silently rewriting it.
    gitignore_path = os.path.join(BUILD, ".gitignore")
    if os.path.exists(gitignore_path):
        with open(gitignore_path, "rb") as f:
            current_bytes = f.read()
        check(f"{tag}-INV-06-gitignore-bytes",
              current_bytes == SUITE_START_GITIGNORE_BYTES,
              f"gitignore bytes drifted: "
              f"current={len(current_bytes)} bytes, "
              f"suite-start={len(SUITE_START_GITIGNORE_BYTES)} bytes")
    else:
        check(f"{tag}-INV-06-gitignore-exists", False,
              ".build/.gitignore missing")

    # 7. Every overlay symlink in .build/ resolves under apps/mail/overlay/.
    overlay_abs = os.path.realpath(OVERLAY)
    bad_links = []
    for dirpath, dirnames, filenames in os.walk(BUILD, followlinks=False):
        # Prune big dirs.
        dirnames[:] = [d for d in dirnames if d not in
                       {"node_modules", "vendor", "vendor-bin",
                        ".git", ".pc"}]
        for name in filenames + dirnames:
            full = os.path.join(dirpath, name)
            if not os.path.islink(full):
                continue
            try:
                resolved = os.path.realpath(full)
            except OSError:
                bad_links.append((full, "<realpath failed>"))
                continue
            # We only care about links INTO overlay; links to upstream
            # files (rare but tracked by git) are fine.
            link_target = os.readlink(full)
            if "overlay" not in link_target:
                continue  # Not an overlay-managed symlink.
            if not (resolved == overlay_abs or
                    resolved.startswith(overlay_abs + os.sep)):
                bad_links.append((full, resolved))
    check(f"{tag}-INV-07-overlay-symlinks-resolve",
          not bad_links,
          f"{len(bad_links)} stale overlay symlinks: {bad_links[:5]}")

    # 8. (Expensive) pop+push round-trip preserves .pc/ + series.
    if run_pop_push:
        # Snapshot .pc/ first, run round-trip, compare. quilt 0.66 in
        # the container handles this correctly; quilt 0.68 on host
        # may rewrite .pc/'s metadata fields silently and break the
        # subsequent pop+push — wrap through dc-run.sh.
        inline = (
            "export QUILT_PATCHES=../patches\n"
            # $APP_ROOT set by dc-run.sh — /app on ephemeral path,
            # /platform/apps/<APP_NAME> on pool path. Either resolves
            # to the right .build/.
            "cd \"$APP_ROOT/.build\"\n"
            "[ -d .pc ] && echo \"$QUILT_PATCHES\" > .pc/.quilt_patches\n"
            "quilt pop -a --quiltrc=- ; pop_rc=$?\n"
            "if [ $pop_rc -ne 0 ] && [ $pop_rc -ne 2 ]; then exit $pop_rc; fi\n"
            "quilt push -a --quiltrc=-\n")
        r = _run(
            ["bash", os.path.join(REPO, "scripts", "host", "dc-run.sh"),
             "bash", "-c", inline],
            cwd=APP_ROOT, env=_make_env(), timeout=180)
        check(f"{tag}-INV-08-pop-push-roundtrip",
              r.returncode == 0,
              f"pop+push rc={r.returncode}, "
              f"stdout={r.stdout if r.stdout else ''}; "
              f"stderr={r.stderr if r.stderr else ''}")


# ---------------------------------------------------------------------------
# Differential equivalence (Phase 9 — D01-D04)
# ---------------------------------------------------------------------------

# Cache + quilt-internal + sidecar-output dirs the differential ignores
# by construction:
# - node_modules/, vendor/, lib/Vendor/, vendor-bin/*/vendor — these are
#   reinstalled per MODE=force so the bytes will differ even with no drift
#   (timestamps in lock-resolved deps, etc.).
# - .git — internal history; baseline-tag content is compared separately
#   via git archive.
# - .pc — quilt-internal; structural validity covered by invariant 8.
# - js/ — webpack output dir. webpack-dev-server's writeToDisk emits
#   bundles here; webpack itself ignores it (watchOptions.ignored
#   `**/js` per webpack.itsl.js). It's derived state, not source state,
#   so source-tree-equiv shouldn't compare it. Pre-existing platform
#   state (e.g. operator ran `make webpack MODE=hmr` then stopped the
#   sidecar) leaves .build/js/ populated; a fresh MODE=force produces
#   an empty .build/ before any compile. Excluding /js/ matches the
#   semantic the test cares about (source bytes equiv, not output
#   bytes equiv).
_DIFFERENTIAL_EXCLUDED_DIRS = {"node_modules", "vendor", "vendor-bin",
                               ".git", ".pc", "js"}


def _build_source_snapshot(snapshot_dir):
    """Copy .build/'s source-tree files (excluding caches) into snapshot_dir.

    Symlinks are recorded by their target path (not followed) — a stale
    symlink is exactly the kind of drift this test wants to catch.
    """
    def _ignore(dirpath, names):
        rel = os.path.relpath(dirpath, BUILD)
        # At top of .build/ we cut the big cache dirs; lib/Vendor lives
        # under lib/ via Mozart, so cut that explicitly too.
        ignored = []
        for n in names:
            if n in _DIFFERENTIAL_EXCLUDED_DIRS:
                ignored.append(n)
            elif rel == "lib" and n == "Vendor":
                ignored.append(n)
        return ignored

    shutil.copytree(BUILD, snapshot_dir, symlinks=True, ignore=_ignore)


def _compare_trees(left, right, *, label_left, label_right,
                   exclude_relpaths=None):
    """Compare two source trees byte-for-byte (excluding cache dirs and
    any relpaths in exclude_relpaths).

    exclude_relpaths: iterable of paths (relative to the tree root) the
    test KNOWS will diverge — typically scratch files the test added
    that aren't reproducible from apps/mail/ source.

    Returns list of differences:
        ("missing_in_left", relpath)
        ("missing_in_right", relpath)
        ("symlink_target_mismatch", relpath, left_target, right_target)
        ("type_mismatch", relpath, left_type, right_type)
        ("content_diff", relpath, len_left, len_right)
    """
    excluded_paths = set(exclude_relpaths or [])

    def _walk_relpaths(root):
        out = {}
        for dirpath, dirnames, filenames in os.walk(root, followlinks=False):
            rel_dir = os.path.relpath(dirpath, root)
            dirnames[:] = [d for d in dirnames
                           if d not in _DIFFERENTIAL_EXCLUDED_DIRS
                           and not (rel_dir == "lib" and d == "Vendor")]
            for name in filenames + dirnames:
                full = os.path.join(dirpath, name)
                rel = os.path.relpath(full, root)
                if rel in excluded_paths:
                    continue
                if os.path.islink(full):
                    out[rel] = ("link", os.readlink(full))
                elif os.path.isdir(full):
                    out[rel] = ("dir", None)
                elif os.path.isfile(full):
                    with open(full, "rb") as f:
                        out[rel] = ("file", f.read())
                else:
                    out[rel] = ("other", None)
        return out

    left_map = _walk_relpaths(left)
    right_map = _walk_relpaths(right)

    diffs = []
    all_keys = sorted(set(left_map) | set(right_map))
    for k in all_keys:
        if k not in left_map:
            diffs.append(("missing_in_left", k))
            continue
        if k not in right_map:
            diffs.append(("missing_in_right", k))
            continue
        lt, lv = left_map[k]
        rt, rv = right_map[k]
        if lt != rt:
            diffs.append(("type_mismatch", k, lt, rt))
            continue
        if lt == "link" and lv != rv:
            diffs.append(("symlink_target_mismatch", k, lv, rv))
        elif lt == "file" and lv != rv:
            diffs.append(("content_diff", k, len(lv), len(rv)))
    return diffs


def differential_check():
    """Phase 9 — Differential equivalence.

    1. Snapshot .build/'s source files.
    2. Without touching apps/mail/, run make assemble MODE=force in place.
    3. Compare snapshot vs fresh-MODE=force.
    4. Also compare baseline-tag-pointed content + .build/.gitignore.

    Divergence is the failure signal. Per item-3 of our walkthrough:
    include all (source files + baseline-tag content + .gitignore).
    """
    phase("PHASE 9 — DIFFERENTIAL EQUIVALENCE")

    step("D01: snapshot .build/ source files (pre-MODE=force)")
    snapshot = tempfile.mkdtemp(prefix="state-integrity-D01-")
    snapshot_left = os.path.join(snapshot, "left")
    info(f"  snapshot dir: {snapshot_left}")
    _build_source_snapshot(snapshot_left)
    info(f"  snapshot size: {sum(1 for _ in os.walk(snapshot_left))} dirs")

    # Capture pre-FORCE baseline-tag-pointed file contents for the
    # baseline-equivalence sub-check. We use `git ls-tree -r baseline`
    # to enumerate paths, then `git show baseline:<path>` for each.
    # Cheap-ish; run the comparison via a single git archive.
    step("D02: capture baseline-tag content (pre-MODE=force)")
    baseline_tar_left = os.path.join(snapshot, "left.tar")
    with open(baseline_tar_left, "wb") as out:
        archive_left = subprocess.run(
            ["git", "archive", "--format=tar", "baseline"],
            cwd=BUILD, env=GIT_ENV, stdout=out)
    check(f"S{STEP_NUM:03d}{SUBSTEP_LETTER}-D02-baseline-archive-rc",
          archive_left.returncode == 0,
          f"git archive rc={archive_left.returncode}")

    step("D03: make assemble MODE=force — produce fresh .build/ "
         "from same apps/mail/")
    make_assemble("FORCE", expect_rc=0, timeout=900)

    step("D04: compare snapshot (pre-FORCE) vs fresh (post-FORCE)")
    snapshot_right = os.path.join(snapshot, "right")
    _build_source_snapshot(snapshot_right)
    # Exclude scratch files the test deliberately introduced —
    # they're not in apps/mail/ source so MODE=force's tree won't have
    # them. _DROPPED_SCRATCH_PATHS is populated dynamically by
    # drop_scratch_file as the arc runs.
    diffs = _compare_trees(
        snapshot_left, snapshot_right,
        label_left="end-of-chaos", label_right="post-MODE=force",
        exclude_relpaths=_DROPPED_SCRATCH_PATHS)
    if diffs:
        info(f"  {len(diffs)} differences found:")
        for d in diffs[:30]:
            info(f"    {d}")
        if len(diffs) > 30:
            info(f"    ... and {len(diffs) - 30} more")
    check(f"S{STEP_NUM:03d}{SUBSTEP_LETTER}-D04-source-tree-equiv",
          not diffs,
          f"{len(diffs)} differences (see log)")

    # Baseline-tag content equivalence — extract both archives to temp
    # dirs and reuse _compare_trees so the same _DROPPED_SCRATCH_PATHS
    # exclusion applies. write_baseline.sh's `git add -A` captures
    # whatever's in .build/'s working tree, including untracked-non-
    # gitignored scratch files the test introduced (e.g. test-output.log).
    # MODE=force's baseline doesn't have those (MODE=force rebuilds from
    # apps/mail/ source which doesn't carry them), so a raw tar-bytes
    # comparison would fail solely on scratch presence — exactly the
    # exclusion list's purpose.
    baseline_tar_right = os.path.join(snapshot, "right.tar")
    with open(baseline_tar_right, "wb") as out:
        subprocess.run(
            ["git", "archive", "--format=tar", "baseline"],
            cwd=BUILD, env=GIT_ENV, stdout=out, check=True)
    baseline_extract_left = os.path.join(snapshot, "baseline-left")
    baseline_extract_right = os.path.join(snapshot, "baseline-right")
    os.makedirs(baseline_extract_left, exist_ok=True)
    os.makedirs(baseline_extract_right, exist_ok=True)
    subprocess.run(
        ["tar", "-xf", baseline_tar_left, "-C", baseline_extract_left],
        check=True)
    subprocess.run(
        ["tar", "-xf", baseline_tar_right, "-C", baseline_extract_right],
        check=True)
    baseline_diffs = _compare_trees(
        baseline_extract_left, baseline_extract_right,
        label_left="end-of-chaos baseline",
        label_right="post-MODE=force baseline",
        exclude_relpaths=_DROPPED_SCRATCH_PATHS)
    if baseline_diffs:
        info(f"  {len(baseline_diffs)} baseline-tag differences found:")
        for d in baseline_diffs[:30]:
            info(f"    {d}")
        if len(baseline_diffs) > 30:
            info(f"    ... and {len(baseline_diffs) - 30} more")
    check(f"S{STEP_NUM:03d}{SUBSTEP_LETTER}-D04-baseline-content-equiv",
          not baseline_diffs,
          f"{len(baseline_diffs)} baseline-tag content differences "
          f"(see log)")

    # .build/.gitignore exact-bytes equivalence — same protection as
    # invariant 6, applied at the differential level. write-baseline.sh
    # writes nothing into .gitignore (only tracks/commits via
    # `git add -A`), so a post-MODE=force .build/ should have
    # byte-identical .gitignore to end-of-chaos.
    gi_left = os.path.join(snapshot_left, ".gitignore")
    gi_right = os.path.join(snapshot_right, ".gitignore")
    if os.path.exists(gi_left) and os.path.exists(gi_right):
        with open(gi_left, "rb") as f:
            gi_left_bytes = f.read()
        with open(gi_right, "rb") as f:
            gi_right_bytes = f.read()
        check(f"S{STEP_NUM:03d}{SUBSTEP_LETTER}-D04-gitignore-equiv",
              gi_left_bytes == gi_right_bytes,
              f".build/.gitignore differs "
              f"(left={len(gi_left_bytes)} bytes, "
              f"right={len(gi_right_bytes)} bytes)")

    # Best-effort cleanup of the snapshot dir.
    shutil.rmtree(snapshot, ignore_errors=True)


# ---------------------------------------------------------------------------
# Cleanup (Phase 10 — C01-C09)
# ---------------------------------------------------------------------------

def _cleanup_step(label, fn):
    """Run a cleanup substep with best-effort error containment.

    Cleanup MUST run all stages even if one fails — if C04 fails, we
    still want C05/C08/C10 to try restoring the world. Without this
    wrapper, the fail-fast check() in any substep would abort the rest
    of cleanup, leaving the working tree in worse shape.

    StateIntegrityFailure is logged and swallowed; other exceptions
    are logged + re-raised (genuine bugs in the cleanup code itself).
    """
    try:
        fn()
    except StateIntegrityFailure as e:
        print(f"    (cleanup [{label}] failed; continuing): {e}",
              file=sys.stderr)


def cleanup_to_suite_start(state):
    """Restore world to suite-start state.

    Steps C01-C09 (renumbered from the original C01-C10 set after
    dropping the pre-FORCE invariants diagnostic — it was informational
    and added clutter without being load-bearing). Post-FORCE
    invariants at C09 confirm MODE=force produced a sound .build/.

    Cleanup is best-effort: each substep is wrapped via _cleanup_step
    so a failure in one doesn't prevent the rest from running. The
    final-equivalence checks (was the world actually restored?) are
    NOT here — they're in the wrapper script tests/run_state_integrity.sh
    so the test file's contract is "test the platform" and the wrapper's
    is "verify the test left the world clean." Per operator: the two
    concerns shouldn't be conflated in a future agent's reading.
    """
    phase("PHASE 10 — CLEANUP TO SUITE-START")

    step("C01: record post-test state (for the cleanup's own log)")
    post_branch = _run(
        ["git", "rev-parse", "--abbrev-ref", "HEAD"],
        cwd=APP_ROOT, env=GIT_ENV).stdout.strip()
    post_commit = _run(
        ["git", "rev-parse", "HEAD"],
        cwd=APP_ROOT, env=GIT_ENV).stdout.strip()
    post_stashes = len([
        l for l in _run(
            ["git", "stash", "list"],
            cwd=APP_ROOT, env=GIT_ENV).stdout.splitlines() if l.strip()])
    post_tags = sorted([
        l.strip() for l in _run(
            ["git", "tag"],
            cwd=BUILD, env=GIT_ENV).stdout.splitlines() if l.strip()])
    info(f"  post-test: branch={post_branch}, commit={post_commit[:8]}, "
         f"stashes={post_stashes}, tags={post_tags}")
    info(f"  expected:  branch={state['mail_branch']}, "
         f"commit={state['mail_commit'][:8]}, "
         f"stashes={state['stash_count']}, tags={state['build_tags']}")

    step("C02: drop leftover make-quilt-pending tag in .build/.git")
    pending_check = _run(
        ["git", "rev-parse", "--verify", "--quiet",
         "refs/tags/make-quilt-pending"],
        cwd=BUILD, env=GIT_ENV)
    if pending_check.returncode == 0:
        info("  pending tag present — dropping")
        _run(["git", "tag", "-d", "make-quilt-pending"],
             cwd=BUILD, env=GIT_ENV)

    step("C03: in apps/mail: switch back to suite-start branch if "
         "we're elsewhere (test crash mid-arc may leave us on "
         f"{ARC_BRANCH}), then git reset --hard <suite-start commit>")
    def _restore_branch():
        current = _run(
            ["git", "rev-parse", "--abbrev-ref", "HEAD"],
            cwd=APP_ROOT, env=GIT_ENV).stdout.strip()
        if current != state["mail_branch"]:
            info(f"  on {current}; switching to {state['mail_branch']}")
            # -f: tolerate uncommitted changes (we're about to reset --hard).
            r = _run(["git", "switch", "-f", state["mail_branch"]],
                     cwd=APP_ROOT, env=GIT_ENV)
            check(f"S{STEP_NUM:03d}{SUBSTEP_LETTER}-C03-switch",
                  r.returncode == 0,
                  f"switch rc={r.returncode}, stderr={r.stderr}")
        r = _run(["git", "reset", "--hard", state["mail_commit"]],
                 cwd=APP_ROOT, env=GIT_ENV)
        check(f"S{STEP_NUM:03d}{SUBSTEP_LETTER}-C03-reset",
              r.returncode == 0,
              f"reset rc={r.returncode}, stderr={r.stderr}")
    _cleanup_step("C03-branch-reset", _restore_branch)

    step("C04: in apps/mail: git clean -fd (drop untracked random files)")
    def _git_clean():
        r = _run(["git", "clean", "-fd"],
                 cwd=APP_ROOT, env=GIT_ENV)
        check(f"S{STEP_NUM:03d}{SUBSTEP_LETTER}-C04-clean",
              r.returncode == 0,
              f"clean rc={r.returncode}, stderr={r.stderr}")
    _cleanup_step("C04-clean", _git_clean)

    step("C05: drop test-introduced stashes")
    # Stash drops use raw _run (no check()) — runaway-stash safety
    # belt is in the loop bound, not in fail-fast asserts.
    while True:
        current = len([
            l for l in _run(
                ["git", "stash", "list"],
                cwd=APP_ROOT, env=GIT_ENV).stdout.splitlines() if l.strip()])
        if current <= state["stash_count"]:
            info(f"  stash count back to {current} (suite-start was "
                 f"{state['stash_count']})")
            break
        if current > state["stash_count"] + 50:
            info(f"  WARNING: stash count {current} >> suite-start "
                 f"{state['stash_count']}; not dropping further "
                 f"to avoid runaway")
            break
        info(f"  dropping stash@{{0}} (count={current}, target="
             f"{state['stash_count']})")
        _run(["git", "stash", "drop", "stash@{0}"],
             cwd=APP_ROOT, env=GIT_ENV)

    step(f"C06: drop {ARC_BRANCH} if it exists")
    branch_check = _run(
        ["git", "rev-parse", "--verify", "--quiet", ARC_BRANCH],
        cwd=APP_ROOT, env=GIT_ENV)
    if branch_check.returncode == 0:
        _run(["git", "branch", "-D", ARC_BRANCH],
             cwd=APP_ROOT, env=GIT_ENV)
        info(f"  dropped {ARC_BRANCH}")
    else:
        info(f"  {ARC_BRANCH} already gone (test-internal cleanup ran)")

    step("C07: make assemble MODE=force — fresh known-good .build/")
    _cleanup_step("C07-force",
                  lambda: make_assemble("FORCE", expect_rc=0, timeout=900))

    step("C08: make assemble (plain) — sanity check, must be no-op")
    _cleanup_step("C08-plain",
                  lambda: make_assemble(None, expect_rc=0, timeout=120))

    step("C09: post-FORCE invariant battery")
    _cleanup_step(
        "C09-invariants",
        lambda: invariants(label=f"S{STEP_NUM:03d}{SUBSTEP_LETTER}-C09-post-force",
                           run_pop_push=True, expect_pending=False))


# ---------------------------------------------------------------------------
# The chaos arc — one developer's workday in apps/mail
# ---------------------------------------------------------------------------

def chaos_arc():
    """One developer's workday in apps/mail.

    92 steps composing into a continuous narrative: dev opens the day
    with edits across files, periodically saves them as quilt patches
    via `make quilt`, occasionally screws up (drops scratch files,
    hand-edits a patch by accident, gets killed mid-save, loses a lock
    contention), recovers, switches branches at end-of-feature, deletes
    things, calls it a day. There is no "verification" operation in the
    script — the dev is just working. invariants() between every step
    does the watching.

    The arc runs on a temp branch (ARC_BRANCH) created by setup_arc_branch.
    Successful `make quilt` saves auto-commit patches/* and overlay/* to
    that branch (via commit_arc_progress hooked into make_quilt_interactive).
    Random-violence files (files dropped at apps/mail's root, in
    apps/mail/patches, in apps/mail/overlay) intentionally stay
    uncommitted — the kind of mess developers leave around that cleanup
    has to handle.

    Between phase 7 and phase 8: switch back to the operator's actual
    working branch (ORIGINAL_BRANCH), run `make assemble` plain. This is
    the contract — branch swap MUST be followed by plain assemble or the
    wrong set of patches WILL be applied. If plain can't handle the
    re-sync, plain is broken and the test surfaces that. Phase 8 then
    runs on the operator's branch with no further commits (cleanup
    resets --hard to drop phase 8's working-tree dirt).

    Per operator's fail-fast directive, a failed check halts the arc.
    Once a step goes off-script, every downstream invariant and answer
    is operating against an unintended state; the cascade of failures
    produces noise that masks the real failure. One root cause, one
    stop. main() catches StateIntegrityFailure and runs cleanup.
    """
    phase("PHASE 1 — starting a feature, exploratory edits")

    step("edit Account.php (a patched lib file)")
    edit_patched_file(PATCHED_PHP_A, "step01")
    invariants()

    step("edit MailManager.php (different patch)")
    edit_patched_file(PATCHED_PHP_B, "step02")
    invariants()

    step("drop NOTES.md scratch jotting")
    drop_scratch_file(os.path.join(BUILD, "NOTES.md"),
                      "step 03 dev notes\n")
    invariants()

    step("drop lingering-todo.md (a persistent TODO list the dev keeps "
         "in their working tree — never gets routed; tags along through "
         "every save in phases 1-7 via restore-skipped, finally cleaned "
         "up at end of phase 7 before the round-trip)")
    drop_scratch_file(os.path.join(BUILD, "lingering-todo.md"),
                      "- [ ] decide what to do with the audit logging\n"
                      "- [ ] ask Daniel about webpack hmr quirks\n"
                      "- [ ] follow up on #999\n")
    invariants()

    step("edit PageController.php — a persistent uncommitted edit on a "
         "patched file. PageController is touched by ai-processing-"
         "disabled (pos 9) and tags-itsl (pos 11) patches, so every "
         "plain-assemble's pop+push runs those patches' apply with the "
         "persistent edit floating on top. The dev hasn't decided what "
         "to do with this tweak yet; skipped at every subsequent save; "
         "reverted via git checkout in phase 7's manual cleanup.")
    edit_patched_file(PERSISTENT_PATCHED_FILE, "phase01-todo")
    invariants()

    step("make diff to see what's changed so far")
    make_diff()
    invariants()

    step("edit EnvelopeItsl.vue (overlay-symlinked → apps/mail/overlay/)")
    edit_overlay_symlinked(OVERLAY_SYMLINKED_VUE_A, "step05")
    invariants()

    step("edit composer.json (copyfile-managed)")
    edit_copyfile(COPYFILE_COMPOSER, "step06")
    invariants()

    step("refine Account.php (same region, second pass)")
    edit_patched_file(PATCHED_PHP_A, "step07")
    invariants()

    step("make diff")
    make_diff()
    invariants()

    step("make quilt — first save: route Account.php to accessibility, "
         "MailManager.php to account-display-resolution, copy composer.json "
         "back to overlay, skip the persistent stray pair (PageController "
         "edit + lingering-todo.md) and the NOTES.md jotting.")
    # Items extract sees:
    #   git diff (alphabetical tracked paths):
    #     composer.json (in overlay-copyfiles → OverlayCopy)
    #     lib/Account.php (Hunk modified — step01 + step07)
    #     lib/Controller/PageController.php (Hunk modified — phase01-todo)
    #     lib/Service/MailManager.php (Hunk modified — step02)
    #   ls-files --others (untracked, alphabetical):
    #     NOTES.md (NewFile)
    #     lingering-todo.md (NewFile)
    # The overlay-symlinked Vue edit from step 7 is INVISIBLE — write
    # went through the symlink into overlay/, not into .build/'s
    # tracked content, so `git diff baseline` doesn't see it.
    answers = [
        ("Copy back to overlay?", "y"),                          # composer.json
        ("Add to:",               "accessibility"),              # Account.php
        ("Add to:",               "s"),                          # PageController persistent skip
        ("Add to:",               "account-display-resolution"), # MailManager
        ("[o]verlay",             "s"),                          # NOTES.md skip
        ("[o]verlay",             "s"),                          # lingering-todo.md skip
        ("Apply?",                "y"),
    ]
    make_quilt_interactive(answers)
    invariants()

    step("make diff")
    make_diff()
    invariants()

    phase("PHASE 2 — refining, adding new code")

    step("third refinement pass on Account.php")
    edit_patched_file(PATCHED_PHP_A, "step12")
    invariants()

    step("new file: AccountThrottle.php (in existing dir)")
    new_file_in_existing_dir(
        NEW_FILE_EXISTING_DIR,
        "<?php\n// state-integrity step13\nclass AccountThrottle {}\n")
    invariants()

    step("drop .account-debug.log scratch")
    drop_scratch_file(os.path.join(BUILD, ".account-debug.log"),
                      "step 14 debug log\n")
    invariants()

    step("make quilt — route Account.php to accessibility, skip the "
         "persistent strays + NOTES.md + new .account-debug.log, route "
         "AccountThrottle.php to a new patch 'account-throttle'.")
    # Items at this step's entry (extract order = Hunks then NewFile,
    # both alphabetical):
    #   Hunks (tracked, modified):
    #     lib/Account.php (modified — step12 prepend)
    #     lib/Controller/PageController.php (phase01-todo restore-
    #       skipped from save 1)
    #   NewFile (untracked; ls-files --others alphabetical):
    #     .account-debug.log (first encounter; dropped at step 14)
    #     NOTES.md (restore-skipped from save 1; stays
    #       untracked across saves)
    #     lib/Service/AccountThrottle.php (first encounter; dropped at
    #       step 13)
    #     lingering-todo.md (restore-skipped from save 1;
    #       stays untracked across saves)
    answers = [
        ("Add to:",   "accessibility"),      # Account.php (step12)
        ("Add to:",   "s"),                  # PageController persistent skip
        ("[o]verlay", "s"),                  # .account-debug.log (first encounter)
        ("[o]verlay", "s"),                  # NOTES.md (restore-skipped)
        ("[o]verlay", "p"),                  # AccountThrottle.php → patch
        ("Add to:",   "n"),                  # ...into a new patch
        ("Name:",     "account-throttle"),
        ("[o]verlay", "s"),                  # lingering-todo.md (restore-skipped)
        ("Apply?",    "y"),
    ]
    make_quilt_interactive(answers)
    invariants()

    step("refine AccountThrottle.php (the new file we just created)")
    edit_patched_file(NEW_FILE_EXISTING_DIR, "step16")
    invariants()

    step("edit TagItem.vue (overlay-symlinked, exists)")
    edit_overlay_symlinked(OVERLAY_SYMLINKED_VUE_B, "step17")
    invariants()

    step("make quilt — route AccountThrottle refinement to "
         "account-throttle. Persistent strays (PageController edit + "
         "lingering-todo.md) + NOTES.md + .account-debug.log are still "
         "around as restored-skipped from the previous save and need "
         "answers too — skip them all again (the dev hasn't decided "
         "what to do with any of them yet).")
    # Items at this step's entry (extract order = Hunks then NewFile,
    # both alphabetical):
    #   Hunks (tracked, modified):
    #     lib/Controller/PageController.php (phase01-todo restore-skipped)
    #     lib/Service/AccountThrottle.php (step16 refinement)
    #   NewFile (untracked, alphabetical via ls-files --others):
    #     .account-debug.log (restore-skipped, stays untracked)
    #     NOTES.md (restore-skipped, stays untracked)
    #     lingering-todo.md (restore-skipped, stays untracked)
    # restore-skipped in verify_and_finalize uses
    # `git checkout pending -- <path>` + `git reset baseline -- <path>`
    # so INDEX stays at baseline, scratches stay untracked across
    # saves and route to [o]verlay [p]atch [s]kip instead of Add to:.
    # The TagItem.vue overlay-symlinked Vue edit from the immediately-
    # preceding step is INVISIBLE — write goes through the symlink to
    # apps/mail/overlay/<file>; .build/'s git baseline doesn't see the
    # symlink target's content change.
    answers = [
        ("Add to:",   "s"),                # PageController persistent skip
        ("Add to:",   "account-throttle"), # AccountThrottle.php (step16)
        ("[o]verlay", "s"),                # .account-debug.log
        ("[o]verlay", "s"),                # NOTES.md
        ("[o]verlay", "s"),                # lingering-todo.md
        ("Apply?",    "y"),
    ]
    make_quilt_interactive(answers)
    invariants()

    step("make diff")
    make_diff()
    invariants()

    step("scatter debug prints across 5 patched lib files (the kind of "
         "thing a dev does mid-debugging session)")
    scatter_debug_prints([PATCHED_PHP_A, PATCHED_PHP_B, PATCHED_PHP_C,
                          PATCHED_PHP_D, PATCHED_PHP_E], "step20")
    invariants()

    step("debug-print AccountThrottle.php too")
    edit_patched_file(NEW_FILE_EXISTING_DIR, "step21-DEBUG")
    invariants()

    step("make diff")
    make_diff()
    invariants()

    step("drop a random scratch file at apps/mail's root (accidental "
         "violence — the kind of mess that ends up untracked)")
    random_file_in_apps_mail("scratch-thinking.md",
                             "step 23 thinking\n")
    invariants()

    phase("PHASE 3 — wrong save, manual rollback by re-editing")

    step("make diff (orient self after the scattered edits)")
    make_diff()
    invariants()

    step("make diff again (cross-check — devs do this)")
    make_diff()
    invariants()

    step("edit-then-revert Account.php (write the same bytes back; "
         "mtime churns but git sees no semantic change)")
    edit_then_revert(PATCHED_PHP_A, "step27")
    invariants()

    step("make diff (revert is a no-op; the debug prints are still there)")
    make_diff()
    invariants()

    step("make quilt — wrong call: route all 6 debug-print hunks to "
         "existing mid-stack 'audit-logging' (the dev mistakes debug "
         "prints for the audit logs they're supposed to be writing). "
         "AccountThrottle.php is created by 'account-throttle' at "
         "series position 25 — not pushed at audit-logging's apply "
         "state (position 19), so _apply_patch_work's text-hunks "
         "pre-check (every routed file must exist at the patch's "
         "apply state) raises with a clear 'file doesn't exist at "
         "this patch's apply state' diagnostic. Save fails, pending "
         "tag preserved, exit non-zero.")
    # Items at this step's entry (extract order = Hunks then NewFile,
    # both alphabetical):
    #   Hunks (tracked, modified):
    #     lib/Account.php (scatter step20)
    #     lib/Controller/PageController.php (phase01-todo restore-skipped)
    #     lib/Service/AccountThrottle.php (step21 line 2; created by
    #       account-throttle pos 25)
    #     lib/Service/DraftsService.php (scatter)
    #     lib/Service/MailManager.php (scatter)
    #     lib/Service/MailTransmission.php (scatter)
    #     lib/Service/Sync/ImapToDbSynchronizer.php (scatter)
    #   NewFile (untracked — restore-skipped keeps INDEX at baseline):
    #     .account-debug.log (restore-skipped)
    #     NOTES.md (restore-skipped)
    #     lingering-todo.md (restore-skipped)
    make_quilt_interactive(
        [
            ("Add to:",   "audit-logging"),   # Account.php
            ("Add to:",   "s"),               # PageController persistent
            ("Add to:",   "audit-logging"),   # AccountThrottle.php (PRE-CHECK FAILS)
            ("Add to:",   "audit-logging"),   # DraftsService.php
            ("Add to:",   "audit-logging"),   # MailManager.php
            ("Add to:",   "audit-logging"),   # MailTransmission.php
            ("Add to:",   "audit-logging"),   # ImapToDbSynchronizer.php
            ("[o]verlay", "s"),               # .account-debug.log
            ("[o]verlay", "s"),               # NOTES.md
            ("[o]verlay", "s"),               # lingering-todo.md
            ("Apply?",    "y"),
        ],
        expect_exitcode_substr="===EXITCODE:2===")
    # Catch handler in quilt_v2.main() emitted "tag preserved; run
    # make assemble MODE=recover" diagnostic and exited; pending tag
    # remains set.
    invariants(expect_pending=True)

    step("make assemble MODE=recover — dev's explicit recovery gesture "
         "after the pre-check failure. Bash recovery primitive "
         "in assemble.sh runs reset --hard pending → clean -fd .pc/ "
         "→ reset baseline → drop tag. WT restored to dev's pre-quilt "
         "state.")
    make_assemble("RECOVER", expect_rc=0)
    invariants(expect_pending=False)

    step("make quilt — retry with corrected routing: AccountThrottle "
         "to its actual home 'account-throttle' (top of stack at this "
         "point, no constraint), 5 scattered PHPs still wrongly routed "
         "to audit-logging (cascade conflicts on those that have "
         "prior-patch contributions in their line-2 area; generic "
         "resolver handles).")
    # Same items at retry entry as at the failed-call entry —
    # MODE=recover restored .build/ to pre-call state. Order: Hunks
    # first (alphabetical), NewFile next (alphabetical).
    # Account.php is created from upstream; routing to audit-logging
    # is fine. AccountThrottle now goes to its own creator-patch
    # (account-throttle, top of stack — no series-position constraint
    # since it sits above all patches). The 5 scattered PHPs go to
    # audit-logging where mid-stack cascade-conflicts may fire on
    # files modified by patches above audit-logging's position.
    #
    # Per cascade-downstream-of-resolved-content also pauses
    # for resolution (was: raised). audit-logging gets refreshed with
    # the routed-hunks resolution, then write_patches walks up series;
    # any patch above audit-logging (pos 19) that touches MailManager
    # line 1-X cascades — currently snooze-source-tracking (pos 20)
    # and account-display-resolution (pos 22). One resolve answer
    # entry handles all N cascades because _resolve_any_conflict_markers
    # internally loops over each prompt until EXITCODE.
    make_quilt_interactive([
        ("Add to:",   "audit-logging"),     # Account.php
        ("Add to:",   "s"),                 # PageController persistent
        ("Add to:",   "account-throttle"),  # AccountThrottle.php (corrected)
        ("Add to:",   "audit-logging"),     # DraftsService.php
        ("Add to:",   "audit-logging"),     # MailManager.php
        ("Add to:",   "audit-logging"),     # MailTransmission.php
        ("Add to:",   "audit-logging"),     # ImapToDbSynchronizer.php
        ("[o]verlay", "s"),                 # .account-debug.log
        ("[o]verlay", "s"),                 # NOTES.md
        ("[o]verlay", "s"),                 # lingering-todo.md
        ("Apply?",  "y"),
        ("Resolve the markers", _resolve_any_conflict_markers),
    ])
    invariants()

    # ----------------------------------------------------------------
    # Deletion-routing pre-check — symmetric to the text-hunk
    # pre-check, applied on the deletion path. Anchored as substeps of the
    # recovery+retry above so they share state-and-narrative with the
    # wrong-routed text-hunk test that just ran (same dev, same
    # stack, different hunk type). Substep numbering avoids shifting
    # downstream STEP_NUM / content markers for the rest of the arc.
    # ----------------------------------------------------------------

    substep("delete .build/lib/Service/AccountThrottle.php — the dev "
            "now wants to remove the file entirely (decided the "
            "experimental class is a dead end after all). Created by "
            "account-throttle at series pos 25; routing the deletion "
            "to any patch below pos 25 should hit the symmetric "
            "delete-side pre-check.")
    delete_patched_file(NEW_FILE_EXISTING_DIR)
    invariants()

    substep("make quilt — wrong call: route the deletion to mid-stack "
            "audit-logging (pos 19). AccountThrottle.php doesn't exist "
            "at audit-logging's apply state — its creator-patch is "
            "above. Pre-check raises with the same shape as the "
            "text-hunk pre-check: file-doesn't-exist diagnostic, "
            "pending tag preserved, exit non-zero.")
    # Items at this substep's entry (extract order = Hunks then
    # NewFile, both alphabetical):
    #   Hunks (tracked):
    #     lib/Controller/PageController.php (modified — phase01-todo
    #       restore-skipped persistent edit)
    #     lib/Service/AccountThrottle.php (deleted vs new baseline —
    #       created by account-throttle pos 25; routed to
    #       audit-logging pos 19 → pre-check fires)
    #   NewFile (untracked — restore-skipped keeps INDEX at baseline):
    #     .account-debug.log (restore-skipped)
    #     NOTES.md (restore-skipped)
    #     lingering-todo.md (restore-skipped)
    make_quilt_interactive(
        [
            ("Add to:",   "s"),                # PageController persistent
            ("Add to:",   "audit-logging"),    # AccountThrottle deletion (PRE-CHECK FAILS)
            ("[o]verlay", "s"),                # .account-debug.log
            ("[o]verlay", "s"),                # NOTES.md
            ("[o]verlay", "s"),                # lingering-todo.md
            ("Apply?",    "y"),
        ],
        expect_exitcode_substr="===EXITCODE:2===")
    # catch handler emitted recover hint; tag preserved.
    invariants(expect_pending=True)

    substep("make assemble MODE=recover — dev's explicit recovery gesture")
    make_assemble("RECOVER", expect_rc=0)
    invariants(expect_pending=False)

    substep("make quilt — retry: route the deletion to a NEW patch "
            "above account-throttle ('retire-account-throttle' — top "
            "of stack at this point, no series-position constraint).")
    # Same items as substep c entry — MODE=recover restored .build/
    # pre-call. Order: Hunks first, NewFile next.
    make_quilt_interactive([
        ("Add to:",   "s"),                       # PageController persistent
        ("Add to:",   "n"),                       # AccountThrottle deletion → new patch
        ("Name:",     "retire-account-throttle"),
        ("[o]verlay", "s"),                       # .account-debug.log
        ("[o]verlay", "s"),                       # NOTES.md
        ("[o]verlay", "s"),                       # lingering-todo.md
        ("Apply?",    "y"),
    ])
    invariants()

    step("edit a patched file to remove a debug print "
         "(undoing the wrong save by hand)")
    edit_patched_file(PATCHED_PHP_A, "step30-remove-debug")
    invariants()

    step("edit a second patched file likewise")
    edit_patched_file(PATCHED_PHP_B, "step31-remove-debug")
    invariants()

    step("edit a third patched file likewise")
    edit_patched_file(PATCHED_PHP_C, "step32-remove-debug")
    invariants()

    phase("PHASE 4 — recovery, skip prompt (no DISCARD)")

    # Per the (d) addendum: no mid-arc DISCARD. The manual-rollback edits
    # from steps 30/31/32 stay dirty in working tree; the step 34 real-
    # feature edit lands on top. Strays from save 4 (NOTES.md,
    # .account-debug.log, PageController persistent edit, lingering-
    # todo.md) continue tagging along via restore-skipped.

    step("edit Account.php with the actual feature change (lands on top "
         "of step 30's manual-rollback marker; both end up in the same "
         "Hunk vs baseline)")
    edit_patched_file(PATCHED_PHP_A, "step34-real-feature")
    invariants()

    step("make quilt — not ready, skip everything")
    # Items at this step's entry (extract order = Hunks then NewFile,
    # both alphabetical):
    #   Hunks (tracked, modified):
    #     lib/Account.php (step34-real-feature + step30-remove-debug,
    #       both in one hunk vs post-save-5-retry baseline)
    #     lib/Controller/PageController.php (phase01-todo persistent
    #       restore-skipped)
    #     lib/Service/MailManager.php (step31-remove-debug)
    #     lib/Service/Sync/ImapToDbSynchronizer.php (step32-remove-debug)
    #   NewFile (untracked — restore-skipped keeps INDEX at baseline):
    #     .account-debug.log (restore-skipped)
    #     NOTES.md (restore-skipped)
    #     lingering-todo.md (restore-skipped)
    answers = [
        ("Add to:",   "s"),  # Account.php (step34 + step30)
        ("Add to:",   "s"),  # PageController persistent
        ("Add to:",   "s"),  # MailManager.php (step31)
        ("Add to:",   "s"),  # ImapToDbSync.php (step32)
        ("[o]verlay", "s"),  # .account-debug.log
        ("[o]verlay", "s"),  # NOTES.md
        ("[o]verlay", "s"),  # lingering-todo.md
    ]
    # Skip-only run: quilt_v2 exits 3 (nothing to save), make wraps to 2.
    make_quilt_interactive(answers,
                           expect_exitcode_substr="===EXITCODE:2===")
    invariants()

    step("make diff (skipped items still visible)")
    make_diff()
    invariants()

    step("make quilt — route Account.php to accessibility (carries both "
         "the step30 manual-rollback marker and step34-real-feature in "
         "one hunk); skip the rest (dev still hasn't decided what to do "
         "with the by-hand removal markers on MailManager / ImapToDbSync, "
         "or with the strays). Cascade likely on Account.php at "
         "accessibility apply state — baseline has audit-logging's step20 "
         "at line 2 (from save 4 retry's routing), but at accessibility "
         "(pos 7) apply state audit-logging (pos 19) hasn't been pushed; "
         "generic resolver handles.")
    # Same items as save 6's entry — order: Hunks first (alphabetical),
    # NewFile next (alphabetical).
    answers = [
        ("Add to:",   "accessibility"),   # Account.php (step34 + step30)
        ("Add to:",   "s"),               # PageController persistent
        ("Add to:",   "s"),               # MailManager.php (step31)
        ("Add to:",   "s"),               # ImapToDbSync.php (step32)
        ("[o]verlay", "s"),               # .account-debug.log
        ("[o]verlay", "s"),               # NOTES.md
        ("[o]verlay", "s"),               # lingering-todo.md
        ("Apply?",  "y"),
        ("Resolve the markers", _resolve_any_conflict_markers),
    ]
    make_quilt_interactive(answers)
    invariants()

    step("hand-edit audit-logging.patch (accidental violence — opens "
         "the wrong file in $EDITOR and types into it before realising)")
    hand_edit_patch_file("audit-logging", "step38")
    invariants()

    # The hand-edit on audit-logging.patch from the previous step is
    # NOT reverted. It tags along (committed onto ARC_BRANCH at the
    # next save's commit_arc_progress) for the rest of phases 4-7.
    # Every subsequent save's `quilt push -a` pushes audit-logging.patch
    # with the trailing comment — implicitly testing the platform's
    # tolerance of trailing junk in patch files the
    # platform contract is deterministic; if a future quilt-version
    # change breaks tolerance, those saves fail and surface it).

    step("make diff")
    make_diff()
    invariants()

    step("drop a vim swap file in .build/ (simulates an editor session "
         "that didn't clean up). Note: upstream's .gitignore matches "
         "`.*.sw?`, so this file is gitignored and stays invisible to "
         "extract (`git ls-files --others --exclude-standard`). It "
         "persists in WT through every save in phases 4-7 without "
         "ever appearing as a NewFile prompt — accurate model of the "
         "real-world vim-crash-leaves-swap scenario. End-of-phase-7 "
         "manual cleanup unlinks it.")
    drop_scratch_file(os.path.join(BUILD, ".MailManager.php.swp"), "")
    invariants()

    step("edit TagPopover.vue (overlay-symlinked)")
    edit_overlay_symlinked(OVERLAY_SYMLINKED_VUE_C, "step43")
    invariants()

    step("edit ImapToDbSynchronizer.php (patched)")
    edit_patched_file(PATCHED_PHP_C, "step44")
    invariants()

    step("drop a random file in apps/mail/overlay/ (accidental violence)")
    random_file_in_apps_mail("overlay/scratch.txt", "step 45\n")
    invariants()

    phase("PHASE 5 — multitasking, parallel, prompts")

    step("make quilt — route ImapToDbSynchronizer to responsiveness "
         "(drops the forward-engineered routing that picked "
         "thread-reply-refresh purely to keep the later phase-6 retry "
         "routing of ImapToDbSync to responsiveness cascade-safe; "
         "cascade may fire here at responsiveness's apply state since "
         "tags-itsl at pos 11 modifies ImapToDbSync but is above "
         "responsiveness pos 8 — generic resolver handles). Skip the "
         "persistent strays + the still-restore-skipped MailManager "
         "(step31) by-hand removal marker.")
    # Items at this step's entry (extract order = Hunks then NewFile,
    # both alphabetical):
    #   Hunks (tracked, modified):
    #     lib/Controller/PageController.php (phase01-todo)
    #     lib/Service/MailManager.php (step31 restore-skipped from
    #       save 7)
    #     lib/Service/Sync/ImapToDbSynchronizer.php (step44 +
    #       step32 by-hand removal marker)
    #   NewFile (untracked — restore-skipped keeps INDEX at baseline):
    #     .account-debug.log (restore-skipped)
    #     NOTES.md (restore-skipped)
    #     lingering-todo.md (restore-skipped)
    # The .MailManager.php.swp dropped at step 42 is gitignored
    # (`.*.sw?` matches per upstream's .gitignore) — invisible to
    # extract. No prompt fires for it; no answer entry needed.
    answers = [
        ("Add to:",   "s"),                # PageController persistent
        ("Add to:",   "s"),                # MailManager (step31)
        ("Add to:",   "responsiveness"),   # ImapToDbSync (step44+step32)
        ("[o]verlay", "s"),                # .account-debug.log
        ("[o]verlay", "s"),                # NOTES.md
        ("[o]verlay", "s"),                # lingering-todo.md
        ("Apply?",    "y"),
        ("Resolve the markers", _resolve_any_conflict_markers),
    ]
    make_quilt_interactive(answers)
    invariants()

    step("lock contention — second terminal tries make quilt while "
         "the first holds the lock; expects fail-fast with rc=6")
    lock_contention_test()
    invariants()

    step("make diff (cross-check after the lock contention)")
    make_diff()
    invariants()

    step("new file in a brand-new directory tree: "
         "lib/AI/Throttle/RateLimiter.php (NewOverlayDir territory)")
    new_file_in_new_dir_tree(
        NEW_FILE_NEW_DIR_TREE_A,
        "<?php\n// state-integrity step49\nclass RateLimiter {}\n")
    invariants()

    step("make quilt — route NewOverlayDir to a new patch 'ai-throttle' "
         "(covers NewOverlayDir → patch branch). Skip persistent strays "
         "and the still-restore-skipped MailManager (step31).")
    # Items at this step's entry (extract order = Hunks, NewOverlayDir,
    # NewFile):
    #   Hunks (tracked, modified, alphabetical):
    #     lib/Controller/PageController.php (phase01-todo)
    #     lib/Service/MailManager.php (step31)
    #   NewOverlayDir (sorted by dir_path):
    #     lib/AI (contains RateLimiter.php; dir doesn't exist in upstream)
    #   NewFile (untracked; alphabetical via ls-files --others):
    #     .account-debug.log (restore-skipped)
    #     NOTES.md (restore-skipped)
    #     lingering-todo.md (restore-skipped)
    # The .MailManager.php.swp dropped earlier is gitignored (`.*.sw?`)
    # — invisible to extract; no prompt, no answer entry.
    answers = [
        ("Add to:",   "s"),               # PageController persistent
        ("Add to:",   "s"),               # MailManager (step31)
        ("[o]verlay", "p"),               # lib/AI NewOverlayDir → patch
        ("Add to:",   "n"),               # ...into a new patch
        ("Name:",     "ai-throttle"),
        ("[o]verlay", "s"),               # .account-debug.log
        ("[o]verlay", "s"),               # NOTES.md
        ("[o]verlay", "s"),               # lingering-todo.md
        ("Apply?",    "y"),
    ]
    make_quilt_interactive(answers)
    invariants()

    step("another new file in another new dir tree: "
         "lib/AI/Throttle/policies/Default.php (NewOverlayDir, "
         "second variant)")
    new_file_in_new_dir_tree(
        NEW_FILE_NEW_DIR_TREE_B,
        "<?php\n// state-integrity step51\nclass Default_ {}\n")
    invariants()

    step("make quilt — route this NewOverlayDir to overlay "
         "(covers NewOverlayDir → overlay branch). Skip the persistent "
         "strays + still-pending MailManager (step31) per the (d) "
         "addendum's tag-along policy.")
    # Items at this step's entry (extract order = Hunks, NewOverlayDir,
    # NewFile):
    #   Hunks (tracked, modified, alphabetical):
    #     lib/Controller/PageController.php (phase01-todo)
    #     lib/Service/MailManager.php (step31)
    #   NewOverlayDir:
    #     lib/AI (Default.php only; RateLimiter is now in baseline via
    #       ai-throttle's contribution from save 9)
    #   NewFile (untracked; alphabetical):
    #     .account-debug.log (restore-skipped)
    #     NOTES.md (restore-skipped)
    #     lingering-todo.md (restore-skipped)
    # .MailManager.php.swp gitignored — invisible.
    answers = [
        ("Add to:",   "s"),  # PageController persistent
        ("Add to:",   "s"),  # MailManager (step31)
        ("[o]verlay", "o"),  # lib/AI NewOverlayDir → overlay
        ("[o]verlay", "s"),  # .account-debug.log
        ("[o]verlay", "s"),  # NOTES.md
        ("[o]verlay", "s"),  # lingering-todo.md
        ("Apply?",    "y"),
    ]
    make_quilt_interactive(answers)
    invariants()

    step("chmod +x lib/functions.php (PermissionChange — quilt patches "
         "don't carry mode info, so this item auto-skips)")
    chmod_file(CHMOD_TARGET, 0o755)
    invariants()

    step("make quilt — chmod auto-skips "
         "the 5 stray-skip items remain (persistent strays + scratches "
         "+ MailManager step31). Skip them all → 0 items routed → "
         "quilt_v2 exits 3, make wraps to 2.")
    # PermissionChange auto-skips silently (removed from items before the
    # present loop). .MailManager.php.swp is gitignored — invisible.
    # The 5 visible items still need answers — order: Hunks first
    # (alphabetical), NewFile next (alphabetical).
    answers = [
        ("Add to:",   "s"),  # PageController persistent
        ("Add to:",   "s"),  # MailManager (step31)
        ("[o]verlay", "s"),  # .account-debug.log
        ("[o]verlay", "s"),  # NOTES.md
        ("[o]verlay", "s"),  # lingering-todo.md
    ]
    make_quilt_interactive(
        answers, expect_exitcode_substr="===EXITCODE:2===")
    invariants()

    step("make diff (chmod still pending)")
    make_diff()
    invariants()

    step("hand-edit overlay/composer.json directly in apps/mail "
         "(not through the .build/ symlink — modifies parent repo)")
    hand_edit_overlay_via_parent(COPYFILE_COMPOSER, "step56")
    invariants()

    step("half-committed parent: commit one patches/ change, leave "
         "another uncommitted (the kind of mid-flight state a dev "
         "abandons when interrupted)")
    # Commit lands on ARC_BRANCH (we're on the temp branch). Cleanup
    # C03's git reset --hard back to suite-start commit drops it when
    # we return to the original branch. Use accessibility.patch as the
    # touched-and-committed file; the composer.json hand-edit (prior
    # step) stays dirty.
    make_half_committed_parent(
        commit_path="patches/accessibility.patch",
        dirty_path="overlay/composer.json",
        commit_marker="step57-committed",
        dirty_marker="step57-dirty")
    invariants()

    step("make quilt — same items as the previous skip-only save (the "
         "previous two steps touched parent repo only — overlay/"
         "composer.json hand-edit and the half-commit are invisible "
         "from .build/'s side). Chmod still auto-skips; persistent "
         "strays + scratches + MailManager (step31) still need to be "
         "skipped.")
    # Same items as save 11 (chmod still auto-skips; previous two
    # steps touched parent repo only; .swp gitignored). Order: Hunks
    # first, NewFile next.
    answers = [
        ("Add to:",   "s"),  # PageController persistent
        ("Add to:",   "s"),  # MailManager (step31)
        ("[o]verlay", "s"),  # .account-debug.log
        ("[o]verlay", "s"),  # NOTES.md
        ("[o]verlay", "s"),  # lingering-todo.md
    ]
    make_quilt_interactive(
        answers, expect_exitcode_substr="===EXITCODE:2===")
    invariants()

    phase("PHASE 6 — failure and recovery")

    step("synthesize an after-_capture_pending state — models the "
         "user-Ctrl-C / external-SIGKILL between capture and write-start. "
         "Direct git ops in .build/.git produce the same state a real "
         "kill would, without instrumenting production code.")
    synthesize_pending_state(PATCHED_PHP_A, "step59-pre-recovery")
    invariants(expect_pending=True)

    step("make diff with pending tag set — must produce real diff "
         "output showing the synthesized pending edit on Account.php")
    r = make_diff()
    check(f"S{STEP_NUM:03d}{SUBSTEP_LETTER}-diff-shows-pending-edit",
          "lib/Account.php" in r.stdout,
          f"expected lib/Account.php in diff stdout (the synthesize-"
          f"pending edit should be visible); "
          f"stdout={r.stdout}")
    invariants(expect_pending=True)

    step("dev tries plain assemble with pending tag set — refused with "
         "the pending-aware diagnostic pointing at make assemble "
         "MODE=recover platform "
         "handles state-changing operations as explicit user gestures; "
         "plain's diagnostic is the user-facing surface that points at "
         "the recovery command). Pin the diagnostic content so a "
         "regression to the generic dirty-tree refusal surfaces here.")
    r = _run(["make", "assemble"], cwd=APP_ROOT, env=_make_env(), timeout=60)
    check(f"S{STEP_NUM:03d}{SUBSTEP_LETTER}-pending-refused",
          r.returncode != 0,
          f"plain assemble should refuse on pending tag, got rc=0; "
          f"stderr={r.stderr}")
    check(f"S{STEP_NUM:03d}{SUBSTEP_LETTER}-pending-aware-diagnostic",
          "make assemble MODE=recover" in r.stderr,
          f"expected pending-aware diagnostic ('make assemble MODE=recover' "
          f"in stderr); got stderr={r.stderr}")
    invariants(expect_pending=True)

    step("make assemble MODE=recover — restores .build/ to pre-make-quilt "
         "state via the synthesized pending tag. recovery "
         "is the user's explicit gesture; this test step exercises the "
         "MODE=recover mode against a synthesized SIGKILL-style pending "
         "tag (different code path from the catch-handler-emits-hint "
         "scenarios in earlier phases — same primitive, different "
         "trigger).")
    make_assemble("RECOVER", expect_rc=0)
    invariants(expect_pending=False)

    step("make quilt — actual save of the now-restored uncommitted "
         "edit, routing Account.php to upstream-bugfixes ("
         "above accessibility's position 7 so the hunk's all-applied-"
         "state context matches at apply; routing variety across the "
         "arc rather than always-home-patch).")
    invariants(expect_pending=False)
    # After recovery, working tree has the synthesized edit on
    # PATCHED_PHP_A plus all persistent strays + scratches + chmod
    # + PageController + MailManager (step31 still pending). PHP_A is
    # routed; everything else skipped. Extract order = Hunks then
    # NewFile, both alphabetical:
    #   Hunks (tracked, modified):
    #     lib/Account.php (step59-pre-recovery)
    #     lib/Controller/PageController.php (phase01-todo)
    #     lib/Service/MailManager.php (step31)
    #     lib/functions.php (PermissionChange — auto-skips, no prompt)
    #   NewFile (untracked — restore-skipped keeps INDEX at baseline):
    #     .account-debug.log
    #     NOTES.md
    #     lingering-todo.md
    # .MailManager.php.swp gitignored — invisible.
    # Cascade likely on Account.php at upstream-bugfixes (pos 18)
    # apply state: audit-logging at pos 19 carries step20 from save 4
    # retry's routing, but pos 19 isn't pushed at pos 18's apply state;
    # the user's hunk's "before" context expects line 2 = step20 from
    # baseline, but working tree at apply has accessibility's
    # step34/step30/step12 markers without step20. Generic resolver
    # handles markers patch -p1 --merge writes.
    make_quilt_interactive([
        ("Add to:",   "upstream-bugfixes"),  # Account.php (step59-pre-recovery)
        ("Add to:",   "s"),                  # PageController persistent
        ("Add to:",   "s"),                  # MailManager (step31)
        ("[o]verlay", "s"),                  # .account-debug.log
        ("[o]verlay", "s"),                  # NOTES.md
        ("[o]verlay", "s"),                  # lingering-todo.md
        ("Apply?", "y"),
        ("Resolve the markers", _resolve_any_conflict_markers),
    ])
    invariants()

    step("sabotage a patch file (unlink) so patches/series references a "
         "file that no longer exists. make quilt aborts in the extract phase "
         "at _check_series_patches_consistent BEFORE any prompt and before _capture_pending "
         "(F11): no prompt fires, no pending tag is created, .build/ is "
         "untouched (extract is read-only). Pre-F11 this silently passed "
         "extract and failed later inside write_patches' quilt push; the "
         "corrupt-in-place step below still exercises that write_patches "
         "raise path.")
    edit_patched_file(PATCHED_PHP_B, "step63-pre-failure")
    sabotage_patch_file("hide-customer-noise")
    # [] answers: extract raises before any prompt fires; make wraps the
    # python crash to rc=2. The dev's MailManager edit + persistent strays
    # stay in .build/'s WT (never mutated); they get saved in the retry
    # below once series<->patches is consistent again.
    pane = make_quilt_interactive([], expect_exitcode_substr="===EXITCODE:2===")
    check(f"S{STEP_NUM:03d}-extract-raise-msg",
          "out of sync" in pane,
          f"expected the F11 series<->patches 'out of sync' message in the "
          f"pane, got:\n{pane[-2000:]}")
    # No pending tag (extract raised before _capture_pending); series
    # mismatch present in the parent repo (the unlink); .build/ untouched.
    invariants(expect_pending=False, expect_series_mismatch=True)

    # Pre-Fix-15 had a `make diff during sabotage` step here. Under
    # preserve-tag-and-emit-hint contract, .build/'s WT post-
    # failure is in partial-pop state (write_patches' opening
    # `git reset --hard baseline` followed by `quilt pop -a` that got
    # most of the way through before hitting the sabotaged patch),
    # which produces a huge baseline-relative diff (24+ patches'
    # worth of file content reverted). diff.sh's per-file
    # patches/series scan multiplies through that and hits the
    # 60s `make_diff` timeout. The step's intent — "make-diff works
    # under pending state" — is already covered by S059's make-diff
    # against the synthesize_pending_state setup, where WT is at
    # pending content (small diff). Cut the partial-pop equivalent.

    step("restore the sabotaged patch via git checkout, then retry "
         "make quilt — no MODE=recover needed. F11 raised at extract "
         "before _capture_pending, so there is no pending tag and .build/ "
         "was never mutated; the dev's edit is still in the WT. Once "
         "series<->patches is consistent again the retry saves normally.")
    # The sabotage was an uncommitted unlink in apps/mail/; checkout
    # restores from index, resolving the series<->patches mismatch.
    _run(["git", "-C", APP_ROOT, "checkout", "--",
          "patches/hide-customer-noise.patch"],
         env=GIT_ENV, check=True)
    invariants(expect_pending=False)
    # .build/ untouched by the failed extract: PATCHED_PHP_B's edit + the
    # persistent strays are still in the WT.
    # route MailManager to accessibility (cascade-prone:
    # accessibility position 7 < account-display-resolution position 22
    # which carries MailManager step02; the generic resolver handles
    # any markers patch -p1 --merge writes). Skip the strays. Extract
    # order = Hunks then NewFile, both alphabetical.
    # .MailManager.php.swp gitignored — invisible.
    make_quilt_interactive([
        ("Add to:",   "s"),               # PageController persistent
        ("Add to:",   "accessibility"),   # MailManager (step63 + step31)
        ("[o]verlay", "s"),               # .account-debug.log
        ("[o]verlay", "s"),               # NOTES.md
        ("[o]verlay", "s"),               # lingering-todo.md
        ("Apply?", "y"),
        ("Resolve the markers", _resolve_any_conflict_markers),
    ])
    invariants()

    # ----------------------------------------------------------------
    # Corrupt-in-place sabotage variant — different failure shape from
    # the unlink sabotage above + below.
    # ----------------------------------------------------------------

    step("corrupt a patch in-place (mangled hunk header on "
         "ai-processing-disabled) — different failure shape from "
         "unlink-sabotage. Empirically (verified against container "
         "quilt 0.66): write_patches' opening `quilt pop -a` reaches "
         "the corrupt patch, fails to reverse-apply the malformed "
         "hunk, exits rc=1; save returns make rc=2 with pending tag "
         "preserved for next-run recovery. This is the design's "
         "deliberate 'refuse cleanly' outcome (vs the alternatives "
         "'tolerate' or 'succeed-with-garbage'). The next test step "
         "restores the patch via git checkout and runs explicit "
         "recovery before the next sabotage scenario fires.")
    edit_patched_file(PATCHED_PHP_D, "step66b-pre-corrupt")
    sabotage_patch_file_corrupt("ai-processing-disabled")
    # Items at this step's entry (extract order = Hunks then NewFile,
    # alpha):
    #   Hunks (tracked, modified):
    #     lib/Controller/PageController.php (phase01-todo)
    #     lib/Service/DraftsService.php (step66b-pre-corrupt)
    #     lib/functions.php (PermissionChange — auto-skips, no prompt)
    #   NewFile (untracked — restore-skipped keeps INDEX at baseline):
    #     .account-debug.log, NOTES.md, lingering-todo.md
    # .MailManager.php.swp gitignored — invisible.
    make_quilt_interactive([
        ("Add to:",   "s"),                       # PageController persistent
        ("Add to:",   "ai-processing-disabled"),  # DraftsService (step66b-pre-corrupt)
        ("[o]verlay", "s"),                       # .account-debug.log
        ("[o]verlay", "s"),                       # NOTES.md
        ("[o]verlay", "s"),                       # lingering-todo.md
        ("Apply?", "y"),
    ], expect_exitcode_substr="===EXITCODE:2===")
    # corrupt-in-place's quilt pop -a failure raises out of
    # write_patches; catch handler emits recover hint; tag preserved.
    invariants(expect_pending=True)

    step("restore the corrupted patch via git checkout, then "
         "make assemble MODE=recover to restore .build/. The parent "
         "repo's corrupted patch file is restored here so the next "
         "sabotage scenario starts from a clean apps/mail/.")
    _run(["git", "-C", APP_ROOT, "checkout", "--",
          "patches/ai-processing-disabled.patch"],
         env=GIT_ENV, check=True)
    make_assemble("RECOVER", expect_rc=0)
    invariants(expect_pending=False)

    step("sabotage a deeper-in-series patch (unlink responsiveness, series "
         "position 8). F11 raises at extract the same as for a position-1 "
         "patch: _check_series_patches_consistent checks every series entry, so the raise "
         "is position-independent — no write_patches walk, no accumulated "
         ".pc/ state. No prompt, no pending tag, .build/ untouched.")
    edit_patched_file(PATCHED_PHP_C, "step66-pre-failure")
    sabotage_patch_file("responsiveness")
    # [] answers: extract raises before any prompt. The dev's ImapToDbSync
    # edit + DraftsService edit + persistent strays stay in .build/'s WT;
    # saved in the retry below.
    pane = make_quilt_interactive([], expect_exitcode_substr="===EXITCODE:2===")
    check(f"S{STEP_NUM:03d}-extract-raise-msg",
          "out of sync" in pane,
          f"expected the F11 series<->patches 'out of sync' message in the "
          f"pane, got:\n{pane[-2000:]}")
    # No pending tag (extract raised before _capture_pending); series
    # mismatch present in the parent repo (the unlink); .build/ untouched.
    invariants(expect_pending=False, expect_series_mismatch=True)

    step("restore responsiveness.patch via git checkout, then retry "
         "make quilt — no MODE=recover needed (F11 raised at extract; no "
         "pending tag, .build/ untouched).")
    _run(["git", "-C", APP_ROOT, "checkout", "--",
          "patches/responsiveness.patch"],
         env=GIT_ENV, check=True)
    invariants(expect_pending=False)
    # retry routes ImapToDbSync to responsiveness — cascade
    # may fire (depends on which patches above position 8 modify
    # ImapToDbSync's line-2 area; tags-itsl position 11 does); generic
    # resolver handles any markers. Items at retry entry mirror the
    # failed-extract entry — .build/ was never mutated, so DraftsService's
    # still-uncommitted step66b-pre-corrupt edit (and the rest) are still
    # present. Order: Hunks first (alphabetical), NewFile next
    # (alphabetical). .MailManager.php.swp gitignored —
    # invisible.
    make_quilt_interactive([
        ("Add to:",   "s"),               # PageController persistent
        ("Add to:",   "s"),               # DraftsService (step66b-pre-corrupt restored)
        ("Add to:",   "responsiveness"),  # ImapToDbSync (step66-pre-failure)
        ("[o]verlay", "s"),               # .account-debug.log
        ("[o]verlay", "s"),               # NOTES.md
        ("[o]verlay", "s"),               # lingering-todo.md
        ("Apply?", "y"),
        ("Resolve the markers", _resolve_any_conflict_markers),
    ])
    invariants()

    step("make diff")
    make_diff()
    invariants()

    # ----------------------------------------------------------------
    # Pause-and-continue UX coverage
    # ----------------------------------------------------------------
    # When patch -p1 --merge fails to apply during write_patches'
    # per-patch loop, the platform writes conflict markers into the
    # affected files and pauses with a
    # prompt. The dev resolves markers (or doesn't), then either
    # presses Enter to continue (quilt refresh against the resolved
    # tree) or Ctrl-C to abort (lock released, pending tag preserved).
    # Two test cases covering both branches.
    #
    # Deterministic conflict trigger: replace the
    # "// state-integrity: step02" marker (added at all-applied state
    # by account-display-resolution at pos 7 via phase 1's first save)
    # with "// state-integrity: pause-continue-bait", route to
    # hide-customer-noise (series position 1). The captured hunk has
    # a `-` line for the step02 marker; at hide-customer-noise's
    # apply state, no patches above are pushed, so the step02 marker
    # doesn't exist anywhere in the file — patch -p1 --merge can't
    # find the line to delete, writes "<<<<<<< / ======= / >>>>>>>"
    # markers around the affected hunk, exits rc=1, write_patches
    # pauses with a prompt.
    #
    # Why a replace-shape edit (not a pure prepend at line 1, which
    # was the obvious-looking choice and what an earlier shape
    # used): patch -p1 --merge applies pure-add hunks at line 1
    # cleanly even when subsequent context lines don't match. The
    # `+` line goes BEFORE the matched anchor; lines below the
    # anchor are never touched by the apply, so their context
    # mismatching doesn't fail the apply. Verified empirically
    # against the actual platform invocation flags
    # (`patch -p1 --merge --no-backup-if-mismatch --batch`) — even
    # with `--fuzz=0` explicitly, pure prepend at line 1 reports
    # "Hunk #1 merged at 1." rc=0, no markers. Replace-shape forces
    # patch to locate a specific line content for deletion; the
    # absence of that content is what triggers the markers.

    step("edit MailManager.php — replace the 'step02' marker with "
         "'pause-continue-bait'. Sets up a deterministic apply "
         "failure for the next save: the '// state-integrity: step02' "
         "line exists at all-applied state (added by "
         "account-display-resolution at pos 7 via phase 1's first save) "
         "but doesn't exist at hide-customer-noise's apply state "
         "(pos 1, no patches above pushed); patch -p1 --merge can't "
         "find the line to delete, writes conflict markers, write_patches "
         "pauses. (Replace-shape, not pure prepend — see the comment "
         "block above for the empirical rationale.)")
    _mm_full = os.path.join(BUILD, PATCHED_PHP_B)
    with open(_mm_full, "r", encoding="utf-8", errors="surrogateescape") as f:
        _mm_content = f.read()
    _PAUSE_CONTINUE_OLD_MARKER = "// state-integrity: step02"
    _PAUSE_CONTINUE_NEW_MARKER = "// state-integrity: pause-continue-bait"
    if _PAUSE_CONTINUE_OLD_MARKER not in _mm_content:
        # Fixture invariant: account-display-resolution carries the
        # step02 marker into all-applied state via phase 1's first save
        # (phase 1's first edit inserts it; phase 1's first save routes it).
        # If it's missing here,
        # earlier phases regressed and the conflict trigger won't
        # work as designed. Crash-fast with a clear diagnostic
        # rather than letting the next save silently apply cleanly.
        raise StateIntegrityFailure(
            f"pause-continue fixture invariant violated: {PATCHED_PHP_B} "
            f"doesn't contain {_PAUSE_CONTINUE_OLD_MARKER!r} at this step's "
            f"entry. "
            f"account-display-resolution should carry this marker "
            f"into all-applied state via phase 1's first save.")
    with open(_mm_full, "w", encoding="utf-8", errors="surrogateescape") as f:
        # Replace only the first occurrence to avoid touching
        # downstream-introduced markers (e.g., later edit_patched_file
        # calls that inserted similar-looking lines via line-2 prepend
        # at later steps). The first occurrence is the
        # account-display-resolution one we want to delete.
        f.write(_mm_content.replace(
            _PAUSE_CONTINUE_OLD_MARKER, _PAUSE_CONTINUE_NEW_MARKER, 1))
    invariants()

    step("make quilt — route to hide-customer-noise (series position 1). "
         "Hunk has a `-` line for '// state-integrity: step02' that "
         "doesn't exist at hide-customer-noise's apply state; patch "
         "-p1 --merge writes conflict markers, write_patches pauses. "
         "Test B: dev decides 'no, abort' → Ctrl-C. quilt_v2 catches "
         "KeyboardInterrupt, exits 130 (make wraps to rc=2), pending "
         "tag preserved for next-run recovery. Persistent strays + "
         "scratches + chmod skipped as ever.")
    # Items at entry (extract order = Hunks then NewFile, alpha):
    #   Hunks (tracked, modified):
    #     lib/Controller/PageController.php (phase01-todo)
    #     lib/Service/DraftsService.php (step66b-pre-corrupt — still
    #       pending from corrupt-in-place restoration; previous
    #       responsiveness-sabotage save also skipped it)
    #     lib/Service/MailManager.php (step02 → pause-continue-bait
    #       replace; the captured hunk's `-` line is what triggers
    #       the apply failure at hide-customer-noise's apply state)
    #     lib/functions.php (PermissionChange — auto-skips, no prompt)
    #   NewFile (untracked — restore-skipped keeps INDEX at baseline):
    #     .account-debug.log, NOTES.md, lingering-todo.md
    # .MailManager.php.swp gitignored — invisible.
    make_quilt_interactive(
        [
            ("Add to:",   "s"),                    # PageController persistent
            ("Add to:",   "s"),                    # DraftsService (still restored)
            ("Add to:",   "hide-customer-noise"),  # MailManager (pause-continue-bait)
            ("[o]verlay", "s"),                    # .account-debug.log
            ("[o]verlay", "s"),                    # NOTES.md
            ("[o]verlay", "s"),                    # lingering-todo.md
            ("Apply?",  "y"),
            ("Resolve the markers", "C-c"),
        ],
        expect_exitcode_substr="===EXITCODE:2===")
    # Ctrl-C raises KeyboardInterrupt out of write_patches;
    # main()'s catch handler emits the "run make assemble MODE=recover"
    # diagnostic; tag preserved.
    invariants(expect_pending=True)

    # Pre-Fix-15 had a `make diff after the aborted Ctrl-C` step
    # here; cut for the same reason as the unlink-sabotage one above
    # (partial-pop state's preserve-tag contract → huge
    # diff → diff.sh's per-file patches/series scan exceeds the 60s
    # make_diff timeout). The "make-diff works under pending state"
    # contract is covered by S059's make-diff against
    # synthesize_pending_state.

    step("make assemble MODE=recover — dev's explicit recovery gesture "
         "after the Ctrl-C abort.")
    make_assemble("RECOVER", expect_rc=0)
    invariants(expect_pending=False)

    step("make quilt — retry the same routing, this time the dev "
         "resolves the markers (generic resolver strips conflict-"
         "marker lines from any file containing them) and presses "
         "Enter. write_patches' input() returns; quilt refresh "
         "captures the resolved content; save proceeds. Test C: "
         "pause-and-continue's resolve-and-continue branch.")
    # Same items as the aborted save's entry — MODE=recover restored
    # .build/ pre-call, including DraftsService's
    # still-uncommitted step66b-pre-corrupt edit. Order: Hunks first
    # (alphabetical), NewFile next (alphabetical).
    # .MailManager.php.swp gitignored — invisible.
    make_quilt_interactive([
        ("Add to:",   "s"),                    # PageController persistent
        ("Add to:",   "s"),                    # DraftsService (still restored)
        ("Add to:",   "hide-customer-noise"),  # MailManager (pause-continue-bait)
        ("[o]verlay", "s"),                    # .account-debug.log
        ("[o]verlay", "s"),                    # NOTES.md
        ("[o]verlay", "s"),                    # lingering-todo.md
        ("Apply?",  "y"),
        ("Resolve the markers", _resolve_any_conflict_markers),
    ])
    invariants()

    step("edit Account.php (fresh after the conflict-resolution sequence)")
    edit_patched_file(PATCHED_PHP_A, "step69")
    invariants()

    step("edit MailManager.php")
    edit_patched_file(PATCHED_PHP_B, "step70")
    invariants()

    phase("PHASE 7 — edit-heavy, long dirty period")

    step("edit EnvelopeItsl.vue (overlay-symlinked)")
    edit_overlay_symlinked(OVERLAY_SYMLINKED_VUE_A, "step71")
    invariants()

    step("make diff")
    make_diff()
    invariants()

    step("edit-then-revert Account.php (write same bytes back)")
    edit_then_revert(PATCHED_PHP_A, "step73")
    invariants()

    step("make diff — Account.php should be absent (revert was a no-op)")
    make_diff()
    invariants()

    step("pile-on: edit MailManager.php again")
    edit_patched_file(PATCHED_PHP_B, "step75")
    invariants()

    step("drop test-output.log scratch")
    drop_scratch_file(os.path.join(BUILD, "test-output.log"),
                      "step 76 test output\n")
    invariants()

    step("make diff")
    make_diff()
    invariants()

    step("make diff (long dwell — devs check repeatedly while thinking)")
    make_diff()
    invariants()

    step("make diff (long dwell)")
    make_diff()
    invariants()

    # ----------------------------------------------------------------
    # End-of-feature: branch round-trip with dirty .build/
    # ----------------------------------------------------------------
    # Per the chaos arc's design: no mid-arc DISCARD/FORCE. The dev
    # cleans up by hand on the parent repo (apps/mail) before the
    # branch switch — explicit gestures, not a make-target nuke.
    #
    # Critically: `.build/` is NOT cleaned up here. Pre-Fix-10 we ran
    # `git checkout baseline -- .` to wipe accumulated dirt so that
    # plain assemble's refuse-on-dirty wouldn't fire on round-trip.
    # That hand-cleanup laundered exactly the accumulated state the
    # arc exists to stress-test. The current platform shape
    # (assemble.sh's stash + walk + stash pop reconciliation, plus
    # the catch handler that auto-recovers on graceful failure)
    # makes round-trip on dirty `.build/` the ULTIMATE end-to-end
    # test of platform reconciliation. The misplaced-make-quilt step
    # below + S090's plain assemble exercise:
    #   - Cross-branch series mismatch (.pc/ from ARC_BRANCH, series
    #     from ORIGINAL_BRANCH).
    #   - write_patches' opening `quilt pop -a` failure on phantom .pc/.
    #   - catch handler auto-recovery on graceful failure.
    #   - stash + walk + restore.
    #   - Edit-preservation across the entire reconciliation.
    #
    # The apps/mail cleanup (overlay/+patches/ via git checkout) DOES
    # stay — different purpose. Without it, `git switch` would refuse
    # on conflicting unstaged changes if any overlay edit overlaps
    # with ORIGINAL_BRANCH's content. That's gating git switch, not
    # the platform's reconciliation contract. Keep.
    #
    # Phase 8 runs on ORIGINAL_BRANCH with no further commits.
    # Cleanup's git reset --hard back to suite-start commit drops
    # phase 8's working-tree dirt.

    step("clean uncommitted overlay edits in apps/mail (parent repo) — "
         "overlay edits made through .build/ symlinks propagate to "
         "apps/mail/overlay/<file> and stay dirty there until cleaned "
         "up. git switch would refuse on conflicting unstaged changes "
         "if those overlap with ORIGINAL_BRANCH's content. (Note: "
         ".build/'s WT stays dirty here — see the comment block above.)")
    # Restrict to the directories ARC_BRANCH commits touch (patches/,
    # overlay/) — random-violence files at apps/mail's root and in
    # patches/ stay around as untracked, the kind of dirt cleanup C04
    # handles. Without --staged: drops working-tree mods only; index
    # already matches HEAD because nothing's been staged-but-not-committed
    # since the last commit_arc_progress.
    _run(["git", "-C", APP_ROOT, "checkout", "--",
          "overlay", "patches"],
         env=GIT_ENV, check=True)
    invariants()

    step(f"git switch back to {ORIGINAL_BRANCH} (end of feature)")
    _run(["git", "switch", ORIGINAL_BRANCH],
         cwd=APP_ROOT, env=GIT_ENV, check=True)
    invariants()

    step(f"misplaced make quilt — dev habitually invokes it post-switch "
         f"with cross-branch state (.pc/ from {ARC_BRANCH} series, "
         f"patches/series from {ORIGINAL_BRANCH}). write_patches' opening "
         f"`quilt pop -a` (kept strict per the design) fails "
         f"on phantom .pc/ entries; the catch handler in "
         f"main() emits the 'run make assemble MODE=recover' diagnostic; "
         f"pending tag preserved; exits 1 (make wraps to 2). Tests: "
         f"write_patches' failure surfacing on cross-branch state, "
         f"the recover-hint diagnostic, non-corruption of accumulated "
         f"dev edits.")
    # Items extract sees in this state — the accumulated dirt from
    # phases 1-7. Hunks first (alphabetical by full path), then
    # NewFile / NewOverlayDir (alphabetical, dot-files first).
    #
    # Hunks (tracked-modified):
    #   lib/Account.php        — fresh edit from phase 7's "edit
    #                            Account.php (fresh after the
    #                            conflict-resolution sequence)" step.
    #   lib/Controller/PageController.php
    #                          — phase01-todo persistent edit.
    #   lib/Service/DraftsService.php
    #                          — corrupt-in-place restore-skipped from
    #                            S066, kept-dirty across all subsequent
    #                            saves.
    #   lib/Service/MailManager.php
    #                          — phase 7's pile-on edit (step70 + step75).
    #
    # NewFile / NewOverlayDir (untracked, INDEX-stays-at-baseline so
    # they remain untracked across restore-skipped iterations):
    #   .account-debug.log     — persistent stray.
    #   NOTES.md               — persistent stray.
    #   lingering-todo.md      — persistent stray.
    #   test-output.log        — phase 7's "drop test-output.log
    #                            scratch" step (first encounter).
    #
    # .MailManager.php.swp is gitignored upstream (`.*.sw?` pattern) —
    # invisible to extract.
    #
    # Route Account.php to accessibility (an existing patch on
    # ORIGINAL_BRANCH that touches Account.php — natural target). Skip
    # everything else. Apply. write_patches enters per-patch loop, fails
    # at opening `quilt pop -a` on phantom .pc/, raises, catch handler
    # emits recover-hint, exits 1; pending tag preserved.
    answers = [
        ("Add to:",   "accessibility"),  # Account.php — routed
        ("Add to:",   "s"),              # PageController persistent
        ("Add to:",   "s"),              # DraftsService restore-skipped
        ("Add to:",   "s"),              # MailManager step70+step75
        ("[o]verlay", "s"),              # .account-debug.log
        ("[o]verlay", "s"),              # NOTES.md
        ("[o]verlay", "s"),              # lingering-todo.md
        ("[o]verlay", "s"),              # test-output.log
        ("Apply?",    "y"),
    ]
    make_quilt_interactive(answers,
                           expect_exitcode_substr="===EXITCODE:2===")
    # Post-condition: pending tag preserved by the catch handler's
    # catch-handler-doesn't-touch-state contract.
    invariants(expect_pending=True)

    step("make assemble MODE=recover — dev's explicit recovery gesture "
         "after the misplaced-quilt failure. Bash recovery primitive "
         "in assemble.sh restores .build/ to dev's pre-quilt-edit "
         "state. Same accumulated dev edits as before the misplaced "
         "save attempt.")
    make_assemble("RECOVER", expect_rc=0)
    invariants(expect_pending=False)

    step("make assemble plain — the ultimate cross-branch + dirty + "
         f"phantom .pc/ test. state at this "
         f"point: dev's accumulated edits in .build/ WT (preserved by "
         f"MODE=recover above); .pc/ snapshots from {ARC_BRANCH} series; "
         f"apps/mail/patches/series reflects {ORIGINAL_BRANCH}. Plain "
         f"assemble: stash captures dev edits → strip overlay symlinks "
         f"→ `quilt pop -af` (snapshot-based; handles phantom .pc/) → "
         f"`quilt push -a` (re-applies {ORIGINAL_BRANCH} series) → "
         f"link_overlay → write_baseline → stash pop (re-applies dev "
         f"edits onto new state). Contract: this scenario produces "
         f"a deterministic stash-pop "
         f"conflict — the dev's edits to Account.php / "
         f"DraftsService.php / MailManager.php were made at "
         f"{ARC_BRANCH} content but the post-walk WT is at "
         f"{ORIGINAL_BRANCH} content; those files diverge across the "
         f"branches (account-throttle and other ARC patches modify "
         f"them) and 3-way merge can't reconcile. assemble.sh exits 1 "
         f"with the platform's stash-pop-conflict diagnostic; make "
         f"wraps to rc=2. Test pins that exact outcome.")
    r = _run(["make", "assemble"], cwd=APP_ROOT,
             env=_make_env(), timeout=600)
    check(f"S{STEP_NUM:03d}{SUBSTEP_LETTER}-assemble-plain-rc",
          r.returncode == 2,
          f"rc={r.returncode}, expected 2 (assemble.sh's stash-pop "
          f"conflict path wrapped by make); stdout={r.stdout}; "
          f"stderr={r.stderr}")
    check(f"S{STEP_NUM:03d}{SUBSTEP_LETTER}-conflict-diagnostic",
          "conflicts in your working tree" in r.stderr,
          f"expected 'conflicts in your working tree' in stderr "
          f"(platform's conflict diagnostic at this step); "
          f"got stderr={r.stderr}")
    # Dev's natural recovery on this kind of conflict: throw it away
    # via MODE=discard. The cross-branch edits aren't worth preserving
    # in this test scenario; phase 8 needs a clean WT to proceed.
    # `make assemble MODE=discard` drops tracked-modified, untracked,
    # any stashes, and the pending tag — fully clean state.
    make_assemble("DISCARD", expect_rc=0)
    invariants()

    phase("PHASE 8 — deletions, overlay-deletion prompt, wrap-up "
          "(on ORIGINAL_BRANCH, no commits)")

    # We're on ORIGINAL_BRANCH after the round-trip. Saves in this
    # phase mutate working tree but commit_arc_progress's branch guard
    # prevents committing — the operator's branch tip stays put per
    # the spec. Cleanup's git reset --hard drops the dirt.

    step("delete AUTHORS.md (a non-patched upstream file)")
    delete_patched_file(DELETE_PATCHED_TARGET)
    invariants()

    step("make quilt — assign the deletion to hide-customer-noise "
         "(covers patched-file-deletion routing)")
    answers = [
        ("Add to:", "hide-customer-noise"),
        ("Apply?",  "y"),
    ]
    make_quilt_interactive(answers)
    invariants(run_pop_push=True)

    step(f"delete {DELETE_OVERLAY_SYMLINK_TARGET} overlay symlink "
         f"(leaf symlink — F1's fix to make this op actually work)")
    delete_overlay_symlink(DELETE_OVERLAY_SYMLINK_TARGET)
    invariants()

    step("make quilt — restore-from-upstream (covers OverlayRevert → "
         "restore branch). Per appinfo/info.xml has an upstream "
         "counterpart (apps/mail/upstream/appinfo/info.xml ships in "
         "Nextcloud's mail upstream), so extract emits OverlayRevert "
         "instead of OverlayDeletion. The dev-perspective prompt is "
         "'[r]estore from upstream  [s]kip:' — confirming removes the "
         "overlay override AND copies upstream's content into .build/ "
         "(otherwise the app would be missing its manifest, breaking "
         "Nextcloud at runtime).")
    # OverlayRevert's prompt is "[r]estore from upstream  [s]kip:"
    #. Wait for "[r]estore"
    # (distinctive — won't collide with NewFile/NewOverlayDir's
    # "[o]verlay" prompt or OverlayDeletion's "[d]elete" prompt).
    answers = [
        ("[r]estore", "r"),
        ("Apply?",    "y"),
    ]
    make_quilt_interactive(answers)
    invariants(run_pop_push=True)

    step(f"delete a second overlay symlink ({DELETE_OVERLAY_SYMLINK_TARGET_B})")
    delete_overlay_symlink(DELETE_OVERLAY_SYMLINK_TARGET_B)
    invariants()

    step("edit Account.php (the dev's working on something else "
         "alongside the still-pending deletion — sets up a save where "
         "one item is routed and another is skipped)")
    edit_patched_file(PATCHED_PHP_A, "step89-mixed")
    invariants()

    step("make quilt — mixed save: route the edit to accessibility, "
         "skip the deletion (the dev wants the edit out the door but "
         "isn't ready to commit losing the second leaf symlink yet). "
         "Exercises verify_and_finalize's restore-skipped on a deletion "
         "— the code path where the skipped item's path is ABSENT in "
         "pending. The fix at scripts/quilt_verify.py branches on "
         "existence in pending: present → git checkout (additions/"
         "mods); absent → git rm --ignore-unmatch (deletions).")
    # Items at this step's entry (alphabetical):
    #   lib/Account.php (Hunk modified — step89-mixed marker)
    #   <DELETE_OVERLAY_SYMLINK_TARGET_B> (OverlayDeletion vs baseline —
    #     the leaf symlink was deleted earlier; overlay/<path> still
    #     exists AND upstream has no counterpart so extract classifies
    #     as OverlayDeletion, not OverlayRevert).
    answers = [
        ("Add to:", "accessibility"),   # Account.php
        ("[d]elete", "s"),              # OverlayDeletion → skip
        ("Apply?",  "y"),
    ]
    make_quilt_interactive(answers)
    invariants()

    step("make quilt — skip-only on the still-pending deletion "
         "(restore-skipped above re-deleted the leaf symlink, so extract "
         "sees it again). Covers the OverlayDeletion → skip branch in "
         "skip-only mode (rc=3 → make rc=2).")
    answers = [
        ("[d]elete", "s"),
    ]
    # Skip-only → quilt rc=3 → make rc=2.
    make_quilt_interactive(answers,
                           expect_exitcode_substr="===EXITCODE:2===")
    invariants()

    step("drop a random file in apps/mail/patches/ (accidental violence)")
    random_file_in_apps_mail("patches/notes-on-this-patch.txt",
                             "step 90 notes\n")
    invariants()

    step("make quilt — final save: route the previously-skipped overlay "
         "deletion (covers the OverlayDeletion → delete branch a second "
         "time, this time on a restored-skipped item). Target is "
         "DELETE_OVERLAY_SYMLINK_TARGET_B (tests/phpunit.itsl.xml) which "
         "has no upstream counterpart per the constants comment block, "
         "so classification stays OverlayDeletion → [d]elete.")
    make_quilt_interactive([
        ("[d]elete", "d"),
        ("Apply?", "y"),
    ])
    invariants(run_pop_push=True)

    # ----------------------------------------------------------------
    # F1's coverage extensions — leaf-under-dir-tree-symlink delete +
    # dir-tree symlink delete. Order matters: leaf-under-dir-tree
    # runs first because it's invisible and leaves .build/ in a state
    # that doesn't poison the dir-tree delete. Dir-tree runs second
    # because its save likely raises IsADirectoryError (P3) and either
    # way leaves .build/src/itsl gone — running the leaf delete after
    # would fail at os.unlink with FileNotFoundError, since the parent
    # symlink no longer resolves.
    # ----------------------------------------------------------------

    step("delete a leaf file accessed via the .build/src/itsl dir-tree "
         "symlink — actually removes apps/mail/overlay/src/itsl/"
         "components/EnvelopeItsl.vue (the symlink follows). .build/'s "
         "git baseline sees nothing change (the dir-tree symlink target "
         "is unchanged; tracked entity unchanged). "
         "edits/deletions through dir-tree symlinks are out of scope "
         "for make quilt. Verifies invisibility.")
    os.unlink(os.path.join(BUILD, "src/itsl/components/EnvelopeItsl.vue"))
    invariants()

    step("make diff — should NOT show an EnvelopeItsl.vue change "
         "(invisibility property — the dir-tree symlink is the tracked "
         "entity, leaf changes are invisible to git diff baseline).")
    r = make_diff()
    check(f"S{STEP_NUM:03d}{SUBSTEP_LETTER}-envelope-vue-invisible",
          "EnvelopeItsl.vue" not in r.stdout,
          f"expected EnvelopeItsl.vue NOT in diff (invisibility — "
          f"writes through dir-tree symlinks land in overlay/, not "
          f"in .build/'s tracked content); stdout={r.stdout}")
    invariants()

    step("make quilt — should see no actionable items from the leaf-"
         "under-dir-tree deletion (it's invisible). The save attempt "
         "exits with rc=3 (no changes) → make rc=2.")
    make_quilt_interactive([], expect_exitcode_substr="===EXITCODE:2===")
    invariants()

    step("delete the entire src/itsl dir-tree symlink — less common but "
         "valid dev gesture: 'remove all of itsl from .build/'. extract "
         "emits OverlayDeletion('src/itsl') (upstream has no src/itsl/ "
         "counterpart, so this is the OverlayDeletion branch, not "
         "OverlayRevert). write_patches' deleted_overlay_files loop's "
         "three-shape handler sees overlay/src/itsl as a real directory "
         "and rmtrees it; no IsADirectoryError. An earlier shape that "
         "called os.path.isdir before unlinking would have followed the "
         "symlink and rmtree'd the target — that's why the order is "
         "islink → isdir → exists.")
    os.unlink(os.path.join(BUILD, "src/itsl"))
    invariants()

    step("make quilt — save the dir-tree-symlink deletion. Platform "
         "handles cleanly per three-shape deletion handler "
         "(islink/isdir/exists branches): the route prompt fires "
         "([d]elete because no upstream src/itsl), confirming runs "
         "shutil.rmtree on apps/mail/overlay/src/itsl. If a future "
         "regression breaks this, the test fails here, surfacing the "
         "platform finding and halting the chaos arc (Phase 8.5 below "
         "depends on .build/ being recoverable).")
    make_quilt_interactive([
        ("[d]elete", "d"),
        ("Apply?",  "y"),
    ])
    invariants(run_pop_push=True)

    phase("PHASE 8.5 — exit tests against fresh dirt + accumulated history")

    # Per the chaos arc's design: no mid-arc DISCARD/FORCE; reset
    # operations belong at end-of-arc. Phase 8 ends with .build/ at
    # all-applied + clean state (last save routed the OverlayDeletion).
    # For the DISCARD test below to be meaningful, drop fresh dirt now
    # — a scratch + a patched-file edit — and then exercise the reset
    # path against the maximally-accumulated history (every patch +
    # every commit phases 1-8 produced).

    step("drop fresh dirt (phase85-scratch.md + edit on Account.php) "
         "so the DISCARD below has something to discard against the "
         "post-arc history")
    drop_scratch_file(os.path.join(BUILD, "phase85-scratch.md"),
                      "exit-test scratch\n")
    edit_patched_file(PATCHED_PHP_A, "phase85-edit")
    invariants()

    step("make assemble plain succeeds on the fresh dirt — "
         "stash + walk + stash pop preserves the dev's edit across "
         "the walk against the fully-accumulated post-arc history. "
         "(Pre-Fix-10 this site asserted refuse-on-dirty; that contract "
         "is gone.)")
    make_assemble(None, expect_rc=0)
    # contract: dev's edit on Account.php (phase85-edit) +
    # the phase85-scratch.md should survive the walk via stash pop
    # and remain as uncommitted state against the new baseline.
    diff_r = _run(
        ["git", "-C", BUILD,
         "diff", "--quiet", "baseline"],
        env=GIT_ENV, check=False)
    check(f"S{STEP_NUM:03d}{SUBSTEP_LETTER}-edits-preserved-tracked",
          diff_r.returncode != 0,
          "contract: dev's tracked-file edit (phase85-edit on "
          "Account.php) should survive the walk via stash pop. Got: "
          "WT == baseline (tracked edit lost).")
    scratch_path = os.path.join(BUILD, "phase85-scratch.md")
    check(f"S{STEP_NUM:03d}{SUBSTEP_LETTER}-edits-preserved-scratch",
          os.path.exists(scratch_path),
          f"contract: dev's untracked scratch ({scratch_path}) "
          f"should survive the walk via stash pop's --include-untracked "
          f"machinery. Got: scratch missing post-walk.")
    invariants()

    step("make assemble MODE=discard — abandons the fresh dirt against "
         "fully-accumulated post-arc history")
    make_assemble("DISCARD", expect_rc=0)
    invariants(run_pop_push=True)

    step("make assemble plain post-DISCARD must succeed cleanly "
         "(clean tree post-revert; pop+push round-trip soundness "
         "asserted via run_pop_push=True invariants)")
    make_assemble(None, expect_rc=0)
    invariants(run_pop_push=True)

    # ----------------------------------------------------------------
    # Synthetic stash-pop-conflict coverage via in-place
    # patch sabotage. Within-branch counterpart to S089's cross-branch
    # stash-pop-conflict scenario. Mechanism: sabotage a patch file in
    # apps/mail/patches/ AFTER the previous baseline-write; plain
    # assemble's walk pop -af reverts via .pc/ snapshots (pre-sabotage),
    # push -a applies the sabotaged patch, post-walk WT diverges from
    # pre-walk WT at the sabotaged region; dev's stashed edit at the
    # SAME region produces a deterministic 3-way merge conflict at
    # stash pop. Pin rc=2 + stderr substring "conflicts in your
    # working tree" (contract). Cleanup: MODE=discard to clear
    # WT; revert the patch sabotage via git checkout; plain assemble
    # to bring .build/ back to original-patch state. Phase 9 then
    # sees clean state on both LEFT (post-arc plain) and RIGHT
    # (post-MODE=force) — symmetric, differential check passes.
    #
    # Target: responsiveness.patch (single hunk, single file, CSS
    # values on standalone lines — minimal moving parts; series
    # position 8 of 24, well clear of the top so subsequent patches
    # might or might not overlap, empirically verified to push
    # cleanly post-sabotage in arc-run-22). Pre-condition asserts
    # crash-fast if the apps/mail repo's overlay shape changed
    # under the test.
    # ----------------------------------------------------------------

    step("stash-pop-conflict setup: dev edits .build/<file> at responsiveness.patch's "
         "hunk region. Mirrors a real-world dev tweaking a CSS value "
         "in a file the patch system also modifies. Edit replaces "
         "'top: 2px;' (post-original-patch content) with 'top: 7px;' "
         "— same line, different value than the sabotage will produce.")
    _fix11d_target = "src/components/EnvelopeSkeleton.vue"
    _fix11d_patch = "responsiveness.patch"
    _fix11d_build_file = os.path.join(BUILD, _fix11d_target)
    with open(_fix11d_build_file, "r") as f:
        _fix11d_pre = f.read()
    _fix11d_new = _fix11d_pre.replace("top: 2px;", "top: 7px;", 1)
    if _fix11d_new == _fix11d_pre:
        raise RuntimeError(
            f"stash-pop-conflict fixture: 'top: 2px;' not found in "
            f"{_fix11d_target}. responsiveness.patch's hunk content "
            f"has changed under the test — repick a target or update "
            f"the substring.")
    with open(_fix11d_build_file, "w") as f:
        f.write(_fix11d_new)
    invariants()

    step("stash-pop-conflict sabotage: rewrite responsiveness.patch's '+top: 2px;' "
         "line to '+top: 99px;'. The .pc/ snapshot is from the prior "
         "(pre-sabotage) push, so pop -af reverts to upstream cleanly; "
         "push -a then applies the sabotaged hunk and produces the "
         "divergent post-walk content needed for the conflict.")
    _fix11d_patch_path = os.path.join(APP_ROOT, "patches", _fix11d_patch)
    with open(_fix11d_patch_path, "r") as f:
        _fix11d_orig_patch = f.read()
    _fix11d_sabotaged = _fix11d_orig_patch.replace(
        "+\t\t\t\ttop: 2px;", "+\t\t\t\ttop: 99px;", 1)
    if _fix11d_sabotaged == _fix11d_orig_patch:
        raise RuntimeError(
            f"stash-pop-conflict fixture: '+\\t\\t\\t\\ttop: 2px;' not found in "
            f"{_fix11d_patch}. responsiveness.patch's hunk shape has "
            f"changed under the test — update the sabotage substring "
            f"to match.")
    with open(_fix11d_patch_path, "w") as f:
        f.write(_fix11d_sabotaged)
    invariants()

    step("plain assemble — produces deterministic stash-pop conflict. "
         "Stash captures dev's 'top: 7px;' edit (against baseline's "
         "pre-sabotage 'top: 2px;'). Walk's pop -af reverts via .pc/ "
         "→ upstream state. Walk's push -a applies sabotaged patch → "
         "'top: 99px;'. write_baseline updates baseline. Stash pop's "
         "3-way merge: base = pre-walk (top: 2px), theirs = stash "
         "(top: 7px), ours = post-walk (top: 99px). Both diverge "
         "from base at the same line → conflict markers in WT. "
         "assemble.sh exits 1 with the platform's conflict "
         "diagnostic; make wraps to rc=2. Mirror of S089's pinned "
         "contract per (rc=2 + stderr substring 'conflicts in "
         "your working tree').")
    r = _run(["make", "assemble"], cwd=APP_ROOT,
             env=_make_env(), timeout=600)
    check(f"S{STEP_NUM:03d}{SUBSTEP_LETTER}-assemble-plain-rc",
          r.returncode == 2,
          f"rc={r.returncode}, expected 2 (synthetic stash-pop "
          f"conflict at this step); stdout={r.stdout}; "
          f"stderr={r.stderr}")
    check(f"S{STEP_NUM:03d}{SUBSTEP_LETTER}-conflict-diagnostic",
          "conflicts in your working tree" in r.stderr,
          f"expected 'conflicts in your working tree' in stderr "
          f"(platform's conflict diagnostic at this step); "
          f"got stderr={r.stderr}")
    # Recovery: MODE=discard clears WT (including conflict markers) +
    # walks back to a consistent state. Same shape as S089's
    # recovery; on-conflict cleanup already drops the stash
    # entry (verified empirically), so DISCARD's `git stash clear` is
    # a defense-in-depth no-op here.
    make_assemble("DISCARD", expect_rc=0)
    invariants()

    step("revert the patch sabotage (apps/mail/patches/responsiveness.patch "
         "back to its committed content). Necessary for Phase 9's "
         "differential equivalence to land on a non-sabotaged state. "
         "Without this revert, both LEFT (post-arc) and RIGHT "
         "(post-MODE=force) would produce sabotaged content (same on "
         "both sides → equivalence holds), but the chaos arc would "
         "exit with synthetic test-injected sabotage in its end "
         "state, which is doctrine-smelly. Explicit teardown is "
         "cleaner.")
    _run(["git", "checkout", "patches/" + _fix11d_patch],
         cwd=APP_ROOT, env=GIT_ENV, check=True)
    invariants()

    step("plain assemble — clean walk on restored patches. The .pc/ "
         "snapshots are from the sabotaged push above; pop -af still "
         "reverts to upstream cleanly (snapshot-based). push -a "
         "applies the now-original patch → original 'top: 2px;' "
         "content. write_baseline captures the cleaned state. "
         "INV-08 verifies pop+push round-trip soundness, confirming "
         "the sabotage's effects are fully cleared.")
    make_assemble(None, expect_rc=0)
    invariants(run_pop_push=True)


# ---------------------------------------------------------------------------
# Driver
# ---------------------------------------------------------------------------

def summarize(state):
    """Print final results."""
    bar = "=" * 70
    print(f"\n{bar}")
    print(f"State-integrity test summary")
    print(f"  Steps run: {STEP_NUM}")
    print(f"  Checks: {PASSED} passed, {FAILED} failed")
    print(f"  Suite-start: branch={state['mail_branch']}, "
          f"commit={state['mail_commit'][:8]}, "
          f"stashes={state['stash_count']}, "
          f"build_tags={state['build_tags']}")
    print(bar)


def main():
    print("=" * 70)
    print("state-integrity test for make quilt / make assemble / make diff")
    print("")
    print("=" * 70)

    # Suite-start hygiene: kill any leftover instance of our tmux
    # session from a prior run that died before its in-Python cleanup
    # ran (SIGKILL via `timeout`, OOM, parent shell closed, etc.).
    # The tmux server is a daemon, survives the parent python's
    # death, and its session keeps docker + quilt_v2 + .build/.lock
    # flocked. tmux_kill targets only our named session, leaving any
    # other tmux work the operator has alone.
    tmux_kill()

    state = capture_suite_start()
    setup_arc_branch(state)

    try:
        chaos_arc()
        differential_check()
    except StateIntegrityFailure as e:
        # The arc's own assertion failed — this is the test doing its
        # job. Print the chain and let cleanup run. Differential is
        # skipped because it's only meaningful after a clean arc.
        print(f"\nFATAL: state-integrity check failed: {e}", file=sys.stderr)
    except Exception as e:
        # Anything else is a test-harness bug, not a platform finding.
        # Print full traceback for diagnosis. Increment FAILED so the
        # final sys.exit reflects the failure — without this a fixture
        # mismatch or unhandled exception silently exits 0 and the
        # wrapper's post-checks (which only see cleanup's branch+commit
        # restoration) report green on a crashed run.
        global FAILED
        FAILED += 1
        print(f"\nFATAL: unexpected error in chaos arc: {e}", file=sys.stderr)
        import traceback
        traceback.print_exc()
    finally:
        try:
            cleanup_to_suite_start(state)
        except Exception as e:
            print(f"\nFATAL: cleanup raised: {e}", file=sys.stderr)
            import traceback
            traceback.print_exc()
            print(
                "\nWORST-CASE RECOVERY: re-clone hubs-apps from origin.",
                file=sys.stderr)
        tmux_kill()

    summarize(state)
    sys.exit(0 if FAILED == 0 else 1)


if __name__ == "__main__":
    main()
