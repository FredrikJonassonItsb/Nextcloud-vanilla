# Architecture

How this platform works and **why** — for someone modifying it (scripts, Makefile, images, compose).
For using the platform, see the [`README.md`](../README.md); for agent workflow rules,
[`CLAUDE.md`](../CLAUDE.md).

**This doc describes contracts, shapes, and rationale — not line-by-line behaviour.** The code is the
source of truth for *what* each step does; this doc explains the *why* and points you at the file. If
you catch it reciting an algorithm the code already states, that's drift — cut it.

---

## Ground rules (the "why" behind everything below)

- **Docker-native.** The toolchain (node, php, composer, quilt, python, jq) exists only in containers.
  The host floor is `git` + `docker compose` + `make` + `bash` + `ssh` + coreutils.
- **Both Linux and macOS are supported hosts — and macOS is untestable from here.** Linux is where the
  platform is tested; macOS runs OrbStack, bash 3.2, and BSD coreutils, and we can't exercise it. So
  **host-side code stays bash-3.2- and BSD-compatible** (no `[[ ]]`, `mapfile`, `declare -A`, GNU-only
  `stat`/`xargs` flags) even where Linux wouldn't need it, and several decisions below (watch polling,
  the OrbStack SSH-agent path, `ls -nd` over `stat`) exist only for the host we can't test. Don't
  "simplify" them away.
- **Supply-chain isolation is the floor, not a feature.** Running third-party installs on a
  credential-bearing host is the threat. Installs run in ephemeral containers with no path to host
  credentials; the long-running containers run no installs. Everything below bends to this.
- **Crash fast.** Scripts are `set -euo pipefail`; no `2>/dev/null || true`, no pre-check-then-skip
  that hides a missing precondition. A surprising state should crash loudly, not be papered over.
- **Rationale lives in the artifact.** Non-obvious decisions are commented at the code, not here and
  not in commit messages. This doc holds only cross-cutting rationale.

---

## Repo topology

Three nested git repos, three roles:

- **`hubs-apps`** — the platform. Owns `scripts/`, `Makefile`, `docker/`, `webpack/`, `tests/`, this
  doc. `apps/` is gitignored.
- **`apps/<app>/`** — each app, independently cloned (NOT a submodule). Owns `patches/`, `overlay/`,
  the `upstream/` submodule pin, its own git/branches/stashes.
- **`apps/<app>/.build/`** — assembled output, a git repo created by `make assemble MODE=force`. Source
  of truth for **nothing** — reproducible from the app repo. So **never hand-edit `.build/` expecting it
  to persist, and never commit it**; edits there are saved via `make quilt` or reconciled away by the
  next assemble. `make quilt` uses its `.git` as durable in-flight storage (the `baseline` tag, the
  `make-quilt-pending` tag during a save).

Durable state lives in branch tips, tags, and stashes; every working tree is reproducible.

---

## Container topology

### Images

Three custom images, each tagged by a single-integer `docker/<image>/VERSION` file (the source of truth
for the tag; the Makefile reads it at parse time, compose interpolates it with `${VAR:?}` so a missing
value crashes loud).

| Image | Base | Role |
|---|---|---|
| `hubs-apps/dev-builder` | `cimg/php:8.3-node` + apt extras + `bun` + the npm-registry-shield | the dev pipeline (assemble/quilt/deps/build/package) and the IDE-attach endpoint |
| `hubs-apps/node-sidecar` | `node:24-slim` | per-app webpack sidecar (HMR or watch) |
| `hubs-apps/nextcloud` | `ubuntu` + Apache/PHP (NC core is bind-mounted, not baked) | the runtime, alongside stock `postgres` |

