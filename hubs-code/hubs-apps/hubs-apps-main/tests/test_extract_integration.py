#!/usr/bin/env python3
"""Integration tests for quilt_extract.extract_hunks() against a real .build/ directory.

These tests require the actual repo with `make assemble` support.
They modify .build/, call extract_hunks(), verify results, then reset.

Test cases: E-INT-01 through E-INT-15, E-OVER-01..02, E-LINK-01..04.
E-INT-14 covers OverlayDeletion classification (no upstream
counterpart); E-INT-15 covers OverlayRevert (upstream
counterpart present).

Invocation (from platform root):
  APP_ROOT="$PWD/apps/mail" bash scripts/host/dc-run.sh python3 /platform/tests/test_extract_integration.py
"""
import sys
import os
import subprocess
import stat
import tempfile

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', 'scripts'))
from quilt_extract import (
    extract_hunks, Hunk, OverlayCopy, NewFile, DeletedFile, OverlayDeletion, OverlayRevert, PermissionChange,
    _get_overlay_copyfiles, _is_overlay_symlink, _get_patch_file_map,
)

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
APP_ROOT = os.environ.get("APP_ROOT", os.path.join(REPO, "apps", "mail"))
BUILD = os.path.join(APP_ROOT, ".build")
OVERLAY = os.path.join(APP_ROOT, "overlay")
PATCHES = os.path.join(APP_ROOT, "patches")
GIT_ENV = {
    **os.environ,
    "GIT_CONFIG_COUNT": "1",
    "GIT_CONFIG_KEY_0": "safe.directory",
    "GIT_CONFIG_VALUE_0": "*",
}

# Pick test files that are NOT touched by any quilt patch (so they exist at baseline).
# CHANGELOG.md is large (good for multi-hunk tests), README.md and COPYING are small.
TEST_FILE = "CHANGELOG.md"
TEST_FILE_SMALL = "README.md"
TEST_FILE_DELETE = "COPYING"

passed = 0
failed = 0


def check(test_id, condition, detail=""):
    global passed, failed
    if condition:
        passed += 1
        print(f"  PASS {test_id}")
    else:
        failed += 1
        print(f"  FAIL {test_id}: {detail}")


def assemble():
    """Verify .build/ is in a runnable state, MODE=discard if dirty.

    No MODE=force — operator pre-populates .build/ once before the test
    suite runs (per Johan: "If they cant pass without a FORCE=1 that
    is an automatic fail"). If .build/.git or baseline tag is missing,
    surface clearly + exit.
    """
    if not os.path.isdir(os.path.join(BUILD, ".git")):
        print(f"FATAL: {BUILD}/.git missing. Run "
              f"`make assemble MODE=force` from {APP_ROOT} once before tests.",
              file=sys.stderr)
        sys.exit(2)
    r = subprocess.run(
        ["git", "-C", BUILD, "rev-parse", "baseline"],
        env=GIT_ENV, capture_output=True)
    if r.returncode != 0:
        print(f"FATAL: baseline tag missing in {BUILD}. Run "
              f"`make assemble MODE=force` from {APP_ROOT} once before tests.",
              file=sys.stderr)
        sys.exit(2)
    # If working tree is dirty, MODE=discard to revert.
    diff = subprocess.run(
        ["git", "-C", BUILD, "diff", "--quiet", "baseline"], env=GIT_ENV)
    if diff.returncode != 0:
        make_env = {**os.environ, "APP_ROOT": APP_ROOT}
        d = subprocess.run(
            ["make", "assemble", "MODE=discard"], cwd=APP_ROOT, env=make_env,
            capture_output=True, text=True, timeout=120)
        if d.returncode != 0:
            print(f"FATAL: MODE=discard failed:\n{d.stderr}", file=sys.stderr)
            sys.exit(2)


def reset_build():
    """Reset .build/ to baseline: checkout tracked files, remove untracked."""
    subprocess.run(
        ["git", "checkout", "baseline", "--", "."],
        cwd=BUILD, env=GIT_ENV, capture_output=True, check=True)
    subprocess.run(
        ["git", "clean", "-fd"],
        cwd=BUILD, env=GIT_ENV, capture_output=True)


def modify_file(relpath, append_text):
    """Append text to a file in .build/."""
    full = os.path.join(BUILD, relpath)
    with open(full, "a") as f:
        f.write(append_text)


