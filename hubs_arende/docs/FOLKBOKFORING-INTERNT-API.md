---
titel: API-kontrakt — kommun-internt folkbokföringsuppslag (Hubs ↔ Frends)
status: Kontrakt v1.0 (utkast) — leverabel till Frends-teamet, 2026-07-06
uppfyller: K-NAV-3.1 (kravställningen KRAVSTALLNING-NAVET-FOLKBOKFORING.md §3)
relaterat: hubs_start/docs/KRAVSTALLNING-NAVET-FOLKBOKFORING.md, hubs_start/docs/ANALYS-HANDLING-FRAN-MALL.md (partsregistret §3.4)
mottagare: Frends-teamet (implementation av kommun-flödet) + Hubs (FolkbokforingClient binder mot detta)
---

# API-kontrakt — kommun-internt folkbokföringsuppslag

Detta dokument är det **stabila interna API-kontraktet** mellan Hubs (ärendemotorn
`hubs_arende`) och kommunens Frends-instans (iPaaS) för folkbokföringsuppslag mot
Skatteverkets Navet. Det är leverabeln till Frends-teamet enligt **K-NAV-3.1**.

Kontraktet är avsiktligt **Skatteverket-agnostiskt på Hubs-sidan**: Hubs känner bara till
detta kontrakt. Allt Skatteverket-specifikt (certifikat, OAuth2, beställningsidentitet,
v3-svarets XML/JSON-struktur) är Frends-flödets ansvar och byts ut utan att Hubs berörs.

---

## 1. Syfte & arkitektur

Hubs ska kunna slå upp **folkbokföringsuppgifter ur personnummer** (namn, adresser,
vårdnadshavare, avregistrering, **skyddsstatus**) för att fylla **partsregistret**
(`oc_hubs_arende_part`). Uppslaget sker alltid i ärendekontext av behörig handläggare
(K-NAV-4.2) och journalförs på Hubs-sidan.

**Hubs anropar ALDRIG Skatteverket direkt.** Kedjan är:

```
Hubs (hubs_arende)                    Kommunens Frends              Skatteverket
┌──────────────────────┐   internt   ┌──────────────────┐  OAuth2  ┌──────────────┐
│ FolkbokforingClient ──┼──── API ───►│ Navet-flöde       ├── CCG ──►│ REST v3      │
│  → PartService        │  (DETTA     │ (per-kommun cert, │  mTLS    │ /v3/hamta    │
│  → oc_hubs_arende_part│  KONTRAKT)  │  beställnings-id) │          │              │
└──────────────────────┘             └──────────────────┘          └──────────────┘
```

- **Hubs-sidan:** `FolkbokforingClient` (Port/Client-mönstret) skickar en batch
  personnummer + korrelations-id + ändamål + ärendereferens och får normaliserade
  personposter tillbaka.
- **Frends-sidan:** ett flöde per kommun som autentiserar mot Skatteverket, anropar
  `POST /folkbokforing/folkbokforingsuppgifter-for-offentliga-aktorer/v3/hamta`,
  och **mappar** v3-svaret till den normaliserade personpost-shapen i §2.3.
- **Multi-tenant:** varje kommun har egen bas-URL/klientidentitet, eget
  Expisoft-certifikat och egen beställningsidentitet (K-NAV-2.3). Ingen delad
  identitet över kommungränser.

---

## 2. Endpoint-specifikation

### 2.1 Uppslag

```
POST {bas-url}/folkbokforing/uppslag
Content-Type: application/json
Authorization: Bearer <token>
```

- `{bas-url}` är kommunens Frends-endpoint och konfigureras per kommun i Hubs.
- **Auth: Bearer-token** som arbetsantagande — exakt mekanism (statisk API-nyckel,
  OAuth2 client credentials i Frends, mTLS internt) **justeras vid onboarding** av
  pilotkommunen. Kontraktets request/response-shape påverkas inte av valet.
- Transporten är kommun-internt nät/VPN; TLS 1.2+ krävs oavsett.

### 2.2 Request

```json
{
  "personnummer": ["191212121212", "200501019876"],
  "korrelationsId": "hubs-uppslag-0f2c7a1e-4b9d-4e2a-9c31-5d8f0a6b2e44",
  "andamal": "partsregister_ifyllnad",
  "arendeRef": "ARENDE-2026-0142"
}
```

