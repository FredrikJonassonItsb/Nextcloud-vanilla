"""Verify-and-finalize the make-quilt save. Crosses the no-rollback transition.

`.build/` already has all patches applied with the user's edits baked in.

  1. link-overlay — recreate overlay symlinks pointing at overlay/.
  2. write-baseline.sh — refresh .build/.git's baseline tag at the new
     state. No-rollback point: past here pending.parent != baseline.
  3. Restore skipped items into the working tree against the new
     baseline. MUST run after write_baseline, else they fold into
     baseline and disappear.
  4. Drop the make-quilt-pending tag.
  5. Edit patch headers.

No `make assemble` call: bytes for binary/new/overlay-copy paths come
from the pending commit on demand (`git show pending:<path>`), not an
in-RAM saved_files dict — .build/.git is the content store.
"""

import os
import subprocess

from quilt_common import (
    PENDING_TAG, PLATFORM_ROOT,
    assert_in_builder, git_env, read_series, run as _run,
)


def verify_and_finalize(repo_root, result, editor_func=None):
    """Cross the no-rollback transition: link-overlay, write_baseline,
    restore skipped, drop pending tag, edit headers.

    Args:
        repo_root: path to app root (upstream/, patches/, overlay/, .build/).
        result: PresentResult from quilt_present (drives the editor loop).
        editor_func: callable(patch_name) to edit patch headers. None
                     suppresses the editor loop (direct test callers;
                     quilt_v2.main() always passes a real function).

    Raises:
        subprocess.CalledProcessError or RuntimeError on git/script
        failures; quilt_v2.main()'s catch handler emits the MODE=recover
        diagnostic. Pending tag preserved for recovery; process death
        takes the same MODE=recover path.
    """
    assert_in_builder()
    build_dir = os.path.join(repo_root, ".build")
    patches_dir = os.path.join(repo_root, "patches")
    overlay_dir = os.path.join(repo_root, "overlay")
    env = git_env()

    # 1. link-overlay — recreate symlinks + copyfiles into .build/.
    #    write_patches already stripped the old overlay symlinks, so
    #    link's no-overlay-symlinks precondition holds.
    link_script = os.path.join(PLATFORM_ROOT, "scripts", "link-overlay.sh")
    subprocess.run(
        ["bash", link_script, "link", build_dir],
        env={**os.environ,
             "OVERLAY": overlay_dir,
             "COPYFILES_D": os.path.join(repo_root, "overlay-copyfiles.d")},
        check=True)

    # 2. write-baseline.sh — refresh the baseline tag. No-rollback point:
    #    once this returns, pending.parent != current baseline. Recovery
    #    is uniform regardless of crash point — `make assemble
    #    MODE=recover` restores from the pending tag and re-walks the series.
    write_baseline_script = os.path.join(PLATFORM_ROOT, "scripts", "write-baseline.sh")
    subprocess.run(
        ["bash", write_baseline_script],
        env={**os.environ,
             "BUILD_DIR": build_dir},
        check=True)

    # 3. Restore skipped items from the pending commit. Skipped items are
    #    user edits the present step deferred (not assigned to a patch this
    #    round); they must reappear in the working tree as uncommitted state
    #    against the new baseline so next make-quilt sees them.
    #
    #    NewOverlayDir carries multiple paths (.files); every other item type
    #    carries one (.file). Collect into a deduped list, then dispatch each
    #    path on whether it exists in pending (add/modify vs deletion shape).
    seen = set()
    skipped_paths = []
    for item in result.skipped:
        item_files = item.files if hasattr(item, "files") else [item.file]
        for f in item_files:
            if f not in seen:
                seen.add(f)
                skipped_paths.append(f)

    for path in skipped_paths:
        # Invariant: WT mirrors pending's content for <path>; INDEX stays
        # at baseline (or absent if not in baseline). Load-bearing for new
        # files: extract uses `git ls-files --others` to detect untracked
        # new files and route them to the [o]verlay [p]atch [s]kip prompt.
        # If INDEX has the new file staged instead (as `git checkout
        # pending -- <path>` alone would leave it), the next extract sees
        # it via `git diff baseline` as Hunk(type="new") → "Add to:" prompt,
        # losing the [o]verlay option. Same invariant _abandon_pending
        # preserves on the abort path via its mixed reset.
        #
        # Mechanism: `git checkout pending -- <path>` writes WT mode-aware
        # (symlinks, exec bits, parent dirs) but ALSO stages to INDEX;
        # `git reset baseline -- <path>` then reverts the INDEX entry to
        # baseline (or removes it if absent there) without touching WT.
        # Verified empirically: git reset on a path not in <commit>'s tree
        # behaves as `git rm --cached`, so this one sequence handles both
        # additions (removed from INDEX) and modifications (restored to
        # baseline content).
        #
        # Branch by existence in pending: `git checkout pending -- <path>`
        # errors "pathspec did not match" when the path isn't in pending's
        # tree, so the deletion case (path deleted before make-quilt entry)
        # takes a separate WT-only os.unlink branch.
        exists_in_pending = _run(
            ["git", "cat-file", "-e", f"{PENDING_TAG}:{path}"],
            cwd=build_dir, env=env, check=False)
        if exists_in_pending.returncode == 0:
            # Addition or modification.
            _run(["git", "checkout", PENDING_TAG, "--", path],
                 cwd=build_dir, env=env)
            _run(["git", "reset", "-q", "baseline", "--", path],
                 cwd=build_dir, env=env)
        else:
            # Skipped deletion: WT-only delete. INDEX is at baseline here
            # (write_baseline just ran); leaving it alone keeps the file
            # tracked-at-baseline, so the next extract sees the deletion
            # via `git diff baseline --diff-filter=D` and emits
            # Hunk(type="deleted") to route. The exists guard mirrors
            # `--ignore-unmatch`: the path may also be absent from baseline
            # (added then deleted in one session, both stages skipped).
            full = os.path.join(build_dir, path)
            if os.path.exists(full):
                os.unlink(full)

    # 4. Drop the make-quilt-pending tag.
    _run(["git", "tag", "-d", PENDING_TAG],
         cwd=build_dir, env=env)

    # 5. Edit patch headers (interactive only). Not gated by the rollback
    #    transition: the save is already final. Crash here → quilt_v2.main()
    #    detects the post-drop state (tag gone) and emits a "save complete;
    #    only header edit interrupted" diagnostic, not MODE=recover.
    if editor_func is not None:
        modified = (set(result.assignments.keys())
                    | set(result.new_patch_files.keys())
                    | set(result.deleted_files.keys()))
        # PresentResult keys are stripped of .patch; series entries on disk
        # carry it. Compare both forms so the suffix mismatch can't hide a
        # modified patch from the edit loop.
        for patch in read_series(patches_dir):
            name = patch.removesuffix(".patch")
            if name in modified or patch in modified:
                editor_func(name)
