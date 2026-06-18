"""Shared utilities and constants for the make quilt v2 modules.

Pure module: no make-quilt logic. Anything used by more than one of
quilt_v2 / quilt_extract / quilt_present / quilt_write / quilt_verify
lives here, so the modules don't drift or grow cross-private-imports.
"""

import contextlib
import fcntl
import os
import re
import subprocess
import sys


# ---------------------------------------------------------------------------
# Constants
# ---------------------------------------------------------------------------

# Tag in .build/.git anchoring the make-quilt-pending commit. Set at
# make-quilt entry (after extract, before present); dropped on successful
# save in verify_and_finalize, or by `make assemble MODE=recover` after a
# failed save. Centralised so every module agrees on the spelling.
PENDING_TAG = "make-quilt-pending"

# Platform root. This module lives at <platform_root>/scripts/, so two
# dirnames up — correct from any invocation context (host, container, test
# import). No env var, argument, or fallback: the file knows where it is.
PLATFORM_ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

# Substring printed at both pause-and-continue prompts in quilt_write (the
# routed-hunks `patch -p1 --merge` and cascade `quilt push -f --merge`
# failure paths). The chaos arc's smart resolver in test_state_integrity.py
# counts occurrences of this exact substring in tmux pane history to tell a
# new prompt from an already-handled one — rewording either site without
# lockstep breaks the count silently. The string IS the cross-file contract.
# Both sites and the test import this constant, so the coupling is single-sourced.
RESOLVE_PROMPT_SIGNATURE = "Resolve the markers in your editor"


# ---------------------------------------------------------------------------
# Subprocess wrapper
# ---------------------------------------------------------------------------

def run(cmd, cwd=None, env=None, check=True, input_data=None):
    """Run a subprocess command and return CompletedProcess.

    Decodes with utf-8/surrogateescape so non-UTF-8 bytes round-trip
    through str: patches may carry arbitrary bytes, surrogateescape
    preserves them.
    """
    result = subprocess.run(
        cmd, cwd=cwd, env=env, input=input_data,
        capture_output=True, text=True,
        encoding="utf-8", errors="surrogateescape")
    if check and result.returncode != 0:
        raise subprocess.CalledProcessError(
            result.returncode, cmd,
            output=result.stdout, stderr=result.stderr)
    return result


def assert_in_builder():
    """Refuse to run outside the dev-builder toolchain (or CI).

    make-quilt shells out to quilt/git/patch pinned in the dev-builder
    image — notably quilt 0.66, whose .pc/ format differs from other
    versions; a host toolchain corrupts .pc/ silently. Crash at the door
    with an actionable message instead.

    Accepts IN_BUILDER=1 (set by docker/compose.dev-builder.yml; covers
    both ephemeral one-shots and the IDE-attach container) or CI (same
    pinned base image). This checks toolchain version only — the separate
    blast-radius concern (which container is the right place for the work)
    is enforced by dc-run.sh at the make-invocation surface, not here.
    """
    if os.environ.get("IN_BUILDER") == "1" or os.environ.get("CI"):
        return
    sys.stderr.write(
        "ERROR: this must run inside the dev-builder container — the host "
        "toolchain is the wrong version.\n"
        "       Wrap the invocation in scripts/host/dc-run.sh.\n")
    sys.exit(2)


# ---------------------------------------------------------------------------
# Git env
# ---------------------------------------------------------------------------

