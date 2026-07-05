# DEV15-FACTS — verifierade serverfakta (natten 2026-07-04→05, via SSH)

Bindande för implementation och deploy. Verifierade direkt mot dev15 (10.43.51.62).

## Nextcloud / appar
- NC 31.0.8.1 · Deck **1.15.9** i `/var/www/html/custom_apps/deck` (sidoladdad) · spreed 21.1.0.5 · webhook_listeners 1.2.0 ENABLED · notifications 4.0.0 · app_api DISABLED (trasig på denna NC-build — rör ej).
- **Deck 1.15.9 eventklasser** (`lib/Event/`): CardCreatedEvent, CardUpdatedEvent, CardDeletedEvent, BoardUpdatedEvent, Acl*Event, Session*Event. **INGEN separat assignment-event** och **INGEN IWebhookCompatibleEvent i denna version** (det gäller bara nyare main).
- **MEN:** `AssignmentService::assignUser/unassignUser` dispatchar **`CardUpdatedEvent`** (rad 141/172) → in-process-lyssnare på CardUpdatedEvent + diff av assignedUsers FÅNGAR assign/unassign i realtid. webhook_listeners är därmed IRRELEVANT för Deck-intake på denna version — lyssnare + 2-min-svep är designen.
- Deck REST-controllers bekräftade: BoardApi/StackApi/CardApi/LabelApi/CommentsApi/OverviewApi.
- occ har `user:auth-tokens:add` (app-lösenord), `talk:bot:install/setup/list/state`, `talk:room:*`.

## Användare — KRITISKT
- Faktiska NC-uid på dev15: `admin`, `anna.ignell`, `axel.israelsson`, `hubs-arende-svc`, **`197411040293` = Fredrik Jonasson (BankID-uid = personnummer!)**.
- `rebecca`/`sandra`/`mattias` FINNS INTE ännu → PENDING-USERS; routingkartan får endast verifierade uid. Atlas mappas till `197411040293` på dev15.
- **REGEL (pga personnummer-uid):** uid får ALDRIG skrivas i agentläsbar/agentgenererad TEXT (kortbeskrivningar, kommentarer, thoughts-content, mallar — där gäller displaynamn). uid endast i DB-kolumner/metadata-fält som PII-brandväggen inte skannar. Brandväggen skannar `content`/titel/beskrivning — INTE strukturerade metadatafält. Utan denna regel 422:ar personnummerregexen varje takeover Fredrik gör.

## TLS / nät
- LE-cert: `/opt/project_data/proxy/letsencrypt/live/dev15.hubs.se/` (SAN = exakt dev15.hubs.se) → caddy read-only-mount, path-routing på :8843.
- Brandvägg: ufw INAKTIV, iptables INPUT ACCEPT → publicerade portar (8843/8790/8791) nås direkt. HMAC/Bearer är därmed enda skyddet — obligatoriskt överallt.
- https://dev15.hubs.se nås publikt (200 via proxy nginx). Talk-boten når host via `http://10.43.51.62:8790`.

## Host
- Ubuntu 6.8, 8 vCPU, 31 GB RAM (21 tillgängligt), 55 GB fri disk, Docker 29.5.3 + Compose v5.1.4.
- `/opt/openstack` skapad, ägd av `ubuntu`. Hubs-stackens ~26 containrar kör via `initiator`-composen — vår stack är ett SEPARAT compose-projekt (`openstack`), rör aldrig deras.
- **API-nycklar: INGA Anthropic/OpenRouter/OpenAI-nycklar funna någonstans** (repo, env, /opt/project_data) → nyckelplatser tomma, RUNNER_ENABLED=0, embed_pending-läget aktivt tills Fredrik sätter nycklar (MORGONCHECKLISTA).
- ⚠️ Kör ALDRIG `docker restart hubs-php` (rensar sdkmc-backend-additions — se hubs-ops-runbook).
