# MORGONCHECKLISTA — Fredrik, morgonen efter nattbygget

Allt nedan är i ordning; gör stegen uppifrån och ned. Stack-hemmet på dev15 är
`/opt/openstack` (compose-projekt `openstack`). Nycklarna är de enda två saker
natten INTE kunde skapa åt dig.

## 1. Skapa de två API-nycklarna (~10 min)

- [ ] **Anthropic:** console.anthropic.com → workspace **"hubs-openstack-runner"**
      (skapa om den inte finns) → sätt **månadstak $50** → skapa API-nyckel.
      Kopiera värdet (börjar `sk-ant-`).
- [ ] **OpenRouter:** openrouter.ai → skapa API-nyckel med **månadstak $10**.
      Kopiera värdet (börjar `sk-or-v1-`).
- [ ] Lägg båda i lösenordshanteraren (poster: `ANTHROPIC_API_KEY hubs-openstack-runner`,
      `OPENROUTER_API_KEY openstack`).

## 2. Klistra in nycklarna i `.env` på dev15 (~5 min)

```bash
ssh <dev15>                      # din vanliga dev15-access
sudo nano /opt/openstack/.env    # (chmod 600 — verifiera med: ls -l /opt/openstack/.env)
```

Fyll i de tre raderna (de står tomma/0 sedan natten):

```
OPENROUTER_API_KEY=sk-or-v1-...
ANTHROPIC_API_KEY=sk-ant-...
RUNNER_ENABLED=1
```

Spara. **Inga andra rader ska röras.**

## 3. Starta om stacken med nycklarna (~2 min)

```bash
cd /opt/openstack
docker compose up -d
docker compose ps        # allt ska vara Up/healthy: brain-db, brain-reb..brain-team, caddy, capture-bot, runner, backup
```

- [ ] Kolla att runnern plockade upp läget: `docker compose logs runner --tail 20`
      — ska visa att slots är aktiva (inte "RUNNER_ENABLED=0" / "no API key").
- [ ] Embeddings-backfill: tankar capturade i natt utan nyckel ligger som
      `embed_pending` — backfill-workern embeddar dem inom 5 min. Verifiera:
      `docker compose logs brain-atlas --tail 20` (leta backfill-rader).

## 4. Kör de två sista smoke-testen (~10 min)

Från repot (kör mot dev15; exit 0 = grönt):

```bash
tests/smoke-07-runner-hello.sh    # hello-world-kortet: CLAIMED → DONE → liggaren 'completed AE-n'
tests/smoke-08-hostile-card.sh    # injektionskortet: AGENT BLOCKED + citat, NOLL sidoeffekter
```

- [ ] smoke-07 grönt (kräver ANTHROPIC_API_KEY — därför först nu).
- [ ] smoke-08 grönt.
- [ ] Kolla spend i Anthropic Console (workspace hubs-openstack-runner): en tom-kö-körning
      ska kosta <$0.10.
- [ ] Om något är rött: `docker compose logs <tjänst>` + kortets kommentarer på
      Agent Engine-tavlan säger var det stannade. Gå INTE vidare till steg 5 på rött.

## 5. Onboarda dig själv först (M2 för Atlas, ~15 min)

```bash
# På din laptop, Git Bash, i repot:
cd openstack-itsl/skills
AE_SETUP_CARD=<id> AE_LEDGER_CARD=<id> AE_ROUTING_CARD=<id> AE_CATALOG_CARD=<id> \
  ./setup-laptop.sh atlas
# Kort-id:n: ssh <dev15> cat /opt/openstack/state/bootstrap.json
```

- [ ] Kör de två `claude mcp add`-kommandona skriptet skriver ut (brain-atlas + brain-team,
      Bearer-nycklarna ur lösenordshanteraren).
- [ ] Verifiera i ny Claude Code-session: spara + sök en testtanke i egen hjärna och
      teamhjärnan.
- [ ] Skicka en rad i Talk-rummet "Atlas minne" från mobilen → trådad bekräftelse.

## 6. Onboarda teamet — EN i taget (M2 + M7)

Ordning: Rebecca → Sandra → Mattias. Per person (~30 min tillsammans med dem):

- [ ] Dela personens `BRAIN_KEY_<NAMN>` + `BRAIN_KEY_TEAM` via lösenordshanteraren.
- [ ] Personen kör `./setup-laptop.sh <reb|ada|marvin>` (Git Bash) + `claude mcp add`-raderna.
- [ ] Personen skapar sitt NC-app-lösenord (Inställningar → Säkerhet, namn
      "agent-engine-laptop") — i egen keychain, ALDRIG till servern.
- [ ] M2-verifiering: capture + sök i egen hjärna; sök i teamhjärnan; kontrollera att
      personens capture INTE syns i någon annans sökning.
- [ ] M7-verifiering: skapa personens hello-world-kort
      `[agent instructions][<kod>-claude][task] Say hello from the queue` (assignee =
      personen) → deras runner tar det end-to-end (CLAIMED → DONE → liggare).
- [ ] Visa de tre vardagsgesterna: tilldela agenten på ett kort · `!queue <text>` i eget
      minne-rum · svara `ok`/rework på granskningar från eget kort.

## 7. Efterkontroller under dagen

- [ ] Liggarkortet: fyra AGENT STATUS-kommentarer, uppdaterade på plats (ingen pile-up).
- [ ] Agent Ops-rummet i Talk: inga larm.
- [ ] Rebecca/Sandra/Mattias ansvarsområden i routingkartan är fortfarande M0-grinden för
      full teamdrift — boka kickoff-timmen om den inte redan är gjord.
- [ ] Påminnelse: dev-nycklarna roteras ALLA vid prod-cutover (M10); se
      docs/SECRETS-TRACKER.md.
