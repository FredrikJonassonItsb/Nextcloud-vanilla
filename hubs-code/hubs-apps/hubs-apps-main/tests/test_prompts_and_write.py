#!/usr/bin/env python3
"""Unit tests for prompt functions and write loop specifics.

Test IDs: P-PATCH-01..12, P-YN-01..06, W-WRITE-01..08

The W-WRITE-09 (backup created before modifications) and W-WRITE-10
(restore_from_backup) tests were removed: those mechanisms were deleted
by the .build/.git-as-storage redesign. Crash rollback for `.build/`
now uses the make-quilt-pending tag, recovered by the user's explicit
`make assemble MODE=recover` gesture. Parent-repo state (patches/ +
overlay/) is the user's to clean up via normal git tools after a
crash; recovery is `.build/`-only by design. There is no backup dict
to assert against.

The W-WRITE tests reflect the rebuilt write_patches shape:
- write_patches returns None on success and raises RuntimeError on any
  failure (or KeyboardInterrupt at the conflict pause). There is no
  partial-success path — patch -p1 --merge replaces git apply so context
  conflicts surface as on-disk markers instead of being silently
  absorbed into the next refresh.
- Bytes for binary/new/overlay come from `git show make-quilt-pending:<path>`
  rather than a saved_files dict — tests set up a real `.build/.git` with
  baseline + pending tags so `git show` resolves.
- Text hunks go through `patch -p1 --merge` once per work-bearing patch,
  with a multi-file mini-diff. Conflicts produce <<<<<<< markers in the
  working tree AND pause for in-process resolution (Enter to continue
  with quilt refresh, Ctrl-C to abort cleanly). W-WRITE-03 covers the
  conflict path; W-WRITE-06 covers the multi-hunk single-invocation
  path.
- W-WRITE-04 (refresh failure) and W-WRITE-05 (quilt new failure) test
  the RuntimeError raise on those fatal paths.

Invocation (from platform root):
  APP_ROOT="$PWD/apps/mail" bash scripts/host/dc-run.sh python3 /platform/tests/test_prompts_and_write.py
"""

import os
import shutil
import subprocess
import sys
import tempfile
from io import StringIO
from unittest.mock import patch as mock_patch, MagicMock

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "scripts"))

from quilt_present import _ask_patch, _ask_yn, PresentResult
from quilt_write import write_patches

passed = 0
failed = 0


def check(test_id, condition, detail=""):
    global passed, failed
    if condition:
        print(f"  PASS {test_id}")
        passed += 1
    else:
        print(f"  FAIL {test_id}: {detail}")
        failed += 1


# ===========================================================================
# Test fixture: build a real `.build/.git` with baseline + pending tags
# ===========================================================================

GIT_ENV = {
    **os.environ,
    "GIT_CONFIG_COUNT": "1",
    "GIT_CONFIG_KEY_0": "safe.directory",
    "GIT_CONFIG_VALUE_0": "*",
    "GIT_AUTHOR_NAME": "test", "GIT_AUTHOR_EMAIL": "test@local",
    "GIT_COMMITTER_NAME": "test", "GIT_COMMITTER_EMAIL": "test@local",
}


