# Skills, Runbooks & Companion Access — adoptionsdesign

**Datum:** 2026-07-05 · **Status:** design klar, beslut väntar, bygg ej påbörjat
**Källa:** 7-vägs parallell research (Nates digests + vår kod + info-ytor + admin/säkerhet) → arkitektsyntes.
**Kravet (Fredrik):** våra agenter ska använda **Nates** implementation av runbooks + skills; dessa ska gå att **aktivera från Hubs admingränssnitt**; varje agent ska vara en **nära kompanjon** till sin människa med tillgång till **samtliga informationskällor människan har** (mail, Talk, portal.hubs.se, Zammad, websökning …) — och **säkerhetsgränssnittet måste tillåta det**.

Grundprincip (från projektet, se [[hubs-pii-authorization-principle]]): *auktoriserad åtkomst av människans egen agent till människans egna data är AVSIKTEN.* Invarianten är **ingen läcka över auktoriseringsgränser** — inte PII-döljning.

---

## 1. Var vi står idag (redan byggt)

- **OB1-kärnan:** per-hjärna `openbrain-svc` med de sex verbatim MCP-verktygen + skriv-brandvägg. ✅
- **Nates SKILL.md-format:** fyra `skills/core/*/SKILL.md` (brain-recall, deck-conventions, itsl-guardrails, open-agent-engine) med `name/description/version`-frontmatter. ✅
- **Nates runbook-exekveringsloop:** runnern kör `claude -p` genom `queue-run.md`:s 20-stegsloop (Nates 19 steg Deck-adapterade + 12b). ✅
- **Kvitto-/handoff-vokabulär:** `AGENT SKILL SUBSCRIBED/INSTALLED/UPDATED/DECLINED`, `AGENT APPLIED`, Review/HUMAN HOLD — **definierat men ingen loader upprätthåller det än.**

**Tre glapp** mot det nya kravet: (A) skills/runbooks är inte *aktiverbara enheter* (alltid-på standing-kontext, ingen per-agent-prenumeration, ingen förstklassig runbook-artefakt); (B) admin-sidorna är **read-only**; (C) sandlådan är **ett enda trust-tier** byggt för *otillförlitliga* arbetskort — kompanjon-access behöver ett andra tier.

---

## 2. Runbooks — adoption

Gör runbooken till en **förstklassig deklarativ artefakt** (Nates sidor exponerar bara författargrammatiken, så filformatet är vårt designval):

`openstack-itsl/runbooks/<namn>.md`:
- Titel `Runbook NN · Titel`
- **Chain**-rad: skill-namn med `→` mellan
- Trigger-mening (runbooks är *trigger-drivna*, inte kalenderstyrda)
- Per-stage-block: (a) skill som laddas, (b) **exakt verktygsscope** steget får (Diet/Tools/Reach), (c) artefakt som produceras och skickas vidare
- Namngivna **human gates**
- `The payoff:`-rad — en konkret inspekterbar artefakt

**Mappning mot "exakt ETT kort per körning":** en flerstegs-runbook blir en kedja av **länkade engine-kort** som avanceras över körningar (steg N producerar artefakt + skapar/avancerar steg N+1-kort) — INTE ett mega-kort. Bevarar one-task-per-run-invarianten och ger "steg 2 tar vid där steg 1 slutade". `queue-run.md` får ett steg: bär ett claimat kort en runbook-stage-tagg → ladda det stegets skill-set + verktygsscope **för just den körningen**.

Human gates → befintliga pauskanaler: `AGENT BLOCKED` (arbetsfråga, på kortet), `AGENT HUMAN HOLD` (auktoritetsfråga, ägarens privata session), terminal gate = Agent Review.

**Första riktiga runbook:** det dokumentgrundade *case-packet*-templatet (Ingestion → Chunking → Normalization → Case Store → Retrieval Map → Citation Guard → Packet Export → Human Gate) med `openbrain-svc` som Case Store. Citation Guard blockerar export tills varje ostödd påstående är fixat eller omvandlat till en namngiven granskningsfråga.

