<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# hubs_arende — testsvit

PHPUnit-svit för ärende-motorn (`OCA\HubsArende`). Varje OCP-kollaboratör mockas
(`PHPUnit\Framework\TestCase::createMock`), så inga riktiga DB-/NC-tjänster krävs.

## Innehåll

| Fil | Täcker |
| --- | --- |
| `Unit/Service/ArendeServiceTest.php` | `createCase` happy-path (commit_destination satt), okänd ärendetyp → `InvalidArgumentException`, commit_destination NOT NULL-invariant, idempotens på `conversationId`, säkerhetsskydds-grind → `AvvisadException` utan INSERT, commit med verifierat kvitto → `provenanceState=registrerad`. |
| `Unit/Service/SakerhetsskyddGrindTest.php` | Fail-closed: tom rad, säkerhetsskydds-nyckelord, `sdkFields.sakerhetsklass=hemlig`, visselblåsning → avvisad; ren orosanmälan → `avvisad=false`. |
| `Unit/Service/ArendeTypRegistryTest.php` | `seedDefaults` idempotent, alla 8 typer har `commit_destination`, kat 6 `pre_saga_hook=diariefor_direkt`, kat 8 `post_commit_hook` satt. |
| `Unit/Integration/FacksystemCommitStubTest.php` | Commit → verifierat kvitto med `dnr` + `gallrasDatum`; retention startar enbart på den verifierade callbacken (GAP-007). |

## Köra lokalt

Kanoniska vägen (samma som CI kör, från app-roten `hubs_arende/`):

```sh
composer install
vendor/bin/phpunit
```

`composer install` drar in `phpunit/phpunit` + `nextcloud/ocp` (^31) i `vendor/`,
och `phpunit` utan flagga plockar upp roten-`phpunit.xml` (bootstrap=`tests/bootstrap.php`,
testsuite=`tests/Unit`). Detta är exakt vad GitHub Actions-workflowen
(`.github/workflows/ci.yml`) kör på PHP 8.1/8.2/8.3.

`bootstrap.php` väljer miljö automatiskt:

1. **I en Nextcloud-checkout** (t.ex. dev15) laddas NC:s egen test-bootstrap och
   server-class-loadern (samma `OCP\*`/`OCA\HubsArende\*` som i produktion).
   Sätt `NEXTCLOUD_TEST_BOOTSTRAP` för ovanliga layouter.
2. **Fristående** (CI/laptop) registreras en PSR-4-autoloader för
   `OCA\HubsArende\ → ../lib` och för `OCP\`/`NCU\` mot `nextcloud/ocp`-paketet
   (som levererar källan men saknar egen Composer-autoload). Roten-`composer.json`
   installerar dessa dev-deps i `hubs_arende/vendor/`.

> Den äldre, tests-scoped layouten (`tests/composer.json` + `tests/phpunit.xml`,
> kört med `composer install --working-dir=tests`) finns kvar och fungerar än,
> men CI och den dokumenterade lokala vägen använder roten-uppsättningen ovan.

Verifierad grön på PHP 8.3–8.5, PHPUnit 10.5: **25 tester, 106 assertions, OK**
(nu plus deny-vägs-testet i `ArendeServiceAuthzTest`).
