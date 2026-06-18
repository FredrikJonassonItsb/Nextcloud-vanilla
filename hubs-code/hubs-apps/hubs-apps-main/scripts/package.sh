#!/usr/bin/env bash
set -euo pipefail

# package.sh — stamp + tar an already-built app tree into <app>.tar.gz.
#
# The build stage (build.sh) has already installed deps, wrapped Mozart, and
# compiled JS into the source dir; package.sh only stamps the version and tars.
# Three modes, dispatched on MODE:
#   (unset)          dev — source .build/ (deps + lib/Vendor/ + js/ already in
#                    place from `make assemble MODE=force` + `make build`). No
#                    stamp, just tar. Ships dev composer deps: fine for dev1, not
#                    for customer-facing artifacts.
#   MODE=snapshot    source .dist/. Optional version stamp, then tar. The fast
#                    dev distributable (CI default on non-tag pipelines).
#   MODE=production  source .dist/ (built with composer --no-dev + optimized
#                    autoload). Optional stamp, then tar. The customer artifact.
#
# Runs in-container (dev-builder, no host npm/composer — supply-chain
# isolation). Output: $APP_ROOT/$APP_NAME.tar.gz (host-visible via bind mount).

APP_ROOT="${APP_ROOT:-$(pwd)}"
APP_NAME="${APP_NAME:-$(basename "$APP_ROOT")}"
OUTPUT="$APP_ROOT/$APP_NAME.tar.gz"

# MODE dispatch — source dir + dev-mode preconditions. Dev: refuse if a
# sidecar is declared (its webpack writeToDisk would race the tar), and
# take the .build/.lock mutex (vs a concurrent assemble/quilt/diff/build —
# every in-container .build/ toucher takes the same lock; make l10n is
# host-direct and outside it, guarded by operator discipline). Production:
# no lock — .dist/ is outside the lock's domain.
MODE="${MODE:-}"
case "$MODE" in
    production|snapshot)
        # Both source the clean .dist/ tree (see build.sh / assemble.sh): MODE
        # picks the flavor — production = --no-dev + optimized autoload (customer
        # artifact); snapshot = dev deps, non-optimized (fast, CI default on
        # non-tag pipelines). Both Mozart-wrap and version-stamp identically.
        SOURCE_DIR="$APP_ROOT/.dist"
        MODE_DISPLAY="$MODE"
        ;;
    "")
        SOURCE_DIR="$APP_ROOT/.build"
        MODE_DISPLAY="dev"
        SIDECAR_FRAG="/platform/docker/sidecars/${APP_NAME}.yml"
        [ ! -f "$SIDECAR_FRAG" ] || {
            echo "ERROR: sidecar declared for $APP_NAME — stop with: make webpack MODE=off" >&2
            exit 1
        }
        exec 200>"$APP_ROOT/.build/.lock"
        if ! flock -n 200; then
            echo "ERROR: another .build/ operation is already running in $APP_ROOT" >&2
            echo "       (lock held on $APP_ROOT/.build/.lock; make status shows what's holding it)" >&2
            exit 1
        fi
        ;;
    *)
        echo "ERROR: MODE must be empty, 'snapshot', or 'production' (got: \"$MODE\")." >&2
        exit 1
        ;;
esac

# js/ must exist for a JS app — failing loud beats a JS-less tarball that
# silently breaks at runtime in the consumer NC. A PHP-only app (no package.json)
# legitimately ships no js/, so gate the check on the manifest's presence.
if [ -f "$SOURCE_DIR/package.json" ] && [ ! -d "$SOURCE_DIR/js" ]; then
    echo "ERROR: No JS build found at $SOURCE_DIR/js/." >&2
    if [ -n "$MODE" ]; then
        echo "       Run: make build MODE=$MODE" >&2
    else
        echo "       Run: make build (or start a sidecar: make webpack MODE=hmr|watch)" >&2
    fi
    exit 1
fi

# appinfo/info.xml is the NC manifest. The packaging tar below includes
# only entries that exist, so a missing appinfo/ would silently produce a
# manifest-less tarball that "deploys successfully" then vanishes from
# `occ app:list`. Fail loud instead.
if [ ! -f "$SOURCE_DIR/appinfo/info.xml" ]; then
    echo "ERROR: No app manifest at $SOURCE_DIR/appinfo/info.xml." >&2
    if [ "$MODE" = "production" ]; then
        echo "       The .dist/ tree is incomplete — run: make build MODE=production" >&2
    else
        echo "       The .build/ tree is incomplete — run: make assemble" >&2
    fi
    exit 1
fi

cd "$SOURCE_DIR"

