#!/usr/bin/env python3
"""Unit tests for quilt_extract._parse_diff_output and class __repr__ methods.

Invocation (from platform root):
  APP_ROOT="$PWD/apps/mail" bash scripts/host/dc-run.sh python3 /platform/tests/test_extract.py
"""
import sys
import os

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', 'scripts'))
from quilt_extract import _parse_diff_output, Hunk, OverlayCopy, NewFile, DeletedFile, OverlayDeletion, OverlayRevert, PermissionChange

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


def check_raises(test_id, fn, exc=RuntimeError, detail=""):
    global passed, failed
    try:
        fn()
    except exc:
        passed += 1
        print(f"  PASS {test_id}")
        return
    except Exception as e:  # noqa: BLE001 - wrong exception type is a failure
        failed += 1
        print(f"  FAIL {test_id}: expected {exc.__name__}, "
              f"raised {type(e).__name__}: {e}")
        return
    failed += 1
    print(f"  FAIL {test_id}: {detail or f'expected {exc.__name__}, nothing raised'}")


# ---------------------------------------------------------------------------
# E-PARSE tests
# ---------------------------------------------------------------------------

print("=== E-PARSE: _parse_diff_output ===")

# E-PARSE-01: Empty diff text
result = _parse_diff_output("")
check("E-PARSE-01a", result == [], f"expected [], got {result}")
result = _parse_diff_output(None)
check("E-PARSE-01b", result == [], f"expected [], got {result}")
result = _parse_diff_output("   \n  \n  ")
check("E-PARSE-01c", result == [], f"whitespace-only should return [], got {result}")

# E-PARSE-02: Single hunk, modified file
diff_02 = (
    "diff --git a/lib/Foo.php b/lib/Foo.php\n"
    "index abc1234..def5678 100644\n"
    "--- a/lib/Foo.php\n"
    "+++ b/lib/Foo.php\n"
    "@@ -10,6 +10,7 @@ class Foo\n"
    "     existing line\n"
    "+    added line\n"
    "     another line\n"
)
result = _parse_diff_output(diff_02)
check("E-PARSE-02a", len(result) == 1, f"expected 1 hunk, got {len(result)}")
if result:
    h = result[0]
    check("E-PARSE-02b", isinstance(h, Hunk), f"expected Hunk, got {type(h)}")
    check("E-PARSE-02c", h.file == "lib/Foo.php", f"file={h.file!r}")
    check("E-PARSE-02d", h.type == "modified", f"type={h.type!r}")
    check("E-PARSE-02e", h.header.startswith("@@ -10,6 +10,7"), f"header={h.header!r}")
    check("E-PARSE-02f", "+    added line" in h.content, f"content missing added line")
    check("E-PARSE-02g", "diff --git" in h.file_header, f"file_header missing diff line")
    check("E-PARSE-02h", "--- a/lib/Foo.php" in h.file_header, f"file_header missing ---")
    check("E-PARSE-02i", "+++ b/lib/Foo.php" in h.file_header, f"file_header missing +++")

# E-PARSE-03: Multiple hunks, same file
diff_03 = (
    "diff --git a/lib/Bar.php b/lib/Bar.php\n"
    "index abc1234..def5678 100644\n"
    "--- a/lib/Bar.php\n"
    "+++ b/lib/Bar.php\n"
    "@@ -5,3 +5,4 @@ class Bar\n"
    "     line a\n"
    "+    add a\n"
    "     line b\n"
    "@@ -20,3 +21,4 @@ function baz()\n"
    "     line c\n"
    "+    add c\n"
    "     line d\n"
)
result = _parse_diff_output(diff_03)
check("E-PARSE-03a", len(result) == 2, f"expected 2 hunks, got {len(result)}")
if len(result) == 2:
    check("E-PARSE-03b", result[0].file == result[1].file == "lib/Bar.php",
          f"files: {result[0].file!r}, {result[1].file!r}")
    check("E-PARSE-03c", result[0].file_header == result[1].file_header,
          "file_header should be same for both hunks")
    check("E-PARSE-03d", result[0].header != result[1].header,
          "headers should differ")
    check("E-PARSE-03e", "+    add a" in result[0].content, "first hunk content")
    check("E-PARSE-03f", "+    add c" in result[1].content, "second hunk content")

