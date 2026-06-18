<!--
SPDX-FileCopyrightText: ITSL <info@itsl.se>
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# hubs_arende — Reset & Restore-rutin

Hur dev15 återställs (reset), hur `bootstrap.sh` återställer allt vi lade till
(restore), och hur detta i slutändan mergas in i den gemensamma
**`itsl deploy`**-rutinen.

> Begreppen: **dev15** = referens-/byggmiljö (10.43.51.62, NC 31.0.8.1).
> **occ-mönster:** `sudo docker exec -u www-data hubs-php php /var/www/html/occ <cmd>`.
> Det som **överlever** en reset står i [`manifest.yaml`](./manifest.yaml) (`survives_summary`).

---

## 0. Mentala modellen: vad nollas, vad bevaras

Vid en reset / `itsl deploy` på en instans:

| Lager | Var det bor | Överlever reset? |
|---|---|---|
| NC-DB (inkl. `oc_hubs_arende_*`-tabeller) | `hubs-postgres` → `/opt/project_data` | **JA** (project_data bevaras) |
| Systemkonfig (`config:system`) | `config/` i project_data | Delvis — `itsl config` **om-kompilerar ur .env** |
| `custom_apps/`-kod (deck, app_api, hubs_arende, …) | volym i `hubs-php` | **NEJ** vid hård reset / volym-återskapning |
| Deploy-daemon-registrering (AppAPI) | NC-DB | JA om DB bevaras, annars NEJ |
| `appstoreenabled`-policy | systemkonfig | Återgår till .env-default (avsiktligt **av**) |

**Slutsats:** efter en reset måste vi köra om allt under
`survives_summary.does_not_survive` i manifestet. Det är exakt vad
`bootstrap.sh` gör — idempotent, så det är ofarligt att köra även när inget
nollats.

---

## 1. Reset av dev15

Tre nivåer, från mjuk till hård. **Markera destruktivitet noga.**

### 1a. Mjuk reset — om-deploya (bevarar data) — REKOMMENDERAD
Bevarar `/opt/project_data` (DB + config). Bara stacken om-deployas.

```bash
ssh ubuntu@10.43.51.62
sudo itsl status          # läs nuläge (= docker compose ps)
sudo itsl deploy          # om-deployar; frågar ja/nej om redan deployad
```
- **Icke-destruktivt mot DB.** `oc_hubs_arende_*`-tabeller och allt i
  project_data finns kvar.
- `custom_apps`-koden (våra appar) **kan** försvinna beroende på hur bilden/
  volymen hanteras → kör `bootstrap.sh` efteråt (idempotent, no-op om allt finns).

### 1b. App-lager-reset — bara våra appar bort
Om bara hubs_arende-stacken ska nollas (utan att röra DB):

```bash
# ###### DESTRUKTIVT mot app-KOD (ej data) ######
for app in hubs_arende deck tasks forms notes libresign app_api; do
  sudo docker exec -u www-data hubs-php php /var/www/html/occ app:disable "$app" || true
done
# (app-data/tabeller ligger kvar i DB; koden tas bort vid nästa deploy/cleanup)
sudo itsl cleanup
```

### 1c. Hård reset — nollställ instansdata — ###### DESTRUKTIVT ######
**Raderar DB och all instansdata.** Endast på en ren byggmaskin, ALDRIG på en
instans med riktig data.

```bash
# ###### DESTRUKTIVT — RADERAR ALLT INKL. DB ######
sudo itsl stop
# ta bort instansdata (kräver explicit bekräftelse — gör INTE av misstag):
sudo rm -rf /opt/project_data/initiator        # aktiv deploy-markör
# (full nollning av DB-volym = docker volume rm för hubs-postgres-volymen)
sudo itsl deploy                                 # ren ominstallation ur .env
```
Efter 1c är även `oc_hubs_arende_*`-tabellerna borta → `bootstrap.sh` kör
migrationerna på nytt (idempotent).

---

## 2. Restore — kör bootstrap.sh efter reset

`bootstrap.sh` är idempotent: kör det **alltid** efter en reset; det återställer
bara det som saknas.

### 2a. Från bygg-host över SSH (vanligast)
```bash
# Från Windows/bygg-host: synka provision/ + appkod till dev15, kör skriptet.
scp -r hubs_arende ubuntu@10.43.51.62:/home/ubuntu/hubs_arende
ssh ubuntu@10.43.51.62 'cd /home/ubuntu/hubs_arende/provision && \
  APP_SRC=/home/ubuntu/hubs_arende bash bootstrap.sh'
```

### 2b. Direkt på dev15
```bash
ssh ubuntu@10.43.51.62
cd /home/ubuntu/hubs_arende/provision
bash bootstrap.sh                      # full restore (steg a–i)
```

