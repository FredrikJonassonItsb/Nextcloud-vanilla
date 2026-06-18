#!/usr/bin/env bash
set -euo pipefail

# Test-suite reset helper. Resets an app to "upstream-only + no patches +
# no overlay edits" state without invoking `make assemble MODE=force`.
#
# Required env vars (set by dc-run.sh's mounts + env):
#   $APP_ROOT     → per-app dir inside the container — either /app
#                   (ephemeral routing: dc-run.sh's per-call bind) or
#                   /platform/apps/<APP_NAME> (pool routing: wholesale
#                   bind). dc-run.sh sets the env var either way.
#   $APP_ROOT/.build/    → the build dir
#   $APP_ROOT/upstream   → upstream source
#   $APP_ROOT/patches    → patches dir
#   $APP_ROOT/overlay    → overlay dir
#   /platform     → platform root (read-only mount, same path in both routes)
#
# Steps:
# 0. Acquire .build/.lock LOCK_EX — every in-container .build/ toucher
#    takes the same flock, so this reset can't interleave with a live
#    assemble / quilt / diff / build. (make l10n touches .build/ too but
#    runs host-direct, outside the lock — operator discipline.) Tests
#    shouldn't run concurrent with live ops, but the lock makes "races
#    impossible" a property, not a discipline, for those in-container ops.
#    Held for the lifetime of this script via fd 200 (kernel auto-releases
#    on exit).
# 1. Drop leftover make-quilt-pending tag (if any).
# 2. Strip overlay symlinks via link-overlay.sh (so the subsequent
#    rsync doesn't write through them).
# 3. rm -rf .pc/ (force-clean quilt state; idempotent + survives state
#    corruption from killed tests).
# 4. rsync upstream/ → .build/ with --delete to remove patch-added files.
#    Excludes preserve gitignored content (node_modules, vendor, etc.) so
#    the operator's one-time MODE=force stays effective; also excludes the
#    platform-installed files in .build/ that aren't in upstream/ —
#    /.lock, /Makefile, /webpack.itsl.js, /webpack.hmr.js — without the
#    excludes, rsync's --delete would remove them, churning the lock inode
#    and forcing MODE=force to reinstall the forwarder + webpack configs.
# 5. Clear patches/*.patch + empty series file.
# 6. link-overlay.sh link (recreate overlay symlinks).
# 7. write-baseline.sh (refresh baseline tag at the new state).
#
# Per Johan: "If they cant pass without a FORCE=1 that is an automatic fail."
# The operator runs `make assemble MODE=force` once before the test suite;
# this reset gets back to a clean per-test state in a few seconds.

APP_ROOT="${APP_ROOT:-$(pwd)}"

export GIT_CONFIG_COUNT=1
export GIT_CONFIG_KEY_0=safe.directory
export GIT_CONFIG_VALUE_0="*"

# Acquire .build/ lock — fd 200 held for script lifetime; kernel
# auto-releases when this shell exits.
exec 200>"$APP_ROOT/.build/.lock"
if ! flock -n 200; then
    echo "ERROR: another .build/ operation is already running in $APP_ROOT" >&2
    echo "       (lock held on $APP_ROOT/.build/.lock; make status shows what's holding it)" >&2
    exit 1
fi

cd "$APP_ROOT/.build"

if git rev-parse --verify --quiet refs/tags/make-quilt-pending >/dev/null; then
    git tag -d make-quilt-pending >/dev/null
fi

OVERLAY="$APP_ROOT/overlay" \
    bash /platform/scripts/link-overlay.sh strip "$APP_ROOT/.build"

rm -rf "$APP_ROOT/.build/.pc"

rsync -a --no-owner --no-group --omit-dir-times --delete \
    --exclude='.git' \
    --exclude='.gitignore' \
    --exclude='Makefile' \
    --exclude='webpack.itsl.js' \
    --exclude='webpack.hmr.js' \
    --exclude='.lock' \
    --exclude='node_modules' \
    --exclude='/vendor' \
    --exclude='/lib/Vendor' \
    --exclude='/vendor-bin' \
    "$APP_ROOT/upstream/" "$APP_ROOT/.build/"

rm -f "$APP_ROOT/patches/"*.patch
: > "$APP_ROOT/patches/series"

OVERLAY="$APP_ROOT/overlay" COPYFILES_D="$APP_ROOT/overlay-copyfiles.d" \
    bash /platform/scripts/link-overlay.sh link "$APP_ROOT/.build"

BUILD_DIR="$APP_ROOT/.build" \
    bash /platform/scripts/write-baseline.sh
