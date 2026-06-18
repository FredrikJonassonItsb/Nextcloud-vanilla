SHELL := /bin/bash
.PHONY: help setup new-app assemble build package deploy seed tunnel deps security-update diff quilt clean distclean l10n \
        nc-up nc-down ide-up ide-down webpack status down

# Pre-goal SERVER check: fires at parse time so deploy fails before the
# build+package chain runs on a missing SERVER.
ifneq ($(filter deploy,$(MAKECMDGOALS)),)
ifeq ($(strip $(SERVER)),)
$(error SERVER is required for deploy: make deploy SERVER=<ip-or-hostname>)
endif
endif
.DEFAULT_GOAL := help

# --- Platform / App root resolution ---
# PLATFORM_ROOT: scripts, shared configs, docker/compose.yml.
# APP_ROOT: the app being built (upstream/, patches/, overlay/, .build/).
# Included from an app Makefile → _FROM_APP set, APP_ROOT known. Run from
# the platform root → APP_ROOT unset; app-level targets error via _REQUIRE_APP.
PLATFORM_ROOT ?= $(patsubst %/,%,$(dir $(abspath $(lastword $(MAKEFILE_LIST)))))

# Platform defaults: .env.example (git, canonical) then .env (per-operator,
# gitignored). Both -include's tolerate a missing file; later assignments win,
# so .env overrides .env.example. Anchored at $(PLATFORM_ROOT) because the
# per-app Makefile shim sets CURDIR to apps/<app>/, not the platform root.
#
# DEVELOPER_UID/GID auto-detect from the host below (?=) — no literal `1000`;
# an explicit .env value wins (non-host UID, or compose invoked directly).
#
# Bumping NEXTCLOUD_VERSION requires `make distclean && make setup && make
# nc-up` to wipe volumes + rebuild at the new version — gated by
# docker/nextcloud/entrypoint.sh's install-version-marker.
-include $(PLATFORM_ROOT)/.env.example
-include $(PLATFORM_ROOT)/.env
DEVELOPER_UID ?= $(shell id -u)
DEVELOPER_GID ?= $(shell id -g)

# DOCKER_GID — the GID the in-container developer joins (via compose.dev.yml's
# group_add) so it can write the bind-mounted docker socket.
#   - Linux: the host GID owning /var/run/docker.sock. `ls -nd` not `stat`:
#     stat's flags differ between GNU and BSD and it isn't a host-floor tool.
#     Socket absent → empty → compose's `${DOCKER_GID:-999}` default.
#   - macOS (OrbStack): 0. OrbStack exposes the Linux VM's own socket inside
#     the container, owned root:root (GID 0) — unrelated to the host file's
#     group (e.g. staff), so the host-side GID is meaningless there and the
#     root group is the only one that grants write. Whether group_add 0 in turn
#     grants write needs OrbStack >= 1.9.4 (earlier broke socket perms) — left
#     for a first-Mac-run check, not something Linux can settle.
#
# HOST_SSH_AUTH_SOCK — host path to the operator's SSH agent socket. Two
# consumers: (1) compose.dev.yml propagates it into the dev-builder at ide-up
# so `make tunnel` from the integrated terminal binds the same host path into
# the sibling tunnel container; (2) tunnel-up.sh binds it directly when
# `make tunnel` runs on the host. One bind mechanism for both sides.
#   - macOS: /run/host-services/ssh-auth.sock — the path OrbStack exposes to
#     bridge the launchd SSH agent into Linux containers. The native
#     $SSH_AUTH_SOCK isn't reliably bind-mountable through VirtioFS.
#   - Linux: $SSH_AUTH_SOCK if set, else the systemd-user default
#     /run/user/<uid>/openssh_agent. Many contexts (Claude Code bash, cron,
#     ssh sans -X, IDE recipe shells) miss SSH_AUTH_SOCK from PAM even with
#     ssh-agent running. `test -S` keeps the var empty when no agent exists,
#     so tunnel-up.sh's diagnostic fires loud.
#
# Both default with ?= so an exported override (or .env) wins.
ifeq ($(shell uname),Darwin)
DOCKER_GID         ?= 0
HOST_SSH_AUTH_SOCK ?= /run/host-services/ssh-auth.sock
else
DOCKER_GID         ?= $(shell ls -nd /var/run/docker.sock 2>/dev/null | awk '{print $$4}')
HOST_SSH_AUTH_SOCK ?= $(shell s="$$SSH_AUTH_SOCK"; test -S "$$s" && echo "$$s" || { s="/run/user/$$(id -u)/openssh_agent"; test -S "$$s" && echo "$$s"; })
endif

# HOST_PROJECT_DIR — absolute host path of the platform tree, used as
# compose bind sources so the daemon mounts the right path regardless of where
# the compose client runs (host shell vs IDE-attach container, which sees the
# tree at /platform). compose.dev.yml propagates it in so make-from-inside
# hands the daemon host-resolvable paths. ?= so a container/sibling-supplied
# value wins over the local derivation.
#
# `$(abspath)` canonicalises: the app shim appends `/../..` to its own dir to
# reach the platform root — functional, but the literal `..` is ugly in env
# vars and in _REQUIRE_HOST's interpolated error message.
HOST_PROJECT_DIR ?= $(abspath $(PLATFORM_ROOT))

# Per-image version tags — one VERSION file per image in its build context,
# bumped (with the Dockerfile, same commit) when app devs must pick up a new
# build. After a bump the local tag is missing, so the next compose-up/dc-run.sh
# BuildKit-builds it once (layer cache makes it fast) and later calls hit the
# skip-if-present path. No app-dev bookkeeping.
#
# A missing VERSION file does NOT abort parse: $(shell) discards cat's rc, so
# the var ends up empty. Deliberate — keeps `make status`/`help` alive on a
# broken checkout (a parse-time $(error) would kill that lifeline; status.sh
# degrades a missing image to "cannot probe"). The loud guard is downstream:
# every consumer hits a compose `${...:?}` image: line that aborts the moment
# an image is built or run. Exported so compose YAML, dc-run.sh, status.sh, and
# compose.sh (which emits compose-time $-refs into version-agnostic sidecar
# fragments) all see them.
DEV_BUILDER_VERSION       := $(shell cat $(PLATFORM_ROOT)/docker/dev-builder/VERSION)
NODE_SIDECAR_VERSION      := $(shell cat $(PLATFORM_ROOT)/docker/node-sidecar/VERSION)
NEXTCLOUD_IMAGE_VERSION   := $(shell cat $(PLATFORM_ROOT)/docker/nextcloud/VERSION)

# EDITOR + GIT_EDITOR resolution. Single source of truth — resolved once at
# parse time and propagated into every container via compose.dev.yml's
# `- EDITOR`/`- GIT_EDITOR` passthroughs (dev-builder + every integrated
# terminal) and dc-run.sh's per-call `--env` (pool + ephemeral). In-container
# scripts read `os.environ["EDITOR"]` or spawn git and trust it — no per-script
# fallback, no per-recipe env(1) shim.
#
# `$(or)` returns the first non-empty (short-circuiting, so `command -v editor`
# only runs if $EDITOR/$VISUAL are both unset):
#   1. $EDITOR  — explicit preference.
#   2. $VISUAL  — older "richer/full-screen" convention.
#   3. `editor` on PATH — Debian update-alternatives symlink (→ nano on a
#      fresh install). Mac/BSD lack it → `command -v` empty, falls through.
#   4. `vi`     — POSIX last resort; always on the dev-builder image.
EDITOR := $(or $(EDITOR),$(VISUAL),$(shell command -v editor 2>/dev/null),vi)

