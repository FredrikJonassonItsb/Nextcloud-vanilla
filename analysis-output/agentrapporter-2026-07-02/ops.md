# ops

## SUMMARY
Repot är ett arbets-monorepo runt hubs_start/hubs_arende där driftkedjan mot dev15 (NC 31.0.8.1) är helt tar-pipe-baserad och medvetet efemär: apps/sdkmc-tillägg, libresign och apk-paket försvinner vid container-recreate, och persistent deploy (image-bake via hubs-apps-plattformen + setup-apps.list + versions.yaml) saknas helt. docker-compose.yml och gov-portal/ är kvarlevor från januari-prototypen "GovPortal" och används inte i nuvarande spår. Repo-hygienen har tre konkreta problem: 6 redundanta zip-ar (~26 MB) är incheckade, två tomma trasiga kataloger ("hubs_arende;C", "hubs_start;C") ligger i roten, och nextcloud/-snapshoten (NC 32.0.1, 24 769 filer inkl. vendorade 3rdparty) driver packstorleken till 253 MiB. Versionsbilden: dev15/produkten kör NC 31, lokal dev/snapshot NC 32, spreed-itsl-forken är hårdpinnad min=max=31 och blockerar NC 32-uppgradering; repo-docs anger v32 EOL ~sep 2026 vilket gör NC 31 (äldre) till den mest akuta EOL-risken.

## DETAILS
## 1. docker-compose.yml, dev15-reset.sh, gov-portal

### docker-compose.yml (repo-roten, daterad 2026-01-26)
`C:/Users/fredrik.jonasson/Cursor/Nextcloud-vanilla/docker-compose.yml`
- mariadb:10.11 + image `nextcloud:latest` (rad 20) — **opinnad**, alltså INTE "lokal NC 32" per se; vilken version som helst dras vid pull. Port 8080.
- Rad 30: mountar `./gov-portal/nextcloud-app` → `custom_apps/govportal:ro` — filen hör till **GovPortal-prototypen från januari 2026**, inte hubs-spåret.
- Hårdkodade dev-lösenord (rad 10–13: `nextcloud_root_password`, `nextcloud_password`).
- **Klassning: (b/d)** — ingen evidens att stacken körs idag; hubs-arbetet sker mot dev15 och (enligt `hubs_start/HANDOVER.md` rad 146) via hubs-apps `make nc-up` (NC 32). Den "lokala NC 32" som nämns i docs är `nextcloud/`-källträdet (32.0.1 enligt `nextcloud/version.php` rad 2–3) + hubs-apps-devstacken, inte denna compose-fil.

### scripts/dev15-reset.sh
`C:/Users/fredrik.jonasson/Cursor/Nextcloud-vanilla/scripts/dev15-reset.sh` (96 rader)
- **Klassning: (a) verifierat fungerande.** Committad i 40217e15 + b118a01c, dokumenterad som körd i minnet hubs-ops (rad 34) och har inbyggt verifieringsblock (rad 78–93: förväntat arenden=0 … inbox>=2).
- Kvalitet: idempotent, `ON_ERROR_STOP` + transaktion (rad 44–45), `BatchMode=yes`, UUID-regex-skydd så setup-groupfolders inte raderas (rad 65–67), raderar ärenderum via `occ groupfolders:delete -f` (aldrig blind SQL, rad 70).
- Kända luckor (dokumenterade i filen rad 27–29): lämnar föräldralösa Talk-rum, Deck-kort, kalenderobjekt. Hårdkodad default-IP `ubuntu@10.43.51.62` (rad 37, överridbar via `HUBS_SSH`).

