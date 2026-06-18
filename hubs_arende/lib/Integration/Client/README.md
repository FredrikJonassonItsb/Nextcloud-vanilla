<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Integration clients — sagans R3–R9

These are the **thin consumer clients** for the apps the `createCase()` SAGA
coordinates. `hubs_arende` does **not** own this functionality — each app
(sdkmc, groupfolders, deck, spreed, calendar) is a **separate NC app** consumed
over its **OCS/HTTP API**. The clients are the seam that lets us swap in-process
NC apps for ExApp HTTP calls later without touching the SAGA.

## Design contract (every client)

- **`isAvailable(): bool`** — `IAppManager::isEnabledForUser($appId)`. This is the
  AppDetectionService pattern: when the app is missing, the step **NO-OPs** and
  returns `null`/`false`. The SAGA **must keep running** (graceful degradation),
  never crash.
- **Deterministic + logged** — every method logs (`Psr\Log\LoggerInterface`) and
  returns a stable shape. Failures inside the internal OCS call are **swallowed +
  logged** (`warning`), never thrown, so the SAGA's behaviour is identical whether
  the call is fully wired or still pending.
- **Autowired** — all clients use constructor promotion + OCP-only deps
  (`IAppManager`, `IClientService`, `IURLGenerator`, `LoggerInterface`). No
  registration in `Application.php` is needed; the NC DI container builds them.
- **`TODO[auth]`** — the internal server-to-server OCS call carries **no user
  session**, and most target routes (e.g. sdkmc `itsl_tag#*`, deck/groupfolders/
  spreed OCS) are session/admin authenticated. Each `ocsRequest()` has a
  `TODO[auth]` where a service-account app-password / signed internal-request
  header must be added. Until then the request is attempted and a 401/failure is
  swallowed — the deterministic return value keeps the SAGA + pekare shape stable.

## Where pointers are written — `PekareMapper`

Apps with no case:-tag of their own (groupfolder, deck card, talk room, calendar
object, sdkmc case-tag) cannot be found from the `hubsCaseId` by tag. So each
forward step records a **pekare** (`OCA\HubsArende\Db\PekareMapper`) and the
compensation resolves the external id back through it:

| SAGA step | client | `objekt_typ` | `objekt_id` stored |
|-----------|--------|--------------|--------------------|
| R3 | `SdkmcClient::createCaseTag()`      | `case_tag`     | tag id / imapLabel |
| R4 | `GroupfolderClient::createArenderum()` | `groupfolder` | folderId |
| R5 | `DeckClient::createCard()`          | `deck_card`    | cardId (+ boardId) |
| R6 | `SpreedClient::createRoom()`        | `talk_room`    | talkToken |
| R7 | `CalendarClient::prepareCaseCalendar()` | `calendar` | objUri |
| R9 | `SdkmcClient::tagMessage()`         | `conversation` | conversationId |

`PekareMapper` API: `record($caseId,$typ,$id,$riktning=null)`,
`findByCaseId($caseId)`, `findByCaseAndTyp($caseId,$typ)`,
`deleteByCaseAndTyp($caseId,$typ)` (idempotent — call after the external object is
torn down so no orphan pekare survives).

## WIRING-GUIDE — exact edits in `ArendeService::createCase()`

`ArendeService.php` is **not** touched by this change set (wired separately).
Each client is injected by adding it to the `ArendeService` constructor
(promoted, autowired) **plus** `PekareMapper`:

```php
public function __construct(
    private ArendeMapper $arendeMapper,
    private PekareMapper $pekareMapper,        // NEW
    private ArendeTypRegistry $typRegistry,
    private SakerhetsskyddGrind $sakerhetsskyddGrind,
    private FacksystemCommitService $commitService,
    private SdkmcClient $sdkmcClient,           // NEW
    private GroupfolderClient $groupfolderClient, // NEW
    private DeckClient $deckClient,             // NEW
    private SpreedClient $spreedClient,         // NEW
    private CalendarClient $calendarClient,     // NEW
    private ISecureRandom $secureRandom,
    private ITimeFactory $timeFactory,
    private LoggerInterface $logger,
) {}
```

Then replace each TODO block. Line refs are against the current file; the marker
strings are stable anchors.

### R3 — `TODO[sdkmc.tag]` (forward ≈ L161–163, comp ≈ L164–170)

Replace the forward TODO (just before the R3 compensation push) with:

```php
$caseTag = $this->sdkmcClient->createCaseTag($hubsCaseId, $this->inflowEmail($rad));
if ($caseTag !== null) {
    $this->pekareMapper->record($hubsCaseId, 'case_tag', $caseTag);
}
```

Replace the R3 compensation body (the `noopCompensation('R3', …)` line) with:

```php
foreach ($this->pekareMapper->findByCaseAndTyp($hubsCaseId, 'case_tag') as $p) {
    $this->sdkmcClient->deleteCaseTag($hubsCaseId, $this->inflowEmail($rad), $p->getObjektId());
}
$this->pekareMapper->deleteByCaseAndTyp($hubsCaseId, 'case_tag');
```

### R4 — `TODO[groupfolders]` (forward ≈ L175–177, comp ≈ L178–184)

Forward:

