#!/usr/bin/env python3
"""make quilt v2 — save .build/ changes as quilt patches.

Interactive only. Tests drive it via tmux.

Architecture:
  0. Lock + sanity. Leftover make-quilt-pending tag → refuse entry,
     point at `make assemble MODE=recover` (never auto-recover).
  1. Extract: `git diff baseline` plus `git ls-files --others`
     (untracked) → typed items (Hunk, NewFile, NewOverlayDir,
     OverlayCopy, OverlayDeletion, OverlayRevert, DeletedFile,
     PermissionChange). MUST run BEFORE capture: untracked detection
     needs the pre-capture working tree.
  2. Capture: git add -A + commit + tag make-quilt-pending. Holds the
     user's edits durably across crashes; bytes that don't fit in diff
     text reachable via `git show pending:<path>`.
  3. Present: collect routing decisions in PresentResult. Paths only.
  4. Write: reset .build/ to baseline; strip overlay symlinks; quilt
     pop -a; push patches/series low-to-high applying assigned work
     (text via `patch -p1 --merge`; bytes via `git show pending:<path>`
     for binary / new files); create new patches at top; write overlay.
  5. Verify+finalize: link-overlay; write_baseline (no-rollback
     transition); restore skipped from pending; drop pending tag;
     edit headers.

No `make assemble` call and no in-RAM saved_files / patch_backups: bytes
live in the pending commit, and .build/.git is the durable store.

Exit codes (this script's own). `make` collapses every nonzero status to
its own exit 2, so a caller going through `make quilt` sees only 0 or 2
and must read the diagnostic message, not $?:
  0   Success
  1   Error during write or verify (pending tag preserved; user runs
      `make assemble MODE=recover` to restore .build/);
      OR pending tag detected at entry, refusing.
  2   Missing prerequisite (.build/, baseline, tools)
  3   No changes found / all skipped
  6   Lock held by another process
  130 User aborted (Ctrl+C — pending tag preserved; MODE=recover)
"""

import os
import sys
import shutil
import subprocess
import tempfile
import shlex

# Ensure sibling modules are importable when invoked as a script.
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from quilt_common import (
    PENDING_TAG, LockHeldError,
    assert_in_builder, git_env, locked, read_series,
    run as _run,
)
from quilt_extract import extract_hunks
from quilt_present import (
    present,
    _ask_yn,
    _green, _red, _cyan, _dim, _bold, _SYM_CHECK, _SYM_HEAVY,
)
from quilt_write import write_patches
from quilt_verify import verify_and_finalize


def _edit_patch_header(patch_name, build_dir):
    """Open $EDITOR for a patch's header description.

    Reads $EDITOR from container env. The make-invoked path always sets
    it (Makefile resolves $EDITOR→$VISUAL→`editor`→vi; compose.dev.yml
    and scripts/host/dc-run.sh propagate it in). The KeyError below is a
    deliberate backstop for direct invocations (tests, operator debug)
    that bypass that resolution — surfacing it beats vi silently
    appearing; main()'s catch handler shows the missing-EDITOR cause."""
    assert_in_builder()  # shells out to `quilt header` — needs the pinned toolchain
    patch_file = f"{patch_name}.patch"
    # Relative: cwd=build_dir, so ../patches resolves to apps/<app>/patches
    # wherever build_dir is mounted. See _ensure_quilt_patches for the
    # file-side fix to quilt 0.66's .quilt_patches-overrides-env behavior.
    quilt_env = {**os.environ, "QUILT_PATCHES": "../patches"}

    # `quilt header` exits non-zero on a malformed/missing patch; surface
    # it rather than treat as "no header" and overwrite with the user's
    # text against an unknown baseline.
    result = _run(
        ["quilt", "header", patch_file, "--quiltrc=-"],
        cwd=build_dir, env=quilt_env, check=False)
    if result.returncode != 0:
        raise RuntimeError(
            f"quilt header {patch_file} failed (rc={result.returncode}): "
            f"{(result.stderr or result.stdout or '').strip()}")
    current = result.stdout.rstrip("\n")

    tmp = tempfile.NamedTemporaryFile(
        mode="w", suffix=f"-{patch_name}.desc",
        prefix="quilt-header-", delete=False,
        encoding="utf-8")
    tmp.write((current if current else patch_name) + "\n")
    tmp.flush()
    tmp.close()

    editor = os.environ["EDITOR"]
    print(f"  {_dim('Editing')} {_cyan(patch_name)} {_dim('description...')}")
    # Editor rc ignored: an abort (e.g. vim :cq) leaves tmp unwritten, so
    # new_header equals current and the rewrite is a no-op — nothing lost.
    # subprocess.call (not _run) so the editor inherits the controlling TTY.
    subprocess.call(shlex.split(editor) + [tmp.name])

    with open(tmp.name, "r", encoding="utf-8") as f:
        new_header = f.read().rstrip("\n")
    os.unlink(tmp.name)

    if new_header:
        # check=True: a swallowed failure would lose the user's edited
        # header text silently. (`quilt header -r` only fails if quilt
        # itself is broken.)
        _run(
            ["quilt", "header", "-r", patch_file, "--quiltrc=-"],
            input_data=new_header + "\n",
            cwd=build_dir, env=quilt_env)