### gov-portal/
`C:/Users/fredrik.jonasson/Cursor/Nextcloud-vanilla/gov-portal/` — React 18 + TypeScript + Vite + Tailwind-prototyp ("GovPortal — Nextcloud Dashboard för Offentlig Sektor", README rad 1–33), byggd 2026-01-26/27. Innehåller `dist/`, `govportal-v1.0.0.zip`, `nextcloud-app/`, och `node_modules/` på disk (ej trackad).
- **Används EJ** i nuvarande spår. Enda levande referenser är historiska: `hubs_start/src/services/api.js` rad 115 ("gov-portal's client-side fan-out is explicitly avoided") + omnämnanden i DEMO.md/HANDOVER.md, samt att den analyserats som underlag (`analysis-output/code-gov-portal.json`). Föregångare till hubs_start → arkiv-/städkandidat.

## 2. Repo-strukturen

### hubs-code/ — referens-checkouts, inte git-forkar
Uppackade GitLab-arkiv (main-branch-zip, ingen `.git` någonstans; zip-kommentaren innehåller commit-SHA, t.ex. sdkmc `cf58173e`). Innehåll:
- `calendar/calendar-main`, `mail/mail-main`: **quilt-overlay-forkar** (kataloger `upstream/` + `overlay/` + `patches/`) — ITSL:s modell "upstream Nextcloud source plus ITSL changes" (hubs-apps README rad 3–5).
- `sdkmc/sdkmc-main` (v2.2.21, NC 30–32), `securemail/securemail-main`: egna appar.
- `spreed-itsl/spreed-itsl-main`: fork av spreed **v21.1.7, NC min=max=31** (appinfo/info.xml).
- `hubs-apps/hubs-apps-main`: **byggplattformen** — Makefile, `setup-apps.list` (klonar sdkmc/mail/calendar från gitlab.itsl.se), docker/, ci/; "packages release tarballs that hubs-php bundles" (README rad 4–5, docs/architecture.md rad 315).
- OBS en aktiv ändring ligger HÄR: `hubs-code/mail/mail-main/overlay/src/itsl/utils/initComposerDeepLink.js` (ny modul, **byggd men ej deployad/GUI-verifierad** — HANDOVER-FORTSATTNING.md rad 201). hubs-code är alltså inte rent read-only.
- Stale-varning: referens-sdkmc är **2.2.21** medan dev15 kör **2.2.25** (hubs-ops rad 24) — checkouten släpar.

### Zip-filerna i roten — städbehov: JA
6 st (`calendar-main (1).zip`, `hubs-apps-main.zip`, `mail-main.zip`, `sdkmc-main (4).zip`, `securemail-main (3).zip`, `spreed-itsl-main (2).zip` à 24 MB), totalt ~26 MB, daterade 2026-06-13, **incheckade i git** (commit 966b5d84 "project snapshot"). Redundanta — hubs-code/ är deras uppackade innehåll. `git rm` städar arbetsträdet, men packen (253 MiB) krymper inte utan history-rewrite.

### "hubs_arende;C" och "hubs_start;C" — trasiga tomma kataloger
Båda är **helt tomma**, skapade 2026-06-18 16:36, otrackade, noll git-historik. Sannolikt artefakter av ett felciterat kommando där en Windows-sökväg med semikolon splittades (t.ex. tar/kopiering `hubs_arende;C:\...`). **Säkra att radera.**

### nextcloud/
Vanilla **NC 32.0.1**-serverkälla (`nextcloud/version.php`). 24 769 trackade filer, inkl. `3rdparty/` (4 508 vendorade composer-filer) och app-vendor (t.ex. `apps/suspicious_login/vendor` 1 685 filer). Detta är huvuddrivaren av repo-storleken.