| Fält | Typ | Krav | Beskrivning |
|---|---|---|---|
| `personnummer` | `string[]` | obligatoriskt | Person-/samordningsnummer, **12 siffror** (`AAAAMMDDNNNN`, utan sekel-avdrag, utan bindestreck). **Min 1, max 900** per anrop (Skatteverkets batchtak för `/v3/hamta`). |
| `korrelationsId` | `string` | obligatoriskt | Genereras av Hubs per uppslag, unikt, kopplat till handläggare+ärende+tidpunkt i Hubs journal (K-NAV-3.2). **Frends SKA propagera detta som `skv_client_correlation_id` i anropet mot Skatteverket** så att hela kedjan kan korreleras vid felsökning — utan att någonsin logga personnummer. |
| `andamal` | `string` | obligatoriskt | Maskinläsbart ändamål (t.ex. `partsregister_ifyllnad`, `part_uppdatering`). Ingår i Frends anropslogg (utan PII). |
| `arendeRef` | `string` | obligatoriskt | Hubs ärendereferens. Bekräftar att uppslaget sker i ärendekontext (SoLPuL 6 § nödvändighet, K-NAV-4.2). Frends behöver inte tolka värdet, bara kräva att det finns och ta med det i (PII-fri) logg. |

### 2.3 Response — normaliserad personpost

```
HTTP 200
Content-Type: application/json
```

```json
{
  "personposter": {
    "191212121212": { ...personpost... },
    "200501019876": null
  }
}
```

- `personposter` är ett objekt **nycklat på begärt personnummer**. **Varje begärt
  personnummer SKA finnas som nyckel i svaret.**
- Värdet är en **personpost** (nedan) eller **`null` om personen inte finns i
  folkbokföringen**. Saknad person är alltså **INTE ett HTTP-fel** (§4) — Hubs mappar
  `null` till status "ej i folkbokföringen" (K-NAV-2.9).

**Personpost-shapen (normativ):**

```json
{
  "personnummer": "191212121212",
  "tidigareBeteckningar": ["191212120000"],
  "namn": {
    "fornamn": "Tolvan",
    "mellannamn": null,
    "efternamn": "Tolvansson"
  },
  "kontaktadress": {
    "rader": ["Testgatan 12", "Lgh 1101"],
    "postnummer": "12345",
    "postort": "TESTSTAD"
  },
  "sarskildPostadress": null,
  "skydd": "ingen",
  "avregistrering": null,
  "relationer": [
    {
      "typ": "VF",
      "personnummer": "201801019999",
      "namn": "Barn Tolvansson",
      "tomDatum": null
    }
  ],
  "fodelsetid": "1912-12-12"
}
```

| Fält | Typ | Beskrivning |
|---|---|---|
| `personnummer` | `string` | Aktuell identitetsbeteckning, 12 siffror `AAAAMMDDNNNN`. Kan skilja sig från det begärda numret vid nummerbyte — det **begärda** numret är nyckeln i `personposter`, detta fält bär det **aktuella**. |
| `tidigareBeteckningar` | `string[]` | Identitetshistorik via hänvisningsnummer (NYA metoden, §3). Tom array om inga byten. |
| `namn` | `objekt` | `fornamn: string`, `mellannamn: string\|null`, `efternamn: string`. |
| `kontaktadress` | `objekt\|null` | `rader: string[]` (1–n utdelningsrader), `postnummer: string`, `postort: string`. Mappas från **v3-elementet `kontaktadress`** (Skatteverkets egen sammanvägning, K-NAV-2.6). `null` om ingen adress levereras — **SKA vara `null` vid `skydd = skyddad_folkbokforing`** (§3). |
| `sarskildPostadress` | `objekt\|null` | Samma shape som `kontaktadress`. Lagras separat på Hubs-sidan (skyddsrelevant — vid skyddad folkbokföring är detta den ENDA utskicksadressen, K-NAV-5.2). |
| `skydd` | `enum` | **`ingen` \| `sekretessmarkering` \| `skyddad_folkbokforing`. OBLIGATORISKT i varje personpost.** Hubs tillämpar **fail-closed** (K-NAV-3.3): saknas fältet eller är värdet okänt förkastas HELA posten med fel — aldrig default `ingen`. Frends får därför ALDRIG utelämna fältet eller hitta på ett fjärde värde. |
| `avregistrering` | `objekt\|null` | `kod: "AV"\|"UV"` (`AV` = avliden, `UV` = utvandrad), `datum: "YYYY-MM-DD"`. `null` om personen inte är avregistrerad. Nummerbyten (`GN`/`GS`) exponeras INTE här — de normaliseras till `tidigareBeteckningar` (§3). |
| `relationer` | `array` | Vårdnadsrelationer: `typ: "V"\|"VF"` (`V` = vårdnadshavare för personen, `VF` = personen är vårdnadshavare för), `personnummer: string\|null` (`null` för aldrig folkbokförd relationsperson, K-NAV-2.7), `namn: string`, `tomDatum: string\|null` (`YYYY-MM-DD`, satt = avslutad vårdnad). Tom array om inga relationer. |
| `fodelsetid` | `string` | `YYYY-MM-DD`. |

