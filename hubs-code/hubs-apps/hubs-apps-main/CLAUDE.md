# Working in hubs-apps (agents read this first)

This repo is the build platform for ITSL's customised Nextcloud apps. What to read, by task:

- **Using the platform** (assemble, run, save patches, build, deploy) → [`README.md`](README.md).
- **Modifying a quilt app** (`mail`, `calendar`, …) → [`docs/quilt-workflow.md`](docs/quilt-workflow.md)
  first — the agent rules, patch-craft, and pitfalls.
- **Modifying the platform itself** (scripts, Makefile, images, compose) →
  [`docs/architecture.md`](docs/architecture.md).

## Always-on rules

- **Everything runs in containers, via `make`.** The host has no node/php/composer/quilt/python/jq, on
  purpose (supply-chain isolation). Don't install a toolchain on the host; don't run those tools
  host-native. `make` targets wrap into the dev-builder for you; app tests run in-container too
  (`scripts/host/dc-run.sh`, or the IDE-attach terminal).
- **`make quilt` is interactive-only.** No `PATCH=`/`FILE=`/`NEW=`/`JSON=` form. Drive it in a real terminal — never
  fake a TTY (`mkfifo`/`script`/`expect`), which leaks long-lived orphan processes.
- **Never `rm -rf .build` (the whole directory)** — it breaks a sidecar's bind-mount inode. Reset with
  `make assemble MODE=discard` or `MODE=force`.
- **Never write "Nextcloud" or "Talk" into customer-facing output** — both are trademark-restricted.
  `apps/spreed` is upstream Nextcloud Talk; its in-repo README is verbatim Talk marketing — don't copy
  app text into anything customer-facing.
- **`git push` is not local.** It triggers CI builds, customer-visible deploys, and ticket
  notifications. Never push without explicit instruction; committing is fine.
- **Crash fast.** No `2>/dev/null || true`, no swallowing a failure to make a step "pass."