### Storlek/hygien i siffror
- `git count-objects`: **pack 253.33 MiB**, 27 365 trackade filer.
- `node_modules`: **0** trackade (gitignore rad 2 fungerar; gov-portal/node_modules ligger bara på disk).
- `vendor/`: 1 795 trackade filer men **alla under nextcloud/** (upstreams egna) — inget eget vendor incheckat.
- hubs_start (220 filer) och hubs_arende (90 filer) ÄR trackade — notera att HANDOVER-FORTSATTNING.md rad 100 ("hubs_arende/hubs_start är untracked") är **inaktuell**; de committades fr.o.m. 966b5d84.

## 3. Deploy-kedjan (HANDOVER-FORTSATTNING.md §3, rad 64–73)

### Nuläge — verifierat fungerande men efemärt
- tar-pipe över ssh: hubs_start (`tar czf - js appinfo/info.xml` → `custom_apps/hubs_start` + `occ config:app:set installed_version` + `occ upgrade`), sdkmc-tillägg → `apps/sdkmc`, hubs_arende → `custom_apps/hubs_arende`. **Evidens:** hubs_start 1.2.15 + hubs_arende 0.7.5 deployade, jest 88 / phpunit 72 / smoke grön (HANDOVER-FORTSATTNING rad 185).
- **Dokumenterade risker (delvis observerade incidenter):**
  1. EFEMÄRT vid container-recreate/`itsl deploy` (rad 40–42): libresign-appen, `apk add openjdk21-jre-headless poppler-utils`, samtliga `apps/sdkmc`-tillägg.
  2. `docker restart hubs-php` FÖRBJUDET (hubs-ops rad 33, **observerad incident 2026-06-20**): NC-entrypointens apps/-omsynk rensar apps/sdkmc-tilläggen (InflodeFeedService, SummaryService, NoteToSelf, ArendeEnrichment + routes.php-blocket). custom_apps + DB överlever. opcache `validate_timestamps` PÅ → restart behövs aldrig för cache-bust.
  3. Recovery-recept finns (re-deploy backend-additions + komplett routes.php, verifiera OCS 401 ej 404).

### Vad KRÄVS för persistent deploy — saknas helt (d)
Enligt hubs-ops-minnet (itsl-CLI: versions.yaml → `itsl pull` → `itsl config` → `itsl deploy`; `itsl updateApp` från GitLab CI-artefakter) + hubs-apps-plattformen:
1. **sdkmc-tilläggen upstreamas** till gitlab.itsl.se/itsl/sdkmc — `hubs_start/backend-additions/MANIFEST.md` är redan skriven för detta (UPSTREAM-KANDIDAT-markering, `HUBS-START-ADD`-block) → in i release-tarball som hubs-php bundlar.
2. **hubs_start + hubs_arende registreras** i `hubs-apps/setup-apps.list` (en rad, INSTALL.md rad 49–54) + egna GitLab-repon → CI-artefakt → `itsl updateApp`.
3. **libresign + apk-paket (java, poppler) bakas in i hubs-php-imagen** (Dockerfile-lager) — `occ app:install` funkar ej (appstore WAF-blockad från dev15), apk-lagret är container-efemärt.
4. **Versionspinning** i `/opt/itsl-sdk/share/versions.yaml`.
Inget av 1–4 är påbörjat; tar-pipen är enda deployvägen idag.

## 4. Versionskonflikter — utredd bild

| Komponent | Version/krav | Källa |
|---|---|---|
| dev15 (produkten) | **NC 31.0.8.1** (verifierat via occ 2026-06-16) | hubs-ops rad 24; HANDOVER-FORTSATTNING rad 34 |
| nextcloud/-snapshot + lokal hubs-apps-dev | **NC 32.0.1** | nextcloud/version.php; HANDOVER.md rad 146 |
| hubs_start 1.2.15 | NC 30–32, PHP ≥8.1 | hubs_start/appinfo/info.xml |
| hubs_arende 0.7.5 | NC 30–32 | hubs_arende/appinfo/info.xml |
| sdkmc (referens 2.2.21; dev15 kör 2.2.25) | NC 30–32 | hubs-code/sdkmc/.../info.xml |
| spreed-itsl 21.1.7 | **NC min=max=31 (hård pinning)** | hubs-code/spreed-itsl/.../appinfo/info.xml |
| libresign | NC 31→11.6.0 (dev15, korrekt), NC 32→12.4.5 | SIGNING-INERA.md rad 71; HANDOVER-FORTSATTNING rad 37 |

- Skenkonflikten: INSTALL.md rad 23–24 säger "spreed fork requires NC 31 while the platform runs NC 32" — men det **verifierade** (occ på dev15) är att riktiga Hubs kör NC 31; "plattformen NC 32" avser den LOKALA dev-stacken. hubs-ops rad 24 punkt 1 korrigerar uttryckligen: "spreed-itsl v32-rebase"-antagandet ska läsas som "håll jämna steg med NC-basen (produkten är på 31)".
- Egna apparna (30–32) är kompatibla med båda världar; **flaskhalsen för NC 32-uppgradering är spreed-itsl-forken** (min=max=31).
- EOL: HANDOVER.md rad 158 anger "v32 EOL ~sep 2026" (repo-uppgift, ej externt verifierad av mig). Eftersom NC 31 är äldre ligger dess EOL tidigare — **dev15:s NC 31.0.8.1 är den akuta EOL-risken**, och uppgraderingen kräver koordinerat fönster med spreed-rebase.

## 5. Git-status

- Untracked: `analysis-output/VARDE-OPERATIVA-VERKSAMHETSLAGRET.md` (marknads-/beslutsunderlag, uttryckligen omnämnt som untracked i HANDOVER-FORTSATTNING rad 203 — **bör committas**, unik text) och `analysis-output/rapport/` (Hubs-operativt-verksamhetslager.html 79 KB, .pdf 2.7 MB, render-pdf.js, assets/). Rekommendation: committa .md + render-pdf.js + HTML; PDF:en är regenererbar binär (2.7 MB) — gitignorera eller committa medvetet som leverabel.
- Resten av analysis-output (65 filer, code-*/market-*/concept-*.json) är redan trackat.
- Gränsregel i hubs-ops rad 36: aldrig git push utan uttrycklig instruktion.

