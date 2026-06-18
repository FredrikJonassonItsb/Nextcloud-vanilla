<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Kontakter-favoriter ovanpå DIGG/SDK — byggbart designdokument

> **Vad detta är:** syntesen av tre PM (teknisk kapacitet, favorit-arkitektur, gallring/GDPR) till **ett**
> byggbart svar på frågan: *Kan Kontakter-appen användas som-den-är, eller måste den utökas, för att
> hantera favoriter (faxnummer, ofta-kontaktade funktioner/användare)?*
>
> **Hårt krav (oförhandlingsbart):** DIGG/SDK-adressboken är **source of truth**. Favoriter får **aldrig**
> bli ett skuggregister som divergerar från DIGG eller som samlar medborgar-PII utanför ärendet.
>
> **Plattform:** server v32. **Verifierat i demo-container:** Kontakter **v8.3.12**
> (`/var/www/html/custom_apps/contacts/appinfo/info.xml` → `<version>8.3.12</version>`), ren CardDAV/SabreDAV-frontend,
> vCard 3.0/4.0. **Datum:** 2026-06-15.
>
> **Arkitekturgrund:** `HUBS-ARKITEKTUR-SOCIALTJANST.md` (kanonisk `hubsCaseId`, server-side-aggregat, ingen
> klient-fan-out). **Ytan:** `UI-EVOLUTION-SOCIALSEKRETERARE.md` (KorgValjare, InflodeRad, EjKoppladSektion
> `@vidarebefordra`/`@besvara`, KopplingBadge/ProvenansChip-familjen).
>
> **Varumärkesregel (enforced):** i kund-/UI-nära text aldrig "Nextcloud"/"Talk"/"Circles". Vi säger *Hubs,
> ärenderum, säkert möte, säkert meddelande, korg, funktionsadress, ärendechatt, enhetschatt, team, Kontakter*.
> Facksystem = Treserva/Lifecare/Viva. App-id/tekniska namn (Kontakter-appen, CardDAV, `IManager`, sdkmc) nämns
> **bara** i bygg-/arkitekturnoteringar.

---

## 1. Sammanfattning + VERDIKT

**VERDIKT: Använd Kontakter-appen SOM-DEN-ÄR för lagring — och lägg ETT tunt sdkmc-resolverlager ovanpå.
Ingen fork, inga schemaändringar, ingen ny app.** Utökningen sker på **styrnings- och resolverytan i sdkmc
(ITSL-kod), inte i Kontakter-appen**.

