#!/usr/bin/env bash
# Wrapper for tests/test_state_integrity.py.
#
# Runs operator-environment pre-checks, executes the test, then runs
# post-checks. The pre-/post-checks live HERE (not in the python file)
# so a future agent reading the test code doesn't conflate them with
# the test's contract — they're our concern as test runners, not part
# of what the platform is being tested for.
#
# Pre-checks:
#   - tests/test_state_integrity.py uses ARC_BRANCH ("state-integrity-arc")
#     for all its commits. If that branch already exists, a prior run
#     crashed before its cleanup ran. We refuse to clobber it; operator
#     decides whether to inspect (`git -C apps/mail log state-integrity-arc`)
#     or delete (`git -C apps/mail branch -D state-integrity-arc`).
#   - apps/mail working tree clean against its current HEAD (otherwise
#     post-cleanup `git reset --hard` would lose the operator's
#     uncommitted work, and the test's stash-count comparison would be
#     unreliable).
#   - .build/ populated (capture_suite_start enforces this too, but
#     surfacing it earlier with a clearer message helps).
#   - No sidecar declared for this app (`docker/sidecars/<app>.yml`
#     absent). The chaos arc tests state-integrity invariants assuming
#     a single actor mutates `.build/`; a running sidecar is a second
#     actor (webpack recompiles + writeToDisk on file events the arc
#     fires). `/js/` is already excluded from the differential
#     comparison (test_state_integrity.py's `_DIFFERENTIAL_EXCLUDED_DIRS`),
#     so this isn't strictly necessary for correctness today, but
#     keeping the precondition keeps the test environment controlled
#     and avoids future invariants accidentally over-tested by sidecar
#     activity. Operator runs `make webpack MODE=off` in the app dir first.
#
# Post-checks (only run if the python test exited cleanly enough that
# its own cleanup got to run):
#   - apps/mail still on the same branch + commit it was at pre-test.
#   - ARC_BRANCH does not exist (cleanup C06 dropped it).
#   - apps/mail's stash count matches pre-test.
#
# If the python test crashed before cleanup, the post-checks are
# expected to fail; their job is to surface that to the operator.

set -euo pipefail

REPO="$(cd "$(dirname "$0")/.." && pwd)"
APP_ROOT="${APP_ROOT:-$REPO/apps/mail}"
ARC_BRANCH="state-integrity-arc"

# Use the same git env shape the test uses, so any git invocations
# through this wrapper agree on safe.directory + identity. The
# user.email/name are arbitrary defaults that satisfy git's identity
# check on machines without a global config (CI, fresh dev1 host); the
# wrapper does no commits, but we include them for parity with the
# test's GIT_ENV.
export GIT_CONFIG_COUNT=3
export GIT_CONFIG_KEY_0=safe.directory
export GIT_CONFIG_VALUE_0='*'
export GIT_CONFIG_KEY_1=user.email
export GIT_CONFIG_VALUE_1='state-integrity@local'
export GIT_CONFIG_KEY_2=user.name
export GIT_CONFIG_VALUE_2='state-integrity'

bar() { printf '=%.0s' {1..70}; printf '\n'; }

# --- Pre-checks ----------------------------------------------------------

bar
echo "tests/run_state_integrity.sh — pre-checks"
bar

if [ ! -d "$APP_ROOT" ]; then
    echo "FATAL: $APP_ROOT does not exist." >&2
    exit 2
fi

if [ ! -d "$APP_ROOT/.build/.git" ]; then
    echo "FATAL: $APP_ROOT/.build/.git does not exist." >&2
    echo "       Run \`make assemble MODE=force\` in apps/mail first." >&2
    exit 2
fi

APP_NAME="$(basename "$APP_ROOT")"
SIDECAR_FRAG="$REPO/docker/sidecars/$APP_NAME.yml"
if [ -f "$SIDECAR_FRAG" ]; then
    echo "FATAL: sidecar declared for $APP_NAME ($SIDECAR_FRAG exists)." >&2
    echo "       The chaos arc tests state-integrity invariants assuming a" >&2
    echo "       single actor mutates .build/; a running sidecar is a second" >&2
    echo "       actor. Stop it first:" >&2
    echo "         cd $APP_ROOT && make webpack MODE=off" >&2
    exit 2
fi

