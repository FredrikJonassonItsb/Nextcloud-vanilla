"""Extract hunks from git diff output.

Each text Hunk is a data object with:
    .file       - relative file path (e.g. "lib/Controller/PageController.php")
    .header     - the @@ line (e.g. "@@ -10,6 +10,7 @@ function foo()")
    .content    - the diff lines (context + adds + deletes), including the @@ line
    .file_header - the full --- / +++ header for this file
    .type       - one of:
                    "modified"       — content change to an existing file
                    "new"            — new text file added
                    "deleted"        — text file removed
                    "binary"         — content change to a binary file
                    "binary_deleted" — binary file removed
    .patches    - list of existing patch names that touch this file

Non-hunk items (OverlayCopy, NewFile, NewOverlayDir, DeletedFile,
OverlayDeletion, OverlayRevert, PermissionChange) carry just .file
(or .files for NewOverlayDir). See each class below.
"""

import os
import re

from quilt_common import assert_in_builder, git_env, run as _run


class Hunk:
    __slots__ = ("file", "header", "content", "file_header", "type", "patches")

    def __init__(self, file, header, content, file_header, hunk_type="modified", patches=None):
        self.file = file
        self.header = header
        self.content = content
        self.file_header = file_header
        self.type = hunk_type
        self.patches = patches or []

    def __repr__(self):
        lines = self.content.count("\n")
        return f"Hunk({self.file!r}, {self.type}, {lines} lines, patches={self.patches})"


class OverlayCopy:
    """An overlay-copyfile that was modified in .build/."""
    __slots__ = ("file",)

    def __init__(self, file):
        self.file = file

    def __repr__(self):
        return f"OverlayCopy({self.file!r})"


class NewFile:
    """An untracked file in .build/ not from overlay or upstream."""
    __slots__ = ("file", "content")

    def __init__(self, file, content=None):
        self.file = file
        self.content = content

    def __repr__(self):
        return f"NewFile({self.file!r})"


class NewOverlayDir:
    """A group of new files under a directory not in upstream.

    When the user picks 'overlay' for this, the entire directory
    is created in overlay/ and link-overlay makes a directory symlink.
    """
    __slots__ = ("dir", "files")

    def __init__(self, dir, files):
        self.dir = dir
        self.files = files  # list of relative paths (from repo root)

    def __repr__(self):
        return f"NewOverlayDir({self.dir!r}, {len(self.files)} files)"


class DeletedFile:
    """A patch-created file deleted in .build/."""
    __slots__ = ("file", "patches")

    def __init__(self, file, patches=None):
        self.file = file
        self.patches = patches or []

    def __repr__(self):
        return f"DeletedFile({self.file!r}, patches={self.patches})"


class OverlayDeletion:
    """An overlay file deleted in .build/ with NO upstream counterpart.

    Confirming this on the dev's prompt removes the file from overlay/
    only — there's nothing to fall back to. Used for ITSL-only overlay
    files (e.g. tests/phpunit.itsl.xml) and dir-tree overlays that
    have no upstream analog (e.g. src/itsl/).
    """
    __slots__ = ("file",)

    def __init__(self, file):
        self.file = file

    def __repr__(self):
        return f"OverlayDeletion({self.file!r})"


class OverlayRevert:
    """An overlay file deleted in .build/ with a regular-file upstream
    counterpart.

    Confirming this on the dev's prompt removes the override from
    overlay/ AND restores upstream's version into .build/. Semantic is
    "stop overriding upstream — fall back to upstream's content," NOT
    "delete the file entirely."

    Discriminated from OverlayDeletion in extract_hunks; see the isfile
    rationale at that call site.
    """
    __slots__ = ("file",)

    def __init__(self, file):
        self.file = file

    def __repr__(self):
        return f"OverlayRevert({self.file!r})"


class PermissionChange:
    """A file with only permission changes (no content diff)."""
    __slots__ = ("file",)

    def __init__(self, file):
        self.file = file

    def __repr__(self):
        return f"PermissionChange({self.file!r})"