def create_file(relpath, content):
    """Create a new file in .build/ (parent dirs created if needed)."""
    full = os.path.join(BUILD, relpath)
    os.makedirs(os.path.dirname(full), exist_ok=True)
    with open(full, "w") as f:
        f.write(content)


def delete_file(relpath):
    """Delete a file from .build/."""
    full = os.path.join(BUILD, relpath)
    if os.path.islink(full) or os.path.exists(full):
        os.unlink(full)


def find_patched_file():
    """Find a file that is touched by an existing quilt patch and exists in .build/."""
    patch_map = _get_patch_file_map(PATCHES)
    for filepath, patches in patch_map.items():
        full = os.path.join(BUILD, filepath)
        if os.path.isfile(full) and not os.path.islink(full):
            return filepath, patches
    return None, []


# ============================================================================
# Setup: clean assemble
# ============================================================================
print("=== Setup: verify .build/ + DISCARD-if-dirty ===")
assemble()
reset_build()
# Verify test files exist
for tf in [TEST_FILE, TEST_FILE_SMALL, TEST_FILE_DELETE]:
    if not os.path.exists(os.path.join(BUILD, tf)):
        print(f"FATAL: expected test file {tf} not found in .build/", file=sys.stderr)
        sys.exit(2)
print("  .build/ is clean at baseline\n")


# ============================================================================
# E-INT-01: No changes -> empty list
# ============================================================================
print("=== E-INT-01: No changes -> empty list ===")
results = extract_hunks(APP_ROOT, GIT_ENV)
check("E-INT-01", len(results) == 0,
      f"expected 0 items, got {len(results)}: {results}")
reset_build()


# ============================================================================
# E-INT-02: Single modified file -> 1 Hunk
# ============================================================================
print("\n=== E-INT-02: Single modified file -> 1 Hunk ===")
modify_file(TEST_FILE_SMALL, "\n<!-- test-int-02 marker -->\n")
results = extract_hunks(APP_ROOT, GIT_ENV)

hunks = [r for r in results if isinstance(r, Hunk)]
check("E-INT-02a", len(hunks) >= 1,
      f"expected >=1 Hunk, got {len(hunks)}")
matching = [h for h in hunks if h.file == TEST_FILE_SMALL]
check("E-INT-02b", len(matching) == 1,
      f"expected 1 hunk for {TEST_FILE_SMALL}, got {len(matching)}")
if matching:
    check("E-INT-02c", matching[0].type == "modified",
          f"expected type=modified, got {matching[0].type}")
    check("E-INT-02d", "test-int-02" in matching[0].content,
          "marker text not in hunk content")
reset_build()


# ============================================================================
# E-INT-03: Modified file tracked by existing patch -> patches annotation
# ============================================================================
print("\n=== E-INT-03: Modified file tracked by existing patch -> patches annotation ===")
patched_file, expected_patches = find_patched_file()
if patched_file:
    modify_file(patched_file, "\n// test-int-03 patched file\n")
    results = extract_hunks(APP_ROOT, GIT_ENV)

    matching = [r for r in results if isinstance(r, Hunk) and r.file == patched_file]
    check("E-INT-03a", len(matching) >= 1,
          f"expected hunk for {patched_file}, got {len(matching)}")
    if matching:
        check("E-INT-03b", expected_patches[0] in matching[0].patches,
              f"expected '{expected_patches[0]}' in patches, got {matching[0].patches}")
else:
    # No patch touches a file that still exists — create a temporary patch
    # that references TEST_FILE_SMALL
    series_path = os.path.join(PATCHES, "series")
    with open(series_path, "r") as f:
        original_series = f.read()
    test_patch = os.path.join(PATCHES, "int03-test.patch")
    try:
        with open(test_patch, "w") as f:
            f.write(
                f"int03-test\n"
                f"--- .build.orig/{TEST_FILE_SMALL}\n"
                f"+++ .build/{TEST_FILE_SMALL}\n"
                f"@@ -1,3 +1,4 @@\n"
                f" line\n"
                f"+patched\n"
                f" line\n"
            )
        with open(series_path, "a") as f:
            f.write("int03-test.patch\n")
        modify_file(TEST_FILE_SMALL, "\n<!-- test-int-03 -->\n")
        results = extract_hunks(APP_ROOT, GIT_ENV)
        matching = [r for r in results if isinstance(r, Hunk) and r.file == TEST_FILE_SMALL]
        check("E-INT-03a", len(matching) >= 1,
              f"expected hunk for {TEST_FILE_SMALL}, got {len(matching)}")
        if matching:
            check("E-INT-03b", "int03-test" in matching[0].patches,
                  f"expected 'int03-test' in patches, got {matching[0].patches}")
    finally:
        with open(series_path, "w") as f:
            f.write(original_series)
        if os.path.exists(test_patch):
            os.unlink(test_patch)