# E-PARSE-04: Multiple files
diff_04 = (
    "diff --git a/fileA.php b/fileA.php\n"
    "index abc..def 100644\n"
    "--- a/fileA.php\n"
    "+++ b/fileA.php\n"
    "@@ -1,3 +1,4 @@\n"
    " ctx\n"
    "+addA\n"
    " ctx\n"
    "diff --git a/fileB.js b/fileB.js\n"
    "index abc..def 100644\n"
    "--- a/fileB.js\n"
    "+++ b/fileB.js\n"
    "@@ -1,3 +1,4 @@\n"
    " ctx\n"
    "+addB\n"
    " ctx\n"
)
result = _parse_diff_output(diff_04)
files = [h.file for h in result]
check("E-PARSE-04a", len(result) == 2, f"expected 2 hunks, got {len(result)}")
check("E-PARSE-04b", "fileA.php" in files and "fileB.js" in files,
      f"files: {files}")

# E-PARSE-05: New file detection
diff_05 = (
    "diff --git a/newfile.txt b/newfile.txt\n"
    "new file mode 100644\n"
    "index 0000000..abc1234\n"
    "--- /dev/null\n"
    "+++ b/newfile.txt\n"
    "@@ -0,0 +1,2 @@\n"
    "+line one\n"
    "+line two\n"
)
result = _parse_diff_output(diff_05)
check("E-PARSE-05a", len(result) == 1, f"expected 1 hunk, got {len(result)}")
if result:
    check("E-PARSE-05b", result[0].type == "new", f"type={result[0].type!r}")

# E-PARSE-06: Deleted file detection
diff_06 = (
    "diff --git a/old.php b/old.php\n"
    "deleted file mode 100644\n"
    "index abc1234..0000000\n"
    "--- a/old.php\n"
    "+++ /dev/null\n"
    "@@ -1,3 +0,0 @@\n"
    "-line one\n"
    "-line two\n"
    "-line three\n"
)
result = _parse_diff_output(diff_06)
check("E-PARSE-06a", len(result) == 1, f"expected 1 hunk, got {len(result)}")
if result:
    check("E-PARSE-06b", result[0].type == "deleted", f"type={result[0].type!r}")

# E-PARSE-07: Binary file detection
diff_07 = (
    "diff --git a/image.png b/image.png\n"
    "index abc..def 100644\n"
    "Binary files a/image.png and b/image.png differ\n"
)
result = _parse_diff_output(diff_07)
check("E-PARSE-07a", len(result) == 1, f"expected 1 hunk, got {len(result)}")
if result:
    h = result[0]
    check("E-PARSE-07b", h.type == "binary", f"type={h.type!r}")
    check("E-PARSE-07c", h.header == "", f"header={h.header!r}")
    check("E-PARSE-07d", h.content == "", f"content should be empty")
    check("E-PARSE-07e", "Binary files" in h.file_header,
          f"file_header should include Binary files line")

# E-PARSE-08: Permission-only change (no hunks, has mode lines)
diff_08 = (
    "diff --git a/script.sh b/script.sh\n"
    "old mode 100644\n"
    "new mode 100755\n"
)
result = _parse_diff_output(diff_08)
check("E-PARSE-08a", len(result) == 1, f"expected 1 item, got {len(result)}")
if result:
    check("E-PARSE-08b", isinstance(result[0], PermissionChange),
          f"expected PermissionChange, got {type(result[0])}")
    check("E-PARSE-08c", result[0].file == "script.sh", f"file={result[0].file!r}")

# E-PARSE-09: empty-file deletion (deleted file mode, no @@ hunk because an
# empty file has no content to diff) -> skipped in the parse; extract_hunks's
# --diff-filter=D sweep recovers it (as DeletedFile / OverlayDeletion /
# OverlayRevert), the same path every structurally-missed deletion takes.
diff_09 = (
    "diff --git a/empty.txt b/empty.txt\n"
    "deleted file mode 100644\n"
    "index e69de29..0000000\n"
)
result = _parse_diff_output(diff_09)
check("E-PARSE-09a", len(result) == 0,
      f"empty-file deletion should be skipped (D-sweep recovers), got {len(result)}")