> **Kontraktsdisciplin:** shapen ovan är **exakt** — inga extra fält utan versionerad
> kontraktsändring, inga fält som byter typ. Hubs `FolkbokforingClient` validerar strikt
> och förkastar poster som avviker.

---

## 3. Frends-sidans skyldigheter (mappning mot Skatteverket REST v3)

| # | Skyldighet |
|---|---|
| F-1 | **Mappa v3-svaret till personpost-shapen i §2.3.** Primär postadress hämtas ur v3-elementet **`kontaktadress`** (Skatteverkets sammanvägning av folkbokföringsadress/särskild postadress/utlandsadress) — Frends bygger INGEN egen adressprioritering (K-NAV-2.6). Särskild postadress mappas separat till `sarskildPostadress`. |
| F-2 | **Relationer:** läs Relationer-gruppen, relationstyperna **V** och **VF** inkl. `RelationTomdatum`. Aldrig folkbokförda relationspersoner (födelsetid + nollor) levereras med `personnummer: null` och endast namn (K-NAV-2.7). |
| F-3 | **Avregistrering:** koderna **AV** (avliden) och **UV** (utvandrad) mappas till `avregistrering`. Koderna **GN/GS** (nummerbyte/falsk identitet) hanteras via hänvisningskedjan: aktuellt nummer i `personnummer`, historiken i `tidigareBeteckningar` (K-NAV-2.8). |
| F-4 | **Hänvisningsnummer — NYA metoden är obligatorisk** (gamla metoden stängdes 2026-01-20): aktuell identitetsbeteckning bär listan över tidigare beteckningar. Frends SKA använda nya metoden; äldre kommunbeställningar kan behöva uppdateras (K-NAV-2.5, K-NAV-1.2). |
| F-5 | **Skyddsstatus:** mappa Skatteverkets `skyddAvPersonuppgifter`-flaggor till tri-state `skydd`. **Fältet får ALDRIG utelämnas.** Kan flaggan inte tolkas: leverera INTE posten som oskyddad — utelämna hellre hela anropet med **502** än att gissa. Vid `skyddad_folkbokforing`: verklig adress finns inte i Navet; `kontaktadress` SKA vara `null` och endast ev. `sarskildPostadress` levereras. Flaggkedjan Navet→Frends→Hubs verifieras i acceptanstest (K-NAV-3.3). |
| F-6 | **Timeout-budget:** Skatteverkets svarstid är normalt <2 s, max 30 s. Frends sätter timeout mot Skatteverket till **30 s** och SKA svara Hubs inom **35 s** (svar eller **504**, §4) — aldrig hänga längre. |
| F-7 | **PII-fri loggning: personnummer, namn och adresser får ALDRIG skrivas till Frends loggar.** Logga korrelations-id, antal begärda/besvarade poster, ändamål, ärendereferens, HTTP-status och latens. Detta speglar Hubs PII-doktrin (partsregistret är enda PII-hemvisten; loggar är det aldrig). |
| F-8 | **Per-kommun-konfiguration:** organisationsnummer + beställningsidentitet (valideras av Navet mot anslutningen), **Expisoft-organisationscertifikat** (privat nyckel endast i Frends key vault), **OAuth2 Client Credentials Grant** mot `sysorgoauth2.skatteverket.se` med scope **`fbfuppgoffakt`** och mTLS (K-NAV-2.2, K-NAV-2.3). Exakta CCG-detaljer bekräftas vid onboarding. |
| F-9 | **Kapacitet & debitering:** respektera Skatteverkets tak (max 25 anrop/konsument/sekund; max 900 identiteter per `/v3/hamta`). Räkna **debiterbara uppslag (träffar) per kommun** för kvartalsfaktureringen (K-NAV-1.8). |
| F-10 | **Korrelation:** propagera Hubs `korrelationsId` som `skv_client_correlation_id` mot Skatteverket (K-NAV-3.2). |

