<!--
SPDX-FileCopyrightText: 2026 ITSL
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# HUBS — Master-implementationsplan (noll → körande system)

**Datum:** 2026-06-16 · **Status:** exekverbar planeringsmaster · **Scope:** allt mellan den verifierade dev15-verkligheten och ett körande Hubs-system (268 krav i `HUBS-KRAVSTALLNING-TOTAL.md`) på en riktig itsl-managed instans.

**Källor (synvägda i denna plan):** PM1 Infrastruktur & miljö · PM2 Harness & stub-arkitektur · PM3 Kod-bygget · PM4 Beslutslogg · PM5 Blinda fläckar · PM6 Faserad roadmap. Alla beslut samlade i den fristående, ratificerbara **`HUBS-BESLUTSLOGG.md`** (BESLUT-01…25).

> **Terminologi:** tekniska namn (AppAPI/ExApp/Deck/Postgres/socket-proxy/Frends/Treserva) används fritt — detta är utvecklar-/planeringstext. Aldrig leverantörsnamn i kund-UI.
> **Ärlighetsmarkör:** kommandon med `<...>` kräver verifiering av exakt version/tag/URL mot cr.itsl.se eller release-sidan **innan** körning — gissa inte tarball-URL:er eller argumentordning.
> **Hård PII-regel genomgående:** aldrig riktig PII/sekretessdata i dev/stage/bygg — endast syntetisk data. Säkerhetsskyddsklassificerat material avvisas/karantänsätts utanför systemet (gäller även testdata).

---

## 0. Executive summary

### 0.1 Målbild

Bygga hela kravbilden (`HUBS-KRAVSTALLNING-TOTAL.md`, 268 krav) på en riktig itsl-managed Hubs-instans, med en arkitektur som samtidigt löser tre problem med **ett och samma grepp — maximal separation**: (1) survive `itsl deploy`, (2) AGPL-/licensgränsen, (3) Hubs-ALDRIG-SoR-invarianten.

### 0.2 Målarkitektur

```
  Internet ──TLS──▶ itsl reverse-proxy ──▶ [hubs-php / NC 31]   ◀── PUBLIK YTA
                                              │
                          ┌── TUNN AGPL-BRO (in-process, "dum") ──┐
                          │  hubs_start (Vue 2.7-frontend + skal)  │
                          │  M0 sdkmc-core (case:{id}-taggmotor,   │
                          │  AppDetectionService, ExApp-registrering, OCS-aggregat)
                          └───────────────┬───────────────────────┘
                                          │  HTTP / AppAPI  (arm's length, app-nivå-JSON)
                                          ▼
                ┌──── M4 VERKSAMHETS-ExApp (proprietär-kapabel, egen process) ────┐
                │  Ärende-motor: register · saga R1–R10 · match · commit          │
                │  ArendeTyp-registry (commitDestination NOT NULL)                │
                │  12 integrations-portar (stub ↔ live per port)                  │
                │  EGEN INTERN DB (Postgres) — opaka pekare, INGA oc_*-FK         │
                └─────────────────────────────────────────────────────────────────┘

  MODULER (mjuka beroenden, graceful degradation via AppDetectionService):
     M1 Meddelanden (sdkmc-msg/mail/securemail) · M2 Video&Chat (spreed-itsl) · M3 Filer (groupfolders)
```

**Bärande invariant genom alla faser:** Hubs är ALDRIG System of Record. Varje `ArendeTyp`-rad har `commitDestination NOT NULL`. Retention startar ENBART på en verifierad facksystem-callback. Säkerhetsskyddsklassificerat/visselblåsning route:as ALDRIG — avvisas/isoleras FÖRE registret föds.

### 0.3 De 5 viktigaste sakerna att göra först (kritisk väg)

1. **Separat ren bygg-/integrations-instans** (egen NC; dev15 förblir read-only referens). *(BESLUT-06)*
2. **app_api (AppAPI) + ExApp-runtime via Docker deploy-daemon + ExApp egen DB** som överlever `itsl deploy`. *(BESLUT-04/05/15)*
3. **Säkerhetsskydds-grinden (P0-SÄK)** som terminerande "föds-inte"-utfall FÖRE allt känsligt inflöde. *(BESLUT-13)*
4. **Harness-stubben produktionifierad till en HTTP-stub-tjänst** med verifierad callback `{hubsCaseId, dnr}` (generaliserad e-diarium/e-arkiv-kontrakt). *(PM2)*
5. **`sdkmc_arende`-registret + atomär `createArende()`-saga + `FacksystemCommitService` mot harness** (commit-bunden retention, idempotens). *(BESLUT-01/07, PM3)*

### 0.4 De viktigaste besluten

Alla beslut ratificeras i **`HUBS-BESLUTSLOGG.md`** (BESLUT-01…25). De fyra med störst hävstång / längst ledtid:

- **BESLUT-01 Datalager** — bygg i sdkmc-DB (alt. b) NU, ExApp-rent schema, ExApp-DB (alt. c) som mål. *Blockerar allt annat.*
- **BESLUT-02 Licens** — designa ExApp-rent + boka IP-jurist; inget proprietärt beslut förrän juristen verifierat snittet.
- **BESLUT-05 Deploy-överlevnad** — M4 som ExApp (överlever per konstruktion); bron bakas i bundle/itsl config.
- **BESLUT-13 Säkerhetsskydd** — fail-closed avvisning FÖRE R1 (lagbrottsgolv innan icke-socialtjänst-roller).

---

## 1. Nuläge på dev15 (verifierad read-only 2026-06-16)

| Dimension | Verifierat läge | Konsekvens för planen |
|---|---|---|
| NC-version | **31.0.8.1**, productname "ITSL Hubs", SAAS, itsl-managed | App-versioner måste matcha NC 31 (`max-version >= 31`, `min-version <= 31`). Bygg-instansen ska spegla 31, inte 32. |
| sdkmc | **2.2.25** (enabled) | Äldre än referensversionerna — versionslyft på bygg-instans (BESLUT-14) före motor-bygg. |
| ENABLED-appar | spreed 21.1.0.5, mail 4.2.10, richdocuments 8.7.4, groupfolders 19.1.10, files_retention 2.0.1, files_automatedtagging 2.0.0, files_accesscontrol 2.0.0, systemtags, workflowengine 2.13.0, circles 31.0.0, scimserviceprovider, sociallogin, oauth2, activity, admin_audit, collectives, calendar, whiteboard, fulltextsearch(+elasticsearch), notify_push | Vy-/ACL-/retention-/audit-/SSO-infrastrukturen FINNS → återanvänds rakt av (BESLUT-10/18). |
| SAKNADE appar | **deck, tasks, forms, libresign, notes, app_api** | Installeras (§2.1). `app_api` FÖRST — allt ExApp beror på den. |
| contacts | **FINNS men DISABLED** | Bara `app:enable` — ingen nedladdning. |
| tables | **ABSENT** | Används EJ. Registret bor i intern DB, aldrig Tables (BESLUT-01). |
| Appstore | **AVSTÄNGD** (`appstoreenabled=false`) | Sidoladda eller återaktivera mot privat registry (BESLUT-04). |
| Docker-socket | `/var/run/docker.sock` FINNS (root:docker) | Docker deploy-daemon för ExApp möjlig (§2.2). Socket-proxy obligatorisk i stage/prod. |
| DB | `hubs-postgres` FINNS (eget Hubs-role) | ExAppen behöver EGEN DB — egen container eller ny DB+role (BESLUT-15). |
| Livscykel | `itsl deploy` bygger om hubs-php från pinnade cr.itsl.se-images + `itsl config` | Allt i hubs-php-FS riskerar skrivas över → survive-matris (§2.4) styr alla designval. |

**occ-mönster (dev15 och bygg-instans):**
```
sudo docker exec -u www-data hubs-php php /var/www/html/occ <cmd>
```
**itsl-mönster:** `sudo itsl <start|stop|status|pull|config|deploy|versions>`

---

## 2. Infrastruktur & miljö *(PM1)*

**Förenande designinsikt:** allt som *kan* flyttas ut ur `hubs-php` ska flyttas ut — det som lever utanför `hubs-php` överlever `itsl deploy` per konstruktion. Survive-deploy-frågan och licens-/modulfrågan har samma svar: separation.

