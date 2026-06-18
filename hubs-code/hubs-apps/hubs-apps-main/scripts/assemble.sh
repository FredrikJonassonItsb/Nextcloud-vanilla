#!/usr/bin/env bash
set -euo pipefail

# assemble.sh — four-mode dev assembly + production rsync.
#
# Mode is selected via the MODE env var. Exactly one mode or none, by
# construction: multi-mode combinations aren't syntactically expressible,
# so no runtime mutex check is needed.
#
#   (unset)          Plain. Stash + walk + stash pop. After `make quilt`
#                    saves a patch, or to reconcile after a branch switch.
#   MODE=force       Full source reset. Wipe .build/, rsync upstream, push,
#                    link overlay, re-init the .build/.git baseline + platform
#                    files. Installs are NOT done here — build.sh owns npm ci +
#                    composer install for every path (dev + CI); the
#                    `make assemble MODE=force` target chains build.sh's install
#                    step after this reset (see Makefile). For first bootstrap,
#                    lockfile changes, upstream pin bumps.
#   MODE=discard     Revert .build/ to the post-last-assemble baseline tag,
#                    then walk to reconcile to current series. Throws away
#                    .build/ edits.
#   MODE=recover     Restore .build/ from a leftover make-quilt-pending tag
#                    after a failed or crashed `make quilt`. The dev's
#                    explicit recovery gesture.
#   MODE=production  Lay the distributable into .dist/ (rsync upstream + quilt
#   MODE=snapshot    push + overlay as real files, no .build/ machinery).
#                    Identical for both modes here — the release (production)
#                    vs fast-dev (snapshot) flavor lives in build.sh/package.sh.
#                    Driven by CI (ci/validate.yml + ci/lint.yml).
#
# Plain has no explicit name: only the unset case routes to it. `MODE=plain`
# rejects. No `.build-ready` writes in any mode — webpack handles file churn
# natively.

# --- Parse mode ---

# No positional arguments. A legacy `--production` flag (muscle memory)
# would otherwise fall through to plain mode — wrong output dir, skipped
# production rsync — silently. Reject loudly instead.
[ "$#" -eq 0 ] || {
    echo "ERROR: assemble.sh takes no positional arguments (got: $*)." >&2
    echo "       Mode is selected via MODE=force/discard/recover/production/snapshot env var." >&2
    exit 2
}

MODE="${MODE:-}"
case "$MODE" in
    ''|force|discard|recover|production|snapshot) ;;
    *)
        echo "ERROR: MODE must be unset (plain) or one of: force, discard, recover, production, snapshot (got: \"$MODE\")" >&2
        exit 2
        ;;
esac

# --- Paths ---

PLATFORM_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
APP_ROOT="${APP_ROOT:-$(pwd)}"
UPSTREAM="$APP_ROOT/upstream"
PATCHES="$APP_ROOT/patches"
OVERLAY="$APP_ROOT/overlay"
COPYFILES_D="$APP_ROOT/overlay-copyfiles.d"

# Relative so .pc/.quilt_patches resolves from any mount point (container
# /app/.build/, host apps/<app>/.build/). All quilt invocations run from
# cwd=$BUILD_DIR, so ../patches → $APP_ROOT/patches. Quilt 0.66 writes this
# into .pc/.quilt_patches on first push and reads it back OVERRIDING the env
# var — so a stale absolute path in an existing .pc/.quilt_patches wins; the
# force-write after lock acquisition overwrites it directly.
export QUILT_PATCHES="../patches"

# safe.directory=* bypasses git's UID-mismatch refusal globally. In the
# Linux container the check passes on its own (host UID matches the
# container `developer` UID); this defends hosts where bind-mount ownership
# reporting diverges from container getuid() — e.g. Mac OrbStack VirtioFS,
# untestable from a Linux dev box. Exported so every git invocation and
# subshell inherits.
export GIT_CONFIG_COUNT=1
export GIT_CONFIG_KEY_0=safe.directory
export GIT_CONFIG_VALUE_0="*"

if [ "$MODE" = "production" ] || [ "$MODE" = "snapshot" ]; then
    BUILD_DIR="$APP_ROOT/.dist"
else
    BUILD_DIR="$APP_ROOT/.build"
