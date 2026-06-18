# Hubs Start — Flödesnavet

**Hubs Start** is a standalone Nextcloud app (app id `hubs_start`, namespace
`HubsStart`) that becomes the user's first view after login. It is an
**action-first dashboard** that ties together every secure communication channel
into a single triage queue ("Att hantera") with verified deep links down into the
underlying functions.

It solves the recurring problem *"the whole doesn't hang together"* for a
caseworker's four most common tasks:

1. Send a secure message (with automatic channel selection)
2. Receive and assign incoming items
3. Book and run a secure meeting
4. Identify the counterparty

## Architecture

- **Standalone app** — follows the `sdkmc` pattern (no quilt patches against
  upstream). Registered with a single line in `setup-apps.list` and inherits the
  whole CI chain. No upstream maintenance cost.
- **The data layer is owned by `sdkmc`**, where the mappers live. Hubs Start
  itself stores **no** secure-communication data. The aggregation
  (summary / receipts / recipients / secure-meeting / meetings) plus the three
  mirroring dashboard widgets are delivered as **additive new files to `sdkmc`**
  (see [`backend-additions/`](backend-additions/)). The status model is never
  duplicated.
- **One server-side aggregation endpoint**
  (`/ocs/v2.php/apps/sdkmc/api/v1/summary`) with `sinceIds` + cache. Client-side
  fan-out is deliberately avoided.
- **Channel classification is encapsulated in one server-side service**
  (`ChannelClassificationService` in `sdkmc`) — never duplicated on the client.
- **Mirroring widgets** (`IAPIWidgetV2`) appear in the standard Nextcloud
  dashboard from day one, which covers mobile clients and resolves the
  "double start view" concern.
- **Frontend stack:** Vue 2.7 + `@nextcloud/vue` v8 (matching `sdkmc`/`mail`
  exactly — **not** Vue 3). State is a small `Vue.observable` store (no
  Pinia/Vuex).

The binding interface specification lives in
[`docs/CONTRACTS.md`](docs/CONTRACTS.md); the exact backend file placements and
route/registration patches live in
[`backend-additions/MANIFEST.md`](backend-additions/MANIFEST.md).

## Build

Requires Node `^20` and npm `^10`.

```sh
npm ci
npm run build      # production bundle  (NODE_ENV=production webpack)
npm run dev        # development bundle
npm run watch      # rebuild on change

# In the Linux dev platform the standard target also works:
make webpack
```

Quality gates:

```sh
npm run lint       # eslint  (src/**/*.{js,vue})
npm run stylelint  # stylelint (src/**/*.{css,scss,vue})
npm run test       # jest unit tests (tests/unit)
```

## Make it the landing page

The first view is driven by the `defaultapp` system value (the navigation entry
is also registered with `order` 1 so the app sits at the top of the app menu):

```sh
occ config:system:set defaultapp --value='hubs_start,dashboard,files'
```

## Further reading

- [`docs/INSTALL.md`](docs/INSTALL.md) — full install procedure and the critical
  deployment ordering (backend before frontend).
- [`docs/CONTRACTS.md`](docs/CONTRACTS.md) — the binding component/API contract.
- [`backend-additions/MANIFEST.md`](backend-additions/MANIFEST.md) — exact target
  paths and the route/DI patches for the `sdkmc` and `mail` additions.