reset_build()


# ============================================================================
# E-INT-04: Multiple files -> correct count
# ============================================================================
print("\n=== E-INT-04: Multiple files -> correct count ===")
modify_file(TEST_FILE, "\n<!-- int-04-a -->\n")
modify_file(TEST_FILE_SMALL, "\n<!-- int-04-b -->\n")
modify_file(".editorconfig", "\n# int-04-c\n")
results = extract_hunks(APP_ROOT, GIT_ENV)

hunks = [r for r in results if isinstance(r, Hunk)]
files_changed = {h.file for h in hunks}
check("E-INT-04a", len(files_changed) >= 3,
      f"expected >=3 files, got {files_changed}")
check("E-INT-04b", TEST_FILE in files_changed,
      f"{TEST_FILE} missing")
check("E-INT-04c", TEST_FILE_SMALL in files_changed,
      f"{TEST_FILE_SMALL} missing")
check("E-INT-04d", ".editorconfig" in files_changed,
      ".editorconfig missing")
reset_build()


# ============================================================================
# E-INT-05: New untracked file -> NewFile
# ============================================================================
print("\n=== E-INT-05: New untracked file -> NewFile ===")
create_file("test-new-file-int05.txt", "brand new content\n")
results = extract_hunks(APP_ROOT, GIT_ENV)

new_files = [r for r in results if isinstance(r, NewFile)]
matching = [n for n in new_files if n.file == "test-new-file-int05.txt"]
check("E-INT-05a", len(matching) == 1,
      f"expected 1 NewFile, got {len(matching)} (new_files={new_files})")
reset_build()


# ============================================================================
# E-INT-06: Deleted upstream file -> captured as a deleted hunk
# ============================================================================
print("\n=== E-INT-06: Deleted upstream file -> captured ===")
# extract surfaces deletions of any non-overlay file in .build/.
delete_file(TEST_FILE_DELETE)
results = extract_hunks(APP_ROOT, GIT_ENV)

deleted = [r for r in results
           if (isinstance(r, Hunk) and r.type == "deleted") or isinstance(r, DeletedFile)]
matching = [d for d in deleted if d.file == TEST_FILE_DELETE]
check("E-INT-06a", len(matching) == 1,
      f"upstream deletion should produce one deleted entry, got {matching}")
reset_build()


# ============================================================================
# E-INT-07: Binary file -> Hunk with type=binary
# ============================================================================
print("\n=== E-INT-07: Binary file -> Hunk with type=binary ===")
binary_file = "img/star.png"
binary_path = os.path.join(BUILD, binary_file)
if os.path.exists(binary_path):
    with open(binary_path, "ab") as f:
        f.write(b"\x00\x01\x02MODIFIED_BINARY\x03\x04")
    results = extract_hunks(APP_ROOT, GIT_ENV)
    binary_hunks = [r for r in results if isinstance(r, Hunk) and r.type == "binary"]
    matching = [h for h in binary_hunks if h.file == binary_file]
    check("E-INT-07a", len(matching) == 1,
          f"expected 1 binary Hunk for {binary_file}, got {len(matching)}")
    if matching:
        check("E-INT-07b", matching[0].header == "",
              f"binary hunk header should be empty, got {matching[0].header!r}")
        check("E-INT-07c", matching[0].content == "",
              "binary hunk content should be empty")
else:
    check("E-INT-07a", False, f"{binary_file} not found in .build/")
reset_build()


# ============================================================================
# E-INT-08: Permission change -> PermissionChange
# ============================================================================
print("\n=== E-INT-08: Permission change -> PermissionChange ===")
perm_target = os.path.join(BUILD, ".editorconfig")
if os.path.exists(perm_target):
    current = os.stat(perm_target).st_mode
    if current & stat.S_IXUSR:
        os.chmod(perm_target, current & ~(stat.S_IXUSR | stat.S_IXGRP | stat.S_IXOTH))
    else:
        os.chmod(perm_target, current | stat.S_IXUSR | stat.S_IXGRP | stat.S_IXOTH)
    results = extract_hunks(APP_ROOT, GIT_ENV)
    perm_changes = [r for r in results if isinstance(r, PermissionChange)]
    matching = [p for p in perm_changes if p.file == ".editorconfig"]
    check("E-INT-08a", len(matching) == 1,
          f"expected PermissionChange for .editorconfig, got perm_changes={perm_changes}")
