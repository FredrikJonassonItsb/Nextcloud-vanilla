#!/usr/bin/env bash
#
# SPDX-FileCopyrightText: ITSL <info@itsl.se>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# ============================================================================
# hubs_arende — IDEMPOTENT PROVISIONERING (bootstrap.sh)
# ============================================================================
# Återställer ALLT vi lägger till ovanpå en ren/återställd dev15 (NC 31.0.8).
# Körs via SSH mot dev15 ELLER på bygg-host. Varje steg (a)–(i) är idempotent
# och loggande: kan köras om hur många gånger som helst utan att skada något.
#
# KÖR:
#   ./bootstrap.sh                 # full provisionering (alla steg a–i)
#   ./bootstrap.sh --dry-run       # visa vad som skulle göras, ändra INGET
#   ./bootstrap.sh --only c,d,g    # kör bara valda steg (beroenden ej kollade)
#   ./bootstrap.sh --rollback-appstore   # nödåterställning av appstore-policy
#
# MILJÖVARIABLER (med VERIFIERADE defaults för dev15 2026-06-17):
#   APP_SRC                  sökväg till hubs_arende-koden (default: ../ rel. skript)
#   SIDELOAD_ONLY=1          1 (default) = hoppa appstore-toggle, tarball->custom_apps direkt
#                            (appstore oåtkomlig på dev15 [WAF]; github=200). 0 = tillåt appstore.
#   INSTALL_LIBRESIGN=0      0 (default) = hoppa libresign (BLOCKERAD: java saknas i hubs-php;
#                            SigneringPort-stub räcker, Inera = beslutad backend). 1 = försök ändå.
#   REGISTER_DAEMON=0        0 (default) = hoppa app_api deploy-daemon (BLOCKERAD: docker.sock ej
#                            monterad i hubs-php; in-process v1). 1 = försök registrera (ExApp-fas).
#   INSTALL_APPAPI=0         0 (default) = hoppa app_api (3.2.0 INKOMPATIBEL m. ITSL NC31 — ExApps-sida
#                            kraschar OC_Util::getChannel). 1 = installera (ExApp-fas, NC31-verifierad version).
#   PROVISION_DEDICATED_DB=0 1 = [DESTRUKTIV] skapa separat pg-role/DB (ExApp-fas)
#   HUBS_NET                 docker-nät för deploy-daemon (auto-detekteras: initiator_hubs)
#   DEDICATED_DB_PASSWORD    krävs om PROVISION_DEDICATED_DB=1 (ur vault, ALDRIG hårdkoda)
#
# DESTRUKTIVA STEG ÄR TYDLIGT MÄRKTA MED:  ###### DESTRUKTIVT ######
# (Inga destruktiva steg körs by default. Bara PROVISION_DEDICATED_DB=1 är det.)
# ============================================================================

set -euo pipefail

# ----------------------------------------------------------------------------
# Konfiguration / konstanter
# ----------------------------------------------------------------------------
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

APP_ID="hubs_arende"
PHP_CONTAINER="hubs-php"
PG_CONTAINER="hubs-postgres"
CUSTOM_APPS="/var/www/html/custom_apps"
STATE_DIR="/opt/project_data/${APP_ID}"
ROLLBACK_DIR="${STATE_DIR}/rollback"
TARBALL_DIR="${STATE_DIR}/tarballs"
LOG_FILE="${STATE_DIR}/provision.log"

APP_SRC="${APP_SRC:-$(cd "${SCRIPT_DIR}/.." && pwd)}"   # hubs_arende/ rot
SIDELOAD_ONLY="${SIDELOAD_ONLY:-1}"        # DEFAULT 1: appstore oåtkomlig på dev15; vi tarball-laddar ändå
INSTALL_LIBRESIGN="${INSTALL_LIBRESIGN:-0}" # DEFAULT 0: BLOCKERAD-PREREQ java saknas; stub räcker (Inera = riktig backend)
REGISTER_DAEMON="${REGISTER_DAEMON:-0}"     # DEFAULT 0: BLOCKERAD-PREREQ docker.sock ej monterad; ExApp-fas
INSTALL_APPAPI="${INSTALL_APPAPI:-0}"       # DEFAULT 0: app_api 3.2.0 INKOMPATIBEL m. ITSL NC31 (ExApps-sida kraschar: OC_Util::getChannel saknas). ExApp-fas.
PROVISION_DEDICATED_DB="${PROVISION_DEDICATED_DB:-0}"
DRY_RUN=0
ONLY_STEPS=""

