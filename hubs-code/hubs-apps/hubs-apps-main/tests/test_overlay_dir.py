#!/usr/bin/env python3
"""Tests for NewOverlayDir and smart overlay directory detection.

Test IDs NOD-01..NOD-20 are the strings passed to `check(...)` below —
search for the ID to find the test. Each test sets up files in `.build/`,
calls `extract_hunks()`, asserts the classification (NewOverlayDir vs
NewFile, dir-cut-point, sort order, copyfiles/symlink filters), and
cleans up.

Requires .build/ with git baseline (make assemble MODE=force).

Coupling: tests assume mail's upstream layout (lib/, lib/Service/, src/,
src/components/ exist; itslnew/, brandnew/, etc. don't). If the upstream
submodule moves to a version with those names taken, tests need
re-pinning — same coupling as the rest of the integration suite.

Invocation (from platform root):
  APP_ROOT="$PWD/apps/mail" bash scripts/host/dc-run.sh python3 /platform/tests/test_overlay_dir.py
"""
import sys
import os
import subprocess
import shutil

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', 'scripts'))
from quilt_extract import (
    extract_hunks, NewFile, NewOverlayDir, Hunk, OverlayCopy,
    _find_overlay_dir, _parent_is_overlay_symlink,
)

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
APP_ROOT = os.environ.get("APP_ROOT", os.path.join(REPO, "apps", "mail"))
BUILD = os.path.join(APP_ROOT, ".build")
OVERLAY = os.path.join(APP_ROOT, "overlay")
UPSTREAM = os.path.join(APP_ROOT, "upstream")
GIT_ENV = {
    **os.environ,
    "GIT_CONFIG_COUNT": "1",
    "GIT_CONFIG_KEY_0": "safe.directory",
    "GIT_CONFIG_VALUE_0": "*",
}

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


def setup():
    """Verify .build/ is in a runnable state.

    Doesn't run MODE=force — the rebuild's design moves MODE=force out
    of the common path. Operator ensures .build/ is populated (one-time)
    before running the test suite. If .build/.git or baseline tag is
    missing, surface a clear error pointing at the manual setup step.
    """
    if not os.path.isdir(os.path.join(BUILD, ".git")):
        print(f"Setup failed: {BUILD}/.git missing. "
              f"Run `make assemble MODE=force` from {APP_ROOT} once before tests.")
        sys.exit(1)
    git_env = {**os.environ, "GIT_CONFIG_COUNT": "1",
               "GIT_CONFIG_KEY_0": "safe.directory", "GIT_CONFIG_VALUE_0": "*"}
    r = subprocess.run(
        ["git", "-C", BUILD, "rev-parse", "baseline"],
        env=git_env, capture_output=True)
    if r.returncode != 0:
        print(f"Setup failed: baseline tag missing in {BUILD}. "
              f"Run `make assemble MODE=force` from {APP_ROOT} once before tests.")
        sys.exit(1)
    # If working tree is dirty, MODE=discard to revert to a known state.
    diff = subprocess.run(
        ["git", "-C", BUILD, "diff", "--quiet", "baseline"], env=git_env)
    if diff.returncode != 0:
        subprocess.run(
            ["make", "assemble", "MODE=discard"], cwd=APP_ROOT,
            capture_output=True, text=True, timeout=120, check=True)


def mkfile(rel_path, content="content\n"):
    full = os.path.join(BUILD, rel_path)
    os.makedirs(os.path.dirname(full), exist_ok=True)
    with open(full, "w") as f:
        f.write(content)


def cleanup(*paths):
    for p in paths:
        full = os.path.join(BUILD, p) if not os.path.isabs(p) else p
        if os.path.isdir(full) and not os.path.islink(full):
            shutil.rmtree(full)
        elif os.path.exists(full) or os.path.islink(full):
            os.unlink(full)


def get_items(type_filter=None):
    items = extract_hunks(APP_ROOT, GIT_ENV)
    if type_filter:
        return [i for i in items if isinstance(i, type_filter)]
    return items


# ============================================================================
print("=== Setup ===")
setup()
print("  Ready\n")


