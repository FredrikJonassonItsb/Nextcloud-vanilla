---
titel: Kravställning — Navet-integration (folkbokföringsuppslag) för Hubs
status: Kravställning v1.0 (utkast) — web-grundad research 2026-07-06, väntar ratificering
beslut: Fredrik 2026-07-06 — Navet SKA implementeras; alla kundkommuner har Navet-abonnemang
relaterat: ANALYS-HANDLING-FRAN-MALL.md (partsregistret §3.4), KOMMUNROLLER-SOR-INTEGRATIONER.md
---

# Kravställning — Navet-integration (folkbokföringsuppslag)

Syfte: Hubs ska kunna slå upp **folkbokföringsuppgifter ur personnummer** (namn, adresser,
vårdnadshavare, avregistrering, **skyddsstatus**) via Skatteverkets **Navet**, för att fylla
**partsregistret** (`oc_hubs_arende_part`) och därmed driva dokumentifyllnad, ärendekopplings-
matchning och mottagarval. Kunderna (kommunerna) har redan Navet-abonnemang.

> Research-grund: fyra web-verifierade spår mot skatteverket.se m.fl. (2026-07-06):
> tekniska gränssnitt · anslutning/juridik · skyddade personuppgifter · kommunal praxis.
> Faktapåståenden nedan bär källor i researchunderlaget; `[VERIFIERA]` = måste bekräftas
> med Skatteverket/kommunen före kravfrysning.

---

## 0. Arkitekturbeslut (förslag att ratificera)

**Hubs integrerar ALDRIG direkt mot Navet.** Hubs konsumerar ett **kommun-internt
uppslags-API exponerat i Frends** (kommunens iPaaS), som i sin tur anropar Skatteverkets
REST-tjänst. Detta är etablerad kommunpraxis (Helsingborgs stad kör exakt denna modell i
Frends) och håller avtal, certifikat och PII-ansvar hos kommunen — Hubs-kravet blir ett
stabilt **internt API-kontrakt**, inte ett Skatteverket-kontrakt.

**Mönster: direktuppslag per ärende** (fråga-svar i realtid) — inte lokalt
kommuninvånarregister (KIR): färsk data, **rikstäckning** (personer folkbokförda i annan
kommun täcks — placerade barn, inflyttade; KIR täcker bara egna kommunen), minimal lokal
PII-kopia. Aviseringsprenumeration för aktiva ärendepersoner = möjlig senare fas (se §9).

```
Hubs (hubs_arende)                    Kommunens Frends              Skatteverket
┌──────────────────────┐   internt   ┌──────────────────┐  OAuth2  ┌──────────────┐
│ FolkbokforingPort ────┼──── API ───►│ Navet-flöde       ├── CCG ──►│ REST v3      │
│  → PartService        │  (kontrakt) │ (per-kommun cert, │  mTLS    │ /v3/hamta    │
│  → oc_hubs_arende_part│             │  beställnings-id) │          │ /v3/sok      │
└──────────────────────┘             └──────────────────┘          └──────────────┘
```

---

## 1. Anslutning & avtal (per kommun)