if git -C "$APP_ROOT" rev-parse --verify --quiet "$ARC_BRANCH" >/dev/null; then
    echo "FATAL: $ARC_BRANCH already exists in $APP_ROOT." >&2
    echo "       A prior run crashed before its cleanup ran. Inspect via:" >&2
    echo "         git -C $APP_ROOT log $ARC_BRANCH" >&2
    echo "       Then delete with:" >&2
    echo "         git -C $APP_ROOT branch -D $ARC_BRANCH" >&2
    echo "       once you're sure nothing valuable is on it." >&2
    exit 2
fi

if [ -n "$(git -C "$APP_ROOT" status --porcelain)" ]; then
    echo "FATAL: $APP_ROOT has uncommitted changes." >&2
    echo "       The test's cleanup does git reset --hard + git clean -fd," >&2
    echo "       which would lose this work. Commit, stash, or discard first." >&2
    git -C "$APP_ROOT" status --short >&2
    exit 2
fi

PRE_BRANCH="$(git -C "$APP_ROOT" rev-parse --abbrev-ref HEAD)"
PRE_COMMIT="$(git -C "$APP_ROOT" rev-parse HEAD)"
PRE_STASHES="$(git -C "$APP_ROOT" stash list | grep -c . || true)"

echo "  pre-test:"
echo "    branch:  $PRE_BRANCH"
echo "    commit:  ${PRE_COMMIT:0:8}"
echo "    stashes: $PRE_STASHES"

# --- Run the test --------------------------------------------------------

bar
echo "running tests/test_state_integrity.py"
bar

# -u for unbuffered stdout so the operator sees progress in real time.
# Block-buffering on regular-file stdout had a prior session thinking
# the test was hung when it wasn't — Python defaults to line-buffering
# only when stdout is a TTY; on a redirected stdout, output sits in the
# 4-KiB buffer until flushed at process exit. -u forces unbuffered.
set +e
python3 -u "$REPO/tests/test_state_integrity.py"
TEST_RC=$?
set -e

# --- Post-checks ---------------------------------------------------------

bar
echo "tests/run_state_integrity.sh — post-checks"
bar

# Capture post-test state regardless of test rc; we want to surface
# any drift even if the test itself succeeded.
POST_BRANCH="$(git -C "$APP_ROOT" rev-parse --abbrev-ref HEAD)"
POST_COMMIT="$(git -C "$APP_ROOT" rev-parse HEAD)"
POST_STASHES="$(git -C "$APP_ROOT" stash list | grep -c . || true)"
ARC_PRESENT=0
if git -C "$APP_ROOT" rev-parse --verify --quiet "$ARC_BRANCH" >/dev/null; then
    ARC_PRESENT=1
fi

echo "  post-test:"
echo "    branch:  $POST_BRANCH"
echo "    commit:  ${POST_COMMIT:0:8}"
echo "    stashes: $POST_STASHES"
echo "    $ARC_BRANCH present: $ARC_PRESENT"

POST_FAIL=0

if [ "$POST_BRANCH" != "$PRE_BRANCH" ]; then
    echo "  FAIL: branch drifted: $PRE_BRANCH → $POST_BRANCH" >&2
    POST_FAIL=1
fi

if [ "$POST_COMMIT" != "$PRE_COMMIT" ]; then
    echo "  FAIL: commit drifted: ${PRE_COMMIT:0:8} → ${POST_COMMIT:0:8}" >&2
    POST_FAIL=1
fi

if [ "$POST_STASHES" != "$PRE_STASHES" ]; then
    echo "  FAIL: stash count drifted: $PRE_STASHES → $POST_STASHES" >&2
    POST_FAIL=1
fi

if [ "$ARC_PRESENT" -ne 0 ]; then
    echo "  FAIL: $ARC_BRANCH still exists. Cleanup did not drop it." >&2
    echo "        (Inspect with \`git -C $APP_ROOT log $ARC_BRANCH\`," >&2
    echo "         then \`git -C $APP_ROOT branch -D $ARC_BRANCH\` to clean up.)" >&2
    POST_FAIL=1
fi

if [ "$POST_FAIL" -eq 0 ]; then
    echo "  post-checks: OK"
fi

bar

# Exit with the worst of test_rc and post_fail. If either is non-zero,
# we're non-zero. Test rc takes priority for the actual exit value so
# CI logs see the test's verdict; post-failures just print to stderr
# and force non-zero if test was zero.
if [ "$TEST_RC" -ne 0 ]; then
    exit "$TEST_RC"
fi
if [ "$POST_FAIL" -ne 0 ]; then
    exit 1
fi
exit 0
