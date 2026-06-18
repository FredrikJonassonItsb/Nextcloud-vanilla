#!/usr/bin/env bash
set -euo pipefail

# build.sh — install deps + compile JS for THIS app, dispatched by MODE.
#
# This is the single "build" stage: it installs the app's dependencies
# (npm + composer, run concurrently) and compiles the JS. assemble.sh lays
# the source (it installs nothing); package.sh only stamps + tars. So every
# install lives here, for every path — dev and CI alike — with no second copy.
#
#   (no MODE)        — dev: install + webpack one-shot against existing .build/.
#                      Refuses the webpack one-shot if a sidecar is declared
#                      (would race its writeToDisk) or if .build/ isn't
#                      bootstrapped. Acquires .build/.lock. With SKIP_WEBPACK=1
#                      it installs only (no compile) — the install half of
#                      `make assemble MODE=force` (Makefile chains it after the
#                      source reset; the sidecar is already stopped there).
#   MODE=production  — assemble into .dist/, install (composer --no-dev +
#                      optimized), webpack --mode production (minified): the
#                      customer release build. On tags CI runs no dependency
#                      cache, so npm ci + composer install run fresh.
#   MODE=snapshot    — same into .dist/, but dev deps + webpack --mode
#                      development (unminified, fast): the dev distributable,
#                      CI's default on non-tag pipelines. node_modules is
#                      identical to production (devDeps install in both — webpack
#                      itself is a devDependency); only the composer flavor and
#                      the webpack mode differ. No lock (.dist/ has no sidecar).
#
# Two routing paths land here with different APP_ROOT, so scripts use
# $APP_ROOT consistently — a /app literal works for ephemeral but breaks
# pool:
#   - ephemeral (production / dev force install): APP_ROOT=/app, per-call
#     --volume bind.
#   - pool (dev): APP_ROOT=/platform/apps/<APP_NAME>, wholesale
#     /platform/apps:rw bind.
# SIDECAR_FRAG stays under /platform (ro platform-tree mount, in both).
#
# webpack is run via `npx --offline --no -- webpack`, NOT the direct
# node_modules/.bin/webpack path, so npm injects npm_package_name +
# npm_package_version from the package.json at cwd (hence the cd first):
# @nextcloud/webpack-vue-config reads them at require-time for
# entry/output naming. --offline --no keep npx from fetching/installing
# (supply-chain-safe).
#
# Inputs (caller env): MODE ('' dev | 'snapshot' | 'production'), APP_NAME,
# APP_ROOT, optional SKIP_WEBPACK (dev install-only).

: "${APP_NAME:?must be set (invoke via 'make build' from app dir)}"
APP_ROOT="${APP_ROOT:-$(pwd)}"
MODE="${MODE:-}"
SKIP_WEBPACK="${SKIP_WEBPACK:-}"
SIDECAR_FRAG="/platform/docker/sidecars/${APP_NAME}.yml"

