# tests

## SUMMARY
Båda testsviterna kördes lokalt 2026-07-02 och är helt gröna: jest 11/11 sviter, 88/88 tester på 32.3 s; phpunit 72/72 tester (237 assertions) på 31.5 s via composer:2-imagen (PHPUnit 10.5.63, PHP 8.5.7). Detta matchar dokumentationens grindar i hubs_start/docs/HANDOVER-FORTSATTNING.md:185 ("jest 88, phpunit 72") exakt — noll avvikelse. Build-artefakterna i hubs_start/js/ finns (main.js 2.18 MB, mtime 2026-06-21 14:54) och är konsistenta med version 1.2.15 i info.xml, som sattes i commit 9e4f0645 (2026-06-21 14:55, dvs bygget gjordes minuten före committen). Inga källfiler i hubs_start/src är nyare än bygget, så artefakterna är inte stale.

## DETAILS
## 1. Jest (hubs_start)

Kommando: `npm test` i `C:/Users/fredrik.jonasson/Cursor/Nextcloud-vanilla/hubs_start`.

Faktiskt resultat:
```
Test Suites: 11 passed, 11 total
Tests:       88 passed, 88 total
Snapshots:   0 total
Time:        32.287 s
```

Noterbart brus (INTE fel): flera `console.error`-utskrifter under körningen — `[Vue warn]: Invalid prop type: "NcStub" is not a constructor` i `<CommitGrind>`-testerna. Detta är den kända ncvue-stub-mockstrategin (omnämnd i `hubs_start/docs/HANDOVER-FORTSATTNING.md:198`, "jest 49→88"); alla tester passerar trots varningarna.

## 2. PHPUnit (hubs_arende)

Docker kör lokalt (server 28.5.1). Kommando enligt receptet: `MSYS_NO_PATHCONV=1 docker run --rm -v "/c/Users/fredrik.jonasson/Cursor/Nextcloud-vanilla/hubs_arende:/app" -w /app composer:2 php vendor/bin/phpunit -c phpunit.xml`.

Faktiskt resultat:
```
PHPUnit 10.5.63, Runtime: PHP 8.5.7
OK (72 tests, 237 assertions)
Time: 00:31.487, Memory: 12.00 MB
```

## 3. Build-artefakter (hubs_start/js/)

Innehåll i `C:/Users/fredrik.jonasson/Cursor/Nextcloud-vanilla/hubs_start/js/`:
- `hubs_start-main.js` — 2 175 278 byte, mtime **2026-06-21 14:54**
- `hubs_start-main.js.map` — 6 332 171 byte, samma mtime
- `hubs_start-vendors-...FilePicker...js` — 2 334 119 byte, 2026-06-21 14:54
- `hubs_start-vendors-node_modules_rehype-highlight_index_js.js` — 175 465 byte, 2026-06-21 14:54
- småchunkar (NcColorPicker, dialogs-index, svg-chunk) samma datum; två `.LICENSE.txt` från 13 juni.

Versionskonsistens: `hubs_start/appinfo/info.xml` = **1.2.15** i både arbetsträd och senaste commit `9e4f0645` (2026-06-21 14:55:01 +0200). Bygget (14:54) gjordes en minut före committen — normalt bygg-sen-committa-mönster. `find hubs_start/src -newer hubs_start-main.js` gav noll träffar, dvs **inga källfiler är nyare än bygget**; artefakterna motsvarar 1.2.15.

OBS: `hubs_start/js/` är gitignorerad (`hubs_start/.gitignore:2` = `/js/`) — artefakterna finns bara lokalt, deploy förutsätter att bygget körs/packas separat.

`hubs_arende/appinfo/info.xml` = 0.7.5; senaste commit som rör `hubs_arende/` är `0fb67a1c` (2026-06-19 11:42).

## 4. Jämförelse mot dokumentationens grindar

`hubs_start/docs/HANDOVER-FORTSATTNING.md:185`: "Grindar: jest 88, phpunit 72, bygg grönt, smoke OK". Faktiskt uppmätt: jest 88 pass, phpunit 72 pass. **Exakt match — inga avvikelser.** Även delpåståendena "phpunit 68→72" (rad 153) och "jest 49→88" (rad 198) är konsistenta med de uppmätta sluttalen.

## DEMO_OR_STUB
- hubs_start test-setup: NcStub-mockar för @nextcloud/vue-komponenter (syns som Vue-warnings i jest-output för CommitGrind-testerna) — ren testinfrastruktur, används endast under jest, ingen gating i produktionskod. Ingen demodata/hårdkodning hittad inom denna uppgifts scope (testkörning + artefaktkontroll; ingen fullständig demodata-scan av källkoden gjordes).

## VERIFIED_WORKING
- Jest-sviten i hubs_start: 11/11 sviter, 88/88 tester PASS på 32.287 s (körd lokalt 2026-07-02 med npm test)
- PHPUnit-sviten i hubs_arende: OK 72 tester / 237 assertions på 31.487 s (körd lokalt 2026-07-02 via docker composer:2, PHPUnit 10.5.63, PHP 8.5.7)
- Dokumentationens grindar (jest 88, phpunit 72 i hubs_start/docs/HANDOVER-FORTSATTNING.md:185) stämmer exakt mot faktisk körning
- Webpack-artefakter finns och är färska: hubs_start/js/hubs_start-main.js (2.18 MB, 2026-06-21 14:54) matchar version 1.2.15 i hubs_start/appinfo/info.xml (satt i commit 9e4f0645 samma minut); inga src-filer nyare än bygget

## RISKS
- Jest-output är brusig av console.error/[Vue warn] (NcStub not a constructor) — passerar idag men maskerar potentiellt riktiga Vue-fel i framtida testkörningar
- hubs_start/js/ är gitignorerad — de gröna artefakterna finns bara lokalt; det som ligger på dev15 kan inte verifieras härifrån (ingen ssh tillåten i denna uppgift)
- Testresultaten säger inget om GUI/E2E — kända kvarvarande frontend-buggar från livetestet på dev15 täcks inte av dessa sviter

## NEXT_STEPS
- Överväg att tysta/fail-a på NcStub-prop-varningarna i jest-setupen så att console.error-brus inte döljer regressioner
- Vid nästa deploy: verifiera att artefakten som packas till dev15 verkligen är byggd från 1.2.15-trädet (js/ är inte versionerad)
- Om synteser behöver demodata-inventering: kör en riktad scan av hubs_start/src och hubs_arende/lib efter hårdkodade demovärden — ingick inte i denna körning