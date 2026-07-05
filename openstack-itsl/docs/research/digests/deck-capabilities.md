# Deck Capabilities Digest — rebuilding Nate B Jones's "Open Engine" on Nextcloud Deck + Talk

Date: 2026-07-04. Researched for ITSL. Purpose: replace Linear (+ Linear MCP) with Nextcloud Deck as the agent work queue, and Slack with Nextcloud Talk for capture. Every load-bearing web fact carries a URL. Facts verified against live docs/source in July 2026 unless marked otherwise.

---

## 1. Internal prior art: `hubs_arende` DeckClient

File: `C:/Users/fredrik.jonasson/Cursor/Nextcloud-vanilla/hubs_arende/lib/Integration/Client/DeckClient.php`

The ITSL team already drives Deck server-side from another Nextcloud app, over HTTP against Deck's own REST API (not PHP-internal calls). What it exercises today:

**Endpoints used** (base `/apps/deck/api/v1.0`, absolute URL built via `IURLGenerator::getAbsoluteURL`):

| Call | Purpose in hubs_arende |
|---|---|
| `GET /boards` | resolve board by exact title match (one board per enhet) |
| `POST /boards` `{title,color}` | create board on demand (color `0082c9`, 6-hex, no `#`) |
| `GET /boards/{boardId}` | full board payload — carries `labels[]`, used to resolve label by title |
| `GET /boards/{boardId}/stacks` | list stacks; each stack embeds `cards[]` — used both to pick the first stack and to locate which stack a known cardId lives in (stackId is required in card URLs) |
| `POST /boards/{boardId}/stacks` `{title:'Inkommande', order:0}` | create intake stack on demand |
| `POST /boards/{b}/stacks/{s}/cards` `{title, type:'plain', order:0, duedate?, description?}` | create card; `duedate` is ISO-8601 |
| `POST /boards/{boardId}/labels` `{title,color}` | create label on demand |
| `PUT  /boards/{b}/stacks/{s}/cards/{c}/assignLabel` `{labelId}` | attach label to card |
| `DELETE /boards/{b}/stacks/{s}/cards/{c}` | compensation delete (saga rollback) |

**Auth pattern**: a dedicated **service account**; `ServiceAccountAuth::authorizationHeader()` supplies a Basic-auth `Authorization` header (app-password style). Every request sends `OCS-APIRequest: true` and `Accept: application/json`, 10 s timeout, `nextcloud.allow_local_address = true` (loopback calls to the same instance). Response parsing tolerates both OCS envelopes (`{ocs:{data:…}}`) and flat JSON.

**Patterns worth stealing for the Open Engine**:
- *Resolve-or-create idempotency*: board/stack/label are looked up by exact title and created only if missing, so repeated runs converge.
- *Two-step create*: POST card → PUT `assignLabel` with a machine-readable label (`case:{id}`); the returned `{boardId, cardId}` is persisted as a pointer for later compensation. Same shape works for "engine ticket id" labels.
- *Graceful degradation*: every method NO-OPs (returns null/false) if the deck app is disabled or a call fails — callers keep going.
- *Read-projection with authority asymmetry*: `getCard()` reads via the service account (which owns the board) and surfaces only coordination fields (title, duedate, stack title, label titles) to users who lack board ACLs.
- *PII-safe logging*: card titles/labels are never logged verbatim — only `len:N:sha256prefix` digests (`safeRef()`).

**Known constraint baked into the API shape**: card operations need the *stackId in the URL path*, so any client that only stores `cardId` must first scan `GET /boards/{id}/stacks` to find the card. Plan to store `(boardId, stackId, cardId)` triples and refresh stackId after moves.

---

## 2. Deck REST API v1.x — verified surface

Primary source: https://deck.readthedocs.io/en/latest/API/ (rendered from https://github.com/nextcloud/deck/blob/main/docs/API.md).

**Base URL**: `https://host/index.php/apps/deck/api/v1.0` (also `v1.1`). Required headers: `OCS-APIRequest: true`, `Content-Type: application/json`. **Auth: Basic auth incl. app passwords** — exactly what DeckClient already does. Source: https://deck.readthedocs.io/en/latest/API/

**Versioning/stability**: v1.0 since Deck ≥ 1.0.0 (2020); v1.1 since Deck 1.3.0 (adds `file` attachment type); v1.2 exists in docs as "unreleased" (board import). The API is slow-moving and additive; one noted change: card title max length extended 100 → 255 chars. Source: https://deck.readthedocs.io/en/latest/API/ and https://github.com/nextcloud/deck/blob/main/docs/API.md