**Agent Maintenance Loop:** adopteras som **dokumenterad, människo-körd, trigger-driven procedur** först (4 trigger-familjer: uppströmsändring, scope creep, stigande människokostnad, tyst fel; 7-ytors audit; beslut *keep/change/pause/retire* med replay-pack), lagrad på standing-status-liggarkortet — INTE auto-tooling ("få nog att köra för hand").

---

## 3. Skills — adoption

Bygg den **loader-halva** vi saknar:

1. **Skriv om alla `description:` till EN tät rad (≤1024 tecken)** packad med trigger-fraser. Nates hårda regel: enrads-beskrivning annars bryts Claude Codes routing tyst. Våra nuvarande är lång svensk prosa. **+ CI-lint** som avvisar flerrads/överlånga beskrivningar.
2. **Per-agent-loader** (i `setup-laptop.sh`/runner-bootstrap) som läser agentens prenumerationsset ur agent_engine-liggarens `optional_skills` och materialiserar **bara** core-skills + agentens prenumererade optional-skills till `~/.claude/skills/<namn>/SKILL.md`. Detta är steget som gör authored filer → per-agent-aktiverbara, on-demand-laddade enheter (Claude Codes tvånivå-progressive-disclosure gratis).
3. **`itsl-guardrails` är icke-valbar core**, laddas FÖRST och ovillkorligt för varje agent → info-access springer aldrig före boundary-reglerna.
4. **`metadata.json` per skill** (category/requires/tags/requires_skills). Fyll det tomma Deck-katalogkortet "Optional standing skill directory" som registry.
5. **Progressive-disclosure-tiering** (references/ öppnas bara när grenen nås) för allt auktoritetsbärande → mindre blast radius.
6. **Behåll människo-gates:** varje skill som vidgar en agents informationsräckvidd kräver `AGENT SKILL SUBSCRIBED` (första install) eller `AGENT APPLIED` (standing-update) — ingen auto-install av räckvidds-vidgande skills.

**Port av innehålls-skills** (guardrail-inlindade): Meeting Synthesis + Brain Dump Processor först (mappar på capture-bots Talk-intag och handläggardokumentation), sedan Assumption Checker, sedan Testing Runbook Creator för våra egna byggare.

---

## 4. Admin-aktivering (Hubs admingränssnitt)

Gör de två befintliga server-renderade inställningssidorna aktiverande:

- **AdminSettings "Agent Engine"** (admin-session, priority 80/40): (a) **per-agent skill-toggle-grid** — varje rad agent × optional skill; toggle skriver `AGENT SKILL SUBSCRIBED/DECLINED`-kvitto på liggaren + uppdaterar `optional_skills`-setet loadern läser; (b) **per-agent runbook-enable-lista** mot `runbooks/`-katalogen; (c) **routing-map-editor** som ersätter dagens occ-enda väg (admin-templaten säger bokstavligen "sätts via occ tills en editor byggs").
- **PersonalSettings "Min agent"** (NoAdminRequired, priority 75/40): per-människa **kompanjon-samtyckesyta** (grant/inspect/revoke per källa) — se §5.

Nya OCS write-endpoints parallellt med befintliga `PUT /api/v1/boards/{boardId}/enroll` (AdminController-mönster: admin-session, registrerar vem som auktoriserade, validerar enum). **Net-new kod = write-endpoints + loadern som hedrar prenumerationssetet.** Resten återanvänder liggare/kvitto-maskineriet.

---

## 5. Säkerhetsmodell — TVÅ trust-tiers på samma substrat