### 2.1 App-installation (deck, tasks, forms, libresign, notes, app_api + contacts)

**Versionskrav (lås FÖRST — verifiera cr.itsl.se-tag / release-sida vid körning, gissa aldrig på minnet):**

| App | Spår för NC 31 | Anmärkning |
|---|---|---|
| **app_api (AppAPI)** | senaste 31-kompatibla (≈ v4.x) | **Installera FÖRST** — allt ExApp beror på den. |
| **deck** | ≈ v1.14.x | ärende-kort per ärende (saga R5). |
| **tasks** | ≈ v0.16.x | beror på calendar (FINNS). |
| **forms** | ≈ v4.3.x | blankett-inflöde. |
| **notes** | ≈ v4.11.x | lättviktig. |
| **libresign** | NC 31-kompatibel tag | tyngst — Java/JSignPdf + CFSSL; specialfall (§2.1.3). |
| **contacts** | redan installerad (DISABLED) | bara `app:enable`. |

**Metodval — hybrid styrt av artefakt-typ (BESLUT-04):**
- **Store-appar** (app_api, deck, tasks, forms, notes, contacts): **(b) appstore mot cr.itsl.se tillfälligt påslagen** under install-fönstret (ger signaturverifiering + NC-31-matchning gratis), sedan av igen. Fallback: **(a) sidoladda store-signerad tarball** (nextcloud-releases-arkiv, ej GitHub-källkods-`.tar.gz`).
- **libresign:** **(a) sidoladda** + runtime (specialfall).
- **M4-ExApp + bro-app:** varken (a) eller (b) i hubs-php → ExApp respektive bundle/persistent volym (§2.4).

**2.1.1 Väg (b) — appstore mot privat registry (exakta kommandon):**
```bash
# 0. Snapshot config FÖRE (rollback-referens)
sudo docker exec -u www-data hubs-php php /var/www/html/occ config:list system > /tmp/buildinst-config-pre.json
# 1. Slå PÅ appstore tillfälligt, peka mot privat registry
sudo docker exec -u www-data hubs-php php /var/www/html/occ config:system:set appstoreenabled --value true --type boolean
sudo docker exec -u www-data hubs-php php /var/www/html/occ config:system:set appstoreurl --value "https://cr.itsl.se/<APPSTORE-INDEX-PATH>"   # verifiera exakt URL med itsl-drift
# 2. Aktivera redan-installerade contacts (ingen nedladdning)
sudo docker exec -u www-data hubs-php php /var/www/html/occ app:enable contacts
# 3. AppAPI FÖRST
sudo docker exec -u www-data hubs-php php /var/www/html/occ app:install app_api
# 4. Resten
sudo docker exec -u www-data hubs-php php /var/www/html/occ app:install deck
sudo docker exec -u www-data hubs-php php /var/www/html/occ app:install tasks
sudo docker exec -u www-data hubs-php php /var/www/html/occ app:install forms
sudo docker exec -u www-data hubs-php php /var/www/html/occ app:install notes
# 5. Verifiera
sudo docker exec -u www-data hubs-php php /var/www/html/occ app:list | grep -E "app_api|deck|tasks|forms|notes|contacts"
# 6. Slå AV appstore igen (återställ policy)
sudo docker exec -u www-data hubs-php php /var/www/html/occ config:system:set appstoreenabled --value false --type boolean
```
> Om `app:install <x>` klagar på NC-31-kompatibilitet → registryn saknar 31-tag; gå till väg (a) för just den appen.

**2.1.2 Väg (a) — sidoladda (fallback) — exakta kommandon:**
```bash
# Verifiera custom_apps-path FÖRST
sudo docker exec -u www-data hubs-php php /var/www/html/occ config:system:get apps_paths   # förvänta "path":".../custom_apps","writable":true
# Per app (exempel deck — använd SIGNERAD nextcloud-releases-tarball, verifiera taggen)
APP=deck ; VER=<v1.14.x>
curl -fSL -o /tmp/$APP.tar.gz "https://github.com/nextcloud-releases/$APP/releases/download/$VER/$APP-$VER.tar.gz"
sudo docker cp /tmp/$APP.tar.gz hubs-php:/tmp/$APP.tar.gz
sudo docker exec -u root hubs-php sh -c "mkdir -p /var/www/html/custom_apps && tar -xzf /tmp/$APP.tar.gz -C /var/www/html/custom_apps"
sudo docker exec -u root hubs-php chown -R www-data:www-data /var/www/html/custom_apps/$APP
sudo docker exec -u www-data hubs-php php /var/www/html/occ app:enable $APP
```
> **Signaturnot:** `nextcloud-releases`-arkivet är signerat → `app:enable` validerar utan flaggor. GitHub-källkods-`.tar.gz` är det INTE → undvik (skulle tvinga osäker `--no-app-signing`).

**2.1.3 libresign — specialfall (sidoladda + runtime):**
```bash
# 1. sidoladda appen (som 2.1.2)
# 2. installera dess beroenden
sudo docker exec -u www-data hubs-php php /var/www/html/occ libresign:install --all          # JSignPdf, CFSSL, Java-deps
sudo docker exec -u www-data hubs-php php /var/www/html/occ libresign:configure:cfssl          # eller :openssl --cn "ITSL Hubs"
```
> **Survive-deploy-varning:** libresigns binärer + cert hamnar antingen i `data/appdata_*/libresign` (persistent) ELLER i container-FS (ej persistent). Verifiera var — om container-FS måste det bakas in i imagen (§2.4). **libresign är den mest deploy-ömtåliga appen** — egen punkt till itsl-drift.

**2.1.4 Efter install:**
```bash
sudo docker exec -u www-data hubs-php php /var/www/html/occ upgrade     # kör migrationer för nya appar
sudo docker exec -u www-data hubs-php php /var/www/html/occ app:list
sudo docker exec -u www-data hubs-php php /var/www/html/occ status
```

### 2.2 ExApp/AppAPI-runtime (deploy-daemon + M4-ExApp)

**Verifiera AppAPI:**
```bash
sudo docker exec -u www-data hubs-php php /var/www/html/occ app:list | grep app_api
sudo docker exec -u www-data hubs-php php /var/www/html/occ app_api:daemon:list
```

**Deploy-daemon — säkerhetsövervägande:** `docker.sock` = root-på-host.

| Miljö | docker.sock-väg | Motivering |
|---|---|---|
| **bygg / dev15** | direkt `/var/run/docker.sock` (helst proxy redan här för paritet) | bygg/demo, accepterad risk |
| **stage / prod** | **OBLIGATORISK** `docker-socket-proxy` (Tecnativa), bara whitelistade endpoints, allt annat 403 | minimerar blast-radius |

**Socket-proxy-skiss (stage/prod):**
```yaml
docker-socket-proxy:
  image: tecnativa/docker-socket-proxy
  environment: { CONTAINERS: 1, IMAGES: 1, POST: 1, NETWORKS: 1, EXEC: 0, VOLUMES: 0 }
  volumes: [ "/var/run/docker.sock:/var/run/docker.sock:ro" ]
  # exponera tcp://docker-socket-proxy:2375 INTERNT, aldrig publikt
```

**Registrera daemon + deploya M4 (verifiera exakt argumentsyntax mot installerad app_api-version — `occ app_api:daemon:register --help` / `app_api:app:register --help`):**
```bash
# dev15/bygg: direkt sock
sudo docker exec -u www-data hubs-php php /var/www/html/occ app_api:daemon:register \
  hubs_docker "Hubs Docker daemon" docker-install unix-socket /var/run/docker.sock https://<bygg-domän>
sudo docker exec -u www-data hubs-php php /var/www/html/occ app_api:daemon:check hubs_docker
# Deploy M4 (privat image från cr.itsl.se)
sudo docker exec -u www-data hubs-php php /var/www/html/occ app_api:app:register \
  hubs_m4 hubs_docker --info-xml https://cr.itsl.se/hubs/m4-exapp/<ver>/info.xml
# Uppdatera ExApp (behåll DB!)
sudo docker exec -u www-data hubs-php php /var/www/html/occ app_api:app:unregister hubs_m4 --keep-data
sudo docker exec -u www-data hubs-php php /var/www/html/occ app_api:app:register hubs_m4 hubs_docker --info-xml https://cr.itsl.se/hubs/m4-exapp/<NY-ver>/info.xml
```
> `--keep-data` (verifiera flaggans exakta namn) är kritiskt — annars river du ExApp-DB:n.

