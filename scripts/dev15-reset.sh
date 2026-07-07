#!/usr/bin/env bash
#
# SPDX-FileCopyrightText: ITSL <info@itsl.se>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# dev15-reset.sh — återställ dev15 till det KÄNDA TESTLÄGET.
#
# Känt läge = 0 ärenden, 0 kvittenser, 0 case-/behandlad-taggar, 0 ärenderum →
# de 2 inkomna orosanmälningarna ligger OTAGGADE i "Att ta emot" i hubs_start,
# redo att triageras från noll.
#
# Idempotent — kör hur många gånger som helst. RÖR INTE setup:
#   - oc_hubs_arende_typ            (ärendetyp-registret)
#   - oc_mail_messages / mailboxes / accounts   (de 2 orosanmälningarna + korgarna)
#   - $assignee_*, $label1          (tilldelnings-/viktighetstaggar)
#   - Deck-boards, Note-to-Self-rum, säkra-möte-rum
#   - groupfolders MED icke-UUID-namn (ev. setup-folders skyddas av UUID-regexen)
#
# RENSAR:
#   - TEAM: per-ärende circles (pekare objekt_typ='team' + namn-fallback
#     'Ärende <UUID>') via `occ circles:manage:destroy` — läses FÖRE SQL-blocket
#     (som raderar pekarna).
#   - oc_hubs_arende_case/pekare/member/flagga   (alla ärenden + koordinations-state)
#     → kvittenser (TreservaKvittens) härleds ur registret, så de försvinner med.
#   - oc_sdkmc_itsl_message_tag + oc_sdkmc_itsl_tag för case:* / behandlad / $dnr_*
#     → meddelandena blir otaggade och dyker upp i "Att ta emot" igen.
#   - ÄRENDERUM: per-ärende groupfolders (mount_point = hubsCaseId UUID), raderas
#     via `occ groupfolders:delete -f` (DB + filsystem) — aldrig blind SQL.
#   - PER-CASE-GRUPPER: NC-grupper 'hubs-case-*' via `occ group:delete` (täpper
#     det tidigare hålet där grupperna läckte som föräldralösa).
#
# LÄMNAR KVAR (osynligt i klienten; pekarna är borta): föräldralösa Talk-diskussions-
#   rum, Deck-kort och kalenderobjekt. Säker post-hoc-radering av dem kräver motorns
#   teardown; säg till om de också ska med.
#
# Användning:
#   scripts/dev15-reset.sh
#   HUBS_SSH="ubuntu@10.43.51.62" scripts/dev15-reset.sh
#
set -euo pipefail

SSH_TARGET="${HUBS_SSH:-ubuntu@10.43.51.62}"
PSQL='sudo docker exec -i hubs-postgres psql -U oc_hubs -d hubs'

echo "→ Återställer dev15 (${SSH_TARGET}) till känt testläge…"

