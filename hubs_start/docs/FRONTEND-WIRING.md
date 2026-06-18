# Frontend wiring — hubs_start → hubs_arende OCS

Hur Vue-demonen (`hubs_start`) pratar med den riktiga ärende-motorn (`hubs_arende`,
NC-app, namespace `OCA\HubsArende`, NC 31.0.8 / PHP 8.3 på dev15).

Allt nätverk går genom `src/services/api.js` (frontend↔backend-kontraktet).
Vue-komponenterna är **oförändrade** — de importerar samma funktioner som förut;
bara vart funktionen ringer har bytts. Varje LIVE-ändrad funktion är markerad med
`// 🔌 LIVE: hubs_arende OCS` i koden.

## OCS-baser i api.js

```js
const SDKMC_OCS       = (p) => generateOcsUrl('apps/sdkmc/api/v1' + p)        // meddelanden/inflöde (orörd)
const HUBS_OCS        = (p) => generateOcsUrl('apps/hubs_start/api/v1' + p)   // UI-preferenser (orörd)
const HUBS_ARENDE_OCS = (p) => generateOcsUrl('apps/hubs_arende/api/v1' + p)  // 🔌 NY: ärende-motorn
```

Effektiv prefix för ärende-motorn: `/ocs/v2.php/apps/hubs_arende/api/v1/...`

## Mappningstabell (LIVE — routas till hubs_arende när demo är AV)

| api.js-funktion          | Metod + hubs_arende-route        | Controller-action   | Body / params skickas               | Retur (OCS-unwrappad) |
|--------------------------|----------------------------------|---------------------|-------------------------------------|-----------------------|
| `fetchArendeSummary()`   | `GET /arende-summary`            | `Arende#summary`    | (valfri `enhet`-query, ej satt än)  | `{ puls, triage, arenden, moten, klartIdag, steg }` |
| `fetchArende(dnr)`       | `GET /arende/{ref}`              | `Arende#show`       | `{ref}` = hubsCaseId **eller** dnr  | hela ärendet (entity `jsonSerialize`) |
| `skapaArende(rad)`       | `POST /arende`                   | `Arende#createCase` | `{ rad }`                           | nytt ärende (HTTP 201) / `{avvisad,reason}` (403) |
| `tilldela(ref, uid)`     | `POST /arende/{ref}/tilldela`    | `Arende#tilldela`   | `{ uid }`                           | `{ ok, ref, uid }` |
| `commitToTreserva(p)`    | `POST /treserva/commit`          | `Arende#commit`     | `{ hubsCaseId, payload }`           | verifierat kvitto `{ ok, dnr, committedAt, gallrasDatum, verifierad }` |

### Reshaping-noteringar (kontrakt mot controllern)

- **`commitToTreserva`** — controllern är `commit(string $hubsCaseId, array $payload)`.
  Det gamla anropet (mot sdkmc) skickade hela payloaden platt. Nu hissas
  `hubsCaseId` ut ur payloaden (`payload.hubsCaseId ?? payload.arende.hubsCaseId
  ?? payload.arende.dnr ?? payload.dnr`) och resten av commit-payloaden
  (`{ arende, typ, artefakter }`) nästlas under `payload`. Kvittot driver
  provenans-flip + retention-nedräkning (aldrig på en checkbox).
- **`skapaArende`** — `createCase(array $rad)`, body `{ rad }` matchar redan.
  Kör SAGA:n (R0 säkerhetsskydd-grind → R1 UUID → R2 register-INSERT → R8 frist →
  R10), idempotent på `conversationId`. Säkerhetsskydd-reject ger HTTP 403 med
  `{ avvisad, reason }` (avvisningskvittot ordagrant).
- **`fetchArende`** — JS-parametern heter fortfarande `dnr` (komponentkontraktet
  oförändrat), men `{ref}` resolvar mot hubsCaseId ELLER dnr i registret (O(1)).
- **`tilldela`** — **ny** funktion i api.js (fanns ingen tidigare). Registrerad i
  default-exporten. Body `{ uid }` matchar `tilldela(string $ref, string $uid)`.

## Fortfarande DEMO / sdkmc (medvetet orörda)

**Meddelanden / inflöde / mottagare / möten → sdkmc (`SDKMC_OCS`, `SDKMC`)** — INTE
flyttade, dessa är sdkmc:s ansvar och konsumeras separat av ärende-motorn:

- `getSettings`, `fetchSummary`, `fetchReceipts`
- `searchRecipients`, `classifyRecipient`, `fetchFavoriter`
- `fetchTodaysMeetings`, `createSecureMeeting`, `fetchLobbyStatus`, `fetchGuestIdentity`
- `takeThread`, `setMessageTag`
- `fetchTreservaReceipts`, `fetchInflodeSummary`, `fetchFordelningSummary`,
  `fetchTeam`, `inflodeAction`
- `fetchAppointmentConfigs` (calendar-appen direkt)
- `getPreferences`, `savePreferences` (hubs_start egen OCS)

> Dessa kan flyttas i ett senare varv när hubs_arende exponerar motsvarande
> aggregat/åtgärder, men just nu äger sdkmc datat.

## DEMO-grenen (fallback)

Varje ärende-funktion har kvar sin `if (isDemo()) return ssDemo.…`-gren ÖVERST.
Demo-läget läses från initial-state `boot.demoMode` (se `demoData.js → isDemo()`):

```js
const boot = loadState('hubs_start', 'boot', {})
return boot && boot.demoMode === true
```

- **`demoMode === true`** → in-memory-fixtures (`demo/socialsekreterare.js`,
  Treserva-stubben). Inget nätverk. För `tilldela` finns ingen stub → optimistisk
  no-op `{ ok: true, ref, uid }` (samma mönster som `inflodeAction`).
- **`demoMode === false`** → LIVE-grenarna ovan körs (riktig hubs_arende-OCS).

## Hur man slår av demo (kör LIVE)

Demo-flaggan injiceras server-side i initial-state `boot`. Sätt
`boot.demoMode = false` i `hubs_start`:s `PageController` (där `boot`-state
provideras), eller via den config som driver den, och ladda om sidan. Då faller
varje `if (DEMO)`-gren igenom och ärende-anropen träffar
`/ocs/v2.php/apps/hubs_arende/api/v1/...` på en install där `hubs_arende` är
aktiverad (`IAppManager::isEnabledForUser`).

Förutsättning för grönt LIVE-läge: `hubs_arende` aktiverad i NC, och sdkmc kvar
för meddelande-/inflödes-ytan (de är separata appar — graceful degradation gäller
internt i hubs_arende, inte i frontend).