---

## 4. Felkoder

Fel returneras som JSON: `{"fel": {"kod": "<sträng>", "meddelande": "<PII-fri text>", "korrelationsId": "<eko av request>"}}`.

| HTTP | `kod` | Betydelse | Hubs-beteende |
|---|---|---|---|
| 400 | `ogiltig_request` | Valideringsfel: fel format på personnummer (ej 12 siffror), tom/för stor batch (>900), saknat obligatoriskt fält. `meddelande` får ALDRIG innehålla det felaktiga personnumret — ange index i batchen i stället. | Programfel — logga (PII-fritt) och visa handläggarfel. |
| 401 | `auth` | Ogiltig/utgången Bearer-token. | Förnya credential / larma drift. |
| 502 | `skatteverket_fel` | Skatteverket svarade med fel (inkl. otolkbar skyddsflagga per F-5), tokenfel mot Skatteverket, 429, driftfönster (måndag 06:00–06:15). | Retry med backoff (K-NAV-2.9); posterna skrivs inte. |
| 504 | `timeout` | Skatteverket svarade inte inom 30 s; Frends bröt inom 35 s-budgeten (F-6). | Retry med backoff. |

**VIKTIGT — saknad person är inte ett fel:** personnummer som inte finns i
folkbokföringen returneras som `null` under sin nyckel i `personposter` med **HTTP 200**
(§2.3). Frends får inte översätta "ej träff" till 4xx/5xx, och Hubs får inte tolka
`null` som fel — det är statusen "ej i folkbokföringen".

---

## 5. Testmiljö & acceptanstest

| Punkt | Värde |
|---|---|
| Skatteverkets testmiljö | `https://api.test.skatteverket.se` |
| Test-organisationsnummer | `162021004748` (Expisoft testcertifikat "Kommun A") |
| Testbeställning för acceptans | **`00000236-FO01-0002`** — beställningen **MED skyddade personuppgifter**. **Obligatorisk** för acceptanstest: flaggkedjan (F-5/K-NAV-3.3), skyddad folkbokföring utan adress och sekretessmarkering måste bevisas överleva hela kedjan Navet→Frends→Hubs. (`-0001` utan skyddade räcker INTE.) |
| Testdata | Skatteverkets publicerade testpersonnummer. **Riktiga personnummer i testmiljön är FÖRBJUDNA**; testdata i produktion = anmälningspliktig personuppgiftsincident (K-NAV-7.1). |
| CI/automatiska tester | Skatteverkets testmiljö får INTE anropas från automatiska pipelines — Hubs/Frends kör mock av detta kontrakt med testpersonnummer (K-NAV-3.4). |

Acceptanstestsviten per kommun (K-NAV-7.2) körs genom DETTA kontrakt och ska minst täcka:
normal person, skyddad folkbokföring (`kontaktadress: null`), sekretessmarkering,
vårdnadshavare V/VF inkl. avslutad vårdnad och aldrig folkbokförd relationsperson,
nummerbyte (tidigareBeteckningar), avliden (AV), utvandrad (UV), ej-träff (`null`),
batch om >1, samt 400/401/502/504.

---

## 6. OpenAPI 3.0-specifikation

Normativ maskinläsbar spec för endpointen (samma innehåll som §2 och §4 — vid konflikt
gäller tabellerna i §2):

