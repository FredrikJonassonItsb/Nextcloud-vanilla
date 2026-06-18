#!/usr/bin/env bash
set -euo pipefail

# nullglob: `arr=(pattern/*)` with no matches (or missing dir) expands to
# an empty array, not the literal pattern. merge_overlay's .d/*.json gather
# relies on this — an app may legitimately have no .d/ dir.
shopt -s nullglob

# deps.sh — manage ITSL-overlay dependency state. Runs in dev-builder via
# dc-run.sh (no host toolchain). Two subcommands, each a two-stage
# per-language flow: merge ITSL overlay onto upstream → write overlay/X.json
# (only if changed); then the stage-2 tool acts on the merged overlay.
#   regen — regenerate overlay/{package,composer}.json + lockfiles. `make deps`.
#   audit — npm audit fix (semver-compatible security fixes) + composer audit
#           (surface vulns). `make security-update`.
#
# Container layout: $APP_ROOT = per-app dir, set by dc-run.sh (/app for the
# ephemeral route, /platform/apps/<APP_NAME> for the pool route); /platform =
# PLATFORM_ROOT, ro, same path either route. deps is EPHEMERAL=1 in the
# Makefile so practically always /app — but works either route by deriving
# from $APP_ROOT.
#
# Workdir is container-local /tmp/deps-work: /tmp is per-container ephemeral,
# so no cleanup trap is needed.

CMD="${1:-}"
case "$CMD" in
	regen|audit) ;;
	*) echo "Usage: deps.sh <regen|audit>" >&2; exit 2 ;;
esac

APP="${APP_ROOT:-$(pwd)}"
PLATFORM=/platform
WORK=/tmp/deps-work
MERGE_JQ="$PLATFORM/scripts/itsl-merge.jq"

# npm-registry-shield: supply-chain quarantine proxy (baked into the
# dev-builder image per docker/dev-builder/Dockerfile). Filters out npm
# package versions younger than 3 days, so a fresh compromise can't land in
# our lockfile before the community catches it. Started in the background
# before any npm metadata fetch, killed via the EXIT trap.
#
# Flags are all CLI so the shield's own config file (/tmp/shield-home/
# .npm-shield/) is never read — this script owns every knob. --foreground:
# no daemon (no systemd/launchd in the dev-builder). --no-dashboard: drop
# the stats UI's HTTP surface, unused.
#
# HOME is sandboxed to /tmp/shield-home so the shield's ~/.npmrc rewrite
# lands in the throwaway dir, not /home/developer/. npm itself does not
# read that sandbox HOME — it's pointed at the shield explicitly at each
# call site (--registry + --prefer-online).
SHIELD_PORT=4873
SHIELD_HOME=/tmp/shield-home
SHIELD_PID=
NPM_REGISTRY="http://127.0.0.1:$SHIELD_PORT"

# Start the shield in the background; block until its TCP port answers (max
# ~10s; bun bootstrap is sub-second in practice). Registers an EXIT trap so
# a crash in the npm step still kills the shield.
start_shield() {
	# Guard for running outside the dev-builder image (the shield + bun
	# are baked into docker/dev-builder/Dockerfile, nowhere else). Fail
	# fast and clear here vs the downstream "command not found" / 10s
	# port-timeout cascade.
	command -v npm-registry-shield >/dev/null \
		|| { echo "ERROR: npm-registry-shield not on PATH. This script must run inside the dev-builder image." >&2; exit 1; }

	rm -rf "$SHIELD_HOME"
	mkdir -p "$SHIELD_HOME"
	echo "==> Starting npm-registry-shield (3-day quarantine, port $SHIELD_PORT)"
	HOME="$SHIELD_HOME" npm-registry-shield start --foreground --no-dashboard "--port=$SHIELD_PORT" &
	SHIELD_PID=$!
	trap stop_shield EXIT
	local i
	for i in $(seq 1 50); do
		if bash -c ": </dev/tcp/127.0.0.1/$SHIELD_PORT" 2>/dev/null; then
			return 0
		fi
		# Bail early if the shield died before binding (EADDRINUSE, etc.) —
		# no point waiting the full 10s for a dead process.
		kill -0 "$SHIELD_PID" 2>/dev/null || { echo "ERROR: shield exited before binding port $SHIELD_PORT" >&2; exit 1; }
		sleep 0.2
	done
	echo "ERROR: shield did not bind port $SHIELD_PORT within 10s" >&2
	exit 1
}