TS="$(date +%Y%m%d-%H%M%S)"

# Apptarballar — VERIFIERADE installerade+enabled på dev15 (NC31) 2026-06-17.
# Format: "appid|version|url|sha256(valfri, 'skip' = hoppa hash)"
# Synk dessa med manifest.yaml. install_tarball_app curlar github DIREKT (appstore
# oåtkomlig från dev15 [WAF]; github=200 verifierat) → ingen appstore-toggle behövs.
declare -a MISSING_APPS=(
  "deck|1.15.9|https://github.com/nextcloud-releases/deck/releases/download/v1.15.9/deck-v1.15.9.tar.gz|skip"
  "tasks|0.17.1|https://github.com/nextcloud-releases/tasks/releases/download/v0.17.1/tasks.tar.gz|skip"
  "forms|5.2.9|https://github.com/nextcloud-releases/forms/releases/download/v5.2.9/forms-v5.2.9.tar.gz|skip"
  "notes|5.0.1|https://github.com/nextcloud-releases/notes/releases/download/v5.0.1/notes.tar.gz|skip"
)
# libresign: BLOCKERAD-PREREQ (java saknas i hubs-php, verifierat 2026-06-17) → default AV.
# Version nedan är OVERIFIERAD (slå upp NC31-kompatibel tagg vid behov). SigneringPort-stub
# räcker i v1; Inera Underskriftstjänst är beslutad riktig backend (EJ libresign).
LIBRESIGN_SPEC="libresign|11.0.4|https://github.com/LibreSign/libresign/releases/download/v11.0.4/libresign.tar.gz|skip"
# app_api: NC31-spåret är 3.x (4.x = NC32+). 3.2.0 verifierad enabled på dev15.
APPAPI_SPEC="app_api|3.2.0|https://github.com/nextcloud-releases/app_api/releases/download/v3.2.0/app_api.tar.gz|skip"

NC_MIN_REQUIRED=31   # dev15 kör NC 31 — varje apptarball måste stödja >= detta

# ----------------------------------------------------------------------------
# Argument
# ----------------------------------------------------------------------------
while [[ $# -gt 0 ]]; do
  case "$1" in
    --dry-run) DRY_RUN=1; shift ;;
    --only)    ONLY_STEPS="$2"; shift 2 ;;
    --rollback-appstore) ROLLBACK_APPSTORE_ONLY=1; shift ;;
    -h|--help) sed -n '2,40p' "${BASH_SOURCE[0]}"; exit 0 ;;
    *) echo "Okänt argument: $1" >&2; exit 2 ;;
  esac
done

# ----------------------------------------------------------------------------
# Loggning
# ----------------------------------------------------------------------------
mkdir -p "${STATE_DIR}" "${ROLLBACK_DIR}" "${TARBALL_DIR}" 2>/dev/null || \
  sudo mkdir -p "${STATE_DIR}" "${ROLLBACK_DIR}" "${TARBALL_DIR}"

log()  { printf '[%s] %s\n' "$(date +%H:%M:%S)" "$*" | tee -a "${LOG_FILE}"; }
warn() { printf '[%s] WARN: %s\n' "$(date +%H:%M:%S)" "$*" | tee -a "${LOG_FILE}" >&2; }
die()  { printf '[%s] FEL: %s\n' "$(date +%H:%M:%S)" "$*" | tee -a "${LOG_FILE}" >&2; exit 1; }

