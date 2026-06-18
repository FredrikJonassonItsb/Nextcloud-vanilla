"""Write patch assignments to quilt patches.

`.build/.git` holds a "make-quilt-pending" commit + tag capturing the
user's edits before this function runs. Steps:

  1. Reset .build/ working tree to baseline (clean state for quilt).
  2. Strip overlay symlinks (so quilt pop/push doesn't write through
     them and corrupt overlay/).
  3. quilt pop -a (clean stack, .pc/ empty).
  4. Walk patches/series low-to-high pushing each existing patch;
     apply assigned work + refresh.
  5. Create any new patches at top of stack with their content.
  6. Write overlay-targeted edits into $repo_root/overlay/.

Why `patch --merge` and not `git apply`: hunks are extracted from
`git diff baseline..pending`, so their context references the
all-applied state. At intermediate stack positions context drifts.
`git apply` rejects on mismatch — and an earlier implementation
absorbed those rejections into `quilt refresh`, silently losing the
edit. `patch -p1 --merge` writes conflict markers in place and exits
non-zero; we pause in-process for the dev to resolve (see below).

On success: .build/ has all patches applied with the user's edits
baked in; patches/ and overlay/ carry the saved state. The caller
(quilt_verify) handles link-overlay → write_baseline → restore
skipped → drop pending tag.

Failure handling: every failure either pauses for resolution or
raises to quilt_v2.main()'s catch handler, which emits the
`make assemble MODE=recover` hint and leaves the pending tag set —
the dev's explicit recover invocation does the recovery git ops.
Uniform path for graceful raise and SIGKILL-style process death:
same end state, same gesture. Ctrl-C at a pause prompt raises
KeyboardInterrupt and exits 130 via that same handler.
"""

import os
import shutil
import subprocess
import sys

from quilt_common import (
    PENDING_TAG, PLATFORM_ROOT, RESOLVE_PROMPT_SIGNATURE,
    assert_in_builder, extract_file_path, git_env, read_series,
    run as _run,
)


def _is_deletion(file_header):
    """Check if this hunk represents a file deletion.

    Two signals, because binary deletions emit no `+++ /dev/null`
    (git replaces the +++/--- pair with `Binary files ... differ`):
      `+++ /dev/null`     — present for text deletions.
      `deleted file mode` — present for any deletion; the only
                            deletion signal in a binary file_header.
    """
    for line in file_header.split("\n"):
        if line.startswith("+++ /dev/null"):
            return True
        if line.startswith("deleted file mode"):
            return True
    return False


def _show_pending(build_dir, path):
    """Read raw bytes of `path` from the make-quilt-pending commit.

    For binary hunks, new files, overlay copies, new overlay files —
    cases where `result` carries the path but not the bytes; the bytes
    live in the pending commit captured at make-quilt entry.
    """
    result = subprocess.run(
        ["git", "show", f"{PENDING_TAG}:{path}"],
        cwd=build_dir, env=git_env(),
        capture_output=True, check=True)
    return result.stdout


def _normalize_patch_name(name):
    """Append .patch suffix if missing."""
    return name if name.endswith(".patch") else f"{name}.patch"


def _build_work_index(result):
    """Group result's per-patch work by patch name.

    Returns: dict[patch_name_with_.patch_suffix -> {hunks, new_files, delete_files}].
    """
    work = {}

    def slot(name):
        pf = _normalize_patch_name(name)
        return work.setdefault(pf, {"hunks": [], "new_files": [], "delete_files": []})

    for patch_name, hunks in result.assignments.items():
        bucket = slot(patch_name)
        for file_header, hunk_content in hunks:
            if _is_deletion(file_header):
                fp = extract_file_path(file_header)
                if fp is None:
                    # extract_hunks always yields an extractable path; a
                    # silent `if fp:` skip would drop the deletion from the
                    # save while reporting success. Raise instead.
                    raise RuntimeError(
                        f"deletion hunk in {patch_name} has no extractable "
                        f"file path; file_header={file_header!r}")
                bucket["delete_files"].append(fp)
            else:
                bucket["hunks"].append((file_header, hunk_content))

    for patch_name, files in result.new_patch_files.items():
        slot(patch_name)["new_files"].extend(files)

    for patch_name, files in result.deleted_files.items():
        slot(patch_name)["delete_files"].extend(files)

    return work