def _setup_repo(tmpdir, build_files=None, pending_files=None, series=""):
    """Build a minimal app repo at tmpdir.

    Creates patches/, overlay/, .build/ + initialises .build/.git with:
    - "baseline" tag at a commit holding `build_files` content.
    - "make-quilt-pending" tag at a commit holding `pending_files` content
      (parent: baseline). Skipped if pending_files is None.

    Args:
        build_files: dict[relpath -> bytes] for the baseline commit.
        pending_files: dict[relpath -> bytes] for the pending commit.
                       None = no pending tag (test doesn't need one).
        series: contents of patches/series file.
    """
    patches_dir = os.path.join(tmpdir, "patches")
    overlay_dir = os.path.join(tmpdir, "overlay")
    build_dir = os.path.join(tmpdir, ".build")
    os.makedirs(patches_dir)
    os.makedirs(overlay_dir)
    os.makedirs(build_dir)
    with open(os.path.join(patches_dir, "series"), "w") as f:
        f.write(series)

    # Baseline commit.
    if build_files:
        for rel, data in build_files.items():
            full = os.path.join(build_dir, rel)
            os.makedirs(os.path.dirname(full), exist_ok=True)
            with open(full, "wb") as f:
                f.write(data)
    subprocess.run(["git", "init", "-q"], cwd=build_dir, env=GIT_ENV, check=True)
    subprocess.run(["git", "add", "-A"], cwd=build_dir, env=GIT_ENV, check=True)
    subprocess.run(["git", "commit", "-q", "--allow-empty", "-m", "baseline"],
                   cwd=build_dir, env=GIT_ENV, check=True)
    subprocess.run(["git", "tag", "baseline"], cwd=build_dir, env=GIT_ENV, check=True)

    # Optional pending commit on top of baseline.
    if pending_files is not None:
        for rel, data in pending_files.items():
            full = os.path.join(build_dir, rel)
            os.makedirs(os.path.dirname(full), exist_ok=True)
            with open(full, "wb") as f:
                f.write(data)
        subprocess.run(["git", "add", "-A"], cwd=build_dir, env=GIT_ENV, check=True)
        subprocess.run(
            ["git", "commit", "-q", "--allow-empty",
             "-m", "make-quilt-pending"],
            cwd=build_dir, env=GIT_ENV, check=True)
        subprocess.run(["git", "tag", "make-quilt-pending"],
                       cwd=build_dir, env=GIT_ENV, check=True)

    return tmpdir, patches_dir, overlay_dir, build_dir


# ===========================================================================
# P-PATCH: _ask_patch tests
# ===========================================================================
print("=== P-PATCH: Patch selection prompt ===")

# Suppress print output from _ask_patch during tests
_devnull = StringIO()


# P-PATCH-01: Number input returns patch name
with mock_patch("builtins.input", return_value="1"), \
     mock_patch("sys.stdout", _devnull):
    choice, _ = _ask_patch(["fix-login", "feat-search"])
check("P-PATCH-01", choice == "fix-login", f"got {choice!r}")

# P-PATCH-02: 'n' returns "new"
with mock_patch("builtins.input", return_value="n"), \
     mock_patch("sys.stdout", _devnull):
    choice, _ = _ask_patch(["fix"])
check("P-PATCH-02", choice == "new", f"got {choice!r}")

# P-PATCH-03: 's' returns "skip"
with mock_patch("builtins.input", return_value="s"), \
     mock_patch("sys.stdout", _devnull):
    choice, _ = _ask_patch(["fix"])
check("P-PATCH-03", choice == "skip", f"got {choice!r}")

# P-PATCH-04: 'w' returns "done"
with mock_patch("builtins.input", return_value="w"), \
     mock_patch("sys.stdout", _devnull):
    choice, _ = _ask_patch(["fix"])
check("P-PATCH-04", choice == "done", f"got {choice!r}")

# P-PATCH-05: 'q' returns "abort"
with mock_patch("builtins.input", return_value="q"), \
     mock_patch("sys.stdout", _devnull):
    choice, _ = _ask_patch(["fix"])
check("P-PATCH-05", choice == "abort", f"got {choice!r}")

# P-PATCH-06: '!' with last_patch returns "all_remaining"
with mock_patch("builtins.input", return_value="!"), \
     mock_patch("sys.stdout", _devnull):
    choice, _ = _ask_patch(["fix"], last_patch="fix")
check("P-PATCH-06", choice == "all_remaining", f"got {choice!r}")

# P-PATCH-07: '!' without last_patch re-prompts (invalid), then accepts 's'
with mock_patch("builtins.input", side_effect=["!", "s"]), \
     mock_patch("sys.stdout", _devnull):
    choice, _ = _ask_patch(["fix"], last_patch=None)
check("P-PATCH-07", choice == "skip", f"got {choice!r}")

# P-PATCH-08: Invalid number (out of range) re-prompts
with mock_patch("builtins.input", side_effect=["99", "1"]), \
     mock_patch("sys.stdout", _devnull):
    choice, _ = _ask_patch(["fix"])
check("P-PATCH-08", choice == "fix", f"got {choice!r}")

# P-PATCH-09: Patch name match returns name
with mock_patch("builtins.input", return_value="fix"), \
     mock_patch("sys.stdout", _devnull):
    choice, _ = _ask_patch(["fix", "feat"])
check("P-PATCH-09", choice == "fix", f"got {choice!r}")

# P-PATCH-10: '?' shows help, then re-prompts
with mock_patch("builtins.input", side_effect=["?", "s"]) as mock_inp, \
     mock_patch("sys.stdout", new_callable=StringIO) as mock_out:
    choice, lines = _ask_patch(["fix"])