run() {
  # Loggar och kör (eller bara loggar vid --dry-run).
  log "  \$ $*"
  if [[ "${DRY_RUN}" -eq 1 ]]; then return 0; fi
  "$@"
}

step_enabled() {
  # Returnerar 0 om steget ska köras (alla om --only saknas).
  local s="$1"
  [[ -z "${ONLY_STEPS}" ]] && return 0
  [[ ",${ONLY_STEPS}," == *",${s},"* ]]
}

# ----------------------------------------------------------------------------
# occ-wrapper: sudo docker exec -u www-data hubs-php php /var/www/html/occ <cmd>
# ----------------------------------------------------------------------------
occ() {
  log "  occ $*"
  if [[ "${DRY_RUN}" -eq 1 ]]; then return 0; fi
  sudo docker exec -u www-data "${PHP_CONTAINER}" php /var/www/html/occ "$@"
}
# occ_q = tyst occ för läsfrågor (fångar utdata, loggar ej kommandot dubbelt)
occ_q() {
  sudo docker exec -u www-data "${PHP_CONTAINER}" php /var/www/html/occ "$@"
}

app_enabled() { occ_q app:list 2>/dev/null | sed -n '/Enabled:/,/Disabled:/p' | grep -qiE "^\s*- ${1}\b"; }
app_present() { occ_q app:list 2>/dev/null | grep -qiE "^\s*- ${1}\b"; }

# ============================================================================
# STEG (a)  — SNAPSHOT KONFIG FÖRE  (rollback-ref). Icke-destruktivt.
# ============================================================================
step_a_snapshot() {
  log "=== (a) Snapshot konfig FÖRE (rollback-referens) ==="
  local cfg="${ROLLBACK_DIR}/config-before-${TS}.json"
  local apps="${ROLLBACK_DIR}/applist-before-${TS}.txt"
  local astore="${ROLLBACK_DIR}/appstoreenabled-before-${TS}.txt"

  if [[ "${DRY_RUN}" -eq 1 ]]; then log "  (dry-run) skulle skriva ${cfg}, ${apps}, ${astore}"; return 0; fi

  occ_q config:list --private  > "${cfg}"   2>/dev/null || warn "kunde ej dumpa config:list"
  occ_q app:list               > "${apps}"  2>/dev/null || warn "kunde ej dumpa app:list"
  # Spara EXAKT nuvarande appstore-policy så (i) kan återställa till samma värde.
  occ_q config:system:get appstoreenabled > "${astore}" 2>/dev/null || echo "false" > "${astore}"
  ln -sf "$(basename "${astore}")" "${ROLLBACK_DIR}/appstoreenabled-LATEST.txt"
  log "  Rollback-ref sparad i ${ROLLBACK_DIR} (appstore-policy: $(cat "${astore}"))"
}

# ============================================================================
# STEG (b)  — SLÅ PÅ APPSTORE TILLFÄLLIGT  (eller sidoladdningsläge).
# ============================================================================
step_b_appstore_on() {
  log "=== (b) Appstore PÅ (tillfälligt) ==="
  if [[ "${SIDELOAD_ONLY}" -eq 1 ]]; then
    log "  SIDELOAD_ONLY=1 -> hoppar appstore-toggle, installerar bara via tarball->custom_apps"
    return 0
  fi
  # Idempotent: sätt true oavsett tidigare värde (tidigare värde redan sparat i a).
  occ config:system:set appstoreenabled --value true --type boolean
  log "  appstoreenabled = true (återställs i steg i)"
}

# ============================================================================
# STEG (c)  — AKTIVERA INBYGGD APP: contacts. Idempotent.
# ============================================================================
step_c_enable_contacts() {
  log "=== (c) occ app:enable contacts ==="
  if app_enabled contacts; then
    log "  contacts redan enabled -> no-op"
  else
    occ app:enable contacts
  fi
}

