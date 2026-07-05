---
name: ingest-gitlab
description: Hämta GitLab-utvecklingshistorik (commits, merge requests, issues) inkrementellt in i en persons/teamets brain via PAT read_api; använd för teknisk kontext/beslut i brain-filling. Följer source-connector-contract.
---
# Ingest GitLab

Utvecklingsbeslut och teknisk historik. Följer [[source-connector-contract]].

## Auth
Personal Access Token, scope **`read_api`**. Bas t.ex. `https://gitlab.itsl.se`.

## Steg
1. Projekt: tom lista = alla där token-användaren är medlem, annars `project_ids`. Egen cursor per projekt.
2. Hämta `fetch_commits` / `fetch_merge_requests` / `fetch_issues` inkrementellt (updated_at/created_at), `per_page=100`. Valfritt `only_with_components` för att hålla signalen hög (bara MR/issues kopplade till komponentordlistan).
3. Normalisera → brain-post; kind `discussion` (MR/issue-tråd) eller `resolution` (mergad fix); `source_url` = objektets web_url; metadata: project, iid, type, state, labels. Respektera rate limits (inkrementellt + exponentiell backoff).

## Bevis
Nya commits/MR/issues; cursor per projekt framflyttad; rate-limit hanterad utan tyst bortfall.
