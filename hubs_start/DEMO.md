# Hubs Start — Demo-läge (vad som är stubbat)

Den här appen är deployad på din **vanilla Nextcloud** (Docker `nextcloud-app`,
NC 32.0.5) så att du kan se gränssnittet. Eftersom en vanilla NC **saknar**
syskonapparna (sdkmc, mail, spreed, calendar) och deras OCS-endpoints, körs appen
i ett **demo-läge** där hela UI:t fylls med realistiska fixtures i stället för
riktig data. Inget av detta påverkar en riktig Hubs-installation — där är
demo-läget AV och appen pratar med de verkliga endpointsen.

## Hur demo-läget slås på/av

`lib/Controller/PageController.php::isDemoMode()`:
- App-config `hubs_start/demo_mode = '1'` → **tvingat PÅ** (så är det satt nu).
- App-config `= '0'` → tvingat AV.
- Annars **AUTO**: PÅ när data-ägaren `sdkmc` inte är installerad.

Slå av demo-läget (för en riktig install med sdkmc/mail/spreed):
```
docker exec -u www-data nextcloud-app php occ config:app:set hubs_start demo_mode --value=0
```

## Vad som är STUBBAT i demo-läget

| Område | Stub | Riktig install |
|---|---|---|
| **All data** | `src/services/demoData.js` (statiska svenska fixtures) | sdkmc OCS `/summary`, `/receipts`, `/meetings/today`, `/recipients/*` |
| **Nätverkslagret** | `src/services/api.js` kortsluter varje funktion till demoData när `demoMode` är på (ingen XHR) | Riktiga axios-anrop mot sdkmc/spreed/calendar |
| **App-detektering** | PageController tvingar `apps = {sdkmc,mail,spreed,calendar,securemail: true}` och full `channelCoverage` så alla widgetar visas | `AppDetectionService` (IAppManager) detekterar verkligt installerade appar |
| **Roll** | tvingad `forvaltare` (så att Systemhälsa + Nytta hittills syns) | `RoleService` via grupptillhörighet |
| **LOA / tillitsnivå** | statiskt `LOA3` | sdkmc `getSettings.loginSecurity` |
| **Djuplänkar** ("Öppna", "Gå till mötet", "Legitimera med BankID", kanalval) | **inaktiva** — klick visar en notis "Demoläge: länken är inaktiv" (`Start.vue::demoBlocked`), eftersom mål-apparna inte finns | Navigerar till `/apps/sdkmc/mailbox-link/...`, `/call/{token}`, `/apps/mail/new?type=` osv. |
| **Boka säkert möte** (wizarden) | `createSecureMeeting` returnerar ett fejkat lyckat svar | Skapar Talk-rum + CalDAV + intents server-side |
| **Onboarding** | visas vid första besök (`onboardingSeen: false`) — klicka igenom till dashboarden | samma, men sparas per användare |

### Demo-innehållet
Fixturen speglar kunddemo-scenariot: Orosanmälan **Dnr SN 2026-0142**, inkommande
SDK från **IVO** och **Försäkringskassan**, säker e-post till medborgare, inkommen
fax, tre säkra möten idag (BankID grön/lila bock, en deltagare i lobbyn), kvittenser
i alla lägen (inkl. ett "Problem"), funktionsbrevlådor, en frånvarobevakning, och
bokningsbara tider.

## Vad som INTE är stubbat (riktig kod som körs)
- Hela frontenden (alla 17 Vue-komponenter, layout, triage-kö, Ctrl+K, wizarden,
  onboarding) körs som riktig kod — det är exakt samma komponenter som i produktion.
- `PageController`, `AppDetectionService`, `RoleService`, `PreferencesService`,
  initial-state-injektionen och appregistreringen är riktiga.
- Bundlen är en riktig produktionsbuild (`webpack`, @nextcloud/vue v8, Vue 2.7).

> Backend-tilläggen i `backend-additions/sdkmc/` (SummaryService, OCS-controllers,
> widgets, mötes-wizard) är INTE installerade i demo-instansen — de hör hemma i
> sdkmc-appen. De är skrivna och granskade (se HANDOVER.md/PUNCHLIST.md) men körs
> först i en riktig Hubs-miljö med sdkmc.

## Hur det deployades
```
# byggd bundle: hubs_start/js/hubs_start-main.js (+ async-chunks)
# kopierat till containern:
/var/www/html/custom_apps/hubs_start/{appinfo,lib,templates,img,l10n,js}
docker exec -u www-data nextcloud-app php occ app:enable hubs_start
docker exec -u www-data nextcloud-app php occ config:app:set hubs_start demo_mode --value=1
docker exec -u www-data nextcloud-app php occ config:system:set defaultapp --value='hubs_start,dashboard,files'
# (den gamla gov-portal-appen disablades — dess trasiga navigationsrutt spammade loggen)
```

## Öppna demon
- URL: **http://localhost:8080/** (du landar på Hubs Start eftersom den är satt som
  defaultapp) eller direkt **http://localhost:8080/apps/hubs_start/**
- Inloggning: din befintliga **admin**-session (annars admin/admin).
- Tips: hård omladdning (Ctrl+F5) om en gammal JS-cache ligger kvar.

## Återställ (ta bort demon)
```
docker exec -u www-data nextcloud-app php occ config:system:delete defaultapp
docker exec -u www-data nextcloud-app php occ app:disable hubs_start
docker exec -u www-data nextcloud-app php occ app:enable govportal   # om du vill ha tillbaka den
```