### 2c. Torrkörning först (rekommenderas alltid efter en hård reset)
```bash
bash bootstrap.sh --dry-run            # visar alla kommandon, ändrar INGET
```

### 2d. Vanliga varianter
```bash
# Host utan utgående nät (kan ej ladda libresign-binärer):
INSTALL_LIBRESIGN=0 bash bootstrap.sh

# Policy förbjuder appstore helt -> sidoladda bara via tarball:
SIDELOAD_ONLY=1 bash bootstrap.sh

# Bara koda om hubs_arende-appen (efter kodändring), inget annat:
bash bootstrap.sh --only g,h

# Nödåterställ appstore-policy om ett tidigare run kraschade i mitten:
bash bootstrap.sh --rollback-appstore
```

### 2e. Verifiera efter restore
```bash
OCC="sudo docker exec -u www-data hubs-php php /var/www/html/occ"
$OCC app:list | grep -E 'hubs_arende|deck|tasks|forms|notes|libresign|app_api|contacts'
$OCC status
$OCC app_api:daemon:list
# Tabellkoll:
sudo docker exec -u postgres hubs-postgres psql -d nextcloud -tAc \
  "SELECT count(*) FROM oc_hubs_arende_case"
```
Förväntat: alla appar `enabled`, `occ status` ok, deploy-daemon listad,
`oc_hubs_arende_case` svarar (0 rader på ren instans).

---

## 3. Rollback (om ett run gick fel)

`bootstrap.sh` skriver en rollback-referens i steg (a) till
`/opt/project_data/hubs_arende/rollback/`:
- `config-before-<ts>.json` — hela `occ config:list --private` före.
- `applist-before-<ts>.txt` — `occ app:list` före.
- `appstoreenabled-before-<ts>.txt` + `…-LATEST.txt` — appstore-policy före.

**Återställ appstore-policy:** `bash bootstrap.sh --rollback-appstore`.

**Diffa appläget mot före:**
```bash
diff <(sudo docker exec -u www-data hubs-php php /var/www/html/occ app:list) \
     /opt/project_data/hubs_arende/rollback/applist-before-<ts>.txt
```

**Ta bort en app vi lade till** (t.ex. vid felaktig version):
```bash
# ###### DESTRUKTIVT mot just den appen ######
sudo docker exec -u www-data hubs-php php /var/www/html/occ app:remove deck
```

---

## 4. Slutmål: merge in i den gemensamma `itsl deploy`-rutinen

Idag är `bootstrap.sh` ett **separat post-reset-steg**. Slutmålet är att
`itsl deploy` självt provisionerar hubs_arende-stacken, så att en operatör bara
kör `sudo itsl deploy` och får allt. Mergeplan (i ordning):

1. **Pinna versioner i SDK:n.** Lägg hubs_arende-app-versionen och de signerade
   tarball-apparna (deck/tasks/forms/notes/libresign/app_api) i
   `/opt/itsl-sdk/share/versions.yaml` under en ny sektion
   `hubs_arende_apps:` (speglar `manifest.yaml`). `scripts/set-versions.sh`
   exponerar dem som env-vars.

2. **Post-deploy-hook.** Lägg `bootstrap.sh` (eller dess steg c–h) som en
   `commands.d/*.conf`-subkommando, t.ex. `itsl provision-arende`, och anropa
   det sist i `itsl deploy` efter `.initialized`-markören satts. Stegen är redan
   idempotenta → säkert att köra varje deploy.

3. **Appstore-policy via .env, inte runtime-toggle.** Ersätt steg (b)/(i) med
   sidoladdning (`SIDELOAD_ONLY=1`) i den gemensamma rutinen, så att
   appstoreenabled aldrig flippas i prod. Tarballarna cachas i project_data
   (`tarballs/`) så deploy fungerar offline.

4. **ExApp-flippen.** När hubs_arende paketeras som ExApp (kontraktets
   "designas ExApp-rent"): byt steg (g) från `custom_apps`-kod till
   `occ app_api:app:register … --json-info` mot deploy-daemonen från steg (e),
   och aktivera `PROVISION_DEDICATED_DB=1` (separat pg-role/DB). Då blir
   `survives_itsl_deploy` = JA för hela appen (ExApp-containern + dess DB lever i
   project_data) och post-deploy-hooken behöver bara registrera om ExAppen.

5. **Versionsuppdatering följer den befintliga rutinen** (memory `hubs-ops`):
   redigera `versions.yaml` → `itsl pull` → `itsl config` → `itsl deploy`.
   hubs_arende-apparna åker med automatiskt när de ligger i versions.yaml.

**Acceptanskriterium för merge klar:** en hård reset (1c) följd av enbart
`sudo itsl deploy` ger en fullt provisionerad hubs_arende-stack utan att någon
kör `bootstrap.sh` manuellt.
