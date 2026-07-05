---
name: ingest-meetings
description: Hämta mötestranskript (Fireflies GraphQL, eller NC Talk-samtal) inkrementellt in i en persons brain — gruppera till talar-turer, lyft expertens muntliga förklaringar, filtrera mötesbrus. Använd för brain-filling av mötes-/beslutskunskap. Följer source-connector-contract.
---
# Ingest Meetings

Talarattribuering låter oss lyfta muntliga expertförklaringar ur mötesbrus. Följer [[source-connector-contract]].

## Auth
Fireflies: `Authorization: Bearer <FIREFLIES_API_KEY>` mot GraphQL `https://api.fireflies.ai/graphql`.

## Steg
1. Lista transkript inkrementellt: `transcripts(fromDate,toDate,limit,skip)` (cursor = ms-epoch av senaste `date`). Filtrera på `participant_emails` om satt (annars alla).
2. Per transkript: hämta fullt (`sentences { speaker_name, text, start_time }`). Bevara rådata.
3. **Gruppera sammanhängande meningar per talare → turer.** Brusfilter: behåll turer ≥ `min_turn_chars` MEN behåll ALLTID expertens turer (`keep_expert_any_length`) oavsett längd.
4. Normalisera per tur → brain-post kind `discussion`; author = speaker; `source_url = <transcript_url>?t=<start_time>`; metadata: transcript_id, start_time, speaker. Cursor = högsta date.

## Termkorrigering
Kör transkripttext genom `term_corrections` (feltranskribering → kanonisk term, t.ex. "i seras"→"iSeras", "skim"→"SCIM") — se [[evidence-normalize]].

## Bevis
Nya turer; expertens turer bevarade oavsett längd; brus bortfiltrerat; cursor framflyttad.