else:
    check("E-INT-08a", False, ".editorconfig not found in .build/")
reset_build()


# ============================================================================
# E-INT-09: Overlay copy modified -> OverlayCopy
# ============================================================================
print("\n=== E-INT-09: Overlay copy modified -> OverlayCopy ===")
# overlay-copyfiles.d/ lists files that are copies (not symlinks) from overlay/
copyfiles = _get_overlay_copyfiles(APP_ROOT)
copyfile = None
for cf in sorted(copyfiles):
    full = os.path.join(BUILD, cf)
    if os.path.isfile(full) and not os.path.islink(full):
        copyfile = cf
        break

if copyfile:
    modify_file(copyfile, "\n// int-09 overlay copy test\n")
    results = extract_hunks(APP_ROOT, GIT_ENV)
    overlays = [r for r in results if isinstance(r, OverlayCopy)]
    matching = [o for o in overlays if o.file == copyfile]
    check("E-INT-09a", len(matching) == 1,
          f"expected 1 OverlayCopy for {copyfile}, got {len(matching)} (overlays={overlays})")
    # Should NOT appear as a regular Hunk
    hunk_matches = [r for r in results if isinstance(r, Hunk) and r.file == copyfile]
    check("E-INT-09b", len(hunk_matches) == 0,
          f"overlay copy should not appear as Hunk, got {hunk_matches}")
else:
    check("E-INT-09a", False, "no overlay copyfile found as regular file in .build/")
reset_build()


# ============================================================================
# E-INT-10: Overlay symlink edit -> filtered out
# ============================================================================
print("\n=== E-INT-10: Overlay symlink edit -> filtered out ===")
# Create a new untracked symlink to overlay/ and verify extract filters it.
test_link = "test-overlay-link.json"
test_link_full = os.path.join(BUILD, test_link)
overlay_target = os.path.join(OVERLAY, "package.json")
if not os.path.exists(overlay_target):
    check("E-INT-10a", False, f"fixture precondition: {overlay_target} missing")
else:
    os.symlink(overlay_target, test_link_full)
    results = extract_hunks(APP_ROOT, GIT_ENV)
    # The new untracked symlink pointing to overlay/ should be filtered
    matching = [r for r in results if hasattr(r, 'file') and r.file == test_link]
    check("E-INT-10a", len(matching) == 0,
          f"overlay symlink {test_link} should be filtered, got {matching}")
reset_build()


# ============================================================================
# E-INT-11: Mixed types in one run
# ============================================================================
print("\n=== E-INT-11: Mixed types in one run ===")
modify_file(TEST_FILE_SMALL, "\n<!-- int-11 mixed -->\n")
create_file("test-int11-new.txt", "new file for int-11\n")
delete_file(TEST_FILE_DELETE)
if copyfile:
    modify_file(copyfile, "\n// int-11 overlay copy\n")

results = extract_hunks(APP_ROOT, GIT_ENV)

types_found = set()
for r in results:
    types_found.add(type(r).__name__)

check("E-INT-11a", "Hunk" in types_found,
      f"expected Hunk in types, got {types_found}")
check("E-INT-11b", "NewFile" in types_found,
      f"expected NewFile in types, got {types_found}")
if copyfile:
    check("E-INT-11c", "OverlayCopy" in types_found,
          f"expected OverlayCopy in types, got {types_found}")

hunk_files = {r.file for r in results if isinstance(r, Hunk)}
check("E-INT-11d", TEST_FILE_SMALL in hunk_files,
      f"{TEST_FILE_SMALL} not in hunks: {hunk_files}")

new_files_found = {r.file for r in results if isinstance(r, NewFile)}
check("E-INT-11e", "test-int11-new.txt" in new_files_found,
      f"test-int11-new.txt not in new files: {new_files_found}")

if copyfile:
    overlay_files = {r.file for r in results if isinstance(r, OverlayCopy)}
    check("E-INT-11f", copyfile in overlay_files,
          f"{copyfile} not in overlays: {overlay_files}")

