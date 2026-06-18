# Backend additions — integration manifest

These files are **additive** changes to the existing `sdkmc` and `mail` apps.
They are delivered here (rather than edited in place in the reference checkout)
so the change set is reviewable in one place. Each file lists its exact target
path. Nothing here edits existing behaviour; the only modifications to existing
files are an appended OCS route block and a handful of DI registration lines,
both provided as snippets below.

---

## STATUS + märknings-konvention + boundary (uppdaterad 2026-06-17)

**Deploy-state:** ALLA sdkmc-additioner nedan är **deployade + verifierade på dev15**
(`/var/www/html/apps/sdkmc/lib/`, sdkmc 2.2.25, NC 31.0.8). Varje OCS-route svarar 401
oautentiserat (route + DI resolverar), sdkmc förblev enabled, `occ status` frisk. Backup
togs på `appinfo/routes.php` + `lib/AppInfo/Application.php` (`.hubsbak`) före in-place-edit.

**Märknings-konvention (för upstream-PR till officiella sdkmc):**
- **Nya filer:** SPDX-header + `HUBS-START BACKEND-ADDITION · UPSTREAM-KANDIDAT · Target: lib/...`.
- **In-place-ändringar** (endast `appinfo/routes.php` — en ny `'ocs'`-nyckel): omsluten av
  `// >>> HUBS-START-ADD (upstream-kandidat) ───` … `// <<< HUBS-START-ADD ───`.
- **Application.php rörs INTE** — OCS-controllers/services autowire:as. (Dashboard-widget-
  registreringen är frivillig och utelämnad för att inte röra sdkmc:s boot.)

**Boundary (vilket dashboard-API hör vart — ratificerat):** meddelande/kontakt/möte = sdkmc;
ärende = `hubs_arende` (vår egen app).