check("P-PATCH-10a", choice == "skip", f"got {choice!r}")
# Help text should include the word "help"
check("P-PATCH-10b", "help" in mock_out.getvalue().lower(),
      "help text not found in output")

# P-PATCH-11: touched annotation shown for matching patches
with mock_patch("builtins.input", return_value="1"), \
     mock_patch("sys.stdout", new_callable=StringIO) as mock_out:
    choice, _ = _ask_patch(["fix"], touched=["fix"])
check("P-PATCH-11", "(this file)" in mock_out.getvalue(),
      f"'(this file)' not in output")

# P-PATCH-12: Empty input re-prompts
with mock_patch("builtins.input", side_effect=["", "s"]), \
     mock_patch("sys.stdout", _devnull):
    choice, _ = _ask_patch(["fix"])
check("P-PATCH-12", choice == "skip", f"got {choice!r}")


# ===========================================================================
# P-YN: Yes/no prompt tests
# ===========================================================================
print("\n=== P-YN: Yes/no prompt ===")

# P-YN-01: 'y' returns True
with mock_patch("builtins.input", return_value="y"):
    result = _ask_yn("Test?")
check("P-YN-01", result is True, f"got {result!r}")

# P-YN-02: 'n' returns False
with mock_patch("builtins.input", return_value="n"):
    result = _ask_yn("Test?")
check("P-YN-02", result is False, f"got {result!r}")

# P-YN-03: '' with default=True returns True
with mock_patch("builtins.input", return_value=""):
    result = _ask_yn("Test?", default=True)
check("P-YN-03", result is True, f"got {result!r}")

# P-YN-04: '' with default=False returns False
with mock_patch("builtins.input", return_value=""):
    result = _ask_yn("Test?", default=False)
check("P-YN-04", result is False, f"got {result!r}")

# P-YN-05: 'yes' returns True
with mock_patch("builtins.input", return_value="yes"):
    result = _ask_yn("Test?")
check("P-YN-05", result is True, f"got {result!r}")

# P-YN-06: EOFError returns None
with mock_patch("builtins.input", side_effect=EOFError):
    result = _ask_yn("Test?")
check("P-YN-06", result is None, f"got {result!r}")


# ===========================================================================
# W-WRITE: Write loop specifics
# ===========================================================================
print("\n=== W-WRITE: Write loop specifics ===")


# W-WRITE-01: Empty work — write_patches succeeds without error, does
# not call quilt push (no patches in series), does not call quilt new
# (no work in result for any new patch).
tmpdir = tempfile.mkdtemp(prefix="test_pw_")
try:
    repo, patches_dir, overlay_dir, build_dir = _setup_repo(tmpdir, series="")

    result = PresentResult()
    result.skipped.append(MagicMock(file="some/file.js"))

    run_calls = []

    def tracking_run(cmd, cwd=None, env=None, check=False, input_data=None):
        run_calls.append(cmd)
        return MagicMock(returncode=0, stdout="", stderr="")

    with mock_patch("quilt_write._run", side_effect=tracking_run):
        write_patches(result, repo)

    # No quilt push (empty series), no quilt new (no work for any new patch).
    quilt_calls = [c for c in run_calls if c and c[0] == "quilt"
                   and c[1] in ("push", "new")]
    check("W-WRITE-01", len(quilt_calls) == 0,
          f"expected 0 push/new calls, got {len(quilt_calls)}: {quilt_calls}")
finally:
    shutil.rmtree(tmpdir, ignore_errors=True)


