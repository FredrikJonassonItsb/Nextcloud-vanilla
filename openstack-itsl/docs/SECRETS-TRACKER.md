# SECRETS-TRACKER — nyckelregister för ITSL Open Stack

**Register, inte valv: detta dokument innehåller ALDRIG nyckelvärden.** Värden bor i
lösenordshanteraren (Fredriks, resp. varje persons egen keychain för personliga nycklar).
Server-sidan läser allt ur `/opt/openstack/.env` (chmod 600, aldrig i git; mall i
`stack/.env.example`). Rotationsgrundregel: redigera `.env` → `docker compose up -d`
(<10 min per nyckel, rehearsal M9). **Prod-cutover (M10) roterar ALLA nycklar — dev-nycklar
reser aldrig till prod.**

## Hjärn-nycklar (bearer-auth mot openbrain-svc)

| Nyckelnamn | Var den bor | Vem skapar | Rotation |
|---|---|---|---|
| `BRAIN_KEY_REB` | `/opt/openstack/.env` + Rebeccas keychain (claude mcp-header) | deploy.sh genererar (32 B hex); Fredrik distribuerar säkert | .env + compose up -d; kör `claude mcp add` om på laptopen; smoke-01 verifierar diagonalen |
| `BRAIN_KEY_ATLAS` | `/opt/openstack/.env` + Fredriks keychain | deploy.sh; Fredrik | som ovan |
| `BRAIN_KEY_ADA` | `/opt/openstack/.env` + Sandras keychain | deploy.sh; Fredrik | som ovan |
| `BRAIN_KEY_MARVIN` | `/opt/openstack/.env` + Mattias keychain | deploy.sh; Fredrik | som ovan |
| `BRAIN_KEY_TEAM` | `/opt/openstack/.env` + ALLA fyras keychains | deploy.sh; Fredrik | som ovan — fyra laptops ska uppdateras, bocka av alla |

## Externa API-nycklar (**TOMMA tills Fredrik klistrar in dem — se MORGONCHECKLISTA.md**)

| Nyckelnamn | Var den bor | Vem skapar | Rotation |
|---|---|---|---|
| `OPENROUTER_API_KEY` | `/opt/openstack/.env` (läses av brain-tjänster + capture-bot) | **Fredrik** på openrouter.ai; månadstak $10 | Skapa ny på openrouter.ai → .env → compose up -d → revokera gamla. Saknad nyckel är ofarlig: embeddings hamnar i pending-läge och backfillas |
| `ANTHROPIC_API_KEY` | `/opt/openstack/.env` (läses endast av runner) | **Fredrik** i Anthropic Console, workspace "hubs-openstack-runner" med månadstak ($50 start) | Ny nyckel i Console → .env → compose up -d → revokera gamla. Runnern är gated på att nyckeln finns OCH `RUNNER_ENABLED=1` |

## Interna stack-hemligheter

| Nyckelnamn | Var den bor | Vem skapar | Rotation |
|---|---|---|---|
| `ENGINE_PUSH_SECRET` (HMAC agent_engine → runner) | `/opt/openstack/.env` + NC-appconfig på dev15 (`occ config:app:set agent_engine`) | deploy.sh genererar (32 B hex); provisioneringen sätter appconfig | Måste bytas på BÅDA sidor i samma fönster: .env + occ config:app:set → compose up -d; smoke-05 verifierar push |
| `TALK_BOT_SECRET_REB` / `_ATLAS` / `_ADA` / `_MARVIN` | `/opt/openstack/.env` + Talks bot-registrering (sätts vid `occ talk:bot:install`) | occ-provision.sh; Fredrik kör | Ny secret kräver om-install av boten (`talk:bot:install` + `talk:bot:setup`) + .env + compose up -d; smoke-02 (HMAC-manipulation → 401) verifierar |
| `BOT_APP_PASSWORD_REB` / `_ATLAS` / `_ADA` / `_MARVIN` / `_ENGINE` | `/opt/openstack/.env` (enda NC-creds i stacken) | occ-provision.sh via `occ user:auth-tokens:add`; Fredrik kör | Nytt token via occ, gamla revokeras i NC; .env + compose up -d |
| Postgres-rollösenord (`u_reb` `u_atlas` `u_ada` `u_marvin` `u_team` `svc_engine`) | `/opt/openstack/.env` (DATABASE_URL per tjänst); DB-porten publiceras ALDRIG på host | init-SQL/deploy.sh genererar | ALTER ROLE + .env + compose up -d; intra-stack, ingen laptop berörs |

## Personliga nycklar (bor ALDRIG server-side — domarkrav)

| Nyckelnamn | Var den bor | Vem skapar | Rotation |
|---|---|---|---|
| NC-app-lösenord, människa ×4 ("agent-engine-laptop") | Respektive persons egen keychain | Varje person själv (Inställningar → Säkerhet på dev15.hubs.se) | Personen revokerar i NC-inställningarna och skapar nytt; ingen server-ändring |
| Claude Max-abonnemang ×4 | Respektive persons Claude-konto | Finns redan | Kontohantering hos Anthropic; används ALDRIG headless (villkor + kostnadsmodell) |

## Icke-hemligheter i `.env` (för fullständighet — ingen rotation)

`NC_BASE=https://dev15.hubs.se` · `RUNNER_ENABLED` (0 tills nycklar satta, sedan 1) ·
`RUNNER_DAILY_USD_CAP=10` · `EMBED_MODEL=openai/text-embedding-3-small`.

## Regler

1. Nyckelvärden skrivs aldrig i git, kort, Deck-kommentarer, Talk, hjärnor eller detta
   dokument. PII-brandväggen blockerar `sk-ant-`/`sk-or-v1-`-mönster som extra nät (422).
2. Distribution av laptop-nycklar: via lösenordshanterarens delningsfunktion — aldrig
   klartext i mail/Talk.
3. Varje ny nyckel förs in HÄR (namn, plats, skapare, rotationsnot) i samma commit som
   koden som använder den.
4. Läckt nyckel: rotera omedelbart enligt kolumnen ovan; en läckt hjärnnyckel exponerar
   exakt EN hjärna (DB-per-hjärna-isolering) — rotera och granska den hjärnans senaste
   skrivningar.
