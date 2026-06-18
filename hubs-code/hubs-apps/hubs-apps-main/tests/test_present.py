#!/usr/bin/env python3
"""Unit tests for quilt_present helpers (PresentResult, name validation).

Invocation (from platform root):
  APP_ROOT="$PWD/apps/mail" bash scripts/host/dc-run.sh python3 /platform/tests/test_present.py
"""
import sys
import os
import re

sys.path.insert(0, os.path.join(os.path.dirname(__file__), '..', 'scripts'))
from quilt_present import PresentResult
from quilt_extract import Hunk

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


# ---------------------------------------------------------------------------
# P-RES: PresentResult tests
# ---------------------------------------------------------------------------
print("=== PresentResult ===")

# P-RES-01: total_assigned counts hunks + new_patch_files + deleted_files
r = PresentResult()
r.add_hunk("fix", "--- a/f.js\n+++ b/f.js", "@@ -1 +1 @@\n-old\n+new")
r.add_hunk("fix", "--- a/g.js\n+++ b/g.js", "@@ -1 +1 @@\n-old\n+new")
r.add_hunk("feat", "--- a/h.js\n+++ b/h.js", "@@ -1 +1 @@\n-old\n+new")
r.new_patch_files = {"fix": ["new1.js", "new2.js"]}
r.deleted_files = {"feat": ["old.js"]}
# 3 hunks + 2 new_patch_files + 1 deleted_files = 6
check("P-RES-01", r.total_assigned() == 6,
      f"expected 6, got {r.total_assigned()}")

# P-RES-02: summary_lines extracts file names from headers
r2 = PresentResult()
r2.add_hunk("fix", "--- a/src/foo.js\n+++ b/src/foo.js", "hunk1")
r2.add_hunk("fix", "--- a/lib/bar.js\n+++ b/lib/bar.js", "hunk2")
lines = r2.summary_lines()
check("P-RES-02a", len(lines) == 1, f"expected 1 line, got {len(lines)}")
patch, hunk_desc, file_list = lines[0]
check("P-RES-02b", patch == "fix", f"patch={patch}")
check("P-RES-02c", hunk_desc == "2 hunks", f"hunk_desc={hunk_desc}")
check("P-RES-02d", "bar.js" in file_list and "foo.js" in file_list,
      f"file_list={file_list}")

# P-RES-03: summary_lines with --- a/ fallback (no +++ b/ line)
r3 = PresentResult()
r3.add_hunk("fix", "--- a/path/fallback.js\nsome other line", "hunk")
lines = r3.summary_lines()
patch, hunk_desc, file_list = lines[0]
check("P-RES-03", "fallback.js" in file_list,
      f"expected fallback.js in file_list, got {file_list}")

# P-RES-04: summary_lines with no path found returns "?"
r4 = PresentResult()
r4.add_hunk("fix", "no paths here at all", "hunk")
lines = r4.summary_lines()
patch, hunk_desc, file_list = lines[0]
check("P-RES-04", "?" in file_list,
      f"expected '?' in file_list, got {file_list}")


# ---------------------------------------------------------------------------
# Additional PresentResult basics
# ---------------------------------------------------------------------------
print("\n=== PresentResult basics ===")

# Fresh result has sane defaults
r0 = PresentResult()
check("P-RES-INIT-assignments", r0.assignments == {}, f"got {r0.assignments}")
check("P-RES-INIT-overlay", r0.overlay_copies == [], f"got {r0.overlay_copies}")
check("P-RES-INIT-new-overlay", r0.new_overlay_files == [], f"got {r0.new_overlay_files}")
check("P-RES-INIT-new-patch", r0.new_patch_files == {}, f"got {r0.new_patch_files}")
check("P-RES-INIT-deleted", r0.deleted_files == {}, f"got {r0.deleted_files}")
check("P-RES-INIT-deleted-overlay", r0.deleted_overlay_files == [], f"got {r0.deleted_overlay_files}")
check("P-RES-INIT-skipped", r0.skipped == [], f"got {r0.skipped}")
check("P-RES-INIT-aborted", r0.aborted is False, f"got {r0.aborted}")
check("P-RES-INIT-total", r0.total_assigned() == 0, f"got {r0.total_assigned()}")

# add_hunk accumulates correctly
r5 = PresentResult()
r5.add_hunk("p1", "h1", "c1")
r5.add_hunk("p1", "h2", "c2")
r5.add_hunk("p2", "h3", "c3")
check("P-RES-ADD-accum", len(r5.assignments["p1"]) == 2 and len(r5.assignments["p2"]) == 1,
      f"got p1={len(r5.assignments.get('p1', []))}, p2={len(r5.assignments.get('p2', []))}")