# GIT_EDITOR resolution. `git config --global core.editor` is authoritative
# for git ops (commit without -m, rebase -i, tag -a); fall back to EDITOR.
#
# The host's GIT_EDITOR is DELIBERATELY NOT consulted: the Claude Code harness
# sets GIT_EDITOR=true (the no-op) to suppress editor spawn for automated git,
# and honouring that in containers would make interactive `make quilt`/`git
# rebase`/`git commit` no-op-succeed instead of opening the operator's editor.
# `:=` overrides any inherited GIT_EDITOR.
#
# --global not bare --get: --get follows git's precedence chain from $PWD, so
# resolution would differ between make-from-platform-root and -app-dir.
# --global is cwd-independent and matches the "global preference" intent.
GIT_EDITOR := $(or $(shell git config --global --get core.editor 2>/dev/null),$(EDITOR))

# Build images with the classic builder, not BuildKit/buildx. The buildx
# plugin isn't present on every docker install (compose v2 doesn't require it),
# and a buildx-less compose tries to PULL our local-only images (hubs-apps/*,
# never pushed) and fails, or warns and stalls. The classic builder ships with
# every docker daemon, and the Dockerfiles use no BuildKit-only feature, so it
# builds them everywhere. `?=` lets a host that prefers BuildKit opt back in
# with `DOCKER_BUILDKIT=1 make ...`. Exported so every $(DC)/dc-run.sh build
# (compose up auto-build, compose build, sidecar --build) inherits it.
DOCKER_BUILDKIT ?= 0

export DEVELOPER_UID DEVELOPER_GID DOCKER_GID HOST_PROJECT_DIR HOST_SSH_AUTH_SOCK \
       NEXTCLOUD_VERSION COMPOSE_PROJECT_NAME \
       DEV_BUILDER_VERSION NODE_SIDECAR_VERSION NEXTCLOUD_IMAGE_VERSION \
       EDITOR GIT_EDITOR DOCKER_BUILDKIT

S := $(PLATFORM_ROOT)/scripts
A := $(APP_ROOT)
APP_NAME ?= $(notdir $(A))

# Per-app sidecar fragment — the file IS the sidecar's persistent state.
# Written by `make webpack MODE=<hmr|watch>`, deleted by MODE=off. Referenced
# by assemble (MODE=force stop+restart around the rm-rf wipe) and webpack.
# build/package check sidecar presence via /platform/docker/sidecars/*.yml in
# their container workers, not this host-side macro.
SIDECAR_FRAG = $(PLATFORM_ROOT)/docker/sidecars/$(APP_NAME).yml