**Bump scheme:** bump a `VERSION` file → the local tag is now missing → the next compose-up / `dc-run.sh`
rebuilds it once (layer cache makes it fast). App developers do nothing. **Gotcha worth stating once:**
bumping `NEXTCLOUD_VERSION` does **not** rebuild the nextcloud image — the image tag is the separate
integer `docker/nextcloud/VERSION`. Bumping NC is a two-file change (see the README's teardown section).

dev-builder's base is pinned to `cimg/php:8.3-node` because the CI builder image shares that base
(below) and the dev-builder must remain a superset of it.

### Runtime roles

The dev-builder image runs in **three** roles with different binds and lifecycles:

| Role | Lifecycle | Brought up by | Binds |
|---|---|---|---|
| **IDE-attach** dev-builder | long-running, idle | `make ide-up` | `/platform` ro + `/platform/apps` rw + `/platform/docker/sidecars` rw + docker socket + SSH-agent socket + home volume |
| **build-pool** dev-builder | long-running, lazy | first benign `dc-run.sh` call | `/platform` ro + `/platform/apps` rw |
| **ephemeral** dev-builder | per-command | install-touching recipes (`EPHEMERAL=1`) | single app at `/app` rw + `/platform` ro |

Plus `nextcloud` + `postgres` (the runtime) and `sidecar-<app>` (one webpack container per app,
mode-switchable). Each long-running flow has its own up/down — `nc-up`, `ide-up`, `webpack MODE=…` — so
the user composes per task; the pre-rebuild monolithic `make dev` is gone.

### `dc-run.sh` routing — pool vs ephemeral

`scripts/host/dc-run.sh` wraps every in-container command and routes by the recipe's `EPHEMERAL` signal.
The contract:

- **Benign ops** (assemble plain/discard/recover, build/package dev, quilt, diff) → `docker exec` into
  the long-running **build-pool**. The pool exists to avoid paying container-creation cost on every
  call, and it only ever runs platform code we own.
- **Install-touching ops** (`EPHEMERAL=1`: assemble MODE=force, deps, security-update, setup, new-app,
  build/package MODE=production) → a fresh `docker compose run --rm`, discarded at exit, so hostile
  post-install state can't persist. **This is the supply-chain boundary** — the reason the routing
  exists at all.

Short-circuits (run in place, no wrap): `CI` set (the runner is already a container — see CI below), or
`IN_BUILDER=1` **and not** `IDE_ATTACH=1` (a shell inside the build-pool). The IDE-attach terminal
deliberately does *not* short-circuit — it routes to a sibling ephemeral, because the IDE container's
blast radius is larger. `make status`'s probe forces `EPHEMERAL=1` so reading state never lazy-starts
the pool.

### Compose layout

Hand-maintained, under `docker/`: `compose.yml` (NC + Postgres + the wholesale
`${HOST_PROJECT_DIR}/apps:/srv/apps` bind), `compose.dev-builder.yml` (the `dev-builder` and
`build-pool` services), `compose.dev.yml` (the IDE-attach overlay — docker socket, home volume,
group_add, SSH-agent bind; loaded by `make` but **not** by `dc-run.sh`), `compose.tunnel.yml`
(profile-gated). Plus generated `docker/sidecars/<app>.yml` (one per sidecar, by `scripts/compose.sh`).
The Makefile `$(DC)` macro assembles the `-f` list per invocation.

### Networking

One bridge network; **service-name DNS, no `host.docker.internal`.** Apache in the nextcloud container
reverse-proxies each app's `apps-extra/<app>/js/*` and `apps-extra/<app>/ws` to `sidecar-<app>:3000`.
The proxy block in `.htaccess` is regenerated-from-running on every `make webpack` (the in-image
`regen-htaccess` TCP-probes each sidecar's port 3000 and emits a rule pair per reachable HMR sidecar) —
a derived view of compose state, not an accumulated log, so killed sessions self-heal.

---

## Supply-chain isolation

The rationale floor is in Ground Rules and the routing section; the net-new specifics
(`scripts/host/dc-run.sh`, `docker/compose*.yml`, `scripts/deps.sh`):

- **`dc-run.sh` forwards no host env by default** (a dumb wrapper); recipes forward only the vars they
  need via an inline `env(1)` shim. (The one exception it forwards itself: `EDITOR`/`GIT_EDITOR`, which
  every interactive editor invocation needs.) `/platform` is read-only inside builders.
- **npm metadata is quarantined.** `make deps` / `make security-update` launch the `npm-registry-shield`
  inside the ephemeral and point npm at it; it hides registry versions younger than 3 days, so a fresh
  compromise can't land in a lockfile before the community catches it. It gates lockfile *writes* only;
  everything downstream consumes the pinned, hash-verified lockfile via `npm ci`, so pulling from the
  public registry is safe.
- **Two carve-outs, IDE-attach only:** the docker socket is bind-mounted (so in-container `make`
  orchestrates compose) and the host **SSH** agent socket is bind-mounted in (so `git push` works) —
  sound because that container runs no installs. The platform binds the SSH agent socket directly rather
  than relying on the Dev Containers extension's forwarding, which is unreliable. Ephemerals stay walled
  off.
- **Host-side SSH** is limited to `make deploy` and `make tunnel` (credentials are host-only); the
  tunnel runs as a compose service with the host agent socket forwarded in, never a key in an image.

## UID/GID

Host UID/GID flow `host → .env → Makefile → compose → Dockerfile` so anything a container writes to a
bind mount lands owned by the host user. The Makefile auto-detects from `id -u`/`id -g`; compose
defaults to `1000` if unset; the dev-builder Dockerfile is the link that fails loud on a direct
`docker build` without the args. Caches (`node_modules`, `vendor`, `lib/Vendor`, …) live in
bind-mounted host dirs, not named volumes — the IDE on the host must see them for LSP resolution.

---

## `make assemble` — the modes

Implements "plain assemble is non-destructive." `assemble.sh` runs in the dev-builder. Mode is a single
`MODE` env var; any value other than the five below (or a positional arg) is rejected:

| Mode | Contract |
|---|---|
| plain (`MODE` unset) | Non-destructive reconcile. No `rm -rf`. Stashes dev edits, walks `.build/` to the current series, restores edits. Refuses if a `make-quilt-pending` tag is set (→ `MODE=recover`). |
| `MODE=force` | Full destructive **source** rebuild (no dep install — the `make assemble MODE=force` target chains `build.sh`'s install after it). **The sole bootstrapper** of `.build/.git`, the sole installer of the platform files, the sole writer of the ignore files. The escape hatch. |
| `MODE=discard` | Reset `.build/` to baseline (hard reset + clean + stash clear), then reconcile. Discards uncommitted edits. |
| `MODE=recover` | Restore `.build/` from the `make-quilt-pending` snapshot after a failed save. |
| `MODE=production` | `.dist/`-only production rebuild — the build path, not a `.build/` reconcile. See Build/package/deploy. |

The reconciliation walk (plain and discard) is **file-test-gated** rather than driven by `quilt
applied`/`series`, which refuse on exactly the cross-branch mismatch the walk exists to fix; and it pops
via the tracked `.pc/` snapshots so it survives cross-branch moves. The sequence itself is in
`scripts/assemble.sh` — read it there.

**No `.build-ready` sentinel exists anywhere.** Webpack rides plain/discard/recover/quilt file churn
natively (its own watcher). `MODE=force`'s `rm -rf` is the one case webpack can't ride — so the
**Makefile `assemble` recipe** stops the sidecar before MODE=force and restarts it after, using compose
primitives. This lives recipe-side because `assemble.sh` runs in-container with no docker socket.

**Ignore files** (seeded by MODE=force only): `.build/.git/info/exclude` is platform territory
(overwritten wholesale — the flock target, the forwarder Makefile, the webpack configs);
`apps/<app>/.git/info/exclude` is the dev's territory (append-if-missing — `.build/`, `.dist/`,
`*.tar.gz`). The platform writes **nothing** into any `.gitignore`; overlay symlinks are tracked in
baseline, so ESLint sees them as the regular files they are.

`write-baseline.sh` is the shared baseline-capture helper (assemble, `make quilt`, test reset all call
it); it requires `.build/.git` to already exist and disables git gc (a mid-save pack would burn minutes).

## `make quilt` — the storage model

`scripts/quilt_v2.py` (+ `quilt_extract`/`present`/`write`/`verify`/`common`). The design choice: in-flight
state is a **git commit in `.build/.git`**, not RAM — it survives mid-save process death, and
`git show <ref>:<path>` yields bytes for any file type uniformly.

- `baseline` tag = the post-last-assemble state. `git diff baseline` + `git ls-files --others` is the
  change set.
- The `make-quilt-pending` commit/tag, captured at entry, is both the byte source during the write and
  the crash-recovery snapshot. Dropped only by success or by `MODE=recover`.
- **Interactive only.** No `PATCH=`/`FILE=`/`NEW=`/`JSON=` selectors exist — the tool prompts for every
  change. Driving it from a non-interactive call (piped stdin, faked PTY) aborts or leaks processes.
- Six phases; the contracts are in the module docstrings.

**Exit codes.** These are `quilt_v2.py`'s own codes, surfaced in its diagnostic message — `make quilt`
itself returns only `0` (success) or `2` (any failure: `make` collapses every nonzero recipe status to
its own `2`), so route recovery on the printed message, not on `$?`. The codes (from the module
docstring):

| Code | Meaning |
|---|---|
| 0 | success |
| 1 | error — pending tag already set at entry, extract failure, or a write/verify failure. **Not all of these are recoverable via `MODE=recover`** (an extract failure left nothing to recover). |
| 2 | missing prerequisite (`.build/.git` or `baseline` absent) **or** run outside the dev-builder (`assert_in_builder`) |
| 3 | nothing to save / everything skipped |
| 6 | `.build/.lock` held by another process |
| 130 | user aborted (Ctrl-C, EOF, or declining the final `Apply?` — including typing `n`) |

Container placement matters: the container's quilt is 0.66 (whatever the base image ships; the tooling
assumes 0.66 behaviour), and it force-writes `.pc/.quilt_patches` because 0.66 reads that file and
overrides `QUILT_PATCHES` on every call.

## The `.build/.lock`

Every `.build/` toucher (`assemble`, `quilt`, `diff`, dev `build`/`package`, the test reset) takes an
exclusive `flock` on `.build/.lock` and fails fast on contention (`make quilt` exits 6). Webpack
sidecars are excepted (they ride file churn, don't mutate via the tooling). `make l10n` touches
`.build/` but serialises by operator discipline, not the lock. The lock file is never unlinked (a fresh
inode would defeat the flock) — it's preserved across MODE=force.

## Teardown

`make down` → `clean` → `distclean`, widening by what they destroy: `down` stops every container and
keeps everything; `clean` adds `down -v` (removes named volumes — Postgres data, NC data, and the
`dev-builder-home` volume, so vscode-server re-installs); `distclean` adds `.build/` + the built images +
caches. All three first run **`scripts/host/teardown-guard.sh`**, which refuses to tear down while a
`.build/` operation is in flight (a running ephemeral, or a build-pool op holding the lock) unless
`EPHEMERALS=1` is passed to reap it — so a teardown can't silently kill an install mid-flight. Any
teardown recipe change must keep the guard in front.

## `make status`

`scripts/host/status.sh` is the operator diagnostic, and its invariants are load-bearing for anyone
editing it: **always exits 0** (findings are information, not a pass/fail signal), **never mutates**,
**never lazy-starts** the pool (its probe forces `EPHEMERAL=1`), and **degrades per-probe** so that even
with docker entirely down the host-only probes still report every app's build state. Don't "fix" any of
those into a conventional exit code or a side effect.

## Webpack chain

Two configs in `webpack/` (the pre-rebuild four collapsed to two): `webpack.itsl.js` (platform base —
app-config resolution, `resolveLoader`, watch options) and `webpack.hmr.js` (the HMR-only deltas — `eval`
devtool, stable chunk names, the devServer block) which `require`s `itsl.js`. Mode is a CLI flag
(`--mode development|production`), not a per-mode file. Both are copied into each app's `.build/` by
MODE=force, so the relative require resolves in-container.

Load-bearing settings you must not casually change — each carries its rationale inline in the config,
so read the comment before touching it: `poll: 1000`, `followSymlinks`, and the hash-based snapshots
(the macOS-symlink-watching set); `devtool: 'eval'` in HMR (vue-loader + LaxifyCSP); `writeToDisk: true`
(jsresourceloader's on-disk check); and the fixed `port: 3000` (service-name DNS disambiguates).

CI distributable builds (`MODE=snapshot`/`production`, signalled by `WEBPACK_DIST` from `build.sh`) set
`devtool: false` — sourcemap emit is among the slowest sealing steps and bloats the tarball; local dev
and HMR keep their source maps.

## Dependency merge

`make deps` regenerates `overlay/package.json` / `composer.json` and lockfiles via
`scripts/itsl-merge.jq`, merging three sources: upstream's manifest + the platform base
(`itsl-{npm,composer}-deps.json`) + app fragments (`itsl-*-deps.d/*.json`). Operators: set/override,
`key+` (append to array), `key-` (remove keys/values), `"__REMOVE__"` (delete key). `key+` on an
*existing* non-array errors rather than silently replacing (a missing key it just sets).

`overlay-copyfiles.d/*` lists overlay files that must be **copied** into `.build/` rather than symlinked
— for files a tool rewrites in place (composer/npm lockfiles, followed via `realpath`) or resolves
relatively (the app's own `webpack.common.js`, which does `require('./…')`), where a symlink would send
the write into `overlay/` or break `__dirname`. (The *platform* webpack configs are a separate case —
copied into `.build/` by MODE=force, not via `overlay-copyfiles.d`.)

## Build, package, deploy, CI

`make build` → `make package` → `make deploy SERVER=…` chain (each depends on the prior), with one
`MODE=production` flag swapping the work dir from `.build/` to `.dist/`. Build/package run in the
dev-builder; deploy is host-side (the host-SSH carve-out). Details in `scripts/{build,package}.sh` and
the Makefile `deploy:` recipe.

Two MODEs produce a *distributable* into `.dist/` (real files, no overlay symlinks): **`MODE=production`**
(`composer --no-dev` + optimized autoload + minified webpack — the customer artifact) and
**`MODE=snapshot`** (dev deps + unminified webpack — a fast, installable dev1-grade tarball). The
assemble is identical for both; only the install/webpack flavor differs. `MODE=production` keeps its
original meaning (the customer build); CI uses `MODE=snapshot` on every non-tag pipeline (see below).

**What ships** is a curated runtime whitelist, not the whole `.dist/` (which also holds `src/`,
`node_modules/`, build configs). `package.sh` tars that whitelist straight from the source tree — no
staging copy — and mode-gates compression (fast for snapshot, tight for the customer build). Its header
is the canonical ship-list: the app dirs, the dep dir for whichever convention the app uses
(`vendor`/`composer`/`3rdparty`), and license files.

**Version stamping is CI-driven and the script is dumb.** `package.sh` reads `STAMP_VERSION` (verbatim,
tag builds) or `STAMP_PIPELINE_IID` (X.Y.`<iid>`, branch builds), or neither (no stamp), in both
distributable modes. The decision of *which* to set lives entirely in the CI build job (`ci/validate.yml`);
`SKIP_VERSION_STAMP=1` (set per-app in the app's `.gitlab-ci.yml`) opts an app out — for apps that
advertise their version via the NC capabilities API, where auto-stamping confuses clients.

### CI — a builder, not a developer

CI runs the custom `ci-builder` image — `cimg/php:8.3-node` with quilt + rsync + pigz **and the platform
tree itself** baked in, so jobs run with no per-job apt install and no runtime platform clone. The
`build-image` job (root `.gitlab-ci.yml`) builds it on every branch push and tag, **tagged by the ref it
was built from**; `.build_env` (`ci/base.yml`) pulls `ci-builder:$PLATFORM_REF`, so the image —
toolchain + platform — always matches the version each app pins, and a Dockerfile or platform change
ships on the next ref with no manual bump. No docker-in-docker. The `CI` env var makes `dc-run.sh`
exec-passthrough, so the same recipes run unwrapped on the runner — hence the dev-builder-is-a-superset
constraint. CI never touches the developer working tree: no `MODE=force`, no `.build/`, no overlay
symlinks, and the app repo is shallow-cloned (`GIT_DEPTH=1` — it builds the tip and reads no history).
It builds the distributable into `.dist/` and its job is to produce the installable
`<app>.tar.gz` as fast as possible, then lint + audit; some refs also upload to the registry. The
`<app>.tar.gz` output contract that `hubs-php` fetches is preserved.

**Mode by trigger.** `build:app` (`ci/validate.yml`) runs `make package MODE=snapshot` by default and
`MODE=production` on tags (the `DIST_MODE` job var, set by a `rules` override). So
feature/`develop`/`main`/`stable*` pipelines get the fast snapshot tarball; tags get the customer
release tarball, built **clean** (no dependency cache — a shipped artifact never reuses a dev-warmed
cache; see below). Uploads (`ci/upload.yml`) fire on tags + `main`/`develop`/`stable*` (and the
`itsl/main`/`itsl/stable*` prefixed lines) — snapshot tarballs are fine in the registry for non-tag refs.

**Dependency cache (cross-pipeline reuse).** Deps live in `.dist/` and persist between pipelines via
GitLab's **cache**; the *artifact* channel carries only the small `<app>.tar.gz`. That's the whole fix
— the old shape re-installed `node_modules`/`vendor` from scratch every run. On a warm cache the
installers reuse the restored trees (`npm ci` skipped, `composer install` a no-op), so a code-only
re-run does no install work. **Tag builds use no cache at all**, so the customer artifact is always a
clean build from the locked deps. The keys themselves — what each is keyed on, what is deliberately not
cached, and why — are documented at the point they are defined, in `ci/base.yml`'s "Dependency cache
keys" block.

**Consumers.** `test:lint` (`ci/lint.yml`) lays its own source-only `.dist/` (assemble, no install, no
webpack) and pulls the shared npm cache for `node_modules` — it never re-installs, never consumes a
heavy `.build/` artifact. The audits (`ci/security.yml`) run `needs: []` (independent of the build, so
a patch-apply failure can't hide a vuln) against the committed lockfile — `overlay/`, or the shallow-
inited `upstream/` submodule for overlay-less apps.

## Tests

Three shapes (see `tests/` and each file's top docstring):

- **Unit** — the driver imports platform modules, so the driver itself must run in the container. Each
  file's docstring carries its exact invocation:
  `APP_ROOT="$PWD/apps/mail" bash scripts/host/dc-run.sh python3 /platform/tests/<file>.py`.
- **Harness** — a host driver shells into the container via `make`/`dc-run.sh`/`tmux`
  (`tests/test_misc_integration.py`, `tests/test_interactive.py`).
- **State-integrity chaos arc** — run via the wrapper `bash tests/run_state_integrity.sh`, **never**
  the `.py` directly (the wrapper carries the environment guards and post-checks the `.py` omits).

`quilt_common.assert_in_builder` refuses a host-native run as a backstop (exit 2), but the rule is
doctrine: platform code runs in the dev-builder.

## App layout reference

Per-app conventions (a developer edits these; the platform reads them):

- `upstream/` (submodule) — the quilt-vs-standalone marker. Present → quilt; absent → standalone.
- `patches/series` + `patches/*.patch` — the quilt stack.
- `overlay/` — ITSL-only files, mirrored into `.build/` (symlinked dev, rsynced production).
- `overlay-copyfiles.d/` — overlay files to copy not symlink (above).
- `itsl-npm-deps.d/` / `itsl-composer-deps.d/` — dependency-merge fragments.
- `l10n-src/` — PO sources; output to `overlay/l10n/` (quilt) or `l10n/` (standalone).

`apps/server` is Nextcloud core — live-bound into the runtime container, skipped by every app-walking
loop. App-specific wrinkles live with the app (e.g. `mail` Mozart-wraps vendored libs into `lib/Vendor/`
via a composer post-install hook that fires on every `build.sh` composer install).