def _ensure_quilt_patches(build_dir):
    """Force-write .pc/.quilt_patches=../patches if .pc/ exists.

    Quilt 0.66 reads .pc/.quilt_patches and OVERRIDES the QUILT_PATCHES
    env var every invocation, so trees with an old absolute value baked
    in won't flip from the env var alone — overwrite the file directly.

    No-op when .pc/ is absent (fresh new-app, post-MODE=force wipe): the
    env var carries to first push, which writes the file itself.
    """
    pc_dir = os.path.join(build_dir, ".pc")
    if os.path.isdir(pc_dir):
        with open(os.path.join(pc_dir, ".quilt_patches"), "w") as f:
            f.write("../patches\n")


def _check_series_patches_consistent(patches_dir):
    """Verify every patches/series entry has a patch file on disk.

    A dangling entry (a dev deleted apps/<app>/patches/<n>.patch but left
    its series line) is a real series/patches inconsistency. Checked here as
    an explicit pre-extract prereq, NOT as a side effect of extract's
    attribution map-builder (quilt_extract._get_patch_file_map): left
    unchecked it surfaces deep in write_patches' `quilt push -f --merge`
    fallback as a misleading "ghost-applied" error that names neither the
    missing file nor the cause. Runs pre-capture (read-only, no pending tag),
    so main()'s extract handler surfaces it without a MODE=recover hint
    (nothing to recover).
    """
    for patch_name in read_series(patches_dir):
        patch_path = os.path.join(patches_dir, patch_name)
        if not os.path.exists(patch_path):
            raise RuntimeError(
                f"patches/series references {patch_name!r} but {patch_path} "
                f"doesn't exist — series and patches/ are out of sync.")


def _detect_pending_and_refuse(build_dir, env):
    """If the pending tag is set, refuse entry and point at MODE=recover.

    Recovery is the user's explicit gesture; never auto-recover. Same
    diagnostic shape as main()'s catch handlers — unifies the surface.

    Returns True if the tag is set (caller exits non-zero), else False.
    """
    pending_check = _run(
        ["git", "rev-parse", "--verify", "--quiet",
         f"refs/tags/{PENDING_TAG}"],
        cwd=build_dir, env=env, check=False)
    if pending_check.returncode != 0:
        return False

    print(f"\n{_red('Refusing:')} {PENDING_TAG} tag is set in {os.path.basename(build_dir)}.",
          file=sys.stderr)
    print(f"  A previous `make quilt` was interrupted (failed mid-write,",
          file=sys.stderr)
    print(f"  Ctrl-C, SIGKILL, etc.) and left state to recover.",
          file=sys.stderr)
    print(f"  Run `make assemble MODE=recover` to restore .build/ to your",
          file=sys.stderr)
    print(f"  pre-make-quilt state, then re-run make quilt.",
          file=sys.stderr)
    return True