# ============================================================================
# NOD-01: New file in brand-new root directory
# ============================================================================
print("=== NOD-01: Brand-new root directory ===")
mkfile("brandnew/file.js")
dirs = get_items(NewOverlayDir)
files = get_items(NewFile)
matching_dirs = [d for d in dirs if d.dir == "brandnew"]
matching_files = [f for f in files if "brandnew" in f.file]
check("NOD-01a", len(matching_dirs) == 1,
      f"expected 1 NewOverlayDir, got {len(matching_dirs)}: {dirs}")
if matching_dirs:
    check("NOD-01b", matching_dirs[0].files == ["brandnew/file.js"],
          f"files={matching_dirs[0].files}")
check("NOD-01c", len(matching_files) == 0,
      f"should not have NewFile, got {matching_files}")
cleanup("brandnew")


# ============================================================================
# NOD-02: New file in new subdir of existing upstream dir
# ============================================================================
print("\n=== NOD-02: New subdir of existing upstream dir ===")
mkfile("lib/itslnew/Service.php", "<?php\n")
dirs = get_items(NewOverlayDir)
matching = [d for d in dirs if d.dir == "lib/itslnew"]
check("NOD-02a", len(matching) == 1,
      f"expected NewOverlayDir(lib/itslnew), got {dirs}")
if matching:
    check("NOD-02b", matching[0].files == ["lib/itslnew/Service.php"],
          f"files={matching[0].files}")
# Verify cut point is NOT lib
wrong_cut = [d for d in dirs if d.dir == "lib"]
check("NOD-02c", len(wrong_cut) == 0,
      f"cut point should not be 'lib': {wrong_cut}")
cleanup("lib/itslnew")


# ============================================================================
# NOD-03: Multiple files grouped under same new directory
# ============================================================================
print("\n=== NOD-03: Multiple files, one group ===")
mkfile("lib/itslnew/A.php", "<?php class A {}\n")
mkfile("lib/itslnew/sub/B.php", "<?php class B {}\n")
mkfile("lib/itslnew/Top.php", "<?php class Top {}\n")
dirs = get_items(NewOverlayDir)
matching = [d for d in dirs if d.dir == "lib/itslnew"]
check("NOD-03a", len(matching) == 1,
      f"expected 1 NewOverlayDir, got {len(matching)}: {dirs}")
if matching:
    check("NOD-03b", len(matching[0].files) == 3,
          f"expected 3 files, got {len(matching[0].files)}: {matching[0].files}")
    expected_files = ["lib/itslnew/A.php", "lib/itslnew/Top.php", "lib/itslnew/sub/B.php"]
    check("NOD-03c", matching[0].files == expected_files,
          f"expected sorted {expected_files}, got {matching[0].files}")
# No separate NewOverlayDir for sub/
sub_dirs = [d for d in dirs if d.dir == "lib/itslnew/sub"]
check("NOD-03d", len(sub_dirs) == 0,
      f"should not have separate dir for sub/: {sub_dirs}")
cleanup("lib/itslnew")


# ============================================================================
# NOD-04: New file in existing upstream dir (no grouping)
# ============================================================================
print("\n=== NOD-04: File in existing upstream dir ===")
mkfile("lib/NewStandalone.php", "<?php class Standalone {}\n")
dirs = get_items(NewOverlayDir)
files = get_items(NewFile)
matching_dirs = [d for d in dirs if "Standalone" in str(d.files)]
matching_files = [f for f in files if f.file == "lib/NewStandalone.php"]
check("NOD-04a", len(matching_files) == 1,
      f"expected NewFile, got {matching_files}")
check("NOD-04b", len(matching_dirs) == 0,
      f"should not have NewOverlayDir, got {matching_dirs}")
cleanup("lib/NewStandalone.php")


# ============================================================================
# NOD-05: New file inside overlay directory symlink (skipped)
# ============================================================================
print("\n=== NOD-05: File inside overlay dir symlink ===")
itsl_link = os.path.join(BUILD, "src", "itsl")
assert os.path.islink(itsl_link), \
    f"fixture precondition: {itsl_link} must be an overlay symlink (link-overlay creates it from overlay/src/itsl/)"
# Create file — it goes into overlay/src/itsl/ via the symlink
mkfile("src/itsl/nod05-test.js", "// new\n")
items = get_items()
matching = [i for i in items
            if (isinstance(i, NewFile) and "nod05" in i.file) or
               (isinstance(i, NewOverlayDir) and any("nod05" in f for f in i.files))]
