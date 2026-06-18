<!--
SPDX-FileCopyrightText: ITSL <info@itsl.se>
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# hubs_arende — Provisionering

Repeterbar, **idempotent** provisioneringsrutin som efter en dev15-reset
återställer ALLT vi lägger till ovanpå en ren Hubs/Nextcloud-instans
(NC 31.0.8 på dev15).

Köra om hur många gånger som helst = säkert. Varje steg är idempotent och
loggar. Inga destruktiva steg körs by default.

## Filer

| Fil | Vad |
|---|---|
| [`bootstrap.sh`](./bootstrap.sh) | Idempotent shell-skript. Stegen (a)–(i). Körs via SSH mot dev15 eller på bygg-host. |
| [`manifest.yaml`](./manifest.yaml) | "Överlever-reset"-manifest: varje artefakt (app, version, källa, install-metod, överlever-itsl-deploy?, restore-steg, destruktiv?). Källa-till-sanning. |
| [`reset-restore.md`](./reset-restore.md) | Hur dev15 återställs + hur `bootstrap.sh` kör efter reset + hur det mergas in i `itsl deploy`. |
| [`README.md`](./README.md) | Denna översikt. |

## Vad provisioneras (stegen i bootstrap.sh)

| Steg | Vad | Destruktivt? |
|---|---|---|
| (a) | Snapshot konfig **före** (rollback-referens) | nej |
| (b) | Slå **på** appstore tillfälligt (eller sidoladdningsläge) | nej |
| (c) | `occ app:enable contacts` (inbyggd, disabled på dev15) | nej |
| (d) | Installera saknade appar: **deck, tasks, forms, notes, libresign** — signerade tarballar → `custom_apps` + enable, med NC31-kompat-vakt | nej |
| (e) | Installera **app_api** (AppAPI) + registrera **Docker deploy-daemon** mot `/var/run/docker.sock` | nej |
| (f) | Provisionera hubs_arende-DB (default: app-migration via occ; option: separat pg-role/DB) | **ja** endast om `PROVISION_DEDICATED_DB=1` |
| (g) | Deploya hubs_arende-koden → `custom_apps` + `occ app:enable` + `occ upgrade` | nej |
| (h) | `occ maintenance:repair` + verifiering (`occ app:list`, `occ status`, tabellkoll) | nej |
| (i) | Slå **av** appstore igen (återställ policy till värdet före) | nej |

> Det **enda** destruktiva läget är `PROVISION_DEDICATED_DB=1` (ExApp-fasen),
> som är **avstängt by default** och dessutom har en idempotent-vakt som inte
> skriver över befintlig DB. Se markeringen `###### DESTRUKTIVT ######` i
> skriptet.

## Snabbstart

```bash
# 1. Synka provision/ + appkod till dev15 (eller kör på bygg-host).
scp -r hubs_arende ubuntu@10.43.51.62:/home/ubuntu/hubs_arende

# 2. Torrkörning först — visar varje kommando, ändrar INGET.
ssh ubuntu@10.43.51.62 'cd /home/ubuntu/hubs_arende/provision && \
  APP_SRC=/home/ubuntu/hubs_arende bash bootstrap.sh --dry-run'

# 3. Skarp körning.
ssh ubuntu@10.43.51.62 'cd /home/ubuntu/hubs_arende/provision && \
  APP_SRC=/home/ubuntu/hubs_arende bash bootstrap.sh'
```

## Flaggor & miljövariabler

```bash
bash bootstrap.sh --dry-run          # visa, ändra inget
bash bootstrap.sh --only c,d,g       # kör bara valda steg
bash bootstrap.sh --rollback-appstore # nödåterställ appstore-policy

APP_SRC=/path/to/hubs_arende         # var appkoden ligger (default: ../ rel. skript)
SIDELOAD_ONLY=1                      # rör inte appstore; installera bara via tarball
INSTALL_LIBRESIGN=0                  # hoppa libresign (host utan utgående nät)
PROVISION_DEDICATED_DB=1             # [DESTRUKTIVT, ExApp-fas] separat pg-role/DB
DEDICATED_DB_PASSWORD=…              # krävs om PROVISION_DEDICATED_DB=1 (ur vault!)
HUBS_NET=…                           # docker-nät för deploy-daemon (auto annars)
```

## Förutsättningar

- Körs på dev15 eller en bygg-host där hubs-stacken är igång
  (`sudo docker inspect hubs-php` måste lyckas).
- `sudo` + Docker-åtkomst. occ-mönster:
  `sudo docker exec -u www-data hubs-php php /var/www/html/occ <cmd>`.
- Utgående nät för att hämta de signerade tarballarna (eller förcacha dem i
  `/opt/project_data/hubs_arende/tarballs/`).
- För deploy-daemonen (steg e): `hubs-php`/AppAPI måste ha åtkomst till
  `/var/run/docker.sock` (grupp `docker`).

## App-id / kontrakt (kort)

- **App-id:** `hubs_arende` · **namespace:** `OCA\HubsArende` · **vendor:** ITSL
  · **licens:** AGPL-3.0-or-later.
- **NC-kompat:** 30–32 (dev15 kör NC 31.0.8) · **PHP 8.1+**.
- In-process NC-app **nu**, designad ExApp-rent → paketeras som ExApp senare
  (därför installeras AppAPI + deploy-daemon redan nu).
- DB-tabeller (skapas av appens migrationer): `hubs_arende_case`,
  `hubs_arende_typ`, `hubs_arende_flagga`, `hubs_arende_pekare`.
  **Invariant:** `commit_destination NOT NULL`.

## Verifiera efter körning

```bash
OCC="sudo docker exec -u www-data hubs-php php /var/www/html/occ"
$OCC app:list | grep -E 'hubs_arende|deck|tasks|forms|notes|libresign|app_api|contacts'
$OCC status
$OCC app_api:daemon:list
```

## Loggar & rollback

- Logg: `/opt/project_data/hubs_arende/provision.log`.
- Rollback-referens (skapas i steg a):
  `/opt/project_data/hubs_arende/rollback/` (config + app:list + appstore-policy
  före körningen).
- Full reset/restore-rutin + mergeplan in i `itsl deploy`:
  [`reset-restore.md`](./reset-restore.md).

## Versioner

App-tarball-versionerna är pinnade i [`manifest.yaml`](./manifest.yaml) och
speglade i `bootstrap.sh` (arrayen `MISSING_APPS` + `LIBRESIGN_SPEC` +
`APPAPI_SPEC`). Versionerna är NC31-säkra utgångsvärden; skriptet **verifierar
varje tarballs `info.xml` `<nextcloud max-version>` ≥ 31 innan enable** och
stoppar med tydligt fel vid inkompatibilitet (ingen tyst uppgradering). Vid
NC-basbyte: uppdatera versionerna på **båda** ställena.