```yaml
openapi: 3.0.3
info:
  title: Kommun-internt folkbokforingsuppslag (Hubs <-> Frends)
  description: >
    Internt uppslags-API exponerat i kommunens Frends. Hubs FolkbokforingClient
    ar enda konsument. Frends mappar Skatteverkets REST v3 (/v3/hamta) till den
    normaliserade personpost-shapen. Personnummer far ALDRIG loggas.
  version: 1.0.0
  contact:
    name: ITSL
    email: info@itsl.se
servers:
  - url: "{basUrl}"
    variables:
      basUrl:
        default: https://frends.kommun.example
        description: Per-kommun-konfigurerad bas-URL
security:
  - bearerAuth: []
paths:
  /folkbokforing/uppslag:
    post:
      operationId: folkbokforingUppslag
      summary: Sla upp folkbokforingsuppgifter for en batch personnummer
      requestBody:
        required: true
        content:
          application/json:
            schema:
              $ref: "#/components/schemas/UppslagRequest"
      responses:
        "200":
          description: >
            Uppslag genomfort. Varje begart personnummer finns som nyckel i
            personposter; vardet ar en personpost eller null om personen inte
            finns i folkbokforingen (ej-traff ar INTE ett fel).
          content:
            application/json:
              schema:
                $ref: "#/components/schemas/UppslagResponse"
        "400":
          description: Ogiltig request (format, batchstorlek, saknat falt)
          content:
            application/json:
              schema:
                $ref: "#/components/schemas/Fel"
        "401":
          description: Ogiltig eller utgangen token
          content:
            application/json:
              schema:
                $ref: "#/components/schemas/Fel"
        "502":
          description: Fel fran Skatteverket (inkl. otolkbar skyddsflagga)
          content:
            application/json:
              schema:
                $ref: "#/components/schemas/Fel"
        "504":
          description: Timeout mot Skatteverket (30 s); Frends svarar inom 35 s
          content:
            application/json:
              schema:
                $ref: "#/components/schemas/Fel"
components:
  securitySchemes:
    bearerAuth:
      type: http
      scheme: bearer
      description: Auth-mekanism justeras vid onboarding av pilotkommunen.
  schemas:
    UppslagRequest:
      type: object
      required: [personnummer, korrelationsId, andamal, arendeRef]
      additionalProperties: false
      properties:
        personnummer:
          type: array
          minItems: 1
          maxItems: 900
          items:
            type: string
            pattern: "^[0-9]{12}$"
            description: Person-/samordningsnummer, 12 siffror AAAAMMDDNNNN
        korrelationsId:
          type: string
          minLength: 1
          description: >
            Genereras av Hubs per uppslag; Frends propagerar som
            skv_client_correlation_id mot Skatteverket.
        andamal:
          type: string
          minLength: 1
          description: Maskinlasbart andamal, t.ex. partsregister_ifyllnad
        arendeRef:
          type: string
          minLength: 1
          description: Hubs arendereferens (uppslag sker alltid i arendekontext)
    UppslagResponse:
      type: object
      required: [personposter]
      additionalProperties: false
      properties:
        personposter:
          type: object
          description: >
            Nycklat pa begart personnummer (12 siffror). Vardet ar en
            Personpost eller null om personen inte finns i folkbokforingen.
          additionalProperties:
            oneOf:
              - $ref: "#/components/schemas/Personpost"
              - type: "null"
    Personpost:
      type: object
      required:
        - personnummer
        - tidigareBeteckningar
        - namn
        - kontaktadress
        - sarskildPostadress
        - skydd
        - avregistrering
        - relationer
        - fodelsetid
      additionalProperties: false
      properties:
        personnummer:
          type: string
          pattern: "^[0-9]{12}$"
          description: Aktuell identitetsbeteckning (kan skilja sig fran begard nyckel vid nummerbyte)
        tidigareBeteckningar:
          type: array
          items:
            type: string
            pattern: "^[0-9]{12}$"
          description: Identitetshistorik via hanvisningsnummer (NYA metoden)
        namn:
          $ref: "#/components/schemas/Namn"
        kontaktadress:
          oneOf:
            - $ref: "#/components/schemas/Adress"
            - type: "null"
          description: >
            Primar postadress (v3-elementet kontaktadress). SKA vara null vid
            skydd = skyddad_folkbokforing.
        sarskildPostadress:
          oneOf:
            - $ref: "#/components/schemas/Adress"
            - type: "null"
        skydd:
          type: string
          enum: [ingen, sekretessmarkering, skyddad_folkbokforing]
          description: >
            OBLIGATORISK. Hubs tillampar fail-closed: saknat/okant varde =>
            hela posten forkastas. Far aldrig utelamnas av Frends.
        avregistrering:
          oneOf:
            - $ref: "#/components/schemas/Avregistrering"
            - type: "null"
        relationer:
          type: array
          items:
            $ref: "#/components/schemas/Relation"
        fodelsetid:
          type: string
          format: date
          description: YYYY-MM-DD
    Namn:
      type: object
      required: [fornamn, mellannamn, efternamn]
      additionalProperties: false
      properties:
        fornamn:
          type: string
        mellannamn:
          oneOf:
            - type: string
            - type: "null"
        efternamn:
          type: string
    Adress:
      type: object
      required: [rader, postnummer, postort]
      additionalProperties: false
      properties:
        rader:
          type: array
          minItems: 1
          items:
            type: string
          description: Utdelningsrader
        postnummer:
          type: string
        postort:
          type: string
    Avregistrering:
      type: object
      required: [kod, datum]
      additionalProperties: false
      properties:
        kod:
          type: string
          enum: [AV, UV]
          description: "AV = avliden, UV = utvandrad (GN/GS normaliseras till tidigareBeteckningar)"
        datum:
          type: string
          format: date
    Relation:
      type: object
      required: [typ, personnummer, namn, tomDatum]
      additionalProperties: false
      properties:
        typ:
          type: string
          enum: [V, VF]
          description: "V = vardnadshavare for personen, VF = personen ar vardnadshavare for"
        personnummer:
          oneOf:
            - type: string
              pattern: "^[0-9]{12}$"
            - type: "null"
          description: null for aldrig folkbokford relationsperson
        namn:
          type: string
        tomDatum:
          oneOf:
            - type: string
              format: date
            - type: "null"
          description: Satt datum = avslutad vardnad
    Fel:
      type: object
      required: [fel]
      additionalProperties: false
      properties:
        fel:
          type: object
          required: [kod, meddelande, korrelationsId]
          properties:
            kod:
              type: string
              enum: [ogiltig_request, auth, skatteverket_fel, timeout]
            meddelande:
              type: string
              description: PII-fri text — far ALDRIG innehalla personnummer/namn
            korrelationsId:
              type: string
              description: Eko av requestens korrelationsId
```