check("NOD-05a", len(matching) == 0,
      f"file inside overlay symlink should be skipped, got {matching}")
# Cleanup: file is actually in overlay/
cleanup(os.path.join(OVERLAY, "src", "itsl", "nod05-test.js"))


# NOD-06: dropped — tested the removed non-interactive FILE= filter path.
# NewOverlayDir extraction itself is covered by NOD-01/02/04/07/09/10.


# ============================================================================
# NOD-07: NewOverlayDir skipped (user hits done early)
# Tested via result object, not tmux
# ============================================================================
print("\n=== NOD-07: NewOverlayDir in skipped list ===")
mkfile("lib/itslnew/File.php", "<?php\n")
items = extract_hunks(APP_ROOT, GIT_ENV)
nod_items = [i for i in items if isinstance(i, NewOverlayDir) and i.dir == "lib/itslnew"]
check("NOD-07a", len(nod_items) == 1, f"expected 1 NewOverlayDir, got {nod_items}")
if nod_items:
    item = nod_items[0]
    # Verify it has .files (not .file) — the write/verify code needs this
    check("NOD-07b", hasattr(item, "files"), "NewOverlayDir should have .files")
    check("NOD-07c", not hasattr(item, "file"), "NewOverlayDir should NOT have .file")
    check("NOD-07d", len(item.files) == 1, f"expected 1 file, got {item.files}")
cleanup("lib/itslnew")


# ============================================================================
# NOD-09: Two different new directories
# ============================================================================
print("\n=== NOD-09: Two different new directories ===")
mkfile("brandnew/deep/thing.js")
mkfile("lib/itslnew/File.php", "<?php\n")
dirs = get_items(NewOverlayDir)
check("NOD-09a", len(dirs) == 2,
      f"expected 2 NewOverlayDir, got {len(dirs)}: {dirs}")
if len(dirs) >= 2:
    check("NOD-09b", dirs[0].dir == "brandnew",
          f"first dir should be 'brandnew', got {dirs[0].dir}")
    check("NOD-09c", dirs[1].dir == "lib/itslnew",
          f"second dir should be 'lib/itslnew', got {dirs[1].dir}")
cleanup("brandnew", "lib/itslnew")


# ============================================================================
# NOD-10: File at repo root (single-segment path)
# ============================================================================
print("\n=== NOD-10: File at repo root ===")
mkfile("rootfile.txt", "root file\n")
dirs = get_items(NewOverlayDir)
files = get_items(NewFile)
matching_dirs = [d for d in dirs if "rootfile" in str(d.files) or d.dir == ""]
matching_files = [f for f in files if f.file == "rootfile.txt"]
check("NOD-10a", len(matching_files) == 1,
      f"expected NewFile, got {matching_files}")
check("NOD-10b", len(matching_dirs) == 0,
      f"should not have NewOverlayDir, got {matching_dirs}")
# Direct function test
result = _find_overlay_dir("rootfile.txt", UPSTREAM)
check("NOD-10c", result is None,
      f"_find_overlay_dir should return None, got {result}")
cleanup("rootfile.txt")


# ============================================================================
# NOD-11: Deeply nested new directory (correct cut point)
# ============================================================================
print("\n=== NOD-11: Deeply nested new directory ===")
mkfile("lib/Service/Itsl/Sub/Deep/Handler.php", "<?php\n")
dirs = get_items(NewOverlayDir)
matching = [d for d in dirs if "Itsl" in d.dir]
check("NOD-11a", len(matching) == 1,
      f"expected 1 NewOverlayDir, got {matching}")
if matching:
    check("NOD-11b", matching[0].dir == "lib/Service/Itsl",
          f"cut point should be lib/Service/Itsl, got {matching[0].dir}")
    # Verify NOT at wrong cut points
    check("NOD-11c", matching[0].dir != "lib",
          "cut point should not be lib")
    check("NOD-11d", matching[0].dir != "lib/Service",
          "cut point should not be lib/Service")
    check("NOD-11e", matching[0].dir != "lib/Service/Itsl/Sub",
          "cut point should not be lib/Service/Itsl/Sub")