# --- php chain: composer install (+ post-install hook) + Mozart verify ---
#
# Runs in BUILD_DIR. composer install is NOT skip-guarded (unlike npm ci): it
# IS idempotent — a no-op against a matching cache-restored vendor/ ("Nothing
# to install"), a real install on a miss, self-healing when stale. The app's
# composer post-install-cmd hook fires after the install either way (including
# a no-op): it runs `composer bin all install` (vendor-bin tools), `mozart
# compose` (wraps third-party PHP under the app namespace → lib/Vendor/), and
# `composer dump-autoload`. So one `composer install` here covers vendor +
# vendor-bin + Mozart + autoload — no separate loops. That's why clean_build_dir
# doesn't cache lib/Vendor/: it's regenerated from vendor/ on every build.
#
# USE the committed lockfile — never `rm -f composer.lock` first: it pins every
# package+subdep to an exact version+hash. Removing it re-resolves from the
# public registry, defeating supply-chain isolation at the moment we build a
# customer artifact. No --quiet: the "Nothing to install" line is the visible
# proof of cross-pipeline cache reuse.
composer_install_chain() {
    local build_dir="$1" mode="$2"
    if [ ! -f "$build_dir/composer.json" ]; then
        echo "==> No composer.json — no PHP deps to install"
        return 0
    fi
    cd "$build_dir"
    if [ "$mode" = production ]; then
        echo "==> composer install --no-dev (locked, optimized)"
        composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
    else
        echo "==> composer install (dev deps, locked)"
        composer install --no-interaction --prefer-dist
    fi

    # Mozart wraps third-party PHP packages under the app's vendor namespace so
    # they don't collide with other NC apps. The wrap is the hook's job (above);
    # we only verify + (production) re-optimize.
    local mozart_ns
    mozart_ns=$(jq -r '.extra.mozart.dep_namespace // empty' composer.json)
    if [ -n "$mozart_ns" ]; then
        # production's --optimize-autoloader was overwritten by the hook's plain
        # `composer dump-autoload` (which it must run, to pick up the freshly
        # wrapped lib/Vendor/ classes). Redump optimized so the customer artifact
        # ships an optimized classmap. snapshot/dev keep the hook's non-optimized
        # autoloader — fine for a test/dev tarball, and faster.
        if [ "$mode" = production ]; then
            composer dump-autoload -o
        fi
        # Verify the hook actually wrapped — crash loud if an app declares
        # extra.mozart but its post-install-cmd omits `vendor/bin/mozart
        # compose`, so we never ship an app missing its OCA\…\Vendor classes.
        local mozart_check
        mozart_check=$(echo "$mozart_ns" | tr '\\' '.')
        if ! grep -rq "$mozart_check" "$build_dir/lib/"; then
            echo "ERROR: Mozart wrapping missing — $mozart_ns not found in lib/." >&2
            echo "       The app declares extra.mozart but its composer post-install-cmd" >&2
            echo "       hook did not run 'vendor/bin/mozart compose'. Fix the app's hook." >&2
            exit 1
        fi
    fi
}

# --- node chain: npm ci (skip-guarded) + webpack ---
#
# Runs in BUILD_DIR. npm ci CAN'T no-op (it always wipes + reinstalls, and
# mail's preinstall hook blocks plain `npm install`), so the only way to reuse
# a cache-restored / warm node_modules is to skip it. Trust the restore only
# when the committed overlay lockfile exists (an app whose lockfile lives in
# upstream/ — no overlay — installs fresh, which is correct: cache:key:files
# can't key on it, so it's never cached) AND npm's complete-install marker
# (node_modules/.package-lock.json) is present (rejects a truncated restore).
#
# NODE_ENV is set ONLY on the webpack invocation, never exported across npm ci:
# under NODE_ENV=production npm ci skips devDependencies, so mail's
# postinstall:patch-package (itself a devDependency) fails. webpack reads it
# (@nextcloud/webpack-vue-config gates minimization on it).
npm_and_webpack() {
    local build_dir="$1" mode="$2" webpack_config="$3"
    if [ ! -f "$build_dir/package.json" ]; then
        echo "==> No package.json — PHP-only app, no JS to compile"
        return 0
    fi
    cd "$build_dir"
    if [ -f "$APP_ROOT/overlay/package-lock.json" ] && [ -f node_modules/.package-lock.json ]; then
        echo "==> node_modules/ present (cache/warm) — skipping npm ci"
    else
        npm ci
    fi
    if [ -n "$SKIP_WEBPACK" ]; then
        echo "==> SKIP_WEBPACK — install only, no compile"
        return 0
    fi
    if [ "$mode" = production ]; then
        NODE_ENV=production npx --offline --no -- webpack --progress --mode production --config "$webpack_config"
    else
        NODE_ENV=development npx --offline --no -- webpack --progress --mode development --config "$webpack_config"
    fi
}