# ============================================================================
# Hjälpare: installera en signerad tarball-app idempotent + NC-kompat-vakt.
# ============================================================================
verify_nc_compat() {
  # Läser custom_apps/<app>/appinfo/info.xml i containern och säkerställer
  # max-version >= NC_MIN_REQUIRED. Stoppar (die) om inkompatibel.
  local app="$1"
  local maxv
  maxv="$(sudo docker exec -u www-data "${PHP_CONTAINER}" sh -c \
      "grep -oE 'max-version=\"[0-9]+' ${CUSTOM_APPS}/${app}/appinfo/info.xml | grep -oE '[0-9]+' | head -1" 2>/dev/null || echo '')"
  local minv
  minv="$(sudo docker exec -u www-data "${PHP_CONTAINER}" sh -c \
      "grep -oE 'min-version=\"[0-9]+' ${CUSTOM_APPS}/${app}/appinfo/info.xml | grep -oE '[0-9]+' | head -1" 2>/dev/null || echo '')"
  log "  ${app}: info.xml nextcloud min=${minv:-?} max=${maxv:-?} (kräver max>=${NC_MIN_REQUIRED})"
  if [[ -n "${maxv}" && "${maxv}" -lt "${NC_MIN_REQUIRED}" ]]; then
    die "${app} stödjer bara NC <= ${maxv}, men dev15 kör NC ${NC_MIN_REQUIRED}. Uppdatera pinnad version i manifest.yaml + bootstrap.sh."
  fi
}

install_tarball_app() {
  # install_tarball_app <appid> <version> <url> <sha256|skip>
  local app="$1" ver="$2" url="$3" sha="$4"
  log "--- installerar ${app} v${ver} ---"

  if app_present "${app}"; then
    log "  ${app} redan present i custom_apps."
  else
    local tb="${TARBALL_DIR}/${app}-${ver}.tar.gz"
    if [[ -f "${tb}" ]]; then
      log "  tarball redan nedladdad: ${tb}"
    else
      run curl -fsSL --retry 3 -o "${tb}" "${url}"
    fi

    if [[ "${sha}" != "skip" && "${DRY_RUN}" -eq 0 ]]; then
      echo "${sha}  ${tb}" | sha256sum -c - || die "${app}: SHA256 stämmer ej — ABORT (möjlig manipulerad tarball)."
      log "  SHA256 verifierad för ${app}."
    elif [[ "${sha}" == "skip" ]]; then
      warn "  ${app}: SHA256 hoppas över (sha=skip). Fyll i hash i manifest.yaml för full signaturverifiering."
    fi

    # Packa upp DIREKT i containerns custom_apps (atomiskt via temp-dir).
    if [[ "${DRY_RUN}" -eq 0 ]]; then
      sudo docker cp "${tb}" "${PHP_CONTAINER}:/tmp/${app}-${ver}.tar.gz"
      sudo docker exec -u www-data "${PHP_CONTAINER}" sh -c \
        "rm -rf ${CUSTOM_APPS}/${app}.new && mkdir -p ${CUSTOM_APPS}/${app}.new && \
         tar -xzf /tmp/${app}-${ver}.tar.gz -C ${CUSTOM_APPS}/${app}.new --strip-components=0 && \
         mv ${CUSTOM_APPS}/${app}.new/${app} ${CUSTOM_APPS}/${app} && \
         rm -rf ${CUSTOM_APPS}/${app}.new /tmp/${app}-${ver}.tar.gz"
    fi
    log "  ${app} uppackad i ${CUSTOM_APPS}/${app}"
  fi

  # NC31-kompat-vakt INNAN enable.
  [[ "${DRY_RUN}" -eq 0 ]] && verify_nc_compat "${app}"

  if app_enabled "${app}"; then
    log "  ${app} redan enabled -> no-op"
  else
    occ app:enable "${app}"
  fi
}

# ============================================================================
# STEG (d)  — INSTALLERA SAKNADE APPAR (signerade tarballar -> custom_apps).
# ============================================================================
step_d_install_tarball_app() { install_tarball_app "$@"; }