cleanup("lib/Service/Itsl")


# ============================================================================
# NOD-12: Mix of NewOverlayDir and standalone NewFile
# ============================================================================
print("\n=== NOD-12: Mix of dir group and standalone ===")
mkfile("lib/itslnew/Grouped.php", "<?php\n")
mkfile("lib/Standalone.php", "<?php\n")
dirs = get_items(NewOverlayDir)
files = get_items(NewFile)
dir_match = [d for d in dirs if d.dir == "lib/itslnew"]
file_match = [f for f in files if f.file == "lib/Standalone.php"]
check("NOD-12a", len(dir_match) == 1,
      f"expected 1 NewOverlayDir, got {dir_match}")
check("NOD-12b", len(file_match) == 1,
      f"expected 1 NewFile, got {file_match}")
if dir_match:
    check("NOD-12c", "lib/Standalone.php" not in dir_match[0].files,
          f"Standalone.php should NOT be in dir group: {dir_match[0].files}")
cleanup("lib/itslnew", "lib/Standalone.php")


# ============================================================================
# NOD-13: Parent dir exists in upstream as a file
# ============================================================================
print("\n=== NOD-13: Parent name is a file in upstream ===")
# krankerl.toml exists as a file in upstream
# We can't mkdir krankerl.toml in .build if the file is there
# Remove the file first, create dir, test, restore
toml_path = os.path.join(BUILD, "krankerl.toml")
toml_backup = None
if os.path.isfile(toml_path):
    with open(toml_path, "rb") as f:
        toml_backup = f.read()
    os.unlink(toml_path)

mkfile("krankerl.toml/subdir/file.js")
dirs = get_items(NewOverlayDir)
matching = [d for d in dirs if d.dir == "krankerl.toml"]
check("NOD-13a", len(matching) == 1,
      f"expected NewOverlayDir(krankerl.toml), got {dirs}")

# Restore
cleanup("krankerl.toml")
if toml_backup is not None:
    with open(toml_path, "wb") as f:
        f.write(toml_backup)


# ============================================================================
# NOD-14: File in copyfiles manifest inside a new directory
# ============================================================================
print("\n=== NOD-14: File in copyfiles.d inside new dir ===")
mkfile("lib/itslnew/special.js")
copyfile_manifest = os.path.join(APP_ROOT, "overlay-copyfiles.d", "test-nod14")
with open(copyfile_manifest, "w") as f:
    f.write("lib/itslnew/special.js\n")
items = get_items()
matching = [i for i in items
            if (isinstance(i, NewFile) and "special" in i.file) or
               (isinstance(i, NewOverlayDir) and any("special" in f for f in i.files))]
check("NOD-14a", len(matching) == 0,
      f"copyfile should be filtered before grouping, got {matching}")
cleanup("lib/itslnew")
os.unlink(copyfile_manifest)


# ============================================================================
# NOD-15: Nested overlay symlink (grandchild)
# ============================================================================
print("\n=== NOD-15: Grandchild of overlay symlinked dir ===")
itsl_link = os.path.join(BUILD, "src", "itsl")
assert os.path.islink(itsl_link), \
    f"fixture precondition: {itsl_link} must be an overlay symlink (link-overlay creates it from overlay/src/itsl/)"
scratch_dir = os.path.join(OVERLAY, "src", "itsl", "__nod15_scratch__")
os.makedirs(scratch_dir)
scratch_file = os.path.join(scratch_dir, "NOD15Comp.vue")
with open(scratch_file, "w") as f:
    f.write("// component\n")

items = get_items()
matching = [i for i in items
            if (isinstance(i, NewFile) and "NOD15" in i.file) or
               (isinstance(i, NewOverlayDir) and any("NOD15" in f for f in i.files))]
check("NOD-15a", len(matching) == 0,
      f"file 2 levels deep in overlay symlink should be skipped, got {matching}")

# Also directly test the helper
result = _parent_is_overlay_symlink(
    "src/itsl/__nod15_scratch__/NOD15Comp.vue", BUILD, OVERLAY)
check("NOD-15b", result is True,
      f"_parent_is_overlay_symlink should catch src/itsl, got {result}")

os.unlink(scratch_file)
os.rmdir(scratch_dir)