```php
$folderId = $this->groupfolderClient->createArenderum(
    $hubsCaseId, (string)$typ->getAclProfil()
);
if ($folderId !== null) {
    $this->pekareMapper->record($hubsCaseId, 'groupfolder', (string)$folderId);
}
```

Compensation body:

```php
foreach ($this->pekareMapper->findByCaseAndTyp($hubsCaseId, 'groupfolder') as $p) {
    $this->groupfolderClient->removeFolder((int)$p->getObjektId());
}
$this->pekareMapper->deleteByCaseAndTyp($hubsCaseId, 'groupfolder');
```

### R5 — `TODO[deck]` (forward ≈ L188–190, comp ≈ L191–197)

Forward (2-step create → label):

```php
$card = $this->deckClient->createCard(
    (string)$arende->getEnhet(),
    (string)($rad['triageRef'] ?? $hubsCaseId),
    $fristDue?->format(\DateTimeInterface::ATOM)   // or null before R8 computes it
);
if ($card !== null) {
    $this->deckClient->addLabel($card['boardId'], $card['cardId'], 'case:' . $hubsCaseId);
    $this->pekareMapper->record($hubsCaseId, 'deck_card', (string)$card['cardId'], (string)$card['boardId']);
}
```

> Note: R5 currently sits **before** R8 (frist). If you want `due=frist` you can
> either move the R5 create after R8, or pass `null` and PUT the due in R8.

Compensation body:

```php
foreach ($this->pekareMapper->findByCaseAndTyp($hubsCaseId, 'deck_card') as $p) {
    $this->deckClient->deleteCard((int)$p->getRiktning(), (int)$p->getObjektId());
}
$this->pekareMapper->deleteByCaseAndTyp($hubsCaseId, 'deck_card');
```

(boardId is stashed in `riktning`; switch to a richer pekare if you prefer.)

### R6 — `TODO[spreed]` (forward ≈ L201–203, comp ≈ L204–210)

Forward:

```php
$talkToken = $this->spreedClient->createRoom(
    (string)($rad['triageRef'] ?? $hubsCaseId),
    $this->aclKretsUids($arende, $typ)   // participant uids for the ACL krets
);
if ($talkToken !== null) {
    $this->pekareMapper->record($hubsCaseId, 'talk_room', $talkToken);
}
```

Compensation body:

```php
foreach ($this->pekareMapper->findByCaseAndTyp($hubsCaseId, 'talk_room') as $p) {
    $this->spreedClient->deleteRoom($p->getObjektId());   // hard delete on rollback; archiveRoom() is the säkerhetsskydd path
}
$this->pekareMapper->deleteByCaseAndTyp($hubsCaseId, 'talk_room');
```

### R7 — `TODO[caldav]` (forward ≈ L214–216, comp ≈ L217–223)

Forward:

```php
$objUri = $this->calendarClient->prepareCaseCalendar($hubsCaseId);
if ($objUri !== null) {
    $this->pekareMapper->record($hubsCaseId, 'calendar', $objUri);
}
```

Compensation body:

```php
foreach ($this->pekareMapper->findByCaseAndTyp($hubsCaseId, 'calendar') as $p) {
    $this->calendarClient->removeCalendar($p->getObjektId());
}
$this->pekareMapper->deleteByCaseAndTyp($hubsCaseId, 'calendar');
```

### R9 — `TODO[sdkmc.tag]` (forward ≈ L238–241, comp ≈ L242–248)

Forward (tag the inflow message(s) + write the conversation pekare that
`ArendeMapper::findByConversationId()` resolves idempotency through):

```php
$messageIds = $this->inflowMessageIds($rad);          // int[]
$this->sdkmcClient->tagMessage($hubsCaseId, $messageIds);
if ($conversationId !== '') {
    $this->pekareMapper->record($hubsCaseId, 'conversation', $conversationId);
}
```

Compensation body:

```php
$this->sdkmcClient->untagMessage($hubsCaseId, $this->inflowMessageIds($rad));
$this->pekareMapper->deleteByCaseAndTyp($hubsCaseId, 'conversation');
```

### Small private helpers to add to `ArendeService`

```php
private function inflowEmail(array $rad): string {
    return (string)($rad['enhetEmail'] ?? $rad['email'] ?? $rad['funktionsadress'] ?? '');
}
private function inflowMessageIds(array $rad): array {
    $ids = $rad['messageIds'] ?? (isset($rad['messageId']) ? [$rad['messageId']] : []);
    return array_values(array_filter(array_map('intval', (array)$ids)));
}
private function aclKretsUids(Arende $arende, ArendeTyp $typ): array {
    // TODO: derive the mottagning krets uids from $arende->getEnhet() / acl_profil.
    return [];
}
```

## tilldela() — three-layer ACL coherence (not part of createCase)

`tilldela()` has `TODO[groupfolders]` / `TODO[sdkmc.tag]` / `TODO[deck]`. Reuse:
`GroupfolderClient::applyAcl($folderId, $aclProfil)` (atomic ACL rewrite),
`SdkmcClient` assignment tag, `DeckClient` move/label — resolving the folderId /
cardId through `PekareMapper::findByCaseAndTyp()`.

## ExApp-future

When sdkmc / this app become ExApps, only the body of each `ocsRequest()` /
`caldavRequest()` changes (in-process URL → remote HTTP + app auth). `isAvailable()`
and the public method surface stay identical, so the SAGA is untouched.