step_d_missing_apps() {
  log "=== (d) Installera saknade appar (deck, tasks, forms, notes[, libresign]) ==="
  local spec app ver url sha
  for spec in "${MISSING_APPS[@]}"; do
    IFS='|' read -r app ver url sha <<<"${spec}"
    install_tarball_app "${app}" "${ver}" "${url}" "${sha}"
  done

  if [[ "${INSTALL_LIBRESIGN}" -eq 1 ]]; then
    IFS='|' read -r app ver url sha <<<"${LIBRESIGN_SPEC}"
    install_tarball_app "${app}" "${ver}" "${url}" "${sha}"
    step_d_post_libresign
  else
    warn "  INSTALL_LIBRESIGN=0 -> hoppar libresign (host saknar utgående nät?)."
  fi
}

step_d_post_libresign() {
  # LibreSign behöver binärer (JSignPdf m.fl.) som kräver JAVA i hubs-php.
  log "--- libresign: säkerställ binärer (occ libresign:install --all) ---"
  # PREREQ-GATE: java måste finnas i containern (verifierat saknad 2026-06-17).
  if [[ "${DRY_RUN}" -eq 0 ]] && ! sudo docker exec "${PHP_CONTAINER}" sh -c 'command -v java' >/dev/null 2>&1; then
    warn "  BLOCKERAD-PREREQ: java saknas i ${PHP_CONTAINER} -> libresign:install (JSignPdf) skulle fela."
    warn "  Hoppar. Lägg java i hubs-php-imagen (image-/compose-ändring) innan libresign aktiveras."
    return 0
  fi
  if occ_q libresign:configure:check >/dev/null 2>&1; then
    log "  libresign redan konfigurerad -> no-op"
  else
    occ libresign:install --all || warn "libresign:install misslyckades (nät? disk? java?) — kör manuellt senare."
  fi
}

# ============================================================================
# STEG (e)  — AppAPI + DOCKER DEPLOY-DAEMON mot /var/run/docker.sock.
# ============================================================================
step_e_install_appapi() {
  log "=== (e1) Installera app_api (AppAPI) ==="
  # PREREQ-GATE: app_api 3.2.0 är INKOMPATIBEL med ITSL:s NC 31.0.8.1-build —
  # ExApps-admin-sidan (/apps/app_api/apps) kraschar med "Call to undefined method
  # OC_Util::getChannel()" (verifierat 2026-06-17). app_api behövs först i ExApp-
  # paketeringsfasen; tills en NC31-verifierad version hittas hålls den AV.
  if [[ "${INSTALL_APPAPI}" -ne 1 ]]; then
    log "  INSTALL_APPAPI=0 (default) -> hoppar app_api (3.2.0 trasig på denna NC-build; ExApp-fas)."
    return 0
  fi
  local app ver url sha
  IFS='|' read -r app ver url sha <<<"${APPAPI_SPEC}"
  install_tarball_app "${app}" "${ver}" "${url}" "${sha}"
}

