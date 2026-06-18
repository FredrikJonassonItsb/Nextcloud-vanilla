"""Tests for quilt_write.py helper functions.

Test IDs: W-PATH-01..05, W-PATH-NEW, W-DEL-01..02

The W-REST-01..06 tests for `restore_from_backup` were removed: that
function was deleted by the .build/.git-as-storage redesign (the prior shape kept saved-state in a per-call RAM dict; the rebuild uses `git show pending:<path>`
in `quilt_write.py`). Crash rollback for `.build/` now uses the pending
tag in `.build/.git`, recovered by the next make-quilt invocation.
Parent-repo state (patches/+overlay/) is the user's to clean up via
normal git tools after a crash — recovery is `.build/`-only by design.
The mechanism the W-REST tests exercised no longer exists.

Invocation (from platform root):
  APP_ROOT="$PWD/apps/mail" bash scripts/host/dc-run.sh python3 /platform/tests/test_write_helpers.py
"""

import os
import sys

# Allow importing from scripts/
sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "scripts"))

from quilt_common import extract_file_path
from quilt_write import _is_deletion

passed = 0
failed = 0


def check(test_id, condition, detail=""):
    global passed, failed
    if condition:
        print(f"PASS {test_id}")
        passed += 1
    else:
        print(f"FAIL {test_id}: {detail}")
        failed += 1


# ---------------------------------------------------------------------------
# extract_file_path
# ---------------------------------------------------------------------------

# W-PATH-01: +++ b/ prefix
header = "diff --git a/lib/Foo.php b/lib/Foo.php\n--- a/lib/Foo.php\n+++ b/lib/Foo.php"
result = extract_file_path(header)
check("W-PATH-01", result == "lib/Foo.php", f"got {result!r}")

# W-PATH-02: --- a/ fallback (deletion: +++ /dev/null, so --- a/ is used)
header = "diff --git a/lib/Foo.php b/lib/Foo.php\n--- a/lib/Foo.php\n+++ /dev/null"
result = extract_file_path(header)
check("W-PATH-02", result == "lib/Foo.php", f"got {result!r}")

# W-PATH-03: diff --git fallback (binary — no --- or +++ lines)
header = "diff --git a/img/x.bin b/img/x.bin"
result = extract_file_path(header)
check("W-PATH-03", result == "img/x.bin", f"got {result!r}")

# W-PATH-04: No recognizable path
header = "some random text\nno paths here"
result = extract_file_path(header)
check("W-PATH-04", result is None, f"got {result!r}")

# W-PATH-05: Tab-separated metadata after path
header = "diff --git a/lib/Foo.php b/lib/Foo.php\n--- a/lib/Foo.php\n+++ b/lib/Foo.php\t2026-04-01"
result = extract_file_path(header)
check("W-PATH-05", result == "lib/Foo.php", f"got {result!r}")

# Extra: new file (--- /dev/null, +++ b/lib/New.php)
header = "diff --git a/lib/New.php b/lib/New.php\n--- /dev/null\n+++ b/lib/New.php"
result = extract_file_path(header)
check("W-PATH-NEW", result == "lib/New.php", f"got {result!r}")

# ---------------------------------------------------------------------------
# _is_deletion
# ---------------------------------------------------------------------------

# W-DEL-01: +++ /dev/null present
header = "--- a/lib/Foo.php\n+++ /dev/null"
result = _is_deletion(header)
check("W-DEL-01", result is True, f"got {result!r}")

# W-DEL-02: Normal file (no /dev/null)
header = "--- a/lib/Foo.php\n+++ b/lib/Foo.php"
result = _is_deletion(header)
check("W-DEL-02", result is False, f"got {result!r}")

# W-DEL-03: Binary-deleted file_header — no +++/--- lines (replaced
# by `Binary files ... and /dev/null differ`), only `deleted file mode`
# signals the deletion. Verifies _is_deletion handles the binary-deleted
# case introduced by the F1/F18 parser fix.
header = ("diff --git a/foo.bin b/foo.bin\n"
          "deleted file mode 100644\n"
          "Binary files a/foo.bin and /dev/null differ")
result = _is_deletion(header)
check("W-DEL-03", result is True, f"got {result!r}")

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------
print(f"\n{passed} passed, {failed} failed out of {passed + failed}")
sys.exit(1 if failed else 0)
