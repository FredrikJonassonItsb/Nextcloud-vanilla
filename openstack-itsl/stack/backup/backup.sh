#!/bin/bash
# backup.sh — pg_dump every stack database to /backups, rotate after RETENTION_DAYS.
# Runs from cron (03:30 Europe/Stockholm) inside the backup container.
# Env: PGHOST, PGUSER, PGPASSWORD (from compose), RETENTION_DAYS (default 14).
set -euo pipefail

: "${PGHOST:=brain-db}"
: "${PGUSER:=postgres}"
: "${RETENTION_DAYS:=14}"

DBS="brain_reb brain_atlas brain_ada brain_marvin brain_team engine_meta"
STAMP="$(date +%Y-%m-%d_%H%M)"
DEST=/backups

echo "[backup] start ${STAMP}"
fail=0
for db in $DBS; do
  out="${DEST}/${db}_${STAMP}.dump"
  if pg_dump -Fc --no-owner -f "$out" "$db"; then
    echo "[backup] ok  ${out} ($(du -h "$out" | cut -f1))"
  else
    echo "[backup] FAIL ${db}" >&2
    rm -f "$out"
    fail=1
  fi
done

# rotation: keep RETENTION_DAYS days
find "$DEST" -name '*.dump' -mtime "+${RETENTION_DAYS}" -delete

echo "[backup] done (fail=${fail})"
exit "$fail"