Som-den-är räcker **inte** ensamt, av en enda anledning: Kontakter-appen v8.3.12 har **ingen native
favorit-stjärna per kontakt** (verifierat: `grep favourite|favorite` i `/var/www/html/custom_apps/contacts/lib`
ger 0 träffar; de enda `favorite`-träffarna i frontend-bundlen är **fil**-favoriter från fil-väljaren, inte
kontakter; upstream-feature-requesten [nextcloud/contacts#105](https://github.com/nextcloud/contacts/issues/105)
"Sort favorite contacts first" är öppen sedan 2017). Det finns alltså ingen stjärn-flagga att skriva till.

Men allt **innehåll** en favorit behöver är vanlig vCard och native CardDAV: flera adressböcker per användare,
read-only-delning via DAV-ACL, grupper via `CATEGORIES`, `TEL;TYPE=fax`, bevarade `X-*`-properties och
server-side-sök via `OCP\Contacts\IManager::search` (ett anrop över alla användarens adressböcker — exakt
"ingen klient-fan-out"-ytan). Det enda som måste byggas är intelligensen som vet att en pekare ska resolvas
mot DIGG — och den ska medvetet **inte** ligga i Kontakter (det är så vi håller sanningen i DIGG).

**Den bärande principen (samma grammatik som `hubsCaseId` för ärenden):** *en favorit är en PEKARE, inte en
post.* Favoriten bär en stabil nyckel (`X-HUBS-SDK-REF`) plus icke-auktoritativ visningscache; de föränderliga
fälten (adress, certifikat/LOA, funktionsbeteckning) resolvas **färskt** ur DIGG vid läsning. Eftersom favoriten
aldrig *äger* dessa fält är divergens **arkitektoniskt omöjlig** — det är så vi uppfyller skuggregister-kravet
by design, inte by policy.

**Tre låsta designbeslut (guardrails):**
1. **Favoriter = pekare, inte kopia** — utom klass (b), se §2.
2. **Favoriter avgränsas till låg-PII** (funktion/myndighet/fax/intern). **Medborgar-PII blockeras** och hör
   hemma i ärendet/Treserva under `hubsCaseId`.
3. **GallringsGrind framför favoriten** — varje favorit klassas och får ett gallrings-/bevarandebeslut
   server-side innan den blir en kvarliggande post (§4).

---

## 2. Favoritmodellen

### 2.1 Pekarmodellen — favoriten är en pekare, inte en kopia

```
KANONISK POST (DIGG/SDK — source of truth)        FAVORIT (Kontakter, i Hubs)
┌─────────────────────────────────────┐           ┌──────────────────────────────────┐
│ orgId:        SE2120001234           │◄──────────┤ X-HUBS-SDK-REF: SE2120001234     │  ← pekaren (oföränderlig)
│ funktion:     Vuxenpsykiatri mott.   │  resolve  │ FN (cache):    "Vuxenpsyk. mott." │  ← visningscache (får vara stale)
│ SDK-adress:   sdk://...              │  ───────► │ X-HUBS-RESOLVED-AT: 2026-06-15    │  ← staleness-stämpel
│ cert/LOA:     SITHS · LOA3           │           │ CATEGORIES:    favorit, funktion  │  ← grupp (vCard CATEGORIES)
│ uppdaterad:   2026-06-12 (DIGG)      │           │ (INGA kopierade adress/cert-fält) │
└─────────────────────────────────────┘           └──────────────────────────────────┘
```

**Regeln:** favoritens vCard får bära **nyckeln** (`X-HUBS-SDK-REF`) + **icke-auktoritativ visningscache**
(`FN`, en etikett) + **metadata** (`X-HUBS-RESOLVED-AT`, ägare). Den får **aldrig** bära den auktoritativa
adressen/certet/funktionsbeteckningen som ett "sant" fält — de hämtas vid läsning. Cachen får aldrig vara
auktoritativ; vid användning verifieras/visas data mot DIGG.

### 2.2 Tre favorit-klasser — datamodell, ägande, PII, gallring

| Klass | Vad | Lagras som | Äger sanningen | PII |
|---|---|---|---|---|
| **(a) Myndighets-/funktionsadress** | SDK-adress ur DIGG (annan myndighet, funktionsadress) | **Ren pekare** — vCard med `X-HUBS-SDK-REF`, inga kopierade föränderliga fält | **DIGG/SDK** | Låg (organisation) |
| **(b) Externt fax/funktion som INTE finns i DIGG** | Skolexpedition-fax, region-fax, mottagning utan SDK | **Egen vCard-post** (`TEL;TYPE=fax`) — Hubs äger värdet | **Hubs (kommunen)** | Låg–medel (org.anknutet) |
| **(c) Ofta-kontaktad intern användare/team** | Kollega, gruppledare, funktionsteam | **Pekare** till användarkatalogen (`X-HUBS-USER-REF`) | **Hubs användarkatalog** | Medel (medarbetare) |

**Klass (a) — ren pekare (huvudfallet, ~80 % av nyttan, lägst risk).** Bygg detta först. Adress/cert/funktion
resolvas, kopieras aldrig. Gallring är trivial: det är ett bokmärke utan eget sanningsinnehåll. Försvinner
DIGG-posten → tombstone (§2.3).

```vcard
BEGIN:VCARD
VERSION:4.0
FN:Vuxenpsykiatrin, mottagning (DIGG)
ORG:Region Skåne
KIND:org
X-HUBS-SDK-REF:SE2120001234            ← pekaren = orgId, källa till sanning i DIGG
X-HUBS-FAVORIT-KLASS:sdk-pekare
X-HUBS-RESOLVED-AT:2026-06-15T08:00:00
CATEGORIES:favorit,funktion,myndighet
REV:2026-06-15T08:00:00Z
END:VCARD
```

**Klass (b) — egen vCard-post (enda klassen där Hubs äger ett värde).** Det finns ingen DIGG-post att peka på;
detta är legitimt en egen post. Kräver en **förvaltande funktions-ägare** (`X-HUBS-OWNER` = funktionsadress/team,
aldrig en individ — annars dör posten när personen slutar). Låg–medel PII, organisationsanknutet; en namngiven
privatpersons fax hör **inte** hit. Gallring: ingen automatisk tidsgallring (ej ärendebunden), men **årlig
förvaltningsöversyn** per funktions-adressbok (§4).

```vcard
BEGIN:VCARD
VERSION:4.0
FN:Lindängsskolan, expedition (fax)
ORG:Malmö stad / Lindängsskolan
KIND:org
TEL;TYPE=fax,work;VALUE=text:+46 40 12 34 56     ← Hubs äger detta värde
X-HUBS-FAVORIT-KLASS:extern-funktion
X-HUBS-OWNER:funktion:mottagningen@               ← förvaltande ägare (funktion, ej individ)
CATEGORIES:favorit,fax,extern
REV:2026-06-15T08:00:00Z
END:VCARD
```

**Klass (c) — pekare till användarkatalogen.** Sanning i Hubs användarkatalog (tekniskt: system-adressbok
`z-server-generated--system`, verifierad i container). Namn/funktion/närvaro resolvas därifrån. Tombstone när
`uid` avaktiveras.

```vcard
BEGIN:VCARD
VERSION:4.0
FN:Eva (gruppledare)                              ← cache; sanning i användarkatalogen
KIND:individual
X-HUBS-USER-REF:eva                               ← pekaren = uid
X-HUBS-FAVORIT-KLASS:intern-anvandare
CATEGORIES:favorit,intern
REV:2026-06-15T08:00:00Z
END:VCARD
```

> **⚠️ Den fjärde "klassen" som INTE får finnas — medborgar-PII.** En medborgares/klients kontaktuppgift är
> **hög-PII** och är en del av *ärendet* (OSL 26 kap.). Den hör hemma i **ärenderummet/Treserva** under
> `hubsCaseId` — aldrig i en fri favoritlista (det vore ändamålsbrott, sekretessrisk och potentiellt ett
> oregistrerat personregister). **Favorit ≠ ärendepart.** Vårdnadshavare/ombud är mellankategorin: som
> huvudregel i ärendet, inte i en fri lista (villkorat undantag i §4).

### 2.3 Resolution, staleness, tombstone (sdkmc-resolverlagret)

Vid varje läsning som behöver färska fält går flödet genom **ett** server-side-anrop, aldrig per-favorit-fan-out
från klienten:

1. Klienten ber om "mina favoriter" → sdkmc kör `IManager::search('', [...])` över personlig + funktions-delad
   adressbok (**ett** anrop).
2. För varje pekare batch-resolvar sdkmc `orgId`/`uid` mot DIGG-/användarkatalog-cachen (TTL, t.ex. 24 h, +
   webhook-invalidering vid DIGG-push) — **ingen ny integration**, återanvänder befintliga SDK-synktjänstens access.
3. sdkmc mergar pekare + färska fält till en DTO och returnerar `{färska fält, resolvedAt, stale?, removed?}`.

- **Staleness-UI:** varje resolvad favorit visar en diskret rad **"Uppdaterad via DIGG 12/6"** (samma
  `ProvenansChip`-grammatik som ärendekortet). Äldre än policygräns → grå "kunde inte färskhetskontrolleras"-ton;
  aldrig fel data presenterad som färsk.
- **Tombstone:** om `orgId`/`uid` inte längre finns i källan returnerar resolvern `{removed:true}`. Favoriten
  visas **överstruken** med varning *"Den här adressen finns inte längre i DIGG — kan inte användas som
  mottagare"* + åtgärd "Ta bort favorit", och blir **icke-väljbar** i komponering. En borttagen DIGG-post kan
  aldrig leva vidare som en användbar skugga. Detta är även den tekniska inaktualitetsgallringen (§4).

### 2.4 Personlig vs funktions-delad lista

Två adressböcker, samma vCard-modell, olika ACL — **inget i Kontakter behöver utökas** (CardDAV-delning med ACL
verifierad i container):

```
┌─ Personlig favorit-adressbok ──────────┐   ┌─ Funktions-delad favorit-adressbok ─────────┐
│ Ägare: handläggaren (uid)              │   │ Ägare: funktion mottagningen@ (team)         │
│ ACL:   privat                          │   │ ACL:   delad READ-ONLY till teamets medlemmar│
│ Inneh: "mina" ofta-kontaktade          │   │ Inneh: enhetens gemensamma fax/funktions-    │
│        (mix av klass a/b/c)            │   │        adresser (skolor, region, partners)   │
│ Gallr: personen rår; städas vid        │   │ Förvaltas av: utsedd ägar-roll i teamet      │
│        behörighetsupphörande           │   │ Gallr: årlig förvaltningsöversyn (§4)        │
└────────────────────────────────────────┘   └──────────────────────────────────────────────┘
```

Den funktions-delade listan är **read-only** för vanliga teammedlemmar; en utsedd förvaltare har skriv. Det
löser **kontinuiteten**: kunskapen om "rätt fax till Lindängsskolan" dör inte med en medarbetare — den ägs av
funktionen, precis som korgar och ärenderum. **Default-vy = union:** i komponering ser handläggaren
**personliga ∪ funktions-delade** favoriter i ett flöde (resolvern slår ihop server-side i ETT anrop), med en
liten filter-pill **[Mina] [mottagningen@]** (samma korg-/scope-grammatik som huvudvyn).

---

## 3. Teknisk lösning

### 3.1 Vad Kontakter klarar SOM-DEN-ÄR (verifierat i container)

| Behov | Native-stöd (Kontakter v8.3.12 / CardDAV) | Verifierat |
|---|---|---|
| Flera favoritlistor (personlig + delad) | Flera adressböcker per användare | ✓ `UserAddressBooks`, `AddressBookImpl` |
| Funktions-delad **read-only** lista | CardDAV-delning med ACL | ✓ `Sharing/Backend` / `GroupSharingService`, `applyShareAcl` |
| Gruppera favoriter (favorit/fax/funktion) | vCard `CATEGORIES` (kontaktgrupper) | ✓ `CardDavBackend` searchProperties |
| Faxnummer | `TEL;TYPE=fax` | ✓ standard vCard 3.0/4.0 |
| Pekar-fält (`X-HUBS-SDK-REF` m.fl.) | `X-*`-properties bevaras av SabreDAV | ✓ okända props serialiseras |
| **Server-side sök/aggregat över alla listor i ETT anrop** | `OCP\Contacts\IManager::search($pattern, $props, $options)` | ✓ matchar "ingen klient-fan-out" |
| Intern-användar-pekare | system-adressbok `z-server-generated--system` | ✓ `SystemAddressbook` |

**Slutsats:** Kontakter behöver **ingen fork och ingen utökning** för lagring, gruppering, delning eller sökning.
Allt favorit-*innehåll* är vanlig vCard. `IManager::search` är den verifierade server-side-ytan (appen själv
använder den i `ContactsMenu/Providers/DetailsProvider.php` och `Service/SocialApiService.php`).

> **Bygg-ärlighet (känt beteende att verifiera per serverversion):** på äldre serverversioner returnerade
> `IManager::search` med tomt `$pattern` bara systemanvändare; på nyare ingår personliga kontakter. För en
> **riktad** sökterm är beteendet stabilt. Favorit-aggregatet ska därför iterera de explicita favorit-adress-
> böckerna, inte förlita sig på tomt-pattern-semantik. `$options['types']=true` returnerar TYPE-fält som
> arrayer (skiljer `fax` från `cell`).

### 3.2 Det enda som måste byggas — det tunna sdkmc-resolverlagret (ITSL-kod, ej i Kontakter)

Det Kontakter *inte* kan (och inte ska kunna) är att veta att `X-HUBS-SDK-REF` betyder "resolva mot DIGG". Det
är **avsiktligt** — det håller sanningen i DIGG. Det som byggs ligger i sdkmc:

1. **OCS-route `/api/v1/favoriter`** — anropar `IManager::search('', ['FN','TEL','CATEGORIES','X-HUBS-SDK-REF','X-HUBS-USER-REF'])`
   över personlig + funktions-delad favorit-adressbok (server-side, **ett** anrop), batch-resolvar varje pekare
   mot DIGG-/användarkatalog-cachen, returnerar resolvad DTO med `{färska fält, resolvedAt, stale?, removed?}`.
2. **DIGG-resolvercache** — återanvänder den befintliga SDK-synktjänstens DIGG-access (TTL + webhook-invalidering).
   Ingen ny integration.
3. **Spara-favorit-validering (GallringsGrind, §4)** — sätter rätt `X-HUBS-FAVORIT-KLASS`, blockerar
   medborgar-PII, kräver `X-HUBS-OWNER` (funktion) på klass (b). Implementeras **server-side** så regeln inte
   kan kringgås per klient.
4. **Klient-glue i vyn** — `FavoritValjare.vue` (ny atom i samma familj som `KopplingBadge`/`ProvenansChip`) i
   mottagar-/komponeringsytan; konsumerar `fetchFavoriter()`. **Inga ändringar i Kontakter-appens UI.**

### 3.3 Ytan i vyn — "Smart mottagare" i komponering

Favoriter syns där en **mottagare** ska väljas — aldrig som ett eget kort i ärendeströmmen (samma
kognitiv-last-disciplin som chatt: en yta, inte en trettonde widget; **inte** i Zon 1–4).

```
┌─ Till: ─────────────────────────────────────────────────────────┐
│ [⌕ Sök mottagare eller välj favorit…]                            │
│ ── Favoriter ─────────────────  [Mina] [mottagningen@]           │
│  ⭐ Vuxenpsyk. mott. (DIGG)   · Uppdaterad via DIGG 12/6  ✓SDK    │  ← klass (a), resolvad färskt
│  ⭐ Lindängsskolan, fax       · Hubs-förvaltad           ⎘fax    │  ← klass (b)
│  ⭐ Eva (gruppledare)         · intern                   ●online │  ← klass (c)
│  ⊘ Gamla mott. (borttagen i DIGG — kan ej väljas)               │  ← tombstone (§2.3)
└─────────────────────────────────────────────────────────────────┘
```

En enda server-side-aggregat-route (`fetchFavoriter()` → resolvad union). Varje rad bär **klass-markör +
färskhets-/proveniens-chip**: klass (a) visar "✓ verifierad SDK-adress (LOA3)" eftersom certet resolvas färskt
(stark tillit-signal vid mottagarval), klass (b) "Hubs-förvaltad", klass (c) "intern". Favoriter kopplar direkt
in i befintliga åtgärder i inflöde-banden — `EjKoppladSektion` `@vidarebefordra` (mottagar-väljaren *är*
favorit-väljaren) och `@besvara`/"besvara utan ärende". Demodata `inf-9` ("möjlig felrouting → Region –
vuxenpsykiatri", `src/services/demo/socialsekreterare.js`) är prov-caset för favorit-driven vidarebefordran.

### 3.4 Koppling till `hubsCaseId` — håll isär

En favorit kan *användas* vid en åtgärd som rör ett ärende (vidarebefordra ett `case:X`-meddelande till en
favorit-mottagare); då bär *meddelandet/åtgärden* `case:{hubsCaseId}` enligt huvudarkitekturen — men favoriten
själv taggas **aldrig** med `hubsCaseId`. Favoriten lever i favorit-adressboken; ärendekopplingen lever på
objektet. Samma separation som "korg ≠ ärende". Blir en favorit-mottagare faktisk *part* i ett ärende
registreras den i ärenderummet under `hubsCaseId`, inte genom att favoriten "blir" ärendeknuten.

---

## 4. Gallring & GDPR-rutin

### 4.1 Allmän handling? (TF 2 kap.)

| Typ av favoritlista | Allmän handling? | Motivering |
|---|---|---|
| **Delad favoritlista** (funktions-förvaltad) | **Ja, sannolikt** (förvaras + upprättad) | Jämförbar med ett internt adressregister/funktionskatalog. |
| **Personlig favoritlista** (används i tjänsten) | **Gråzon → behandla som allmän handling** | Praktisk regel: anta allmän handling, så undviks feldimensionerad gallring. |
| **Ren pekare/cache mot SDK** | Tveksamt självständig handling, men spegling fritar **inte** från GDPR-ansvar | Klass (a)/(c) är arbetshjälp utan eget sanningsinnehåll. |

**Konsekvens:** är listan allmän handling får den **inte gallras godtyckligt** — gallring kräver stöd i
**gallringsbeslut** (kommun: arkivreglemente + **dokumenthanteringsplan**, där den antagna planen *är*
gallringsbeslutet; statlig SDK-part: Riksarkivets RA-FS). Favoritlistor (delad + personlig) ska därför **in i
dokumenthanteringsplanen som egna handlingstyper** med uttryckligt gallrings-/bevarandebeslut, frist och ansvar.

### 4.2 Skuggregister-guardrailen (huvudregeln)

DIGG/SDK upprätthåller riktigheten centralt; en lokalt kopierad PII-lista vore per definition **sämre data**
över tid → exakt det skuggregister kravet förbjuder. **Avgränsning som kodas in:** favoriter får **endast**
referera poster vars källa är DIGG/SDK-spegeln eller den interna funktions-/användarkatalogen — och klass (b)
är enda undantaget där Hubs äger ett (låg-PII, organisationsanknutet) värde. **Fri inmatning av medborgar-PII
blockeras** i sdkmc-lagret.

**Tillåt favoritmarkering av (låg PII):** funktionsadresser/funktionsbrevlådor, myndigheter/SDK-deltagare,
faxnummer till funktioner/mottagningar, interna funktioner/team/korgar.
**Tillåt INTE som fri favorit (hög PII → hör hemma i ärendet):** medborgare/klienter som privatpersoner →
Treserva/Lifecare/Viva eller ärenderummet.

### 4.3 Gallringsfrist & inaktualitet

| Objekt | Frist / översyn | Mekanism |
|---|---|---|
| **Delad funktions-/fax-favoritlista** | **Årlig översyn** + händelsestyrd | Inaktualitetsgallring mot SDK (tombstone) + årlig granskning av registeransvarig |
| **Personlig favoritlista** | Översyn vid behov; **hård gallring senast vid behörighetsupphörande** | Vid offboarding gallras/anonymiseras den personliga adressboken — aldrig automatisk arvföring av PII |
| **Ev. ärendebunden vårdnadshavar-pekare (villkorat)** | **Ärver ärendets gallringsfrist** | Gallras med ärendet i facksystemet |

**Automatisk inaktualitetsgallring (kärngaranti mot divergens):** favoriten lagrar SDK-referens + minimal
cache; en schemalagd server-side-verifiering (via `IManager`/SDK-uppslag — ingen klient-fan-out) kontrollerar
att referensen finns kvar. **Post borttagen/ändrad i DIGG → pekaren markeras inaktuell och gallras/flaggas**
(samma tombstone-mekanism som §2.3). Ingen automatisk `files_retention`-tidsgallring på favoriter (de är inte
ärendebundna).

**Vårdnadshavare/ombud — villkorat undantag** accepteras endast om **samtliga** gäller: (1) favoriten ligger i
en **ärenderums-scoped** kontext (ej fri lista), (2) den **ärver ärendets gallringsfrist**, (3) den lagras som
**pekare** mot facksystemets/ärendets post, (4) sekretessbedömning är gjord. Utanför dessa villkor: nej.

### 4.4 Logg & ansvar

- **Logg:** skapande/ändring/gallring av favoriter loggas för spårbarhet (`activity`), men **loggraden
  minimeras** (referens/ID + händelse, inte PII) och får **egen gallringsfrist** — loggen får inte själv bli ett
  PII-skuggregister.
- **Personuppgiftsansvarig:** nämnden/myndigheten (inte enskild handläggare). **Laglig grund:** normalt art. 6.1.e
  (allmänt intresse/myndighetsutövning) i förening med verksamhetsförfattning — **inte** samtycke. **Ändamål:**
  adresseringsstöd, inte ett personregister över medborgare (ändamålsbegränsning, jfr EU-domstolen C-77/21).
- **Registeransvarig för delad lista:** utsedd funktion (enhetschef/systemförvaltare) ansvarar för årlig översyn.
- **Personuppgiftsbiträde:** driftleverantör regleras i biträdesavtal (favoritdata får ej användas för andra ändamål).

### 4.5 GallringsGrind-återbruk

GallringsGrind-mönstret (handlingstyp → gallrings-/bevarandebeslut) återanvänds rakt av: varje favorit klassas
**server-side** vid skapande och får ett beslut innan den blir en kvarliggande post.

```
Ny favorit  ──►  Klassificera handlingstyp (server-side)
                  │
                  ├─ Funktion/myndighet/fax (låg PII)  [klass a/b/c]
                  │     ──► TILLÅT. Beslut: bevaras m. inaktualitets-gallring
                  │          + årlig översyn (delad) / behörighetsbunden (personlig)
                  │
                  ├─ Medborgare/klient (hög PII, fri lista)
                  │     ──► BLOCKERA. Styr till "spara i ärenderummet" (case:{hubsCaseId})
                  │
                  └─ Vårdnadshavare/ombud (hög PII, ärendebunden)
                        ──► TILLÅT ENDAST om ärenderums-scoped + ärver ärendets
                             gallringsfrist + pekare + sekretessbedömd; annars BLOCKERA
```

Varje gren motsvarar en rad i kommunens dokumenthanteringsplan (handlingstyp → bevaras/gallras, frist, ansvar)
— så favorit-funktionen är från dag ett förankrad i ett antaget gallringsbeslut.

---

## 5. Förutsättningar & öppna punkter

**Måste byggas (sdkmc — ITSL-kod):**
- OCS-route `/api/v1/favoriter` (server-side resolvad aggregat-DTO; `IManager::search` över favorit-adressböckerna).
- DIGG-resolvercache (återanvänder befintlig SDK-synktjänst; TTL + webhook-invalidering) — **förutsätter att
  SDK-synken redan exponerar batch-resolve på `orgId[]`**; om inte, byggs ett tunt batch-uppslag.
- Spara-validering / GallringsGrind server-side (klass-sättning, medborgar-PII-spärr, `X-HUBS-OWNER`-krav på (b)).
- Schemalagd inaktualitetsverifiering (cron i sdkmc) som sätter tombstone på döda pekare.
- `FavoritValjare.vue` i komponerings-/mottagar-ytan + inkoppling i `EjKoppladSektion` `@vidarebefordra`/`@besvara`.

**Som-den-är (ingen kodändring i Kontakter):** adressböcker (personlig + delad read-only), `CATEGORIES`-grupper,
`TEL;TYPE=fax`, `X-*`-properties, `IManager::search`, system-adressbok. **Ingen fork.**

**Engångs-setup (drift):** systemanvändare (t.ex. `sdk-katalog`) som äger DIGG-spegel-adressboken, delad
read-only; favorit-adressböcker per användare/team.

**Policy per kommun (ej kod):** förvaltningsöversyns-intervall för klass (b); för in favoritlistor i
dokumenthanteringsplanen som egna handlingstyper; förankra "favoritlista ≠ allmän handling av bevarandevärde"
samt laglig grund (6.1.e) med kommunarkivarie/dataskyddsombud; rutin vid avslutad användare (personlig adressbok).

**Stubbat i demon (idag):** ingen riktig DIGG-resolver — favorit-DTO:n mockas i `src/services/demo/`; `inf-9` är
prov-caset för vidarebefordran. Resolvercachens TTL/webhook och inaktualitets-cron är ännu inte implementerade.

**Öppna punkter att verifiera:**
- `IManager::search`-scope för tomt `$pattern` i **just denna serverversion** (v32) — bekräfta att favorit-
  aggregatet itererar explicita adressböcker snarare än att lita på tomt-pattern-semantik (§3.1).
- Om produkten kräver att favoriter syns *inne i* Kontakter-appens UI / syncas till externa CardDAV-klienter:
  då (och endast då) kompletteras klass (a) med ett **tunt vCard-kort** i en synlig favoriter-adressbok — som
  **måste låsas/regenereras mot `REV` från DIGG** för att inte bli en andra sanning. Default är att inte göra
  detta (favoriter är ett Hubs-koncept i komponeringsytan).
- Fastställ exakt `orgId`-nyckelformat och cert/LOA-fält som resolvern returnerar från DIGG (för "✓ verifierad
  SDK-adress (LOA3)"-chippet).
- `CATEGORIES:Favoriter`-tagg på själva DIGG-spegeln är **avrådd** (spegeln är read-only; skrivning bryter
  source-of-truth/skapar skuggregister). Favorit-markering sker via pekare i favorit-adressboken, inte via tagg
  på spegeln.