# W-WRITE-02: Binary file written from pending commit (via git show).
tmpdir = tempfile.mkdtemp(prefix="test_pw_")
try:
    binary_content = b"\x89PNG\r\n\x1a\n\x00\x00binary_data"
    repo, patches_dir, overlay_dir, build_dir = _setup_repo(
        tmpdir,
        # baseline has the file too; pending has user's edit.
        build_files={"img/logo.png": b"\x89PNG\r\n\x1a\n\x00\x00original"},
        pending_files={"img/logo.png": binary_content},
        series="")

    result = PresentResult()
    # Binary hunk: empty content signals binary path; bytes come from pending.
    result.add_hunk("fix-icon",
                    "diff --git a/img/logo.png b/img/logo.png\n--- a/img/logo.png\n+++ b/img/logo.png",
                    "")  # empty content = binary

    def mock_run_quilt(cmd, cwd=None, env=None, check=False, input_data=None):
        # All quilt subcommands succeed; no patches in series so push won't run.
        return MagicMock(returncode=0, stdout="", stderr="")

    with mock_patch("quilt_write._run", side_effect=mock_run_quilt):
        write_patches(result, repo)

    written_path = os.path.join(build_dir, "img/logo.png")
    if os.path.exists(written_path):
        with open(written_path, "rb") as f:
            actual = f.read()
        check("W-WRITE-02", actual == binary_content,
              f"binary content mismatch: {len(actual)} vs {len(binary_content)} bytes")
    else:
        check("W-WRITE-02", False, "binary file not written")
finally:
    shutil.rmtree(tmpdir, ignore_errors=True)


# W-WRITE-03: patch --merge conflict pauses for in-process resolution.
# Pause-and-continue UX. No silent absorb:
# the loop calls input() to wait for the dev to resolve markers and
# press Enter, or Ctrl-C to abort.
#
# Two sub-cases tested:
#   (a) stdin closed (no resolver present, e.g. piped automation) →
#       RuntimeError with diagnostic naming the patch.
#   (b) Enter received → loop continues with quilt refresh against
#       the resolved tree; write_patches returns None.

# (a) No resolver: stdin closed → EOFError → RuntimeError.
tmpdir = tempfile.mkdtemp(prefix="test_pw_")
try:
    repo, patches_dir, overlay_dir, build_dir = _setup_repo(
        tmpdir,
        build_files={"f.js": b"old\n"},
        pending_files={"f.js": b"new\n"},
        series="")

    result = PresentResult()
    result.add_hunk("mypatch",
                    "diff --git a/f.js b/f.js\n--- a/f.js\n+++ b/f.js",
                    "@@ -1 +1 @@\n-old\n+new")

    # Real `patch -p1 --merge` writes <<<<<<< / ======= / >>>>>>>
    # markers to the target file when it returns rc=1 with NOT-MERGED
    # hunks. Post-Fix-8 (`f1bbc78`), `_resolve_or_raise` scans the
    # candidate files for marker text BEFORE deciding whether to
    # pause-and-continue (markers present) or hard-fail (markers
    # absent → "no conflict markers written" RuntimeError indicating
    # malformed diff / permission error / target missing). The mock
    # must therefore simulate the marker-write side effect; without
    # it, the test would exercise the wrong branch — a no-marker
    # hard-fail rather than the pause-and-continue UX the test
    # exists to verify. Marker content matches what
    # _strip_conflict_markers_in_build (chaos arc helper) and the
    # platform's diagnostic both look for: lines starting with
    # `<<<<<<<`, `=======`, `>>>>>>>`.
    def mock_run_patch_conflict(cmd, cwd=None, env=None, check=False, input_data=None):
        if cmd[0] == "patch":
            with open(os.path.join(cwd, "f.js"), "w") as f:
                f.write("<<<<<<< current\nold\n=======\nnew\n>>>>>>> incoming\n")
            return MagicMock(returncode=1,
                             stdout="patching file f.js\n",
                             stderr="Hunk #1 NOT MERGED at 1-3.\n")
        return MagicMock(returncode=0, stdout="", stderr="")

    raised = False
    raised_msg = ""
    with mock_patch("quilt_write._run", side_effect=mock_run_patch_conflict), \
         mock_patch("builtins.input", side_effect=EOFError):
        try:
            write_patches(result, repo)
        except RuntimeError as e:
            raised = True
            raised_msg = str(e)

    check("W-WRITE-03a", raised,
          "expected RuntimeError when stdin is closed at conflict prompt")
    check("W-WRITE-03b",
          "stdin closed" in raised_msg and "mypatch" in raised_msg,
          f"error should name the EOF and patch: {raised_msg!r}")
finally:
    shutil.rmtree(tmpdir, ignore_errors=True)