| Krav | Beskrivning |
|---|---|
| **K-NAV-1.1** | **Kommunen är avtalspart** — inte ITSL/Frends. Beställning av tjänsten "Folkbokföringsuppgifter för offentliga aktörer – REST" görs per kommun på **blankett SKV 7777** (godkänns av beslutande chef, till navet.solna@skatteverket.se). Avtal = beställning + orderbekräftelse med unik **beställningsidentitet**. |
| **K-NAV-1.2** | **Inventera befintliga abonnemang FÖRST.** Kommunernas befintliga Navet-beställningar kan avse avisering till annat system (KIR) — de täcker inte automatiskt online-uppslag från Hubs. Per kommun: omfattar beställningen fråga-svar-tjänsten? Vilket urval/termer? Rätt hänvisningsnummer-metod? Ändring görs via e-post med beställnings-ID. `[VERIFIERA per kommun]` |
| **K-NAV-1.3** | **Frends anges som distributör** (servicebyrå) i beställningen inkl. distributörens funktionsbrevlåda; ansvaret för behandlingen ligger alltid kvar hos kommunen. PuB-avtal kommun↔ITSL/Frends krävs. `[VERIFIERA avtalsform med Skatteverket + kommunens DSO]` |
| **K-NAV-1.4** | **Organisationscertifikat per kommun** från **Expisoft AB** (enda godtagna utfärdaren). Certifikat/privat nyckel ENDAST i serverdrift med starkt begränsad åtkomst (key vault i Frends) — aldrig på klient. Spärrat certifikat slår ut hela kommunens åtkomst → bevaka giltighet. |
| **K-NAV-1.5** | **Termbeställning:** kravställningen specificerar termbehovet (§4) så varje kommun kan verifiera/utöka sin beställning: namn, adresser inkl. särskild postadress + kontaktadress, relationer/vårdnadshavare, civilstånd, avregistrering, skyddsstatus. Vilka termer som levereras styrs av kommunens beställning. |
| **K-NAV-1.6** | **Onboarding-runbook per kommun:** beställning → orderbekräftelse → anslutningsförfarande i utvecklarportalen → testmiljö → produktionsverifiering. Bemannad funktionsbrevlåda för Skatteverkets driftmeddelanden (villkorskrav). |
| **K-NAV-1.7** | **Rättslig dokumentation per kommun:** rättslig analys (GDPR art. 6.1e + SoLPuL 6 § + OSL) och **DPIA** före driftsättning. Skatteverket prövar INTE detta åt kommunen — ansvaret ligger uttryckligen på mottagaren. |
| **K-NAV-1.8** | **Ekonomi:** kommuner betalar (statliga myndigheter är gratis): fast avgift (~200 kr/kvartal, onlinetjänst) + transaktionsavgift **endast vid träff**; kvartalsfakturering. Hubs/Frends ska kunna **räkna debiterbara uppslag per kommun**. `[VERIFIERA aktuell prislista]` |

## 2. Tekniskt gränssnitt (Frends ↔ Skatteverket)

| Krav | Beskrivning |
|---|---|
| **K-NAV-2.1** | **REST v3 är primärt gränssnitt** (inte äldre SOAP): `POST https://api.skatteverket.se/folkbokforing/folkbokforingsuppgifter-for-offentliga-aktorer/v3/hamta` (uppslag ur personnummer/samordningsnummer, max 900 per anrop) och `/v3/sok` (namn-/adressökning, max 100 träffar). OpenAPI-spec finns (v3, 1.0.2). Pinna v3; bevaka utvecklarportalen. |
| **K-NAV-2.2** | **Autentisering: OAuth2 Client Credentials Grant** mot `sysorgoauth2.skatteverket.se`, mTLS med Expisoft-organisationscertifikat, scope `fbfuppgoffakt`, TLS 1.2+. `[VERIFIERA exakt CCG-detalj: tjänstebeskrivningen nämner client_secret, CCG-sidan säger certifikat ersätter secret — bekräfta vid onboarding]` |
| **K-NAV-2.3** | **Request-kontrakt:** anropet bär kommunens `organisationsnummer` + `bestallningsidentitet` — dessa valideras av Navet mot anslutningen. **Multi-tenant per kommun** på (orgnr, beställnings-id, certifikat) — ingen delad identitet över kommungränser. |
| **K-NAV-2.4** | **Kapacitetsgränser:** max 25 anrop/konsument/sekund; svarstid normalt <2 s, max 30 s (Frends-timeout >30 s); realtid — INTE batch (massuppdatering = aviseringstjänsten, separat spår). |
| **K-NAV-2.5** | **Hänvisningsnummer — NYA metoden är obligatorisk** (gamla stängdes 2026-01-20): aktuell identitetsbeteckning bär listan över tidigare beteckningar; tidigare pekar på senaste. Flödet MÅSTE använda nya metoden; äldre kommunbeställningar kan behöva uppdateras (→ K-NAV-1.2). |
| **K-NAV-2.6** | **Adressmodell:** använd v3-elementet **`kontaktadress`** som primär postadress (Skatteverkets egen sammanvägning av folkbokföringsadress/särskild postadress/utlandsadress) i stället för egen prioriteringslogik. Lagra särskild postadress separat (skyddsrelevant, §5). |
| **K-NAV-2.7** | **Relationer/vårdnadshavare:** läs Relationer-gruppen, relationstyp **V** (vårdnadshavare) och **VF** (vårdnadshavare för), inkl. RelationTomdatum (avslutad vårdnad). Hantera aldrig-folkbokförda relationspersoner (födelsetid + nollor, endast namn) och personnummerbyten via Hanvisningar. |
| **K-NAV-2.8** | **Avregistrering & samordningsnummer:** hantera avregistreringsorsak explicit — **AV=avliden, UV=utvandrad, GN/GS=nummerbyte, falsk identitet** — som egen status i Hubs (aldrig tyst som vanlig person; handläggarnotis). Samordningsnummer accepteras som identitetsbeteckning med status (aktivt/vilande) synlig; UI klarar personer utan folkbokföringsadress. |
| **K-NAV-2.9** | **Felhantering:** identitetsbeteckningar som saknas i Navet kommer i svaret (ej HTTP-fel) → tydlig Hubs-status "ej i folkbokföringen". Retry/backoff för tokenfel, 429, driftfönster (måndag 06:00–06:15 nere; support vardagar 08–12). |

