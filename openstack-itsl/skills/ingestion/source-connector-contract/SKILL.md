---
name: source-connector-contract
description: Kontraktet varje käll-inhämtning (ingest-*) följer när data hämtas in i en persons openbrain — inkrementell cursor, rådata bevaras, normaliserat Evidence, dedup via content_fingerprint, PII-brandvägg 422 = vägran; ladda först vid all ingestion, sync, brain-filling eller ny konnektor.
---
# Source Connector Contract

Basdisciplinen för ALL inhämtning till en persons brain (openbrain-svc). Varje `ingest-<källa>` lyder detta; bygg aldrig en konnektor som bryter mot det. Destillerat ur kb-pipeline (`connectors/base.py`), nativt mot Hubs.

## Tre invarianter
1. **Inkrementell.** Spara en cursor per källa OCH per subscope (rum, brevlåda, projekt). `since = max(konfig-fönster, sparad cursor)`. Kör om godtyckligt ofta → bara nytt hämtas. Flytta cursorn till högsta sedda timestamp/id EFTER lyckad skrivning, aldrig före.
2. **Rådata bevaras.** Spara originalobjektet oförändrat (`raw/<källa>/<id>.json`) innan normalisering. Varje brain-post bär `source_url` + `raw_ref` → alltid spårbar tillbaka till primärkällan vid granskning.
3. **Respektera fönstret.** Hämta inte utanför `since` (default 1 år).

## Normaliserad modell (Evidence → brain-thought)
Varje källobjekt → EN brain-post: `content` = rensad brödtext; `metadata` = `{ source, source_url, timestamp, author, author_is_expert, kind (problem|cause|resolution|discussion), components[], customer?, raw_ref, dedupe_key }`. Se [[evidence-normalize]].

## Skrivväg (native Hubs)
`POST ${BRAIN_URL}/ingest {content, source, author, metadata}` (Bearer `BRAIN_KEY`), eller `brain-api.sh create '<content>' --source <källa> --metadata '<json>'`.
- **Dedup är serverns jobb:** `content_fingerprint` unikt-index → upsert (created/merged). Skicka stabil text; kör inte egen dedup.
- **PII-brandvägg:** ett `422` betyder vägran (pii-patterns.json träffade). Vägran är FINAL — försök aldrig kringgå, formulera om eller maska för att smyga förbi. För personens EGNA data i personens EGEN brain gäller companion-principen (Tier-2), men egress UT ur brainen är fortsatt spärrad.

## Robusthet
En källa/rum/brevlåda som felar (403/404/rate limit) får INTE fälla de andra — logga och fortsätt. Timestamps tz-robust. HTML→text, men `text/plain` lämnas orört.

## Bevis före "klart"
Cursorn framflyttad; N poster skrivna med `source_url`+`raw_ref`; inga tysta 5xx-bortfall; 422:or loggade och respekterade.

## Se även
[[evidence-normalize]] · [[pii-pseudonymize]] · runbook `fyll-personhjarna` · referens: [[kunskapsbanken-ingestion]]
