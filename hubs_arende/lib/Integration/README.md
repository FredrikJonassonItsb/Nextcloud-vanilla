<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# hubs_arende — Integrationslager (ports + stubbar + live)

Detta lager isolerar ärende-motorn (`OCA\HubsArende`) från alla externa
integrationer bakom **portar** (interfaces). Varje port har en **stateful stub**
för bygg/test/demo utan riktiga integrationer, och en (kommande) **live**-impl
mot den verkliga tjänsten. Vilken som körs styrs per port av **INTEGRATION_MODE**
i app-config (`stub` | `live`, default `stub`).

Mönstret är migrerat ur prototypens stub-lager
(`hubs_start/src/services/demo/treserva.js`) och spårbart mot seam-registret i
`hubs_start/docs/DEMO-STUBS.md`.

---

## 1. Port ↔ Stub ↔ Live ↔ Seam — mappningstabell

| Port (interface) | Stub (in-memory, körbar nu) | Live-mål (skarp impl) | INTEGRATION_MODE-nyckel (app-config) | DEMO-STUBS seam-id |
|---|---|---|---|---|
| `Integration\Port\FacksystemCommitPort` | `Integration\Stub\FacksystemCommitStub` | Frends iPaaS → Treserva/Lifecare/Viva (verifierad callback) | `hubs_arende.integration.facksystem` | `treserva`, `treserva.commit`, `treserva.skapa`, `treserva.koppla` |
| `Integration\Port\SigneringPort` | `Integration\Stub\SigneringStub` | Inera Underskriftstjänst (PAdES-B-LTA) | `hubs_arende.integration.signering` | *(nytt: `signering`)* |
| `Integration\Port\EdiariumPort` | `Integration\Stub\EdiariumStub` | e-diarium / e-arkiv (FGS-Ärende/Diarium/Paket) | `hubs_arende.integration.ediarium` | *(nytt: `ediarium`)* |

> App-config-nyckeln är scopad till app-id `hubs_arende`. Den korta nyckeln in i
> `IAppConfig` är t.ex. `integration.facksystem` (app-id sätts av ramverket).
> Default när nyckeln saknas: `stub`.

Tjänsten `Service\FacksystemCommitService` är den enda vägen in i facksystem-commit
för `ArendeService::commit()`; den läser INTEGRATION_MODE, routar till rätt modul
och returnerar ett **verifierat kvitto** med identiskt kontrakt mot stub och live.

---

## 2. INTEGRATION_MODE-toggle

Per-port toggle via `occ` (eller admin-UI/`IAppConfig` programmatiskt):

```bash
# Default är 'stub' — dessa rader behövs bara för att gå live per port.
occ config:app:set hubs_arende integration.facksystem --value live
occ config:app:set hubs_arende integration.signering  --value live
occ config:app:set hubs_arende integration.ediarium   --value live

# Tillbaka till stub (bygg/test/demo):
occ config:app:set hubs_arende integration.facksystem --value stub
```

**Fail-safe:** om läget är `live` men ingen live-`FacksystemCommitPort` är
registrerad i DI faller `FacksystemCommitService` tillbaka till stubben **med en
varning i loggen** i stället för att krascha. I prod ska live-porten alltid vara
registrerad (se §5).

**Kodkonstanter:** `FacksystemCommitService::CONFIG_KEY_MODE`,
`::MODE_STUB`, `::MODE_LIVE`. `->mode()` returnerar aktuellt läge.

---

## 3. Det kritiska mönstret — verifierad async callback (GAP-007)

Kärnan ur `treserva.js commitHandling`: **retention startar ENBART på en
verifierad callback**, aldrig på själva commit-anropet eller en kryssruta.

I porten realiseras detta i tre led:

1. `commit($hubsCaseId, $modul, $payload)` — registrerar handlingen, delar ut
   (deterministisk syntetisk) `dnr`, men flippar **inte** provenans/retention.
   I async-läge returneras ett **preliminärt** kvitto (`verifierad=false`,
   `gallrasDatum=null`) + en `callbackToken`.
2. `registerCallback($hubsCaseId, $correlationId)` — binder en korrelationsnyckel
   till det väntande kvittot (speglar Frends verifierade återkallning).
3. `verifyCallback($callbackToken, ['hubsCaseId'=>…, 'dnr'=>…])` — **först här**
   sätts `provenanceState='registrerad'`, `retentionState='gallras_efter_commit'`,
   `gallrasDatum` (+retentionDays) och `verifierad=true`. Idempotent på token.

Stubben kan köra detta **synkront** (`synchronousCallback=true`, default) så att
en hel kedja hänger ihop i ett anrop för demo — men kontraktet och invarianten är
identiska med async-/live-vägen.

---

## 4. Demo: en hel ärende-livscykel på stubbarna

Allt nedan körs **utan** Frends/Inera/e-diarium, helt in-process.

