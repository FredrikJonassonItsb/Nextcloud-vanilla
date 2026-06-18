"""Interactive hunk-by-hunk presentation for make quilt v2.

Takes extract_hunks() output, walks each item asking the user what to do,
returns a PresentResult with all assignments — no files are written.
Entry point: present(extract_hunks(repo_root), patches_series, repo_root).
Result-field shapes are documented on PresentResult's __init__.
"""

import os
import re
import subprocess
import sys
import shutil

from quilt_common import extract_file_path
from quilt_extract import Hunk, OverlayCopy, NewFile, NewOverlayDir, DeletedFile, OverlayDeletion, OverlayRevert, PermissionChange

# ---------------------------------------------------------------------------
# ANSI color helpers
# ---------------------------------------------------------------------------

_USE_COLOR = sys.stdout.isatty() and os.environ.get("NO_COLOR") is None
_IS_TTY = sys.stdout.isatty()

_SYM_CHECK = "\u2713"      # ✓
_SYM_DASH = "\u2500"       # ─
_SYM_HEAVY = "\u2501"      # ━
_SYM_ARROW = "\u2192"      # →
_SYM_DOT = "\u00b7"        # ·
_SYM_TRI = "\u25b8"        # ▸


def _color(code, text):
    return f"\033[{code}m{text}\033[0m" if _USE_COLOR else text

def _red(text):      return _color("31", text)
def _yellow(text):   return _color("33", text)
def _green(text):    return _color("32", text)
def _bold(text):     return _color("1", text)
def _cyan(text):     return _color("36", text)
def _dim(text):      return _color("2", text)
def _dim_cyan(text): return _color("2;36", text)

def _pi():
    """Prompt indicator."""
    return _color("1;35", _SYM_TRI) if _USE_COLOR else ">"


def _collapse(num_lines, replacement):
    """Replace last num_lines with a single line. Falls back to print."""
    if _IS_TTY and num_lines > 0:
        sys.stdout.write(f"\033[{num_lines}A")
        for _ in range(num_lines):
            sys.stdout.write("\033[2K\n")
        sys.stdout.write(f"\033[{num_lines}A")
    print(replacement)


# ---------------------------------------------------------------------------
# Result object
# ---------------------------------------------------------------------------

class PresentResult:
    """Result of the interactive presentation."""
    __slots__ = ("assignments", "overlay_copies", "new_overlay_files",
                 "new_patch_files", "deleted_files", "deleted_overlay_files",
                 "reverted_overlay_files", "skipped", "aborted")

    def __init__(self):
        self.assignments = {}      # {patch_name: [(file_header, hunk_content), ...]}
        self.overlay_copies = []   # [file_path, ...]
        self.new_overlay_files = [] # [file_path, ...]
        self.new_patch_files = {}  # {patch_name: [file_path, ...]}
        self.deleted_files = {}    # {patch_name: [file_path, ...]}
        self.deleted_overlay_files = []   # [file_path, ...] — removed from overlay/, no upstream fallback
        self.reverted_overlay_files = []  # [file_path, ...] — overlay override removed, upstream restored
        self.skipped = []          # [item, ...]
        self.aborted = False

    def add_hunk(self, patch_name, file_header, hunk_content):
        self.assignments.setdefault(patch_name, [])
        self.assignments[patch_name].append((file_header, hunk_content))

    def total_assigned(self):
        return (sum(len(hunks) for hunks in self.assignments.values())
                + sum(len(files) for files in self.deleted_files.values())
                + sum(len(files) for files in self.new_patch_files.values()))

    def summary_lines(self):
        """Return summary lines (per-patch hunk counts + filenames) for display."""
        lines = []
        for patch, hunks in self.assignments.items():
            files = sorted({
                os.path.basename(extract_file_path(h[0]) or "?")
                for h in hunks
            })
            n = len(hunks)
            hs = "hunk" if n == 1 else "hunks"
            file_list = ", ".join(files)
            lines.append((patch, f"{n} {hs}", file_list))
        return lines


# ---------------------------------------------------------------------------
# Input helpers
# ---------------------------------------------------------------------------