## 3. Internt API-kontrakt (Hubs ↔ Frends)

| Krav | Beskrivning |
|---|---|
| **K-NAV-3.1** | Frends exponerar ett **kommun-internt uppslags-API** med stabilt kontrakt: `POST /folkbokforing/uppslag` `{personnummer[], korrelationsId, andamal, arendeRef}` → normaliserad personpost (namn, kontaktadress, särskild postadress, relationer, avregistrering, **skyddsstatus**, identitetshistorik). Hubs FolkbokforingPort binder mot detta — aldrig mot Skatteverket direkt. |
| **K-NAV-3.2** | Kontraktet bär **korrelations-id** (`skv_client_correlation_id`) genererat av Hubs per uppslag, kopplat till handläggare+ärende+tidpunkt (→ K-NAV-4.2). |
| **K-NAV-3.3** | **Fail-closed på skyddsstatus:** om skyddsflaggan saknas/inte kan tolkas i leveransen förkastas posten — ALDRIG default "oskyddad". Flaggorna måste verifieras överleva hela kedjan Navet→Frends→Hubs i acceptanstest (eSam-varningen: "följer Navets flaggor med?"). |
| **K-NAV-3.4** | **Mock av interna API:t** för CI/demo: Skatteverkets testmiljö får INTE användas i automatiska pipelines och testdata kan ändras utan förvarning → Frends/Hubs-mock med Skatteverkets testpersonnummer för alla automatiska tester. |

## 4. Hubs-sidan: FolkbokforingPort → Partsregistret

| Krav | Beskrivning |
|---|---|
| **K-NAV-4.1** | Ny **`FolkbokforingPort`** i hubs_arende (Port/Client-mönstret som Facksystem/Sdkmc): `hamtaPerson(pnr[], kontext)` → normaliserad post. Graceful `isAvailable()` — saknas integrationen fungerar allt utom auto-ifyllnad (handläggaren fyller manuellt). |
| **K-NAV-4.2** | **Uppslag ENDAST i ärendekontext av behörig handläggare** (SoLPuL 6 § nödvändighet): API:t kräver `arendeRef`, `assertEnhetAtkomst` grindar, **inga fria sökningar** i MVP (`/v3/sok` aktiveras ej förrän eget beslut). Varje uppslag journalförs: handläggare, ärende, ändamål, tidpunkt, korrelations-id. Skatteverket loggar INTE användarnivå — hela ansvaret är vårt. |
| **K-NAV-4.3** | Svaret skrivs till **partsregistret** (`oc_hubs_arende_part`, ANALYS §3.4) med `kalla='navet'`, `verifierad=<timestamp>`, identitetshistorik (GN/GS-byten). Vårdnadshavare (V/VF) skapas som egna parts-rader med roll `vardnadshavare`. |
| **K-NAV-4.4** | **Rättelse-garantin** (avtalsvillkor): Navet-hämtade fält ska vara **uppdaterbara vid nytt uppslag** (ej inlåsta kopior) med enkel versionshistorik; systemet ska kunna ta emot rättelser. |
| **K-NAV-4.5** | **Debounce/färskhet:** transaktionsavgift per träff → inga onödiga repetitiva uppslag. Uppslag cachas ENDAST i partsregistret (ärendets krets), med definierad TTL/färskhetspolicy per ärendetyp; manuell "uppdatera från Navet"-knapp i parts-UI:t. |
| **K-NAV-4.6** | **Gallring:** partsregistret gallras med ärendet enligt policy (Fredriks beslut 3). För skyddade personer: följ Skatteverkets praxis att radera överskottsuppgifter. Ingen folkbokföringsdata cachas utanför ärendet. |