# ============================================================================
# NOD-16: Empty new directory
# ============================================================================
print("\n=== NOD-16: Empty new directory ===")
scratch_dir = os.path.join(BUILD, "lib", "__nod16_scratch__")
os.makedirs(scratch_dir)
dirs = get_items(NewOverlayDir)
files = get_items(NewFile)
matching = ([d for d in dirs if "__nod16_scratch__" in d.dir] +
            [f for f in files if "__nod16_scratch__" in f.file])
check("NOD-16a", len(matching) == 0,
      f"empty dir should be invisible, got {matching}")
os.rmdir(scratch_dir)


# ============================================================================
# NOD-17: New directory with hidden files (dotfiles)
# ============================================================================
print("\n=== NOD-17: Directory with dotfiles ===")
mkfile("lib/itslnew/.hidden", "config\n")
mkfile("lib/itslnew/Visible.php", "<?php\n")
dirs = get_items(NewOverlayDir)
matching = [d for d in dirs if d.dir == "lib/itslnew"]
check("NOD-17a", len(matching) == 1,
      f"expected 1 NewOverlayDir, got {matching}")
if matching:
    # At least Visible.php should be there; .hidden depends on .gitignore
    check("NOD-17b", any("Visible.php" in f for f in matching[0].files),
          f"Visible.php should be in files: {matching[0].files}")
    check("NOD-17c", len(matching[0].files) >= 1,
          f"should have at least 1 file: {matching[0].files}")
    # If both present, verify sort order (. before V)
    if len(matching[0].files) == 2:
        check("NOD-17d", matching[0].files[0] < matching[0].files[1],
              f"files should be sorted: {matching[0].files}")
cleanup("lib/itslnew")


# ============================================================================
# NOD-18: Directory name close to gitignored pattern
# ============================================================================
print("\n=== NOD-18: Name close to gitignored pattern ===")
mkfile("node_modules_test/file.js")
dirs = get_items(NewOverlayDir)
matching = [d for d in dirs if d.dir == "node_modules_test"]
check("NOD-18a", len(matching) == 1,
      f"node_modules_test should not be gitignored, got {matching}")
cleanup("node_modules_test")


# ============================================================================
# NOD-19: Simultaneous new dir and modified upstream file
# ============================================================================
print("\n=== NOD-19: New dir + modified upstream file ===")
app_file = os.path.join(BUILD, "lib", "AppInfo", "Application.php")
with open(app_file, "r") as f:
    original = f.read()
with open(app_file, "w") as f:
    f.write(original.replace("<?php", "<?php\n// NOD-19 test", 1))
mkfile("lib/itslnew/New.php", "<?php\n")

items = get_items()
hunks = [i for i in items if isinstance(i, Hunk) and "Application.php" in i.file]
dirs = [i for i in items if isinstance(i, NewOverlayDir) and i.dir == "lib/itslnew"]
check("NOD-19a", len(hunks) >= 1,
      f"expected hunk for Application.php, got {hunks}")
check("NOD-19b", len(dirs) == 1,
      f"expected NewOverlayDir(lib/itslnew), got {dirs}")

# Restore
with open(app_file, "w") as f:
    f.write(original)
cleanup("lib/itslnew")


# ============================================================================
# NOD-20: New dir where overlay has a partial name match
# ============================================================================
print("\n=== NOD-20: Name prefix matches overlay file ===")
# upstream/composer/ exists (has autoload.php)
# overlay/composer.json exists but is a file, not related
mkfile("composer/extra/plugin.json", "{}\n")
dirs = get_items(NewOverlayDir)
matching = [d for d in dirs if "composer" in d.dir]
check("NOD-20a", len(matching) == 1,
      f"expected 1 NewOverlayDir, got {matching}")
if matching:
    check("NOD-20b", matching[0].dir == "composer/extra",
          f"dir should be composer/extra, got {matching[0].dir}")
    check("NOD-20c", matching[0].dir != "composer",
          "dir should not be 'composer' (exists in upstream)")
cleanup("composer/extra")


# ============================================================================
# Summary
# ============================================================================
print(f"\n{'='*60}")
print(f"Overlay dir tests: {passed} passed, {failed} failed")
print(f"{'='*60}")
sys.exit(1 if failed else 0)