def _emit_failure_with_recover_hint(exception, interrupted, phase,
                                    build_dir, env):
    """Print the catch-handler diagnostic on graceful failure: failure
    context (+ CalledProcessError stderr) and a tag-state-aware pointer.

    The tag-state check matters because verify_and_finalize drops the
    pending tag BEFORE its last step (the patch-header editor loop). If
    a failure hit the editor (Ctrl-C in vi, $EDITOR raised), the tag is
    gone but patches and overlay/ are durable — so the static "tag
    preserved; run MODE=recover" line would be wrong twice: the tag
    isn't preserved (recover refuses "no pending tag") and there's
    nothing to recover. Distinguish with one `git rev-parse --verify`.
    """
    if interrupted:
        print(f"\nInterrupted during {phase}.", file=sys.stderr)
    else:
        print(f"\n{_red('Error during ' + phase + ':')} {exception}",
              file=sys.stderr)
        if isinstance(exception, subprocess.CalledProcessError):
            stderr_text = (exception.stderr or "").strip()
            if stderr_text:
                print(stderr_text, file=sys.stderr)

    tag_check = _run(
        ["git", "rev-parse", "--verify", "--quiet",
         f"refs/tags/{PENDING_TAG}"],
        cwd=build_dir, env=env, check=False)
    tag_present = (tag_check.returncode == 0)

    if tag_present:
        # Failure before the tag-drop — save is NOT durable, recover.
        print(f"  {PENDING_TAG} tag preserved.", file=sys.stderr)
        print(f"  Run `make assemble MODE=recover` to restore .build/ to your",
              file=sys.stderr)
        print(f"  pre-make-quilt state, then re-run make quilt.",
              file=sys.stderr)
    else:
        # Tag dropped — patches and overlay/ already hold the saved
        # state; only the header-editor step was interrupted, and
        # headers are metadata, not save state. Recovery would refuse.
        print(f"  Save complete — patches and overlay/ are durable.",
              file=sys.stderr)
        print(f"  Only the patch-header editor was interrupted; no recovery needed.",
              file=sys.stderr)
        print(f"  Hand-edit the description at the top of "
              f"apps/<app>/patches/<n>.patch if the header text matters.",
              file=sys.stderr)


def _capture_pending(build_dir, env):
    """Capture user edits in .build/ as the make-quilt-pending commit + tag.

    Caller must have verified there's something to capture (extract
    non-empty); an empty stage makes `git commit` fail and propagate,
    which can't happen in normal flow — not worth a graceful exit.
    """
    # Stage tracked deltas + untracked-non-ignored. The .gitignore regime
    # (write-baseline.sh) filters node_modules, vendor, .pc/, vendor-bin/.
    _run(["git", "add", "-A"], cwd=build_dir, env=env)
    _run(["git", "commit", "-m", PENDING_TAG], cwd=build_dir, env=env)
    # Tag so next run's recovery flow can find the commit.
    _run(["git", "tag", PENDING_TAG], cwd=build_dir, env=env)


def _abandon_pending(build_dir, env):
    """Drop the pending tag and reset HEAD to baseline, preserving working tree.

    Called when main() captured a pending commit but aborts before
    write_patches (said 'no' at confirm, ctrl-C during present). Without
    it, HEAD stays at the pending commit and next make-quilt sees "commit
    between baseline and HEAD with no tag" — a bug shape, not real state.

    Reset must be MIXED, not soft. Pre-entry, user scratches are
    untracked (WT, not INDEX) — what `git ls-files --others` returns and
    extract's untracked detection depends on. _capture_pending's `git add
    -A` staged them; mixed reset unstages, returning INDEX to baseline.
    Soft reset would keep them staged, silently re-categorizing scratches
    NewFile → Hunk(type="new") on next extract — losing the
    [o]verlay [p]atch [s]kip prompt.

    Pairs with quilt_verify's restore-skipped (`git checkout pending` +
    `git reset baseline` per path): both abort and save flows leave
    INDEX = baseline, matching pre-entry shape.

    Both ops must succeed — the tag was just created; its absence means
    something else is wrong.
    """
    _run(["git", "tag", "-d", PENDING_TAG], cwd=build_dir, env=env)
    _run(["git", "reset", "baseline"], cwd=build_dir, env=env)


