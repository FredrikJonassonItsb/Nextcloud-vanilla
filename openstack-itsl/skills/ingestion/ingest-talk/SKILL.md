---
name: ingest-talk
description: Hämta Nextcloud Talk-rumshistorik inkrementellt in i en persons brain via Spreed OCS (app-lösenord); använd för realtidsfelsökning/beslut i rum personen är medlem i, vid sync eller brain-filling. Exkludera 1:1-rum för integritet. Följer source-connector-contract.
---
# Ingest Talk (Nextcloud Spreed)

Hög signal men ostrukturerat: realtidsfelsökning i rum. Följer [[source-connector-contract]].

## Auth
Basic med app-lösenord (`NEXTCLOUD_USER` / `NEXTCLOUD_APP_PASSWORD`) + headers `OCS-APIRequest: true`, `Accept: application/json`. Användaren måste vara MEDLEM i rummet — att gå med är synligt och ändrar deltagarlistan (respektera samtycke, Tier-2-grant).

## Steg
1. Rum: `GET /ocs/v2.php/apps/spreed/api/v4/room`. Filtrera på `room_tokens` (tom = alla) och **exkludera `exclude_room_tokens`** (1:1-rum → integritet).
2. Per rum: cursor `room-<token>`. `GET /ocs/v2.php/apps/spreed/api/v1/chat/<token>?lookIntoFuture=0&limit=200&setReadMarker=0`.
   - **Bakåt-paginering:** följ svarets `X-Chat-Last-Given` → nästa `lastKnownMessageId`. Stanna när pagineringen inte rör sig bakåt (`given ≥ last_known`) eller når `prev_cursor`/`since`. (`lookIntoFuture+offset` kan utelämna äldre meddelanden — därför bakåt.)
   - Hoppa `systemMessage` (join/leave). Ett rum som ger 403/404 får INTE fälla övriga.
3. **Flatten Rich Object String:** ersätt `{key}`-platshållare med parameterns `name` ur `messageParameters`.
4. Normalisera → brain-post kind `discussion`; author = `actorDisplayName`; `source_url = <bas>/index.php/call/<token>#message_<id>`; metadata: room, token, thread_parent. Cursor = högsta message-id.

## Bevis
Nya meddelanden per rum; 1:1-rum uteslutna; systemmeddelanden bort; cursor per rum framflyttad.