## DEMO_OR_STUB
- docker-compose.yml rad 10-13: hårdkodade dev-lösenord (nextcloud_root_password/nextcloud_password) — ingen gate, men stacken är sannolikt död (gov-portal-era, jan 2026)
- docker-compose.yml rad 20: image nextcloud:latest opinnad — ingen versions-gate alls
- docker-compose.yml rad 38-39: NEXTCLOUD_DEFAULT_APP=govportal utkommenterad (demo-toggle för prototypen)
- sdkmc demo-inflöde: gateas via occ config:app:set sdkmc hubs_start_inflode_demo (0/1); dokumenterat AV på dev15 (HANDOVER-FORTSATTNING.md rad 57)
- hubs_start 'Nytta hittills'-widget: indikativa värden, riktig stats-endpoint saknas (HANDOVER.md rad 156)
- sdkmc summary-endpoint hade hårdkodade 0-räknare för virtuella brevlådor enligt INSTALL.md rad 38-39 ('currently hard-coded 0') — status för fixen framgår ej av docs, verifiera mot deployad SummaryService
- gov-portal/: hel prototyp-app (React) = demo-föregångare till hubs_start, används ej; mountas bara av den döda docker-compose.yml

## VERIFIED_WORKING
- scripts/dev15-reset.sh — körd mot dev15, inbyggt verifieringsblock (rad 78-93), committad (40217e15, b118a01c), dokumenterad som verifierad i minnet hubs-ops rad 34
- tar-pipe-deployen (HANDOVER-FORTSATTNING.md rad 71-73) — hubs_start 1.2.15 + hubs_arende 0.7.5 deployade på dev15 med jest 88 / phpunit 72 / occ hubs_arende:smoke grön (rad 185)
- sdkmc backend-additions deployade + verifierade på dev15: varje OCS-route svarar 401 oautentiserat, occ status frisk (backend-additions/MANIFEST.md rad 14-17)
- dev15 kör NC 31.0.8.1 — verifierat via occ 2026-06-16 (hubs-ops rad 24)
- docker-restart-incidenten är observerad (2026-06-20): apps/sdkmc-tillägg rensades av entrypointens apps-omsynk; recovery-receptet testat (hubs-ops rad 33)
- node_modules ej incheckade (git ls-files: 0 träffar); .gitignore rad 2-3 täcker node_modules/ + vendor/