### Boards
- `GET /boards` (supports `If-Modified-Since`, optional `details` param), `POST /boards` `{title,color}`, `GET/PUT/DELETE /boards/{id}`, `POST /boards/{id}/undo_delete`.
- ACL management: `POST/PUT/DELETE /boards/{id}/acl…` for sharing boards with users/groups (permission flags for edit/manage/share).

### Stacks (= workflow statuses)
- `GET /boards/{b}/stacks` (with `If-Modified-Since`), `POST` `{title,order}`, `GET/PUT/DELETE /boards/{b}/stacks/{s}`, `GET /boards/{b}/stacks/archived`.
- Stacks have an integer `order` — a 6-column workflow is just 6 ordered stacks.

### Cards
- `POST /boards/{b}/stacks/{s}/cards` `{title, type:'plain', order, description?, duedate?}` (duedate ISO-8601).
- `GET /boards/{b}/stacks/{s}/cards/{c}`; `PUT` same path updates — **all update fields optional** (partial updates OK per docs summary; see Open Questions for a caveat).
- `DELETE` same path.
- **Move/reorder (verified)**: `PUT /boards/{b}/stacks/{s}/cards/{c}/reorder` with body `{order: int, stackId: int}` — **moves the card to another stack and position; this is the claim-by-status-move primitive.** Source: https://github.com/nextcloud/deck/blob/main/docs/API.md
- **Archive**: `PUT …/cards/{c}/archive` and `…/unarchive` (no body). Card JSON has `archived: bool` and `done` (ISO timestamp or null).
- Card JSON includes `assignedUsers[]`, `labels[]`, `duedate`, `order`, `archived`, `done`. Source: https://github.com/nextcloud/deck/blob/main/docs/API.md

### Labels
- `POST /boards/{b}/labels` `{title,color}`, `GET/PUT/DELETE /boards/{b}/labels/{l}`.
- Attach/detach on card: `PUT …/cards/{c}/assignLabel` `{labelId}` and `…/removeLabel` `{labelId}`.

### Assignees
- `PUT …/cards/{c}/assignUser` `{userId}` and `…/unassignUser` `{userId}`. Source: https://github.com/nextcloud/deck/blob/main/docs/API.md

### Attachments
- `GET/POST …/cards/{c}/attachments`, `GET/PUT/DELETE …/attachments/{a}`, `PUT …/attachments/{a}/restore`. v1.1 adds `file` type (stored as NC files). Useful for long agent artifacts that don't fit comments.

### Comments (OCS API — receipts channel)
- `GET  /ocs/v2.php/apps/deck/api/v1.0/cards/{cardId}/comments` — paginated, `limit` (default 20) / `offset`.
- `POST` same path `{message, parentId?}` — **message max 1000 chars**; `parentId` gives one-level threaded replies; server parses `@mentions` and returns them.
- `PUT  …/comments/{commentId}` — **update allowed only when the authenticated user === comment author (`actorId`)**.
- `DELETE …/comments/{commentId}` — author-only.
- Sources: https://deck.readthedocs.io/en/latest/API/ , https://help.nextcloud.com/t/deck-how-do-comments-work-exactly/111156
- Alternative comment surface (same underlying NC comments store) via WebDAV: `PROPFIND/POST remote.php/dav/comments/deckCard/{cardId}` — documented at https://deck.readthedocs.io/en/latest/API-Nextcloud/

### Activity
- Deck feeds the standard Nextcloud Activity app; query the Activity OCS API with filter `deck` for a card/board audit trail. Documented pointer: https://deck.readthedocs.io/en/latest/API-Nextcloud/ (refers to the Activity app API for details). There is no Deck-specific activity REST endpoint in the documented API.

