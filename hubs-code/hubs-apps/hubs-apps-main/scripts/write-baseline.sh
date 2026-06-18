#!/usr/bin/env bash
set -euo pipefail

# write-baseline.sh — capture .build/'s current state as the git baseline.
#
# Required env vars:
#   BUILD_DIR  Path to .build/ directory (e.g. apps/<app>/.build/).
#
# Precondition: .build/.git already exists, info/exclude seeded, platform
# files (Makefile + webpack configs) installed. MODE=force is the sole
# bootstrapper; this script only commits a baseline INTO that repo. Every
# caller must run after a MODE=force. Crashes fast if unmet.
#
# .build/.gitignore is upstream's; this script writes nothing into it
# (upstream gitignores dep dirs: /vendor/, node_modules/, etc.).
#
# Things tracked in baseline (NOT gitignored, NOT info/excluded) — why
# each must stay tracked:
#
# - Overlay symlinks: tracked so `make quilt` sees a deleted symlink
#   (user removed it → "remove from overlay/") and prompts. Edits through
#   a symlink land in overlay/<file>, leaving the target unchanged and
#   thus invisible to `git diff baseline` — by contract overlay-file
#   edits belong to the parent repo, not make quilt.
#
# - .pc/ (quilt's per-patch backups): tracked so `git reset --hard
#   PENDING_TAG` restores .pc/ with the working tree. Untracked, a
#   mid-pop/push crash leaves .pc/ inconsistent with sources and the next
#   quilt op breaks.
#
# - composer.lock and vendor-bin/*/composer.{json,lock}: pinned to
#   ITSL-vetted versions. If composer install regenerates one, we
#   silently re-resolve from the public registry — the supply-chain
#   exposure the rebuild exists to prevent. Tracking surfaces it in
#   `make diff` before it ships.
#
# Things kept OUT via .git/info/exclude (seeded by MODE=force, not here):
# /.lock (the universal .build/ lock, held by the calling op, probed by
# diff.sh), /Makefile (the forwarder), /webpack.itsl.js + /webpack.hmr.js
# (platform configs). info/exclude (not .gitignore) so both `git add -A`
# and `git ls-files --others --exclude-standard` (quilt_extract.py's walk)
# honor it.
#
# Idempotent: each invocation adds a "baseline" commit and force-moves the
# baseline tag.
#
# Single source of truth for the baseline-capture sequence; shared by
# assemble.sh, quilt_verify.py (phase 6), and tests/_reset.sh.

: "${BUILD_DIR:?BUILD_DIR must be set}"

# Fail fast with a clear message if .build/.git is missing, rather than
# letting the `git config` calls below error obscurely.
if [ ! -d "$BUILD_DIR/.git" ]; then
    echo "ERROR: ${BUILD_DIR##*/}/.git does not exist — write-baseline.sh captures" >&2
    echo "       into an existing repo, it does not bootstrap one." >&2
    echo "       Run: make assemble MODE=force" >&2
    exit 1
fi

# safe.directory=* bypasses git's UID-mismatch refusal. Set here per
# script, not inherited — each caller sets its own. Cross-platform
# rationale in assemble.sh.
export GIT_CONFIG_COUNT=1
export GIT_CONFIG_KEY_0=safe.directory
export GIT_CONFIG_VALUE_0="*"

cd "$BUILD_DIR"

# Disable autocrlf locally: a global autocrlf=true|input would CRLF-mangle
# patch context, symlink-target blobs, and lockfiles. Unconditional, no
# host guard — we control .build/.git's byte content end-to-end.
git config core.autocrlf false

# core.symlinks=true stores overlay symlinks as mode 120000, not plain
# files holding the target path — overlay-deletion detection via `git diff
# baseline` depends on it. Explicit, not redundant: git's probe sets this
# on supported filesystems, but this is the guard for the untestable Mac
# OrbStack case. Must precede `git add -A` below.
git config core.symlinks true

# Identity: arbitrary but stable — .build/.git is never pushed, commits
# never leave the machine. "platform" (not "make-quilt") because the
# non-quilt callers (assemble, tests/_reset.sh) capture baselines too.
git config user.name "platform"
git config user.email "platform@local"

# Disable auto-gc. Each save adds loose objects; under default
# gc.auto=6700, git fires pack-objects from inside a later commit/stash/
# rebase — minutes of 99% CPU + multi-GB RSS on a populated tree, blocking
# the dev mid-make-quilt. Set both knobs to 0: gc.auto (loose-object
# threshold) and gc.autoPackLimit (separate, governs auto-repack of packs).
git config gc.auto 0
git config gc.autoPackLimit 0
git add -A
git commit -q -m "baseline" --allow-empty
git tag -f baseline