## 5. Skyddade personuppgifter (kritiskt för socialtjänst)

Tre nivåer: **sekretessmarkering** (1 flagga; varningssignal, posten levereras normalt hel),
**skyddad folkbokföring** (2 flaggor; **verklig adress finns inte i Navet** — personen är
"på kommunen skriven", ev. särskild postadress/förmedlingsadress), **fingerade
personuppgifter** (syns INTE — ser ut som normal post, ska inte kunna detekteras).
Sekretessen följer med till mottagande myndighet (OSL 22:3, omvänt skaderekvisit; 26 kap.
för socialtjänsten; starkaste skyddet vinner).

| Krav | Beskrivning |
|---|---|
| **K-NAV-5.1** | Partsregistret får tvingande **tri-state skyddsfält**: `ingen | sekretessmarkering | skyddad_folkbokforing`, mappat fail-closed (K-NAV-3.3). Navets flaggor är **enda sanningen** — inga egna parallella flaggor som kan divergera; komplettera med manuell skyddsmarkering på ärendenivå (synkad, ej ersättning — personer kan ha skyddsbehov utan Navet-flagga). |
| **K-NAV-5.2** | **Skyddad folkbokföring:** adressfält kan vara tomma utan valideringsfel; UI visar "Skyddad folkbokföring — adress hanteras via Skatteverkets förmedlingstjänst"; ev. särskild postadress lagras separat och är **enda** utskicksadress. Utskick: förmedlingstjänsten (innerkuvert med personnummer) eller digital brevlåda — aldrig okrypterad e-post. |
| **K-NAV-5.3** | **Sekretessmarkering:** tydlig varningsmarkering på ALLA ytor där personen förekommer (ärendekort, parts-panel, medlemslistor, dokumentdialoger). Enligt Hubs PII-princip: **visning för behörig handläggare är avsedd** — invarianten är ingen läcka över behörighetsgränsen. |
| **K-NAV-5.4** | **Förhöjd behörighetsspärr:** åtkomst till skyddsflaggade parter ska kunna begränsas till utpekad krets ovanpå ärendebehörigheten (Skatteverkets krav "begränsa antalet personer"). All läsning loggas; larm vid onormal åtkomst. |
| **K-NAV-5.5** | **Vårdnadshavar-scenariot (barnperspektiv):** hela hushållet har normalt samma skydd, och **en vårdnadshavare kan vara hotaktören**. Vyer/handlingar riktade till EN vårdnadshavare får aldrig röja den andra partens/barnets adress, skola eller vistelseort → **per-mottagare-filtrering**, inte bara per-ärende. |
| **K-NAV-5.6** | **Fingerade personuppgifter:** systemet ska INTE försöka detektera dem; inga funktioner får anta att alla skyddsvärda är flaggade. |

## 6. Skyddsgrind i dokumentgenereringen (koppling till handling-från-mall)

| Krav | Beskrivning |
|---|---|
| **K-NAV-6.1** | `DocxFyllningsMotor`/`HandlingService` får en **skyddsgrind** som konsulterar partens skyddsfält FÖRE ifyllnad: **skyddad folkbokföring** → adressfält blockeras (fylls aldrig); **sekretessmarkering** → namn/adress utelämnas som default i handlingar, individen identifieras med personnummer; åsidosättande = aktivt handläggarbeslut som loggas. |
| **K-NAV-6.2** | **Indirekta röjanden:** handlingar som lämnar systemet ska kunna undertrycka indirekt geografisk info (handläggarens direktnummer/kontor, skolnamn, mötesadresser) för skyddsflaggade parter. Kombinationen personnummer + plats/tid (kallelse med besöksadress!) är särskilt farlig — kallelse-mallen får en skyddsvariant. |
| **K-NAV-6.3** | Förhandsdialogen (HandlingForhandsModal) visar skyddsstatus per part och vilka fält som grindats, så handläggaren ser VARFÖR fält är tomma. |

## 7. Drift & test