def _parse_diff_output(diff_text):
    """Parse unified diff text into Hunk objects.

    Splits on file boundaries (^diff --git) and hunk boundaries (^@@ -\\d).
    All content is preserved as-is — no interpretation of +/- lines.
    """
    hunks = []
    if not diff_text or not diff_text.strip():
        return hunks

    file_sections = re.split(r'^(diff --git .+)$', diff_text, flags=re.MULTILINE)

    # file_sections[0] is empty (before first diff --git), then alternating
    # [diff_line, content, diff_line, content, ...] — hence start at 1, stride 2.
    i = 1
    while i < len(file_sections):
        diff_line = file_sections[i]
        section = file_sections[i + 1] if i + 1 < len(file_sections) else ""
        i += 2

        m = re.match(r'^diff --git a/(.+) b/(.+)$', diff_line)
        if not m:
            # The line starts with `diff --git ` (the outer split ensured it).
            # The strict a/.. b/.. match relies on git_env's core.quotePath=false
            # (raw UTF-8 paths, not octal-escaped + double-quoted) — without it
            # a non-ASCII filename would arrive as `"a/caf\303\251.txt"` and
            # fail to match. With it, the match fails only under diff.noprefix /
            # mnemonicPrefix (never set — .build/.git is platform-managed) or a
            # filename containing a literal `"`. Crash rather than silently
            # drop: a drop loses this file's changes.
            raise RuntimeError(
                f"unparseable 'diff --git' line in git diff output "
                f"(.build/.git should always emit a/ b/ prefixes): "
                f"{diff_line!r}")
        # For a non-rename git emits `a/PATH b/PATH` (identical sides). The
        # greedy split above mis-places the a/b boundary when a path component
        # ends in " b" (a dir named "a b" → `diff --git a/a b/x b/a b/x`); the
        # a==b backreference forces the correct split. A true rename (a != b)
        # doesn't match it — fall back to the greedy b/ side (the new path),
        # which is only used in the rename raise below (renames never get past
        # the no-hunk branch).
        m_same = re.match(r'^diff --git a/(.+) b/\1$', diff_line)
        file_path = m_same.group(1) if m_same else m.group(2)

        # Mode (new / deleted) and binary status are ORTHOGONAL — a binary
        # file can be created, modified, or deleted. Detect independently so
        # binary-deleted stays distinct from binary-modified: collapsing both
        # to hunk_type="binary" lets the path slip the already_handled
        # `type=="deleted"` filter, so the second loop re-emits it as a
        # deletion → duplicate prompt + write_patches crash on
        # `git show pending:<path>` for a file deleted in pending.
        is_binary = bool(re.search(r'^Binary files ', section, re.MULTILINE))
        is_new_mode = bool(re.search(r'^new file mode', section, re.MULTILINE))
        is_deleted_mode = bool(re.search(r'^deleted file mode', section, re.MULTILINE))

        if is_binary:
            # Binary diffs carry no text content — git emits one
            # `Binary files X and Y differ` line instead of @@/+/- hunks.
            # file_header spans `diff --git` through that line so downstream
            # can extract the path and detect deletion via `deleted file mode`.
            bin_header_lines = [diff_line]
            for bl in section.split("\n"):
                bin_header_lines.append(bl)
                if bl.startswith("Binary files "):
                    break
            hunk_type = "binary_deleted" if is_deleted_mode else "binary"
            hunks.append(Hunk(
                file=file_path,
                header="",
                content="",
                file_header="\n".join(bin_header_lines) + "\n",
                hunk_type=hunk_type))
            continue

        if is_new_mode:
            hunk_type = "new"
        elif is_deleted_mode:
            hunk_type = "deleted"
        else:
            hunk_type = "modified"

        file_header_lines = []
        remaining_lines = section.split("\n")
        hunk_start_idx = None
        for idx, line in enumerate(remaining_lines):
            if re.match(r'^@@ -\d', line):
                hunk_start_idx = idx
                break
            file_header_lines.append(line)

        if hunk_start_idx is None:
            # No @@ hunks. The no-hunk shapes `git diff baseline` produces,
            # and how each is handled:
            #   - pure mode/permission change (old mode / new mode) →
            #     PermissionChange.
            #   - empty-file deletion: an empty file has no content to diff, so
            #     `deleted file mode` arrives with no @@. Skip here; the
            #     --diff-filter=D sweep in extract_hunks recovers it uniformly
            #     (DeletedFile / OverlayDeletion / OverlayRevert) — the same
            #     path every structurally-missed deletion takes.
            # A rename/copy (similarity index + rename/copy from/to) reaches
            # the diff only when the target is staged or git-mv'd, and a staged
            # new-empty file (new file mode, no @@) likewise needs staging —
            # neither happens in the make-quilt flow (the dev never stages in
            # .build/; new files arrive untracked via ls-files --others). Both
            # are unexpected: raise with the cause rather than silently drop a
            # real change.
            if re.search(r'^old mode|^new mode', section, re.MULTILINE):
                hunks.append(PermissionChange(file=file_path))
                continue
            if is_deleted_mode:
                # Empty-file deletion — recovered by the --diff-filter=D sweep.
                continue
            if re.search(r'^similarity index |^rename from |^copy from ',
                         section, re.MULTILINE):
                raise RuntimeError(
                    f"rename/copy of {file_path!r} in `git diff baseline` — "
                    f"unexpected: the make-quilt flow never stages or git-mv's "
                    f"in .build/.\nSection:\n{section}")
            raise RuntimeError(
                f"unhandled diff section for {file_path!r}: no @@ hunk, not a "
                f"mode change or empty-file deletion. Section:\n{section}")

        file_header = diff_line + "\n" + "\n".join(file_header_lines) + "\n"

        hunk_text = "\n".join(remaining_lines[hunk_start_idx:])
        hunk_parts = re.split(r'^(@@ -\d[^\n]*@@[^\n]*)', hunk_text, flags=re.MULTILINE)

        # hunk_parts: [maybe_empty, @@_line, content, ...] — start at 1, stride 2.
        j = 1
        while j < len(hunk_parts):
            header = hunk_parts[j]
            content_after = hunk_parts[j + 1] if j + 1 < len(hunk_parts) else ""
            j += 2

            full_content = header + content_after
            # Strip trailing empty line if present (from split artifacts)
            if full_content.endswith("\n\n"):
                full_content = full_content[:-1]

            hunks.append(Hunk(
                file=file_path,
                header=header,
                content=full_content,
                file_header=file_header,
                hunk_type=hunk_type))

    return hunks


