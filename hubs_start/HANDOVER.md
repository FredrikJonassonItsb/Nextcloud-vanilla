# Hubs Start — Handover

**Datum:** 2026-06-13
**Vad:** Implementation av dashboardappen **Hubs Start — Flödesnavet** enligt den rekommenderade analysen ([HUBS-DASHBOARD-ANALYS.md](../HUBS-DASHBOARD-ANALYS.md)).
**Hur:** Kontraktstyrt bygge — kärnkontrakten handskrivna, alla löv (komponenter, backend-tjänster, widgets, tester) genererade av en parallell agent-workflow (25 agenter i två vågor) mot de fasta kontrakten, följt av en granskningsfas.

> **Status:** KLART. Grund + kontrakt handskrivna; alla löv (komponenter, backend,
> widgets, tester, l10n, docs) genererade av workflowen (27 agenter, ~1,98M tokens,
> 564 verktygsanrop, ~12 min); 3 granskare körda; **alla blocker- och major-fynd
> åtgärdade** i en efterföljande fix-runda. Syntaxgrind grön (se §8). Kvarstående
> icke-blockerande punkter i [PUNCHLIST.md](PUNCHLIST.md).

---

## 1. Vad är byggt

En **fristående Nextcloud-app** (`hubs_start`, namespace `HubsStart`) som blir kundens förstavy efter inloggning. Den löser det uttalade problemet "helheten sitter inte ihop" genom en **åtgärd-först-dashboard** med en triage-kö över alla säkra kanaler och verifierade djuplänkar ned i respektive funktion.

Arkitekturen följer domarpanelens syntes exakt:

- **Standalone-app** (sdkmc-mönstret, inga quilt-patchar) → ingen upstream-driftkostnad. Registreras med en rad i `setup-apps.list`, ärver hela CI-kedjan.
- **Datalagret ägs av sdkmc** (där mappers finns). Den nya aggregeringen (summary/receipts/recipients/secure-meeting/meetings) + de tre speglingswidgetarna levereras som **additiva nya filer till sdkmc** (`backend-additions/`). Statusmodellen dupliceras aldrig.
- **EN server-side aggregerings-endpoint** (`/ocs/v2.php/apps/sdkmc/api/v1/summary`) med `sinceIds` + cache. Gov-portals client-side fan-out undviks medvetet.
- **Kanalklassningen kapslad i EN server-side-tjänst** (`ChannelClassificationService` i sdkmc) — Smart mottagares hjärta, aldrig duplicerad på klienten.
- **Speglingswidgets** (`IAPIWidgetV2`) i standard-dashboarden från dag 1 → mobilklienter + dubbel-startvy-problemet löst.
- **mail** får ETT litet tillägg: routerhook `/apps/mail/new?type=…&to=…` för kanal-förvald komposer.

## 2. Vägval (låsta beslut)

| Beslut | Val | Varför |
|---|---|---|
| App-id | `hubs_start` (understreck) | NC app-id tillåter inte bindestreck; understreck är standard (jfr `user_ldap`) |
| Frontend-stack | **Vue 2.7 + @nextcloud/vue v8** | Matchar sdkmc/mail exakt (verifierat i deras package.json) — INTE Vue 3 |
| Build | `@nextcloud/webpack-vue-config`, single entry `main` | Samma som sdkmc |
| Store | `Vue.observable` (ingen Pinia/Vuex) | Liten state, självständig app, färre rörliga delar |
| Var bor aggregeringen | **sdkmc** (ej hubs_start) | Datakällan; undviker cross-app-koppling och class_exists-vakter |
| Förstavy-mekanism | `defaultapp`-config + navigation order 1 | gov-portals bevisade mekanism; FrontpageRoute undveks (servar literal `/`) |
| Rollstyrning | 3 fasta profiler via grupp (`RoleService`) | Förenklad fas 1; full rollmotor = fas 2 (domarpanelens råd) |

## 3. Filträd (kärnan — handskriven)