fi
# Basename for operator-facing messages. BUILD_DIR is an in-container path
# (/app/.build, or /platform/apps/<app>/.build on the pool) the host operator
# can't cd to; messages print ".build"/".dist", which resolves from the app dir
# they ran make in. BUILD_DIR itself stays absolute for all real file ops.
BUILD_NAME="${BUILD_DIR##*/}"

# --- Sanity checks ---

# Refuse to run outside the dev-builder: assemble shells out to the
# version-pinned toolchain (quilt 0.66 — .pc/ format skew otherwise).
# dc-run.sh sets IN_BUILDER=1 inside the container; CI runs on the pinned
# base image. Mirrors quilt_common.assert_in_builder.
if [ "${IN_BUILDER:-}" != "1" ] && [ -z "${CI:-}" ]; then
    echo "ERROR: assemble.sh must run inside the dev-builder container —" >&2
    echo "       the host toolchain is the wrong version." >&2
    echo "       Wrap the invocation in scripts/host/dc-run.sh." >&2
    exit 2
fi

[ -e "$UPSTREAM/.git" ] || {
    echo "ERROR: upstream submodule not initialised at $UPSTREAM" >&2
    echo "       Run: git submodule update --init" >&2
    exit 1
}
[ -f "$PATCHES/series" ] || {
    echo "ERROR: patches/series not found at $PATCHES/series" >&2
    exit 1
}

# --- Helpers ---

# Wipe BUILD_DIR contents but keep the directory itself (preserves the
# Docker bind-mount inode — sidecars/nextcloud may have it bind-mounted
# while we work inside). Also keep .lock: dev modes hold the flock on it,
# so unlinking the inode would let a concurrent process open the path and
# get a fresh, unlocked inode. Production has no .lock (exits before the
# flock block), so the filter is a harmless no-op there.
#
# KEEP_CACHED_DEPS=1 (set by CI on snapshot/non-tag pipelines only; tag builds
# run clean with no cache — see ci/validate.yml) additionally preserves the
# top-level install-output dirs node_modules/,
# vendor/ and vendor-bin/. CI restores those from the GitLab dependency cache
# *before* this assemble runs; without the carve-out the wipe would delete the
# restore and force a full re-install every pipeline. The source tree below
# them is still wiped and re-laid fresh, so a code change is reflected; only
# the cached deps survive. lib/Vendor/ (Mozart output, app-configured nested
# path) is deliberately NOT preserved — it is regenerated from vendor/ by the
# app's composer post-install-cmd hook on every build (see build.sh), which
# keeps this carve-out app-agnostic. Unset locally, so plain MODE=force /
# MODE=production keep their full-clean semantics (corruption recovery,
# deterministic from-scratch build).
clean_build_dir() {
    if [ -d "$BUILD_DIR" ]; then
        if [ "${KEEP_CACHED_DEPS:-}" = 1 ]; then
            find "$BUILD_DIR" -mindepth 1 -maxdepth 1 \
                ! -name '.lock' ! -name 'node_modules' ! -name 'vendor' ! -name 'vendor-bin' \
                -exec rm -rf {} +
        else
            find "$BUILD_DIR" -mindepth 1 -maxdepth 1 ! -name '.lock' -exec rm -rf {} +
        fi
    else
        mkdir -p "$BUILD_DIR"
    fi
}

# Refresh the in-.build/ git baseline tag — reference point for MODE=discard
# (revert) and plain mode's "uncommitted changes" check. Called at end of
# plain / force / discard (recover captures no baseline; production uses no
# git baselines).
#
# Implementation in scripts/write-baseline.sh, shared with the make-quilt
# path: single source of truth for the add/commit/tag sequence. Requires
# .build/.git to already exist with info/exclude seeded — MODE=force does that.
write_baseline() {
    BUILD_DIR="$BUILD_DIR" \
        bash "$PLATFORM_ROOT/scripts/write-baseline.sh"
}

# --- Distributable path (production + snapshot) ---
#
# Early exit before the dev-mode setup below. Both modes operate on .dist/,
# not .build/ — separate directory and lifecycle, outside the .build/ mutex
# domain. The assemble is identical for production (customer release) and
# snapshot (fast dev); only the downstream install flavor (build.sh/package.sh)
# differs. Why it skips the dev-mode machinery: CI is sequential (no flock);
# the overlay ships real files so it uses rsync, not link-overlay.sh symlinks;
# clean_build_dir wipes any prior .pc/, so the .pc/.quilt_patches force-write
# is moot.