```php
use OCA\HubsArende\Integration\Stub\FacksystemCommitStub;
use OCA\HubsArende\Integration\Stub\SigneringStub;
use OCA\HubsArende\Integration\Stub\EdiariumStub;

$hubsCaseId = '2026-aaaa-bbbb-cccc';            // mintad av ArendeService (saga R1)

// (kat 6) DIREKT-diarieföring via e-diarium (preSagaHook='diariefor_direkt')
$ediarium = new EdiariumStub();
$diarie = $ediarium->registrera($hubsCaseId, [
    'handlingstyp' => 'LVU-ansokan',
    'titel'        => 'Ansökan om vård enligt LVU',
    'riktning'     => 'upprattad',
    'arendetyp'    => 'rattsligt_tvang',
]);
// → ['ok'=>true, 'diarienummer'=>'SN-2026-0101', 'provenanceState'=>'registrerad', ...]

// Signering av beslutet via Inera (simulerad PAdES-B-LTA)
$signering = new SigneringStub(instantSign: true);
$sig = $signering->requestSignature($hubsCaseId,
    ['ref'=>'beslut-1', 'filename'=>'beslut.pdf', 'handlingstyp'=>'beslut'],
    [['uid'=>'anna', 'role'=>'beslutsfattare', 'loa'=>'LOA3']]);
$status = $signering->pollStatus($sig['signRequestId']);     // status='signed'
$signed = $signering->fetchSignedDocument($sig['signRequestId']); // PAdES-PDF (base64)

// Commit till facksystemet (synkron stub kör verifierad callback in-process)
$facksystem = new FacksystemCommitStub(synchronousCallback: true, retentionDays: 90);
$kvitto = $facksystem->commit($hubsCaseId, 'ifo_barn', [
    'typ'                => 'beslut',
    'arendetyp'          => 'rattsligt_tvang',
    'commit_destination' => 'facksystem',
    'frends_modul'       => 'ifo_barn',
    'artefakter'         => ['signRequestId' => $sig['signRequestId']],
]);
// → ['ok'=>true,'dnr'=>'2026-IFO-0501','committedAt'=>…,'gallrasDatum'=>…(+90d),
//    'verifierad'=>true,'modul'=>'ifo_barn','receipt'=>[...]]

$entry = $facksystem->getEntry($hubsCaseId);
// retentionState='gallras_efter_commit' satt FÖRST av den verifierade callbacken.
```

**Async-variant** (testar saga-väntan): `new FacksystemCommitStub(synchronousCallback: false)`.
Då returnerar `commit()` ett preliminärt kvitto + `callbackToken`; anropa sedan
`verifyCallback($token, ['hubsCaseId'=>…, 'dnr'=>…])` för det verifierade kvittot.

**Fel-/timeout-injektion** (testar sagans kompensering):

```php
$facksystem = new FacksystemCommitStub(
    failHubsCaseIds: 'case-fel-1',         // → CommitFailedException
    timeoutHubsCaseIds: 'case-timeout-1',  // → CommitTimeoutException
);
```

Via tjänsten (väljer port + INTEGRATION_MODE + routing):

```php
$kvitto = $facksystemCommitService->commit($hubsCaseId, [
    'commit_destination' => 'facksystem',
    'frends_modul'       => 'ifo_barn',
    'typ'                => 'beslut',
    'arendetyp'          => 'rattsligt_tvang',
]);
```

---

## 5. Att gå live (per port)

1. Implementera live-porten, t.ex. `Integration\Live\FrendsFacksystemAdapter
   implements FacksystemCommitPort`, mot det verkliga Frends-flödet (HTTP via
   `OCP\Http\Client\IClientService`), med verifierad callback bunden till ett
   riktigt callback-event — aldrig till tagg+tid (GAP-007).
2. Registrera den i DI (`AppInfo\Application::register`) och låt den injiceras som
   `$livePort` i `FacksystemCommitService`.
3. Sätt `occ config:app:set hubs_arende integration.facksystem --value live`.
4. `payload`-mappning per modul (IFO-barn vs ek.bistånd-fältschema) är riktig
   integrationskod per modul (GAP-019) och hålls bakom modul-routingen — motorn
   förblir generisk; bara adaptern känner till fältscheman.

Stub- och live-grenen är **fysiskt separerade** bakom porten — att gå skarpt är att
byta INTEGRATION_MODE, exakt som `api.js` DEMO-short-circuit i prototypen.

---

## 6. Spårbarhet mot DEMO-STUBS.md

| Denna kod | Prototyp-seam (DEMO-STUBS.md) | Prototyp-fil |
|---|---|---|
| `FacksystemCommitStub::commit()` + `verifyCallback()` | `treserva.commit` (verifierad callback → retention) | `demo/treserva.js` `commitHandling()` |
| `FacksystemCommitStub` register/dnr-mint | `treserva.skapa` / `treserva` (REGISTER + dnrSeq) | `demo/treserva.js` `skapaArende()`/`REGISTER` |
| `FacksystemCommitStub::listReceipts()` | `treserva.commit` (RECEIPTS-ytan) | `demo/treserva.js` `listReceipts()` |
| `FacksystemCommitService` INTEGRATION_MODE-toggle | `api.js DEMO-short-circuit` (stub vs OCS-gren) | `src/services/api.js` |
| `SigneringStub` | *(nytt — Inera, antas löst GAP-034/035/037/033)* | — |
| `EdiariumStub` | *(nytt — e-diarium/e-arkiv FGS)* | — |

> Varumärkesregel (ärvd): tekniska namn (Treserva, Frends, Inera, Nextcloud)
> används fritt i denna utvecklartext; i UI-citat mot slutanvändare aldrig.