```
hubs_start/
├── appinfo/
│   ├── info.xml                 # app-id hubs_start, nav, settings, NC 30–32
│   └── routes.php               # page#index + OCS preferences
├── lib/
│   ├── AppInfo/Application.php
│   ├── Controller/
│   │   ├── PageController.php        # renderar SPA + initial state 'boot'
│   │   └── PreferencesController.php # OCS: onboarding-seen, keyboard-mode
│   ├── Service/
│   │   ├── AppDetectionService.php   # IAppManager → vilka Hubs-appar finns + kanaltäckning
│   │   ├── RoleService.php           # 3 profiler via grupptillhörighet
│   │   └── PreferencesService.php    # per-användar UI-prefs (bara det UI:t läser)
│   └── Settings/{Admin,AdminSection}.php
├── src/
│   ├── main.js, App.vue
│   ├── views/Start.vue          # integrationslayouten = komponentkontraktet
│   ├── services/
│   │   ├── api.js               # ★ HELA frontend↔backend-kontraktet (typedefs)
│   │   ├── deepLinks.js         # alla utgående länkar (verifierade routes)
│   │   ├── channels.js          # kanal-id → label/ikon/färg (endast presentation)
│   │   └── sections.js          # triage-sektioner + GOV.UK-statusar
│   ├── store/index.js           # reaktiv state-shape + actions (kontrakt)
│   └── components/              # ← lövkomponenterna (workflow-genererade)
├── css/variables.scss          # designtokens, .hs-card, WCAG-targetstorlek
├── templates/{index,admin}.php
├── img/{app,app-dark}.svg
├── docs/
│   ├── CONTRACTS.md            # ★ bindande spec alla agenter byggde mot
│   └── build-workflow.js       # workflow-skriptet (resumerbart)
├── backend-additions/
│   ├── MANIFEST.md             # exakt var varje fil ska och route/registreringspatchar
│   ├── sdkmc/lib/Service/ChannelClassificationService.php   # ★ handskriven (hjärtat)
│   ├── sdkmc/lib/...           # SummaryService, SecureMeetingService, OCS-controllers, widgets (workflow)
│   └── mail/initITSL-additions.js   # routerhook-snippet (workflow)
├── package.json, webpack.js, composer.json
└── HANDOVER.md (denna fil)
```

★ = den handskrivna kontraktsstommen som låste interfacen innan löven fanns.

## 4. Kontrakts­stommen (läs dessa först om du tar vid)

- **`src/services/api.js`** — varje nätverksanrop + JSDoc-typedefs (`Summary`, `QueueItem`, `Recipient`, `ChannelInfo`). Allt går genom denna modul; komponenter anropar aldrig axios direkt.
- **`docs/CONTRACTS.md`** — per-komponent props/events, hårda regler (Vue 2, varumärkesregel, i18n, WCAG 2.2, ingen client-fan-out), och backend-shapes + OCS-routetabell.
- **`src/views/Start.vue`** — visar exakt hur varje barnkomponent är inkopplad (props + events).

## 5. Kritisk väg (MÅSTE-ordning vid driftsättning)

Detta är den gemensamma största risken som alla tre koncepten flaggade. Backend FÖRE frontend:

1. **`ChannelClassificationService`** (klar, handskriven) — Smart mottagares grund.
2. **sdkmc OCS-endpoints** (`summary`, `receipts`, `recipients/*`, `secure-meeting`, `meetings/*`) + route-block och widget-registrering enligt `backend-additions/MANIFEST.md`. Summary måste ge **riktiga server-räknare** för de virtuella brevlådorna (idag hårdkodat 0).
3. **mail routerhook** `/apps/mail/new?type=` — annars blir "ett klick" tre.
4. **Därefter** hubs_start-appen + `occ config:system:set defaultapp --value='hubs_start,dashboard,files'`.

## 6. Resultat av bygg-workflowen

Levererade filer (alla på disk):

- **17 Vue-komponenter** under `src/components/` + integrationen i `src/views/Start.vue`:
  HeaderBar, LoaChip, ActionBar, AttHanteraQueue, QueueSection, QueueItem, DagensMoten,
  KvittensWidget, FunktionsbrevladorWidget, BevakningarWidget, BokningsbaraTider,
  NyttaWidget, SystemHalsa, SmartMottagare, MeetingWizard, CommandPalette, Onboarding.