def _ask_yn(prompt, default=True):
    suffix = "[Y/n]" if default else "[y/N]"
    while True:
        try:
            ans = input(f"  {_pi()} {prompt} {suffix}: ").strip().lower()
        except (EOFError, KeyboardInterrupt):
            print()
            return None  # signal abort
        if not ans:
            return default
        if ans in ("y", "yes"):
            return True
        if ans in ("n", "no"):
            return False


def _ask_patch(series, touched=None, last_patch=None):
    """Ask which patch to assign a hunk to.

    Returns: (choice, lines_printed)
    choice is one of: patch_name, "new", "skip", "done", "abort"
    """
    touched_set = set(touched) if touched else set()

    if not series:
        print(_dim("    (no existing patches)"))
    for i, p in enumerate(series):
        mark = f" {_dim('(this file)')}" if p in touched_set else ""
        print(f"    [{i+1}] {_cyan(p)}{mark}")

    if series:
        assign = f"[1{'-' + str(len(series)) if len(series) > 1 else ''}] or [n]ew"
    else:
        assign = "[n]ew patch"
    nav_parts = ["[s]kip", "[w]rite & exit", "[q]uit", "[?]"]
    if last_patch is not None:
        nav_parts.insert(0, f"[!] all {_SYM_ARROW} {last_patch}")
    nav = "  ".join(nav_parts)
    opts = f"{assign}  |  {nav}"

    lines_printed = max(len(series), 1) + 1  # patch list + prompt line

    while True:
        try:
            choice = input(f"  {_pi()} Add to: {opts}\n  > ").strip().lower()
        except (EOFError, KeyboardInterrupt):
            print()
            return ("abort", lines_printed)
        lines_printed += 1
        if not choice:
            continue

        if choice == "?":
            print(_dim("  " + _SYM_DASH * 40))
            if series:
                print(_dim(f"  1-{len(series)} - save this change to an existing patch"))
            print(_dim("    n - create a new patch for this change"))
            print(_dim("    s - leave this change unsaved for now"))
            if last_patch is not None:
                print(_dim(f"    ! - save everything remaining to {last_patch}"))
            print(_dim("    w - done — save chosen patches and finish"))
            print(_dim("    q - quit without saving"))
            print(_dim("    ? - show this help"))
            lines_printed += 6 + (1 if series else 0) + (1 if last_patch else 0)
            continue
        if choice == "n":
            return ("new", lines_printed)
        if choice == "s":
            return ("skip", lines_printed)
        if choice in ("w", "write"):
            return ("done", lines_printed)
        if choice in ("q", "quit"):
            return ("abort", lines_printed)
        if choice == "!" and last_patch is not None:
            return ("all_remaining", lines_printed)
        try:
            idx = int(choice) - 1
            if 0 <= idx < len(series):
                return (series[idx], lines_printed)
        except ValueError:
            pass
        for p in series:
            if choice == p:
                return (p, lines_printed)
        print(f"  {_dim('Type a number, n, s, w, q, or ? for help')}")
        lines_printed += 1


def _ask_new_patch_name(series):
    """Ask for a new patch name.

    Returns (name, lines_printed); name is None on abort. lines_printed
    is how many lines this prompt put on screen (the Name: line each
    iteration, plus a validation/duplicate message when one fires). The
    caller adds it to its _collapse count: _ask_patch only counts its own
    output, so without this the picker's first list line is stranded above
    the confirmation.
    """
    lines_printed = 0
    while True:
        try:
            name = input(f"  {_pi()} Name: ").strip()
        except (EOFError, KeyboardInterrupt):
            print()
            return (None, lines_printed)
        lines_printed += 1  # the Name: line just consumed
        if not name:
            continue
        patch = name.lower().replace(" ", "-")
        if patch.endswith(".patch"):
            patch = patch[:-6]
        if not re.match(r'^[a-z0-9][a-z0-9._-]*$', patch):
            print(f"  '{patch}' is not a valid name. Use a-z, 0-9, dots, hyphens.")
            lines_printed += 1
            continue
        if patch in series:
            print(f"  A patch named '{patch}' already exists. Choose another name.")
            lines_printed += 1
            continue
        return (patch, lines_printed)


