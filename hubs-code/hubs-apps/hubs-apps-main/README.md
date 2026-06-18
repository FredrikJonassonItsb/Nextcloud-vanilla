# ITSL Hubs Apps

The shared platform for building ITSL's customised Nextcloud apps. Each app is upstream Nextcloud
source plus ITSL changes; this platform assembles them, runs them in a local dev stack, saves the
changes as quilt patches, and packages release tarballs that `hubs-php` bundles. The dev toolchain
(node, php, composer, quilt, …) lives **inside containers** — see [Host contract](#host-contract).

> **Working on this platform as an AI agent?** Read [`CLAUDE.md`](CLAUDE.md) first.
>
> **Modifying the platform itself** (scripts, Makefile, images, compose)? See
> [`docs/architecture.md`](docs/architecture.md).

---

## Host contract

Install on the host, and nothing more:

- `git`, with an SSH key registered on `gitlab.itsl.se` (setup clones over SSH — test with
  `ssh -T git@gitlab.itsl.se`)
- `docker` (Linux) **or** OrbStack (macOS)
- `docker compose` v2
- `make`, `bash`, `ssh`, POSIX coreutils

**Do not install `node`, `php`, `composer`, `quilt`, `python`, `npm`, or `jq` on the host.** They run
in containers — running their installers on a machine that holds your SSH keys and GitLab tokens is the
supply-chain exposure this platform exists to avoid, so installs are walled off in ephemeral containers
with no path to your host credentials.

**One carve-out:** the translation tooling runs on the host. `l10n-tools/l10n` — every subcommand —
needs `python3`, `python3-polib`, and `python3-yaml`; `make l10n` (the `fix` workflow) additionally
spawns the `claude` CLI. Nothing else touches the host toolchain.

Windows is out of scope. macOS bash is 3.2 — the host-side scripts stay compatible with it.

---

## First-time setup

```bash
git clone git@gitlab.itsl.se:itsl/hubs-apps.git
cd hubs-apps
make setup
```

`make setup` clones each app, the Nextcloud server source, and the l10n-tools submodule, then installs
dependencies and assembles every quilt app. The first run takes several minutes (it's cloning repos and
running `npm`/`composer` installs — that's normal, not a hang). It is idempotent — re-run it any time
and it skips what's already there.

**The app set is data, not code.** The apps `make setup` clones are listed in
[`setup-apps.list`](setup-apps.list), one per line. Add an *existing* app by adding a line there and
re-running `make setup` — no Makefile edit. (`apps/server`, the Nextcloud core, is cloned specially by
the recipe and is not in the list.)

To **scaffold a brand-new app** from upstream instead of cloning one, `make new-app UPSTREAM=<git-url>
VERSION=<tag>` derives the app name from upstream's `appinfo/info.xml`, sets up `apps/<name>/` (submodule
pin, overlay, dependency merge, l10n, an initial assemble) and commits it. Push the new app repo, then
add it to `setup-apps.list`.

`.env` is optional — copy `.env.example` to `.env` only to override a default; see
[Configuration](#configuration).

---

## Concepts

Read this before the quick start — it defines the terms the rest of the manual uses.

### Each app is its own git repo

`make setup` clones each app into `apps/<app>/` as an **independent git repository** with its own
remote. **You commit your changes there** — in `apps/<app>/`, not in the hubs-apps repo and not in
`.build/`. The hubs-apps repo owns the platform (scripts, Makefile); each app repo owns that app's
patches and overlay.

### Quilt apps vs standalone apps

- **Quilt app** (e.g. `mail`, `calendar`): upstream Nextcloud source as a read-only git submodule
  (`upstream/`), ITSL changes as **quilt patches** (`patches/`), plus an **overlay** (`overlay/`) of
  ITSL-only files. The marker that an app is a quilt app is that `upstream/` exists.
- **Standalone app** (e.g. `sdkmc`): source lives directly in the app directory, no layering — the
  patch/overlay workflow below does not apply to it.

### The assembled tree — `.build/`

`make assemble` composes upstream + patches + overlay into `apps/<app>/.build/`. **`.build/` is what
Nextcloud serves and what webpack compiles** — you edit and run against it. It is reproducible from the
app repo at any time and is the source of truth for nothing; don't commit it.

Inside `.build/`, files come in two kinds, and `make diff` tells you which a given file is:

- **Symlinks into `overlay/`** — editing one writes straight through to `overlay/`, no save step. (A few
  overlay files — lockfiles, the app's webpack config — are *copied* rather than symlinked, so edits to
  those don't flow back.)
- **Real files** (upstream + applied patches) — edits stay in `.build/` until you save them with
  `make quilt`.

### Overlay vs patches — which to use

| You want to… | Put it in | How |
|---|---|---|
| Change an existing upstream file | a quilt patch | edit in `.build/`, then `make quilt` |
| Add a new ITSL-only file (component, class, config) | `overlay/` | create it under `overlay/`, mirroring the `.build/` path |
| Replace an upstream file wholesale | `overlay/` | the overlay file shadows the upstream one at assembly |

---

## Quick start — your first change

```bash
make setup                                  # once (above)
make nc-up                                  # Nextcloud at localhost:8080 (admin/admin), apps enabled
cd apps/mail && make webpack MODE=hmr       # live JS reload for the app you're changing
# edit apps/mail/.build/<file> (real file → patch) or apps/mail/overlay/ (new ITSL file)
make diff                                   # what changed, and which patch owns each file
make quilt                                  # save real-file edits as a patch — interactive, prompts per change
make assemble                               # verify the patch re-applies cleanly
git -C apps/mail add patches overlay && git -C apps/mail commit   # commit in the APP repo
```

Open <http://localhost:8080>, find the app in Nextcloud's top bar, and your `.build/` edit appears —
JS hot-reloads with the `MODE=hmr` sidecar running; PHP and template edits show on a browser refresh
(only JS needs the sidecar). That's the loop; the rest of this manual explains each piece and the cases
the quick start skips.

---

## Running the platform

There is no single "start everything" command. Each layer has its own up/down, so you bring up only
what the task needs. **`make help` is the authoritative list of targets** — this section explains what
they're for, not every flag. Platform targets (`nc-up`, `ide-up`, `setup`, `status`, `down`/`clean`/
`distclean`) run from the repo root; per-app targets (`assemble`, `build`, `package`, `deploy`, `diff`,
`quilt`, `deps`, `webpack`, `l10n`) run from an app directory (`cd apps/<app>`).

### Nextcloud — `make nc-up` / `make nc-down`

```bash
make nc-up      # Nextcloud + Postgres
```

Browse <http://localhost:8080> (published on `127.0.0.1` — local to this host), log in as `admin` /
`admin` (the container provisions that account on first install). `nc-up` brings up **only** Nextcloud
and Postgres — it does not build apps or start webpack. Its container auto-discovers each app under
`apps/`, symlinks it into Nextcloud, and enables it. A cloned **quilt** app you haven't assembled yet
has no `.build/` for Nextcloud to serve, so startup **fails fast** with an error naming it — run
`cd apps/<app> && make assemble MODE=force`, then `make nc-up` again. `make nc-down` stops Nextcloud and
Postgres and keeps your data.

### Editing in an IDE — `make ide-up` / `make ide-down`

`make ide-up` attaches VSCode (running on your host) to a long-running **dev-builder** container — the
container that holds the dev toolchain. You edit on the host; build tools, language servers, and tests
run inside the container.

**Host prerequisites:**
- VSCode with `code` on your `PATH`.
- The Dev Containers extension: `code --install-extension ms-vscode-remote.remote-containers`.
- A graphical desktop session (VSCode is a GUI app).
- `ssh-agent` running with your GitLab key loaded (`ssh-add -l` shows it).
- `make setup` already run in this clone.

```bash
make ide-up
```

The recipe brings up the dev-builder, regenerates the multi-root workspace, launches VSCode at the
attach URI, waits for vscode-server to install in the container (~30–60 s the first time, instant
after), and installs the recommended extensions. You're attached when the VSCode window's bottom-left
corner shows the container name.

**The workspace** shows one or two folders per app:

| App type | Folders | Edit where |
|---|---|---|
| Quilt | `<app>.assembled` + `<app>.repo` | edit assembled code in `<app>.assembled` (`.build/`); commits land in `<app>.repo` (`patches/` + `overlay/`). `make quilt` moves edits from one to the other. |
| Standalone | `<app>` | edit and commit in the same tree |
| `server` | `server` | Nextcloud core, live-bound into the running container — edits to `lib/`, `core/`, `settings/` propagate immediately; edits under `server/apps/` do not |

**Inside the container:** `/platform` is the platform tree (read-only); `/platform/apps` is read-write
(your edits land here); `/home/developer` persists across `ide-down`/`ide-up` (shell history,
vscode-server, globally-installed CLIs survive). Open an integrated terminal with `` Ctrl+` `` — you're
the `developer` user inside the container, and `make` targets work there. `git push` works through your
host's SSH agent, whose socket the platform bind-mounts into the container.

`make ide-down` stops only the dev-builder; Nextcloud, Postgres, and sidecars keep running.

### Live JS — `make webpack MODE=hmr|watch|off` (per app)

Run from an app directory. Each app gets its own webpack sidecar container.

```bash
cd apps/mail
make webpack MODE=hmr     # hot reload (Nextcloud's Apache proxies it)
make webpack MODE=watch   # rebuild-to-disk; refresh the browser yourself
make webpack MODE=off     # stop this app's sidecar
```

Requires `make nc-up` running (each invocation refreshes the Apache proxy) and a built `.build/`. With
`MODE=hmr`, JS edits hot-reload; with `MODE=watch`, they rebuild to disk and you refresh manually. JS
edits do **not** appear without one of these running (PHP and template edits show on a plain refresh).

### See what's running — `make status`

```bash
make status
```

A read-only diagnostic: which containers are up, each app's build state, and any mismatches it spots.
It changes nothing. Reach for it first when something looks wrong.

### Composing the layers

| Goal | Commands |
|---|---|
| Just verify an app's JS compiles | `cd apps/<app> && make build` |
| Edit code in the IDE | `make ide-up` |
| Run an app in the browser, no live editing | `make nc-up`, browse `localhost:8080` |
| Full development with hot reload | `make nc-up`, then `cd apps/<app> && make webpack MODE=hmr` |
| Wind down for the day | `make down` |

---

## Editing & saving changes

### Where to edit

- **Overlay files** (new ITSL files, `overlay/src/itsl/…`): edit under `overlay/` — changes show in
  `.build/` through the symlinks immediately.
- **Upstream files** (changing existing Nextcloud code): edit in `apps/<app>/.build/`, then save with
  `make quilt`.

If you're unsure which kind a `.build/` file is, `make diff` tells you — it shows each change and which
patch (if any) owns it.

### Save changes as patches — `make quilt`

```bash
make quilt
```

**`make quilt` is interactive** — it walks each change and prompts where to route it (an existing
patch, a new patch, the overlay, or skip). It needs a real terminal; there is no non-interactive
`PATCH=`/`FILE=`/`NEW=`/`JSON=` form. Overlay edits never need it — they're already saved through the
symlink. **Commit in the app repo** (`apps/<app>/`: `patches/` + `overlay/`), not the hubs-apps root and
not `.build/`.

A patch should represent **one concern** and carry a real multi-paragraph header. (Agents: the full
patch-craft discipline is in [`docs/quilt-workflow.md`](docs/quilt-workflow.md).)

### Reconciling `.build/` — `make assemble` and its four modes

`make assemble` rebuilds `.build/` from the current upstream + patches + overlay. **You pick the mode
that matches what you did** — the platform does not guess. Day to day you'll use plain; the rest are for
recovery and structural changes.

| Command | When | What it does |
|---|---|---|
| `make assemble` (plain) | Normal flow: after a branch switch, after pulling new patches, dirty working tree | Non-destructive: stashes your edits, walks `.build/` to the current series, restores your edits. Few seconds. |
| `make assemble MODE=force` | Structural change: lockfile bump, upstream pin move, first bootstrap, recovering a corrupted `.build/` | Full destructive rebuild from scratch with fresh `npm`/`composer` installs. Slow. |
| `make assemble MODE=discard` | Deliberately throw away your uncommitted `.build/` edits and reconcile | Resets `.build/` to baseline, then reconciles. Cheap — **but it discards unsaved edits; `make quilt` first if you want them.** |
| `make assemble MODE=recover` | A previous `make quilt` failed and left recovery state | Restores `.build/` from the in-flight save snapshot. Cheap. |

Plain mode preserves your edits across the reconcile; if an edit collides with a patch you get conflict
markers in `.build/` and resolve them with `make quilt` (not another `make assemble`). `MODE=force` is
the escape hatch for anything plain can't handle.

---

## Dependencies

Each app's dependency overlays are generated, not hand-edited. To add or remove a dependency:

1. Edit a fragment in `apps/<app>/itsl-npm-deps.d/*.json` (or `itsl-composer-deps.d/*.json`) — set a
   version, or `"__REMOVE__"` to drop one.
2. `make deps` — regenerates `overlay/package.json` (or `composer.json`) and the lockfile.
3. Commit the fragment and the regenerated files.
4. `make assemble MODE=force` — installs the new lockfile into `.build/`.

`make security-update` applies semver-compatible audit fixes the same way. **Never hand-edit the
generated `overlay/` manifests or lockfiles** (`package.json`, `composer.json`, `*-lock.*`) — `make
deps` owns them and overwrites them.

---

## Translations

```bash
make l10n        # runs the AI-assisted "fix" — host-only, spawns Claude Code
```

`make l10n` runs the `fix` workflow on the host (it needs your `claude` CLI and credentials, which a
container can't reach). The other l10n subcommands run directly from an app dir:

```bash
l10n-tools/l10n start     # extract strings, update PO files
l10n-tools/l10n status    # completeness
l10n-tools/l10n finish    # compile PO → JSON/JS
l10n-tools/l10n audit     # check against terminology policy
```

PO sources live in `l10n-src/`; compiled output lands in `overlay/l10n/` (quilt apps) or `l10n/`
(standalone). For a quilt app, `.build/` must be assembled first.

---

## Build, package, deploy

```bash
make build   [MODE=production]                # compile JS
make package [MODE=production]                # build, then tar into <app>.tar.gz
make deploy  [MODE=production] SERVER=<host>  # build, package, ship, occ upgrade
```

The targets chain: `package` runs `build`, `deploy` runs `package`. `make deploy SERVER=foo` runs the
whole chain fresh — there is no "ship the existing tarball" path, by design. `SERVER=` is required for
`deploy` (checked before anything runs).

- **Dev (default):** compiles against `.build/`; the tarball includes dev dependencies — fatter, but
  fine for dev1 testing (`make deploy SERVER=dev1.hubs.se`).
- **Production (`MODE=production`):** full rebuild into `.dist/`, composer `--no-dev` + Mozart wrap,
  CI-driven version stamping — the customer-facing artifact. Normally CI's job, not a local one.
- **Snapshot (`MODE=snapshot`):** same `.dist/` rebuild but with dev deps + unminified webpack — a
  fast, installable dev1-grade tarball. CI uses `MODE=snapshot` on non-tag pipelines and
  `MODE=production` on tags (see `docs/architecture.md` → "CI").

### Remote mail infrastructure — `make tunnel` / `make seed`

For testing mail against a real dev server (requires SSH access to it):

```bash
make tunnel SERVER=dev1.hubs.se   # SSH tunnel to remote IMAP/SMTP, as a compose service
make seed   SERVER=dev1.hubs.se   # one-time: create test users + copy mailbox data
```

The tunnel forwards IMAP (10143), SMTP out (10025), SMTP in (10026) into the dev stack. `make seed`
provisions test users `autohandlaggare1` / `autohandlaggare2` and mirrors mailbox data. Re-run
`make seed` if you point the tunnel at a different server (stored credentials won't match otherwise).

---

## Teardown & recovery

Three levels, widening by what they remove. All three refuse while a `.build/` operation is in flight;
pass `EPHEMERALS=1` to reap the in-flight op and tear down anyway.

| Command | Stops containers | Named volumes | `.build/` (your work) | Use when |
|---|---|---|---|---|
| `make down` | all | keep | **keep** | end of day; `nc-up`/`ide-up` resume where you left off |
| `make clean` | all | **remove** | **keep** | reset the environment, keep your code (recover with `nc-up` + `seed`) |
| `make distclean` | all | remove | **remove** | hard reset back to `make setup` (also removes built images + caches) |

### Bumping the Nextcloud version

`NEXTCLOUD_VERSION` lives in `.env` (or `.env.example`) and is the single source — never hardcode it
elsewhere. Because the nextcloud image is tagged by `docker/nextcloud/VERSION`, bumping NC is a
two-file change: bump `NEXTCLOUD_VERSION` **and** bump `docker/nextcloud/VERSION`, then
`make distclean && make setup && make nc-up` to rebuild the image and reinstall from it.

### When something's wrong

`make status` first — it shows per-app state and mismatches. A failed `make quilt` →
`make assemble MODE=recover`. A `.build/` you can't otherwise recover → `make assemble MODE=force`.

---

## Container access

```bash
docker compose exec nextcloud bash                           # shell in the NC container
docker compose exec nextcloud php occ <command>              # occ
docker compose exec postgres psql -U nextcloud -d nextcloud  # database
docker compose logs nextcloud                                # logs
```

The Nextcloud log is at `/var/www/html/data/nextcloud.log` inside the container. Postgres listens on
`localhost:5432` (db/user/password all `nextcloud`).

### Tests

App-level tests run **inside the container** (the host has no node/php). From an app's `.build/` via
the dev-builder — e.g. `npx vitest run src/itsl/tests/`. The platform's own test suite is documented in
[`docs/architecture.md`](docs/architecture.md).

---

## Configuration

`.env` (copied from `.env.example`) is the single source for compose and make. Most values are
optional with sane defaults or host auto-detection; copy and edit only what you need to override.

| Variable | Required? | Notes |
|---|---|---|
| `NEXTCLOUD_VERSION` | **required** | the one NC version; see [Bumping the Nextcloud version](#bumping-the-nextcloud-version) |
| `COMPOSE_PROJECT_NAME` | **required** | the compose project every container scopes to; pre-set to `hubs-apps` in `.env.example` — keep it |
| `DEVELOPER_UID` / `DEVELOPER_GID` | auto-detected | host UID/GID, so container-written files land as yours |
| `PHP_VERSION` | defaulted | PHP / base-image version (`8.3`) |
| `PHP_*` limits | defaulted | memory / upload / post-size |

---

## Architecture & internals

How the platform works — container topology, the assemble internals, supply-chain isolation, the
webpack chain, CI and version stamping — is in [`docs/architecture.md`](docs/architecture.md). Agents
working with the platform: [`CLAUDE.md`](CLAUDE.md).