deleted = [r for r in results
           if (isinstance(r, Hunk) and r.type == "deleted") or isinstance(r, DeletedFile)]
deleted_files = {d.file for d in deleted}
check("E-INT-11g", TEST_FILE_DELETE in deleted_files,
      f"upstream deletion {TEST_FILE_DELETE} should be captured, but missing from: {deleted_files}")
reset_build()


# ============================================================================
# E-INT-12: File tracked by multiple patches -> correct annotation
# ============================================================================
print("\n=== E-INT-12: File tracked by multiple patches -> correct annotation ===")
# Create two temporary patches that both touch TEST_FILE_SMALL
series_path = os.path.join(PATCHES, "series")
with open(series_path, "r") as f:
    original_series = f.read()

patch1 = os.path.join(PATCHES, "int12-first.patch")
patch2 = os.path.join(PATCHES, "int12-second.patch")
patches_created = []
try:
    for pname, ppath in [("int12-first", patch1), ("int12-second", patch2)]:
        with open(ppath, "w") as f:
            f.write(
                f"{pname}\n"
                f"--- .build.orig/{TEST_FILE_SMALL}\n"
                f"+++ .build/{TEST_FILE_SMALL}\n"
                f"@@ -1,3 +1,4 @@\n"
                f" line\n"
                f"+{pname} patched\n"
                f" line\n"
            )
        patches_created.append(ppath)

    with open(series_path, "w") as f:
        f.write(original_series.rstrip("\n") + "\n"
                "int12-first.patch\n"
                "int12-second.patch\n")

    modify_file(TEST_FILE_SMALL, "\n<!-- int-12 multi-patch -->\n")
    results = extract_hunks(APP_ROOT, GIT_ENV)

    matching = [r for r in results if isinstance(r, Hunk) and r.file == TEST_FILE_SMALL]
    check("E-INT-12a", len(matching) >= 1,
          f"expected hunk for {TEST_FILE_SMALL}, got {len(matching)}")
    if matching:
        check("E-INT-12b", "int12-first" in matching[0].patches,
              f"expected 'int12-first' in patches, got {matching[0].patches}")
        check("E-INT-12c", "int12-second" in matching[0].patches,
              f"expected 'int12-second' in patches, got {matching[0].patches}")
        check("E-INT-12d", len(matching[0].patches) >= 2,
              f"expected >=2 patches, got {matching[0].patches}")
finally:
    with open(series_path, "w") as f:
        f.write(original_series)
    for p in patches_created:
        if os.path.exists(p):
            os.unlink(p)
reset_build()


# ============================================================================
# E-INT-13: Large file with many hunks -> correct count
# ============================================================================
print("\n=== E-INT-13: Large file with many hunks -> correct count ===")
# CHANGELOG.md is large (~1768 lines). Insert markers at widely-spaced locations
# to produce multiple separate hunks (git diff uses 3 lines of context).
full_path = os.path.join(BUILD, TEST_FILE)
with open(full_path, "r") as f:
    lines = f.readlines()

total = len(lines)
if total >= 50:
    # Space markers at least 10 lines apart to ensure separate hunks
    offsets = [5, 20, 35, 50]
    offsets = [o for o in offsets if o < total]
    inserted = 0
    for offset in offsets:
        idx = offset + inserted
        lines.insert(idx, f"<!-- INT-13 MARKER {offset} -->\n")
        inserted += 1

    with open(full_path, "w") as f:
        f.writelines(lines)

    results = extract_hunks(APP_ROOT, GIT_ENV)
    matching = [r for r in results if isinstance(r, Hunk) and r.file == TEST_FILE]
    check("E-INT-13a", len(matching) >= 3,
          f"expected >=3 hunks for {TEST_FILE}, got {len(matching)}")
    # Verify all markers present across hunks
    all_content = "\n".join(h.content for h in matching)
    for offset in offsets:
        check(f"E-INT-13b-{offset}",
              f"INT-13 MARKER {offset}" in all_content,
              f"marker {offset} not found in any hunk content")
else:
    check("E-INT-13a", False, f"{TEST_FILE} too small ({total} lines)")
reset_build()


# ============================================================================
# E-OVER-01: _get_overlay_copyfiles with no file
# ============================================================================
print("\n=== E-OVER-01: No overlay-copyfiles.d/ directory ===")
with tempfile.TemporaryDirectory() as tmpdir:
    result = _get_overlay_copyfiles(tmpdir)
    check("E-OVER-01", result == set(), f"expected empty set, got {result}")