**TIER 1 (default, oförändrad) — "untrusted-origin":** varje arbetskort behåller `BOUNDARIES_V1` (byte-identisk, aldrig syntetiserad ur origin-text), **INGRESS**-PII-brandväggen (`PiiFirewall::scan` VÄGRAR vid träff, skrubbar aldrig; inbyggda mönster = osänkbart golv), och det snäva runner-`--allowedTools`-taket (engine-api.sh + brain-api.sh + Read/Grep/WebSearch/WebFetch). Skyddar människan FRÅN ett fientligt kort. **Fallback närhelst ingen explicit ägargrant finns.**

**TIER 2 (ny, opt-in) — "owner-trusted":** en agent bunden till **exakt EN verifierad människa** läser den människans egna källor. Tre saker flippar i detta tier och ENDAST här:
- (a) förstklassig **provenance-flagga** på engine-kort + link-rad väljer tier;
- (b) brandväggen flippar från INGRESS till **EGRESS** — människans EGNA PII SLÄPPS IN i sessionen (kompanjonens hela jobb) men BLOCKERAS på varje utgående väg (delade tavlor, utåtriktade kommentarer, tredjepartsverktyg, web-sök-queries, speglad text, cross-brain-writes) → **default-deny egress**;
- (c) verktygstaket vidgas för **READ av ägarens egna källor endast**, medan alla utåtriktade WRITES förblir **draft-only oavsett tier** (BOUNDARIES_V1:s never publish/email/deploy/delete/billing överlever tier-bytet).

**Samexistens-garantier:** avsaknad av verifierad ägargrant → ALLTID Tier 1 (ingen grant ⇒ sandlåda). Ett korts tier avgörs av provenance-flaggan → otillförlitligt arbetskort och owner-trusted kompanjon-session delar aldrig trust-kontext. Per-källa-taggning (source:talk/mail/portal/case) på varje intaget objekt låter egress-brandväggen resonera om vad som får lämna vilken gräns. **Ägarbindningen är VERIFIERAD** (inte den ambient Deck-ACL-aktör som self-service-enrollment använder idag — bred access kräver ett starkare, helst BankID-grade, ägarbekräftelsesteg).

**Prerekvisit innan någon Tier-2-session körs:** per-människa-per-källa consent-objekt `{ownerUid, sourceType, scope:read, granted_at, expires_at, revoked}`, egress-läge i brandväggen, en widened-read-only runner-profil, verifierad ägarbindning, och en audit/liggare av kompanjon-läsningar.

---

## 6. Informationskällor (connectors)