# E-PARSE-09b: a no-@@/no-mode section that is NOT a recognised shape is a
# can't-happen state -> raise rather than silently drop a real change.
diff_09b = (
    "diff --git a/mystery.txt b/mystery.txt\n"
    "index abc..def 100644\n"
)
check_raises("E-PARSE-09b", lambda: _parse_diff_output(diff_09b),
             detail="unknown no-hunk section should raise")

# E-PARSE-09c: a rename/copy (only reaches the diff if staged or git-mv'd,
# which the make-quilt flow never does) -> raise with a clear cause.
diff_09c = (
    "diff --git a/old.txt b/new.txt\n"
    "similarity index 100%\n"
    "rename from old.txt\n"
    "rename to new.txt\n"
)
check_raises("E-PARSE-09c", lambda: _parse_diff_output(diff_09c),
             detail="rename section should raise")

# E-PARSE-10: a genuinely malformed `diff --git` line (no a/.. b/.. — git never
# emits this for a real change with core.quotePath=false) -> raise, never drop.
diff_10 = (
    "diff --git MALFORMED\n"
    "index abc..def 100644\n"
    "--- a/foo.txt\n"
    "+++ b/foo.txt\n"
    "@@ -1,3 +1,4 @@\n"
    " ctx\n"
    "+add\n"
)
check_raises("E-PARSE-10a", lambda: _parse_diff_output(diff_10),
             detail="malformed diff --git should raise")

# E-PARSE-10b: a non-ASCII filename parses. git_env sets core.quotePath=false so
# git emits the raw UTF-8 path (a/café.txt, not "a/caf\303\251.txt"); the regex
# must accept it rather than crash.
diff_10b = (
    "diff --git a/café.txt b/café.txt\n"
    "index abc..def 100644\n"
    "--- a/café.txt\n"
    "+++ b/café.txt\n"
    "@@ -1 +1 @@\n"
    "-old\n"
    "+new\n"
)
result = _parse_diff_output(diff_10b)
check("E-PARSE-10b", len(result) == 1 and result[0].file == "café.txt",
      f"expected 1 hunk for café.txt, got {[getattr(x, 'file', x) for x in result]}")

# E-PARSE-10c: a path component ending in " b" (e.g. a directory named "a b")
# makes the `diff --git a/X b/X` line ambiguous (git emits it unquoted). The
# a==b backreference must still recover the full path, not a greedy mis-split.
diff_10c = (
    "diff --git a/a b/file.txt b/a b/file.txt\n"
    "index 587be6b..62d8fe9 100644\n"
    "--- a/a b/file.txt\n"
    "+++ b/a b/file.txt\n"
    "@@ -1 +1 @@\n"
    "-x\n"
    "+X\n"
)
result = _parse_diff_output(diff_10c)
check("E-PARSE-10c", len(result) == 1 and result[0].file == "a b/file.txt",
      f"expected file 'a b/file.txt', got {[getattr(x, 'file', x) for x in result]}")

# E-PARSE-11: Trailing double newline stripping
diff_11 = (
    "diff --git a/strip.txt b/strip.txt\n"
    "index abc..def 100644\n"
    "--- a/strip.txt\n"
    "+++ b/strip.txt\n"
    "@@ -1,3 +1,4 @@\n"
    " ctx\n"
    "+added\n"
    " ctx\n"
    "\n"  # extra trailing newline -> content would end \n\n
)
result = _parse_diff_output(diff_11)
check("E-PARSE-11a", len(result) == 1, f"expected 1 hunk, got {len(result)}")
if result:
    check("E-PARSE-11b", not result[0].content.endswith("\n\n"),
          "content should not end with double newline")
    check("E-PARSE-11c", result[0].content.endswith("\n"),
          "content should end with single newline")

# E-PARSE-12: File with @@ in content (not a hunk header)
diff_12 = (
    "diff --git a/docs.txt b/docs.txt\n"
    "index abc..def 100644\n"
    "--- a/docs.txt\n"
    "+++ b/docs.txt\n"
    "@@ -1,3 +1,4 @@\n"
    " existing\n"
    "+This line has @@ something @@ in it\n"
    " trailing\n"
)
result = _parse_diff_output(diff_12)
check("E-PARSE-12a", len(result) == 1, f"expected 1 hunk (not split), got {len(result)}")
if result:
    check("E-PARSE-12b", "@@ something @@" in result[0].content,
          "content line with @@ should be preserved")