> Not: OpenAPI 3.0 saknar `nullable`-typen från 3.1 — nullbarhet uttrycks här med
> `oneOf` + `type: "null"`-mönstret. Om Frends-verktyget kräver strikt 3.0-`nullable: true`
> är det en ekvivalent, tillåten omskrivning.

---

## 7. Referens

- **Kravställning (normativ bakgrund):** `hubs_start/docs/KRAVSTALLNING-NAVET-FOLKBOKFORING.md`
  — särskilt §0 (arkitekturbeslut, ratificerat 2026-07-06), §2 (Frends ↔ Skatteverket),
  §3 (detta kontrakt, K-NAV-3.x), §5 (skyddade personuppgifter), §7 (testmiljö).
- **Partsregistret (mottagaren av datat):** `hubs_start/docs/ANALYS-HANDLING-FRAN-MALL.md` §3.4
  — `oc_hubs_arende_part` är motorns enda sanktionerade PII-tabell; transient arbetsdata,
  gallras med ärendet, aldrig SoR.
- **Skatteverket:** "Folkbokföringsuppgifter för offentliga aktörer – REST" v3
  (tjänstebeskrivning 1.0.2), Navets tekniska informationssida (länka dit — inte till
  direkta PDF-URL:er, K-NAV-7.3).

---
*Kontrakt v1.0 (utkast), 2026-07-06. Ändringar i personpost-shapen eller felkoderna är
kontraktsändringar och kräver versionsbump + samordning Hubs ↔ Frends. Auth-mekanismen
(§2.1) är den enda punkt som avsiktligt lämnats öppen till onboarding.*