def _quilt_add(build_dir, quilt_env, paths):
    """Snapshot `paths` to .pc/<top>/ via `quilt add`.

    Pre: top patch exists (caller pushed or created it).

    quilt add returns:
        0  → success.
        2  → "File X is already in patch" — legitimate when the top
             patch already touches a path (existing push snapshotted
             it). Not an error.
        1  → real error (file outside working dir, .pc/ broken, etc.).
    """
    if not paths:
        return
    r = _run(["quilt", "add", "--quiltrc=-", *sorted(paths)],
             cwd=build_dir, env=quilt_env, check=False)
    if r.returncode not in (0, 2):
        raise RuntimeError(
            f"quilt add failed (rc={r.returncode}): "
            f"{(r.stderr or r.stdout or '').strip()}")


def _quilt_files(build_dir, quilt_env, patch_name):
    """Return the list of files quilt registers as touched by `patch_name`.

    Marker-detection candidates after `quilt push -f --merge`. On a
    cascade conflict the list holds every file the patch touched
    (merged-clean or marked); an empty list is the "ghost-applied"
    signal (forced apply against a truly-missing target) that
    distinguishes patch-level failure from a resolvable conflict.

    RC 0 even for zero files; non-zero only on environmental failure
    (no .pc/, lock held). Surface those as RuntimeError — the caller
    can't proceed if quilt can't enumerate.
    """
    r = _run(["quilt", "files", "--quiltrc=-", patch_name],
             cwd=build_dir, env=quilt_env, check=False)
    if r.returncode != 0:
        raise RuntimeError(
            f"quilt files {patch_name} failed (rc={r.returncode}): "
            f"{(r.stderr or r.stdout or '').strip()}")
    return [line.strip() for line in r.stdout.splitlines() if line.strip()]


def _refresh_top_patch(build_dir, quilt_env, patch_name):
    """Run `quilt refresh` against the current top patch.

    Flags force canonical, deterministic output; quilt's defaults leak
    build paths, churn mtimes, and reorder hunks non-deterministically.

      -p ab                        git-conventional a/ b/ prefixes;
                                   default leaks `.build.orig/<path>`.
      --no-timestamps              strip mtimes from ---/+++ lines.
      --no-index                   drop redundant `Index:` block.
      --sort                       deterministic hunk order.
      --strip-trailing-whitespace  drop editor-save trailing whitespace
                                   quilt would otherwise keep in context.

    Raises on refresh failure — the patch wasn't written; don't let the
    run continue thinking it saved. main()'s catch handler emits the
    recover hint; pending tag stays set.
    """
    r = _run(["quilt", "refresh", "--quiltrc=-",
              "-p", "ab",
              "--no-timestamps", "--no-index",
              "--sort", "--strip-trailing-whitespace"],
             cwd=build_dir, env=quilt_env, check=False)
    if r.returncode != 0:
        stderr_tail = "\n".join((r.stderr or "").splitlines()[-20:])
        stdout_tail = "\n".join((r.stdout or "").splitlines()[-20:])
        raise RuntimeError(
            f"quilt refresh failed for {patch_name} (rc={r.returncode}); "
            f"the patch did not save.\n"
            f"--- stdout ---\n{stdout_tail}\n"
            f"--- stderr ---\n{stderr_tail}")