# E-PARSE-13: File with +++ in content (not a file header)
diff_13 = (
    "diff --git a/code.txt b/code.txt\n"
    "index abc..def 100644\n"
    "--- a/code.txt\n"
    "+++ b/code.txt\n"
    "@@ -1,3 +1,4 @@\n"
    " existing\n"
    "++++ this is not a file header\n"
    " trailing\n"
)
result = _parse_diff_output(diff_13)
check("E-PARSE-13a", len(result) == 1, f"expected 1 hunk, got {len(result)}")
if result:
    check("E-PARSE-13b", "+++ this is not a file header" in result[0].content,
          "content +++ line should be in content, not file_header")
    check("E-PARSE-13c", "+++ b/code.txt" in result[0].file_header,
          "real +++ should be in file_header")

# E-PARSE-14: a bare `diff --git` line with no section body (no index / mode /
# @@). git never emits this for a real change, so it's a can't-happen shape ->
# raise rather than silently return nothing and drop a possible change.
diff_14 = "diff --git a/lonely.txt b/lonely.txt\n"
check_raises("E-PARSE-14a", lambda: _parse_diff_output(diff_14),
             detail="bare diff --git with empty section should raise")


# ---------------------------------------------------------------------------
# E-REPR tests
# ---------------------------------------------------------------------------

print("\n=== E-REPR: __repr__ methods ===")

# E-REPR-01: Hunk repr
h = Hunk(
    file="lib/Foo.php",
    header="@@ -10,6 +10,7 @@",
    content="@@ -10,6 +10,7 @@\n ctx\n+add\n ctx\n",
    file_header="diff --git a/lib/Foo.php b/lib/Foo.php\n--- a/lib/Foo.php\n+++ b/lib/Foo.php\n",
    hunk_type="modified",
    patches=["fix-login"]
)
r = repr(h)
check("E-REPR-01a", "lib/Foo.php" in r, f"repr missing file: {r}")
check("E-REPR-01b", "modified" in r, f"repr missing type: {r}")
check("E-REPR-01c", "4 lines" in r, f"repr should show line count: {r}")
check("E-REPR-01d", "fix-login" in r, f"repr missing patches: {r}")

# E-REPR-02: OverlayCopy repr
oc = OverlayCopy(file="css/styles.css")
r = repr(oc)
check("E-REPR-02a", "css/styles.css" in r, f"repr missing file: {r}")
check("E-REPR-02b", "OverlayCopy" in r, f"repr missing class name: {r}")

# E-REPR-03: NewFile repr
nf = NewFile(file="js/new.js")
r = repr(nf)
check("E-REPR-03a", "js/new.js" in r, f"repr missing file: {r}")
check("E-REPR-03b", "NewFile" in r, f"repr missing class name: {r}")

# E-REPR-04: DeletedFile repr
df = DeletedFile(file="old/removed.php", patches=["cleanup"])
r = repr(df)
check("E-REPR-04a", "old/removed.php" in r, f"repr missing file: {r}")
check("E-REPR-04b", "cleanup" in r, f"repr missing patches: {r}")
check("E-REPR-04c", "DeletedFile" in r, f"repr missing class name: {r}")

# E-REPR-04d: OverlayDeletion repr
od = OverlayDeletion(file="src/itsl/foo.vue")
r = repr(od)
check("E-REPR-04d-a", "src/itsl/foo.vue" in r, f"repr missing file: {r}")
check("E-REPR-04d-b", "OverlayDeletion" in r, f"repr missing class name: {r}")

# E-REPR-04e: OverlayRevert repr — companion to OverlayDeletion
# for the upstream-counterpart-present case).
ovr = OverlayRevert(file="appinfo/info.xml")
r = repr(ovr)
check("E-REPR-04e-a", "appinfo/info.xml" in r, f"repr missing file: {r}")
check("E-REPR-04e-b", "OverlayRevert" in r, f"repr missing class name: {r}")

# E-REPR-05: PermissionChange repr
pc = PermissionChange(file="bin/run.sh")
r = repr(pc)
check("E-REPR-05a", "bin/run.sh" in r, f"repr missing file: {r}")
check("E-REPR-05b", "PermissionChange" in r, f"repr missing class name: {r}")


# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------

print(f"\n{passed} passed, {failed} failed")
sys.exit(1 if failed else 0)
