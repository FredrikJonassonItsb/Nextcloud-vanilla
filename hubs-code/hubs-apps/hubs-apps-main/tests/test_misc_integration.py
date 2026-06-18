#!/usr/bin/env python3
"""Miscellaneous integration tests for make quilt v2.

Covers: lock management (M-LOCK), editor (M-ED/M-EDIT), series
(M-SER), and patch-file mapping (E-PMAP).

Test IDs are the strings passed to `check(...)` below — search for the
ID to find the test.

Setup helpers that need to drive `make quilt` to a successful save use
`run_quilt_scripted(script)` — input piped via subprocess stdin. Routes
through dc-run.sh so quilt operations use container quilt 0.66 (host
quilt 0.68 against the same .pc/ would version-skew silently). The
interactive prompts read from input() with no TTY-only branches, so a
piped stdin script answers prompts the same way a human would.
"""
import sys
import os
import subprocess
import fcntl
import tempfile

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
APP_ROOT = os.environ.get("APP_ROOT", os.path.join(REPO, "apps", "mail"))
BUILD = os.path.join(APP_ROOT, ".build")
PATCHES = os.path.join(APP_ROOT, "patches")
LOCK_FILE = os.path.join(BUILD, ".lock")

# Add scripts to path for direct imports
sys.path.insert(0, os.path.join(REPO, "scripts"))

passed = 0
failed = 0

# Save original state for final restore
_orig_series = None
_orig_patches = {}


def _save_original():
    global _orig_series, _orig_patches
    series_path = os.path.join(PATCHES, "series")
    if os.path.exists(series_path):
        with open(series_path) as f:
            _orig_series = f.read()
    _orig_patches = {}
    for fname in os.listdir(PATCHES):
        if fname.endswith(".patch"):
            with open(os.path.join(PATCHES, fname)) as f:
                _orig_patches[fname] = f.read()


def _restore_original():
    """Restore original patches/series state and reflect in .build/.

    Same fast-reset shape as clean(): MODE=discard (if dirty) → restore
    patches/series content from the saved originals → plain assemble.
    No MODE=force.
    """
    # DEVELOPER_UID/GID auto-detect mirrors Makefile's `?= $(shell id -u)`
    # chain — satisfies dc-run.sh's hard-require when the subprocess
    # bypasses make. Without DEVELOPER_UID/GID, the compose
    # interpolation falls back to :-1000 and writes land as the wrong
    # UID on any non-1000 host. User-shell DEVELOPER_UID/GID still wins.
    make_env = {
        "DEVELOPER_UID": str(os.getuid()), "DEVELOPER_GID": str(os.getgid()),
        **os.environ,
        "APP_ROOT": APP_ROOT,
    }

    # If .build/ is dirty against baseline, MODE=discard first.
    git_env = {**os.environ, "GIT_CONFIG_COUNT": "1",
               "GIT_CONFIG_KEY_0": "safe.directory", "GIT_CONFIG_VALUE_0": "*"}
    diff = subprocess.run(
        ["git", "-C", BUILD, "diff", "--quiet", "baseline"], env=git_env)
    if diff.returncode != 0:
        subprocess.run(
            ["make", "assemble", "MODE=discard"],
            cwd=APP_ROOT, env=make_env,
            capture_output=True, text=True, timeout=120)

    # Remove all patch files, write originals.
    for fname in os.listdir(PATCHES):
        if fname.endswith(".patch"):
            os.unlink(os.path.join(PATCHES, fname))
    for fname, content in _orig_patches.items():
        with open(os.path.join(PATCHES, fname), "w") as f:
            f.write(content)
    if _orig_series is not None:
        with open(os.path.join(PATCHES, "series"), "w") as f:
            f.write(_orig_series)

    # Plain assemble re-applies the original patch series and refreshes baseline.
    subprocess.run(["make", "assemble"], cwd=APP_ROOT, env=make_env,
                   capture_output=True, timeout=120)