## RISKS
- Hela hubs-leveransen på dev15 är efemär: apps/sdkmc-tillägg + libresign + apk-paket (java, poppler) försvinner vid container-recreate/itsl deploy/docker restart hubs-php — en oavsiktlig restart raderar backend-API:t som hubs_start kräver (custom_apps + DB överlever dock)
- Persistent deploy saknas helt: ingen image-bake, ingen setup-apps.list-registrering, ingen CI-artefakt, ingen versions.yaml-pinning — allt bygger på manuell tar-pipe från en Windows-arbetsstation
- spreed-itsl-forken är hårdpinnad NC min=max=31 → blockerar NC 32-uppgradering; NC 31 (dev15) har tidigare EOL än NC 32 (~sep 2026 enligt HANDOVER.md rad 158) → uppgraderingsfönster måste koordineras med spreed-rebase
- Referens-checkouten hubs-code/sdkmc (2.2.21) släpar efter dev15 (2.2.25) — patchar skrivna mot stale kod kan divergera från det som faktiskt kör
- Repo 253 MiB pack / 27k filer pga incheckad NC 32.0.1-källa inkl. vendorade 3rdparty + 26 MB redundanta zip-ar; git rm räcker inte för att krympa (history-rewrite krävs)
- Dokumentationsdrift: INSTALL.md rad 23-24 ('platform runs NC 32') motsäger verifierat NC 31 på dev15; HANDOVER-FORTSATTNING rad 100 ('hubs_arende/hubs_start untracked') är inaktuell — risk att nästa person agerar på fel premiss
- dev15-reset.sh lämnar föräldralösa Talk-rum/Deck-kort/kalenderobjekt (dokumenterat rad 27-29) — långsamt ackumulerande skräp i testmiljön
- initComposerDeepLink.js (mail-overlay) är byggd men EJ deployad/GUI-verifierad och ligger i hubs-code/ (referensytan) — lätt att tappa bort vid städning av hubs-code

## NEXT_STEPS
- Radera de tomma trasiga katalogerna 'hubs_arende;C' och 'hubs_start;C' (otrackade, noll innehåll, noll historik)
- git rm de 6 zip-arna i roten (~26 MB, dubbletter av hubs-code/) + lägg *.zip i .gitignore; ta beslut om history-rewrite (filter-repo) om packstorleken ska ner
- Committa analysis-output/VARDE-OPERATIVA-VERKSAMHETSLAGRET.md + rapport/render-pdf.js + HTML; gitignorera eller medvetet committa PDF:en (2.7 MB, regenererbar)
- Starta persistensspåret: registrera hubs_start + hubs_arende i hubs-apps/setup-apps.list + GitLab-repon, upstreama sdkmc-tilläggen per MANIFEST.md-konventionen, baka libresign + openjdk21/poppler i hubs-php-imagen, pinna i /opt/itsl-sdk/share/versions.yaml
- Red ut spreed-itsl-rebasen (NC 31 → följa NC-basen) innan NC-uppgraderingsfönstret; bekräfta EOL-datum för NC 31/32 externt i stället för repo-uppgiften '~sep 2026'
- Uppdatera stale docs: INSTALL.md versionsnot (plattform=NC 31 i produkt, 32 lokalt) + HANDOVER-FORTSATTNING rad 100 (apparna är numera trackade)
- Synka hubs-code/sdkmc till 2.2.25 (dev15:s version) eller markera checkouten med datum/SHA så patchbasen är spårbar
- Besluta gov-portal/ + docker-compose.yml: arkivera (eget arkiv-repo/tag) eller radera — de hör till januari-prototypen och används inte