def _get_patch_file_map(patches_dir):
    """Build a map of {file_path: [patch_names]} from existing quilt patches."""
    patch_files = {}
    series_path = os.path.join(patches_dir, "series")
    if not os.path.exists(series_path):
        return patch_files

    with open(series_path, "r") as f:
        patches = [line.strip() for line in f if line.strip() and not line.startswith("#")]

    for patch_name in patches:
        patch_path = os.path.join(patches_dir, patch_name)
        if not os.path.exists(patch_path):
            # A dangling series entry (patch file deleted, series line left)
            # is a series/patches inconsistency — but validating that is not
            # this attribution map-builder's job. quilt_v2.main()'s
            # _check_series_patches_consistent prereq catches it before extract
            # runs, so with that in place this branch is unreachable in the
            # make-quilt flow; skip the absent patch for best-effort
            # attribution rather than building a partial map by raising here.
            continue
        with open(patch_path, "r", encoding="utf-8", errors="surrogateescape") as f:
            patch_content = f.read()
        # Files in this patch. Quilt prefixes: a/ b/ .build/ .build.orig/.
        # Scan both +++ and --- sides: `+++ b/<path>` covers added + modified
        # files; deletions carry `+++ /dev/null`, so the path is only on the
        # `--- a/<path>` side. /dev/null lines fail the prefix and are skipped;
        # modified files match both sides and dedupe below. Without the ---
        # scan, `make diff` leaves delete-only patches unattributed.
        for prefix in (r'^\+\+\+ ', r'^--- '):
            for m in re.finditer(prefix + r'(?:[ab]/|\.build(?:\.orig)?/)(.+?)(?:\t.*)?$', patch_content, re.MULTILINE):
                file_path = m.group(1)
                name = patch_name.removesuffix(".patch")
                patch_files.setdefault(file_path, [])
                if name not in patch_files[file_path]:
                    patch_files[file_path].append(name)

    return patch_files



def _get_overlay_copyfiles(repo_root):
    """Read overlay-copyfiles.d/* fragments from the app repo."""
    result = set()
    d_dir = os.path.join(repo_root, "overlay-copyfiles.d")
    if not os.path.isdir(d_dir):
        return result
    for f in sorted(os.listdir(d_dir)):
        if f.startswith("."):
            continue
        fpath = os.path.join(d_dir, f)
        if not os.path.isfile(fpath):
            continue
        with open(fpath, "r") as fh:
            for line in fh:
                line = line.strip()
                if line and not line.startswith("#"):
                    result.add(line)
    return result