step_e_register_daemon() {
  log "=== (e2) Registrera Docker deploy-daemon (unix-socket /var/run/docker.sock) ==="
  local daemon_name="hubs_docker"

  # PREREQ-GATE 1: avstängt by default (in-process v1; ExApp-fas senare).
  if [[ "${REGISTER_DAEMON}" -ne 1 ]]; then
    log "  REGISTER_DAEMON=0 (default) -> hoppar daemon-registrering. BESLUT: hubs_arende kör"
    log "  in-process i v1; daemon behövs först i ExApp-paketeringsfasen. Sätt REGISTER_DAEMON=1 då."
    return 0
  fi
  # PREREQ-GATE 2: docker.sock MÅSTE vara monterad i hubs-php (verifierat saknad 2026-06-17).
  if [[ "${DRY_RUN}" -eq 0 ]]; then
    if ! sudo docker exec "${PHP_CONTAINER}" test -S /var/run/docker.sock 2>/dev/null; then
      warn "  BLOCKERAD-PREREQ: /var/run/docker.sock är INTE monterad i ${PHP_CONTAINER}."
      warn "  Daemon-registrering hoppas. Montera docker.sock i initiator/docker-compose.yml"
      warn "  (en itsl-deploy-/runtime-ändring) + ge containern grupp 'docker' INNAN du kör med REGISTER_DAEMON=1."
      return 0
    fi
  fi

  # Auto-detektera hubs-nätet om HUBS_NET inte satt.
  local net="${HUBS_NET:-}"
  if [[ -z "${net}" && "${DRY_RUN}" -eq 0 ]]; then
    net="$(sudo docker inspect -f '{{range $k,$v := .NetworkSettings.Networks}}{{$k}}{{end}}' "${PHP_CONTAINER}" 2>/dev/null | head -1)"
    log "  auto-detekterat docker-nät: ${net:-<okänt>}"
  fi

  # Idempotent: registrera bara om daemon saknas.
  if occ_q app_api:daemon:list 2>/dev/null | grep -q "${daemon_name}"; then
    log "  deploy-daemon '${daemon_name}' redan registrerad -> no-op"
  else
    occ app_api:daemon:register \
      "${daemon_name}" "Hubs Docker (lokal socket)" "docker-install" \
      "unix-socket" "/var/run/docker.sock" "http://localhost" \
      ${net:+--net "${net}"} \
      || warn "daemon-registrering misslyckades. Kontrollera att ${PHP_CONTAINER} har åtkomst till /var/run/docker.sock (grupp 'docker')."
  fi

  log "  TIPS: testa med 'occ app_api:daemon:check ${daemon_name}'"
}

# ============================================================================
# STEG (f)  — PROVISIONERA hubs_arende DB.
#   DEFAULT (in-process v1): app-migration via occ (idempotent, hasTable-vakt).
#   OPTION (ExApp-fas):      separat pg-role/DB  ###### DESTRUKTIVT ######
# ============================================================================
step_f_provision_db() {
  log "=== (f) Provisionera hubs_arende-databas ==="

  if [[ "${PROVISION_DEDICATED_DB}" -eq 1 ]]; then
    ###### DESTRUKTIVT ######
    log "  PROVISION_DEDICATED_DB=1 -> separat pg-role/DB i ${PG_CONTAINER} (ExApp-fas)"
    [[ -z "${DEDICATED_DB_PASSWORD:-}" ]] && die "DEDICATED_DB_PASSWORD måste sättas (ur vault) för separat DB."
    if [[ "${DRY_RUN}" -eq 0 ]]; then
      # Idempotent-vakt: skapa bara om DB inte finns (skriver INTE över befintlig data).
      local exists
      exists="$(sudo docker exec -u postgres "${PG_CONTAINER}" psql -tAc \
        "SELECT 1 FROM pg_database WHERE datname='hubs_arende'" 2>/dev/null || echo '')"
      if [[ "${exists}" == "1" ]]; then
        log "  DB 'hubs_arende' finns redan -> no-op (ingen DROP)."
      else
        log "  Skapar ROLE + DATABASE hubs_arende (DESTRUKTIVT vid namnkollision avstängt via vakt)."
        sudo docker exec -u postgres "${PG_CONTAINER}" psql -v ON_ERROR_STOP=1 <<SQL
DO \$\$ BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname='hubs_arende') THEN
    CREATE ROLE hubs_arende LOGIN PASSWORD '${DEDICATED_DB_PASSWORD}';
  END IF;
END \$\$;
CREATE DATABASE hubs_arende OWNER hubs_arende;
SQL
      fi
    fi
    warn "  ExApp-DB skapad. App-migrationerna måste pekas mot denna DB i ExApp-config — EJ default-vägen i v1."
  else
    # DEFAULT v1: tabellerna skapas av appens egna migrationer vid app:enable/upgrade.
    log "  DEFAULT (in-process v1): hubs_arende-tabeller skapas via app-migrationer i steg (g)/(occ upgrade)."
    log "  Tabeller: hubs_arende_case, hubs_arende_typ, hubs_arende_flagga, hubs_arende_pekare (commit_destination NOT NULL)."
    log "  Inget separat DB-steg krävs — icke-destruktivt."
  fi
}

