# ITSL Mail

ITSL's customized build of [Nextcloud Mail](https://github.com/nextcloud/mail), adding SDK messaging, FAX, secure email, tagging, and audit logging for the Swedish public sector.

## Architecture

This repo layers ITSL changes on top of upstream via the [hubs-apps platform](https://gitlab.itsl.se/itsl/hubs-apps):

```
upstream/           Git submodule (read-only Nextcloud Mail, pinned version)
patches/            Quilt patches applied on top of upstream
overlay/src/itsl/   ITSL Vue components, stores, utils
overlay/            Files that replace or extend upstream (webpack, appinfo, l10n)
```

Six upstream Vue components are transparently swapped with ITSL versions via `NormalModuleReplacementPlugin` entries in `overlay/webpack.common.js`.

See the [platform README](../../README.md) for setup instructions, make targets, and development workflow.

## Upgrade Guide

See [docs/STABLE-UPGRADE-GUIDE.md](docs/STABLE-UPGRADE-GUIDE.md) for the step-by-step upgrade path from v4.2.0 to v5.7.4 via stable branch tips.

## Visualizations

- [Upgrade Strategy](docs/v9-stable-strategy.html) — the 10-step stable-to-stable plan
