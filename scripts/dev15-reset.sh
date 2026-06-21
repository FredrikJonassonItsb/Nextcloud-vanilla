#!/usr/bin/env bash
#
# SPDX-FileCopyrightText: ITSL <info@itsl.se>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# dev15-reset.sh — återställ dev15 till det KÄNDA TESTLÄGET.
#
# Känt läge = 0 ärenden, 0 kvittenser, 0 case-/behandlad-taggar → de 2 inkomna
# orosanmälningarna ligger OTAGGADE i "Att ta emot" i hubs_start, redo att triageras.
#
# Idempotent — kör hur många gånger som helst. RÖR INTE setup:
#   - oc_hubs_arende_typ            (ärendetyp-registret)
#   - oc_mail_messages / mailboxes / accounts   (de 2 orosanmälningarna + korgarna)
#   - $assignee_*, $label1          (tilldelnings-/viktighetstaggar)
#   - Deck-boards, Note-to-Self-rum, säkra-möte-rum
#
# RENSAR:
#   - oc_hubs_arende_case/pekare/member/flagga   (alla ärenden + koordinations-state)
#     → kvittenser (TreservaKvittens) härleds ur registret, så de försvinner med.
#   - oc_sdkmc_itsl_message_tag + oc_sdkmc_itsl_tag för case:* / behandlad / $dnr_*
#     → meddelandena blir otaggade och dyker upp i "Att ta emot" igen.
#
# LÄMNAR KVAR (medvetet): föräldralösa Talk-rum / groupfolders / Deck-kort / kalender-
#   objekt som tidigare ärenden skapade. De är osynliga i klienten (pekarna är borta)
#   och stör inte testet; en säker post-hoc-radering av dem kräver motorns egen
#   teardown (saga-kompensation) och görs inte blint via SQL här.
#
# Användning:
#   scripts/dev15-reset.sh
#   HUBS_SSH="ubuntu@10.43.51.62" scripts/dev15-reset.sh
#
set -euo pipefail

SSH_TARGET="${HUBS_SSH:-ubuntu@10.43.51.62}"
PSQL='sudo docker exec -i hubs-postgres psql -U oc_hubs -d hubs'

echo "→ Återställer dev15 (${SSH_TARGET}) till känt testläge…"

ssh -o BatchMode=yes -o ConnectTimeout=15 "${SSH_TARGET}" "${PSQL}" <<'SQL'
\set ON_ERROR_STOP on
BEGIN;

-- 1) hubs_arende-registret: alla ärenden + koordinations-state. Behåll _typ.
DELETE FROM oc_hubs_arende_pekare;
DELETE FROM oc_hubs_arende_member;
DELETE FROM oc_hubs_arende_flagga;
DELETE FROM oc_hubs_arende_case;

-- 2) sdkmc per-meddelande-taggar: case:* / behandlad / $dnr_* (mappningar + de
--    per-ärende-specifika tagg-definitionerna). Setup-taggar lämnas orörda.
DELETE FROM oc_sdkmc_itsl_message_tag
 WHERE tag_id IN (
   SELECT id FROM oc_sdkmc_itsl_tag
   WHERE imap_label LIKE 'case:%' OR imap_label = 'behandlad' OR imap_label LIKE '$dnr_%'
 );
DELETE FROM oc_sdkmc_itsl_tag
 WHERE imap_label LIKE 'case:%' OR imap_label LIKE '$dnr_%';

COMMIT;

-- Verifiering av känt läge.
\echo ''
\echo '=== KÄNT LÄGE (förväntat: arenden=0, pekare=0, medlemmar=0, hanterade_taggar=0, inbox>=2) ==='
SELECT
  (SELECT count(*) FROM oc_hubs_arende_case)   AS arenden,
  (SELECT count(*) FROM oc_hubs_arende_pekare)  AS pekare,
  (SELECT count(*) FROM oc_hubs_arende_member)  AS medlemmar,
  (SELECT count(*) FROM oc_sdkmc_itsl_message_tag mmt
     JOIN oc_sdkmc_itsl_tag mt ON mmt.tag_id = mt.id
     WHERE mt.imap_label LIKE 'case:%' OR mt.imap_label = 'behandlad') AS hanterade_taggar,
  (SELECT count(*) FROM oc_mail_messages m
     JOIN oc_mail_mailboxes mb ON m.mailbox_id = mb.id
     WHERE lower(mb.name) = 'inbox')           AS inbox_meddelanden;
SQL

echo "✓ Klart. Ladda om hubs_start → de 2 orosanmälningarna ligger i 'Att ta emot'."