# DC: docker compose with all platform-managed -f flags. Explicitly enumerated
# (compose.yml, compose.dev-builder.yml, compose.dev.yml, sidecars/<app>.yml)
# so anything else matching the globs — editor backups, stashed copies — is
# foreign and not loaded.
# Load order matters for nested mounts: dev.yml's /platform/apps:rw +
# /docker/sidecars:rw are nested binds over dev-builder.yml's /platform:ro, so
# dev.yml MUST come after dev-builder.yml (kernel applies binds in order; the
# inner mount must land after the outer to overlay).
# The -f list is built by a shell loop at recipe-execution time, not
# $(wildcard)/$(shell): those memoize within a make invocation and would miss
# fragments generated earlier in the same recipe. Shared by both DC macros below.
DC_FILES = $$(for f in $(PLATFORM_ROOT)/docker/compose.yml $(PLATFORM_ROOT)/docker/compose.dev-builder.yml $(PLATFORM_ROOT)/docker/compose.dev.yml $(PLATFORM_ROOT)/docker/sidecars/*.yml; do [ -f "$$f" ] && printf -- "-f %s " "$$f"; done)
#
# DC — the routine compose surface. COMPOSE_IGNORE_ORPHANS=1 silences the "Found
# orphan containers" warning compose prints on every `up` while the tunnel is
# up: the tunnel is project-labeled but lives in compose.tunnel.yml, which DC
# doesn't load (rationale below), so compose flags it as an orphan. Same
# suppression dc-run.sh and tunnel-up.sh already apply. The tunnel is the only
# container that ever trips this — every other service is in the loaded set.
DC = cd $(PLATFORM_ROOT) && COMPOSE_IGNORE_ORPHANS=1 docker compose $(DC_FILES)
#
# DC_TEARDOWN — same -f set, WITHOUT the IGNORE env, for the down/clean/distclean
# recipes only. `--remove-orphans` and COMPOSE_IGNORE_ORPHANS are mutually
# exclusive (compose errors "cannot combine ..."), and the teardowns need
# --remove-orphans to sweep the tunnel + dead husks. It doesn't emit the orphan
# warning anyway — it removes them rather than warning.
DC_TEARDOWN = cd $(PLATFORM_ROOT) && docker compose $(DC_FILES)

# compose.tunnel.yml is deliberately NOT in $(DC): its strict `${VAR:?}` for
# SERVER + HOST_SSH_AUTH_SOCK (set only by tunnel-up.sh) would force every recipe
# to supply those values. So the tunnel container is an orphan from $(DC)'s
# perspective — project-labeled but not in the loaded YAML — and `$(DC_TEARDOWN) down`
# alone won't remove it. The teardowns pass `--remove-orphans`, which does: it
# sweeps every project container not in the loaded set (the tunnel, plus any
# stopped one-off husk that somehow lingered). It's project-scoped, so it never
# touches another compose project's containers.
#
# `--remove-orphans` is risk-free ONLY because the GUARD runs first. On its own
# it would force-kill an in-flight one-off ephemeral (the EPHEMERAL=1
# `compose run --rm` path: assemble MODE=force, deps, setup, ...) out from under
# an operator — the original foot-gun. The guard removes that risk: every
# teardown refuses while a .build/ op is in flight (a running one-off OR a
# build-pool op holding a .build/.lock), unless EPHEMERALS=1 — which reaps the
# one-offs and lets the recipe's `$(DC_TEARDOWN) down` kill any pool op. So by the time
# `--remove-orphans` runs, nothing in flight is alive for it to kill; it catches
# only the tunnel and (rare) dead husks. One-offs are otherwise self-cleaning —
# `compose run --rm` sets AutoRemove, so they vanish on completion.
#
# GUARD: the refuse-or-reap gate, run first in down / clean / distclean.
# EPHEMERALS is forwarded so `EPHEMERALS=1` means "kill in-flight ops and tear
# down anyway".
GUARD = bash $(S)/host/teardown-guard.sh "$(EPHEMERALS)"

# Guard: app-level targets require _FROM_APP (i.e., running from an app directory)
ifndef _FROM_APP
_REQUIRE_APP = @printf '\033[31mERROR: Run this from an app directory (e.g. cd apps/<app>)\033[0m\n' >&2; exit 1
else
_REQUIRE_APP =
endif

# Guard: host-only targets refuse when invoked from inside the IDE-attach
# container. IDE_ATTACH=1 is set by compose.dev.yml (loaded only by $(DC) for
# the IDE-attach path; ephemeral dc-run.sh one-shots don't see it). The refusal
# message points at the host command meant. Guarded targets and why:
#   - setup: bootstraps a fresh clone (git clones + per-app install) — running
#     it from inside implies the dev-builder is already up, i.e. already done.
#   - ide-up: needs `code` on host PATH + host DISPLAY (image has neither), and
#     from inside the dev is already attached.
#   - clean/distclean: their `$(DC_TEARDOWN) down` is project-wide and stops the
#     dev-builder the recipe shell runs in, so the later rm steps never execute.
#   - l10n: spawns `claude`, which needs the operator's host auth + MCP config.
#
# Deliberately NOT guarded:
#   - ide-down / nc-up / nc-down / webpack / status: single-step or
#     socket-orchestrated, fine from either side.
#   - tunnel + seed: tunnel is a compose service (not a host ssh process), so
#     the in-container netns is irrelevant; both reach the host SSH agent via
#     compose.dev.yml's /ssh-agent bind.
#   - down: from inside uses a foreign-sibling spawn path — see its recipe.
#   - assemble/build/package/quilt/diff/deps/security-update: route to a fresh
#     ephemeral via dc-run.sh.
#   - deploy/new-app: mixed — container parts route, host SSH parts run in the
#     integrated terminal where the SSH agent is bound.
ifeq ($(IDE_ATTACH),1)
_REQUIRE_HOST = @printf '\033[31mERROR:\033[0m `make $@` must run on the host, not inside the IDE-attach container.\n  Run from your host shell:\n    cd $(HOST_PROJECT_DIR) && make $@\n' >&2; exit 1
else
_REQUIRE_HOST =
endif

ifdef _FROM_APP
# --- App help (shown when running from an app directory) ---
help:
	@printf '\n'
	@printf '  \033[1mPatches:\033[0m\n'
	@printf '    \033[36mdiff\033[0m            Show unsaved changes in .build/\n'
	@printf '    \033[36mquilt\033[0m           Save changes as quilt patches (interactive)\n'
	@printf '\n'
	@printf '  \033[1mBuild & Deploy:\033[0m\n'
	@printf '    \033[36massemble\033[0m        Assemble .build/ (upstream + patches + overlay)\n'
	@printf '    \033[36mbuild\033[0m           Compile JS (MODE=production for production into .dist/)\n'
	@printf '    \033[36mpackage\033[0m         build + tar into app.tar.gz (MODE=production packages .dist/)\n'
	@printf '    \033[36mdeploy\033[0m          build + package + ship to server (SERVER=<ip>)\n'
	@printf '\n'
	@printf '  \033[1mDev sidecar:\033[0m\n'
	@printf '    \033[36mwebpack MODE=hmr\033[0m    HMR sidecar for THIS app (Apache reverse-proxy)\n'
	@printf '    \033[36mwebpack MODE=watch\033[0m  watch sidecar for THIS app (bundle-to-disk)\n'
	@printf '    \033[36mwebpack MODE=off\033[0m    Stop THIS app'\''s sidecar\n'
	@printf '\n'
	@printf '  \033[1mDependencies:\033[0m\n'
	@printf '    \033[36mdeps\033[0m            Regenerate merged package/composer files + lockfiles\n'
	@printf '    \033[36msecurity-update\033[0m Apply npm/composer security fixes\n'
	@printf '\n'
	@printf '  \033[1mTranslations:\033[0m\n'
	@printf '    \033[36ml10n\033[0m            Apply translation fixes (l10n-tools/l10n fix)\n'
	@printf '\n'
	@printf '  \033[2mPlatform commands:\033[0m\n'
	@printf '  \033[2m  nc-up, nc-down, ide-up, ide-down, seed, tunnel, setup, status\033[0m\n'
	@printf '  \033[2m  down, clean, distclean (each tier wider than the last)\033[0m\n'
	@printf '\n'
else
# --- Platform help (shown when running from the platform root) ---
help:
	@printf '\n'
	@printf '  \033[1mRuntime:\033[0m\n'
	@printf '    \033[36mnc-up\033[0m / \033[36mnc-down\033[0m         Nextcloud + Postgres only\n'
	@printf '    \033[36mide-up\033[0m / \033[36mide-down\033[0m       Attach VSCode to dev-builder only\n'
	@printf '\n'
	@printf '  \033[1mPlatform:\033[0m\n'
	@printf '    \033[36mseed\033[0m            Seed users + mailboxes from remote (SERVER=<ip>)\n'
	@printf '    \033[36mtunnel\033[0m          SSH tunnel to remote IMAP/SMTP (SERVER=<ip>)\n'
	@printf '    \033[36msetup\033[0m           Clone app repos, init l10n-tools, assemble quilt apps\n'
	@printf '    \033[36mnew-app\033[0m         Bootstrap new quilt app (UPSTREAM=url VERSION=tag — name derived from upstream <id>)\n'
	@printf '    \033[36mstatus\033[0m          Runtime + per-app build state + lock probe + mismatches\n'
	@printf '\n'
	@printf '  \033[1mCleanup (each tier wider than the last):\033[0m\n'
	@printf '    \033[36mdown\033[0m            Stop ALL containers; preserves .build/ + named volumes,\n'
	@printf '                    drops regenerable sidecar fragments (end-of-day stop)\n'
	@printf '    \033[36mclean\033[0m           Reset the env, KEEP .build/: remove named volumes +\n'
	@printf '                    .dist/ + sidecar fragments + workspace\n'
	@printf '                    (recovery: `make nc-up` + `make seed`; your code is intact)\n'
	@printf '    \033[36mdistclean\033[0m       clean + remove .build/ + built images + caches\n'
	@printf '                    (recovery: `make setup` from scratch)\n'
	@printf '\n'
	@printf '  \033[2mApp commands: cd into an app directory first (e.g. cd apps/<app>)\033[0m\n'
	@printf '  \033[2m  assemble [MODE=force|discard|recover], build, package, deploy\033[0m\n'
	@printf '  \033[2m  diff, quilt, deps, security-update, l10n\033[0m\n'
	@printf '  \033[2m  webpack MODE=<hmr|watch|off>\033[0m\n'
	@printf '\n'
endif

# --- Dependency management ---
# Both targets run in dev-builder via dc-run.sh (no host npm/composer/jq).
# Flow lives in scripts/deps.sh: merge ITSL overlay (platform
# itsl-{npm,composer}-deps.json + per-app itsl-{npm,composer}-deps.d/*.json)
# onto upstream/X.json → write overlay/X.json (only if changed), then regen
# lockfile (deps) or audit (security-update). See scripts/deps.sh +
# scripts/itsl-merge.jq.

deps:
	$(_REQUIRE_APP)
	@# EPHEMERAL=1: deps.sh runs npm/composer lock updates that invoke
	@# third-party code (npm preinstall hooks leak even under --ignore-scripts;
	@# composer plugins land in vendor/), so isolate in a fresh ephemeral.
	@APP_ROOT="$(A)" EPHEMERAL=1 bash $(S)/host/dc-run.sh bash /platform/scripts/deps.sh regen

security-update:
	$(_REQUIRE_APP)
	@# EPHEMERAL=1: `npm audit fix` writes new pinned versions to the
	@# lockfile, same supply-chain surface as `make deps`. Route through
	@# fresh ephemeral.
	@APP_ROOT="$(A)" EPHEMERAL=1 bash $(S)/host/dc-run.sh bash /platform/scripts/deps.sh audit

# l10n hardcoded to `fix` — the only subcommand make invokes; others stay
# available via direct `l10n-tools/l10n <subcmd>` from the app dir.
#
# Host-side carve-out: `l10n fix` spawns `claude`, which needs the operator's
# host auth token + MCP servers + ~/.claude/ — all lost inside dc-run.sh.
# Requires host deps beyond the bash+ssh+git+docker+make floor:
#   - python3 + python3-polib + python3-yaml (l10n-tools is python)
#   - claude (Anthropic CLI)
l10n:
	$(_REQUIRE_APP)
	$(_REQUIRE_HOST)
	@cd $(A) && $(PLATFORM_ROOT)/l10n-tools/l10n fix

diff:
	$(_REQUIRE_APP)
	@APP_ROOT=$(A) bash $(S)/host/dc-run.sh bash /platform/scripts/diff.sh

quilt:
	$(_REQUIRE_APP)
	@# EDITOR/GIT_EDITOR resolved + exported at parse time (see block near top);
	@# dc-run.sh forwards both. quilt_v2._edit_patch_header reads
	@# os.environ["EDITOR"] and gets the resolved value — no per-recipe shim.
	@APP_ROOT="$(A)" bash $(S)/host/dc-run.sh python3 /platform/scripts/quilt_v2.py

# --- Assembly ---
# Production build uses .dist/ and never touches .build/. Dev-mode lifecycle
# (nc-up, ide-up, webpack) is independent; assemble is orthogonal.

# assemble — source reconciliation (plain / force / discard / recover /
# production). MODE=force stops + restarts a running sidecar across the rm-rf
# wipe (webpack's in-memory loader cache wedges otherwise — observed). Other
# modes don't rm-rf the watched tree, so need no coordination.
#
# Sidecar-coord guard runs only with docker-daemon access: (a) host
# (IN_BUILDER unset) or (b) IDE-attach container (IDE_ATTACH=1, socket bound by
# compose.dev.yml). Skips in ephemeral one-shots (IN_BUILDER=1, no socket)
# where the $(DC) stop would fail — those ride the wipe because the outer make
# invocation already ran the coord on host/IDE-attach before spawning them.
assemble:
	$(_REQUIRE_APP)
	@# assemble.sh installs nothing now (source layout only — no third-party
	@# code), so it runs in the build-pool for every mode. MODE=force is the full
	@# rebuild: it wipes .build/ (racing a sidecar — hence the stop/restart) and
	@# then chains build.sh's install step (the install-touching part: npm ci +
	@# composer install + the Mozart hook) in a fresh ephemeral, SKIP_WEBPACK=1
	@# so it installs without compiling — the sidecar / `make build` owns JS.
	@# Plain/discard/recover reconcile source only; they never install.
	@set -e; WAS_ON=0; \
	if [ "$${MODE:-}" = "force" ] \
	     && { [ -z "$${IN_BUILDER:-}" ] || [ "$${IDE_ATTACH:-}" = "1" ]; } \
	     && [ -f $(SIDECAR_FRAG) ]; then \
		echo "==> stop sidecar-$(APP_NAME) for MODE=force"; \
		$(DC) stop sidecar-$(APP_NAME); \
		WAS_ON=1; \
	fi; \
	APP_ROOT=$(A) bash $(S)/host/dc-run.sh env MODE=$${MODE:-} bash /platform/scripts/assemble.sh; \
	if [ "$${MODE:-}" = "force" ]; then \
		echo "==> install deps for $(APP_NAME) (build.sh, no webpack)"; \
		APP_ROOT=$(A) EPHEMERAL=1 bash $(S)/host/dc-run.sh env MODE= SKIP_WEBPACK=1 bash /platform/scripts/build.sh; \
	fi; \
	if [ "$$WAS_ON" = 1 ]; then \
		echo "==> restart sidecar-$(APP_NAME) after MODE=force"; \
		$(DC) up -d --wait --wait-timeout 300 sidecar-$(APP_NAME); \
		$(DC) exec -T nextcloud /usr/local/bin/regen-htaccess; \
	fi

# --- Dev-mode lifecycle ---
# Independent flows, one compose call per use case.

nc-up:
	@# No --build: compose's image:+build: spec rebuilds only when the
	@# versioned tag is missing (fresh checkout, post-distclean, post-VERSION-
	@# bump), else uses the present tag. Adding --build defeats the VERSION-file
	@# scheme — it forces a cache-check + progress spam on every nc-up.
	@$(DC) up -d --wait nextcloud postgres

nc-down:
	@# Scoped `stop`, not project-wide `down`: $(DC) loads every fragment, so
	@# down would also stop sidecar-* (which own their lifecycle via `make
	@# webpack`). No -v, so volumes are preserved.
	@$(DC) stop nextcloud postgres

# down — single-gesture project teardown. Stops + removes ALL containers
# (nextcloud, postgres, dev-builder, build-pool, sidecar-*); preserves named
# volumes, .build/, sidecar fragments, workspace file. Three tiers:
#   make down       — containers only, all state preserved.
#   make clean      — + named volumes + .dist/ + fragments + workspace; KEEPS .build/.
#   make distclean  — + .build/ + built images + python/bytecode caches.
#
# From the host: straight `$(DC_TEARDOWN) down`.
#
# From inside the IDE-attach container the CLI is itself inside a container
# down removes: it dies the moment the daemon stops the dev-builder, so any
# unsent `rm` for dev-builder/build-pool never lands (they end Exited, not
# Removed), and docker has no "auto-rm running container" primitive. So the
# cleanup MUST come from a process that outlives the dev-builder: we spawn a
# foreign sibling (`docker run --rm -d`, no compose project label so this
# down doesn't touch it) running `make down`. That recursive call sees no
# IDE_ATTACH and takes the host branch. The recipe prints a dot/second until
# the dev-builder dies under it; the dots stopping is the "cleanup reached
# dev-builder" signal.
ifeq ($(IDE_ATTACH),1)
down:
	@# Guard runs HERE, in the foreground: a refuse must reach the operator's
	@# IDE terminal, but the sibling below is detached so its exit wouldn't.
	@# The sibling re-runs the guard too (harmless — clear by then for one-offs,
	@# which this reaped), so EPHEMERALS is forwarded to it so EPHEMERALS=1
	@# carries through to kill a still-running pool op on its $(DC_TEARDOWN) down.
	@$(GUARD)
	@# --group-add $(DOCKER_GID): the image's `developer` user needs the host
	@# docker group to reach /var/run/docker.sock. The long-running container
	@# gets it from compose.dev.yml's group_add; this `docker run` sibling
	@# isn't compose-managed, so the group must be added per-invocation.
	@docker run --rm -d \
		-v /var/run/docker.sock:/var/run/docker.sock \
		-v $(HOST_PROJECT_DIR):/platform \
		-w /platform \
		--group-add $(DOCKER_GID) \
		-e COMPOSE_PROJECT_NAME -e HOST_PROJECT_DIR \
		-e DEVELOPER_UID -e DEVELOPER_GID -e DOCKER_GID \
		-e DEV_BUILDER_VERSION -e NODE_SIDECAR_VERSION \
		-e NEXTCLOUD_IMAGE_VERSION -e NEXTCLOUD_VERSION -e EPHEMERALS \
		hubs-apps/dev-builder:$(DEV_BUILDER_VERSION) \
		make down >/dev/null
	@printf 'Docker containers shutting down...'
	@while sleep 1; do printf '.'; done
else
down:
	@# Refuse if a .build/ op is in flight (unless EPHEMERALS=1) — DC down would
	@# otherwise kill the build-pool out from under a running pool op. Then DC
	@# down stops every platform service; --remove-orphans sweeps the tunnel
	@# (compose.tunnel.yml, not in the DC -f set) and any stopped husk, and is
	@# safe because the guard already cleared anything in flight. See the GUARD /
	@# --remove-orphans rationale near the macro.
	@$(GUARD)
	@$(DC_TEARDOWN) down --remove-orphans
	@# down removed every sidecar container; drop their now-orphaned fragment
	@# files too — a fragment with no container is stale state `make status`
	@# flags (the fragment IS a sidecar's persistent declaration, the way
	@# webpack MODE=off deletes its own when it stops one). Regenerated by
	@# `make webpack`, so wiping costs nothing. PLATFORM_ROOT-anchored so an
	@# app-dir invocation (via the Makefile shim, CWD=apps/<x>) hits the
	@# platform tree, not a CWD-relative miss.
	@rm -f $(PLATFORM_ROOT)/docker/sidecars/*.yml
endif

# ide-up — bring up the dev-builder, regenerate the workspace, attach VSCode
# via the Dev Containers `vscode-remote://` URI, install recommended
# extensions. Fused into one target since every "IDE up" gesture needs all of
# them.
#
#   1. `compose up -d --wait dev-builder` — idempotent. --wait blocks until
#      docker reports started; dev-builder has no healthcheck, so "started"
#      == running (all `tail -f /dev/null` can signal). No --build, same
#      VERSION-file reasoning as nc-up.
#
#   2. Regenerate hubs-apps.code-workspace (in-container workspace.sh) so
#      apps added/removed since last attach are picked up. .gitignored.
#
#   3. Resolve the dev-builder container name (varies by COMPOSE_PROJECT_NAME),
#      hex-encode it for the vscode-remote URI, launch `code --file-uri`.
#      Re-running while attached sends the URI to the existing window via IPC;
#      a wedged one (see reload-window failure mode below) ignores it silently.
#      We do NOT pass --reuse-window: the URI handler already focuses the right
#      window, and the flag's real semantic ("reuse LAST ACTIVE window") would
#      hijack an unrelated project's window when several are open.
#
#   Reload-window failure mode (upstream, not ours): the Dev Containers
#   extension crashes onResolveRemoteAuthority on Node 22's `navigator` global,
#   surfacing as "Cannot reconnect" on every Reload Window against an attached
#   container; Microsoft closed it as wontfix. So: don't use Reload Window for
#   attached sessions. Recovery, lightest first:
#     (a) `pkill -f /usr/share/code/ && code` — kills host UI; relaunch
#         auto-reopens the workspace and re-attaches. UI-only wedge.
#     (b) `pkill -f /usr/share/code/ && make ide-up` — same, plus workspace
#         regen + extension re-apply. After `make new-app` or extension bump.
#     (c) `make ide-down && make ide-up` — full container cycle, when
#         in-container vscode-server state stays weird.
#
#   4. Wait for a vscode-server PROCESS inside the container (wait-for-vscode-
#      server.sh polls /proc/<pid>/exe). First attach ever downloads + spawns
#      it (~30-60s); later attaches reuse the cached binary. Probe a live
#      process, NOT binary-on-disk: the binary persists on the volume across
#      restarts, so a filesystem-presence probe falsely succeeded with no
#      server running.
#
#   5. install-recommended-extensions.sh inside dev-builder — idempotent,
#      reads the workspace's recommendations, installs missing.
#
#   Steps 4-5 run AFTER step 3 because vscode-server only appears once host
#   VSCode attaches; installing earlier lets the recommendations prompt fire
#   on first attach anyway — the paper-cut auto-install exists to remove.
#
# Host needs: VSCode; Dev Containers extension (one-time --install-extension
# ms-vscode-remote.remote-containers); ssh-agent with keys loaded (`make
# status` surfaces this; compose.dev.yml binds the agent socket in as
# SSH_AUTH_SOCK=/ssh-agent, reachable from the integrated terminal).
#
# Failure modes worth naming (rest just propagate via set -e): dev-builder not
# up after step 1 → explicit `[ -z "$$CONTAINER_NAME" ]` check below; vscode-
# server never appears (step 4 timeout) → extension missing on host or attach
# failed, wait script's message points at the cause.
ide-up:
	$(_REQUIRE_HOST)
	@echo "==> dev-builder up..."
	@$(DC) up -d --wait dev-builder
	@# (The /etc/group entry for the host docker GID is added by the container's
	@# start command in compose.dev.yml, not here — so it lands on every launch
	@# path, not just this recipe. Needs root, hence the image's NOPASSWD sudo;
	@# threat model unaffected since the socket bind already exposes root.)
	@# Write .tmp then rename: atomic, so a workspace.sh failure leaves the
	@# existing workspace file untouched. && chain so mv only runs on success.
	@echo "==> regenerating hubs-apps.code-workspace..."
	@bash $(S)/host/dc-run.sh bash /platform/scripts/workspace.sh \
		> $(PLATFORM_ROOT)/.hubs-apps.code-workspace.tmp \
		&& mv $(PLATFORM_ROOT)/.hubs-apps.code-workspace.tmp \
			$(PLATFORM_ROOT)/hubs-apps.code-workspace
	@# set -euo pipefail so intermediate failures trip make; without it the
	@# trailing `echo` returns 0 and masks earlier errors (code not on PATH,
	@# wait-for-vscode-server timeout).
	@set -euo pipefail; \
	CONTAINER_NAME=$$($(DC) ps --format '{{.Name}}' dev-builder); \
	if [ -z "$$CONTAINER_NAME" ]; then \
		echo "ERROR: dev-builder did not come up. See compose output above." >&2; \
		exit 1; \
	fi; \
	HEX=$$(printf '%s' "$$CONTAINER_NAME" | od -An -txC | tr -d ' \n'); \
	echo "==> launching VSCode (attaches to $$CONTAINER_NAME via vscode-remote)..."; \
	code --file-uri "vscode-remote://attached-container+$$HEX/platform/hubs-apps.code-workspace"; \
	echo "==> waiting for vscode-server inside container (Dev Containers extension installs it on first attach)..."; \
	bash $(S)/host/wait-for-vscode-server.sh "$$CONTAINER_NAME"; \
	echo "==> installing recommended extensions (idempotent — skips already-present)..."; \
	$(DC) exec -T dev-builder bash /platform/scripts/install-recommended-extensions.sh; \
	echo "==> ide-up complete"

ide-down:
	@# Scoped `stop`, not project-wide `down` (which would also hit nextcloud +
	@# postgres + sidecars). Preserves dev-builder-home so vscode-server survives.
	@$(DC) stop dev-builder

# webpack — per-app sidecar lifecycle. MODE=hmr|watch generates the per-app
# fragment + brings up sidecar-<APP>; MODE=off removes both. Every transition
# runs regen-htaccess in nextcloud so the Apache HMR proxy block reflects
# current reachability. MODE=off uses `rm -fs` (stop + remove): an Exited
# container left behind becomes a compose-orphan to later `compose run` calls
# with a smaller -f set (e.g. dc-run.sh's).
#
# The hmr|watch fragment is written temp-then-mv with an explicit rc test, so a
# compose.sh failure leaves $(SIDECAR_FRAG) at its prior contents (a partial
# fragment would load as malformed YAML next $(DC)). The explicit test is
# required because set -e does NOT reliably abort on a redirected command in
# every shell — it continues inside a `( … )` subshell — so this must not lean
# on it.
webpack:
	$(_REQUIRE_APP)
	@set -e; case "$${MODE:?MODE=<hmr|watch|off>}" in \
		hmr|watch) \
			if [ -z "$$($(DC) ps -q nextcloud)" ]; then \
				echo "ERROR: Nextcloud must be running for make webpack MODE=$$MODE — it hosts the" >&2; \
				echo "       Apache HMR proxy the sidecar serves through. Run: make nc-up" >&2; \
				exit 1; \
			fi; \
			mkdir -p $(dir $(SIDECAR_FRAG)); \
			if APP_ROOT=$(A) bash $(S)/host/dc-run.sh bash /platform/scripts/compose.sh $(APP_NAME) "$$MODE" > $(SIDECAR_FRAG).tmp; then \
				mv $(SIDECAR_FRAG).tmp $(SIDECAR_FRAG); \
			else \
				rm -f $(SIDECAR_FRAG).tmp; \
				echo "ERROR: compose.sh failed; $(SIDECAR_FRAG) left unchanged" >&2; \
				exit 1; \
			fi; \
			$(DC) up -d --wait --wait-timeout 300 sidecar-$(APP_NAME); \
			$(DC) exec -T nextcloud /usr/local/bin/regen-htaccess ;; \
		off) \
			$(DC) rm -fs sidecar-$(APP_NAME); \
			rm -f $(SIDECAR_FRAG); \
			rm -f $(A)/.build/js/*.hot-update.*; \
			if [ -n "$$($(DC) ps -q nextcloud)" ]; then $(DC) exec -T nextcloud /usr/local/bin/regen-htaccess; else echo "==> Nextcloud down — skipped HMR proxy refresh (nothing to refresh while NC is down)" >&2; fi ;; \
		*) echo "ERROR: MODE must be hmr|watch|off (got: \"$$MODE\")" >&2; exit 1 ;; \
	esac

# setup — one-shot bootstrap for a fresh clone. Idempotent, every step gated on
# absence. Host-only steps are git clones (SSH-credentials carve-out); per-app
# install dispatches into the container via dc-run.sh. No nested make.
#
# Per-app shape diverges by type:
#   Quilt apps (apps/<X>/upstream/ present)
#     1. assemble MODE=force, gated on .build/.git — the universal "assemble
#        ran" marker; .build/node_modules wouldn't trigger for PHP-only apps.
#     2. build.sh, gated on .build/package.json present AND .build/js absent —
#        PHP-only apps lack package.json and would crash webpack.itsl.js on
#        "no webpack base config found".
#   Standalone apps (no upstream/)
#     1. npm ci (gated on node_modules)
#     2. composer install --no-dev --no-scripts (gated on vendor/autoload.php).
#        --no-scripts skips composer post-install hooks (arbitrary third-party
#        code); quilt apps run theirs via assemble.sh's controlled flow.
#     3. npm run build (gated on js/) — no platform webpack wrap: standalone
#        apps match the convention via their own webpack.config (sdkmc) or use
#        another tool, so `npm run build` is the build-tool-agnostic path.
setup:
	$(_REQUIRE_HOST)
	@[ -f l10n-tools/l10n ] || git submodule update --init l10n-tools
	@# The extendable app set lives in setup-apps.list (one app per line), NOT
	@# inline here — a developer adds an app by editing that file, no Makefile
	@# edit. Read it with a plain bash-3.2 `read` loop (no mapfile). The DEFAULT
	@# IFS (space/tab) splits each line into name/url/flags and trims surrounding
	@# whitespace — do NOT prefix `IFS=`, which clears IFS and would dump the
	@# whole line into $$_name. Skip blanks + #-comments. `flags` is the literal
	@# "recurse-submodules" or empty; $${_flags:+--$$_flags} expands to
	@# `--recurse-submodules` when present (quilt apps) and to nothing when absent
	@# (standalone), reproducing the prior per-app flags exactly. The
	@# `|| [ -n "$$_name" ]` processes a final line lacking a trailing newline, so
	@# a hand-edited list never silently drops its last app. Same absence gate
	@# ([ -d apps/<name> ]) keeps re-running setup idempotent. `set -e` + no
	@# 2>/dev/null on the redirect: a missing/unreadable list crashes setup loudly.
	@set -e; while read -r _name _url _flags || [ -n "$$_name" ]; do \
		case "$$_name" in ''|\#*) continue ;; esac; \
		[ -d apps/$$_name ] || git clone $${_flags:+--$$_flags} "$$_url" apps/$$_name; \
	done < $(PLATFORM_ROOT)/setup-apps.list
	@# apps/server is Nextcloud core, not an "app": github not gitlab, shallow,
	@# pinned to the NEXTCLOUD_VERSION tag, with its own submodule init. Too
	@# special for the list, so it stays inline.
	@[ -d apps/server ]     || { git clone --branch $(NEXTCLOUD_VERSION) --depth 1 https://github.com/nextcloud/server.git apps/server && git -C apps/server submodule update --init --depth 1; }
	@# Per-app sub-steps that install third-party code route through EPHEMERAL=1:
	@# the quilt build.sh (npm ci + composer install + Mozart hook) and the
	@# standalone npm ci + composer install. assemble MODE=force is source-only
	@# (benign) now but rides ephemeral too — consistency in a single-gesture
	@# bootstrap over micro-optimisation.
	@set -e; for d in apps/*/; do \
		app=$$(basename $$d); \
		[ "$$app" = server ] && continue; \
		if [ -d $$d/upstream ]; then \
			if [ ! -d $$d/.build/.git ]; then \
				echo "==> Assembling $$app (quilt — MODE=force)"; \
				APP_ROOT=$(PLATFORM_ROOT)/$$d EPHEMERAL=1 bash $(S)/host/dc-run.sh env MODE=force bash /platform/scripts/assemble.sh; \
			fi; \
			if { [ -f $$d/.build/package.json ] && [ ! -d $$d/.build/js ]; } \
			   || { [ -f $$d/.build/composer.json ] && [ ! -d $$d/.build/vendor ]; }; then \
				echo "==> Building $$app (quilt — install deps + webpack via platform itsl config)"; \
				APP_ROOT=$(PLATFORM_ROOT)/$$d APP_NAME=$$app EPHEMERAL=1 bash $(S)/host/dc-run.sh bash /platform/scripts/build.sh; \
			fi; \
		else \
			echo "==> Installing + building $$app (standalone — no platform webpack wrap, defers to app's scripts.build)"; \
			APP_ROOT=$(PLATFORM_ROOT)/$$d EPHEMERAL=1 bash $(S)/host/dc-run.sh bash -c 'set -e; \
				if [ -f package.json ] && [ ! -d node_modules ]; then npm ci; fi; \
				if [ -f composer.json ] && [ ! -f vendor/autoload.php ]; then composer install --no-dev --no-scripts; fi; \
				if [ -f package.json ] && [ ! -d js ]; then npm run build; fi'; \
		fi; \
	done

# new-app — scaffold a new quilt app, compile JS, wire it into a running NC.
# NAME is derived from upstream's info.xml <id> inside new-app.sh (NC loads
# apps by <id>; deriving rather than asking removes the mis-scaffold foot-gun).
# One shell sub-recipe so NAME flows from step 1's stdout to steps 2-3.
#   1. dc-run.sh APPS_RW=1 (no APP_ROOT bind — apps/<NAME> doesn't exist yet) →
#      new-app.sh derives NAME, scaffolds + assembles + commits, emits NAME on
#      stdout (progress to stderr, per dc-run.sh's stdout-reservation contract).
#   2. dc-run.sh APP_ROOT bind (apps/<NAME> exists now) → build.sh.
#   3. If NC is up, discover-apps inside it. Silent skip if down — `make nc-up`
#      later runs the same discovery via the entrypoint's --strict path.
# The step-3 `compose ps -q` is not a swallowed error: by here the daemon is
# known reachable (steps 1-2 would have crashed otherwise), and ps -q is silent
# whether NC is up (prints ID) or declared-but-down (prints nothing).
new-app:
	@# EPHEMERAL=1 on both calls: new-app.sh is install-touching (deps.sh regen +
	@# l10n init; its assemble MODE=force is source-only now), and the follow-up
	@# build.sh installs deps (npm ci + composer install + Mozart). APPS_RW=1 on
	@# the first call sets the /apps bind new-app.sh writes /apps/<NEW> into; the
	@# pool's /platform/apps:rw wouldn't match that path, so this must stay on
	@# the ephemeral route.
	@set -e ; \
	NAME="$$(APPS_RW=1 EPHEMERAL=1 bash $(S)/host/dc-run.sh env \
		UPSTREAM=$(UPSTREAM) VERSION=$(VERSION) \
		bash /platform/scripts/new-app.sh)" ; \
	APP_ROOT="$(PLATFORM_ROOT)/apps/$$NAME" APP_NAME="$$NAME" EPHEMERAL=1 \
		bash $(S)/host/dc-run.sh bash /platform/scripts/build.sh ; \
	if [ -n "$$($(DC) ps -q nextcloud)" ]; then \
		$(DC) exec -T nextcloud /usr/local/bin/discover-apps ; \
	fi

# --- Build / package / deploy ---
# Three per-app targets, one MODE=production flag (no MODE → .build/;
# production → .dist/ full rebuild):
#   make build [MODE=production]              compile JS
#   make package [MODE=production]            build + tar into <app>.tar.gz
#   make deploy [MODE=production] SERVER=...  build + package + scp + remote install
#
# Prereq chain: package needs build, deploy needs package, MODE propagates via
# the CLI variable. Every deploy runs the chain fresh — there is no
# ship-existing-tarball path; "shippable" and "freshly built" are the same.

build:
	$(_REQUIRE_APP)
	@# EPHEMERAL=1 for the distributable modes (production/snapshot) — they run
	@# `npm ci` in .dist/, executing package postinstall hooks (mail's
	@# patch-package). Dev mode (no MODE) is a one-shot webpack, no third-party
	@# code; routes to pool. CI sets KEEP_CACHED_DEPS=1 so assemble's
	@# clean_build_dir preserves the dep cache it restored into .dist/ (unset
	@# locally → full-clean assemble, unchanged).
	@set -e; EPHEMERAL=; \
	if [ "$${MODE:-}" = "production" ] || [ "$${MODE:-}" = "snapshot" ]; then EPHEMERAL=1; fi; \
	APP_ROOT=$(A) EPHEMERAL=$$EPHEMERAL bash $(S)/host/dc-run.sh env \
		MODE=$${MODE:-} KEEP_CACHED_DEPS=$${KEEP_CACHED_DEPS:-} \
		bash /platform/scripts/build.sh

# package: depends on build. dev (no MODE) → tar .build/ as-is; snapshot/
# production → composer install + Mozart wrap (via the app hook) + optional
# version stamp + tar .dist/. MODE picks the flavor (production = --no-dev +
# optimized; snapshot = dev deps). All dispatch (MODE validation, dev-mode
# flock + sidecar refusal, STAMP_*) lives in package.sh; this recipe forwards
# MODE + STAMP_*.
package: build
	$(_REQUIRE_APP)
	@# EPHEMERAL=1 for the distributable modes (production/snapshot) — they run
	@# composer install (+ the app's mozart/composer-plugin hooks: third-party
	@# code). Dev mode just tars .build/; routes to pool.
	@set -e; EPHEMERAL=; \
	if [ "$${MODE:-}" = "production" ] || [ "$${MODE:-}" = "snapshot" ]; then EPHEMERAL=1; fi; \
	APP_ROOT=$(A) EPHEMERAL=$$EPHEMERAL bash $(S)/host/dc-run.sh env \
		MODE=$${MODE:-} \
		STAMP_VERSION=$${STAMP_VERSION:-} STAMP_PIPELINE_IID=$${STAMP_PIPELINE_IID:-} \
		bash /platform/scripts/package.sh

# deploy: depends on package. Host-side SSH-credentials carve-out (~/.ssh,
# agent socket) — the second instance of that exception alongside `make
# tunnel`. MODE's effect is via the chain (package MODE=production builds the
# tarball deploy ships).
#
# DEPLOY_REMOTE_SCRIPT: one block fed to ssh -T via stdin so the remote
# sequence (extract, version reset, occ upgrade) runs in a single handshake.
# installed_version reset to 0.0.0 forces NC to re-run migrations even when
# info.xml's version hasn't bumped (common in dev iterations).
define DEPLOY_REMOTE_SCRIPT
set -euo pipefail
docker cp /tmp/$(APP_NAME).tar.gz hubs-php:/var/www/html/apps/$(APP_NAME).tar.gz
docker exec hubs-php bash -c '
    set -euo pipefail
    cd /var/www/html/apps/
    rm -rf $(APP_NAME)
    tar xzf $(APP_NAME).tar.gz
    rm -f $(APP_NAME).tar.gz
    chown -R www-data:www-data $(APP_NAME)
'
docker exec -u www-data hubs-php php occ config:app:set $(APP_NAME) installed_version --value=0.0.0
docker exec -u www-data hubs-php php occ upgrade
endef
export DEPLOY_REMOTE_SCRIPT

# Host-side ssh/scp options — match seed.sh + compose.tunnel.yml so SERVER=<host>
# logs in identically across deploy/seed/tunnel: User=ubuntu by default
# (overridable via SERVER=user@host, since ssh's user@host destination wins over
# -o User), plus accept-new so a first-time dev host's key doesn't prompt
# mid-recipe — the piped `ssh -T … bash -s` would otherwise read the heredoc as
# the host-key prompt answer.
DEPLOY_SSH_OPTS = -o StrictHostKeyChecking=accept-new -o User=ubuntu

deploy: package
	$(_REQUIRE_APP)
	@echo "==> Uploading $(APP_NAME).tar.gz to $(SERVER)"
	@scp $(DEPLOY_SSH_OPTS) $(A)/$(APP_NAME).tar.gz $(SERVER):/tmp/
	@echo "==> Deploying to hubs-php container + occ upgrade"
	@echo "$$DEPLOY_REMOTE_SCRIPT" | ssh $(DEPLOY_SSH_OPTS) -T $(SERVER) sudo bash -s
	@VERSION=$$(ssh $(DEPLOY_SSH_OPTS) $(SERVER) "sudo docker exec hubs-php php occ app:list --output=json | jq -r '.enabled[\"$(APP_NAME)\"]'"); \
		echo "==> $(APP_NAME) $$VERSION deployed to $(SERVER)"

# seed — populate dev env with test users + remote-mirrored mailboxes. Runs
# from either side: its only needs (ssh to SERVER; compose exec into nextcloud)
# work identically in the IDE-attach container, which has the host SSH agent
# at /ssh-agent and the docker socket bound in.
seed:
	@SERVER="$(SERVER)" bash $(S)/host/seed.sh

# tunnel — SSH port-forward IMAP/SMTP from a remote dev VM to a
# service-name-addressable container in the compose project. tunnel-up.sh
# validates SERVER, reads HOST_SSH_AUTH_SOCK, and runs `compose --profile tunnel
# up -d tunnel` (detached). `make status` shows state; `make down` stops it.
tunnel:
	@SERVER="$(SERVER)" bash $(S)/host/tunnel-up.sh

# status — runtime + per-app diagnostic. Host-side (compose ps + filesystem
# probes) plus one batched dc-run.sh for the .build/.lock probe: flock(1) isn't
# on the host floor, so the probe uses Python's fcntl.flock in dev-builder. No
# $(_REQUIRE_APP) — the report is platform-wide regardless of cwd. Read-only.
status:
	@bash $(S)/host/status.sh

# Teardown ladder — down → clean → distclean, ordered by what you LOSE. All
# three first run $(GUARD): refuse if a .build/ op is in flight, unless
# EPHEMERALS=1 (which kills it and proceeds). $(DC_TEARDOWN) down --remove-orphans stops +
# removes all platform services (sidecars included) on every rung and sweeps the
# tunnel + any stopped husk; it's safe because the guard cleared anything in
# flight first (see the GUARD / --remove-orphans note near the $(DC) macro).
#
#   down      Stop the stack — keeps named volumes, .build/, .dist/, workspace;
#             nc-up/ide-up resume where you were. DROPS the sidecar fragments:
#             down removed every sidecar container, and a fragment with no
#             container is an orphaned declaration `make status` flags (the
#             fragment IS the sidecar's persistent state, like webpack MODE=off
#             deletes its own when it stops one). Regenerated by make webpack:
#             from each app dir, `make webpack MODE=<mode>` to bring it back.
#   clean     RESET THE DEV ENVIRONMENT, KEEP YOUR WORK. `-v` wipes the named
#             volumes (postgres_data; nextcloud_data/config/apps/apps_extra — so
#             a NEXTCLOUD_VERSION bump re-seeds from the new image; dev-builder-
#             home — fresh vscode-server), and .dist/ + generated fragments +
#             workspace go. .build/ (your assembled tree + uncommitted edits) is
#             KEPT. Resume: nc-up + seed; your code is intact.
#   distclean clean PLUS your .build/, the project's built images (hubs-apps/*,
#             all versions), and the python/bytecode caches — the hard reset,
#             back to `make setup`.
#
# Per-app .build/ (distclean) and .dist/ (clean + distclean) removed by glob —
# apps/server has neither, no-op there. down/clean/distclean each also delete
# the sidecars/*.yml fragments (every rung removes the sidecar containers, so
# the fragment declarations they leave behind are orphaned; regenerated by
# make webpack) but NOT docker/sidecars/.gitkeep, which keeps the dir's
# ownership on next clone; the workspace is regenerated on every make ide-up.
# All rm paths are PLATFORM_ROOT-absolute so a teardown run from an app dir
# (Makefile shim, CWD=apps/<x>) removes the platform tree, not a CWD-relative
# miss. compose.yml/dev-builder.yml/dev.yml are committed, untouched.
clean:
	$(_REQUIRE_HOST)
	@$(GUARD)
	@$(DC_TEARDOWN) down -v --remove-orphans
	@rm -rf $(PLATFORM_ROOT)/apps/*/.dist/ $(PLATFORM_ROOT)/docker/sidecars/*.yml \
		$(PLATFORM_ROOT)/hubs-apps.code-workspace $(PLATFORM_ROOT)/.hubs-apps.code-workspace.tmp

distclean:
	$(_REQUIRE_HOST)
	@$(GUARD)
	@$(DC_TEARDOWN) down -v --remove-orphans
	@# Hard reset: also remove the project's built images (all versions, incl.
	@# stale tags left by prior VERSION bumps) so the next setup / dc-run / nc-up
	@# rebuilds them from the Dockerfiles. Base images (cimg, postgres, node) are
	@# upstream pulls, not ours — left cached. `docker image rm -f` is idempotent
	@# on an already-gone ID; the reference glob lists only present images, so an
	@# empty result skips the rm rather than erroring on no args. `set -eo
	@# pipefail` so a `docker images` failure aborts the teardown loudly instead
	@# of the pipe masking it into an empty (skip-the-rm) result.
	@set -eo pipefail; ids="$$(docker images --filter=reference='hubs-apps/*' -q | sort -u)"; [ -z "$$ids" ] || docker image rm -f $$ids
	@rm -rf $(PLATFORM_ROOT)/apps/*/.build/ $(PLATFORM_ROOT)/apps/*/.dist/ $(PLATFORM_ROOT)/docker/sidecars/*.yml \
		$(PLATFORM_ROOT)/hubs-apps.code-workspace $(PLATFORM_ROOT)/.hubs-apps.code-workspace.tmp \
		$(PLATFORM_ROOT)/.pytest_cache $(PLATFORM_ROOT)/tests/__pycache__ $(PLATFORM_ROOT)/scripts/__pycache__