**Bro-appen (hubs_start + M0 sdkmc-core) är INTE en ExApp** — in-process AGPL-PHP (combined work). Bakas i Hubs-bundlen/persistent volym (§2.4). AppAPI används bara för M4-tjänsten på andra sidan HTTP-snittet.

### 2.3 Intern DB för ExAppen (egen Postgres)

**Beslut (BESLUT-15):** I (b)-fasen ligger registret i sdkmc:s NC-app-DB (ingen egen DB). Vid (c)-lyft: **egen ExApp-container via AppAPI/Docker-socket + EGEN DB** — egen Postgres-container ELLER **ny DB+role i hubs-postgres** (egen role, inte "postgres", inte Hubs-rollen). Egen Postgres-container endast om isolering kräver.

| Dimension | Egen Postgres-container | Ny DB+role i hubs-postgres |
|---|---|---|
| Process-/krasch-isolering | ✅ full | ❌ delar instans med NC |
| Licens-/arm's-length (AGPL) | ✅ fysiskt frikopplad | 🟡 svagare separation |
| Survive `itsl deploy` | ✅ daemon äger den | 🟡 överlever men itsl äger schemat |
| Drift-overhead | ❌ en container till | ✅ noll ny infra |

**Schema-ägarskap & single-writer:** ExApp-tjänsten äger 100 % av sitt schema. **Inga `oc_*`-FK:er** — alla NC-pekare (`deckCardId`, `talkToken`, `groupfolderId`, `caseTagId`, `calendarObjUri`) som **opaka strängar/int**. Egen migrationskedja (Doctrine/Phinx för PHP-Slim, Alembic för Python), körd idempotent i ExApp-startup. All skrivning via ETT service-lager (`ArendeService`). `commitDestination` NOT NULL.

**Backup (egen rytm, ej itsl:s NC-backup):**
```bash
docker exec hubs-m4-postgres pg_dump -U hubs_m4 -Fc hubs_m4 > /backup/hubs_m4_$(date +%F).dump   # dagligt + WAL i stage/prod
# pg_dump FÖRE varje ExApp-image-uppdatering (rollback-punkt). Dumpar på ANNAN volym/host.
```

### 2.4 Survive `itsl deploy` — matris per artefakt

`itsl deploy` bygger om hubs-php från pinnade images + `itsl config`-genererad config. Tre överlevnadsmekanismer: **(a)** bakat i imagen/bundlen · **(b)** persistent volym + itsl config-persistens · **(c)** separat ExApp.

| Artefakt | Survive-mekanism (rekommenderad) | Konkret åtgärd |
|---|---|---|
| **AppAPI (app_api)** | **(a) baka i Hubs-bundle** | be itsl pinna app_api i cr.itsl.se-imagen |
| **deck, tasks, forms, notes** | **(b) persistent `custom_apps`-volym + (a) helst i bundle** | montera `custom_apps` som named volume; ELLER in i bundle-listan |
| **contacts** | **(b/itsl config)** — enabled-state är *config* | verifiera att deploy inte återställer disabled |
| **libresign + binärer/cert** | **(a) baka app + runtime i imagen** | mest ömtåligt; container-FS-binärer dör → KRÄVER image-bakning |
| **bro: hubs_start (M4-skal)** | **(a) baka i Hubs-bundle** | AGPL, in-process, hör hemma i NC-imagen |
| **bro: M0 sdkmc-core** | **(a) baka i bundle** | sdkmc finns redan; M0-utbrytning via samma pipeline |
| **AGPL-bro-konfig (ExApp-reg, secrets)** | **itsl config-persistens** | aldrig handredigerad config.php |
| **M4 verksamhets-ExApp** | **(c) separat ExApp** — överlever per konstruktion | deployas av daemonen, ligger ALDRIG i hubs-php |
| **ExApp intern DB (Postgres)** | **(c) persistent named volume på daemon-host** | överlever image-uppdatering med `--keep-data` |
| **AppAPI daemon-registrering** | **(b) NC-DB persisterar** (verifiera mot itsl config-reset) | `app_api:daemon:list` efter deploy; re-registrera vid behov |

**Princip:** för varje artefakt — "rör `itsl deploy` detta?" Om ja och inte i bundlen → flytta ut (ExApp) eller persistera (volym + itsl config). **Det som kan bli ExApp ska bli ExApp.**

### 2.5 Secrets / config

| Secret | Lagras i | Rotation |
|---|---|---|
| AppAPI deploy-secret per ExApp | AppAPI genererar; persisteras i NC-db + ExApp-env | `app:unregister --keep-data` + re-register, eller AppAPI rotate-kommando |
| ExApp-DB-credentials | ExApp-stackens `.env` / itsl config-vault, injiceras som env, ALDRIG i image-lager | ny pwd i vault → restart ExApp+DB |
| cr.itsl.se pull-creds | daemon-hostens docker login / itsl config | itsl-drift-rutin |
| Facksystem-secrets (Frends/Treserva/Sokigo) | **ExApp-intern vault**, utanför NC helt | per-kund, ExApp-ägd |
| Bro↔ExApp shared secret | **`itsl config`-persistens** (överlever deploy), aldrig config.php | följer AppAPI-secret-rotation |

**Principer:** `.env` committas aldrig (`.env.example` levereras, riktiga värden injiceras vid deploy). itsl config = secret-sanningen för NC-sidan (deploy regenererar config.php). ExApp-sidans secrets i ExApp-vault, separerat av licens-/isoleringsskäl. **Per-miljö-rotation:** dev-secrets följer ALDRIG med uppåt till stage/prod.

### 2.6 Miljöer: bygg → dev15 → stage → prod

| Miljö | Roll | PII |
|---|---|---|
| **bygg (lokal/CI)** | ritbord — **vanilla NC 31** (spegla dev15, inte 32); bygg bro-app + M4-image, migrationer, enhetstest, signera | syntetisk |
| **dev15** | första itsl-managed integration — deploya färdiga artefakter, verifiera survive-deploy, demo | syntetisk |
| **stage** | prod-spegel — full socket-proxy, prod-lik secrets, last-/backup-/rotationstest | syntetisk |
| **prod (kund)** | skarp — endast promoterade, bundle-bakade + ExApp-deployade artefakter; ingen interaktiv install | skarp (kund äger) |

**Promotionsregel:** en artefakt klättrar ett steg endast om den (i) är versionspinnad i cr.itsl.se, (ii) överlevde `itsl deploy` i föregående miljö, (iii) har egen secret-uppsättning per miljö. Inga `--no-app-signing`, inga handredigerade containrar uppåt. **dev15 är read-only referens — allt destruktivt/utforskande sker i bygg-instansen (BESLUT-06).**

### 2.7 Nät: ExAppen är ALDRIG publikt exponerad

```
  Internet ──TLS──▶ itsl reverse-proxy ──▶ [hubs-php :80]   (PUBLIK YTA)
                                              │ AppAPI (HTTP intern)
                      ┌─── internt nät "hubs-internal" ───┐
                      │  [m4-exapp :9000] ◀─callback─ NC   │
                      │       ▼ single-writer              │
                      │  [m4-postgres :5432] (pgdata vol)  │
                      │  [docker-socket-proxy :2375] ◀ daemon
                      └────────────────────────────────────┘
```
Inga host-`-p`-publiceringar för ExApp/DB/proxy. ExApp får aldrig publik DNS/route. Intern TLS för stage/prod; intern-http acceptabelt i dev15/bygg.

### 2.8 Öppna punkter att verifiera med itsl-drift (gissa inte)
- Exakt cr.itsl.se appstore-index-URL + om deck/tasks/forms/notes/app_api är speglade där.
- Om `custom_apps` redan är persistent volym i itsl-compose (avgör survive-mekanism b vs a).
- Installerad app_api-versionens exakta `app_api:app:register`/`daemon:register`-argumentsyntax.
- Om `itsl config` återställer `appstoreenabled`/`app:enable`-state vid deploy.
- Vilka appar itsl vill baka i Hubs-bundlen vs lämna som persistent-volym-ansvar.

---

## 3. Harness & stub-arkitektur *(PM2)*

