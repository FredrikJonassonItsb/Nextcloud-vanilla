# Smoke tests — ITSL Open Stack (CONTRACTS §9)

Permanenta regressionstester, körbara från repot mot dev15. Exit ≠ 0 = rött.
**Gå aldrig vidare på rött** (BYGGPLAN §9).

## Kom igång

```bash
cp tests/.env.test.example tests/.env.test   # fyll i (checkas aldrig in)
./tests/run-all.sh                           # smoke-01 → smoke-06
./tests/run-all.sh --with-runner             # även 07–08 (kräver ANTHROPIC_API_KEY på servern)
./tests/run-all.sh --from-server             # hämta hemligheter ur /opt/openstack/.env via ssh
```

Varje skript kan även köras enskilt, med samma flaggor.

## Sviten

| Skript | Bevisar |
|---|---|
| `smoke-01-key-matrix.sh` | 5×5-nyckelmatrisen: exakt diagonalen lyckas; utan auth → 401 |
| `smoke-02-capture-roundtrip.sh` | Sarah-frasen → EN rad i rätt hjärna; dedupe; fel HMAC → 401; personnummer → 422 + ingen rad |
| `smoke-03-claim-race.sh` | 2 parallella claims → exakt en 200 + en 409; EN AGENT CLAIMED |
| `smoke-04-ledger-upsert.sh` | PUT /ledger ×2 → EN AGENT STATUS-kommentar, uppdaterad på plats |
| `smoke-05-takeover.sh` | assign bot → engine-kort (grammatik, 8 sektioner, default-deny-Boundaries) + ⇄-kvitto + hos-agenten; unassign → recall |
| `smoke-06-sync-loop.sh` | 3 kommentarer in ⇒ exakt 3 ⇄-speglade ut, 0 ekon; kvitto → 1 ⇄-status; ingen ping-pong efter 2 svep |
| `smoke-07-runner-hello.sh` | hello-world-kortet CLAIMED → DONE → Agent Done + liggare `completed` (hoppas över utan API-nyckel) |
| `smoke-08-hostile-card.sh` | injektionskort → AGENT BLOCKED med citat, Agent Needs Input, noll sidoeffekter, ingen env-läcka |

## Förkunskapskrav

- `bash`, `curl`, `openssl`, `ssh` (nyckelbaserat mot `DEV15_SSH`) och EN av
  `jq`/`python3`/`python`/`node` för JSON-parsning.
- **smoke-05/06:** `TEST_HUMAN_USER` + `TEST_HUMAN_APP_PASSWORD` — takeover-gesten
  och kommentarspegling filtrerar bot-aktörer strukturellt (CONTRACTS §3), så en
  riktig människas app-lösenord krävs. Det finns aldrig på servern (BYGGPLAN §5.3)
  och kan därför inte hämtas med `--from-server`. Utan det hoppas 05/06 över (gult).
- **smoke-05/06:** `SMOKE_BOT` måste peka på en bot vars ägare är en VERIFIERAD
  nc-användare (CONTRACTS §1) — på dev15 i regel `bot-atlas` (fredrik).

## Exit-koder

`0` grönt · `1` rött · `2` konfigurationsfel · `3` överhoppat (guard)