def git_env():
    """Env dict for git ops on bind-mounted .build/.git: safe.directory='*'
    plus core.quotePath=false.

    safe.directory='*': `.build/.git` is owned by the host developer UID, so
    git's safe.directory check fires when that differs from the current user.
    The wildcard bypasses it per-invocation without touching global git config.

    core.quotePath=false: git otherwise octal-escapes and double-quotes any
    non-ASCII path in its output (`"a/caf\\303\\251.txt"`), which the path
    regexes in quilt_extract (`diff --git a/.. b/..`, `+++`/`---`) don't parse —
    a non-ASCII filename would crash extract. Forcing raw UTF-8 keeps them
    parseable.

    Fresh dict so callers can mutate without affecting others.
    """
    return {
        **os.environ,
        "GIT_CONFIG_COUNT": "2",
        "GIT_CONFIG_KEY_0": "safe.directory",
        "GIT_CONFIG_VALUE_0": "*",
        "GIT_CONFIG_KEY_1": "core.quotePath",
        "GIT_CONFIG_VALUE_1": "false",
    }


# ---------------------------------------------------------------------------
# Series file
# ---------------------------------------------------------------------------

def read_series(patches_dir, strip_suffix=False):
    """Read patches/series; return list of patch names in order.

    Missing file → empty list: quilt's legitimate "no patches yet" state
    for a fresh new-app, not error-hiding. Comment and blank lines filtered.

    Args:
        patches_dir: absolute path to apps/<app>/patches/.
        strip_suffix: drop the `.patch` suffix. True to compare against
            PresentResult keys (which omit it); False to match quilt's
            on-disk conventions.
    """
    series_path = os.path.join(patches_dir, "series")
    if not os.path.exists(series_path):
        return []
    with open(series_path) as f:
        names = [line.strip() for line in f
                 if line.strip() and not line.startswith("#")]
    if strip_suffix:
        return [n.removesuffix(".patch") for n in names]
    return names


# ---------------------------------------------------------------------------
# Diff-header parsing
# ---------------------------------------------------------------------------

def extract_file_path(file_header):
    """Get file path from a hunk's file_header. None if none parse.

    Three forms in order:
      `+++ b/path`         — standard target (modified/new).
      `--- a/path`         — deletions, where `+++` is `/dev/null`.
      `diff --git a/X b/Y` — binary diffs, which carry no `---`/`+++` lines.
    """
    for line in file_header.split("\n"):
        if line.startswith("+++ b/"):
            return line[6:].split("\t")[0].strip()
        if line.startswith("--- a/"):
            return line[6:].split("\t")[0].strip()
    m = re.match(r'^diff --git a/.+ b/(.+)$', file_header.split("\n")[0])
    if m:
        return m.group(1)
    return None


# ---------------------------------------------------------------------------
# .build/.lock contextmanager
# ---------------------------------------------------------------------------

class LockHeldError(Exception):
    """Raised by `locked()` when .build/.lock is held by another process.

    Contention is fail-fast per the .build/ lock policy — no wait-for-lock.
    Callers catch, emit an operator-actionable message, and exit non-zero
    (sys.exit(6) is the quilt_v2 convention; other tools may differ).
    """


@contextlib.contextmanager
def locked(build_dir):
    """Acquire an exclusive flock on build_dir/.lock; release on exit.

    Raises LockHeldError if another process holds the lock. `finally`
    ensures release even on sys.exit — SystemExit honors finally blocks.

    Do not unlink the lock file on release: that opens a TOCTOU race
    where acquirers lock different inodes at the same path and two hold
    it at once. Leaving the file keeps everyone on the same inode. It's
    registered in `.build/.git/info/exclude` (seeded by `make assemble
    MODE=force`) so it stays out of baseline commits and quilt's
    `--exclude-standard` walks.
    """
    lock_file = os.path.join(build_dir, ".lock")
    fd = os.open(lock_file, os.O_CREAT | os.O_RDWR, 0o664)
    try:
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        # EWOULDBLOCK from LOCK_NB — held by another process. Don't
        # widen to OSError: EBADF/EINVAL imply a bad fd we just opened,
        # so they're bugs, not contention, and must propagate uncaught.
        os.close(fd)
        raise LockHeldError()
    try:
        yield fd
    finally:
        fcntl.flock(fd, fcntl.LOCK_UN)
        os.close(fd)