if [ "$MODE" = "production" ] || [ "$MODE" = "snapshot" ]; then
    echo "==> Distributable assemble ($MODE): $BUILD_NAME"
    clean_build_dir
    rsync -a --no-owner --no-group --omit-dir-times --exclude='.git' "$UPSTREAM/" "$BUILD_DIR/"

    cd "$BUILD_DIR"
    if [ -s "$PATCHES/series" ]; then
        echo "==> quilt push -a"
        quilt push -a --quiltrc=-
    fi

    # Overlay is optional: a pure-patch app (no ITSL additions, e.g. notes) has
    # no overlay/ dir. rsync of a missing source errors, so gate on existence —
    # the dev path's link_overlay tolerates this the same way (it iterates
    # overlay/* and finds nothing). Not error-hiding: "no overlay" is a real
    # app shape, not a missing precondition.
    if [ -d "$OVERLAY" ]; then
        echo "==> rsync overlay (production copy mode)"
        rsync -a --no-owner --no-group --omit-dir-times --exclude='.gitkeep' "$OVERLAY/" "$BUILD_DIR/"
    else
        echo "==> no overlay/ — pure-patch app, nothing to copy"
    fi
    echo "==> Assembly complete: $BUILD_NAME"
    exit 0
fi

# --- Concurrent-operation guard (flock) — dev modes only ---
#
# Universal .build/ mutex: every in-container .build/ toucher (assemble dev
# modes, quilt, diff, build dev, package dev, tests/_reset.sh) takes LOCK_EX on
# .build/.lock first. Sole exceptions: long-running webpack sidecars run outside
# the lock (holding a flock for hours isn't viable and webpack needs no
# coordination), and make l10n runs host-direct without the lock — operator
# discipline against concurrent assemble/quilt (l10n fix spawns claude, which
# needs the operator's host auth, so it can't run in-container; the lock went
# with the wrapper script that used to hold it). The lock file lives inside
# .build/ so it's removed only when .build/ is (make
# distclean; make clean keeps .build/), and shares an inode across every call
# site — quilt_v2.py's fcntl.flock and the flock(1) of assemble / diff / build /
# package / tests/_reset.sh — so it's one kernel VFS lock regardless of which
# tool holds it. (Production is outside this domain; it exits above.)

mkdir -p "$BUILD_DIR"
BUILD_LOCK="$BUILD_DIR/.lock"
exec 200>"$BUILD_LOCK"
if ! flock -n 200; then
    echo "ERROR: another .build/ operation is already running" >&2
    echo "       (lock held on .build/.lock; make status shows what's holding it)" >&2
    exit 1
fi

# Force the canonical relative value into .pc/.quilt_patches before any quilt
# op: quilt 0.66's read of this file overrides the env var, so a stale
# "/app/patches" from a prior run wins even with QUILT_PATCHES set. No-op when
# .pc/ doesn't exist (force first-bootstrap) — first push creates it.
[ -d "$BUILD_DIR/.pc" ] && echo "$QUILT_PATCHES" > "$BUILD_DIR/.pc/.quilt_patches"

# --- Source the overlay-symlink helpers (strip + link) ---
#
# Defines strip_overlay_symlinks and link_overlay, used by plain / discard /
# force. Production exits above — it rsyncs real files, not symlinks.

source "$PLATFORM_ROOT/scripts/link-overlay.sh"

# --- MODE=recover ---
#
# Restore .build/ from a leftover make-quilt-pending tag. One recovery
# gesture covers both graceful save failure (write_patches /
# verify_and_finalize raised) and SIGKILL-style death (make quilt ran no
# cleanup at all) — the same git-ops sequence below handles either.