# ============================================================================
# STEG (g)  — DEPLOYA + AKTIVERA hubs_arende (kod -> custom_apps -> enable).
# ============================================================================
step_g_deploy_app() {
  log "=== (g) Deploya hubs_arende-koden + occ app:enable ==="

  [[ ! -d "${APP_SRC}/appinfo" ]] && warn "APP_SRC=${APP_SRC} saknar appinfo/ — är koden byggd? (fortsätter ändå)"

  if [[ "${DRY_RUN}" -eq 0 ]]; then
    # Tar-pipe koden in i containern (exkludera utvecklingsskräp). Atomiskt via .new.
    log "  Synkar ${APP_SRC} -> ${PHP_CONTAINER}:${CUSTOM_APPS}/${APP_ID}"
    tar -C "${APP_SRC}" \
        --exclude='.git' --exclude='node_modules' --exclude='tests' \
        --exclude='provision' --exclude='*.zip' \
        -czf - . | \
      sudo docker exec -i -u www-data "${PHP_CONTAINER}" sh -c \
        "rm -rf ${CUSTOM_APPS}/${APP_ID}.new && mkdir -p ${CUSTOM_APPS}/${APP_ID}.new && \
         tar -xzf - -C ${CUSTOM_APPS}/${APP_ID}.new && \
         rm -rf ${CUSTOM_APPS}/${APP_ID} && mv ${CUSTOM_APPS}/${APP_ID}.new ${CUSTOM_APPS}/${APP_ID}"
    # chown (kör som root för att garantera ägarskap)
    sudo docker exec "${PHP_CONTAINER}" chown -R www-data:www-data "${CUSTOM_APPS}/${APP_ID}" || true
  else
    log "  (dry-run) skulle tar-pipe ${APP_SRC} -> ${CUSTOM_APPS}/${APP_ID}"
  fi

  # Enable + kör migrationer. Idempotent.
  if app_enabled "${APP_ID}"; then
    log "  ${APP_ID} redan enabled -> kör 'occ upgrade' för ev. nya migrationer"
  else
    occ app:enable "${APP_ID}"
  fi
  occ upgrade || log "  occ upgrade: inget att uppgradera (ok)"
}

# ============================================================================
# STEG (h)  — maintenance:repair + VERIFIERA.
# ============================================================================
step_h_repair_verify() {
  log "=== (h) maintenance:repair + verifiering ==="
  occ maintenance:repair

  log "  --- VERIFIERING ---"
  local ok=1
  # OBS: app_api är AVSIKTLIGT inte i denna lista — 3.2.0 är inkompatibel med ITSL
  # NC31 (ExApps-sida kraschar) och hålls disabled tills ExApp-fasen (INSTALL_APPAPI=1).
  for a in contacts deck tasks forms notes "${APP_ID}"; do
    if app_enabled "${a}"; then
      log "  [OK]   ${a} enabled"
    else
      warn "  [SAKNAS] ${a} EJ enabled"; ok=0
    fi
  done
  if [[ "${INSTALL_LIBRESIGN}" -eq 1 ]]; then
    app_enabled libresign && log "  [OK]   libresign enabled" || { warn "  [SAKNAS] libresign EJ enabled"; ok=0; }
  fi

  # Verifiera hubs_arende-tabeller (commit_destination NOT NULL).
  # OBS: DB-namnet på dev15 är 'hubs' (EJ 'nextcloud') och role 'oc_hubs' — härled via occ för robusthet.
  if [[ "${DRY_RUN}" -eq 0 ]]; then
    local dbname dbuser
    dbname="$(occ_q config:system:get dbname 2>/dev/null | tr -d '[:space:]')"; dbname="${dbname:-hubs}"
    dbuser="$(occ_q config:system:get dbuser 2>/dev/null | tr -d '[:space:]')"; dbuser="${dbuser:-oc_hubs}"
    log "  (DB=${dbname} role=${dbuser})"
    if sudo docker exec "${PG_CONTAINER}" psql -U "${dbuser}" -d "${dbname}" -tAc \
         "SELECT 1 FROM information_schema.tables WHERE table_name='oc_hubs_arende_case'" 2>/dev/null | grep -q 1; then
      log "  [OK]   tabell oc_hubs_arende_case finns"
      # Bekräfta den kritiska invarianten: commit_destination NOT NULL.
      if sudo docker exec "${PG_CONTAINER}" psql -U "${dbuser}" -d "${dbname}" -tAc \
           "SELECT is_nullable FROM information_schema.columns WHERE table_name='oc_hubs_arende_case' AND column_name='commit_destination'" 2>/dev/null | grep -qi '^NO'; then
        log "  [OK]   invariant: commit_destination NOT NULL aktiv"
      else
        warn "  [VARNING] commit_destination tycks NULLABLE — never-SoR-invarianten ej upprätthållen i schemat!"; ok=0
      fi
    else
      warn "  [SAKNAS] oc_hubs_arende_case — migrationen kördes inte? (kontrollera occ upgrade)"; ok=0
    fi
  fi

  occ status || warn "occ status returnerade fel"
  [[ "${ok}" -eq 1 ]] && log "  VERIFIERING: ALLT OK" || warn "  VERIFIERING: vissa artefakter saknas (se ovan)"
}

