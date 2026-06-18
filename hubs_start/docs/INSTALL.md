# Hubs Start — Installation

This document covers deploying `hubs_start` together with its backend additions.
Read [`../backend-additions/MANIFEST.md`](../backend-additions/MANIFEST.md) first
— it lists the exact target path of every backend file and the route/DI patches.

## Prerequisites

Hubs Start is a front-end shell over data owned by other apps. The following must
already be installed and enabled in the same instance:

- **`sdkmc`** — owns the secure-communication data layer and (after the backend
  additions) the aggregation OCS endpoints and the mirroring dashboard widgets.
  Hubs Start cannot show real counters without it.
- **`mail`** — receives the small routerhook addition that powers the
  channel-preselected composer (`/apps/mail/new?type=…&to=…`).
- **`spreed`** — secure meetings (the meeting wizard / today + lobby endpoints).
- **`calendar`** — meeting scheduling.

Platform: Nextcloud 30–32, PHP ≥ 8.1, Node `^20` / npm `^10` for building the
frontend bundle.

> Version note: the spreed fork currently requires NC 31 while the platform runs
> NC 32 — coordinate the upgrade window (see HANDOVER.md §9).

## Critical-path ordering (backend BEFORE frontend)

This is the single largest shared risk. **Deploy the backend additions before
enabling the dashboard**, in this order. Enabling `hubs_start` first will show an
empty / broken first view because the OCS endpoints it calls will not exist yet.

1. **`ChannelClassificationService`** (`sdkmc`) — the heart of "Smart mottagare".
   Already hand-written; ships in `backend-additions/sdkmc/`.
2. **sdkmc OCS endpoints** — `summary`, `receipts`, `recipients/*`,
   `secure-meeting`, `meetings/*`. Copy the new files to their target paths,
   append the `'ocs'` route block to `sdkmc/appinfo/routes.php`, and register the
   three dashboard widgets in `sdkmc/lib/AppInfo/Application.php`, all exactly as
   specified in the MANIFEST. `summary` must return **real server counters** for
   the virtual mailboxes (currently hard-coded 0).
3. **mail routerhook** — add `initComposerDeepLink()` to
   `mail/overlay/src/itsl/utils/initITSL.js` and call it once inside `initITSL()`
   (after `initInterceptors()`), per the MANIFEST. Without this, "one click"
   becomes three.
4. **Then** install and enable `hubs_start` itself, and set it as the landing
   page (steps below).

## Install steps

1. **Register the app in the build platform.** Add one line to
   `hubs-apps/setup-apps.list`:

   ```
   hubs_start <clone-url-of-hubs_start>
   ```

2. **Apply the backend additions** per
   [`../backend-additions/MANIFEST.md`](../backend-additions/MANIFEST.md):
   copy each new file to its listed target path under `sdkmc`/`mail`, append the
   OCS route block, add the widget DI registrations, and wire the mail
   routerhook. Rebuild the `sdkmc` and `mail` frontends as needed.

3. **Build the Hubs Start frontend** (in the app directory):

   ```sh
   npm ci && npm run build      # or: make webpack
   ```

4. **Enable the app:**

   ```sh
   occ app:enable hubs_start
   ```

5. **Set it as the landing page:**

   ```sh
   occ config:system:set defaultapp --value='hubs_start,dashboard,files'
   ```

## Verify

- The Hubs Start navigation entry appears at the top of the app menu (order 1)
  and is the page served after login.
- The "Att hantera" triage queue shows **real** counts (not 0), confirming the
  sdkmc `summary` endpoint is reachable.
- Sending from the queue opens the mail composer with the channel preselected,
  confirming the mail routerhook is active.
- The three mirroring widgets ("Att hantera", "Kvittenser", "Dagens möten")
  appear on the standard Nextcloud dashboard.