def _parent_is_overlay_symlink(filepath, build_dir, overlay_dir):
    """Check if any parent directory of filepath is a symlink to overlay/."""
    parts = filepath.split("/")
    for i in range(1, len(parts)):
        parent = os.path.join(build_dir, *parts[:i])
        if os.path.islink(parent):
            target = os.readlink(parent)
            if not os.path.isabs(target):
                target = os.path.normpath(os.path.join(os.path.dirname(parent), target))
            if target.startswith(overlay_dir + "/") or target == overlay_dir:
                return True
    return False


def _find_overlay_dir(filepath, upstream_dir):
    """Find the highest new parent directory not in upstream.

    'lib/itsl/Foo.php', lib/ in upstream but itsl/ not → 'lib/itsl'.
    'src/existing.js', src/ in upstream → None (file-level overlay, not
    directory-level).
    """
    parts = filepath.split("/")
    cut = None
    for i in range(len(parts) - 1):  # exclude the filename itself
        check_path = os.path.join(upstream_dir, *parts[:i + 1])
        if not os.path.isdir(check_path):
            cut = i
            break
    if cut is not None:
        return "/".join(parts[:cut + 1])
    return None


def _is_overlay_symlink(filepath, build_dir, overlay_dir):
    """Check if a file in .build/ is a symlink pointing to overlay/."""
    full = os.path.join(build_dir, filepath)
    if os.path.islink(full):
        target = os.readlink(full)
        # Normalize relative targets
        if not os.path.isabs(target):
            target = os.path.normpath(os.path.join(os.path.dirname(full), target))
        return target.startswith(overlay_dir + "/") or target == overlay_dir
    return False