- **Backend (sdkmc-tillägg)** under `backend-additions/sdkmc/lib/`:
  `Service/ChannelClassificationService.php` (handskriven), `Service/SummaryService.php`
  (aggregeringen — riktiga server-räknare för virtuella brevlådor, kvittenser,
  Bevakningar, deep-links), `Service/MeetingService.php` (delad mötesaggregering),
  `Service/SecureMeetingService.php` (mötes-wizard: Talk-rum + CalDAV-LOCATION +
  intents per event-UID), OCS-controllers `Summary/Recipient/SecureMeeting/Meeting`,
  och tre `IAPIWidgetV2`-widgets `AttHantera/Kvittenser/DagensMoten`.
- **Mail-hook:** `backend-additions/mail/initITSL-additions.js` (routerhook för
  `/apps/mail/new?type=&to=`).
- **Config/test/docs:** `.eslintrc.js`, `stylelint.config.js`, `babel.config.js`,
  `jest.config.js`, `tests/mocks/nextcloud.js`, 4 jest-spec:ar (channels/sections/
  deepLinks/store), `l10n/sv.js` + `sv.json` (**223 strängar**, 5 plural), `README.md`,
  `docs/INSTALL.md`, `.gitignore`.

Agenterna rapporterade nästan inga kontraktsavvikelser; de fåtaliga (t.ex.
MeetingWizards `startNow`-prop, tangentbordsfokus via DOM-query) var contract-säkra
val som granskningen och fix-rundan därefter städade.

## 7. Granskningsfynd & åtgärder

Tre granskare (frontend-kontrakt, PHP/NC-korrekthet, varumärke+WCAG) fann **3 blockers**
och flera majors/minors. **Alla blocker + major är åtgärdade** i fix-rundan:

- 2 widget-blockers (anrop till icke-existerande `SummaryService`-metoder) → ny delad
  `MeetingService` + korrekt `buildReceipts`-anrop.
- 1 frontend-blocker (`this.n` i NyttaWidget) → explicit import.
- Majors: CommandPalette `:title`→`:name`, Start.vue `startNow`-bindning,
  SecureMeetingService IL10N-titel, SummaryService kanalupplösning (var död kod) +
  INBOX-predikat, AttHanteraQueue WCAG-fokus/hjälp.

Fullständig lista + kvarstående icke-blockerande punkter: **[PUNCHLIST.md](PUNCHLIST.md)**.

## 8. Verifiering i denna miljö (grön)

- `node --check` grönt på **all** ren JS (services, store, tester, l10n).
- `<script>`-blocken i **alla 17 komponenter + Start.vue** passerar `node --check` (som ESM).
- Varumärkesgrep: **inga** "Nextcloud"/"Talk" i någon `t()/->t()`-sträng.
- **PHP:** ingen php-binär på Windows-hosten → PHP-korrekthet vilar på agent-granskning;
  kör `composer test:unit` + phpstan i Linux-containern.
- **Full build/test** i Linux-dev-miljön: `make nc-up` + `make webpack MODE=hmr` (HMR mot
  NC 32), `npm run test`.

## 9. Kvarstående TODO / integrationsgap (fas 2)

- **PENDING-semantik för kvittenser** måste klargöras med MW innan kvittowidgeten visar "Problem" (risk för falska misslyckanden) — markerat `// TODO(hubs-start)` i SummaryService/receipts.
- **CalDAV free-busy** i mötes-wizardens steg B (nu enklare tidsval).
- **notify_push** för realtid (nu 30 s polling med sinceIds).
- **Ärendeaggregering per dnr** ("Relaterade resurser"-spåret).
- **Full rollprofilmotor** med per-roll-terminologi.
- **Stats-endpoint** bakom "Nytta hittills" (nu indikativa värden).
- **WCAG 2.2 VPAT** — extern granskning + dokumenterad efterlevnad per kriterium som Adda-bilaga.
- **spreed-fork kräver NC 31, plattformen NC 32** — koordinera uppgraderingsfönster (v32 EOL ~sep 2026).

## 10. Nästa steg när du är tillbaka

1. Läs avsnitt 6–7 (workflow-resultat + punchlist).
2. Åtgärda eventuella `blocker`/`major`-fynd (jag har redan kört en första fix-runda om workflowen hann).
3. Lägg `hubs_start` i `hubs-apps/setup-apps.list`, kör backend-additions enligt MANIFEST.
4. `make nc-up` + `make webpack MODE=hmr`, kör jest + phpstan i containern.
5. Sätt `defaultapp` och testa flödena 1–6 (skicka, boka, genomför, tilldela, bevaka, förvaltarvy) i kunddemo.