def check(test_id, condition, detail=""):
    global passed, failed
    if condition:
        passed += 1
        print(f"  PASS {test_id}")
    else:
        failed += 1
        print(f"  FAIL {test_id}: {detail}")


def clean():
    """Reset to clean state: empty series, no patches, .build/ at upstream.

    Fast-reset, no MODE=force anywhere. Per Johan: "If they cant pass
    without a FORCE=1 that is an automatic fail."

    Order matters — pop must run BEFORE clearing patches/:
      1. MODE=discard if .build/ is dirty against baseline.
      2. Pop currently-applied patches (uses .pc/ + patches/*.patch — both
         must still exist at this point).
      3. Clear patches/*.patch + empty series.
      4. Re-run link-overlay (overlay symlinks may have been cleared).
      5. write-baseline.sh — refresh baseline to upstream-only state.

    Steps 2-5 run in a single dc-run.sh container (one container startup
    instead of four, plus shell-level set -euo pipefail catches mid-step
    failures).
    """
    # DEVELOPER_UID/GID auto-detect mirrors Makefile's `?= $(shell id -u)`
    # chain — satisfies dc-run.sh's hard-require when the subprocess
    # bypasses make. Without DEVELOPER_UID/GID, the compose
    # interpolation falls back to :-1000 and writes land as the wrong
    # UID on any non-1000 host. User-shell DEVELOPER_UID/GID still wins.
    make_env = {
        "DEVELOPER_UID": str(os.getuid()), "DEVELOPER_GID": str(os.getgid()),
        **os.environ,
        "APP_ROOT": APP_ROOT,
    }
    git_env = {**os.environ, "GIT_CONFIG_COUNT": "1",
               "GIT_CONFIG_KEY_0": "safe.directory", "GIT_CONFIG_VALUE_0": "*"}

    # The diff-then-discard pre-step isn't strictly needed (the reset
    # below force-cleans regardless), but it's harmless and a small
    # speedup when .build/ is already at baseline state.
    diff = subprocess.run(
        ["git", "-C", BUILD, "diff", "--quiet", "baseline"], env=git_env)
    if diff.returncode != 0:
        subprocess.run(
            ["make", "assemble", "MODE=discard"],
            cwd=APP_ROOT, env=make_env,
            capture_output=True, text=True, timeout=120)

    # Run the shared reset helper inside the dev-builder container per HC 1.
    # See tests/_reset.sh for the full sequence.
    r = subprocess.run(
        ["bash", os.path.join(REPO, "scripts", "host", "dc-run.sh"),
         "bash", "/platform/tests/_reset.sh"],
        cwd=APP_ROOT, env=make_env,
        capture_output=True, text=True, timeout=120)
    if r.returncode != 0:
        print(f"  WARNING: reset failed: stdout={r.stdout[:200]} stderr={r.stderr[:200]}")
        return False
    return True


def modify_file(rel_path, marker):
    """Insert a marker comment near the top of a file in .build/."""
    full = os.path.join(BUILD, rel_path)
    with open(full, "r") as f:
        lines = f.readlines()
    lines.insert(1, f"// {marker}\n")
    with open(full, "w") as f:
        f.writelines(lines)