# summary_lines: 1 hunk says "hunk" (singular)
r6 = PresentResult()
r6.add_hunk("single", "--- a/x.js\n+++ b/x.js", "c")
lines = r6.summary_lines()
check("P-RES-SINGULAR", lines[0][1] == "1 hunk", f"got {lines[0][1]}")


# ---------------------------------------------------------------------------
# P-NAME: Patch name validation regex
# ---------------------------------------------------------------------------
print("\n=== Patch name validation ===")

# The regex used in _ask_new_patch_name at line 226
NAME_RE = re.compile(r'^[a-z0-9][a-z0-9._-]*$')


def validate_name(raw, series=None):
    """Simulate _ask_new_patch_name's validation logic (without input()).

    Returns the normalized name if valid and not duplicate, else None.
    """
    if series is None:
        series = []
    if not raw:
        return None  # empty loops (P-NAME-06)
    patch = raw.lower().replace(" ", "-")
    if patch.endswith(".patch"):
        patch = patch[:-6]
    if not NAME_RE.match(patch):
        return None
    if patch in series:
        return None
    return patch


# P-NAME-01: Valid name
check("P-NAME-01", validate_name("my-fix") == "my-fix",
      f"got {validate_name('my-fix')}")

# P-NAME-02: Name with .patch suffix stripped
check("P-NAME-02", validate_name("my-fix.patch") == "my-fix",
      f"got {validate_name('my-fix.patch')}")

# P-NAME-03: Name with spaces normalized
check("P-NAME-03", validate_name("my fix") == "my-fix",
      f"got {validate_name('my fix')}")

# P-NAME-04: Invalid name (special chars)
check("P-NAME-04a", validate_name("my fix!") is None,
      "should reject 'my fix!' due to '!'")
check("P-NAME-04b", validate_name("my-fix") == "my-fix",
      "should accept 'my-fix' after rejection")

# P-NAME-05: Duplicate name
check("P-NAME-05a", validate_name("fix", series=["fix"]) is None,
      "should reject duplicate 'fix'")
check("P-NAME-05b", validate_name("fix2", series=["fix"]) == "fix2",
      "should accept 'fix2' when 'fix' exists")

# P-NAME-06: Empty input loops (returns None for empty)
check("P-NAME-06", validate_name("") is None,
      "empty string should return None")

# P-NAME-07: EOFError returns None
# Can't directly test EOFError without mocking input(), but we verify the
# code path exists by confirming the function handles it. We test the
# documented behavior: EOFError -> returns None.
from unittest.mock import patch as mock_patch
from quilt_present import _ask_new_patch_name

with mock_patch('builtins.input', side_effect=EOFError):
    name, _lines = _ask_new_patch_name([])
check("P-NAME-07", name is None, f"expected None, got {name}")


# ---------------------------------------------------------------------------
# Additional P-NAME edge cases (regex boundary testing)
# ---------------------------------------------------------------------------
print("\n=== Patch name regex edge cases ===")

# Must start with a-z or 0-9
check("P-NAME-EDGE-start-dot", validate_name(".hidden") is None,
      "should reject name starting with dot")
check("P-NAME-EDGE-start-dash", validate_name("-prefix") is None,
      "should reject name starting with dash")
check("P-NAME-EDGE-start-digit", validate_name("0day") == "0day",
      "should accept name starting with digit")

# Allowed characters in body: a-z 0-9 . _ -
check("P-NAME-EDGE-dots", validate_name("fix.v2") == "fix.v2",
      "dots allowed in body")
check("P-NAME-EDGE-underscore", validate_name("fix_it") == "fix_it",
      "underscores allowed in body")
check("P-NAME-EDGE-mixed", validate_name("fix-v2.3_final") == "fix-v2.3_final",
      "mixed separators allowed")

# Uppercase gets lowered
check("P-NAME-EDGE-upper", validate_name("MyFix") == "myfix",
      f"uppercase lowered, got {validate_name('MyFix')}")

# .patch suffix stripping only removes trailing .patch
check("P-NAME-EDGE-patch-middle", validate_name("fix.patchwork") == "fix.patchwork",
      ".patch in middle not stripped")
check("P-NAME-EDGE-double-patch",
      validate_name("fix.patch.patch") == "fix.patch",
      f"double .patch: got {validate_name('fix.patch.patch')}")

# Reject special characters
check("P-NAME-EDGE-slash", validate_name("a/b") is None,
      "slash rejected")
check("P-NAME-EDGE-space-only", validate_name("   ") is None,
      "whitespace-only rejected (becomes '---' which starts with dash)")
check("P-NAME-EDGE-at", validate_name("fix@2") is None,
      "@ rejected")


# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------
print(f"\n{passed} passed, {failed} failed")
sys.exit(1 if failed else 0)