# (b) Resolver present: input() returns "" (Enter), loop continues
# through quilt refresh successfully → write_patches returns None.
tmpdir = tempfile.mkdtemp(prefix="test_pw_")
try:
    repo, patches_dir, overlay_dir, build_dir = _setup_repo(
        tmpdir,
        build_files={"f.js": b"old\n"},
        pending_files={"f.js": b"new\n"},
        series="")

    result = PresentResult()
    result.add_hunk("mypatch",
                    "diff --git a/f.js b/f.js\n--- a/f.js\n+++ b/f.js",
                    "@@ -1 +1 @@\n-old\n+new")

    # First call to `patch` returns conflict (and writes markers, per
    # the mock_run_patch_conflict rationale above); everything else
    # (quilt add / push / refresh) succeeds. After input() returns,
    # the loop falls through to quilt refresh, which captures
    # whatever the resolved tree looks like.
    def mock_run_resolved(cmd, cwd=None, env=None, check=False, input_data=None):
        if cmd[0] == "patch":
            with open(os.path.join(cwd, "f.js"), "w") as f:
                f.write("<<<<<<< current\nold\n=======\nnew\n>>>>>>> incoming\n")
            return MagicMock(returncode=1,
                             stdout="patching file f.js\n",
                             stderr="Hunk #1 NOT MERGED at 1-3.\n")
        return MagicMock(returncode=0, stdout="", stderr="")

    raised = False
    with mock_patch("quilt_write._run", side_effect=mock_run_resolved), \
         mock_patch("builtins.input", return_value=""):
        try:
            write_patches(result, repo)
        except Exception as e:
            raised = True
            print(f"    UNEXPECTED: {type(e).__name__}: {e}")

    check("W-WRITE-03c-resume", not raised,
          "Enter at conflict prompt should resume into refresh, not raise")
finally:
    shutil.rmtree(tmpdir, ignore_errors=True)


# W-WRITE-04: quilt refresh failure now FATAL (raises RuntimeError) —
# refresh failure means the patch didn't save. The previous "soft fail
# into failed list" let the run continue under the illusion that the
# patch saved; raising forces recovery on retry.
tmpdir = tempfile.mkdtemp(prefix="test_pw_")
try:
    repo, patches_dir, overlay_dir, build_dir = _setup_repo(
        tmpdir,
        build_files={"f.js": b"old\n"},
        pending_files={"f.js": b"new\n"},
        series="")

    result = PresentResult()
    result.add_hunk("mypatch",
                    "diff --git a/f.js b/f.js\n--- a/f.js\n+++ b/f.js",
                    "@@ -1 +1 @@\n-old\n+new")

    def mock_run_refresh_fail(cmd, cwd=None, env=None, check=False, input_data=None):
        if cmd[0] == "quilt" and cmd[1] == "refresh":
            return MagicMock(returncode=1, stdout="", stderr="refresh failed")
        return MagicMock(returncode=0, stdout="", stderr="")

    raised = False
    with mock_patch("quilt_write._run", side_effect=mock_run_refresh_fail):
        try:
            write_patches(result, repo)
        except RuntimeError as e:
            raised = "quilt refresh failed" in str(e)
    check("W-WRITE-04", raised,
          "expected RuntimeError mentioning 'quilt refresh failed'")
finally:
    shutil.rmtree(tmpdir, ignore_errors=True)


# W-WRITE-05: quilt new failure now FATAL (raises RuntimeError) — not
# captured in failed list. After the redesign: if the user said
# `make quilt PATCH=newp NEW=1` and quilt new fails, the entire intent
# is broken. Fail loudly so recovery handles it on retry.
tmpdir = tempfile.mkdtemp(prefix="test_pw_")
try:
    repo, patches_dir, overlay_dir, build_dir = _setup_repo(
        tmpdir,
        build_files={"f.js": b"old\n"},
        pending_files={"f.js": b"new\n"},
        series="")

    result = PresentResult()
    result.add_hunk("newpatch",
                    "diff --git a/f.js b/f.js\n--- a/f.js\n+++ b/f.js",
                    "@@ -1 +1 @@\n-old\n+new")

    def mock_run_new_fail(cmd, cwd=None, env=None, check=False, input_data=None):
        if cmd[0] == "quilt" and cmd[1] == "new":
            return MagicMock(returncode=1, stdout="", stderr="patch already exists")
        return MagicMock(returncode=0, stdout="", stderr="")

    raised = False
    raised_msg = ""
    with mock_patch("quilt_write._run", side_effect=mock_run_new_fail):
        try:
            write_patches(result, repo)
        except RuntimeError as e:
            raised = True
            raised_msg = str(e)

    check("W-WRITE-05a", raised, "expected RuntimeError on quilt new failure")
    check("W-WRITE-05b", "quilt new" in raised_msg,
          f"error should mention 'quilt new': {raised_msg!r}")