# Kill the shield if running. Idempotent (safe from the EXIT trap after the
# shield's already exited). kill's status is ignored on purpose: the only
# failure case is "process already gone," which is what we want.
stop_shield() {
	[ -n "$SHIELD_PID" ] || return 0
	kill -TERM "$SHIELD_PID" 2>/dev/null || true
	wait "$SHIELD_PID" 2>/dev/null || true
	SHIELD_PID=
}

# Combine platform base + app-local .d/ overlays then merge onto upstream.
# Two jq stages:
#   1. `* $x` reduce — fold the ITSL deps inputs into one overlay. jq's `*`
#      is recursive-merge for objects, rightmost-wins for arrays/scalars;
#      inputs are passed platform-first then .d/ files, so app .d/ overrides
#      platform base. Order is load-bearing.
#   2. itsl-merge.jq — apply the overlay onto upstream/X.json via ITSL's
#      set/append/remove suffix convention (see jq file).
# Writes overlay/X.json only when content differs (clean git diff on no-ops).
merge_overlay() {
	local lang="$1" upstream_file overlay_file deps_base deps_dir
	case "$lang" in
		npm)
			upstream_file=$APP/upstream/package.json
			overlay_file=$APP/overlay/package.json
			deps_base=$PLATFORM/itsl-npm-deps.json
			deps_dir=$APP/itsl-npm-deps.d
			;;
		composer)
			upstream_file=$APP/upstream/composer.json
			overlay_file=$APP/overlay/composer.json
			deps_base=$PLATFORM/itsl-composer-deps.json
			deps_dir=$APP/itsl-composer-deps.d
			;;
	esac

	[ -f "$deps_base" ] || { echo "ERROR: $deps_base missing — platform setup incomplete." >&2; exit 1; }

	mkdir -p "$(dirname "$overlay_file")"

	# App-local .d/*.json overlays (zero or more), locale-sorted; later files
	# win in the stage-1 merge. nullglob (top of script) collapses both
	# "missing dir" and "empty" to an empty array.
	local deps_d_files=("$deps_dir"/*.json)

	local tmp=$overlay_file.tmp
	jq -s 'reduce .[] as $x ({}; . * $x)' "$deps_base" "${deps_d_files[@]}" \
		| jq -s -f "$MERGE_JQ" "$upstream_file" /dev/stdin \
		> "$tmp"

	if [ -f "$overlay_file" ] && cmp -s "$tmp" "$overlay_file"; then
		rm "$tmp"
	else
		mv "$tmp" "$overlay_file"
		echo "    overlay/$(basename "$overlay_file") updated"
	fi
}

# Fresh workdir for the next package-manager invocation — clears between the
# npm and composer halves of one run.
prep_work() {
	rm -rf "$WORK"
	mkdir -p "$WORK"
}

# Copy $WORK/$1 → $APP/overlay/$1 only if content differs (like
# merge_overlay): byte-identical output keeps a stable mtime + clean git diff.
write_back_if_changed() {
	local name="$1" target="$APP/overlay/$1"
	if [ -f "$target" ] && cmp -s "$WORK/$name" "$target"; then
		echo "    overlay/$name unchanged"
	else
		cp "$WORK/$name" "$target"
		echo "    overlay/$name updated"
	fi
}

regen_npm() {
	echo "==> Resolving npm lockfile"
	prep_work
	cp "$APP/overlay/package.json" "$WORK/package.json"
	cp "$APP/upstream/package-lock.json" "$WORK/package-lock.json"
	start_shield
	# Flags:
	#   --package-lock-only — resolve into the lockfile, don't install.
	#   --no-audit          — audit is the separate audit_npm path.
	#   --ignore-scripts    — load-bearing: itsl-npm-deps.json ships a
	#                         preinstall hook that refuses any npm command
	#                         but `ci`; `make deps` must opt out of its own
	#                         guard.
	#   --registry= …       — point npm at the shield (overrides any .npmrc).
	#   --prefer-online     — skip cached metadata so the shield's quarantine
	#                         filter runs on every resolution.
	( cd "$WORK" && npm install --package-lock-only --no-audit --ignore-scripts \
		"--registry=$NPM_REGISTRY" --prefer-online )
	write_back_if_changed package-lock.json
}

regen_composer() {
	echo "==> Resolving composer lockfile"
	prep_work
	cp "$APP/overlay/composer.json" "$WORK/composer.json"
	# upstream may legitimately ship no composer.lock (fresh app, or one that
	# doesn't commit its lock) — composer then resolves from composer.json.
	# Absence is a real state, not an error: no `|| exit`.
	[ -f "$APP/upstream/composer.lock" ] && cp "$APP/upstream/composer.lock" "$WORK/composer.lock"

	# When itsl-composer-deps.json declares packages, filter `composer update`
	# to just those — faster than a `--lock` full re-resolve. Otherwise fall
	# through to `--lock` (re-lock without changing already-locked versions).
	# merge_overlay (runs first, per the dispatch below) already required
	# this file to exist.
	local itsl_pkgs
	itsl_pkgs=$(jq -r '(.require // {}) + (.["require-dev"] // {}) | keys[]' "$PLATFORM/itsl-composer-deps.json")

	if [ -n "$itsl_pkgs" ]; then
		# Word-split of $itsl_pkgs into separate composer args is intentional
		# — package names are whitespace-free (vendor/name). Hence unquoted.
		# shellcheck disable=SC2086
		( cd "$WORK" && composer update --no-install --no-interaction --no-scripts --quiet $itsl_pkgs )
	else
		( cd "$WORK" && composer update --lock --no-install --no-interaction --no-scripts --quiet )
	fi
	write_back_if_changed composer.lock
}

audit_npm() {
	echo "==> npm: checking for security fixes"
	prep_work
	cp "$APP/overlay/package.json" "$WORK/package.json"
	cp "$APP/overlay/package-lock.json" "$WORK/package-lock.json"
	start_shield

	# npm audit fix's rc: 0 = clean or all auto-fixed; 1 = vulns remain that
	# couldn't be auto-fixed (informational, continue); 2+ = execution itself
	# failed — crash hard.
	# Flags as regen_npm. --ignore-scripts is needed here too: audit fix runs
	# the install machinery when bumping a vulnerable dep, tripping the same
	# preinstall guard.
	local audit_rc=0
	( cd "$WORK" && npm audit fix --package-lock-only --ignore-scripts \
		"--registry=$NPM_REGISTRY" --prefer-online ) || audit_rc=$?
	case "$audit_rc" in
		0) ;;
		1) echo "    npm audit fix could not auto-fix all vulnerabilities — see output above" ;;
		*) echo "    ERROR: npm audit fix failed (rc=$audit_rc) — see output above" >&2; exit 1 ;;
	esac

	# Diff against on-disk before copying back so a no-fix run leaves the
	# mtime untouched. rc 2+ (diff itself failed) refuses to overwrite.
	local diff_rc=0
	diff -q "$WORK/package-lock.json" "$APP/overlay/package-lock.json" >/dev/null || diff_rc=$?
	case "$diff_rc" in
		0) echo "    no changes" ;;
		1) cp "$WORK/package-lock.json" "$APP/overlay/package-lock.json"
		   echo "    overlay/package-lock.json updated — review with: git diff overlay/package-lock.json" ;;
		*) echo "    ERROR: diff failed (rc=$diff_rc) — refusing to overwrite lockfile" >&2; exit 1 ;;
	esac
}

# composer audit's rc: 0 = clean; 1 = vulns found (informational, continue);
# 2+ = execution failed (network, malformed lock) — crash hard. No `|| true`
# (would conflate vulns with execution failure); no `| tail -N` (would
# truncate the vulnerability list — security signal must not be hidden).
audit_composer() {
	echo "==> composer: checking for security fixes"
	prep_work
	cp "$APP/overlay/composer.json" "$WORK/composer.json"
	cp "$APP/overlay/composer.lock" "$WORK/composer.lock"

	local audit_rc=0
	( cd "$WORK" && composer audit --locked ) || audit_rc=$?
	case "$audit_rc" in
		0) echo "    no vulnerabilities reported" ;;
		1) echo "    composer audit found vulnerabilities — see output above" ;;
		*) echo "    ERROR: composer audit failed (rc=$audit_rc) — see output above" >&2; exit 1 ;;
	esac
}

# --- dispatch ---
# Merge always runs first — both regen and audit consume post-merge overlay
# state. Idempotent and cheap (no-op when overlay/X.json is already current).

if [ -f "$APP/upstream/package.json" ]; then
	merge_overlay npm
	case "$CMD" in
		regen) regen_npm ;;
		audit) audit_npm ;;
	esac
fi

if [ -f "$APP/upstream/composer.json" ]; then
	merge_overlay composer
	case "$CMD" in
		regen) regen_composer ;;
		audit) audit_composer ;;
	esac
fi