def _resolve_or_raise(build_dir, patch_name, candidate_files,
                      stdout, stderr, context):
    """Scan `candidate_files` for conflict markers; pause-and-continue
    or raise.

    Two call sites, picked by `context`:
      - "routed": `patch -p1 --merge` failure in _apply_patch_work —
        all-applied hunks didn't match the working tree here.
      - "cascade": `quilt push -f --merge` failure in write_patches'
        loop — the patch's stored content didn't match, usually
        because an earlier patch this save was refreshed with resolved
        content that shifted this patch's apply context.

    Three outcomes:
      - `<<<<<<<` in any candidate → print diagnostic (heading by
        `context`), input() waits for resolve-and-Enter. Returns None;
        Ctrl-C propagates KeyboardInterrupt; EOF raises RuntimeError.
      - No markers, candidates non-empty → patch-level failure (no
        resolution surface). Raise.
      - No markers, candidates empty → cascade-only "ghost-applied"
        signal (forced apply against a missing target). Raise.

    Returns-on-resolution / raises-on-unresolvable lets the caller
    chain `_resolve_or_raise(...); _refresh_top_patch(...)`.

    Cross-file coupling: the substring printed at both prompt sites is
    `RESOLVE_PROMPT_SIGNATURE` from quilt_common, also imported by
    tests/test_state_integrity.py's smart resolver, which counts its
    occurrences in tmux pane history to tell a new cascade prompt from
    an already-handled one. Single-sourced; rewording it here without
    the constant breaks that test silently.
    """
    files_with_markers = []
    for fp in candidate_files:
        full = os.path.join(build_dir, fp)
        try:
            with open(full, "rb") as fh:
                content = fh.read()
        except FileNotFoundError:
            # Candidate file absent on disk — dominant case is a
            # deletion patch: quilt records it as touched but the
            # patch removed it, so no markers there. Treat as
            # markerless; if every candidate is in this state it falls
            # through to the "no resolution surface" raise. Catch is
            # narrow on purpose: any other OSError (PermissionError,
            # IsADirectoryError) is unexpected on a tree we own — let
            # it crash rather than masquerade as "no markers."
            content = b""
        if b"<<<<<<<" in content:
            files_with_markers.append(fp)

    stdout_tail = "\n".join((stdout or "").splitlines()[-20:])
    stderr_tail = "\n".join((stderr or "").splitlines()[-20:])

    if not files_with_markers:
        # No resolution surface — three sub-cases, named in the raises:
        #   cascade + empty     → ghost-applied (missing target)
        #   cascade + non-empty → push -f --merge patch-level failure
        #   routed  + non-empty → patch -p1 --merge patch-level failure
        if context == "cascade" and not candidate_files:
            raise RuntimeError(
                f"quilt push -f --merge {patch_name} ghost-applied "
                f"the patch (recorded as applied but no files were "
                f"actually modified — typically a stored patch whose "
                f"target files don't exist at this stack position). "
                f"Likely a series-corruption issue from earlier in "
                f"this save.\n"
                f"--- quilt stdout ---\n{stdout_tail}\n"
                f"--- quilt stderr ---\n{stderr_tail}")
        if context == "cascade":
            raise RuntimeError(
                f"quilt push -f --merge {patch_name} failed with no "
                f"conflict markers in any of {sorted(candidate_files)}. "
                f"Likely a patch-level failure (malformed diff, "
                f"permission error).\n"
                f"--- quilt stdout ---\n{stdout_tail}\n"
                f"--- quilt stderr ---\n{stderr_tail}")
        raise RuntimeError(
            f"patch -p1 --merge failed for hunks routed to "
            f"{patch_name} with no conflict markers written. "
            f"Likely a malformed diff, permission error, or "
            f"other patch-level failure. Affected files: "
            f"{sorted(candidate_files)}.\n"
            f"--- patch stdout ---\n{stdout_tail}\n"
            f"--- patch stderr ---\n{stderr_tail}")

    # Pause-and-continue, in-process by design: no --continue command,
    # no routing persistence. Routing decisions stay in the work dict
    # in RAM between pause and resume because we ARE the same process.
    if context == "cascade":
        print(
            f"\nCascading conflict in {patch_name}: the patch's stored "
            f"hunks don't apply cleanly against the current stack "
            f"state. Most often this means an earlier patch in this "
            f"save was refreshed with resolved content that shifted "
            f"{patch_name}'s context; can also be a hand-edited patch "
            f"file or an upstream change.",
            file=sys.stderr)
    else:
        print(
            f"\nConflict applying hunks routed to {patch_name}.",
            file=sys.stderr)
    print(
        f"Conflict markers (<<<<<<< / ======= / >>>>>>>) are in:",
        file=sys.stderr)
    for fp in sorted(files_with_markers):
        print(f"  .build/{fp}", file=sys.stderr)
    if context == "cascade":
        if stdout_tail.strip():
            print(f"--- quilt stdout ---\n{stdout_tail}",
                  file=sys.stderr)
        if stderr_tail.strip():
            print(f"--- quilt stderr ---\n{stderr_tail}",
                  file=sys.stderr)
        print(
            f"\n{RESOLVE_PROMPT_SIGNATURE}, then press Enter "
            f"to continue (refreshes {patch_name} to capture your "
            f"resolution into patches/{patch_name}). Ctrl-C aborts; "
            f"pending tag preserved — run `make assemble MODE=recover` "
            f"to restore .build/ to your pre-quilt state.",
            file=sys.stderr)
    else:
        if stdout_tail.strip():
            print(f"--- patch stdout ---\n{stdout_tail}",
                  file=sys.stderr)
        if stderr_tail.strip():
            print(f"--- patch stderr ---\n{stderr_tail}",
                  file=sys.stderr)
        print(
            f"\n{RESOLVE_PROMPT_SIGNATURE}, then press Enter "
            "to continue (quilt refresh against the resolved tree). "
            "Ctrl-C aborts; pending tag preserved — run "
            "`make assemble MODE=recover` to restore .build/ to your "
            "pre-quilt state.",
            file=sys.stderr)
    try:
        # input() reads from sys.stdin, separate from the input_data
        # pipe fed to the patch subprocess. Ctrl-C → KeyboardInterrupt
        # → main()'s catch handler (exit 130). EOF means no human (piped
        # automation), which can't resolve a context conflict — raise.
        input()
    except EOFError:
        raise RuntimeError(
            f"stdin closed at pause prompt for {patch_name}; "
            f"no resolver present.")