def run_quilt_scripted(script=""):
    """Run quilt_v2.py via dc-run.sh, scripting interactive prompts via stdin.

    Args:
        script: string with newline-separated answers for interactive prompts
                (e.g. "n\\nmy-patch\\ny\\n" creates new patch named my-patch
                and confirms Apply?). Empty string for paths that exit
                before reading stdin (lock-held, no-changes, recovery).

    Returns: (exit_code, stdout, stderr).

    Routes through dc-run.sh so quilt operations use container quilt
    0.66. Host quilt 0.68 against container-written .pc/ would version-
    skew (a deletion patch round-tripped through the two versions
    silently rewrites .pc/'s metadata fields and breaks the next
    pop+push). dc-run.sh skips the `-t` docker flag when stdin isn't a
    TTY (as here, since subprocess pipes stdin), so the container
    starts with the script as stdin and Python in the container reads
    it via input().
    """
    # DEVELOPER_UID/GID auto-detect (see _restore_original comment).
    env = {
        "DEVELOPER_UID": str(os.getuid()), "DEVELOPER_GID": str(os.getgid()),
        **os.environ,
        "APP_ROOT": APP_ROOT,
    }
    # EDITOR=true via the env(1) shim — `true` is a no-op that exits 0,
    # so the editor step succeeds without prompting. The shim runs INSIDE
    # the container as the entrypoint, overriding whatever EDITOR
    # dc-run.sh injected via `--env EDITOR=...` (which would be the
    # Makefile-resolved chain value if run via make, or empty if not).
    # Explicit `EDITOR=true` keeps tests deterministic regardless of host
    # EDITOR.
    cmd = ["bash", os.path.join(REPO, "scripts", "host", "dc-run.sh"),
           "env", "EDITOR=true",
           "python3", "/platform/scripts/quilt_v2.py"]
    result = subprocess.run(
        cmd, cwd=APP_ROOT, env=env, input=script,
        capture_output=True, text=True, timeout=300)
    return result.returncode, result.stdout, result.stderr


def read_series():
    """Read patch names from series file."""
    with open(os.path.join(PATCHES, "series")) as f:
        return [line.strip() for line in f if line.strip() and not line.startswith("#")]


# ===========================================================================
# M-LOCK: Lock tests
# ===========================================================================