**Mål:** bygga/testa/dema hela systemet end-to-end **innan** en enda riktig integration finns. **Kontrakt-först:** varje extern integration är en **port** (interface) i M4-ExAppen med två implementationer `*StubAdapter` ↔ `*LiveAdapter`, valda per port av `IntegrationPortFactory` via `INTEGRATION_MODE`. Samma kontraktstester körs mot båda — **att byta integration får aldrig ändra systemet.**

**Var harnesset bor (icke-förhandlingsbart):** portarna + båda implementationerna i **M4-ExAppen** (proprietär-zon, egen DB), bakom AppAPI/HTTP-gränsen — **aldrig** i den AGPL-tunna bron. Bron förblir tunn: registrerar, autentiserar, djuplänkar, vidarebefordrar.

**Invariant som harnesset bevarar i varje läge:** Hubs är ALDRIG SoR; varje `ArendeTyp` har `commitDestination` NOT NULL; retention startar **enbart** på verifierad commit-callback — stubben gör redan rätt *mönster* i `treserva.js commitHandling()`, och det mönstret är kontraktet live måste uppfylla.

### 3.1 Portar (12) + negativ KarantanGate

| Port (interface) | Stub-beteende (källa) | Live-ersättning | Seam-id | Status |
|---|---|---|---|---|
| **P1 `FacksystemCommitPort`** | `treserva.js`: stateful REGISTER/RECEIPTS, callback-mönster, skapa/koppla | Frends → Treserva/Lifecare/Viva; `FacksystemCommitService` (modul×produkt) | `treserva`, `.commit`, `.skapa`, `.koppla` | FINNS(stub)/BYGGS(live) — GAP-019 |
| **P2 `EDiariumArkivPort`** | ny stub, ärver treserva-mönster; publik-post + FGS | Public360/W3D3/Platina + Sydarkivera FGS; temporal-sekretess-flip | `ediarium` | BYGGS — P0-hävstång |
| **P3 `ExternMyndighetCommitPort`** | ankare=vetskap, ingen dnr, PII-maskad, milstolps-frister | IMY/MSB/IVO/FK; `commitMode='extern_anmalan'` | `extern_myndighet` | BYGGS |
| **P4 `UnderskriftPort`** | libresign lokal rot-CA (demo) | Inera Underskriftstjänst; PAdES+PDF/A-1+LTV+kval. tidsstämpel | `underskrift` | FINNS(demo)/BYGGS(Inera) — GAP-033/034/035 |
| **P5 `FavoritResolverPort`** | `favoriter.js`: 4 DTO:er (a/b/c + tombstone) | sdkmc `GET /favoriter` + DIGG-batch, fail-closed, klass-validering | `favoriter`, `.tombstone` | FINNS(stub)/BYGGS(live) — GAP-061/064 |
| **P6 `IdentitetPort`** | deterministisk LOA3, poll-sekvens, lobby-släpp | BankID/Freja/SITHS via Sweden Connect | `identitet` | BYGGS |
| **P7 `TranskriberingPort`** | fast transkript + AI-utkast | Lokal KB-Whisper + `llm2`, human-in-the-loop | `transkribering` | BYGGS(stub)/**LÅST**(live, GAP-052) |
| **P8 `SignalProvenansPort`** | kvittens-tidslinje (5 steg) | securemail/sdkmc läskvittens + delgivningssätt | `delgivning` | FINNS(modell)/BYGGS — GAP-038/039 |
| **P9 `KanalInflodePort`** | `socialsekreterare.js` triage/inflöde | M1 mail/securemail/sdkmc + `MessageTypeService` | `api.js DEMO`, `demodata` | FINNS/FINNS(motor) |
| **P10 `ChattRumPort`** | stubbad `talkToken` | spreed-itsl rum-skapande + `TalkController` | (del av `treserva`) | FINNS(ctrl)/BYGGS(rum) |
| **P11 `ArenderumPort`** | stubbad `groupfolderId` | Groupfolders + atomär 3-lagers-ACL | (saga-steg) | BYGGS — GAP-057/058 |
| **P12 `DeckKalenderPort`** | stubbade `deckBoardId/cardId` | Deck 2-stegs kort + CalDAV VTODO | (saga-steg) | BYGGS — GAP-010 |
| **KarantanGate** (negativ) | vägrar mint:a vid säkerhetsskydd | preSagaHook `avvisa_sakerhetsskydd` + retroaktiv isolering | (saga-utfall A4) | BYGGS |
| visselblåsning | **ingen stub, ingen port** (isoleras ur sagan) | **medveten icke-integration** | — | N/A by design |

### 3.2 Harness-mode: per-port toggle (`stub | live`)

Config i ExAppens egen config (ej NC-config):
```
INTEGRATION_MODE_DEFAULT = stub
INTEGRATION_MODE_FACKSYSTEM = stub|live   # P1   ... per port ...
INTEGRATION_MODE_TRANSKRIB  = stub        # P7 — LÅST stub (GAP-052 policy)
INTEGRATION_MODE_KANAL/CHATT/ARENDERUM/DECK = auto   # P9–P12 följer AppDetectionService
```
- **Default `stub`** i dev/stage. `live` sätts **per kund/per port i prod** — och först efter gröna kontraktstester (§3.4) mot kundens live-konnektor.
- **`auto`** för NC-grann-portarna: `AppDetectionService::detect()` avgör; saknad granne → stub-port utan att invarianten bryts.
- `IntegrationPortFactory.get(Port)` → `mode=live` utan konfigurerad konnektor = **hård start-up-fail** (aldrig tyst stub i prod). `GET /api/v2/integration/health` exponerar mode + adapter-klass per port.
- **commitDestination-grind:** factory validerar att den `ArendeTyp` anropet rör har `commitDestination` NOT NULL innan något port-anrop släpps igenom — invarianten lever i harness-lagret, inte bara schemat.

### 3.3 Stub-beteende: realism, inte attrapp
1. **Verifierade async callbacks** (Frends-mönstret): P1/P2-stub returnerar synkront `CommitAck` (ack-id, **inget dnr**); levererar `{hubsCaseId, dnr}` senare via timer/event → `onVerifiedCallback`. Retention/provenans flippar först där.
2. **Fördröjningar** (`STUB_LATENCY_MS`): commit-callback 2–8 s, BankID-poll 1 s, DIGG-resolve 200 ms. Testläge = 0.
3. **Fel-/timeout-injektion** (`STUB_FAULT_RATE`, `STUB_FORCE_FAULT[port]=timeout|reject|verifierad_false`) → bevisar saga-kompensering och fail-closed.
4. **Idempotens:** idempotensnyckel `conversationId` → dubbla `createCase`/`commit` ger samma ack (GAP-057).
5. **Deterministisk syntetisk testdata — ALDRIG PII** (`barnRef`="Barn 2026-0142"). Tombstone-fixturen bevaras som negativ-test-data.
6. **Stateful där live är stateful:** `REGISTER`/`RECEIPTS`-Map:arna blir ExAppens stub-persistens (in-memory/SQLite).

**Flytt av befintliga stubbar:** `treserva.js` → `FacksystemCommitStubAdapter` (P1) + `EDiariumArkivStubAdapter` (P2); `favoriter.js` → `FavoritResolverStubAdapter` (P5). Frontend-demoläget tunnas ut: klienten anropar de riktiga OCS-routerna, som i `stub`-mode landar i ExAppens stub-adapter — stubben sitter nu **bakom** kontraktet, inte framför.

### 3.4 Kontraktstester: samma svit mot stub OCH live
Ett testbatteri per port, parametriserat på adapter. `mode=stub` i CI på varje commit; `mode=live` som **acceptansgrind** mot en kunds konnektor innan den portens `INTEGRATION_MODE` sätts `live`. Varje testfall taggas med sitt **seam-id** (spårbarhet mot DEMO-STUBS.md). Test-shape-kontraktet är låst av frontend: `commitArende()` konsumerar `{ok, dnr, gallrasDatum, verifierad, receipt}` — exakt det `commitHandling()` returnerar. Live-adaptern MÅSTE returnera samma shape.

### 3.5 Demo-/acceptans-harness: komplett ärende-livscykel på stubbar
**Scenario A — gröna tråden:** skapa → klassa → fördela → committa → gallra, helt på stubbar. Verifiera: register-rad komplett + gul ProvenansChip (`ej_registrerad`); auto-koppling kräver människo-bekräftelse; atomär ACL-omskrivning utan sekretessfönster; commit-ack utan dnr → callback flippar provenans/dnr/retention; gallring ENBART efter verifierad callback.
**Scenario B — negativa utfall:** säkerhetsskydd → KarantanGate vägrar mint:a; DIGG-down → fail-closed; saga-fel → kompensering 5→1; extern myndighet → ankare=vetskap, retention oförändrad.
**Mekanik:** `demo:reset` återställer stub-persistens deterministiskt; `GET /api/v2/integration/health` visar att alla portar är `stub`. Samma A/B körs som CI-acceptans (stub) och driftsättnings-acceptans (live, per port) med identiska assertions.

---

## 4. Kod-bygget *(PM3)*

> **Två kod↔doc-motsägelser som planen löser (verifierade mot kod):**
> 1. **Route-prefix:** `routes.php` registrerar `/api/v2/...`; `api.js:63` bygger `SDKMC_OCS = generateOcsUrl('apps/sdkmc/api/v1' + path)`. **Beslut: registrera nya ärende-routes på `/api/v2/arende*` och patcha `api.js`-konstanten v1→v2 i samma PR som kopplar bort demo.** Enradsändring men load-bearing — utan den 404:ar prod-grenen. *(BESLUT-21)*
> 2. **Registrets hem:** MODULARISERING §3.1 underkänner Tables (ingen single-writer/transaktion/constraint). **Planen följer (b) sdkmc-DB via QBMapper/Migration → (c) ExApp-DB.** Tables används aldrig som motor-backend (matchar dev15: tables ABSENT). *(BESLUT-01)*

### 4.1 Komponent-beroendegraf (byggordning)
```
[STEG A]            [STEG B]              [STEG C]                  [STEG D]            [STEG E]
M0/M1-SPLIT    →    INTERN DB        →    KOMPONENTER (saga)   →    AGPL-BRO↔ExApp  →   FRONTEND
sdkmc-core         Version02xxxx          C0 Säkerhetsskydds-grind  bro-app (OCP/OCS)   api.js DEMO-av
sdkmc-msg          CreateArendeTable      C1 ArendeService (saga)   ExApp (logik+DB)    SDKMC_OCS→v2
(taggmotor→M0)     Arende/Mapper          C2 ArendeMatchService     HTTP-kontrakt       32 komp. återanv.
                   ArendeTyp/Mapper       C3 InnehallsKlassService
                   Receipt/Audit          C4 FacksystemCommitService
                   constraints            C5 ReconciliationJob
HÅRDA KANTER: A▶B ▶C ; C0▶C1 ; C1▶C2/C4 ; C1▶C5 ; C▶D ; B/C▶E
SAGA-ORDNING I C: C0 grind ─FÖRE─ R1 mint ▶ R2 rad ▶ R3 case-tagg ▶ R4 rum+ACL ▶ R5 Deck ▶ R6 Spreed ▶ R7 kalender ▶ R8 klocka ▶ R9 tagga ▶ R10 commit-punkt
                  (async, EJ i sagan) ▶ commit ▶ provenans-flip ▶ retention ▶ kvittens
```

**FINNS-vs-BYGGS i en blick:**

| Lager | FINNS (återanvänds rakt av) | BYGGS |
|---|---|---|
| Tagg/join-token | `ItslTagService`, `ItslTag(Mapper)`, `ItslMessageTag(Mapper)`, `TagFileController`, `TagSearchHelper` | label-konventioner `case:`/`kat:`/`flag:` (ren data) |
| Klassning (kanal) | `MessageTypeService` (deterministisk, fail-closed) | `InnehallsKlassService` ovanpå |
| Event-mall | `MessageImportantClassifiedListener` + `…Event` | `MessageReceivedListener` + `MessageReceivedEvent` |
| DB-mönster | `QBMapper`+`getOrCreate`, `Version020008…`, `Types::JSON`, `addUniqueIndex`, soft-delete | `Arende`/`ArendeMapper`, `ArendeTyp`, `CreateArendeTable` |
| Korg/ACL | `ConsolidateMailboxesService`, `ProvisionPersonligAccountsService` | per-kategori ACL-profil (R4) |
| Retention | `MailboxRetentionService`, `ExpungeService`, `ExpungeJob`, `DeleteTagsJob` | bindning retention→verifierad callback (GAP-007) |
| Kvittens | `MessageReceipt(Mapper)`+`MessageReceiptController` | provenans-flip + dnr-alias (R9) |
| Commit | — | `FacksystemCommitService` + Frends-konnektorfamilj |
| Saga | — | `ArendeService` (R1–R10 + kompensering) |
| Match | — | `ArendeMatchService` (kaskad + konfidens) |
| Grind | `Check/Loa3` (Flow-mönster) | säkerhetsskydds-grind (terminerande) |
| Detektering | `AppDetectionService` (kanal) | utöka till funktions-detektering (+groupfolders,files,contacts,deck) |
| UI | 32 Vue-komponenter LIVE + `api.js` (27 prod-grenar) | koppla bort `if(DEMO)`, patcha v1→v2 |

### 4.2 STEG A — sdkmc M0/M1-split (refaktor, INGEN data-migration)

Bryt monoliten i **`sdkmc-core` (M0)** + **`sdkmc-msg` (M1)** *innan* M4 byggs (BESLUT-03). Taggmotorn (`case:{id}`-token = join-mekanismen) + register + `ArendeService` → M0; `MessageTypeService`/retention/consolidate/receipt → M1.

**Migrationsstrategi (tre faser, facade-skydd, mot körande 2.2.25):**
- **A0 — intern modularisering, samma app-id.** Namespace `OCA\SdkMc\Core\*` *inuti* sdkmc; flytta M0-klasserna; `class_alias(OCA\SdkMc\Core\X::class, OCA\SdkMc\Service\X::class)`. Ingen `info.xml`-ändring, inget nytt app-id, ingen data-migration. `sdkmc_itsl_*`-tabellerna orörda.
- **A1 — DI-registrering delas** i `registerCore()` / `registerMsg()` i samma `AppInfo\Application`.
- **A2 (senare, vid ExApp-lyft) — fysisk app-split** till eget paket `sdkmc-core`; `class_alias`-broarna tas bort sist.

**Bevis:** hela befintliga sdkmc-testsviten körs oförändrad efter A0 → grön via `class_alias`. Rulla A0/A1 som patch-release **2.3.0** (additiv) → deployas till bygg-instans isolerat före M4-arbete.

### 4.3 STEG B — Intern DB-schema (sdkmc-core, alt. b)

**Mönster att kopiera verbatim:** `Version020008Date20251229000000.php`, `ItslTagMapper` (`QBMapper`, `getOrCreateTag`-idempotens, soft-delete). Migration `Version023000DateNNNNNNNN_CreateArendeTable.php`.

Tre tabeller, opaka pekare, INGA `oc_*`-FK:
- **`sdkmc_arende`** (registret): `hubs_case_id STRING(36) NOT NULL UNIQUE` (join, UUID v4) · `commit_destination STRING(32) NOT NULL` (INVARIANT, ärvs ur typ) · `provenance_state` (DEFAULT `ej_registrerad`) · `retention_state` (DEFAULT `aktiv`) · `idempotency_key STRING(255) UNIQUE` (GAP-057) · opaka pekare `groupfolder_id/deck_board_id/deck_card_id/talk_token/calendar_obj_uri/case_tag_id` · `conversation_ids JSON` · `dnr` (null tills callback) · `frist_due` (speglad, ej självräknad) · `deleted_at` (soft-delete) · INDEX(enhet,status), INDEX(dnr).
- **`sdkmc_arende_typ`** (process-mall-registry, 8 seed-rader): axlar a–h (`default_enhet`, `forsta_atgard`, `koppling_default`, `frist_policy JSON`, `acl_profil`, `diarie_plikt`, `dhp_handlingstyp`, `frends_modul`) · `pre_saga_hook`/`post_commit_hook` · `parts_modell` · **`commit_destination STRING(32) NOT NULL`** ∈ {facksystem|diarium|e_arkiv|extern_myndighet|triage_forward|karantan}.
- **`sdkmc_arende_receipt`** (append-only audit): `event_type` · `payload_hash` (innehåll loggas ALDRIG, bara hash) · `verifierad BOOLEAN DEFAULT false` (true först på callback). Ingen UPDATE/DELETE-väg i Mappern.

**Constraints & single-writer:** `commit_destination` NOT NULL (ingen rad utan slutdestination → Hubs kan aldrig tyst bli SoR). `UNIQUE(hubs_case_id)`+`UNIQUE(idempotency_key)` stänger GAP-057. `ArendeController` rör aldrig Mapper direkt — all skrivning via `ArendeService`. **Seed (8 rader)** idempotent via `postSchemaChange` (check-then-insert): kat 6 `pre_saga_hook='diariefor_direkt'`, kat 8 `post_commit_hook='familjeratt_yttrande'`+`parts_modell='flerpartsarende'`.

**Testbarhet:** migration mot sqlite+postgres; assert 3 tabeller + constraints + 8 seed-rader + `commit_destination` NOT NULL avvisar null-insert; rollback-test.

### 4.4 STEG C — Komponenterna i ordning

- **C0 Säkerhetsskydds-grind** (`lib/Core/Service/SakerhetsskyddGate.php`, M0) — klassar bort säkerhetsskyddsklassificerat **före R1**. Markör → `commit_destination='karantan'`, ingen R1, audit `karantan`, objektet `flag:sakerhetsskydd` + dolt (deny-by-default). **Retroaktiv karantän:** `ArendeService::quarantineRetroactive()` kör kompenseringskedjan omvänt (R10→R3). Återanvänder `MessageTypeService::enhanceMessages()` X-header-läsning + `Check/Loa3`-mönster. *Test: noll rader, en karantan-audit, inget Deck/Talk-anrop.*
- **C1 ArendeService** (`lib/Core/Service/ArendeService.php`) — single writer; mintar `hubsCaseId`; kör saga R1–R10 + kompensering; idempotens på `conversationId`. **Parameteriseras av `ArendeTyp`-raden** (en `createCase()`, åtta beteenden, noll grenar). R8-frist bunden till **inkom-datum** (ej `now()`). dnr-parning är INTE i sagan (asynkront via C4). Saknad granne → steget **hoppas** (graceful, AppDetectionService). *Test: fel på steg n → kompensering R(n-1)…R1, registret tomt; dubbel createCase → en rad.*
- **C2 ArendeMatchService** — matchar inkommande mot register, sätter `arendekoppling` (nytt|hor_till|ej_kopplat) + konfidens. Fail-säker default `ej_kopplat`. Kaskad: explicit `case:`-tagg (1.0) → dnr i ämne → `conversationId` (≥tröskel→auto) → SSN/orgId (<tröskel→föreslagen). **Tröskel server-side, ej klientkonstant** (GAP-060). Bilaga speglas vid bekräftelse, aldrig vid förslag. *(BESLUT-23)*
- **C3 InnehallsKlassService** — 1 av 8 innehållskategorier + flaggor ovanpå 5 kanaltyper. Deterministisk regelkaskad FÖRST (SDK-fält/X-headers 1.0 → avsändartyp → blankett → nyckelord); LLM endast valfritt, avstängbart, människo-bekräftat förslag. Ortogonal mot C2. *(BESLUT-09)*
- **C4 FacksystemCommitService** (+ `IFacksystemConnector`) — committa via Frends; vänta på **verifierad callback** `{hubsCaseId,dnr}`; FÖRST då provenans-flip (`registrerad`), dnr-parning, retention-tagg, frist-spegling. **Bygg `EdiariumConnector` FÖRST** (enklast payload → referens-implementation + test-orakel för tyngre Treserva-modulkonnektorer). Routes `POST /api/v2/treserva/commit`, `GET /api/v2/treserva/receipts`. *Test: callback → flip + retention ENBART efter callback; utebliven callback → ingen flip, ingen gallring.* *(BESLUT-07/25)*
- **C5 ReconciliationJob** (`lib/Core/BackgroundJob/ArendeReconciliationJob.php`) — periodisk drift-kontroll register↔objekt (GAP-056): pekare existerar? taggat objekt har rad? `conversation_ids[]` matchar `sdkmc_itsl_message_tag`? Mönster: `processAllPendingDeletions` driven av `DeleteTagsJob`. *(BESLUT-19)*

### 4.5 STEG D — AGPL-bro ↔ ExApp

**Licensgräns = processgräns.** Bygg STEG B/C adapter-rent i sdkmc-core nu (alt. b); lyft till ExApp (alt. c) = persistens-adapter-byte bakom C:s service-lager.

| Tunn NC-bro-app (AGPL, in-process, "dum") | M4 ExApp (proprietär-kapabel, egen process+DB) |
|---|---|
| `case:{id}`-taggmotorn (taggen bor där objektet bor) | Ärende-motorn: saga R1–R10 + kompensering, single-writer |
| `AppDetectionService` (graceful degradation) | `ArendeTyp`-registry (`commit_destination` NOT NULL) |
| ExApp-registrering (AppAPI) | `ArendeMatchService` (kaskad) |
| OCS-aggregat-routes (`/summary`, `/inflode-summary`, `/fordelning-summary`) | `FacksystemCommitService` + Frends-konnektorfamilj |
| Djuplänkning, autentisering, event-vidarebefordran | **Egen intern DB** (Postgres), opaka pekare, INGA `oc_*`-FK |
| **INGEN affärslogik** | All verksamhetslogik |

**HTTP-kontraktet (arm's length, app-nivå-JSON, INGEN intern-RPC):**
```
NC-bro  POST /case            ▶ ExApp  {arendeTyp, triggerContext:{conversationId, enhet, inkomDatum, kanalTyp}}
                              ◀        {hubsCaseId, steg, fristDue, pointers:{deckCardId,talkToken,…}}  (sagan körd)
NC-bro  POST /case/match      ▶ ExApp  {conversationId, fromAddr, subjectHash, existingTags[]}
                              ◀        {arendekoppling, hubsCaseId?, konfidens, forslag?}
NC-bro  POST /case/{id}/commit▶ ExApp  {arendeTyp, payloadRef}  ◀ {accepted} ▸ async ▸ callback /commit/verified ▶ {hubsCaseId,dnr}
NC-bro  GET  /case/summary    ▶ ExApp  (dashboard-aggregat; INGEN klient-fan-out)
NC-bro  POST /case/{id}/assign▶ ExApp  {uid}  (atomär ACL-omskrivning på NC-sidan triggas av ExApp-svar)
```
> **AGPL-disclaimer:** combined-work-gränsen är en rättsfråga — inget proprietärt beslut utan IP-jurist (BESLUT-02). Bron förblir AGPL (in-process OCP). mail `-only` vs spreed `-or-later` hanteras.

### 4.6 STEG E — Produktionifiera hubs_start

32 Vue-komponenter LIVE; `api.js` har 27 prod-grenar redan skrivna. **Handover = bygg backend bakom seams, rita inte om ytan.**
1. **`PageController::isDemoMode()`** → `demo_mode='0'` (eller AUTO med sdkmc installerad) → `if(DEMO)`-grenarna faller, axios-grenen körs.
2. **Patcha route-prefix (load-bearing):** `api.js:63` `api/v1` → **`api/v2`** för att matcha `routes.php`.
3. **`AppDetectionService::detect()`** utökas kanal → funktions-detektering (+groupfolders, files, contacts, deck).

**Routes prod-grenarna pekar på** (registreras bredvid `itsl_tag#*`, controller `ArendeController` rör aldrig Mapper): `POST /api/v2/arende` · `GET /api/v2/arende/{ref}` · `GET /api/v2/arende-summary` · `GET /api/v2/inflode-summary` · `POST /api/v2/arende/{ref}/tilldela` · `POST /api/v2/inflode/koppla` · `POST /api/v2/treserva/commit` · `GET /api/v2/treserva/receipts` · `GET /api/v2/favoriter`.

**Återanvänds rakt av (ingen ändring):** `api.js` prod-grenar; `store/index.js`-actions (`commitArende`, `inflodeAction`, `loadInflodeSummary`); `arendeFlow.js nastaFor()` (data-driven, värden nu ur `ArendeTyp`); `MinaArenden.vue zonOf()`; `FristChip`/`KopplingBadge`/`FavoritValjare`/`ProcessStepper`. **Enda nya UI:** `KategoriBadge` + ett filtervärde.

### 4.7 Build/CI per komponent + integrationsordning

| Komponent | CI-gates |
|---|---|
| STEG A split | **hela befintliga sdkmc-suiten grön oförändrad** (bevis: beteende-neutral); phpcs/psalm |
| STEG B migration | sqlite+postgres; `commit_destination` NOT NULL avvisar null; 8 seed-rader; rollback-test |
| C0–C5 | grind: noll rad + karantän-audit + retroaktiv komp · saga: fel-injektion → kompensering omvänd ordning + idempotens + graceful-skip · match: 7-signal-fixturer, tröskel ur config · klass: per-kategori, ingen extern LLM i determ.väg · commit: callback → flip ENBART efter callback, EdiariumConnector som referens · recon: divergens → larm/självläkning |
| STEG E frontend | `npm run build` (Vue 2.7, webpack); jest på api.js-grenval (DEMO on/off); `demo_mode='0'` smoke |

**Integrationsordning (lås EN beroendekant i taget):**
```
1. STEG A (split) ▶ deploy 2.3.0 till bygg-instans, befintlig suite grön
2. STEG B (DB) ▶ migration grön, 8 seed-rader
3. C0 + C1 mot ALLA grann-STUBBAR (Deck/Spreed/CalDAV/groupfolders/Frends/Sakerhetsskydd)
4. C2 + C3 mot MessageReceivedEvent-harness
5. C4: EdiariumConnector mot FrendsStub FÖRST ▶ sen Treserva-modulkonnektorer
6. Byt grann-stub → riktig app EN i taget (AppDetectionService styr): groupfolders ▶ Deck ▶ Spreed ▶ CalDAV ▶ Frends-prod (sist, tyngst GAP-019)
7. C5 recon påslaget när ≥2 riktiga grannar live
8. STEG E: api.js DEMO-av + v1→v2, smoke route-för-route
9. STEG D (ExApp-lyft): persistens-adapter-byte bakom C, NC-projektioner oförändrade (licens/modul, sist)
```

---

## 5. Blinda fläckar & tillägg *(PM5)*

> Det operativa/juridiska/process-mässiga "HUR & VARMED" runt de 268 funktionskraven. P1 = blockerar bygge/drift eller skapar irreversibel skada. P2 = krävs före pilot/prod.

### 5.1 P1 — innan en rad kod körs på en riktig instans

| # | Sak | Konsekvens om den saknas |
|---|---|---|
| 1 | **Separat ren bygg-/integrations-instans (ALDRIG dev15)** | bro-app skrivs över vid nästa deploy; risk att korrumpera kunddata; ingen reproducerbar miljö |
| 2 | **ExApp-leveransväg som överlever `itsl deploy`** (AppAPI + Docker-daemon + bro-persistens) | M4 kan inte deployas alls; bron försvinner vid deploy |
| 3 | **Syntetisk testdata-generator + hård PII-spärr** (CI-grind avvisar PII-mönster) | GDPR-/sekretessincident redan i utveckling; demo-stubbar räcker ej för kontrakts-/lasttest |
| 4 | **IP-/AGPL-juristverifiering FÖRE proprietär M4-kod** (mail `-only`, spreed `-or-later`, securemail, §13) | fel snitt → hela M4 måste AGPL-öppnas/skrivas om (irreversibelt affärsmässigt) |
| 5 | **Secrets-/nyckelhantering** (vault, rotation, ExApp-cert, DB-creds, facksystem-API-nycklar) | läckta facksystem-credentials = direkt väg in i kommunens SoR; hårdkodade secrets i AGPL-bro = publicerade |
| 6 | **Säkerhetsskydds-/SUA-review + karantänrutin** (organisatorisk, inte bara kod) | säkerhetsskyddsklassificerat i molnet = lagbrott + nationell säkerhetsrisk |
| 7 | **CI/CD mot cr.itsl.se** (bygg/test/signering/release + itsl-bundle-integration) | ingen reproducerbar/granskningsbar väg commit→artefakt; ingen kontrollerad rollback |
| 8 | **Kontraktstester bro↔ExApp** (app-nivå-API) | bro/M4 driftar isär → tyst API-drift → ärenden tappas mellan bro och motor |
| 9 | **Backup/DR av ExApp-DB + registret** (kartan pekare↔dnr) | förlorad DB = alla pekare bryts; ärenden oåterkalleligt frikopplade från SoR |
| 10 | **Observability** (audit→admin_audit/SIEM, hälsokontroller, metrics/tracing) | tyst ExApp-haveri → inga commits, ingen larmar; ingen åtkomst-audit = spårbarhetsbrott |

### 5.2 P2 — före pilot/prod

| # | Sak | Konsekvens om den saknas |
|---|---|---|
| 11 | **Miljö-promotion bygg→stage→prod per kund** (test mot exakt kundens NC-version 31.0.8.1 ≠ 32) | versionsskew upptäcks i kund-prod |
| 12 | **GDPR/PuB/DPIA + arkivlag/gallring förankrad i DHP per kund** | otillåten behandling/gallring; ingen kund kan signera PuB |
| 13 | **Installation + uppdateringscykel-spårning tredjeparts-appar mot NC-version** | saknad deck → ärende-kort dör tyst; trasig instans efter deploy |
| 14 | **Pentest + hotmodell + NIS2/cybersäkerhetslagen (2025:1506, i kraft 2026-01-15)** | sårbarhet i AppAPI-snittet/ExApp-DB = väg in till kommunens SoR; rapporteringsplikt |
| 15 | **Versionshantering & rollback (bro/ExApp/DB-schema oberoende men kompatibla)** | release-skew bryter kontraktet utan rollback; misslyckad NOT NULL-migration låser registret |

### 5.3 Övrig täckning (urval)
- **CI/CD:** image-signering + Trivy/Grype-scan före push; SBOM per artefakt; frontend-bygge separat om SPA proprietär (ingen `@nextcloud/*`-bundling, verifieras i CI); automatiserad bundle-inbäkning + `itsl config`-persistens.
- **Test/QA:** enhet (saga/kompensering/idempotens) · integration mot riktiga grannar · E2E (treserva.js-tråden mot OCS) · last/volym (registrator-massutlämnande, KC, årsräknings-säsong 1 mars) · säkerhet (GAP-058 ACL-koherens, jäv-exkludering, vissel-isolering) · graceful-degradation per saknad granne · regressionsmatris NC 31.x/32.x.
- **Observability:** distribuerad tracing bro→AppAPI→ExApp→facksystem; metrics (saga-utfall, commit-success per konnektor, frist-överskridanden, recon-drift); **dead-letter/alert när ett ärende varken committas eller avvisas** (fångar den dödligaste felmoden).
- **Data:** seeding ArendeTyp + 8 aclProfiler; migreringsväg (b)→(c) testad som data-migrering; reconciliation mot syntetisk drift.
- **Säkerhet/juridik:** verifiera att opaka pekare verkligen är opaka (ingen verksamhetsdata läcker in → annars blir DB tyst SoR); AGPL §13-driftansvar dokumenterat; per-kund PuB + RoPA.
- **Docs:** runbooks (ExApp-omstart, DB-restore, saga stuck, karantän-rutin, `itsl deploy`-recovery); OpenAPI för `/api/v2/arende*`; onboarding.
- **WCAG 2.1 AA** av hubs_start (lagkrav, DOS-lagen) — alla 12 roller inkl. medborgarvända flöden.
- **Kapacitet:** DB-skalning hög-volym-roller; aggregat-cache; Circles-synk-belastning.
- **Team:** PHP/OCP · Vue 2.7 · DevOps (itsl/Docker/AppAPI) · IP-jurist · domänexpert (förvaltningsrätt/OSL) · informationssäkerhet/säkerhetsskydd · DSO-kontakt · arkivarie.
- **Pilot/rollout:** pilotkund-urval + onboarding-checklista; SLA/incident (inkl. NIS2-rapportering); per-kund facksystem-mappning.
- **Licens/SKU:** M0 obligatorisk · M1 ankare · M2/M3/M4 tillval; per-konnektor-prissättning; licens-spårning i bundle kopplat till AppDetectionService.

**Störst hävstång att lyfta nu:** #1+#2 (utan bygg-instans + deploy-överlevande väg finns ingen plats att bygga något); #4+#6 (de enda *irreversibla* riskerna — fel licenssnitt = omskrivning, säkerhetsskydd i molnet = lagbrott).

---

## 6. Faserad roadmap & milstolpar *(PM6)*

**Grundprincip "byggbart-med-stubbar":** UI:t är LIVE, `api.js` anropar redan routes som inte finns. Bygg backend bakom namngivna seams, ersätt en stub i taget bakom samma kontrakt. Varje fas levererar en körbar e2e-skiva på stubbar, sedan härdas seam för seam.

| Fas | Mål | Byggbart MED stubbar | Kräver RIKTIG integration | DoD-kärna |
|---|---|---|---|---|
| **P0-säk** | Terminerande "föds-inte" + säkerhetsskydds-grind FÖRE känsligt inflöde | preSagaHook-skelett, karantän-UI, isolerings-ACL | ingen (ren grind) | Säkerhetsskydd kan ALDRIG nå registret; karantän + retroaktiv isolering verifierad |
| **P0-infra** | Bygg-instans + AppAPI + ExApp-runtime + intern DB + saknade appar + harness | allt | app_api, Docker-daemon, ExApp Postgres | ExApp deployad, överlever `itsl deploy`, egen DB svarar, appar enable |
| **P0-int** | Registret + atomär createArende-saga + FacksystemCommitService mot harness | createArende e2e | (harness ersätter Treserva) | skapa→commit→verifierad callback→provenans-flip→retention, allt-eller-inget, idempotent |
| **P1** | ArendeTyp-registry + klass/match + 8 kategorier + flaggor; frontend-prod; reconciliation | alla 8 kat | (fortf. harness) | 8 kategorier genom EN motor; match server-side + människo-bekräftad; reconciliation grön |
| **P2** | Per-roll-utrullning (12 roller) + per-kommun-config; riktiga integrationer; Inera; cross-role | nya roller mot harness | Treserva/Lifecare, e-diarium, Inera | ≥1 roll i prod mot RIKTIGT facksystem; PAdES/LTV; jäv-isolering |
| **P3** | Modul-paketering M0–M4 säljbart, multi-tenant, stage→prod | — | per-tenant provisioning, IP-jurist-grönt | M4 säljs separat (M0+M1+M4 min.); multi-tenant isolerad; prod-pipeline |

**P0-SÄK** (grind, inte integration → byggbart utan externa beroenden): `preSagaHook` i `ArendeService` FÖRE R1; `sakerhetsskydd_karantan`-hook (ingen `hubsCaseId` mintas); retroaktiv isolering (river `case:`/`kat:`-taggar); visselblåsning som **NON-route** (`aclProfil='vissel_isolerad'`, opt-out ur match/consolidate/reconcile). *DoD: säkerhetsskyddsklassat mintar ALDRIG hubsCaseId; varje avvisning spårad i `activity`.*

**Blocker → fas-mappning:**
| Blocker | Löses i |
|---|---|
| säkerhetsskydds-grind | **P0-SÄK** |
| GAP-056 (register + reconciliation) | P0-INT (register) + P1 (recon-loop) |
| GAP-019 (commit-konnektor) | P0-INT (mot harness) → P2 (riktig) |
| GAP-057 (atomär saga / ACL-race) | P0-INT (createArende) + P1 (tilldela) |
| GAP-058 (3-lagers-ACL-koherens) | P1 |
| GAP-001 (skyddsbedömnings-tvång) · GAP-007 (commit-bunden gallring) | P0-INT |
| GAP-034 (Inera-AES/LTV) · GAP-031 (retention-paus) | P2 |
| GAP-052 (AI på sekretess) | P2 — förblir dokumenterad röd zon, körs ej skarpt (väntar IMY/SKR/Socialstyrelsen) |
| B-MOD-1 (sdkmc-split) · B-LIC-1 (IP-jurist) | P3 |

**Kritisk väg:** `P0-SÄK → P0-INFRA (app_api ▶ ExApp-runtime ▶ intern DB ▶ appar ▶ harness) → P0-INT (register ▶ saga ▶ commit→harness) → P1 (ArendeTyp ▶ klass/match ▶ 8 kat ▶ recon ▶ ACL-koherens ▶ frontend-prod) → P2 (config-fält ▶ 12 roller ▶ riktiga konnektorer ▶ Inera) → P3 (split ▶ M4 separat ▶ multi-tenant ▶ IP-jurist)`.

### 6.1 Nästa 5 steg (börja direkt)
1. **Sätt upp separat bygg-instans (vanilla NC 31) och installera app_api.** Verifiera att en trivial ExApp kan deployas via Docker-daemonen OCH överlever ett `itsl deploy`. *(Hård gate — inget i P0-infra startar utan den.)*
2. **Provisionera ExApp egen intern DB** (egen Postgres-container ELLER ny DB+role i hubs-postgres) och scaffolda ExApp-skelettet med en QBMapper-migration. *(Realiserar BESLUT-01; hem för registret.)*
3. **Bygg säkerhetsskydds-grinden (P0-SÄK) som preSagaHook-skelett** innan registret-arbete: `karantanKravs=true` → abort före UUID-mint + retroaktiv isolering + visselblåsning-opt-out. Mata syntetiskt säkerhetsskyddsklassat inflöde och bevisa att inget `hubsCaseId` föds.
4. **Produktionifiera harness-stubben till en HTTP-stub-tjänst** som tar emot `commitToFacksystem` och svarar med verifierad callback `{hubsCaseId, dnr}` — generaliserad som e-diarium/e-arkiv-kontrakt (ej hårdkodad Treserva).
5. **Implementera `sdkmc_arende`-registret (rad-shape INTERNALS §1.1.2) + `createArende()`-sagan R1–R10 med kompensering**, single-writer, `commit_destination` NOT NULL, idempotensnyckel `conversationId`. Koppla mot harness-stubben från steg 4; verifiera atomicitet + kompensering + commit-bunden retention. *(Stänger GAP-056/010/057/007 mot stub → första körbara e2e-skivan.)*

---

## 7. Beslut

Alla beslut samlas, motiveras och ratificeras i den fristående **`HUBS-BESLUTSLOGG.md`** (BESLUT-01…25). Sammanfattning av de mest blockerande:

| ID | Område | REKOMMENDATION | Måste fattas |
|---|---|---|---|
| **BESLUT-01** | Datalager | (b) sdkmc-DB NU, schema ExApp-rent, (c) som mål | FÖRST (blockerar allt) |
| **BESLUT-03** | sdkmc M0/M1-split | FÖRE M4-motorn byggs (kod-refaktor, ej data) | P0.1 |
| **BESLUT-04/05** | Appstore + deploy-överlevnad | sidoladda nu / privat registry mål; M4 som ExApp + bro i bundle/itsl config | före install/deploy |
| **BESLUT-06** | Bygg-instans | separat ren instans; dev15 = read-only | NU |
| **BESLUT-07** | Referenskonnektor | Treserva/Lifecare först (generaliserbart), EdiariumConnector som test-orakel | P0.2 |
| **BESLUT-11** | commitDestination | NOT NULL-constraint nu; per-kommun fallback i config | schema/per-kund |
| **BESLUT-13** | Säkerhetsskydd | fail-closed avvisning, terminerande preSagaHook, retroaktiv karantän | P0.4 (lagbrottsgolv) |
| **BESLUT-16** | Testdata | endast syntetisk; CI-grind avvisar PII | NU |
| **BESLUT-21** | Route-prefix | `/api/v2/arende*` (verifierad), patcha api.js v1→v2 | vid bygg |
| **BESLUT-25** | Retention-tröskel | alltid verifierad commit-callback; aldrig manuell kryssruta ensam | P1.2 |

**Lång ledtid — starta NU även om ratificering dröjer:** BESLUT-02 (IP-jurist), BESLUT-08 (Inera-avtal/SITHS-HSA), BESLUT-09 (IMY/SKR/Socialstyrelse-vägledning), BESLUT-20 (bemanning).

Användaren kan ratificera genom att i `HUBS-BESLUTSLOGG.md` svara **"ja till alla rekommendationer"** eller markera avvikelser per rad.
