# Kom igång och testa — ITSL Open Stack på dev15

Praktisk guide för att själv verifiera systemet. Allt körs redan på dev15
(10.43.51.62 / https://dev15.hubs.se). Du behöver ITSL-nätet/VPN + SSH-nyckeln.

---

## 0. Snabbkoll: lever allt?

```bash
ssh ubuntu@10.43.51.62 'cd /opt/openstack/stack && sudo docker compose --env-file /opt/openstack/.env ps'
```
Förväntat: 10 tjänster, alla `healthy` (brain-db, brain-reb/atlas/ada/marvin/team, caddy,
capture-bot, runner, backup).

---

## 1. Kör hela smoke-sviten (5 min) — den snabbaste helhetsverifieringen

Från repot på din Windows-host (Git Bash):
```bash
cd openstack-itsl/tests
bash run-all.sh --from-server          # smoke 01–06
bash run-all.sh --from-server --with-runner   # + 07–08 (runnern, kräver API-nyckel — redan satt)
```
`--from-server` hämtar hemligheterna från serverns `.env` via SSH. Grönt = OK.
Förväntat i dag: 01/02/03/04 helgröna; 05/07 gröna på kärnan med de kända Deck-numeriska-uid-
och runner-confound-avvikelserna (se IMPLEMENTATIONSSTATUS §0). Sätt `SMOKE_BOT=bot-reb` i
`tests/.env.test` för att testa med ett normalt uid (Rebecca) i stället för Fredriks BankID-uid.

---

## 2. Testa HUVUDFUNKTIONEN i GUI:t: "tilldela agent → kortet tas över"

Detta är flödet du frågade om. Gör det som en riktig människa i Deck:

1. Logga in på https://dev15.hubs.se som en av teammedlemmarna (t.ex. Rebecca).
2. Skapa (eller öppna) ett Deck-kort på valfri **enrollad** tavla. *(Just nu är test-tavlor
   enrollade av smoke-testerna; för en egen tavla: kör
   `node provision/enroll-board.mjs <board-id> --admin-user <admin> --admin-password <app-pw>`.)*
3. Öppna kortet → **Tilldela** → välj **`Reb (agent)`**.
4. Inom sekunder: ett nytt kort dyker upp på tavlan **"Agent Engine"** i stacken **Agent Todo**,
   med titeln `[agent instructions][reb-claude][task] <din titel>` och en komplett 8-sektionsmall.
   På ditt ursprungskort får du labeln `hos-agenten` + en `⇄`-kommentar med länk till engine-kortet.
5. **Ta tillbaka:** ta bort `Reb (agent)` som tilldelad → agenten släpper kortet (recall).

> Obs: assignee-fältet på engine-kortet sätts för normala uid men INTE för Fredriks BankID-uid
> `197411040293` (Deck-bugg, se nedan). Själva takeovern fungerar oavsett.

---

## 3. Testa capture via Talk (minnet)

1. Öppna Talk-rummet **"Reb minne"** (eller Atlas/Ada/Marvin/Team minne).
2. Skriv ett meddelande, t.ex. *"Kom ihåg: kunden X vill ha veckovis uppföljning."*
3. Boten svarar med 👍 + "Sparat i hjärnan reb ✓". Meddelandet embeddas och blir sökbart.
4. Testa kommandon: `!status` (agentens liggarstatus) och `!queue Fixa X` (skapar ett Inbox-kort).
5. **PII-skydd:** skriv ett personnummer → boten svarar "Blockerat" och sparar det INTE.

---

## 4. Testa den headless agenten (runnern)

Runnern kör Claude Code headless mot din API-nyckel och plockar kort ur kön.
```bash
# Skapa ett testkort adresserat till en agent + väck runnern:
cd openstack-itsl/tests && bash smoke-07-runner-hello.sh --from-server
```
Förväntat: runnern får en HMAC-wake, claude claimar kortet (AGENT CLAIMED), gör jobbet,
postar AGENT DONE och flyttar kortet till **Agent Done**. Kostnad loggas i `run_log`
(dagstak $10/agent). Du ser det live med:
```bash
ssh ubuntu@10.43.51.62 'sudo docker logs openstack-runner-1 --since 5m'
```

---

## 5. Testa minnet / semantisk sökning

```bash
ssh ubuntu@10.43.51.62 'KEY=$(sudo grep "^BRAIN_KEY_ATLAS=" /opt/openstack/.env | cut -d= -f2); \
  curl -sk -X POST https://localhost:8843/atlas/mcp -H "Authorization: Bearer $KEY" \
  -H "Content-Type: application/json" -H "Accept: application/json, text/event-stream" \
  -d "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/call\",\"params\":{\"name\":\"search\",\"arguments\":{\"query\":\"din fråga här\"}}}"'
```
Sökningen är semantisk (hittar på betydelse, inte exakta ord). Default-tröskel 0.25 (svenskanpassad).

För att koppla en agents hjärna till Claude Code på en laptop: kör `skills/setup-laptop.sh`
(installerar identitet + skills + lägger till brain-MCP:n).

---

## 6. Reset till känt läge (mellan tester)

Smoke-testerna städar efter sig. Om något blir kvar:
```bash
# rensa engine-länkar + döda enrollments (på servern):
ssh ubuntu@10.43.51.62 'sudo docker exec hubs-postgres psql -U oc_hubs -d hubs -c "TRUNCATE oc_agent_engine_links;"'
```
Runnern är igång som standard. Vill du testa recall:s omedelbara arkivering utan att runnern
claimar först: `ssh ubuntu@10.43.51.62 'sudo docker stop openstack-runner-1'` (starta igen med `start`).

---

## Kända avvikelser du kommer att stöta på (alla dokumenterade, inga kärnfel)

| Vad du ser | Varför | Status |
|---|---|---|
| Fredriks agent (Atlas) får ingen assignee på engine-kort | Deck-bugg: numeriska BankID-uid matchas inte i `assignUser` (strikt jämförelse mot int-castade nycklar) | Behöver Deck-patch; drabbar hela skarpa itsl.hubs.se. Takeovern funkar ändå |
| smoke-05/06 recall-assertions röda när runnern kör | Runnern claimar kortet → recall gör kooperativ avbrytning (korrekt), inte direkt arkivering | Testartefakt; stoppa runnern för att testa arkiveringsvägen |
| Reb-rummets capture kräver att Rebecca är inloggad | — | Löst: teamet är upplagt |

Full status: [IMPLEMENTATIONSSTATUS.md](IMPLEMENTATIONSSTATUS.md) · Arkitektur: [BYGGPLAN.md](BYGGPLAN.md) ·
Interaktion: [INTERAKTIONSDESIGN.md](INTERAKTIONSDESIGN.md).
