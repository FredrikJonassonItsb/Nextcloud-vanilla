# Deck-patchar för ITSL Open Stack

Nextcloud-appar patchas ibland lokalt för ITSL-behov. **Varje patch här är
idempotent och måste köras om efter varje Deck-uppdatering** (en app-uppgradering
skriver över `custom_apps/deck`). Kör:

```bash
provision/../patches/apply-deck-patches.sh          # från host över ssh
# eller på servern direkt:
bash apply-deck-patches.sh
```

Skriptet är säkert att köra hur många gånger som helst — redan patchade filer hoppas över.

---

## Patch 1 — `deck-numeric-uid-assignuser` (KRITISK för skarp itsl.hubs.se)

**Fil:** `custom_apps/deck/lib/Service/AssignmentService.php` (verifierat mot Deck 1.15.9).

**Bugg:** `assignUser()` avvisar användare vars uid är **numeriskt** (t.ex. BankID-personnummer
`197411040293`) med `"The user is not part of the board"` — trots att användaren är board-medlem.

**Orsak:** raden

```php
$boardUsers = array_keys($this->permissionService->findUsers($boardId, true));
...
if (!in_array($userId, $boardUsers, true) && count($groups) !== 1) {
```

`findUsers()` returnerar en array **nyckelad på uid**. PHP tvingar automatiskt numeriska
sträng-nycklar till **integers**, så `array_keys()` ger `197411040293` som en `int`. Den
**strikta** (`===`) `in_array`-jämförelsen mot uid-**strängen** `"197411040293"` matchar då
aldrig → 400.

**Konsekvens för ITSL:** skarpa itsl.hubs.se använder BankID-personnummer som uid för ALLA
användare → assignee/tilldelning på Deck-kort skulle vara trasig för alla. (På dev15 syns det
bara för Fredriks `197411040293`; teamets `rebecca.dumky` m.fl. är alfabetiska och opåverkade.)

**Fix (enrad, minimal footprint):** casta båda sidor till sträng före jämförelsen:

```php
if (!in_array((string)$userId, array_map('strval', $boardUsers), true) && count($groups) !== 1) {
```

**Bör även rapporteras uppström** till Nextcloud Deck — det är en genuin bugg i deras kod, inte
ITSL-specifik. Tills en officiell fix finns behåller vi den lokala patchen.

En `.itsl-bak`-kopia av originalfilen läggs bredvid vid första körningen.
