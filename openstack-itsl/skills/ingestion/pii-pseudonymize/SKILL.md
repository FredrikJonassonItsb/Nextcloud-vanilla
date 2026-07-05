---
name: pii-pseudonymize
description: Pseudonymisera/maska persondata (e-post→stabil token, personnummer/telefon→mask) före text lämnar personens brain eller går till moln-LLM/export/delad yta; GDPR-disciplin för ingestion och egress. Skilj pseudonymize (stabila tokens) från redact (hård maskning).
---
# PII Pseudonymize

GDPR-disciplin. Rådata + korpus stannar internt; bara pseudonymiserad text lämnar auktoriseringsgränsen. Kompletterar brain-brandväggens 422 (ingress) med egress-skydd.

## Två lägen
- **`pseudonymize`** (konsekvent, sökbart): e-post → stabil `[EPOST_<sha8>]` (samma e-post → samma token), personnummer → `[PERSONNUMMER]`, telefon → `[TELEFON]`. Använd för AI-export/RAG där relationer måste bevaras.
- **`redact`** (hård): all identifierad persondata → platshållare. Använd när inget behöver korreleras.

## Regler
- Svenskt personnummer `YYMMDD±XXXX` / `YYYYMMDD±XXXX`.
- Telefon: **validera** (7–13 siffror, `+`/`0`/`46`-start) så löpande id/ordernummer INTE maskas (över-matchning var en tidigare bugg).
- **Vart det gäller:** vid egress ur en owner-trusted brain (Tier-2), i web-sök-queries, mot delad/team-yta, mot moln-LLM. INTE nödvändigt för personens egna data som stannar i personens egen brain (companion-principen, [[hubs-pii-authorization-principle]]).

## Bevis
Ingen rå e-post/personnummer/telefon i utgående text; stabila tokens där korrelation krävs; inga maskade löp-id (falska positiva).