if [ "$MODE" = "recover" ]; then
    if [ ! -d "$BUILD_DIR/.git" ]; then
        echo "ERROR: $BUILD_NAME has no git history — nothing to recover from." >&2
        echo "       Run: make assemble MODE=force" >&2
        exit 1
    fi
    if ! git -C "$BUILD_DIR" rev-parse --verify --quiet refs/tags/baseline >/dev/null; then
        echo "ERROR: baseline tag missing in $BUILD_NAME — can't recover." >&2
        echo "       Run: make assemble MODE=force" >&2
        exit 1
    fi

    pending_tag="make-quilt-pending"
    if ! git -C "$BUILD_DIR" rev-parse --verify --quiet "refs/tags/${pending_tag}" >/dev/null; then
        echo "ERROR: no ${pending_tag} tag in $BUILD_NAME — nothing to recover." >&2
        echo "       MODE=recover only runs when a previous make quilt left" >&2
        echo "       a pending tag set. If you want to revert .build/ to" >&2
        echo "       the post-last-assemble baseline, use MODE=discard instead." >&2
        exit 1
    fi

    echo "==> MODE=recover: restoring $BUILD_NAME from ${pending_tag}"
    cd "$BUILD_DIR"

    # Symlinks AND .pc/ are tracked in baseline, so `reset --hard pending`
    # restores them from pending's tree. No separate link-overlay step: if
    # the user deleted an overlay symlink before the failure, pending captured
    # that deletion, and re-linking would silently undo their intent.
    git reset --hard "${pending_tag}"

    # `reset --hard` leaves untracked files. quilt add writes .pc/<patch>/<file>
    # entries untracked until the next write_baseline; if write_patches raised
    # before then, they survive the reset above. A later extract would see them
    # via `git ls-files --others` and emit a NewOverlayDir prompt for .pc/ —
    # which the dev should never see (.pc/ is platform-managed). Restricted to
    # .pc/ so user scratches elsewhere aren't touched.
    git clean -fd .pc/

    # Mixed reset: HEAD → baseline, INDEX → baseline, WT untouched. Leaves
    # INDEX = baseline for the dev's next make-quilt, matching quilt_verify's
    # restore-skipped mechanic — so untracked scratches stay untracked at the
    # next extract, preserving the overlay/patch/skip prompt the dev expects.
    git reset baseline >/dev/null

    # Drop any dangling stash entries. Normally .build/.git's stash list is
    # empty (plain mode drops its stash on conflict-exit; make quilt uses tags,
    # not stashes), but a pathological prior run could leave one.
    git stash clear

    # Drop the tag last — once dropped, the orphan commit is reflog-only and
    # gets GC'd.
    git tag -d "${pending_tag}" >/dev/null

    # APP_NAME comes from dc-run.sh (host APP_ROOT basename, not the
    # in-container /app that `basename $APP_ROOT` would yield). Unset on
    # standalone host runs — there, basename APP_ROOT is correct.
    app_name="${APP_NAME:-$(basename "$APP_ROOT")}"
    echo "==> Recovered. $BUILD_NAME is at your pre-make-quilt state."
    echo "    If apps/${app_name}/{patches,overlay}/ has uncommitted"
    echo "    changes from the failed save,"
    echo "    \`git -C apps/${app_name} checkout -- patches overlay\`"
    echo "    reverts them."
    exit 0
fi

# --- MODE=discard ---