| Krav | Beskrivning |
|---|---|
| **K-NAV-7.1** | **Testmiljö:** `https://api.test.skatteverket.se`, testorgnr 162021004748 (Expisoft testcert "Kommun A"), testbeställningar **00000236-FO01-0001** (utan skyddade) och **-0002** (MED skyddade — obligatorisk för att testa §5-kraven), Skatteverkets testpersonnummer (XLSX/öppna data). **Riktiga personnummer i test = förbjudet; testdata i produktion = anmälningspliktig personuppgiftsincident.** |
| **K-NAV-7.2** | E2E-acceptanstest per kommun före driftsättning: flaggkedjan (K-NAV-3.3), vårdnadshavare, nummerbyten, avlidna/utvandrade, skyddade poster. |
| **K-NAV-7.3** | Bevaka Navets nyhetsflöde (ändringar aviseras 3 månader i förväg; mindre tekniska ändringar utan föranmälan). Länka till Navets **tekniska informationssida** — inte direkta PDF-URL:er (hash-URL:erna byts vid dokumentuppdatering). |

## 8. Utanför scope (medvetet)

- **Aviseringsprenumeration** (SHS/e-transport, dagliga ändringsfiler): separat framtida spår
  för att hålla aktiva ärendepersoners skyddsstatus färsk utan uppslag. Kräver egen kanal
  (SHS-nod/e-transport) och eget format (Epersondata.xsd).
- **`/v3/sok`** (namnsökning utan känt pnr): avaktiverad i MVP (fria sökningar strider mot
  ärendekontext-kravet); eget beslut om den behövs.
- **SSBTEK** (ekonomiskt bistånd): egen sammansatt bastjänst via Försäkringskassan, ersätter
  inte Navet; blir eget krav-spår om/när Hubs stödjer ekonomiskt bistånd (kat 3). Arkitekturen
  (internt API via Frends) ska tåla att SSBTEK adderas parallellt.
- **KIR/lokalt invånarregister**: byggs inte — direktuppslag valdes (§0).

## 9. Öppna frågor — STATUS efter Fredriks beslut 2026-07-06

> **Arkitekturbeslutet i §0 är RATIFICERAT** (Fredrik 2026-07-06). Byggfokus: (1) mock av
> interna uppslags-API:t på plats FÖRST, (2) skarpa integrationen byggs så långt det går
> utan kommun-onboarding.

1. ~~Befintlig beställning utökas?~~ **BESLUTAT ANTAGANDE: ny SKV 7777 krävs troligen per
   kommun** — planera onboarding-runbooken därefter. Slutbekräftas per kommun.
2. ~~Frends-som-distributör?~~ **Troligen OK** — bekräftas formellt vid första onboarding.
3. ~~CCG-detaljen~~ **OK** — löses vid teknisk onboarding.
4. **Vilka termer** respektive kommuns beställning levererar — kvarstår `[per kommun]`.
5. **SOAP-utfasningsplan** — kvarstår `[lågt]`.
6. Exakt **prislista 2026** — kvarstår `[lågt]`.

## 10. Föreslagen byggordning

1. **Pilotkommun väljs** → inventering (K-NAV-1.2) + beställningsuppdatering + Expisoft-cert.
2. **Frends-flöde** mot testmiljön (testbeställning -0002 med skyddade) + internt API-kontrakt.
3. **Hubs:** FolkbokforingPort + partsregister-skrivning + audit (K-NAV-4.x) + skyddsfält/UI
   (K-NAV-5.x) — mockat internt API gör att detta kan byggas parallellt med steg 2.
4. **Skyddsgrinden i dokumentgenereringen** (K-NAV-6.x) — kräver att handling-från-mall fas 1
   finns (ANALYS §8).
5. E2E-acceptans i pilotkommunen → runbook → utrullning per kommun (K-NAV-1.6).

---
*Kravställning v1.0 (utkast), 2026-07-06. Web-grundad mot skatteverket.se (Navet Allmän
beskrivning v4.6, Teknisk beskrivning v5.5, Tjänstebeskrivning REST v3 1.0.2, XML-struktur
v5.2, vägledningen för skyddade personuppgifter), eSam ES2023-12, Socialstyrelsen, SKR,
Helsingborgs stads Frends-case. Fullständigt källregister med URL:er i researchunderlaget.
`[VERIFIERA]`-markerade punkter måste stängas med Skatteverket/kommun före kravfrysning.*