def extract_hunks(repo_root, env=None):
    """Extract all changes from .build/ as a list of typed item objects.

    Args:
        repo_root: path to the app root (upstream/, patches/, overlay/, .build/).
        env: environment dict for git commands (needs safe.directory='*'
            to operate on bind-mounted .build/.git). Defaults to
            quilt_common.git_env() if not supplied.

    Returns:
        list of Hunk / OverlayCopy / NewFile / NewOverlayDir /
        DeletedFile / OverlayDeletion / OverlayRevert / PermissionChange
        objects.
    """
    assert_in_builder()
    build_dir = os.path.join(repo_root, ".build")
    overlay_dir = os.path.join(repo_root, "overlay")
    upstream_dir = os.path.join(repo_root, "upstream")
    patches_dir = os.path.join(repo_root, "patches")

    if env is None:
        env = git_env()

    result = _run(
        ["git", "diff", "baseline"],
        cwd=build_dir, env=env, check=False)
    if result.returncode != 0:
        raise RuntimeError(f"git diff failed: {result.stderr}")

    hunks = _parse_diff_output(result.stdout)

    copyfiles = _get_overlay_copyfiles(repo_root)
    patch_map = _get_patch_file_map(patches_dir)

    results = []
    overlay_copy_files_seen = set()

    for hunk in hunks:
        # PermissionChange objects pass through without annotation
        if isinstance(hunk, PermissionChange):
            if not _is_overlay_symlink(hunk.file, build_dir, overlay_dir):
                results.append(hunk)
            continue

        # Skip overlay symlinks (edits flow through symlinks to overlay/)
        if _is_overlay_symlink(hunk.file, build_dir, overlay_dir):
            continue

        if hunk.file in copyfiles:
            if hunk.file not in overlay_copy_files_seen:
                overlay_copy_files_seen.add(hunk.file)
                results.append(OverlayCopy(file=hunk.file))
            continue

        # Classify deletions on overlay-managed paths, discriminated by
        # upstream-counterpart presence:
        #   overlay/ exists AND upstream/ is a regular file ⇒ OverlayRevert
        #   overlay/ exists, upstream/ absent OR not a file ⇒ OverlayDeletion
        #   no overlay/ counterpart ⇒ fall through to normal Hunk handling.
        # isfile, not exists, on upstream: OverlayRevert's write path
        # (quilt_write.py) calls shutil.copy2, which fails on directories.
        # Gating on upstream-isfile routes the dir+dir edge case (overlay-dir
        # shadowing upstream-dir at the same path) to OverlayDeletion, whose
        # three-shape handler (islink/isdir/exists) covers any overlay shape.
        # text-deleted and binary-deleted both route here: the dev's gesture
        # (rm .build/<x>) and the write-side handlers are bytes-agnostic.
        if hunk.type in ("deleted", "binary_deleted"):
            overlay_path = os.path.join(overlay_dir, hunk.file)
            if os.path.exists(overlay_path):
                upstream_path = os.path.join(upstream_dir, hunk.file)
                if os.path.isfile(upstream_path):
                    results.append(OverlayRevert(file=hunk.file))
                else:
                    results.append(OverlayDeletion(file=hunk.file))
                continue
            # Fall through to normal hunk handling.

        hunk.patches = patch_map.get(hunk.file, [])
        results.append(hunk)

    # Untracked new files, grouped by overlay directory. ls-files cannot
    # legitimately fail here (.build/.git exists per the upstream prereq
    # check, safe.directory configured); a non-zero rc is a real failure to
    # surface, not a cue to silently drop all untracked-file detection.
    new_result = _run(
        ["git", "ls-files", "--others", "--exclude-standard"],
        cwd=build_dir, env=env, check=False)
    if new_result.returncode != 0:
        raise RuntimeError(
            f"git ls-files --others failed (rc={new_result.returncode}): "
            f"{(new_result.stderr or new_result.stdout or '').strip()}")

    overlay_dir_groups = {}  # {overlay_dir: [file_path, ...]}
    standalone_new = []      # files in existing upstream dirs

    for f in new_result.stdout.strip().split("\n"):
        f = f.strip()
        if not f:
            continue
        if _is_overlay_symlink(f, build_dir, overlay_dir):
            continue
        if f in copyfiles:
            continue

        # Skip files inside directory symlinks to overlay (already in overlay)
        if _parent_is_overlay_symlink(f, build_dir, overlay_dir):
            continue

        ovl_dir = _find_overlay_dir(f, upstream_dir)
        if ovl_dir:
            overlay_dir_groups.setdefault(ovl_dir, []).append(f)
        else:
            standalone_new.append(f)

    for dir_path in sorted(overlay_dir_groups):
        results.append(NewOverlayDir(
            dir=dir_path, files=sorted(overlay_dir_groups[dir_path])))

    # standalone NewFile: parent dirs exist in upstream (vs NewOverlayDir)
    for f in standalone_new:
        results.append(NewFile(file=f))

    # Deletions already come through as type="deleted" hunks; this is a
    # second, explicit sweep for entirely-removed files. Same
    # crash-on-failure as the ls-files call: git diff cannot legitimately
    # fail here.
    deleted_result = _run(
        ["git", "diff", "--name-only", "--diff-filter=D", "baseline"],
        cwd=build_dir, env=env, check=False)
    if deleted_result.returncode != 0:
        raise RuntimeError(
            f"git diff --name-only --diff-filter=D baseline failed "
            f"(rc={deleted_result.returncode}): "
            f"{(deleted_result.stderr or deleted_result.stdout or '').strip()}")

    # This sweep catches deletions the diff-parse path missed structurally.
    # already_handled dedups against deletions already emitted:
    #   - OverlayDeletion/OverlayRevert are always deletions → include
    #     unconditionally.
    #   - Hunks only when type=="deleted"/"binary_deleted", so a
    #     binary-modified Hunk can't suppress an unrelated deletion entry.
    already_handled = {h.file for h in results
                      if isinstance(h, (Hunk, OverlayDeletion, OverlayRevert)) and
                      (not isinstance(h, Hunk) or h.type in ("deleted", "binary_deleted"))}
    for f in deleted_result.stdout.strip().split("\n"):
        f = f.strip()
        if not f or f in already_handled:
            continue
        overlay_path = os.path.join(overlay_dir, f)
        if os.path.exists(overlay_path):
            # Same isfile-not-exists classification as the diff-loop branch
            # above (rationale there); the two sites must stay uniform.
            upstream_path = os.path.join(upstream_dir, f)
            if os.path.isfile(upstream_path):
                results.append(OverlayRevert(file=f))
            else:
                results.append(OverlayDeletion(file=f))
        else:
            results.append(DeletedFile(file=f, patches=patch_map.get(f, [])))

    return results