| Källa | Status | Väg | Auth-modell | Risk |
|---|---|---|---|---|
| **portal.hubs.se** | ✅ finns | = syskon-apparna hubs_start + hubs_arende + sdkmc, alla OCS v1. Bygg EN read-aggregations-endpoint i agent_engine som proxar getSummary/queue/mailboxes bakom en auktoriseringsscopad "vad väntar på min människa"-vy. **Noll ny transport.** | Agent PÅ UPPDRAG AV sin människa via per-agent NC-service-konto (app-password). **Öppen fråga:** hedrar OCS-endpointsen service-kontots egen ACL-scope eller krävs on-behalf-of-param? | Rikaste ytan, lägst transport-risk, men ACL-scoping **overifierad** — mis-scopad proxy kan visa ärenden människan ej är behörig till. Verifiera per endpoint. |
| **Talk-historik** | ◐ delvis | Inbound funkar (capture-bot HMAC-webhook). Net-new: `GET /ocs/.../spreed/api/v1/chat/{token}` för att läsa rums-HISTORIK, genom samma PII-brandvägg. | Agent AS itself (registrerad bot) för att skicka; PÅ UPPDRAG AV (måste vara deltagare) för att läsa privat/case-rum. | Att läggas till i ett privat rum för att läsa historik är **synligt** och ändrar deltagarlistan. Över-bred rums-medlemskap = läckvektor. |
| **Mail** | ◐ delvis | NC Mail-app (IMAP) + sdkmc control-plane (FetchEmailListener, AccountItslMailbox, function-mailbox-delegering) + DAV för bilagor. Net-new: IMAP/Mail **read-and-draft**-klient (finns ej), poll i capture-bot-form, bara delegerade brevlådor. **Ingen send-verb i toolsetet.** | PÅ UPPDRAG AV; per-brevlåda → service-konto läser bara brevlåda det fått medlemskap i (sdkmc-ACL/delegering). | Högst-PII-källan (personnummer/BankID/case-UUID → 422 idag). Draft-only-connector utan send-verb = strukturell Human Gate. |
| **Websökning** | ✗ saknas | Ingen provider wired, men `WebSearch`/`WebFetch` finns redan i runnerns allowlist (deployat 2026-07-05). Lägg provider (MCP-search-server ELLER Brave/Tavily REST) på runner-sidan. | Agent AS itself, delad service-API-nyckel. Enda inneboende **publika** källan — ingen per-människa-auth, ingen PII-inbound-risk. | Enda risken = **utgående query** kan läcka case-PII till tredjepart → varje query genom egress-brandväggen. |
| **Zammad** | ✗ saknas (men **bekräftad real**) | **Noll repo-fotavtryck.** Net-new REST-connector mot Zammad `/api/v1/tickets` på runner/agent-sidan, utanför OCS/DAV-trust-gränsen, egen auth + delad PII-brandvägg. | PÅ UPPDRAG AV via **per-människa Zammad API-token** (tickets ägs per agent i Zammad). Kräver secret-vault per människa. | Greenfield, ingen intern prior art, ingen ACL-substrat att luta sig mot. ✅ **Bekräftat att Zammad finns** (Fredrik 2026-07-05) → byggs **SIST (M-F)**; instans-URL/auth/tilldelningsmodell inhämtas då. |

---

## 7. Fasplan

- **M-A · Skills-loader + routing-säkra beskrivningar** — skriv om SKILL.md-descriptions till enrads + CI-lint; bygg per-agent-loader (materialiserar prenumererade skills); itsl-guardrails alltid först; metadata.json. → *Skills blir riktiga aktiverbara enheter.*
- **M-B · Admin-aktiverings-UI** — toggle-grid + routing-map-editor + OCS write-endpoints som emitterar SUBSCRIBED/DECLINED-kvitton. → *Aktiverbart från Hubs adminyta (det explicita kravet).*
- **M-C · Förstklassig runbook-artefakt + case-packet-kedja** — `runbooks/<namn>.md`-format; queue-run.md laddar stegets scope; case-packet-runbook med openbrain-svc som Case Store. → *Multi-stegs Nate-runbooks på vår one-card-per-run-runner.*
- **M-D · Companion Tier-2-kärna** — provenance/tier-flagga på kort+link; EGRESS-läge i PiiFirewall; consent-objekt + verifierad ägarbindning; widened read-only runner-profil (writes draft-only); companion-read-audit. → *Säkerhetsgränssnittet TILLÅTER bred access; sandlådan förblir default.*
- **M-E · Connector 1: portal.hubs.se** read-aggregation (verifiera ACL-scoping först). → *Rikaste companion-ytan, noll ny transport.*
- **M-F · Connectors 2–5:** Talk-historik → Mail → websökning → Zammad (bekräftad real; sist, per-människa-token + secret-vault). PersonalSettings grant/inspect/revoke-UI (med BankID-grade re-auth per grant) skeppar bredvid. → *Full companion-räckvidd, varje källa consent+egress+audit-grindad.*
- **M-G · Innehålls-skills + Maintenance Loop (manuell)** — Meeting Synthesis + Brain Dump Processor, sedan Assumption Checker + Testing Runbook Creator; Maintenance Loop som dokumenterad procedur. → *Hög-värde-skills live; drift fångas tidigt.*

---

## 8. Beslut som krävs (rekommendation i **fet**)