if [ "$MODE" = "discard" ]; then
    if [ ! -d "$BUILD_DIR/.git" ]; then
        echo "ERROR: $BUILD_NAME has no git history — nothing to revert to." >&2
        echo "       Run: make assemble MODE=force" >&2
        exit 1
    fi
    if ! git -C "$BUILD_DIR" rev-parse --verify --quiet refs/tags/baseline >/dev/null; then
        echo "ERROR: baseline tag missing in $BUILD_NAME — can't discard." >&2
        echo "       Run: make assemble MODE=force" >&2
        exit 1
    fi
    echo "==> MODE=discard: reverting $BUILD_NAME to baseline"
    cd "$BUILD_DIR"
    # Throw away ALL uncommitted state, not just tracked-modified, so the
    # walk + write_baseline below run on a fully clean WT — otherwise
    # write_baseline's `git add -A` would silently absorb leftover untracked
    # files into baseline content. reset --hard handles tracked-modified +
    # unmerged INDEX; clean -fd drops untracked scratches; stash clear drops
    # stash entries.
    git reset --hard baseline
    git clean -fd
    git stash clear

    # Drop the make-quilt-pending tag if present: an in-flight make-quilt
    # snapshot is uncommitted state, which DISCARD exists to clear, and
    # `reset --hard` orphans the commit but leaves the tag. "No pending tag"
    # is the common legitimate state, so the presence-check branches, it
    # doesn't error-hide.
    #
    # Tag name kept in lockstep with scripts/quilt_common.py PENDING_TAG —
    # change one, change both. (No clean cross-language shared constant in
    # bash; two literals is the cost.)
    pending_tag="make-quilt-pending"
    if git rev-parse --verify --quiet "refs/tags/${pending_tag}" >/dev/null; then
        git tag -d "${pending_tag}" >/dev/null
        echo "==> dropped leftover ${pending_tag} tag from interrupted make quilt"
    fi

    # Reconcile to the current series' all-applied state. After reset --hard
    # baseline the WT is whatever was assembled last — on a switched branch,
    # the OLD series. Without this walk, DISCARD on a switched branch leaves
    # .build/ at the wrong patch set. Same walk as plain mode (strip overlay →
    # pop -af → push -a → link → write_baseline) but no stash: DISCARD threw
    # the edits away, so there's nothing to preserve.
    echo "==> reconciling to current series"
    strip_overlay_symlinks "$BUILD_DIR"
    # File-based gates, not `quilt applied`/`quilt series`: those error
    # ("series file no longer matches the applied patches") when on-disk
    # series and .pc/applied-patches diverge — the cross-branch case this
    # walk fixes. Under pipefail the errored quilt makes the `if` false, so
    # the walk would skip the very pop+push needed to reconcile. Direct file
    # tests answer "is there content to act on?" without the refusal.
    if [ -s "$BUILD_DIR/.pc/applied-patches" ]; then
        echo "==> quilt pop -af"
        quilt pop -af --quiltrc=-
    fi
    if [ -s "$PATCHES/series" ]; then
        echo "==> quilt push -a"
        quilt push -a --quiltrc=-
    fi
    echo "==> link-overlay"
    link_overlay "$OVERLAY" "$BUILD_DIR"
    write_baseline

    echo "==> $BUILD_NAME reverted to baseline"
    exit 0
fi

# --- MODE=force ---

if [ "$MODE" = "force" ]; then
    echo "==> MODE=force: full rebuild"
    clean_build_dir

    echo "==> rsync upstream"
    rsync -a --no-owner --no-group --omit-dir-times --exclude='.git' "$UPSTREAM/" "$BUILD_DIR/"

    cd "$BUILD_DIR"
    if [ -s "$PATCHES/series" ]; then
        echo "==> quilt push -a"
        quilt push -a --quiltrc=-
    fi

    echo "==> link-overlay"
    link_overlay "$OVERLAY" "$BUILD_DIR"

    # --- Initialize .build/.git + seed both ignore files ---
    #
    # MODE=force owns ignore-file setup: it's the only mode that (re)creates
    # .build/.git and the only first-bootstrap path. Plain / discard / recover
    # operate on an already-bootstrapped .build/ and rely on both ignore files
    # persisting from a prior force.
    echo "==> initialize .build/.git + seed ignore files"
    git -C "$BUILD_DIR" init -q
    # B-local (.build/.git/info/exclude): platform-managed files force installs
    # into .build/ but must keep out of the baseline commit. Honored by
    # write-baseline.sh's `git add -A` and quilt_extract.py's `git ls-files
    # --others --exclude-standard`. .build/ is platform territory — overwrite
    # wholesale. Entries: /.lock (flock target), /Makefile (forwarder),
    # /webpack.{itsl,hmr}.js (sidecar configs) — all installed below.
    cat > "$BUILD_DIR/.git/info/exclude" <<'EOF'