# ============================================================================
# E-OVER-02: overlay-copyfiles.d/ with entries, comments, blanks
# ============================================================================
print("\n=== E-OVER-02: overlay-copyfiles.d/ with entries, comments, blanks ===")
with tempfile.TemporaryDirectory() as tmpdir:
    d_dir = os.path.join(tmpdir, "overlay-copyfiles.d")
    os.makedirs(d_dir)
    with open(os.path.join(d_dir, "test.txt"), "w") as f:
        f.write("file1.js\n# comment\n\nfile2.js\n")
    result = _get_overlay_copyfiles(tmpdir)
    check("E-OVER-02a", "file1.js" in result, f"file1.js missing: {result}")
    check("E-OVER-02b", "file2.js" in result, f"file2.js missing: {result}")
    check("E-OVER-02c", len(result) == 2, f"expected 2 entries, got {len(result)}: {result}")


# ============================================================================
# E-LINK-01: File is not a symlink
# ============================================================================
print("\n=== E-LINK-01: File is not a symlink ===")
check("E-LINK-01", _is_overlay_symlink(TEST_FILE_SMALL, BUILD, OVERLAY) is False,
      "regular file should return False")


# ============================================================================
# E-LINK-02: Symlink pointing to overlay/
# ============================================================================
print("\n=== E-LINK-02: Symlink pointing to overlay/ ===")
# package.json is a copyfile (manifests are copied, not symlinked, to avoid
# realpath-rewriting); appinfo/info.xml is a real overlay symlink in mail.
check("E-LINK-02", _is_overlay_symlink("appinfo/info.xml", BUILD, OVERLAY) is True,
      "symlink to overlay should return True")


# ============================================================================
# E-LINK-03: Symlink pointing elsewhere
# ============================================================================
print("\n=== E-LINK-03: Symlink pointing elsewhere ===")
with tempfile.TemporaryDirectory() as tmpdir:
    os.symlink("/tmp/something", os.path.join(tmpdir, "other.txt"))
    check("E-LINK-03", _is_overlay_symlink("other.txt", tmpdir, OVERLAY) is False,
          "symlink to /tmp should return False")


# ============================================================================
# E-LINK-04: Relative symlink resolving into overlay/
# ============================================================================
print("\n=== E-LINK-04: Relative symlink resolving into overlay/ ===")
with tempfile.TemporaryDirectory() as tmpdir:
    subdir = os.path.join(tmpdir, "sub")
    os.makedirs(subdir)
    rel_target = os.path.relpath(os.path.join(OVERLAY, "package.json"), subdir)
    os.symlink(rel_target, os.path.join(subdir, "test.json"))
    check("E-LINK-04", _is_overlay_symlink("test.json", subdir, OVERLAY) is True,
          "relative symlink resolving to overlay/ should return True")


# ============================================================================
# E-INT-14: Deleted overlay symlink with no upstream counterpart
#           -> OverlayDeletion (not DeletedFile)
# ============================================================================
print("\n=== E-INT-14: Deleted overlay file -> OverlayDeletion ===")
# Use a real overlay symlink that already exists in baseline. No
# synthetic-fixture baseline mutation needed — apps/mail/.build/'s
# overlay symlinks ARE the canonical fixture for these classification
# tests, and reset_build's `git checkout baseline -- .` restores the
# symlink cleanly (git tracks symlinks as mode 120000 with the target
# as content). Earlier shape used `git tag -f baseline` to splice in
# a synthetic file; that was scaffolding around an incorrect comment
# claim that "we can't just delete a symlink — git tracks symlinks as
# special objects." git diff baseline does surface symlink deletions
# (with `+++ /dev/null`), and the leak from moving baseline forward
# was caught empirically by the chaos arc's S112-D04 differential
# equivalence check (arc-run-19).
#
# Target: tests/phpunit.itsl.xml — overlay leaf symlink, ITSL-only
# (no upstream/tests/phpunit.itsl.xml). This is the
# OverlayDeletion branch (no upstream → no fall-back content).
overlay_test = "tests/phpunit.itsl.xml"
build_path = os.path.join(BUILD, overlay_test)
try:
    # Sanity: the symlink must exist pre-test (baseline-tracked overlay
    # link). If it doesn't, the apps/mail repo's overlay shape changed
    # under the test and the fixture target needs revisiting.
    assert os.path.islink(build_path), \
        f"E-INT-14 fixture: {build_path} is not a symlink — apps/mail/overlay shape changed"

    # Delete the symlink. extract sees this as a deletion vs baseline
    # (which still tracks the symlink); overlay/<path> exists; upstream
    # has no counterpart → OverlayDeletion classification.
    os.unlink(build_path)
    results = extract_hunks(APP_ROOT, GIT_ENV)

    overlay_dels = [r for r in results if isinstance(r, OverlayDeletion)]
    matching = [d for d in overlay_dels if d.file == overlay_test]
    check("E-INT-14a", len(matching) == 1,
          f"expected OverlayDeletion for {overlay_test}, got overlay_dels={overlay_dels}")

    # Must NOT appear as DeletedFile, deleted Hunk, or OverlayRevert
    # (the upstream/<path> file doesn't exist in upstream so extract's
    # classification lands on OverlayDeletion, not OverlayRevert).
    wrong = [r for r in results
             if (isinstance(r, DeletedFile) and r.file == overlay_test) or
                (isinstance(r, Hunk) and r.type == "deleted" and r.file == overlay_test) or
                (isinstance(r, OverlayRevert) and r.file == overlay_test)]
    check("E-INT-14b", len(wrong) == 0,
          f"overlay deletion should not be DeletedFile/deleted Hunk/OverlayRevert, got {wrong}")