# ---------------------------------------------------------------------------
# Display helpers
# ---------------------------------------------------------------------------

def _patches_suffix(patches):
    """' · patchA, patchB' annotation naming the patches that already touch an
    item (Hunk / DeletedFile), or '' when none do."""
    if not patches:
        return ""
    return f" {_SYM_DOT} " + ", ".join(_dim_cyan(p) for p in patches)


def _show_hunk(item, index, total, repo_root):
    """Display a hunk's content with context."""
    sep = _SYM_DASH * min(shutil.get_terminal_size().columns, 60)

    if index > 0:
        print(f"\n{_dim(sep)}")

    progress = _dim(f"[{index+1}/{total}]")

    if isinstance(item, Hunk):
        patch_str = _patches_suffix(item.patches)
        type_str = ""
        if item.type == "new":
            type_str = f"  {_dim('new file')}"
        elif item.type == "deleted":
            type_str = f"  {_dim('deleted')}"
        elif item.type == "binary":
            type_str = f"  {_dim('binary')}"
        elif item.type == "binary_deleted":
            type_str = f"  {_dim('binary deleted')}"

        print(f"  {progress} {_bold(item.file)}{type_str}{patch_str}")

        if item.content:
            print()
            for line in item.content.split("\n"):
                if line.startswith("+") and not line.startswith("+++"):
                    print(f"  {_green(line)}")
                elif line.startswith("-") and not line.startswith("---"):
                    print(f"  {_red(line)}")
                elif line.startswith("@@"):
                    print(f"  {_cyan(line)}")
                else:
                    print(f"  {_dim(line)}")
            print()

    elif isinstance(item, OverlayCopy):
        print(f"  {progress} {_bold(item.file)}  {_dim('overlay copy')}")
        overlay_path = os.path.join(repo_root, "overlay", item.file)
        build_path = os.path.join(repo_root, ".build", item.file)
        if os.path.exists(overlay_path) and os.path.exists(build_path):
            color_arg = ["--color=always"] if _USE_COLOR else []
            diff_result = subprocess.run(
                ["diff", "-u", *color_arg, overlay_path, build_path],
                capture_output=True, text=True)
            if diff_result.stdout:
                print()
                for line in diff_result.stdout.split("\n"):
                    print(f"  {line}")
        print()

    elif isinstance(item, NewFile):
        print(f"  {progress} {_bold(item.file)}  {_dim('new file')}")
        print()

    elif isinstance(item, NewOverlayDir):
        n = len(item.files)
        fs = "file" if n == 1 else "files"
        print(f"  {progress} {_bold(item.dir + '/')}  {_dim(f'new directory ({n} {fs})')}")
        for f in item.files:
            print(f"    {_dim(f)}")
        print()

    elif isinstance(item, OverlayDeletion):
        print(f"  {progress} {_bold(item.file)}  {_dim('overlay file removed from .build/')}")
        print()

    elif isinstance(item, OverlayRevert):
        print(f"  {progress} {_bold(item.file)}  {_dim('overlay file removed from .build/ — upstream has this file')}")
        print()

    elif isinstance(item, DeletedFile):
        patch_str = _patches_suffix(item.patches)
        print(f"  {progress} {_bold(item.file)}  {_dim('deleted')}{patch_str}")
        print()


# ---------------------------------------------------------------------------
# Main presentation loop
# ---------------------------------------------------------------------------