def _apply_patch_work(work, build_dir, quilt_env, patch_name):
    """Apply one patch's hunks/new_files/delete_files at top of stack.

    Pre: caller pushed (or `quilt new`-created) the target patch so it
    is the top patch.

    Raises (both → main()'s catch handler, recover hint, tag preserved):
        - KeyboardInterrupt: Ctrl-C at the conflict pause prompt (exit 130).
        - RuntimeError: quilt add/refresh failure, or stdin EOF at the prompt.
    """
    text_hunks = [(fh, hc) for fh, hc in work["hunks"] if hc]
    binary_hunks = [(fh, hc) for fh, hc in work["hunks"] if not hc]

    # Files referenced by ANY operation this patch performs, snapshotted
    # to .pc/<top>/ before any working-tree edit so refresh diffs against
    # the pre-edit state.
    #
    # Includes new_files (not yet on disk). Depends on quilt 0.66
    # behaviour: `quilt add <nonexistent-path>` creates an empty
    # placeholder snapshot rather than refusing, so refresh later sees
    # empty-vs-content and records the creation. Without it, routing new
    # files to existing patches breaks silently. Verified empirically
    # against the chaos arc.
    files_in_scope = set()
    for fh, _ in work["hunks"]:
        fp = extract_file_path(fh)
        if fp:
            files_in_scope.add(fp)
    files_in_scope.update(work["new_files"])
    files_in_scope.update(work["delete_files"])
    _quilt_add(build_dir, quilt_env, files_in_scope)

    # Text hunks via `patch -p1 --merge` (merge mode rationale: module
    # docstring). Below, dedup hunks per file into a single
    # `diff --git ... --- ... +++` block. Load-bearing, verified
    # empirically: a repeated file_header makes patch re-open the file
    # and lose its line-offset bookkeeping, so later hunks can't track
    # positions shifted by earlier ones — both hunks end up NOT MERGED.
    if text_hunks:
        by_file = {}
        for fh, hc in text_hunks:
            fp = extract_file_path(fh)
            if fp is None:
                # Shouldn't happen — extract_hunks guarantees the
                # diff --git / --- a/ / +++ b/ triplet. Surface rather
                # than silently drop.
                raise RuntimeError(
                    f"hunk in {patch_name} has no extractable file path; "
                    f"file_header={fh!r}")
            if fp not in by_file:
                by_file[fp] = [fh, ""]
            by_file[fp][1] += hc
        mini_diff = "".join(fh + hcs for fh, hcs in by_file.values())

        # Pre-check: every routed-hunk file must exist at this patch's
        # apply state. patch -p1 --merge against a missing file silently
        # misses that file's edit when mixed with markers on other files
        # (post-check sees markers, pauses, refresh then diffs without
        # the missing file — silent edit loss). Raise cleanly instead;
        # dev recovers via MODE=recover and re-routes.
        for fp in by_file:
            full = os.path.join(build_dir, fp)
            if not os.path.exists(full):
                raise RuntimeError(
                    f"hunks routed to {patch_name} reference {fp}, but "
                    f"that file doesn't exist at this patch's apply "
                    f"state. The file's creator-patch is later in "
                    f"series than {patch_name}; re-run make quilt and "
                    f"route to a later patch (or to the file's "
                    f"creator-patch).")

        # Flag rationale (each verified empirically):
        # --merge                 → write conflict markers in place on
        #                           mismatch (the resolution surface)
        #                           instead of a .rej with file untouched.
        # --no-backup-if-mismatch → suppress `<file>.orig`; otherwise it
        #                           litters .build/ and (worse) lands in
        #                           the next refresh's diff if missed.
        # --batch                 → never prompt; a missing-file case
        #                           would else hit `File to patch:` and
        #                           read EOF off the closed stdin pipe.
        merge_result = _run(
            ["patch", "-p1", "--merge", "--no-backup-if-mismatch",
             "--batch"],
            cwd=build_dir, check=False, input_data=mini_diff)
        if merge_result.returncode != 0:
            # _resolve_or_raise sorts context-conflict (markers, resolve
            # and Enter → returns → fall through to refresh) from
            # patch-level error (no markers → raises). The pre-check
            # above already caught missing-file; this is the safety net
            # for what slips past. context="routed" picks the heading.
            _resolve_or_raise(
                build_dir, patch_name, list(by_file.keys()),
                merge_result.stdout, merge_result.stderr,
                context="routed")
            # Fall through to quilt refresh — captures the resolved tree.

    # Binary hunks: bytes from the pending commit (the diff carries no
    # content for binaries — `Hunk.content == ""`). _show_pending failure
    # is fatal: extract found the path in `git diff baseline`, so its
    # absence in pending means .build/.git is inconsistent with extract.
    for fh, _ in binary_hunks:
        fp = extract_file_path(fh)
        if fp is None:
            raise RuntimeError(
                f"binary hunk in {patch_name} has no extractable file "
                f"path; file_header={fh!r}")
        full = os.path.join(build_dir, fp)
        content = _show_pending(build_dir, fp)
        os.makedirs(os.path.dirname(full), exist_ok=True)
        with open(full, "wb") as fh_out:
            fh_out.write(content)

    # New files belonging to this patch — same byte-source contract as
    # binary above.
    for fp in work["new_files"]:
        full = os.path.join(build_dir, fp)
        content = _show_pending(build_dir, fp)
        os.makedirs(os.path.dirname(full), exist_ok=True)
        with open(full, "wb") as fh_out:
            fh_out.write(content)

    # Deletions: same shape gap as the modified-hunks pre-check. A
    # deletion routed to a patch earlier than the file's creator-patch
    # references a file absent at this apply state; the naive `if exists:
    # unlink else skip` would silently lose it (refresh sees no diff,
    # patch saves "successfully" without the deletion). Raise instead.
    # Two loops (pre-check then unlink) keep it all-or-nothing: a single
    # check-then-unlink loop would leave us half-deleted if file n fails.
    for fp in work["delete_files"]:
        full = os.path.join(build_dir, fp)
        if not os.path.exists(full):
            raise RuntimeError(
                f"deletion routed to {patch_name} references {fp}, but "
                f"that file doesn't exist at this patch's apply state. "
                f"The file's creator-patch is later in series than "
                f"{patch_name}; re-run make quilt and route the "
                f"deletion to a later patch (or to the file's "
                f"creator-patch).")

    # Unlink each. Pre-check guarantees existence, so a FileNotFoundError
    # here means an unexpected mid-flight mutation (concurrent deletion
    # outside the platform's lock) — crash-fast rather than absorb.
    # Refresh then sees gone-vs-snapshot and records the deletion.
    for fp in work["delete_files"]:
        full = os.path.join(build_dir, fp)
        os.unlink(full)

    # Refresh — captures all changes into the patch file.
    _refresh_top_patch(build_dir, quilt_env, patch_name)