| Endpoint | Hör hemma i |
|---|---|
| summary, receipts, recipients, meetings, secure-meeting, **team, favoriter, inflode-summary, inflode/{action:besvara\|vidarebefordra}** | **sdkmc** (additions här) |
| arende-summary, **fordelning-summary, treserva/receipts**, inflode/{action:skapa\|koppla\|registrera\|gallra}, arende/*, steg | **hubs_arende** (ej i denna MANIFEST) |

`hubs_start/src/services/api.js` routar per boundary (ärende-verb → `HUBS_ARENDE_OCS`,
meddelande-verb → `SDKMC_OCS`). sdkmc:s `inflode/{action}` avvisar ärende-verb med
`400 agas_av_arende_motorn`; hubs_arende avvisar meddelande-verb symmetriskt.

**Fas 2 — nya socialsekreterare-additioner (2026-06-17):**

| Deliverable | Target path i sdkmc |
|---|---|
| `sdkmc/lib/Service/TeamService.php` | `lib/Service/TeamService.php` |
| `sdkmc/lib/Controller/OCS/TeamController.php` | `lib/Controller/OCS/TeamController.php` |
| `sdkmc/lib/Service/FavoriterService.php` | `lib/Service/FavoriterService.php` |
| `sdkmc/lib/Controller/OCS/FavoriterController.php` | `lib/Controller/OCS/FavoriterController.php` |
| `sdkmc/lib/Service/InflodeFeedService.php` | `lib/Service/InflodeFeedService.php` |
| `sdkmc/lib/Controller/OCS/InflodeFeedController.php` | `lib/Controller/OCS/InflodeFeedController.php` |

Route-rader (tillagda i samma `'ocs'`-block):
```php
['name' => 'OCS\\Team#index',          'url' => '/api/v1/team',            'verb' => 'GET'],
['name' => 'OCS\\Favoriter#index',     'url' => '/api/v1/favoriter',       'verb' => 'GET'],
['name' => 'OCS\\InflodeFeed#summary', 'url' => '/api/v1/inflode-summary', 'verb' => 'GET'],
['name' => 'OCS\\InflodeFeed#action',  'url' => '/api/v1/inflode/{action}','verb' => 'POST'],
```
Datakällor (verifierade, ej påhittade): Team = `IGroupManager`/`IUserManager`; Favoriter =
`OCP\\Contacts\\IManager` (favorit-adressböcker, pekare ej kopia); InflodeFeed = `ItslMailboxMapper`
+ mail-tabeller (samma mönster som `SummaryService::fetchUnassignedThreads`). Alla graceful:
saknad källa på dev15 → ärligt tom shape, aldrig 500, aldrig fabricerad PII.

---

## sdkmc — new files

| Deliverable | Target path in sdkmc |
|---|---|
| `sdkmc/lib/Service/ChannelClassificationService.php` | `lib/Service/ChannelClassificationService.php` |
| `sdkmc/lib/Service/SummaryService.php` | `lib/Service/SummaryService.php` |
| `sdkmc/lib/Service/MeetingService.php` | `lib/Service/MeetingService.php` |
| `sdkmc/lib/Service/SecureMeetingService.php` | `lib/Service/SecureMeetingService.php` |
| `sdkmc/lib/Controller/OCS/SummaryController.php` | `lib/Controller/OCS/SummaryController.php` |
| `sdkmc/lib/Controller/OCS/RecipientController.php` | `lib/Controller/OCS/RecipientController.php` |
| `sdkmc/lib/Controller/OCS/SecureMeetingController.php` | `lib/Controller/OCS/SecureMeetingController.php` |
| `sdkmc/lib/Controller/OCS/MeetingController.php` | `lib/Controller/OCS/MeetingController.php` |
| `sdkmc/lib/Dashboard/AttHanteraWidget.php` | `lib/Dashboard/AttHanteraWidget.php` |
| `sdkmc/lib/Dashboard/KvittenserWidget.php` | `lib/Dashboard/KvittenserWidget.php` |
| `sdkmc/lib/Dashboard/DagensMotenWidget.php` | `lib/Dashboard/DagensMotenWidget.php` |

> Note: OCS controllers live under `lib/Controller/OCS/`. The route `name`
> values therefore use `OCS\\Summary#…` etc. (see route block below).

## sdkmc — append to `appinfo/routes.php`

Add this block inside the returned array (sdkmc's routes.php currently has only a
`'routes'` key — add a sibling `'ocs'` key, or merge if one already exists):

```php
'ocs' => [
    ['name' => 'OCS\\Summary#summary',      'url' => '/api/v1/summary',                'verb' => 'GET'],
    ['name' => 'OCS\\Summary#receipts',     'url' => '/api/v1/receipts',               'verb' => 'GET'],
    ['name' => 'OCS\\Recipient#search',     'url' => '/api/v1/recipients/search',      'verb' => 'GET'],
    ['name' => 'OCS\\Recipient#classify',   'url' => '/api/v1/recipients/classify',    'verb' => 'GET'],
    ['name' => 'OCS\\SecureMeeting#create', 'url' => '/api/v1/secure-meeting',         'verb' => 'POST'],
    ['name' => 'OCS\\Meeting#today',        'url' => '/api/v1/meetings/today',         'verb' => 'GET'],
    ['name' => 'OCS\\Meeting#lobby',        'url' => '/api/v1/meetings/{token}/lobby', 'verb' => 'GET', 'requirements' => ['token' => '[a-z0-9]{4,30}']],
],
```

## sdkmc — register dashboard widgets in `lib/AppInfo/Application.php`

Add to the top `use` block:

```php
use OCA\SdkMc\Dashboard\AttHanteraWidget;
use OCA\SdkMc\Dashboard\KvittenserWidget;
use OCA\SdkMc\Dashboard\DagensMotenWidget;
```

Add inside `register(IRegistrationContext $context)`:

```php
$context->registerDashboardWidget(AttHanteraWidget::class);
$context->registerDashboardWidget(KvittenserWidget::class);
$context->registerDashboardWidget(DagensMotenWidget::class);
```

These mirror the dashboard data into the standard Nextcloud dashboard + mobile
clients and resolve the "double start view" concern from day 1.

## mail — routerhook (`overlay/src/itsl/utils/initITSL.js`)

Add the `initComposerDeepLink()` function from
`mail/initITSL-additions.js` and call it once inside `initITSL()` (after
`initInterceptors()`). It registers handling for
`/apps/mail/new?type={messageType}&to={value}` and opens `ComposerItsl` with the
channel preselected. See that file for the exact, drop-in snippet and the two
integration lines.

## hubs_start registration in the build platform

Add one line to `hubs-apps/setup-apps.list`:

```
hubs_start <clone-url-of-hubs_start>
```

Set the landing page per installation:

```
occ config:system:set defaultapp --value='hubs_start,dashboard,files'
```