def main():
    assert_in_builder()
    # cwd (dc-run.sh's --workdir, /app) is the single source of truth for
    # which app this runs against — the caller chain picks it, not us.
    repo_root = os.getcwd()
    build_dir = os.path.join(repo_root, ".build")
    patches_dir = os.path.join(repo_root, "patches")

    # --- Prerequisite checks ---
    if not os.path.isdir(os.path.join(build_dir, ".git")):
        print(".build/ is not set up. Run 'make assemble' first.", file=sys.stderr)
        sys.exit(2)

    env = git_env()

    try:
        _run(["git", "rev-parse", "baseline"],
             cwd=build_dir, env=env)
    except subprocess.CalledProcessError:
        print(".build/ is missing its baseline. Run 'make assemble' first.", file=sys.stderr)
        sys.exit(2)

    # --- Lock + main flow ---
    # Kernel VFS lock shared across every .build/ call site (assemble.sh,
    # quilt_v2, diff.sh, l10n, tests/_reset.sh). Fail-fast on contention;
    # released via the contextmanager's finally even on sys.exit / signals.
    try:
        with locked(build_dir):
            # Must precede any quilt operation.
            _ensure_quilt_patches(build_dir)

            if _detect_pending_and_refuse(build_dir, env):
                sys.exit(1)

            # --- Step 1: Extract ---
            # Extract BEFORE capture, while the working tree is still
            # pre-make-quilt:
            # - `git ls-files --others` (untracked detection) returns
            #   empty post-capture, since capture tracks those files in
            #   HEAD = pending.
            # - extract classifies untracked-with-tracked-parent as
            #   NewFile, else NewOverlayDir, driving present's overlay
            #   prompt. Post-capture they'd surface as new-file Hunks
            #   (`git diff baseline` shows `new file mode`), so present
            #   would only offer patch/skip — the overlay route is lost.
            print("Scanning .build/ for changes...\n")
            # Extract is read-only and pre-capture (no tag yet), so a
            # failure (e.g. series<->patches drift, unparseable diff) is
            # not a recoverable in-flight save: surface the cause, do NOT
            # point at MODE=recover (nothing to recover).
            try:
                _check_series_patches_consistent(patches_dir)
                items = extract_hunks(repo_root)
            except KeyboardInterrupt:
                print("\nInterrupted while scanning .build/.", file=sys.stderr)
                sys.exit(130)
            except Exception as e:
                print(f"\n{_red('Cannot scan .build/ for changes:')} {e}",
                      file=sys.stderr)
                sys.exit(1)

            if not items:
                print("No changes found.")
                sys.exit(3)

            series = read_series(patches_dir, strip_suffix=True)

            # --- Capture user edits as the pending commit ---
            # After extract (above), before present: makes the edits
            # durable if present is killed mid-prompt. Moves HEAD to
            # pending; working tree unchanged.
            _capture_pending(build_dir, env)

            # --- Step 2: Present ---
            result = present(items, series, repo_root=repo_root)

            if result.aborted:
                print("\nAborted. No changes saved.")
                _abandon_pending(build_dir, env)
                sys.exit(130)

            # Must cover all seven work slots: total_assigned() folds in
            # the three patch-routable ones (assignments, deleted_files,
            # new_patch_files); the four overlay slots are listed below.
            # Miss one and a real save is misread as no-work, skipping
            # `Apply?`.
            if (result.total_assigned() == 0
                    and not result.overlay_copies
                    and not result.new_overlay_files
                    and not result.deleted_overlay_files
                    and not result.reverted_overlay_files):
                print("\nNothing to do — all changes were skipped.")
                _abandon_pending(build_dir, env)
                sys.exit(3)

            # --- Confirmation summary ---
            # Rule capped at 40 cols on wider terminals; reused in the
            # success banner.
            heavy = _SYM_HEAVY * min(shutil.get_terminal_size().columns, 40)
            print(f"\n{_bold(heavy)}\n")

            for patch, hunk_desc, file_list in result.summary_lines():
                files_part = _dim(f"  ({file_list})") if file_list else ""
                print(f"  {_cyan(patch):<30s} {hunk_desc}{files_part}")
            if result.overlay_copies:
                n = len(result.overlay_copies)
                fs = "file" if n == 1 else "files"
                print(f"  {'Overlay sync':<30s} {n} {fs}")
            if result.new_overlay_files:
                n = len(result.new_overlay_files)
                fs = "file" if n == 1 else "files"
                print(f"  {'New overlay':<30s} {n} {fs}")
            if result.new_patch_files:
                for patch, files in result.new_patch_files.items():
                    n = len(files)
                    fs = "file" if n == 1 else "files"
                    print(f"  {_cyan(patch) + ' (new files)':<30s} {n} {fs}")
            if result.deleted_files:
                for patch, files in result.deleted_files.items():
                    n = len(files)
                    fs = "file" if n == 1 else "files"
                    print(f"  {_cyan(patch) + ' (deleted)':<30s} {n} {fs}")
            if result.deleted_overlay_files:
                n = len(result.deleted_overlay_files)
                fs = "file" if n == 1 else "files"
                print(f"  {'Overlay delete':<30s} {n} {fs}")
            if result.reverted_overlay_files:
                n = len(result.reverted_overlay_files)
                fs = "file" if n == 1 else "files"
                print(f"  {'Overlay revert':<30s} {n} {fs}")
            if result.skipped:
                print(f"  {_dim('Skipped'):<30s} {_dim(str(len(result.skipped)))}")
            print()

            if not _ask_yn("Apply?"):
                print("Aborted. No changes saved.")
                _abandon_pending(build_dir, env)
                sys.exit(130)

            # --- Step 3: Write ---
            print(f"\n{_dim('Writing patches...')}")

            # Rollback for .build/ is the pending tag, not a backup dict:
            # any failure (Exception, KeyboardInterrupt, SIGKILL) leaves
            # the tag, and `make assemble MODE=recover` restores via the
            # bash primitive in assemble.sh. The parent repo's patches/ +
            # overlay/ keep whatever partial state remains; the dev
            # reverts with `git -C apps/<app> checkout -- patches overlay`.
            #
            # The broad `except (KeyboardInterrupt, Exception)` is deliberate.
            # Every legitimate failure (conflict, drift, lock contention) is
            # handled inside write_patches, so a coding bug should be the ONLY
            # thing that reaches here. But if one does, the user must still get
            # the MODE=recover gesture and carry on with their work — never be
            # left stranded in a half-applied state because the platform hit a
            # bug. Recovery is identical whether the cause was a bug or a kill.
            #
            # write_patches has no partial-success path — returns or
            # raises. Conflicts pause for in-process resolution (Enter to
            # continue with a refresh, Ctrl-C to raise out → same path).
            try:
                write_patches(result, repo_root)
            except (KeyboardInterrupt, Exception) as e:
                interrupted = isinstance(e, KeyboardInterrupt)
                _emit_failure_with_recover_hint(
                    e, interrupted, "write", build_dir, env)
                sys.exit(130 if interrupted else 1)

            # --- Step 4: Verify + finalize ---
            print(_dim("Verifying patches..."))

            def editor_func(patch_name):
                _edit_patch_header(patch_name, build_dir)

            try:
                verify_and_finalize(repo_root, result,
                                    editor_func=editor_func)
            except (KeyboardInterrupt, Exception) as e:
                interrupted = isinstance(e, KeyboardInterrupt)
                _emit_failure_with_recover_hint(
                    e, interrupted, "verify", build_dir, env)
                sys.exit(130 if interrupted else 1)

            # --- Done ---
            all_patch_names = (set(result.assignments.keys())
                               | set(result.new_patch_files.keys())
                               | set(result.deleted_files.keys()))
            patches_written = len(all_patch_names)
            overlays_synced = (len(result.overlay_copies)
                               + len(result.new_overlay_files))
            overlays_deleted = len(result.deleted_overlay_files)
            overlays_reverted = len(result.reverted_overlay_files)

            parts = []
            if patches_written:
                ps = "patch" if patches_written == 1 else "patches"
                parts.append(f"{patches_written} {ps}")
            if overlays_synced:
                fs = "file" if overlays_synced == 1 else "files"
                parts.append(f"{overlays_synced} overlay {fs}")
            if overlays_deleted:
                fs = "file" if overlays_deleted == 1 else "files"
                parts.append(f"{overlays_deleted} overlay {fs} deleted")
            if overlays_reverted:
                fs = "file" if overlays_reverted == 1 else "files"
                parts.append(f"{overlays_reverted} overlay {fs} reverted to upstream")
            print(f"\n{_bold(heavy)}")
            print(f"{_green(_SYM_CHECK)} Saved {', '.join(parts)}")
    except LockHeldError:
        print("Another .build/ operation is already running. `make status` shows what's holding it.", file=sys.stderr)
        sys.exit(6)


if __name__ == "__main__":
    main()
