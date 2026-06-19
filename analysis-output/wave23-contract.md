# Wave 2/3 + gui-decisions — UNIFIED CONTRACT (synthesis of 8-agent understand workflow)

Date: 2026-06-19. Source: workflow wf7pcf1x2 (8 agents, dev15 ground-truth verified).
No GUI/BankID login available → verify via jest / phpunit (composer:2 docker) / `php -l` / `occ hubs_arende:smoke`.
Constraints: additive + graceful only; never regress the verified happy-path; keep every gate green;
NEVER-SoR (pointers, not PII/content); brand rule (no "Nextcloud"/"Talk"/"Circles" in customer UI strings);
never push.

## Cross-agent reconciliations (LOCKED)
1. **Engine `full.pekare` is the superset block** (one helper builds it):
   `{ talkToken:?string, groupfolderId:?int, conversationId:?string, deckBoardId:?int, deckCardId:?int, calendarUri:?string, bevakningBoardId:?int }`.
   Absent pointer ⇒ `null` (never `0`, never `''`). Pick the SAGA-original per type (oldest id), not later 1:n adds.
2. **`bevakningBoardId := deck_card.riktning`** (cast int). No dedicated "Bevakning" board exists on dev15
   (boards are enhet-named; cards live on board 3 'barn-familj@' / stack 'Inkommande'). `bevakningBoardId === deckBoardId`.
3. **Collapsed card (mapToCard) also carries `talkToken` + `bevakningBoardId`** so arende-summary lists them without a full fetch.
   `dnr` already present (#9 done). Frontend `MinaArenden.onBevakning` already reads `arende.bevakningBoardId` (wave 1).
4. **pekare columns are `objekt_typ / objekt_id / riktning`** (NOT typ/ref/value). Guard `pekareMapper === null` (positional harness).
5. **Frontend `full[]` cache-key → `triageRef`** (= `dnr ?? hubsCaseId`, always set). Today keyed on `dnr` (null for unregistered
   cases) → enrichment/talkToken never resolve. Fix in `ArendeKort.full()`, `toggleExpand`, `MinaArenden.onExpand/onStepperGoto`, store.
6. **`oc_mail_messages` exists (6 rows)**; #1 dedup is exercisable live. Dedup key = `thread_root_id ?: message_id ?: db_id`,
   keep the copy with non-empty `preview_text`. INBOX mailboxes 12 & 18 (mailbox-18 copies have empty preview).
7. **Note-to-Self user-asymmetric** (only uid `197411040293` has a room; axel none) → controller + frontend graceful-empty.
   In-process Talk classes loadable on dev15. Wrapper returns only `{id,text,createdAt}` (never room name/token).
8. **Register is ephemeral** (rewritten each `occ hubs_arende:smoke`) → assert on SHAPE; re-seed before data tests; tolerate orphan pointers.

## Integration seams (field names frontend↔backend MUST agree on)
- Engine GET `/arende/{ref}` → adds `pekare` block (#1 above). `talkToken` is the anchor for #10.
- Engine `/arende-summary` arenden[i] → adds `talkToken`, `bevakningBoardId`.
- sdkmc inflöde row (InflodeFeedService.toFeedRow) → adds `excerpt:string` (PII-scrubbed, '' default),
  populates `identitet:{badge,verifierad}` from channel, `messageType` chain = korg ?? subject-bridge ?? channel-transport,
  optional `conversationId := thread_root_id`.
- sdkmc NEW `GET/POST /api/v1/note-to-self` → `{notes:[{id,text,createdAt}]}` / body `{text}` → `{note:{...}}`. api.js: `fetchNotes()`,`addNote(text)`.
- sdkmc NEW `GET /api/v1/arende-enrichment?talkToken=` → `{diskussion:{olasta,omnamnandeTillMig,deltagare,meddelanden}}` (#16 shape:
  count + omnamnandeTillMig + 1-2 latest senders), honest-empty `meddelanden/moten`. api.js: `fetchArendeEnrichment(talkToken)`.
- sdkmc TeamService team row → adds `token:?string` (group room; best-effort, null on dev15). Frontend `onOppnaTradar` → `spreedRoomLink(token)`.
- Frontend deepLinks → NEW `spreedRoomLink(token)` (= `/call/{token}`, null when absent) + resolve case `'room'`.
- CommitGrind #5 → `payload.dokument:[{fileid,namn,vald}]` (all `vald:true`), committed subset `payload.valdaDokument:[{fileid,namn}]`.
  Engine consumption of `valdaDokument` is OUT OF SCOPE tonight (flag).

## Build set
DEFINITE (grounded, verifiable): engine pekare/summary; sdkmc feed (#1/#19); sdkmc note-to-self (#12);
deepLinks spreedRoomLink; InflodeRad fallback+excerpt (#19/#1); CommitGrind doc-select (#5); SigneringsGrind (#6);
MinaAnteckningar (#12 fe); ArendeDiskussion #10 button; store cache-key fix; component-test layer (root-cause gap).
GRACEFUL+FLAG (thin data, needs GUI verify): #3 enrichment endpoint+merge (#15/#16); #18 enhetschatt token.