def write_patches(result, repo_root):
    """Apply hunk assignments to quilt patches.

    Args:
        result: PresentResult from quilt_present.
        repo_root: path to app root (upstream/, patches/, overlay/, .build/).

    Returns:
        None on success.

    Raises (both → main()'s catch handler, recover hint, tag preserved):
        KeyboardInterrupt: Ctrl-C at the conflict pause prompt (exit 130).
        RuntimeError: quilt push/add/new/refresh failure, or stdin closed
            at the prompt. SIGKILL-style death takes the same path.
    """
    assert_in_builder()
    build_dir = os.path.join(repo_root, ".build")
    patches_dir = os.path.join(repo_root, "patches")
    overlay_dir = os.path.join(repo_root, "overlay")
    upstream_dir = os.path.join(repo_root, "upstream")
    # Relative so .pc/.quilt_patches stays portable. main() already set
    # the file to ../patches; this env covers paths that create .pc/ anew.
    quilt_env = {**os.environ, "QUILT_PATCHES": "../patches"}
    env = git_env()

    # 1. Reset .build/ working tree to baseline. The pending commit captures
    #    user edits; we need a clean baseline tree to navigate quilt against.
    subprocess.run(
        ["git", "reset", "--hard", "baseline"],
        cwd=build_dir, env=env, check=True,
        capture_output=True)

    # 2. Strip overlay symlinks before any quilt op: quilt pop
    #    reverse-applies through symlinks, so a patched file shadowed by
    #    an overlay symlink would corrupt overlay/. Shared impl in
    #    scripts/link-overlay.sh.
    subprocess.run(
        ["bash", os.path.join(PLATFORM_ROOT, "scripts", "link-overlay.sh"),
         "strip", build_dir],
        env={**os.environ, "OVERLAY": overlay_dir},
        check=True)

    # 3. quilt pop -a to start from upstream-rsynced state (.pc/ empty).
    #    `quilt applied` RC: 0 = applied patches listed (pop them);
    #    1 = "No patches in series", 2 = "No applied patches" (both real
    #    "nothing to pop" states); any other RC is unexpected. This is
    #    branching between two real actions ("pop them" vs "nothing to
    #    pop"), not a silent-skip on a missing precondition.
    pc_dir = os.path.join(build_dir, ".pc")
    if os.path.isdir(pc_dir):
        applied = _run(["quilt", "applied", "--quiltrc=-"],
                       cwd=build_dir, env=quilt_env, check=False)
        if applied.returncode not in (0, 1, 2):
            raise RuntimeError(
                f"quilt applied failed unexpectedly (RC={applied.returncode}): "
                f"{(applied.stderr or applied.stdout or '').strip()}")
        if applied.returncode == 0 and applied.stdout.strip():
            subprocess.run(
                ["quilt", "pop", "-a", "--quiltrc=-"],
                cwd=build_dir, env=quilt_env, check=True,
                capture_output=True)

    # 4. Walk patches/series low-to-high. For each existing patch:
    #    push it; if work assigned, apply work + refresh.
    series = read_series(patches_dir)
    series_set = set(series)
    work_index = _build_work_index(result)

    for pf in series:
        # Default push (no -f/--merge) is the normal primitive. On
        # failure, fall back to `push -f --merge` ONLY to drive cascade
        # conflicts through the markers-and-pause UX (forced-merge → rc=1
        # markers → _resolve_or_raise → dev resolves → refresh).
        #
        # The forced-merge "clean success" path (rc=0, no markers) is NOT
        # legitimate here. Against quilt 0.66 it fires on two state-drift
        # cases, both silent data loss — so we crash, naming them:
        #   - Patch CREATEs a file that already exists: quilt "applies it
        #     anyway", silently replacing existing content.
        #   - Patch's hunks are already applied: quilt no-ops, and a later
        #     refresh diffs empty-vs-empty → empty patch.
        # Both mean series drift (hand-edit, corruption, branch mismerge).
        # A third cause would surface here too; characterise before
        # allowing it through.
        push_result = _run(["quilt", "push", pf, "--quiltrc=-"],
                           cwd=build_dir, env=quilt_env, check=False)
        if push_result.returncode != 0:
            merge_push = _run(
                ["quilt", "push", "-f", "--merge", pf, "--quiltrc=-"],
                cwd=build_dir, env=quilt_env, check=False)
            if merge_push.returncode == 0:
                # The state-drift case above — surface, don't absorb.
                # quilt's stdout names the sub-case ("applying it anyway"
                # vs "already applied"), so pass both attempts' tails through.
                push_stdout_tail = "\n".join((push_result.stdout or "").splitlines()[-20:])
                push_stderr_tail = "\n".join((push_result.stderr or "").splitlines()[-20:])
                merge_stdout_tail = "\n".join((merge_push.stdout or "").splitlines()[-20:])
                merge_stderr_tail = "\n".join((merge_push.stderr or "").splitlines()[-20:])
                raise RuntimeError(
                    f"quilt push {pf} refused but `quilt push -f --merge "
                    f"{pf}` succeeded without writing conflict markers. "
                    f"This pattern indicates patch-series state drift:\n"
                    f"  (1) {pf} creates a file that already exists — "
                    f"default push refuses to clobber; forced push "
                    f"would silently replace existing content with the "
                    f"patch's content.\n"
                    f"  (2) {pf}'s hunks are already applied to the "
                    f"working tree — default push refuses; forced push "
                    f"would no-op and a subsequent refresh would empty "
                    f"the patch.\n"
                    f"Inspect apps/<app>/patches/{pf} for inconsistency "
                    f"with the current series state.\n"
                    f"--- quilt push stdout ---\n{push_stdout_tail}\n"
                    f"--- quilt push stderr ---\n{push_stderr_tail}\n"
                    f"--- quilt push -f --merge stdout ---\n{merge_stdout_tail}\n"
                    f"--- quilt push -f --merge stderr ---\n{merge_stderr_tail}")
            # _resolve_or_raise (context="cascade"): markers → pause-and-
            # continue; no markers → raise (patch-level, or ghost-applied
            # when candidates are empty). See its docstring.
            candidate_files = _quilt_files(build_dir, quilt_env, pf)
            _resolve_or_raise(
                build_dir, pf, candidate_files,
                merge_push.stdout, merge_push.stderr,
                context="cascade")
            # Refresh captures the resolution into pf, and is mandatory:
            # quilt blocks the next push until the top is refreshed.
            _refresh_top_patch(build_dir, quilt_env, pf)

        # Apply assigned work (if any) to this patch.
        work = work_index.get(pf)
        if work and (work["hunks"] or work["new_files"] or work["delete_files"]):
            _apply_patch_work(work, build_dir, quilt_env, pf)

    # 5. New-patch loop. Any patch in work_index but not in series is a
    #    new patch to create (hunks, new files, and/or deletions, all
    #    merged into work_index by _build_work_index). With all existing
    #    patches applied, `quilt new` lands each above them in series.
    new_patches = [pf for pf in work_index.keys() if pf not in series_set]
    for pf in new_patches:
        # If quilt new fails there's no patch, hence no save — fatal.
        # main()'s catch handler emits the recover hint on raise.
        new_result = _run(["quilt", "new", pf, "--quiltrc=-"],
                          cwd=build_dir, env=quilt_env, check=False)
        if new_result.returncode != 0:
            stderr_tail = "\n".join((new_result.stderr or "").splitlines()[-20:])
            stdout_tail = "\n".join((new_result.stdout or "").splitlines()[-20:])
            raise RuntimeError(
                f"quilt new {pf} failed.\n"
                f"--- stdout ---\n{stdout_tail}\n"
                f"--- stderr ---\n{stderr_tail}")

        work = work_index.get(pf)
        if work:
            _apply_patch_work(work, build_dir, quilt_env, pf)

    # 6. Write overlay-targeted edits to the parent repo's overlay/.
    #    Bytes come from the pending commit; _show_pending failure is
    #    fatal, same as binary/new-file above (extract found these paths
    #    in `git diff baseline`, so they must be in pending).
    for f in result.overlay_copies:
        content = _show_pending(build_dir, f)
        dst = os.path.join(overlay_dir, f)
        os.makedirs(os.path.dirname(dst), exist_ok=True)
        with open(dst, "wb") as fh:
            fh.write(content)

    for f in result.new_overlay_files:
        content = _show_pending(build_dir, f)
        dst = os.path.join(overlay_dir, f)
        os.makedirs(os.path.dirname(dst), exist_ok=True)
        with open(dst, "wb") as fh:
            fh.write(content)

    # OverlayDeletion targets cover three on-disk shapes in overlay/:
    #   - leaf file (regular)        → unlink
    #   - dir-tree overlay (real dir) → rmtree
    #   - symlink-as-overlay-entry   → unlink the link
    # islink check FIRST is load-bearing correctness: os.path.isdir
    # follows symlinks, so "if isdir: rmtree" would rmtree the symlink's
    # target. Order forces "remove the link, not the target". (rmtree on
    # a real overlay dir is recoverable via `git checkout` in apps/<app>/,
    # the dev's own git, not platform state.)
    for f in result.deleted_overlay_files:
        dst = os.path.join(overlay_dir, f)
        if os.path.islink(dst):
            os.unlink(dst)
        elif os.path.isdir(dst):
            shutil.rmtree(dst)
        elif os.path.exists(dst):
            os.unlink(dst)
        else:
            # Extract saw overlay/<f> present, so its absence now is
            # state drift between extract and write (concurrent mutation
            # despite the lock, or an earlier step this save removed it).
            # Surface rather than no-op — the gesture didn't land.
            raise RuntimeError(
                f"OverlayDeletion target {dst!r} is missing in all three "
                f"shapes (link/dir/exists). Extract identified it as "
                f"present; absence here is state drift.")

    # OverlayRevert: restore from upstream. Two steps per item:
    #   1. Remove the overlay override at overlay/<path>. Extract emits
    #      OverlayRevert only when upstream/<path> is a regular file
    #      (os.path.isfile in quilt_extract.py), so the overlay side is a
    #      leaf file or symlink → unlink. A real dir (unhandled shape) or a
    #      missing target (extract/write drift) raises a clear diagnostic,
    #      matching the OverlayDeletion handler — not a bare traceback.
    #   2. Copy upstream/<path> → .build/<path>. shutil.copy2 preserves
    #      mode+timestamps, matching `rsync -a` in MODE=force (verified
    #      against the differential check). makedirs covers the rare case
    #      where the parent was also overlay-deleted earlier this save.
    # Order matters: must run BEFORE link-overlay (verify_and_finalize),
    # so link-overlay sees overlay/<path> gone and skips the symlink,
    # leaving upstream content for write_baseline to capture.
    # copy2 not git-show: upstream/ is a submodule with its own pinned
    # HEAD, so staying in the host fs keeps this local + simple. copy2
    # follows symlinks by default; apps/mail upstream has none and any
    # future divergence surfaces in the differential check.
    for f in result.reverted_overlay_files:
        overlay_path = os.path.join(overlay_dir, f)
        upstream_path = os.path.join(upstream_dir, f)
        build_path = os.path.join(build_dir, f)
        if os.path.islink(overlay_path) or os.path.isfile(overlay_path):
            os.unlink(overlay_path)
        elif os.path.isdir(overlay_path):
            raise RuntimeError(
                f"OverlayRevert target {overlay_path!r} is a directory; "
                f"extract reverts regular files only — unhandled overlay shape.")
        else:
            raise RuntimeError(
                f"OverlayRevert target {overlay_path!r} is missing; extract "
                f"identified it as present, so absence here is state drift.")
        os.makedirs(os.path.dirname(build_path), exist_ok=True)
        shutil.copy2(upstream_path, build_path)