/.lock
/Makefile
/webpack.itsl.js
/webpack.hmr.js
EOF
    # P-local (apps/<app>/.git/info/exclude): hide assemble-generated artifacts
    # from the parent app repo's git status. info/exclude is per-clone and
    # branch-independent — survives branch switches without a committed
    # .gitignore per branch. Parent repo is dev territory, so append-if-missing,
    # never overwrite — don't clobber the dev's own excludes.
    app_git_dir="$(git -C "$APP_ROOT" rev-parse --absolute-git-dir)"
    app_exclude="$app_git_dir/info/exclude"
    # GitLab runner checkouts can omit .git/info/ (template-less git init);
    # create it so the exclude seed below does not crash on a missing dir.
    mkdir -p "$app_git_dir/info"
    touch "$app_exclude"
    # `grep -qxF … || echo >> …` appends each missing entry. The `touch` above
    # guarantees the file exists, so grep's only non-zero is rc=1 (absent →
    # append); rc=2 (read error) can't occur — the `||` hides no real failure.
    for entry in '.build/' '.dist/' '*.tar.gz'; do
        grep -qxF "$entry" "$app_exclude" || echo "$entry" >> "$app_exclude"
    done

    # --- Install platform files into .build/ ---
    #
    # MODE=force owns this: these files come from the platform tree (not
    # upstream/ or overlay/) and are excluded from baseline via info/exclude
    # above. Since clean_build_dir keeps nothing but .lock, force is the sole
    # installer — plain / discard / recover assume they persist from a prior force.
    # The Makefile forwarder chains .build/Makefile → apps/<app>/Makefile →
    # $(PLATFORM_ROOT)/Makefile; sidecars read the webpack configs via
    # --config /app/webpack.hmr.js (compose.sh's generated fragment).
    echo "==> install platform files"
    echo 'include ../Makefile' > "$BUILD_DIR/Makefile"
    cp "$PLATFORM_ROOT/webpack/webpack.itsl.js" "$BUILD_DIR/webpack.itsl.js"
    cp "$PLATFORM_ROOT/webpack/webpack.hmr.js" "$BUILD_DIR/webpack.hmr.js"

    write_baseline
    echo "==> Assembly complete: $BUILD_NAME"
    exit 0
fi

# --- Plain mode (default) ---

if [ ! -d "$BUILD_DIR/.git" ]; then
    echo "ERROR: $BUILD_NAME not bootstrapped (no git history)." >&2
    echo "       Run: make assemble MODE=force" >&2
    exit 1
fi
if ! git -C "$BUILD_DIR" rev-parse --verify --quiet refs/tags/baseline >/dev/null; then
    echo "ERROR: baseline tag missing in $BUILD_NAME." >&2
    echo "       Run: make assemble MODE=force" >&2
    exit 1
fi

# Refuse on an interrupted make quilt (pending tag set). Recovery is the
# dev's explicit gesture (MODE=recover), not auto. This check must precede
# the walk: a stash + walk on pending-tag state would conflate pending
# content with the pre-quilt edits already captured in the pending commit.
#
# Tag name kept in lockstep with scripts/quilt_common.py PENDING_TAG —
# change one, change both.
pending_tag="make-quilt-pending"
if git -C "$BUILD_DIR" rev-parse --verify --quiet "refs/tags/${pending_tag}" >/dev/null; then
    echo "ERROR: $BUILD_NAME has an interrupted make quilt." >&2
    echo "       Run: make assemble MODE=recover" >&2
    echo "       to restore .build/ to your pre-quilt state, then re-run" >&2
    echo "       make quilt to retry the save." >&2
    exit 1
fi

echo "==> Plain assemble: stash → pop+push+relink → stash pop"

# Stash dev edits (tracked + untracked) before the walk, re-apply after, so
# the walk runs against a clean WT. Replaces a prior refuse-on-dirty contract
# that gave the natural edit + branch-switch + assemble workflow no path short
# of out-of-band cleanup.
#
# Detect empty-stash by entry-count delta, not exit code: `git stash push`
# returns rc=1 both for "nothing to save" (clean WT) and for real failures
# (disk full, fs error). Counting before/after distinguishes them without
# parsing stderr. Because rc=1 is ambiguous, also capture WT dirtiness up
# front: delta 0 on a CLEAN tree is the normal nothing-to-stash case; delta 0
# on a DIRTY tree means the stash genuinely failed — abort, because the walk
# would otherwise silently discard edits to patched files (quilt pop -af) and
# absorb edits to non-patched files into baseline (write_baseline's git add -A).
# This dirty-check is a postcondition guard, not an error-hiding pre-check.
#
# The stash message names the assembler so the dev recognizes the source in
# `git stash list`; the .build/.git stash list is otherwise empty (quilt uses
# tags, not stashes), so the count delta cleanly identifies our entry.
wt_dirty=$(git -C "$BUILD_DIR" status --porcelain)
pre_count=$(git -C "$BUILD_DIR" stash list | wc -l)
git -C "$BUILD_DIR" stash push --include-untracked -m make-assemble-stash --quiet || true
post_count=$(git -C "$BUILD_DIR" stash list | wc -l)
stashed=0
if [ "$post_count" -gt "$pre_count" ]; then
    stashed=1
    echo "==> stashed dev edits for the walk"