### Caching / efficient polling
- ETags on board, stack and card endpoints; `If-None-Match` → 304. Child changes propagate to parent ETags (a card change bumps its board's ETag), and `If-Modified-Since` on list endpoints — so a poller can watch one board cheaply. Source: https://deck.readthedocs.io/en/latest/API/

### Undocumented-but-present routes (verified in source, not in API.md)
From https://github.com/nextcloud/deck/blob/main/appinfo/routes.php:
- `GET /api/v{apiVersion}/overview/upcoming` (`overview_api#upcomingCards`) — the dashboard "upcoming cards for me" feed.
- `GET /api/v{apiVersion}/search` (`search#search`) — cross-board search used by the web UI.
Both live in the OCS routes section. **Treat as internal/unstable**: parameters and response shape are undocumented; verify against your Deck version before depending on them.

### Query "cards assigned to user X in stack Y"
**No server-side filter endpoint exists in the documented REST API.** Confirmed: "The documentation does not provide dedicated filtering or search endpoints for querying cards by assigned user" (https://github.com/nextcloud/deck/blob/main/docs/API.md). The supported pattern is `GET /boards/{b}/stacks` — each stack embeds its full `cards[]` incl. `assignedUsers[]` and `labels[]` — then filter client-side. This is exactly what DeckClient's `findStackIdForCard()` already does. For "my cards across boards", the undocumented `overview/upcoming` route exists but is duedate-oriented. Conclusion: **assignee/label/title filtering is a client-side (MCP-tool-side) concern.**

---

## 3. Nextcloud Talk bot framework (capture bot, replaces Slack)

Source: https://nextcloud-talk.readthedocs.io/en/latest/bots/ and https://nextcloud-talk.readthedocs.io/en/latest/occ/

- Requires the `bots-v1` capability — Nextcloud 27.1+ (any current 2026 instance qualifies).
- **Registration is CLI-only by design** ("for security reasons bots can only be added via the command line"):
  - `occ talk:bot:install [--no-setup] [-f|--feature FEATURE] <name> <secret> <url> [<description>]` — name 1–64 chars, secret 40–128 chars, url = your webhook endpoint. Features: `webhook`, `response`, `event`, `reaction`, `none`.
  - `occ talk:bot:setup <bot-id> [<conversation-token>…]` — enable the bot in specific conversations (batchable).
  - Also `talk:bot:list [token]`, `talk:bot:state`, `talk:bot:remove`, `talk:bot:uninstall`.
- **Inbound**: Talk POSTs to the bot URL on (1) chat messages, (2) reaction added (Talk 21+), (3) reaction removed, (4) bot joined/left a conversation. Payloads are Activity Streams 2.0 (actor/object/target).
- **Verification**: HMAC-SHA256 over `X-Nextcloud-Talk-Random` + body with the shared secret; sent as `X-Nextcloud-Talk-Signature`; `X-Nextcloud-Talk-Backend` carries the server URL. The bot must verify before acting.
- **Outbound (replies)**: `POST /ocs/v2.php/apps/spreed/api/v1/bot/{token}/message` — plain text up to **32,000 chars**, supports `replyTo`, `referenceId`, `silent`; signed with the same shared secret. Reactions: `POST/DELETE /bot/{token}/reaction/{messageId}`. 401 = bad signature, 404 = convo/message gone, **429 = rate-limited** (budget for backoff).
- **Alternative for a same-box implementation**: a Nextcloud PHP app can register the bot URL as `nextcloudapp://$APPID` and receive `OCA\Talk\Events\BotInvokeEvent` in-process instead of running an HTTP endpoint (Talk 21+; feature flag `event`). Relevant if ITSL packages the capture bot inside a small NC app rather than a sidecar service.

Fit for Open Engine capture: a Talk bot receives every message in the designated "inbox" conversation, applies the title/label conventions, creates the Deck card via the REST API (service account), and replies in-thread with the card link — functionally equivalent to the Slack capture flow.

---

## 4. Event push: webhook_listeners (and alternatives)

Source: https://docs.nextcloud.com/server/latest/admin_manual/webhook_listeners/index.html (docs cover NC 35 as of July 2026; the app shipped with NC 30, https://github.com/nextcloud/server/pull/45475).

- Bundled app; enable with `occ app:enable webhook_listeners`. Register webhooks via **OCS API** (admin or delegated-admin account required); `occ webhook_listeners:list` to inspect.
- Payload: uniform envelope `{event: {class: "<FQCN>", …}, user: {uid, displayName}, time}`. **Filters** (dot-notation over the whole envelope, regex + comparison operators) are evaluated server-side at registration — e.g. filter to one boardId.
- Core webhook-compatible events: files/node events, system tags, calendar objects; plus per-app events (Forms, Tables listed in the manual).
- **Deck IS webhook-compatible (verified in source, not yet in the admin manual)**: `nextcloud/deck` `lib/Event/ACardEvent.php` on `main` declares `abstract class ACardEvent extends Event implements IWebhookCompatibleEvent` with `getWebhookSerializable()` returning the full serialized card. Source: https://github.com/nextcloud/deck/blob/main/lib/Event/ACardEvent.php. So card lifecycle events (created/updated/deleted subclasses of `ACardEvent`) can be registered as webhook triggers. **Uncertain**: which released Deck version first shipped this (verify on the target instance by attempting registration), and whether board/label/assignment events are also compatible.
- **Delivery latency caveat**: webhooks fire from background jobs — by default up to ~5 min lag; for near-real-time you must run dedicated worker processes (systemd/tmux `occ background-job:worker …` per the admin manual). Source: https://docs.nextcloud.com/server/latest/admin_manual/webhook_listeners/index.html
- **No documented signing/HMAC on outgoing webhook_listeners POSTs** — put a shared secret in the registered URL/headers and/or IP-restrict the receiver. (Absence of the feature in docs verified; treat as "not provided".)

**Alternatives if webhook_listeners is unsuitable**:
- *Polling with ETag/If-Modified-Since* on `GET /boards/{id}/stacks` — cheap (304s) and version-proof; the pragmatic fallback loop for agents.
- *Flow (workflow engine)*: Deck has no meaningful Flow triggers (long-standing gap, https://github.com/nextcloud/deck/issues/1722); don't plan on it.
- *Third-party `webhooks` app* (kffl/nextcloud-webhooks, https://github.com/kffl/nextcloud-webhooks): predates webhook_listeners, no Deck events; skip.
- *Custom NC app*: listen to `OCA\Deck\Event\*` in PHP and push wherever you like — full control, ITSL already ships NC apps, so this is a realistic escape hatch.

Historical note: Deck webhook feature request https://github.com/nextcloud/deck/issues/3341 was closed 2021-10-12 labelled "question" — its closure is NOT evidence of implementation; the real capability arrived later via the server-wide webhook_listeners + `IWebhookCompatibleEvent` path above.

---

## 5. MCP servers for Nextcloud (as of July 2026)

### cbcoutinho/nextcloud-mcp-server — the serious one
https://github.com/cbcoutinho/nextcloud-mcp-server and https://pypi.org/project/nextcloud-mcp-server/
- Python, **AGPL-3.0**, ~291 stars / 47 forks, 133 releases; latest release "Astrolabe 0.10.1" 2026-02-03; ~3,385 commits — actively maintained.
- 110+ tools across 12 apps: Notes, Calendar (20+), Contacts, Files/WebDAV, **Deck (15 tools: boards, stacks, cards, labels, assignments)**, Cookbook, Tables, Sharing, News, Mail (read-only), Collectives, **Talk (6 tools: conversations, messages)**.
- Auth: app passwords (Basic), optional Login Flow v2 for multi-user. Transport: **streamable-http (default) and stdio**. Experimental vector/semantic search over Deck cards etc. (needs Qdrant + Ollama).
- Caveat: the exact 15 Deck tool names were not enumerated in the README summary — confirm coverage of `reorder` (stack move) and comments before committing (see Open Questions).

### Jaypeg-dev/nextcloud-mcp — nascent
https://github.com/Jaypeg-dev/nextcloud-mcp
- JavaScript, MIT, ~1 star, 13 commits, no releases. Covers Tasks/Calendar/Notes/Mail/Files/Deck; Deck tools: `get_deck_boards`, `get_deck_board`, `create_deck_card`, `update_deck_card`, `move_deck_card` (so it does have a move primitive). stdio + HTTP/SSE, app-password auth. Too immature to bet on; useful as reference code.

### Assessment
No purpose-built "Deck as agent work queue" MCP exists. cbcoutinho's server is the right base for generic read/write, but Open Engine semantics (atomic claim, ledger update, filtered queue pull) will need either custom tools added to it (AGPL — fine for internal use; contributions upstream possible) or a thin ITSL-owned MCP server that speaks the Deck REST API directly (the DeckClient.php logic ports almost 1:1 to Python/TS).

---

## 6. Requirement mapping: Open Engine (Linear design) → Deck

| Open Engine requirement | Deck mechanism | Verified? | Gap / build item |
|---|---|---|---|
| 6 workflow statuses | 6 ordered stacks on one board (`POST /boards/{b}/stacks` with `order`) | Yes (docs + DeckClient) | None. Provision script creates board + 6 stacks idempotently (reuse resolve-or-create pattern) |
| Label filtering (e.g. `agent-ready`, priority) | Board labels + `assignLabel`/`removeLabel`; labels embedded in card JSON | Yes | **No server-side label query** — fetch `GET /boards/{b}/stacks` (ETag-cached) and filter in the MCP tool |
| Title-pattern filtering (e.g. `[OE] …` prefixes) | Card titles ≤ 255 chars; no search param in documented API | Yes (absence confirmed) | Client-side regex over the same stacks payload; optionally probe undocumented `GET /api/v1.x/search` (unstable) |
| Claim by status-move | `PUT …/cards/{c}/reorder` `{stackId, order}` moves between stacks | Yes | **No atomic compare-and-swap** — two agents can race. Protocol fix: `assignUser` self first, re-read card, proceed only if you're sole assignee AND card is in the expected stack; treat assignee as the lock, stack as the display |
| Receipts as comments | OCS comments `POST /ocs/v2.php/apps/deck/api/v1.0/cards/{id}/comments` | Yes | **1000-char limit per comment** — chunk receipts, or put long artifacts in card attachments / description and post a summary comment |
| One status-ledger card with an updatable comment per agent | `PUT …/comments/{commentId}` updates a comment — **author-only** (actorId match) | Yes | Works iff each agent has its own NC account/app password (also gives clean attribution + per-agent revocation). Single shared service account also works (it authored all comments). 1000-char cap applies; fallback ledger = the card's `description` via card PUT |
| Assignee-based routing | `assignUser`/`unassignUser`; `assignedUsers[]` in card JSON | Yes | "Cards for agent X in stack Y" = client-side filter of stacks payload; no API query. Each agent = NC user for routing to work |
| Capture from chat (Slack → Talk) | Talk bot: occ-registered webhook bot, HMAC-verified inbound, `POST /bot/{token}/message` replies | Yes | **Build the capture bot** (small HTTP service or NC-app `BotInvokeEvent` variant); parse message → create card → reply with link |
| Agents triggered on new/changed cards | webhook_listeners + Deck `ACardEvent implements IWebhookCompatibleEvent` (payload = full card JSON, filterable server-side) | Yes (source-verified on main) | Verify shipped in target Deck version; run background-job workers for sub-5-min latency; add own auth on the receiving endpoint (no HMAC on outgoing webhooks); ETag polling as fallback |
| MCP access for agents | cbcoutinho/nextcloud-mcp-server: Deck (15 tools) + Talk, stdio + streamable-http, app-password auth, AGPL, active | Yes | Add/wrap custom queue-semantic tools: `claim_card`, `post_receipt` (chunking), `update_ledger`, `pull_queue(status, label, assignee, title_regex)` |
| Audit trail | NC Activity app (filter `deck`) + Deck comments; ETag change detection | Partial | Activity OCS API shape for deck filter not verified in this pass |

**Net build list**: (1) capture bot for Talk; (2) ~5 custom MCP tools encoding queue semantics + client-side filtering; (3) webhook receiver with its own auth + worker tuning (or an ETag poller); (4) one-time board/stack/label provisioning script; (5) one NC account + app password per agent. No Nextcloud core/app forking required; a small ITSL NC app is optional (in-process Deck events, `nextcloudapp://` bot) not mandatory.

---

## OPEN QUESTIONS

1. **Which released Deck/NC versions ship `ACardEvent implements IWebhookCompatibleEvent`?** Verified on `main` only. Action: on the target instance, try registering a webhook for `OCA\Deck\Event\CardCreatedEvent` (and confirm the exact FQCN list of ACardEvent subclasses — created/updated/deleted; are board, label-assign, user-assign events also compatible?).
2. **Does the card `PUT` truly accept partial updates on the deployed Deck version?** Docs summary says all fields optional, but community threads historically reported needing title/type/owner on update (https://help.nextcloud.com/t/changing-cards-via-deck-api-updating-and-archiving/114057). Test on target version; if not, GET-merge-PUT.
3. **Concurrency on `reorder`**: is `If-Match`/ETag honored on card PUT/reorder (docs list ETags on GETs only)? If not, the assignee-lock claim protocol above must carry the race — write a two-agent race test.
4. **Exact tool list of cbcoutinho's 15 Deck tools** — does it expose `reorder` (stack move), comments, and archive? Enumerate from source before deciding wrap vs. fork vs. own server. (Repo stats — 291 stars, 0.10.1 of 2026-02-03 — came from a summarized fetch; re-check when pinning a version.)
5. **Talk bot posting rate limits** (429): what are the actual thresholds for bot messages on the target instance (brute-force/ratelimit settings)? Matters if receipts also mirror to Talk.
6. **Undocumented `GET /api/v1.x/search` and `overview/upcoming`**: parameters/response shape and stability — worth 30 min of probing on dev15, since server-side search would remove most client-side filtering.
7. **webhook_listeners outgoing auth**: confirm absence of signing on the target NC version and decide receiver auth (secret URL path + allowlist vs. mTLS).
8. **Comment length**: 1000-char limit is from Deck docs; confirm whether the limit is enforced server-side at exactly 1000 on the deployed version, and settle the receipt-chunking convention (e.g. `part 2/3` replies threaded via `parentId`).