# ============================================================================
# STEG (i)  — SLÅ AV APPSTORE IGEN (återställ policy till sparat värde).
# ============================================================================
step_i_appstore_off() {
  log "=== (i) Återställ appstore-policy ==="
  if [[ "${SIDELOAD_ONLY}" -eq 1 ]]; then
    log "  SIDELOAD_ONLY=1 -> rörde aldrig appstore, inget att återställa"
    return 0
  fi
  local prev="false"
  local f="${ROLLBACK_DIR}/appstoreenabled-LATEST.txt"
  [[ -f "${f}" ]] && prev="$(cat "${f}" | tr -d '[:space:]')"
  [[ -z "${prev}" ]] && prev="false"
  log "  Återställer appstoreenabled -> ${prev} (värde FÖRE provisionering)"
  occ config:system:set appstoreenabled --value "${prev}" --type boolean
}

# ============================================================================
# Nödläge: bara återställ appstore-policy (om ett tidigare run avbröts i mitten)
# ============================================================================
if [[ "${ROLLBACK_APPSTORE_ONLY:-0}" -eq 1 ]]; then
  log "### NÖDÅTERSTÄLLNING: enbart appstore-policy ###"
  step_i_appstore_off
  exit 0
fi

# ============================================================================
# HUVUDFLÖDE
# ============================================================================
main() {
  log "############################################################"
  log "# hubs_arende provisionering START  (ts=${TS}, dry_run=${DRY_RUN})"
  log "#   APP_SRC=${APP_SRC}"
  log "#   SIDELOAD_ONLY=${SIDELOAD_ONLY} INSTALL_LIBRESIGN=${INSTALL_LIBRESIGN} PROVISION_DEDICATED_DB=${PROVISION_DEDICATED_DB}"
  log "############################################################"

  # Förutsättning: php-containern måste finnas.
  if [[ "${DRY_RUN}" -eq 0 ]]; then
    sudo docker inspect "${PHP_CONTAINER}" >/dev/null 2>&1 || die "Container ${PHP_CONTAINER} hittas inte. Kör på dev15/bygg-host med hubs-stacken igång."
  fi

  step_enabled a && step_a_snapshot
  step_enabled b && step_b_appstore_on
  step_enabled c && step_c_enable_contacts
  step_enabled d && step_d_missing_apps
  step_enabled e && { step_e_install_appapi; step_e_register_daemon; }
  step_enabled f && step_f_provision_db
  step_enabled g && step_g_deploy_app
  step_enabled h && step_h_repair_verify
  step_enabled i && step_i_appstore_off

  log "############################################################"
  log "# hubs_arende provisionering KLAR. Logg: ${LOG_FILE}"
  log "############################################################"
}

main "$@"