finally:
    shutil.rmtree(tmpdir, ignore_errors=True)


# W-WRITE-06: Multi-file hunks for one patch all flow through a single
# `patch -p1 --merge` invocation. The mini-diff carries all referenced
# files (one section per file).
tmpdir = tempfile.mkdtemp(prefix="test_pw_")
try:
    repo, patches_dir, overlay_dir, build_dir = _setup_repo(
        tmpdir,
        build_files={"a.js": b"old1\n", "b.js": b"old2\n"},
        pending_files={"a.js": b"new1\n", "b.js": b"new2\n"},
        series="")

    result = PresentResult()
    result.add_hunk("mypatch",
                    "diff --git a/a.js b/a.js\n--- a/a.js\n+++ b/a.js",
                    "@@ -1 +1 @@\n-old1\n+new1")
    result.add_hunk("mypatch",
                    "diff --git a/b.js b/b.js\n--- a/b.js\n+++ b/b.js",
                    "@@ -1 +1 @@\n-old2\n+new2")

    patch_calls = []

    def mock_run_multi(cmd, cwd=None, env=None, check=False, input_data=None):
        if cmd[0] == "patch":
            patch_calls.append(input_data)
        return MagicMock(returncode=0, stdout="", stderr="")

    with mock_patch("quilt_write._run", side_effect=mock_run_multi):
        write_patches(result, repo)

    check("W-WRITE-06a", len(patch_calls) == 1,
          f"expected 1 patch --merge invocation, got {len(patch_calls)}")
    if patch_calls:
        mini_diff = patch_calls[0]
        check("W-WRITE-06b",
              "a/a.js" in mini_diff and "a/b.js" in mini_diff,
              f"both files should appear in the mini-diff: "
              f"{mini_diff[:200]!r}")
finally:
    shutil.rmtree(tmpdir, ignore_errors=True)


# W-WRITE-07: Overlay-copy bytes written from pending commit (via git show).
tmpdir = tempfile.mkdtemp(prefix="test_pw_")
try:
    overlay_content = b"<?php // overlay content"
    repo, patches_dir, overlay_dir, build_dir = _setup_repo(
        tmpdir,
        build_files={"lib/Custom.php": b"<?php // original"},
        pending_files={"lib/Custom.php": overlay_content},
        series="")

    result = PresentResult()
    result.overlay_copies = ["lib/Custom.php"]

    with mock_patch("quilt_write._run",
                    return_value=MagicMock(returncode=0, stdout="", stderr="")):
        write_patches(result, repo)

    dst = os.path.join(overlay_dir, "lib/Custom.php")
    if os.path.exists(dst):
        with open(dst, "rb") as f:
            actual = f.read()
        check("W-WRITE-07", actual == overlay_content,
              f"content mismatch: {actual!r}")
    else:
        check("W-WRITE-07", False, "overlay file not written")
finally:
    shutil.rmtree(tmpdir, ignore_errors=True)


# W-WRITE-08: New overlay-file bytes written from pending commit.
tmpdir = tempfile.mkdtemp(prefix="test_pw_")
try:
    new_content = b"<?php // brand new file"
    repo, patches_dir, overlay_dir, build_dir = _setup_repo(
        tmpdir,
        # File doesn't exist in baseline; appears only in pending.
        build_files={"lib/Other.php": b"placeholder"},  # need any baseline content
        pending_files={"lib/New.php": new_content,
                       "lib/Other.php": b"placeholder"},
        series="")

    result = PresentResult()
    result.new_overlay_files = ["lib/New.php"]

    with mock_patch("quilt_write._run",
                    return_value=MagicMock(returncode=0, stdout="", stderr="")):
        write_patches(result, repo)

    dst = os.path.join(overlay_dir, "lib/New.php")
    if os.path.exists(dst):
        with open(dst, "rb") as f:
            actual = f.read()
        check("W-WRITE-08", actual == new_content,
              f"content mismatch: {actual!r}")
    else:
        check("W-WRITE-08", False, "new overlay file not written")
finally:
    shutil.rmtree(tmpdir, ignore_errors=True)


# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------
print(f"\n{passed} passed, {failed} failed out of {passed + failed}")
sys.exit(1 if failed else 0)