def _route_remaining_to_patch(remaining_items, result, last_patch):
    """Route every item to last_patch (or to its overlay-target list).

    Backs the "all remaining → last patch" shortcut. OverlayCopy /
    OverlayDeletion / OverlayRevert are NOT patch-routable (filesystem
    ops on overlay/, not quilt patches) — they go to their overlay lists,
    not last_patch. PermissionChange can't appear: present() filters it
    out before the loop, so there's no branch for it here.
    """
    for remaining in remaining_items:
        if isinstance(remaining, Hunk):
            result.add_hunk(last_patch, remaining.file_header, remaining.content)
        elif isinstance(remaining, OverlayCopy):
            result.overlay_copies.append(remaining.file)
        elif isinstance(remaining, (NewFile, NewOverlayDir)):
            files = (remaining.files if isinstance(remaining, NewOverlayDir)
                     else [remaining.file])
            result.new_patch_files.setdefault(last_patch, []).extend(files)
        elif isinstance(remaining, DeletedFile):
            result.deleted_files.setdefault(last_patch, []).append(remaining.file)
        elif isinstance(remaining, OverlayDeletion):
            result.deleted_overlay_files.append(remaining.file)
        elif isinstance(remaining, OverlayRevert):
            result.reverted_overlay_files.append(remaining.file)