# --- orchestrate: php chain in the background, node chain in the foreground ---
#
# The two chains are independent — composer/vendor/Mozart touches no JS,
# webpack/node_modules touches no PHP — so they run concurrently and the build
# waits on whichever finishes last. On a warm code-only run that overlap is
# small (npm skipped, composer no-ops); on a lockfile-change / cold run it hides
# the smaller install entirely under the larger (per-app: composer under npm ci,
# or the reverse for a PHP-heavy app). Tar (package.sh) needs both sides, so it
# runs after this returns.
#
# The php chain's output is buffered to a log and printed after the wait — else
# its download lines interleave with npm/webpack into unreadable soup. The
# exit code is captured with `|| rc=$?` (errexit-safe: a bare failing `wait`
# would exit the script before the cat); a non-zero rc crashes the build. The
# EXIT trap kills the bg chain if the foreground crashes first, so it can't
# outlive the job.
install_and_build() {
    local build_dir="$1" mode="$2" webpack_config="$3"
    local cpid="" crc=0 clog=""
    if [ -f "$build_dir/composer.json" ]; then
        clog="$(mktemp)"
        composer_install_chain "$build_dir" "$mode" >"$clog" 2>&1 &
        cpid=$!
        # On a foreground crash (set -e) before the wait below: kill the bg
        # chain so it can't outlive the job, surface its partial output for
        # diagnosis, and clean up the log.
        trap 'kill "$cpid" 2>/dev/null || true; [ -f "$clog" ] && cat "$clog"; rm -f "$clog"' EXIT
    fi

    npm_and_webpack "$build_dir" "$mode" "$webpack_config"

    if [ -n "$cpid" ]; then
        wait "$cpid" && crc=0 || crc=$?
        trap - EXIT
        cat "$clog"
        rm -f "$clog"
        if [ "$crc" -ne 0 ]; then
            echo "ERROR: composer install chain failed (rc=$crc) — see its output above." >&2
            exit "$crc"
        fi
    fi
}

case "$MODE" in
    production|snapshot)
        # WEBPACK_* / NODE_PATH are read by webpack.itsl.js; harmless during the
        # installs, so exported once. assemble.sh lays the .dist/ source (rsync
        # upstream + quilt push + overlay as real files, no install); then we
        # install + compile into it. WEBPACK_DIST marks this a distributable build
        # so webpack.itsl.js drops source maps (snapshot/production only; local
        # dev MODE='' leaves it unset and keeps them).
        export WEBPACK_APP_NAME="$APP_NAME" WEBPACK_APP_DIR="$APP_ROOT/.dist" NODE_PATH="$APP_ROOT/.dist/node_modules" WEBPACK_DIST=1
        bash /platform/scripts/assemble.sh
        install_and_build "$APP_ROOT/.dist" "$MODE" /platform/webpack/webpack.itsl.js
        ;;
    "")
        # dev: install + (unless SKIP_WEBPACK) webpack one-shot on the already
        # assembled .build/. The sidecar refusal applies only when we actually
        # webpack — install-only (the force-rebuild's install half) runs after
        # the Makefile has stopped the sidecar, so there's nothing to race.
        if [ -z "$SKIP_WEBPACK" ] && [ -f "$SIDECAR_FRAG" ]; then
            echo "ERROR: sidecar declared for $APP_NAME — stop with: make webpack MODE=off" >&2
            exit 1
        fi
        if [ ! -f "$APP_ROOT/.build/.lock" ]; then
            echo "ERROR: .build/ not bootstrapped — run: make assemble MODE=force" >&2
            exit 1
        fi
        # Lock BEFORE touching any .build/ state: wrapping install + webpack
        # stops a concurrent assemble's clean from racing .build/ between our
        # decision and our action. Released implicitly when fd 200 closes at
        # process exit.
        exec 200>"$APP_ROOT/.build/.lock"
        if ! flock -n 200; then
            echo "ERROR: another .build/ operation is already running" >&2
            echo "       (lock held on .build/.lock; make status shows what's holding it)" >&2
            exit 1
        fi
        export WEBPACK_APP_NAME="$APP_NAME" WEBPACK_APP_DIR="$APP_ROOT/.build" NODE_PATH="$APP_ROOT/.build/node_modules"
        install_and_build "$APP_ROOT/.build" "" "$APP_ROOT/.build/webpack.itsl.js"
        ;;
    *)
        echo "ERROR: MODE must be empty, 'snapshot', or 'production' (got: \"$MODE\")." >&2
        exit 1
        ;;
esac