# ── 0) Team (per-ärende circles) — FÖRE SQL:en som raderar pekarna ──────────
# Primärt ur motorns egen bokföring (pekare objekt_typ='team'); fallback på
# namn-konventionen 'Ärende <UUID>' så även föräldralösa team städas.
echo "→ Raderar ärende-team (circles)…"
ssh -o BatchMode=yes -o ConnectTimeout=15 "${SSH_TARGET}" bash -s <<'REMOTE'
UUID_RE='^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
ids=$(sudo docker exec hubs-postgres psql -U oc_hubs -d hubs -t -A -c \
  "SELECT objekt_id FROM oc_hubs_arende_pekare WHERE objekt_typ = 'team'
   UNION
   SELECT unique_id FROM oc_circles_circle
    WHERE name ~ ('^Ärende [0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$');")
n=0
for id in $ids; do
  if sudo docker exec -u www-data hubs-php php /var/www/html/occ circles:manage:destroy "$id" >/dev/null 2>&1; then
    n=$((n + 1))
  fi
done
echo "  ✓ ${n} team raderade."
REMOTE

# ── 1+2) Register + taggar (SQL, atomiskt) ──────────────────────────────────
ssh -o BatchMode=yes -o ConnectTimeout=15 "${SSH_TARGET}" "${PSQL}" <<'SQL'
\set ON_ERROR_STOP on
BEGIN;
-- hubs_arende-registret: alla ärenden + koordinations-state. Behåll _typ.
DELETE FROM oc_hubs_arende_pekare;
DELETE FROM oc_hubs_arende_member;
DELETE FROM oc_hubs_arende_flagga;
DELETE FROM oc_hubs_arende_handelse;
DELETE FROM oc_hubs_arende_case;
-- sdkmc per-meddelande-taggar: case:* / behandlad / $dnr_* (mappningar + per-ärende-defs).
DELETE FROM oc_sdkmc_itsl_message_tag
 WHERE tag_id IN (
   SELECT id FROM oc_sdkmc_itsl_tag
   WHERE imap_label LIKE 'case:%' OR imap_label = 'behandlad' OR imap_label LIKE '$dnr_%'
 );
DELETE FROM oc_sdkmc_itsl_tag
 WHERE imap_label LIKE 'case:%' OR imap_label LIKE '$dnr_%';
COMMIT;
SQL

# ── 3) Ärenderum: per-ärende groupfolders (UUID-namn) via occ (DB + filsystem) ──
echo "→ Raderar ärenderum (groupfolders med UUID-namn)…"
ssh -o BatchMode=yes -o ConnectTimeout=15 "${SSH_TARGET}" bash -s <<'REMOTE'
UUID_RE='^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
ids=$(sudo docker exec hubs-postgres psql -U oc_hubs -d hubs -t -A \
        -c "SELECT folder_id FROM oc_group_folders WHERE mount_point ~ '$UUID_RE';")
n=0
for id in $ids; do
  if sudo docker exec -u www-data hubs-php php /var/www/html/occ groupfolders:delete "$id" -f >/dev/null 2>&1; then
    n=$((n + 1))
  fi
done
echo "  ✓ ${n} ärenderum raderade."
REMOTE

# ── 4) Per-case-grupper: NC-grupper 'hubs-case-*' via occ ───────────────────
echo "→ Raderar per-case-grupper (hubs-case-*)…"
ssh -o BatchMode=yes -o ConnectTimeout=15 "${SSH_TARGET}" bash -s <<'REMOTE'
gids=$(sudo docker exec hubs-postgres psql -U oc_hubs -d hubs -t -A \
        -c "SELECT gid FROM oc_groups WHERE gid LIKE 'hubs-case-%';")
n=0
for gid in $gids; do
  if sudo docker exec -u www-data hubs-php php /var/www/html/occ group:delete "$gid" >/dev/null 2>&1; then
    n=$((n + 1))
  fi
done
echo "  ✓ ${n} per-case-grupper raderade."
REMOTE

# ── Verifiering av känt läge ────────────────────────────────────────────────
ssh -o BatchMode=yes -o ConnectTimeout=15 "${SSH_TARGET}" "${PSQL}" <<'SQL'
\echo ''
\echo '=== KÄNT LÄGE (förväntat: arenden=0, pekare=0, medlemmar=0, hanterade_taggar=0, arenderum=0, team=0, case_grupper=0, inbox>=2) ==='
SELECT
  (SELECT count(*) FROM oc_hubs_arende_case)   AS arenden,
  (SELECT count(*) FROM oc_hubs_arende_pekare)  AS pekare,
  (SELECT count(*) FROM oc_hubs_arende_member)  AS medlemmar,
  (SELECT count(*) FROM oc_sdkmc_itsl_message_tag mmt
     JOIN oc_sdkmc_itsl_tag mt ON mmt.tag_id = mt.id
     WHERE mt.imap_label LIKE 'case:%' OR mt.imap_label = 'behandlad') AS hanterade_taggar,
  (SELECT count(*) FROM oc_group_folders
     WHERE mount_point ~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$') AS arenderum,
  (SELECT count(*) FROM oc_circles_circle
     WHERE name ~ '^Ärende [0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$') AS team,
  (SELECT count(*) FROM oc_groups WHERE gid LIKE 'hubs-case-%') AS case_grupper,
  (SELECT count(*) FROM oc_mail_messages m
     JOIN oc_mail_mailboxes mb ON m.mailbox_id = mb.id
     WHERE lower(mb.name) = 'inbox')           AS inbox_meddelanden;
SQL

echo "✓ Klart. Ladda om hubs_start → de 2 orosanmälningarna ligger i 'Att ta emot'."
