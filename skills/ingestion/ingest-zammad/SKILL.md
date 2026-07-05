---
name: ingest-zammad
description: Hämta Zammad-supportärenden inkrementellt in i en persons/teamets brain (ärende = incident→lösning) via REST Token-auth; använd vid nattlig sync, brain-filling eller "har detta hänt förut" för Hubs-support. Följer source-connector-contract.
---
# Ingest Zammad

Supportärenden är högst signal: redan incident→lösning-par. Följer [[source-connector-contract]].

## Auth
`Authorization: Token token=<ZAMMAD_TOKEN>` (scope `ticket.agent`). Bas t.ex. `https://zammad.itsl.se`.

## Steg
1. `cursor = max(since, sparad cursor)`. Sök `/api/v1/tickets/search?query=updated_at:>=<YYYY-MM-DD>&sort_by=updated_at&order_by=asc&per_page=100`.
   - **FÄLLA:** använd DATUM (`YYYY-MM-DD`), inte full ISO — en full tidsstämpel med `:` och `+00:00` ger Elasticsearch **tyst noll träffar**.
   - Saknas sök-API (400/422/501 på FÖRSTA sidan, ej mitt i pagineringen) → fallback `/api/v1/tickets`-listning, filtrera `updated_at` klientsidan.
2. Per ärende: `GET /api/v1/tickets/<id>?expand=true`, `GET /api/v1/ticket_articles/by_ticket/<id>`, taggar via `GET /api/v1/tags?object=Ticket&o_id=<id>` (taggar ligger INTE på ticket-objektet ens med expand). Bevara rådata (ticket + articles).
3. Normalisera per artikel → brain-post: `sender=customer`→kind `problem`, `agent`→`resolution`, annars `discussion`. **Inkludera interna noteringar** (lösningen bor ofta där). `content_type: text/plain` HTML-rensas INTE (skulle äta `<` i loggar/kod); HTML → text.
4. `source_url = <bas>/#ticket/zoom/<id>`. metadata: ticket_id, article_id, state, priority, group, internal, sender, tags. Sätt cursor = högsta `updated_at`.

## Bevis
Nya artiklar som brain-poster med source_url + raw_ref; interna noteringar med; cursor framflyttad.