finally:
    # reset_build's `git checkout baseline -- .` restores the deleted
    # symlink from baseline. No baseline mutation to undo.
    reset_build()


# ============================================================================
# E-INT-15: Deleted overlay symlink with upstream counterpart
#           -> OverlayRevert — companion to E-INT-14
# ============================================================================
print("\n=== E-INT-15: Deleted overlay file with upstream counterpart -> OverlayRevert ===")
# Same shape as E-INT-14 — use a real baseline-tracked overlay symlink.
# No synthetic baseline mutation; reset_build restores the symlink.
#
# Target: appinfo/info.xml — overlay leaf symlink, AND
# upstream/appinfo/info.xml exists (Nextcloud's mail manifest). Per
# This is the OverlayRevert branch (upstream counterpart
# present → restore-from-upstream path).
overlay_test = "appinfo/info.xml"
build_path = os.path.join(BUILD, overlay_test)
upstream_path = os.path.join(APP_ROOT, "upstream", overlay_test)
try:
    # Sanity: pre-test fixture invariants.
    assert os.path.islink(build_path), \
        f"E-INT-15 fixture: {build_path} is not a symlink — apps/mail/overlay shape changed"
    assert os.path.isfile(upstream_path), \
        f"E-INT-15 fixture: {upstream_path} missing — apps/mail/upstream shape changed"

    # Delete the symlink. extract sees this as a deletion vs baseline;
    # overlay/<path> exists; upstream/<path> exists as a regular file
    # → the OverlayRevert classification.
    os.unlink(build_path)
    results = extract_hunks(APP_ROOT, GIT_ENV)

    overlay_revs = [r for r in results if isinstance(r, OverlayRevert)]
    matching = [d for d in overlay_revs if d.file == overlay_test]
    check("E-INT-15a", len(matching) == 1,
          f"expected OverlayRevert for {overlay_test}, got overlay_revs={overlay_revs}")

    # Must NOT appear as OverlayDeletion / DeletedFile / deleted Hunk —
    # the upstream-counterpart presence steers classification to
    # OverlayRevert, not OverlayDeletion.
    wrong = [r for r in results
             if (isinstance(r, OverlayDeletion) and r.file == overlay_test) or
                (isinstance(r, DeletedFile) and r.file == overlay_test) or
                (isinstance(r, Hunk) and r.type == "deleted" and r.file == overlay_test)]
    check("E-INT-15b", len(wrong) == 0,
          f"overlay-with-upstream deletion should not be OverlayDeletion/DeletedFile/deleted Hunk, got {wrong}")
finally:
    # reset_build's `git checkout baseline -- .` restores the symlink.
    reset_build()


# ============================================================================
# Summary and cleanup
# ============================================================================
print(f"\n{'='*60}")
print(f"Integration tests: {passed} passed, {failed} failed")
print(f"{'='*60}")

# Final reset to leave .build/ clean
reset_build()

sys.exit(1 if failed else 0)