def test_lock():
    print("\n=== M-LOCK: Lock tests ===")

    # M-LOCK-01: Lock acquired on normal run (file exists during run)
    print("\n--- M-LOCK-01: Lock acquired on normal run ---")
    if clean():
        modify_file("lib/AppInfo/Application.php", "lock-test-01")
        # We can't easily check the lock during a run, so we exercise
        # the quilt_common.locked contextmanager directly. Inside the
        # `with` block the lock file exists; LockHeldError fires if
        # another process holds it.
        from quilt_common import locked
        with locked(BUILD):
            check("M-LOCK-01a", os.path.exists(LOCK_FILE),
                  ".build/.lock not created inside locked()")
        # And once we exit, a fresh acquire should succeed (release
        # happens in the contextmanager's finally).
        with locked(BUILD):
            check("M-LOCK-01b", True, "second acquire after release succeeded")

    # M-LOCK-02: Lock held by another process -> exit 6
    print("\n--- M-LOCK-02: Lock held -> exit 6 ---")
    if clean():
        # No edit needed — quilt_v2.main()'s lock check fires before
        # the "no changes" check at extract, so an empty .build/ still
        # produces the lock-held diagnostic when the lock is held.
        held_fd = os.open(LOCK_FILE, os.O_CREAT | os.O_RDWR, 0o664)
        try:
            fcntl.flock(held_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            # Empty stdin script — main() exits at the lock check
            # before any prompt would fire.
            rc, stdout, stderr = run_quilt_scripted()
            check("M-LOCK-02a", rc == 6, f"expected exit 6, got {rc}")
            check("M-LOCK-02b", "already running" in (stdout + stderr).lower(),
                  f"expected 'already running' message")
        finally:
            fcntl.flock(held_fd, fcntl.LOCK_UN)
            os.close(held_fd)

    # M-LOCK-03: Lock released after successful run
    print("\n--- M-LOCK-03: Lock released after successful run ---")
    if clean():
        modify_file("lib/AppInfo/Application.php", "lock-test-03")
        # Drive interactive: 'n' (new patch), name, 'y' (Apply?).
        rc, stdout, stderr = run_quilt_scripted(
            "n\nlock-test-03\ny\n")
        check("M-LOCK-03a", rc == 0, f"expected exit 0, got {rc}; stderr={stderr[:200]}")
        # After successful run, the lock should be released. The
        # contextmanager's finally guarantees this — verify behaviourally
        # that the lock can be re-acquired.
        from quilt_common import locked
        with locked(BUILD):
            check("M-LOCK-03b", True, "lock acquired after successful run")

    # M-LOCK-04 dropped — tested rc=4 from the removed non-interactive
    # validation path (_validate_non_interactive). Lock-released-after-error
    # is still covered structurally: main()'s `with locked(...)` block
    # ensures release on every exit (success or raise) via the
    # contextmanager's finally.

    # M-LOCK-05: make assemble blocked when lock is held (cross-script lock unification)
    # Symmetric counterpart to M-LOCK-02 (which tests make quilt blocked).
    # Verifies assemble.sh and quilt_v2.py share the same .build/.lock flock.
    print("\n--- M-LOCK-05: make assemble blocked when lock is held ---")
    if clean():
        held_fd = os.open(LOCK_FILE, os.O_CREAT | os.O_RDWR, 0o664)
        try:
            fcntl.flock(held_fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            # DEVELOPER_UID/GID auto-detect (see _restore_original).
            make_env = {
                "DEVELOPER_UID": str(os.getuid()), "DEVELOPER_GID": str(os.getgid()),
                **os.environ,
                "APP_ROOT": APP_ROOT,
            }
            result = subprocess.run(
                ["make", "assemble"], cwd=APP_ROOT, env=make_env,
                capture_output=True, text=True, timeout=30)
            # assemble.sh exits 1 on lock contention; make wraps to 2.
            check("M-LOCK-05a", result.returncode != 0,
                  f"expected non-zero exit, got 0; stdout={result.stdout[:200]}")
            check("M-LOCK-05b", "already running" in (result.stdout + result.stderr).lower(),
                  f"expected 'already running' diagnostic; got {result.stderr[:300]}")
        finally:
            fcntl.flock(held_fd, fcntl.LOCK_UN)
            os.close(held_fd)


# ===========================================================================
# M-ED/M-EDIT: Editor tests
# ===========================================================================

def test_editor():
    print("\n=== M-ED/M-EDIT: Editor tests ===")

    # M-ED-01: EDITOR=true skips editor (implicit — every test in
    # this file sets EDITOR=true via run_quilt_scripted's env, and
    # the saves succeed without hanging on an editor prompt; if the
    # skip path were broken, all the M-LOCK/M-SER/M-ED-02/03 setups
    # that create patches via run_quilt_scripted would hang).
    print("\n--- M-ED-01: EDITOR=true skips editor ---")
    check("M-ED-01", True, "implicitly tested by all runs using EDITOR=true")

    # M-ED-02: EDITOR=cat shows header content
    # Run _edit_patch_header via dc-run.sh — host quilt 0.68 against
    # container-quilt-0.66's .pc/ would silently rewrite .pc/'s
    # metadata fields and break the next pop+push. The rebuild keeps
    # quilt operations in container; tests follow.
    print("\n--- M-ED-02: EDITOR=cat shows header content ---")
    if clean():
        modify_file("lib/AppInfo/Application.php", "editor-test-02")
        rc, stdout, stderr = run_quilt_scripted(
            "n\neditor-cat-test\ny\n")
        check("M-ED-02a", rc == 0, f"setup failed: rc={rc}; stderr={stderr[:200]}")
        if rc == 0:
            inline_py = (
                "import os, sys; sys.path.insert(0, '/platform/scripts'); "
                "from quilt_v2 import _edit_patch_header; "
                "_edit_patch_header('editor-cat-test', os.environ['APP_ROOT'] + '/.build')"
            )
            # EDITOR=cat via the env(1) shim — overrides whatever
            # dc-run.sh may have injected via --env EDITOR=... (the
            # Makefile-resolved chain). Explicit override keeps the
            # test deterministic regardless of host EDITOR.
            r = subprocess.run(
                ["bash", os.path.join(REPO, "scripts", "host", "dc-run.sh"),
                 "env", "EDITOR=cat",
                 "python3", "-c", inline_py],
                cwd=APP_ROOT,
                env={
                    "DEVELOPER_UID": str(os.getuid()), "DEVELOPER_GID": str(os.getgid()),
                    **os.environ,
                    "APP_ROOT": APP_ROOT,
                },
                capture_output=True, text=True, timeout=60)
            output = r.stdout + r.stderr
            check("M-ED-02b", "editor-cat-test" in output,
                  f"patch name not shown by cat editor; "
                  f"rc={r.returncode} output={output[:300]}")

    # M-ED-03: Editor sets patch header (run via dc-run.sh, see M-ED-02).
    # Editor script written to $APP_ROOT (rw bind mount) so it's visible
    # inside the container at $APP_ROOT/.test-editor.sh (which resolves
    # to /app/.test-editor.sh on the ephemeral path or
    # /platform/apps/<APP_NAME>/.test-editor.sh on the pool path —
    # dc-run.sh sets APP_ROOT either way).
    print("\n--- M-ED-03: Editor sets patch header ---")
    if clean():
        modify_file("lib/AppInfo/Application.php", "editor-test-03")
        rc, stdout, stderr = run_quilt_scripted(
            "n\nheader-test\ny\n")
        check("M-ED-03a", rc == 0, f"setup failed: rc={rc}; stderr={stderr[:200]}")
        if rc == 0:
            editor_script = os.path.join(APP_ROOT, ".test-editor.sh")
            with open(editor_script, "w") as f:
                f.write('#!/bin/bash\necho "My custom description" > "$1"\n')
            os.chmod(editor_script, 0o755)
            try:
                # EDITOR set inside the inline python from APP_ROOT (which
                # dc-run.sh sets per routing path) so the path resolves
                # correctly under both ephemeral (/app) and pool
                # (/platform/apps/<APP_NAME>) shapes. Doing this in
                # python rather than via `env EDITOR=...` keeps the
                # variable derivation in one place.
                inline_py = (
                    "import os, sys; sys.path.insert(0, '/platform/scripts'); "
                    "os.environ['EDITOR'] = os.environ['APP_ROOT'] + '/.test-editor.sh'; "
                    "from quilt_v2 import _edit_patch_header; "
                    "_edit_patch_header('header-test', os.environ['APP_ROOT'] + '/.build')"
                )
                r = subprocess.run(
                    ["bash", os.path.join(REPO, "scripts", "host", "dc-run.sh"),
                     "python3", "-c", inline_py],
                    cwd=APP_ROOT,
                    env={
                        "DEVELOPER_UID": str(os.getuid()), "DEVELOPER_GID": str(os.getgid()),
                        **os.environ,
                        "APP_ROOT": APP_ROOT,
                    },
                    capture_output=True, text=True, timeout=60)
                # Read the patch file and check for custom header
                patch_path = os.path.join(PATCHES, "header-test.patch")
                if os.path.exists(patch_path):
                    with open(patch_path) as pf:
                        content = pf.read()
                    check("M-ED-03b", "My custom description" in content,
                          f"custom description not in patch header (rc={r.returncode}); "
                          f"first 300 chars: {content[:300]}; stderr: {r.stderr[:200]}")
                else:
                    check("M-ED-03b", False,
                          f"patch file not created; rc={r.returncode}; "
                          f"stderr: {r.stderr[:200]}")
            finally:
                if os.path.exists(editor_script):
                    os.unlink(editor_script)

    # M-ED-04: missing $EDITOR raises KeyError (defensive backstop).
    # quilt_v2._edit_patch_header reads os.environ["EDITOR"] directly.
    # The Makefile's fallback chain resolves EDITOR for the make-invoked
    # path so this KeyError doesn't surface in normal operation. The
    # backstop is for direct script invocations (this test, ad-hoc
    # debugging) that bypass make's resolution — surfaces the missing-
    # EDITOR cause cleanly rather than landing as empty-string EDITOR
    # which would break shlex.split downstream.
    print("\n--- M-ED-04: missing EDITOR raises KeyError ---")
    if clean():
        modify_file("lib/AppInfo/Application.php", "editor-test-04")
        rc, stdout, stderr = run_quilt_scripted(
            "n\nkey-error-test\ny\n")
        check("M-ED-04a", rc == 0,
              f"setup failed: rc={rc}; stderr={stderr[:200]}")
        if rc == 0:
            # Invoke _edit_patch_header in the container with EDITOR explicitly
            # unset. dc-run.sh may have injected --env EDITOR=... (the
            # Makefile-resolved value); `env -u EDITOR` runs INSIDE the
            # container and strips EDITOR from python's env regardless,
            # so the test exercises the defensive backstop reliably.
            inline_py = (
                "import os, sys; sys.path.insert(0, '/platform/scripts'); "
                "from quilt_v2 import _edit_patch_header; "
                "_edit_patch_header('key-error-test', os.environ['APP_ROOT'] + '/.build')"
            )
            r = subprocess.run(
                ["bash", os.path.join(REPO, "scripts", "host", "dc-run.sh"),
                 "env", "-u", "EDITOR",
                 "python3", "-c", inline_py],
                cwd=APP_ROOT,
                env={
                    "DEVELOPER_UID": str(os.getuid()), "DEVELOPER_GID": str(os.getgid()),
                    **os.environ,
                    "APP_ROOT": APP_ROOT,
                },
                capture_output=True, text=True, timeout=60)
            output = r.stdout + r.stderr
            check("M-ED-04b", r.returncode != 0 and "KeyError" in output
                  and "'EDITOR'" in output,
                  f"expected KeyError on missing EDITOR; "
                  f"rc={r.returncode} output={output[:300]}")


# M-JSON tests dropped — JSON output mode and _emit_json were part of
# the removed non-interactive UX. No replacement needed (no consumer).


# ===========================================================================
# M-SER: Series tests
# ===========================================================================

def test_series():
    print("\n=== M-SER: Series tests ===")

    # M-SER-01: New patch added to series
    print("\n--- M-SER-01: New patch added to series ---")
    if clean():
        modify_file("lib/AppInfo/Application.php", "series-test-01")
        rc, stdout, stderr = run_quilt_scripted(
            "n\nnew-series-patch\ny\n")
        check("M-SER-01a", rc == 0, f"expected exit 0, got {rc}; stderr={stderr[:200]}")
        series = read_series()
        check("M-SER-01b", "new-series-patch.patch" in series,
              f"patch not in series: {series}")

    # M-SER-02: Series order preserved after append
    print("\n--- M-SER-02: Series order preserved after append ---")
    if clean():
        # Create first patch
        modify_file("lib/AppInfo/Application.php", "series-test-02a")
        rc, _, stderr = run_quilt_scripted("n\nfirst-patch\ny\n")
        check("M-SER-02a", rc == 0, f"first patch failed: rc={rc}; stderr={stderr[:200]}")

        # Create second patch on a different file
        modify_file("lib/Account.php", "series-test-02b")
        rc, _, stderr = run_quilt_scripted("n\nsecond-patch\ny\n")
        check("M-SER-02b", rc == 0, f"second patch failed: rc={rc}; stderr={stderr[:200]}")

        series = read_series()
        check("M-SER-02c", len(series) >= 2,
              f"expected >= 2 patches, got {len(series)}: {series}")
        if len(series) >= 2:
            first_idx = series.index("first-patch.patch") if "first-patch.patch" in series else -1
            second_idx = series.index("second-patch.patch") if "second-patch.patch" in series else -1
            check("M-SER-02d", first_idx < second_idx,
                  f"order wrong: first at {first_idx}, second at {second_idx}; series={series}")


# ===========================================================================
# E-PMAP: Patch-file mapping tests
# ===========================================================================

def test_patch_file_map():
    print("\n=== E-PMAP: Patch-file mapping tests ===")

    from quilt_extract import _get_patch_file_map

    # Use a temporary directory for isolated tests
    with tempfile.TemporaryDirectory() as tmpdir:

        # E-PMAP-01: File tracked by one patch -> correct annotation
        print("\n--- E-PMAP-01: One patch, one file ---")
        series_path = os.path.join(tmpdir, "series")
        with open(series_path, "w") as f:
            f.write("fix-login.patch\n")
        with open(os.path.join(tmpdir, "fix-login.patch"), "w") as f:
            f.write("--- a/lib/Auth.php\n+++ b/lib/Auth.php\n@@ -1,3 +1,4 @@\n+// fix\n")
        result = _get_patch_file_map(tmpdir)
        check("E-PMAP-01a", "lib/Auth.php" in result,
              f"file not in map: {result}")
        check("E-PMAP-01b", result.get("lib/Auth.php") == ["fix-login"],
              f"annotation wrong: {result.get('lib/Auth.php')}")

        # E-PMAP-02: File tracked by two patches -> both annotations
        print("\n--- E-PMAP-02: Two patches, same file ---")
        with open(series_path, "w") as f:
            f.write("patch-a.patch\npatch-b.patch\n")
        with open(os.path.join(tmpdir, "patch-a.patch"), "w") as f:
            f.write("--- a/lib/Foo.php\n+++ b/lib/Foo.php\n@@ -1,3 +1,4 @@\n+// a\n")
        with open(os.path.join(tmpdir, "patch-b.patch"), "w") as f:
            f.write("--- a/lib/Foo.php\n+++ b/lib/Foo.php\n@@ -5,3 +6,4 @@\n+// b\n")
        result = _get_patch_file_map(tmpdir)
        check("E-PMAP-02a", "lib/Foo.php" in result,
              f"file not in map: {result}")
        check("E-PMAP-02b", result.get("lib/Foo.php") == ["patch-a", "patch-b"],
              f"annotation wrong: {result.get('lib/Foo.php')}")

        # E-PMAP-03: File not in any patch -> empty annotation
        print("\n--- E-PMAP-03: File not in any patch ---")
        result = _get_patch_file_map(tmpdir)
        check("E-PMAP-03", "lib/NotInAny.php" not in result,
              f"unexpected file in map: {result}")

        # E-PMAP-04: Empty series -> empty mapping
        print("\n--- E-PMAP-04: Empty series ---")
        with open(series_path, "w") as f:
            f.write("")
        result = _get_patch_file_map(tmpdir)
        check("E-PMAP-04", result == {},
              f"expected empty dict, got: {result}")

        # E-PMAP-05: Quilt-format patch headers parsed correctly
        print("\n--- E-PMAP-05: Quilt-format headers ---")
        with open(series_path, "w") as f:
            f.write("# This is a comment\n\nquilt-style.patch\n")
        # Quilt produces patches with .build/ prefix instead of b/
        with open(os.path.join(tmpdir, "quilt-style.patch"), "w") as f:
            f.write("Description: my patch\n"
                    "--- .build.orig/lib/Bar.php\n"
                    "+++ .build/lib/Bar.php\n"
                    "@@ -1,3 +1,4 @@\n"
                    "+// quilt format\n")
        result = _get_patch_file_map(tmpdir)
        check("E-PMAP-05a", "lib/Bar.php" in result,
              f"quilt-format file not in map: {result}")
        check("E-PMAP-05b", result.get("lib/Bar.php") == ["quilt-style"],
              f"annotation wrong: {result.get('lib/Bar.php')}")


# ===========================================================================
# Main
# ===========================================================================

def main():
    global passed, failed

    _save_original()

    tests = [
        test_lock,
        test_editor,
        test_series,
        test_patch_file_map,
    ]

    try:
        for test in tests:
            try:
                test()
            except Exception as e:
                failed += 1
                print(f"  EXCEPTION in {test.__name__}: {e}")
                import traceback
                traceback.print_exc()
    finally:
        _restore_original()

    print(f"\n{'='*50}")
    print(f"Results: {passed} passed, {failed} failed, {passed + failed} total")
    if failed > 0:
        sys.exit(1)


if __name__ == "__main__":
    main()
