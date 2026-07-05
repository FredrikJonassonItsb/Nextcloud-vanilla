# Ingestion-skills — källinhämtning till openbrain

Destillerat ur Kunskapsbanken/kb-pipeline (`C:\Users\fredrik.jonasson\Cursor\Kunskapsbanken\kb-pipeline`), **re-implementerat nativt** mot Hubs-stacken (openbrain-svc `POST /ingest`, `brain-api.sh`). Nate-model: en skill gör EN sak pålitlig; välj efter flaskhals, inte nyhet.

## Katalog
| Skill | Gör | Kräver |
|---|---|---|
| `source-connector-contract` | **Ladda först.** Tre invarianter (inkrementell/rådata/fönster) + normaliserad modell + skrivväg + PII-422. | — |
| `evidence-normalize` | Källobjekt → enhetlig brain-post (source/source_url/kind/…). | contract |
| `pii-pseudonymize` | GDPR-maskning före egress/export. | — |
| `ingest-zammad` | Supportärenden (incident→lösning). | `ZAMMAD_TOKEN` |
| `ingest-talk` | Nextcloud Talk-rumshistorik. | NC app-lösenord |
| `ingest-mail` | E-post (SentItems, draft-only). | Graph `Mail.Read` |
| `ingest-meetings` | Mötestranskript → talar-turer. | `FIREFLIES_API_KEY` |
| `ingest-gitlab` | Commits/MR/issues. | `GITLAB_TOKEN` |

## Komposition
Runbook [`fyll-personhjarna`](../../runbooks/fyll-personhjarna.md) kedjar dessa: `ingest-* → evidence-normalize → (egress) pii-pseudonymize → skriv till personens brain → (valfritt) 2-menings-sammanfattning till central router`.

## Native-mappning mot kb-pipeline
- kb-pipelines `Connector.extract() → Iterator[Evidence]`  ⇒  ingest-*-skill + native konnektor.
- kb-pipelines SQLite-korpus  ⇒  per-person `openbrain-svc` (`POST /ingest`, dedup via `content_fingerprint`).
- kb-pipelines `RawStore`  ⇒  `raw_ref` i metadata + bevarad rådata.
- kb-pipelines export-`pseudonymize`  ⇒  `pii-pseudonymize` vid egress (Tier-2).

Referens-implementation med hårdkörda mekaniker/fällor: `kb_pipeline/connectors/`. Se minnesnot `kunskapsbanken-ingestion`.