1. **Credential-modell för privata källor:** → **Mixad: per-agent NC-service-konto med explicita ACL-grants för Talk/Mail/portal (beprövat bot-mönster, auditbart "vem läste vad"); per-människa-token bara för externa Zammad.**
2. **Vilken connector först?** → **portal.hubs.se** (noll ny transport, rikast, tvingar ACL-scoping-frågan tidigt). Sedan Talk → Mail → websök → Zammad sist.
3. **Hur separeras injektions-sandlådan från companion-vägen?** → **Per-kort provenance-flagga (firewall-riktning + verktygstak switchar på den) + hård fallback "ingen verifierad grant ⇒ sandlåda"; överväg separata runner-invocation-profiler så companion- och arbetskort-session aldrig delar process.**
4. **Hur stark måste ägarbindningen vara?** → **Separat verifierat-ägar-bekräftelsesteg i PersonalSettings, med BankID-grade re-auth för den initiala breda granten** (instansen använder redan BankID-uid; self-service-ACL är för svag för en persons privata data). — ✅ **BESLUTAT (Fredrik 2026-07-05): BankID-grade re-auth per grant.**
5. **Skill-aktivering: install-tid vs runtime?** → **Install-tid: loadern materialiserar bara agentens prenumererade skills** (Nates fil-kopia-modell, mindre resident kontext, mindre blast radius).

**Beslut (Fredrik 2026-07-05):**
- ✅ **Zammad finns** i ITSL:s riktiga miljö → behålls i planen, byggs **SIST** (M-F) på per-människa API-token + secret-vault (instans-URL/auth inhämtas vid M-F).
- ✅ **Ägarbindning = BankID-grade re-auth per grant** (beslut 4).
- ⏸️ **Var börja:** Fredrik **granskar denna doc först** innan bygget startar — inget byggs än.

---

## 9. Snabba vinster (låg risk, byggbara nu)

- Skriv om de fyra core-SKILL.md-beskrivningarna till enrads trigger-fras-rader + CI-lint (ren författarändring, tar bort tyst-routing-brott-risken).
- Fyll "Optional standing skill directory"-katalogkortet + metadata.json per skill.
- Bygg portal read-aggregations-endpoint genom att återanvända `capture-bot/engine.js`:s service-konto-OCS-mönster (pending ACL-verifiering).
- Wire en websök-provider i de redan befintliga WebSearch/WebFetch-slotsen med utgående-query-PII-brandvägg.
- Gör routing-map-sektionen i AdminSettings till en editor.
- Adoptera Maintenance Loop som dokumenterad procedur med keep/change/pause/retire-post på liggaren.
- Lägg `runbooks/<namn>.md`-formatet + författa case-packet-runbooken som doc-artefakt (chain/handoff/payoff) FÖRE exekverings-kod.

---

## 10. Risker (topp)

- **portal OCS ACL-scoping overifierad** → kan under-serva eller värre, visa ärenden människan ej är behörig till. Verifiera per endpoint före M-E.
- **EGRESS-flippen inverterar invarianten** → bugg i riktningsval (behandla otillförlitligt kort som owner-trusted) låter fientligt kort exfiltrera människans PII. Fallback-regeln + provenance-flaggan måste vara vattentäta, helst separata runner-processprofiler.
- **Läsa privat Talk/DM kräver att service-kontot GÅR MED** i rummet (synligt, ändrar deltagarlista) — social/consent-fråga.
- **Widened `--allowedTools` för owner-read** riskerar scope creep tillbaka till write/exfil-verktyg → profilen måste vara read-only, writes draft-only, upprätthållet **strukturellt** (ingen send/publish-verb existerar), inte per instruktion.
- **Per-agent-loadern är net-new kod** vi/Nate inte byggt → tyst fel-laddning gör admin-aktivering till fiktion. Behöver verify-by-doing-självtest (AGENT APPLIED per skill).
- **Zammad kanske inte finns** → bygg inte connectorn före bekräftelse.
- **Websök utgående query kan läcka case-PII** → egress-brandvägg på query-texten, inte bara lagrat innehåll.