elif [ -n "$wt_dirty" ]; then
    echo "ERROR: .build/ has uncommitted changes but 'git stash push' produced no stash entry." >&2
    echo "       Refusing to run the walk — it would discard or absorb your unsaved edits." >&2
    echo "       Cause: unmerged paths from a prior conflict (git stash refuses those)," >&2
    echo "       or a disk/filesystem error during stash. Inspect: git -C $BUILD_NAME status" >&2
    exit 1
fi

# Step 4: strip overlay symlinks before pop.
strip_overlay_symlinks "$BUILD_DIR"

# Step 5: pop. `pop -af` (forced, snapshot-based), not `pop -a`: the
# `.pc/<patch>/<file>` snapshots are quilt's own backups, tracked in baseline,
# and survive cross-branch moves. `pop -a` instead reads each patch FILE under
# patches/ to compute the reverse delta, so missing or content-drifted patch
# files (the cross-branch case) make it fail "Patch X does not remove cleanly"
# even when the snapshots hold the bytes. In clean cases the two are identical.
#
# Gated on patches actually being applied — pop on nothing errors RC=2 ("No
# patch removed") regardless of -f, a legitimate state for a fresh app or
# post-manual-pop. The file gate (`-s`) is used, not `quilt applied | grep`,
# for the same reason as the discard walk: quilt's applied/series both error
# on cross-branch series/applied divergence, and under pipefail that would
# make the `if` false and skip the pop+push this walk exists to do.
cd "$BUILD_DIR"
if [ -s "$BUILD_DIR/.pc/applied-patches" ]; then
    echo "==> quilt pop -af"
    quilt pop -af --quiltrc=-
fi

# Step 6: push.
if [ -s "$PATCHES/series" ]; then
    echo "==> quilt push -a"
    quilt push -a --quiltrc=-
fi

# Step 7: relink overlay (creates fresh symlinks for current overlay/).
echo "==> link-overlay"
link_overlay "$OVERLAY" "$BUILD_DIR"

# Refresh baseline at the all-applied + relinked state.
write_baseline

# Pop the stash if we created one. Clean apply drops the entry
# automatically. On conflict, git writes conflict markers into WT and (by
# default) keeps the stash entry — but the markers are the recovery surface,
# so drop the stash explicitly: plain mode never leaves a stash behind, and
# the dev recovers via files + make quilt, not raw git on .build/.git.
if [ "$stashed" = "1" ]; then
    if ! git stash pop --quiet; then
        # Drop the now-redundant stash (its data is in WT as conflict markers).
        # stash@{0} is guaranteed here (pop preserves on conflict), so drop
        # should always succeed — surface the unexpected failure, don't swallow.
        if ! git stash drop --quiet; then
            echo "make assemble: WARNING: git stash drop --quiet failed after conflict-pop." >&2
        fi
        # Clear the unmerged index entries the conflicted `stash pop` staged.
        # The dev's edits + conflict markers stay in the WORKING TREE (mixed
        # reset doesn't touch it); only the index is reset to the just-written
        # baseline. Without this the index is left mid-merge, so the NEXT
        # `git stash push` (re-running assemble after resolving) fails with
        # "needs merge / could not write index" and wedges the dev with no
        # make-level way out. Resetting lets a re-run proceed (and re-conflict
        # if still unresolved) instead of dead-ending.
        git reset -q
        echo "make assemble: conflicts in your working tree." >&2
        echo "  Conflict markers (<<<<<<< / ======= / >>>>>>>) are in your files." >&2
        echo "  Resolve them in your editor, then run make quilt to save your edits." >&2
        exit 1
    fi
    echo "==> restored dev edits"
fi

echo "==> Assembly complete: $BUILD_NAME"
