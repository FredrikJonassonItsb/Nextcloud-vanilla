---
name: evidence-normalize
description: Normalisera ett objekt från valfri källa (Zammad/Talk/mail/möte/GitLab/portal) till en brain-post med enhetlig metadata (source, source_url, timestamp, author, kind, components, raw_ref, dedupe_key) före skrivning till openbrain-svc; kärnsteget som gör multi-käll-data tvärsöksbar. Följer source-connector-contract.
---
# Evidence Normalize

Allt från alla källor → EN enhetlig form, annars går inget att söka/klustra/lita på tvärs källor. Följer [[source-connector-contract]].

## Målform (brain-post)
- `content` = rensad brödtext (HTML→text; `text/plain` lämnas orört; citathistorik bortklippt för mail).
- `metadata`:
  - `source` ∈ `zammad|talk|outlook|fireflies|gitlab|portal`
  - `source_url` — djuplänk till primärkällan (**obligatoriskt**, spårbarhet)
  - `timestamp` (ISO/epoch normaliserat, tz-robust), `author`, `author_is_expert`
  - `kind` ∈ `problem|cause|resolution|discussion` (grovklassning; enrich förfinar senare)
  - `components[]` (komponentordlista + `term_corrections` mot feltranskribering), `customer?`
  - `raw_ref` — sökväg till bevarad rådata
  - `dedupe_key` — stabilt id (`<källa>-<objekt>-<del>`) → underlag för `content_fingerprint`

## Standarder
Stabil `evidence_id`/`dedupe_key`; komponenttaggning via ordlista; term_corrections (t.ex. "i seras"→"iSeras", "skim"→"SCIM", "o-id-c"→"OIDC").

## Bevis
Varje post har source_url + raw_ref + kind + stabilt id; ingen post utan spårbarhet.