# --- Version stamp (CI-driven; distributable builds only) ---
#
# A non-empty MODE is a distributable build (snapshot or production); the dev
# default (MODE='') just tars the already-assembled .build/ and skips the stamp.
# Dependency install + Mozart wrap now happen in build.sh's build stage (one
# `composer install` whose post-install hook wraps Mozart), NOT here —
# package.sh's job is stamp + tar. So .dist/{vendor,lib/Vendor} are already
# populated by the time we package; we only stamp the version into the manifest.
if [ -n "$MODE" ]; then
    # --- Optional version stamp (CI-driven) ---
    #
    # Two env-var inputs, priority order:
    #   STAMP_VERSION=<literal>   → stamp verbatim (tag builds).
    #   STAMP_PIPELINE_IID=<n>    → read X.Y from .dist/appinfo/info.xml,
    #                               append .n.
    #   neither set               → no stamp (local dev; or CI with
    #                               SKIP_VERSION_STAMP=1).
    #
    # X.Y is read from the POST-overlay info.xml: apps that override version
    # in overlay/appinfo/info.xml ship a different X.Y than upstream's, so
    # reading pre-overlay would stamp the wrong major.minor for them.
    APP_VERSION=""
    if [ -n "${STAMP_VERSION:-}" ]; then
        APP_VERSION="$STAMP_VERSION"
    elif [ -n "${STAMP_PIPELINE_IID:-}" ]; then
        # Tolerant X.Y read: optional tag attributes, surrounding whitespace,
        # and any part-count (X.Y / X.Y.Z / X.Y.Z.W all yield X.Y). A strict
        # three-part-only pattern silently yielded empty (→ the exit 1 below)
        # on a valid 2- or 4-part <version>.
        XY=$(sed -n 's|.*<version[^>]*>[[:space:]]*\([0-9][0-9]*\.[0-9][0-9]*\)[^<]*</version>.*|\1|p' "$SOURCE_DIR/appinfo/info.xml")
        if [ -z "$XY" ]; then
            echo "ERROR: STAMP_PIPELINE_IID set but couldn't parse X.Y from $SOURCE_DIR/appinfo/info.xml" >&2
            exit 1
        fi
        APP_VERSION="${XY}.${STAMP_PIPELINE_IID}"
    fi
    if [ -n "$APP_VERSION" ]; then
        echo "==> Stamping version: $APP_VERSION"
        sed -i "s|<version>[^<]*</version>|<version>${APP_VERSION}</version>|" "$SOURCE_DIR/appinfo/info.xml"
        grep '<version>' "$SOURCE_DIR/appinfo/info.xml"
    fi
fi

# --- Package ---
#
# Tar the runtime tree straight from SOURCE_DIR (CWD) into the tarball — no
# staging copy, so nothing touches the container overlay (/tmp); on CI both the
# read and the output stay on the /ramdisk build dir.
#
# WHITELIST, not blacklist: SOURCE_DIR (.build/.dist) also holds build inputs
# (src/, node_modules/, tests/, configs, lockfiles) that must never ship, and the
# non-runtime surface grows per app while the runtime shape is small and stable —
# so we name what ships, not what doesn't. Three dep-dir conventions exist across
# NC apps and all three are listed: vendor/ (composer default — mail), composer/
# (custom vendor-dir — Talk, calendar), 3rdparty/ (legacy bundled — sociallogin).
# License/credit files ship when present. There is deliberately NO "warn on an
# unlisted dir": .build/.dist always carries more non-runtime dirs, so it would be
# noise — a genuinely new RUNTIME dir surfaces as a broken app in test; add it here.
echo "==> Packaging $APP_NAME ($MODE_DISPLAY mode from ${SOURCE_DIR##*/})"

# Only members that exist are passed to tar (a missing member is fatal to tar,
# and every app ships only a subset).
WHITELIST="appinfo lib templates js css img l10n
           vendor composer 3rdparty fonts sounds
           COPYING LICENSE LICENSE.md LICENSES AUTHORS AUTHORS.md REUSE.toml"
members=""
for entry in $WHITELIST; do
    [ -e "$entry" ] && members="$members $entry"
done

# Compression: the .tar.gz is consumed by hubs-php's fetch + dev1 deploy, so any
# gzip-format stream works. Dev/snapshot tarballs are transient — optimise for
# speed (level 1); the production (customer) artifact keeps the smaller level-6
# tree. pigz parallelises across the runner's cores; gzip is the identical-format
# fallback.
case "$MODE" in
    production) ZLEVEL=6 ;;   # customer artifact — keep it small
    *)          ZLEVEL=1 ;;   # dev/snapshot — transient, optimise for speed
esac
COMPRESS=gzip
command -v pigz >/dev/null 2>&1 && COMPRESS=pigz

# tar flags:
#   -h           deref symlinks — dev .build/ ships relative overlay symlinks
#                (link-overlay.sh) that must become real files; no-op on .dist/.
#   --transform  prefix every member with "$APP_NAME/" so the tarball extracts
#                into appName/ (the dir NC installs). Applied to the stored path
#                only; --exclude still matches the pre-transform path.
#   --exclude    prune dep test suites + bin/ (not runtime) and the macOS sidecar
#                files that leak in on a Mac host (OrbStack). GNU tar matches / in
#                wildcards, so '*/tests' catches dep tests at any depth.
tar -c -h \
    --transform "s,^,$APP_NAME/," \
    --exclude='*/test' --exclude='*/tests' \
    --exclude='vendor/bin' --exclude='composer/bin' --exclude='3rdparty/bin' \
    --exclude='._*' --exclude='.DS_Store' \
    -f - $members | "$COMPRESS" "-$ZLEVEL" > "$OUTPUT"

# du -h, not `ls -lh | awk '{print $5}'`: column-parsing ls output is
# fragile (an odd path shifts fields); du prints the size by itself.
# --apparent-size reports the file's real size, not block-rounded usage.
SIZE=$(du -h --apparent-size "$OUTPUT" | cut -f1)
echo "==> Package ready: ${OUTPUT##*/} ($SIZE, $MODE_DISPLAY mode)"