def present(items, series, repo_root):
    """Walk through items interactively, collecting assignments.

    Args:
        items: list from extract_hunks()
        series: list of existing patch names (without .patch suffix)
        repo_root: path to app root (upstream/, patches/, overlay/, .build/);
            used by _show_hunk for the OverlayCopy diff.

    Returns:
        PresentResult with assignments, overlay_copies, etc.
    """
    result = PresentResult()
    series = list(series)  # mutable copy — new patches get appended

    if not items:
        print("No changes found.")
        return result

    hunks = [i for i in items if isinstance(i, Hunk)]
    overlays = [i for i in items if isinstance(i, OverlayCopy)]
    new_files = [i for i in items if isinstance(i, NewFile)]
    new_dirs = [i for i in items if isinstance(i, NewOverlayDir)]
    deleted = [i for i in items if isinstance(i, DeletedFile)]
    overlay_dels = [i for i in items if isinstance(i, OverlayDeletion)]
    overlay_revs = [i for i in items if isinstance(i, OverlayRevert)]
    perm_changes = [i for i in items if isinstance(i, PermissionChange)]

    # Permission-only changes can't be saved as patches.
    if perm_changes:
        for pc in perm_changes:
            print(f"  {_dim(f'Only file permissions changed on {pc.file} (not content). Skipping.')}")
        # Remove from items so they don't appear in the loop
        items = [i for i in items if not isinstance(i, PermissionChange)]

    total = len(items)
    parts = []
    if hunks:
        parts.append(f"{len(hunks)} hunk{'s' if len(hunks) != 1 else ''}")
    if overlays:
        parts.append(f"{len(overlays)} overlay")
    if new_files:
        parts.append(f"{len(new_files)} new")
    if new_dirs:
        total_new_dir_files = sum(len(d.files) for d in new_dirs)
        parts.append(f"{len(new_dirs)} new dir{'s' if len(new_dirs) != 1 else ''} ({total_new_dir_files} files)")
    if deleted:
        parts.append(f"{len(deleted)} deleted")
    if overlay_dels:
        parts.append(f"{len(overlay_dels)} overlay delete{'s' if len(overlay_dels) != 1 else ''}")
    if overlay_revs:
        parts.append(f"{len(overlay_revs)} overlay revert{'s' if len(overlay_revs) != 1 else ''}")
    print(f"  {', '.join(parts)}")
    print()

    last_patch = None
    stop_outer = False

    # Each handler marks id(item) here once the user chooses for it.
    # Anything still unmarked after the loop was never reached (early
    # "write & exit"/"done") and falls to skipped in the closing sweep.
    processed_ids = set()

    for idx, item in enumerate(items):
        _show_hunk(item, idx, total, repo_root=repo_root)

        if isinstance(item, (Hunk, DeletedFile)):
            # Hunk and DeletedFile share one patch-assignment flow: the same
            # _ask_patch prompt and abort/done/skip/all_remaining/new handling.
            # They differ only in how the chosen patch records the item and in
            # the confirmation label. All Hunk variants
            # (modified/new/deleted/binary/binary_deleted) share this prompt
            # too — no per-type branching; the type field carries to
            # write_patches, which drives per-type handling (text vs binary
            # bytes; modify vs delete via _is_deletion's `deleted file mode`).
            choice, lines = _ask_patch(
                series, touched=item.patches, last_patch=last_patch)

            if choice == "abort":
                result.aborted = True
                return result
            if choice == "done":
                break
            if choice == "skip":
                result.skipped.append(item)
                processed_ids.add(id(item))
                _collapse(lines, f"  {_dim(_SYM_CHECK + ' ' + item.file + ' — skipped')}")
                continue
            if choice == "all_remaining":
                _route_remaining_to_patch(items[idx:], result, last_patch)
                processed_ids.update(id(i) for i in items[idx:])
                _collapse(lines, f"  {_dim(_SYM_CHECK + ' all remaining ' + _SYM_ARROW + ' ' + last_patch)}")
                break
            if choice == "new":
                name, name_lines = _ask_new_patch_name(series)
                if name is None:
                    result.aborted = True
                    return result
                series.append(name)
                choice = name
                lines += name_lines  # Name: prompt isn't in _ask_patch's count

            if isinstance(item, Hunk):
                result.add_hunk(choice, item.file_header, item.content)
                done_label = item.file
            else:
                result.deleted_files.setdefault(choice, []).append(item.file)
                done_label = item.file + ' (deleted)'
            processed_ids.add(id(item))
            last_patch = choice
            _collapse(lines, f"  {_dim(_SYM_CHECK + ' ' + done_label + ' ' + _SYM_ARROW + ' ' + choice)}")

        elif isinstance(item, OverlayCopy):
            answer = _ask_yn("Copy back to overlay?")
            if answer is None:
                result.aborted = True
                return result
            if answer:
                result.overlay_copies.append(item.file)
                _collapse(1, f"  {_dim(_SYM_CHECK + ' ' + item.file + ' ' + _SYM_ARROW + ' overlay')}")
            else:
                result.skipped.append(item)
                _collapse(1, f"  {_dim(_SYM_CHECK + ' ' + item.file + ' — skipped')}")
            processed_ids.add(id(item))

        elif isinstance(item, (NewFile, NewOverlayDir)):
            # NewFile (one untracked file) and NewOverlayDir (a whole new
            # directory tree) share the [o]verlay/[p]atch/[s]kip routing,
            # differing only in the set of files routed and the labels. A
            # NewOverlayDir routes all its files together: overlay = one atomic
            # directory symlink (via link-overlay); patch = added file-by-file
            # (quilt push creates the dir).
            if isinstance(item, NewOverlayDir):
                files = item.files
                n = len(files)
                label = f"{item.dir}/ ({n} {'file' if n == 1 else 'files'})"
                skip_label = f"{item.dir}/"
            else:
                files = [item.file]
                label = item.file
                skip_label = item.file
            ops_lines = 0  # [o]verlay/[p]atch/[s]kip prompt lines (+ invalid-entry hints)
            while True:
                try:
                    choice = input(f"  {_pi()} [o]verlay  [p]atch  [s]kip: ").strip().lower()
                except (EOFError, KeyboardInterrupt):
                    print()
                    result.aborted = True
                    return result
                ops_lines += 1
                if choice in ("o", "overlay"):
                    result.new_overlay_files.extend(files)
                    processed_ids.add(id(item))
                    _collapse(ops_lines, f"  {_dim(_SYM_CHECK + ' ' + label + ' ' + _SYM_ARROW + ' overlay')}")
                    break
                elif choice in ("p", "patch"):
                    patch_choice, lines = _ask_patch(
                        series, last_patch=last_patch)
                    lines += ops_lines  # the [o]/[p]/[s] prompt(s) precede _ask_patch's count
                    if patch_choice == "abort":
                        result.aborted = True
                        return result
                    if patch_choice == "done":
                        stop_outer = True
                        break
                    if patch_choice == "skip":
                        result.skipped.append(item)
                        processed_ids.add(id(item))
                        _collapse(lines, f"  {_dim(_SYM_CHECK + ' ' + skip_label + ' — skipped')}")
                        break
                    if patch_choice == "all_remaining":
                        _route_remaining_to_patch(items[idx:], result, last_patch)
                        processed_ids.update(id(i) for i in items[idx:])
                        _collapse(lines, f"  {_dim(_SYM_CHECK + ' all remaining ' + _SYM_ARROW + ' ' + last_patch)}")
                        stop_outer = True
                        break
                    if patch_choice == "new":
                        name, name_lines = _ask_new_patch_name(series)
                        if name is None:
                            result.aborted = True
                            return result
                        series.append(name)
                        patch_choice = name
                        lines += name_lines  # Name: prompt isn't in _ask_patch's count
                    result.new_patch_files.setdefault(patch_choice, []).extend(files)
                    processed_ids.add(id(item))
                    last_patch = patch_choice
                    _collapse(lines, f"  {_dim(_SYM_CHECK + ' ' + label + ' ' + _SYM_ARROW + ' ' + patch_choice)}")
                    break
                elif choice in ("s", "skip"):
                    result.skipped.append(item)
                    processed_ids.add(id(item))
                    _collapse(ops_lines, f"  {_dim(_SYM_CHECK + ' ' + skip_label + ' — skipped')}")
                    break
                else:
                    print("  Type 'o' for overlay, 'p' for patch, or 's' to skip.")
                    ops_lines += 1
            if stop_outer:
                break

        elif isinstance(item, (OverlayDeletion, OverlayRevert)):
            # Both are overlay files removed from .build/, discriminated at
            # extract time by whether upstream has a counterpart, and sharing
            # one [action]/[s]kip loop:
            #   OverlayDeletion — nothing upstream to fall back to; [d]elete
            #     just drops overlay/<path>. (To undo the deletion gesture,
            #     `make assemble MODE=discard` rebuilds .build/ from baseline,
            #     re-creating the overlay symlink.)
            #   OverlayRevert — upstream HAS the file; [r]estore drops the
            #     override AND brings upstream's content back. To truly delete,
            #     restore then `rm .build/<path>` and re-run make quilt (the
            #     second run reclassifies it as a tracked-file deletion).
            # OverlayRevert prints two info lines before its prompt, so its
            # _collapse covers 3 lines vs OverlayDeletion's 1. Invalid
            # responses leak into scrollback (deliberate — only the most
            # recent prompt block collapses).
            if isinstance(item, OverlayRevert):
                print(_dim(
                    "    upstream has this file — restoring removes the "
                    "overlay override and brings back upstream's content."))
                print(_dim(
                    "    To delete the file entirely, confirm "
                    "restore now, then `rm .build/<path>` and re-run "
                    "make quilt."))
                prompt = "[r]estore from upstream  [s]kip: "
                accept = ("r", "restore")
                target = result.reverted_overlay_files
                done_label = item.file + ' — restore from upstream'
                reprompt = "  Type 'r' to restore from upstream, or 's' to skip."
                collapse_n = 3
            else:
                prompt = "[d]elete from overlay  [s]kip: "
                accept = ("d", "delete")
                target = result.deleted_overlay_files
                done_label = item.file + ' — delete from overlay/'
                reprompt = "  Type 'd' to delete from overlay, or 's' to skip."
                collapse_n = 1
            while True:
                try:
                    choice = input(f"  {_pi()} {prompt}").strip().lower()
                except (EOFError, KeyboardInterrupt):
                    print()
                    result.aborted = True
                    return result
                if choice in accept:
                    target.append(item.file)
                    processed_ids.add(id(item))
                    _collapse(collapse_n, f"  {_dim(_SYM_CHECK + ' ' + done_label)}")
                    break
                elif choice in ("s", "skip"):
                    result.skipped.append(item)
                    processed_ids.add(id(item))
                    _collapse(collapse_n, f"  {_dim(_SYM_CHECK + ' ' + item.file + ' — skipped')}")
                    break
                else:
                    print(reprompt)

    # Sweep unrouted items (see processed_ids above) into skipped.
    # PermissionChange was filtered before the loop, so it can't land here.
    for item in items:
        if id(item) not in processed_ids:
            result.skipped.append(item)

    return result
