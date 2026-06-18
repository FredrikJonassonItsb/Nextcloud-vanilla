# Medborgaridentifiering & onboarding i medborgarriktade flöden

*Research för Hubs (ITSL) — säker kommunikation i svensk offentlig sektor. Underlag för dashboard/handläggarvy. Datum: 2026-06-13.*

> Varumärkesregel: i produktnära text säger vi aldrig "Nextcloud" eller "Talk". I detta interna analysdokument används plattformsnamnen för precision.

---

## Sammanfattning

Medborgaridentifiering är inte ett tekniskt sidoproblem i Hubs medborgarriktade flöden (säker dialog, säkert videomöte, säkra utskick) — det är **kärnan i compliance, mottagaregaranti och användarupplevelse på en gång**. Tre saker gäller samtidigt 2026:

1. **De facto-mönstret är låst.** Alla godkända svenska konkurrenter (TDialog/Compodium, Sefos/Meaplus, CGI, SecureAppbox, Visiba, Digitala Samtal) använder exakt samma medborgarflöde: notis via SMS/e-post → klick på länk → inloggning med BankID/Freja (LOA3) → läs/svara/möt i säker webbvy, **utan att medborgaren behöver ett konto hos kommunen**. Medborgarsidan är därför ingen differentiator. Det Hubs ska bygga och visualisera är **handläggarsidan**: vem är identifierad, på vilken tillitsnivå, levererades meddelandet, öppnades det, vem svarade — och en hederlig hantering av de ~5–10 % medborgare som saknar eID.

2. **Regelverket flyttar sig under 2026–2027.** SMS-OTP är på väg ut som fullvärdig faktor (NIST SP 800-63B-4, juli 2025, klassar SMS-OTP som *restricted authenticator*; flera tillsynsmyndigheter internationellt förbjuder SMS som ensam faktor från 2026). Samtidigt kommer två nya identitetsbärare som varje medborgarflöde måste vara redo för: **statlig e-legitimation "Sverige-id"** på högsta tillitsnivå (godkänd av Digg 2026-04-21, lansering december 2026) och **EUDI-plånboken** (eIDAS2), som offentliga aktörer enligt huvudregeln ska **acceptera** när stark autentisering krävs — riktmärket i förordningen är public sector-acceptans inom 24 månader efter de tekniska genomförandeakterna (antagna 2024-11-28). Sveriges nationella plånbok (Digg + Idemia, projekt inom Ena) ska finnas senast slutet av 2026.

3. **Utkanalen blir reglerad.** SOU 2024:47 föreslår att kommuner och regioner **ska** skicka myndighetspost via den statliga infrastrukturen **Mina meddelanden** (Kivra/Min myndighetspost/Billo/e-Boks m.fl.). För Hubs betyder det: positionera Hubs som **dialogkanalen** (tvåvägs, sekretess, handläggarflöde) och bygg på sikt en koppling så enkelriktade beslut kan gå ut via Mina meddelanden från samma yta. Hubs konkurrerar inte med Kivra om massutskick.

**Rekommendationens kärna:** bygg ett återanvändbart **identifierings- och leveranslager** under alla medborgarmoduler (säker dialog, video, utskick), och visualisera det för handläggaren som (a) en **identitets-badge** per motpart (metod + LOA + tidpunkt), (b) en **leveranstidslinje** (skickad → levererad → öppnad → besvarad), och (c) ett **väntrum/lobby-kort** för video där verifierade deltagare syns innan insläpp. Designa abstraktionen "identitetsleverantör" så att BankID, Freja, Sverige-id och EUDI-wallet är utbytbara bakom samma UI — "eIDAS2-redo" är ett upphandlingsargument redan 2026.

---

## Marknad & aktörer

### Identitetsutfärdare (vad handläggaren faktiskt verifierar mot)

| Aktör / produkt | Tillitsnivå | Relevans för Hubs medborgarflöden |
|---|---|---|
| **BankID** (Finansiell ID-Teknik) | LOA3 (Diggs tillitsnivå 3) | De facto-standard, ~8,5 M användare. Relying Party-modell: `auth`/`sign`-order → polla `/collect` var 2:a sekund → `completionData` med signatur, användarinfo och OCSP-svar som RP ska spara för spårbarhet/revision. Detta är exakt det data Hubs ska visa i identitets-badgen och spara i ärendeloggen. |
| **Freja eID Plus / Freja+** (Freja eID Group) | LOA3 | Godkänd på nivå 3. Sedan dec 2024 även **nivå 3 för icke-bofasta** (utan svenskt personnummer / folkbokföring) — kritiskt för nyanlända, asylsökande, utlandssvenskar och EU-medborgare i kommunala flöden. Freja har också lägre kontonivåer (Freja, Freja+) som INTE räcker för känsliga uppgifter — Hubs måste kunna kräva och visa rätt nivå. |
| **SITHS** (Inera) | LOA3, tjänstelegitimation | För vård- och omsorgspersonal (handläggarsidan), inte medborgare. Relevant för Hubs när kommunal HSL/socialtjänst loggar in och i SIP-/videomöten där mötesledaren autentiseras med SITHS. |
| **Sverige-id (statlig e-legitimation)** | **LOA4 (högsta)** | Utfärdas av **Polismyndigheten**, **godkänd av Digg 2026-04-21**, lansering **december 2026**. För både svenska medborgare och registrerade icke-svenska medborgare; åldersgräns från det år man fyller nio. Kan användas för identifiering, informationsdelning och **digital underskrift**, och anmäls till EU:s eIDAS-system för gränsöverskridande användning. För Hubs: ny relying-party-integration att förbereda; höjer "max LOA" i flöden som kräver det. |
| **EUDI-plånbok (svensk)** | Hög/högsta (eIDAS2) | Sveriges nationella plånbok byggs av **Digg** med **Idemia** som teknikpartner, inom Ena-färdplanen; ska finnas senast slutet av 2026, bredare utrullning 2027–2028. Bär verifierbara attribut (mPID, attesteringar). För Hubs ett nytt accept-krav, inte ett eget bygge. |
| **eIDAS-noden** (Digg) | LOA-mappad | Låter utländska EU-eID logga in i svenska tjänster. Relevant för gränspendlare och EU-medborgare i medborgarflöden. |

**Praktisk konsekvens:** Hubs behöver INTE bygga eID. Hubs ska bygga en **leverantörsoberoende identitetsabstraktion** ovanpå dessa, med ett UI som översätter "BankID, LOA3, 2026-06-13 14:02, personnummer verifierat" till en begriplig badge för handläggaren. BankID:s `completionData` (signatur + OCSP + tidsstämpel) är den naturliga datakällan för spårbarhets-/revisionskravet.

### Konkurrenternas identifieringsflöden (vad Hubs möter i upphandling)

- **Compodium / Vidicue** (säkra videomöten, marknadsledare i kommun/region): dubbel autentisering — mötesledare via SITHS/BankID, deltagare via BankID, Freja eID+ **eller SMS-kod** — plus **digitalt väntrum** där mötesledaren manuellt släpper in verifierade deltagare. Bokning via länk i SMS/e-post, aldrig konto. Detta är referensmönstret Hubs videovy ska matcha eller överträffa.
- **TDialog (Compodium, drift via Certezza)**: notis via e-post + länk till säker brevlåda, inloggning SAML/BankID/Freja/SITHS, läskvitton. On-prem, kundkontrollerade nycklar.
- **Sefos (Meaplus)**: konfigurerbara LOA-nivåer (BankID, Freja, SMS, lösenord) som läggs **ovanpå** Outlook/Teams-bokningen — "identifieringslager på befintligt verktyg". Visar att hybridmönstret konkurrerar mot helt egna plattformar.
- **Digitala Samtal**: startar videosamtal **direkt från brukarkortet i Tietoevry Lifecare** — bokningsfritt, inget separat väntrum. Vinkeln är "identifiering inbäddad i verksamhetssystemet". Fångar aktivt kommuner när Ineras "Digitalt möte" avvecklas.
- **Visiba Care** (vård): patient identifierar sig med Mobilt BankID vid bokning OCH inloggning, teknikkontroll före möte, virtuellt väntrum, flerpart med **ombud** (anhörig via eget BankID).
- **SecureAppbox / CGI**: samma notis+länk+BankID-mönster för säker e-post och funktionsbrevlådor.

**Slutsats:** medborgarupplevelsen är standardiserad — avvik inte från notis+länk+BankID (medborgaren är redan tränad), men vinn på **avsändar-/handläggarvyn** och på en **ärlig fallback-väg** för de utan eID, som ingen konkurrent visualiserar väl.

### Utkanaler / digital brevlåda (enkelriktade utskick)

- **Mina meddelanden** (Digg-infrastruktur) med brevlådorna **Kivra** (6+ M användare, ~1,50–3 kr/försändelse), **Min myndighetspost**, **Billo**, **e-Boks**, **Fortnox**. **SOU 2024:47** föreslår skyldighet för kommuner/regioner att skicka myndighetspost denna väg; anslutning för medborgare förblir frivillig (anslutningsgraden är redan hög). **Auktorisationssystem för digital post** infördes maj 2025 som alternativ till upphandling.
- Hubs roll: dialog, inte massutskick. Men ett **"skicka beslut till medborgarens digitala brevlåda"-val** i handläggarvyn (via Mina meddelanden) gör Hubs till kommunens enda avsändaryta för både dialog och formell post — en stark paketering ingen konkurrent har.

---

## Juridik & krav

**eIDAS2 (EU 2024/1183, i kraft 2024-05-20).**
- Varje medlemsstat ska tillhandahålla en EUDI-plånbok; första plånbok klar senast 2026-12-06 (24 mån efter de tekniska genomförandeakterna 2024-11-28).
- **Acceptansskyldighet:** offentliga aktörer ska acceptera EUDI-plånboken där stark autentisering krävs — riktmärket i förordningen är public sector inom 24 mån (≈ dec 2026), privat reglerad sektor inom 36 mån (≈ dec 2027). **Konkret för Hubs:** om en kommun kräver LOA3 för en e-tjänst måste EUDI-plånboken godtas som alternativ — Hubs identitetsabstraktion måste kunna ta emot den. Bygg "accept wallet" som ett konfigurerbart kort, inte som hårdkodad BankID.

**Diggs tillitsramverk för svensk e-legitimation (tillitsnivå 2–4 ≈ LOA).**
- **Tillitsnivå 3 är de facto-krav** för tjänster som hanterar känsliga personuppgifter. Godkända på nivå 3: BankID, Freja eID Plus, SITHS. Sverige-id når nivå 4.
- Hubs ska kunna **kräva och visa** miniminivå per flöde (t.ex. orosanmälan/socialtjänst = minst LOA3) och **vägra** lägre Freja-kontonivåer. Detta bör synas i UI som ett spärrtillstånd, inte ett tyst fel.

**OSL (offentlighets- och sekretesslagen).**
- Sekretessreglerade uppgifter får inte röjas i okrypterade kanaler. 10 kap. 2 a § OSL (1 juli 2023) tillåter utlämnande till leverantör för enbart teknisk bearbetning/lagring om det inte är olämpligt — men kräver lämplighetsbedömning (eSam ES2023-06). **Hubs on-prem-modell undviker hela bedömningen** (ingen extern part får informationen). Notera videospecifika OSL-fällan: även **bokningsflödet** kan röja — om en extern part bokar/ser ett mötesrum kan det i sig vara ett röjande. Därför ska väntrum/identifiering ske **före** att någon sekretessbärande kontext exponeras.

**GDPR.**
- Personnummer och identitetsattribut från eID är personuppgifter; samla **minsta nödvändiga** (verifiera identitet, spara bevis på verifiering — inte mer). Tredjelandsöverföring (Schrems II, DPF:s osäkra status) är skälet till svensk/on-prem-drift. Spara `completionData`/verifieringsbevis med tydlig gallringsregel.

**HSLF-FS 2016:40 (Socialstyrelsen).**
- Kräver kryptering så endast avsedd mottagare kan läsa, samt **flerfaktorsautentisering vid elektronisk åtkomst** till uppgifter om hälsa. Direkt stöd för LOA3-kravet i kommunal HSL- och vårdnära flöden. Kraven kan **inte avtalas bort** med den enskildes samtycke.

**Arkivlagen (1990:782) + arkivföreskrifter.**
- Identifieringsbevis, läskvitton och mötesloggar som utgör underlag för beslut kan vara **allmänna handlingar** som ska bevaras/gallras enligt dokumenthanteringsplan. Hubs ska kunna exportera ett **revisionsspår per ärende** (vem identifierades, hur, när; vad skickades; när öppnades det) — och ha konfigurerbar gallring. Detta är både arkiv- och GDPR-krav i en funktion.

**DOS-lagen (2018:1937) + EN 301 549 / WCAG.**
- Medborgarriktade gränssnitt lyder under DOS-lagen. Bygg mot **WCAG 2.2 AA** redan nu (EN 301 549 v4.1.1 väntas få rättslig verkan ~2026). Mest relevanta nya kriterier för identifierings-/onboardingflöden:
  - **Accessible Authentication** — inloggning får inte kräva kognitiva test (t.ex. ingen "skriv av denna kod ur minnet"; eID-app-bekräftelse är OK, OTP-avskrift är gränsfall — erbjud alltid kopiera/klistra och uppläsning).
  - **Target Size 24×24 px** — "Logga in med BankID/Freja"-knappar och QR-/kodfält.
  - **Consistent Help** — hjälp ("Saknar du BankID?") på samma plats i varje steg.
- Konkret onboardingkrav: erbjud ett **icke-eID-spår** synligt och likvärdigt, inte gömt — annars exkluderas just de medborgare DOS-lagen ska skydda.

**NIS2 / cybersäkerhetslagen (2025:1506, i kraft 2026-01-15).**
- Misslyckade inloggningar och avvikande identifieringsförsök är säkerhetshändelser. Hubs identifieringslager bör mata ett incident-/säkerhetsunderlag (jfr regulatorik-analysen). Stark autentisering i medborgarflöden är även ett NIS2-hygienargument mot ledningen.

---

## Funktioner att bygga

Allt nedan delar ett **gemensamt identifierings- och leveranslager** ("ID-core") under säker dialog, säkert videomöte och säkra utskick. Bygg en gång, exponera i tre vyer.

### 1. Identitets-badge per motpart *(alla personas)*
Ett litet, konsekvent kort intill varje konversation/deltagare:
- **Metod + nivå:** "Verifierad med BankID · LOA3" / "Freja eID+ · LOA3 · icke-bofast" / "Sverige-id · LOA4" / "EU-plånbok".
- **Tidsstämpel + namn/personnummer-status:** "verifierad 2026-06-13 14:02".
- **Varningsläge:** "Ej verifierad — SMS-kod" eller "Ombud (anhörigbehörighet)".
- Datakälla: BankID `completionData` (signatur, användarinfo, OCSP, tid). Sparas i ärendelogg (arkiv/revision).
- **Persona som vinner:** socialsekreterare (orosanmälan/ekonomiskt bistånd — vet att rätt person identifierats), överförmyndarhandläggare (ställföreträdare vs. huvudman), HR (medarbetare i rehabärende).

### 2. Leveranstidslinje för utskick & meddelanden *(socialsekreterare, registrator, HR)*
Ersätter "ringa och kolla att faxen kom fram" med synlig status, à la GOV.UK task-statusar:
`Skickad → Levererad → Notis öppnad → Inloggad (LOA3) → Läst → Besvarad` (+ feltillstånd "Studsade / Notis ej öppnad inom X dagar → eskalera").
- Per kanal-ikon: SDK / säkert meddelande / digital brevlåda (Mina meddelanden) / fax.
- **Eskaleringsknapp** när medborgaren inte öppnat inom tröskel → "skicka via digital brevlåda" eller "skriv ut & posta" (hybridpost). Detta är onboardingens säkerhetsnät.
- **Persona:** registrator/handläggare (mottagaregaranti = compliance-värde, inget missat i sekretessflöde).

### 3. Väntrum/lobby-kort för säkert videomöte *(socialsekreterare, elevhälsa, SIP-deltagare)*
Direkt på dashboarden: "Dagens säkra möten" + live-väntrumsstatus.
- "2 deltagare i väntrummet — **Anna A. (BankID, LOA3, verifierad)**, **okänd (SMS-kod, ej eID)**" med manuell insläppsknapp per person (matchar Compodiums modell, överträffar genom ärendekoppling).
- **Teknikkontroll** för medborgaren före insläpp (kamera/mikrofon), jfr Visiba.
- **Flerpart med blandade nivåer:** huvudperson via BankID, anhörig/ombud via eget BankID, tolk via länk. Visa varje deltagares nivå.
- **Persona:** socialsekreterare (klientmöte), elevhälsa (vårdnadshavare + elev), SIP (kommun+region+anhörig).

### 4. Onboarding-väljare "Hur ska medborgaren legitimera sig?" *(handläggare vid utskick/bokning)*
När handläggaren skapar en säker dialog/möte: en progressiv väljare som sätter **minsta tillitsnivå** och fallback-policy:
- Primärt: BankID / Freja eID+ / Sverige-id / EU-plånbok (kort som tänds när de blir tillgängliga).
- Sekundärt: **SMS-OTP-fallback** — men *märkt* som lägre tillit ("identitet ej eID-verifierad"), spärrbar per flödestyp (t.ex. förbjuden för orosanmälan), och med en synlig not om att SMS-OTP är *restricted* (NIST). Hubs bör default-spärra SMS-OTP för LOA3-krävande flöden och tillåta det bara som komplement, aldrig som ensam faktor för sekretess.
- **Icke-eID-spår (ombud/anhörigbehörighet):** flöde där en anhörig med eget BankID företräder en huvudman som saknar eID (demens, sjukdom, minderårig) — handläggaren registrerar ombudsrelationen och badgen visar "Ombud: Erik E. (BankID) företräder Karin K.". Detta är onboardingens **mest underbyggda lucka** i dagens lösningar.
- **Persona:** alla medborgarvända; särskilt äldreomsorg/överförmyndare där huvudmannen ofta saknar eID.

### 5. "Saknar du BankID?"-hjälpvy för medborgaren *(medborgare, WCAG-krav)*
Synlig, likvärdig hjälpväg i mottagarvyn (inte gömd): skaffa BankID/Freja, använda ombud, eller boka fysiskt/telefonärende. Uppfyller Consistent Help + Accessible Authentication och DOS-lagens inkluderingssyfte.

### 6. "eIDAS2-/Sverige-id-redo"-status *(IT-chef, upphandling)*
Adminvy/kort som visar vilka identitetsleverantörer som är aktiverade (BankID ✓, Freja ✓, Sverige-id ✓ när lanserad dec 2026, EU-plånbok ✓ när noden är klar). Görs till **demonstrerbart upphandlingsargument** — konkurrenter kan sällan visa detta samlat.

---

## Rekommendation för Hubs

1. **Bygg ett leverantörsoberoende ID-core, inte en BankID-integration.** Modellera "identitetsleverantör" som ett interface (auth-order, collect, completionData, LOA-mappning) med BankID och Freja först, och Sverige-id (dec 2026) + EUDI-plånbok (2026/27) som inkopplingsbara. Detta är skillnaden mellan en produkt som åldras 2027 och en som säljs på "eIDAS2-redo".

2. **Gör identitet och leverans synligt — det är Hubs differentiator, inte själva inloggningen.** Identitets-badge (metod+LOA+tid), leveranstidslinje och väntrums-kort är de tre vyerna ingen konkurrent har samlat. De svarar direkt mot den dokumenterade fax-rädslan ("kom det fram, till rätt person?") och mot NIS2/arkiv-spårbarhet.

3. **Behandla SMS-OTP som en märkt nödutgång, inte en faktor.** Default-spärra SMS-OTP som ensam autentisering för LOA3-flöden (NIST *restricted*; internationell utfasning 2026). Tillåt det bara som komplement eller för icke-sekretessbelagda notiser, och visa alltid tillitsnedsättningen i badgen. Det är både säkrare och ett säljargument mot konkurrenter som likställer SMS med eID.

4. **Lös icke-eID-fallet ärligt (ombud/anhörigbehörighet + fysiskt spår).** ~5–10 % saknar eID, ofta just i äldreomsorg/överförmyndare/socialtjänst där Hubs är starkast. En väl visualiserad ombudsmodell och ett synligt fallback-spår är en differentiator OCH ett DOS-lagskrav — ingen konkurrent gör detta bra.

5. **Positionera utkanalen rätt: dialog ≠ Kivra.** Bygg på sikt en Mina meddelanden-koppling så formella beslut kan gå ut via medborgarens digitala brevlåda från samma handläggarvy (SOU 2024:47 gör detta sannolikt obligatoriskt), men marknadsför Hubs som **den säkra tvåvägsdialogen** — det är där Kivra är blind.

6. **Bygg mot WCAG 2.2 AA i identifierings-/onboardingstegen från dag ett** (Accessible Authentication, 24×24 px, Consistent Help) och dokumentera efterlevnad per kriterium — tillgänglighet är ett poängsatt upphandlingskrav, inte bara en plikt.

7. **Spara verifieringsbevis som revisionsspår per ärende med gallringsregel.** Ett exporterbart spår (vem/hur/när identifierad, vad skickat, när öppnat) tjänar arkivlagen, GDPR och NIS2-incidentunderlag i en funktion — och blir ett konkret bevis för "compliance by design" i demon.

---

## Källor

**Statlig e-legitimation (Sverige-id) & tillitsnivåer**
- https://www.digg.se/om-oss/nyheter/digital-identitet/nyheter/2026-04-21-den-statliga-e-legitimationen-sverige-id-godkand-av-digg
- https://polisen.se/tjanster-tillstand/pass-och-nationellt-id-kort/statlig-e-legitimation-sverige-id/
- https://polisen.se/aktuellt/nyheter/nationell/2026/mars/polismyndigheten-utvecklar-en-ny-e-legitimation-pa-hogsta-sakerhetsniva/
- https://www.regeringen.se/pressmeddelanden/2026/05/regeringen-infor-en-statlig-e-legitimation/
- https://regeringen.se/regeringsuppdrag/2025/04/uppdrag-att-utfarda-en-statlig-e-legitimation-pa-hogsta-tillitsniva/
- https://www.digg.se/digitala-tjanster/e-legitimering/om-e-legitimering/tillitsnivaer-for-e-legitimering
- https://www.digg.se/om-oss/nyheter/digital-identitet/nyheter/2024-12-18-digg-godkanner-freja-pa-tillitsniva-3-for-icke-bofasta

**eIDAS2 / EUDI-plånbok**
- https://www.digg.se/kunskap-och-stod/eu-rattsakter/eidas-forordningen
- https://www.digg.se/styrning-och-samordning/ena---sveriges-digitala-infrastruktur/strategisk-fardplan-ena-2030/digital-identitetsplanbok
- https://www.eideasy.com/blog/eu-digital-identity-wallet-acceptance-2027
- https://www.bakermckenzie.com/en/insight/publications/2026/03/european-union-eudi-wallet-harmonizes-identification-and-age-gating
- https://www.signicat.com/se/blogg/eu-planboken-och-sveriges-digitala-framtid
- https://fides.community/ecosystem-explorer/personal-wallets/?wallet=national-eudi-wallet-sweden
- https://www.regeringen.se/rattsliga-dokument/statens-offentliga-utredningar/2024/06/sou-202445/

**BankID / Freja relying party-flöde**
- https://www.bankid.com/utvecklare/guider/teknisk-integrationsguide/graenssnittsbeskrivning/collect
- https://frejaeid.com/frejas-olika-tillits-och-kontonivaer/
- https://org.frejaeid.com/en/an-e-id-for-foreign-citizens/
- https://sdk.se/kommuner-brottas-med-valet-av-e-legitimation-for-loa3-enligt-digg/

**SMS-OTP / autentiseringssäkerhet**
- https://blog.typingdna.com/nist-sp-800-63b-rev-4-sms-otp-is-now-a-restricted-authenticator-but-we-have-the-fix/
- https://www.authsignal.com/blog/articles/why-sms-based-authentication-is-no-longer-enough-for-secure-account-protection
- https://securityboulevard.com/2026/04/silent-network-authentication-the-invisible-layer-replacing-sms-otp-in-2026/

**Digital brevlåda / Mina meddelanden (utkanal)**
- https://www.regeringen.se/contentassets/a358f464e0f04c818f466b0191124525/sou_2024_47_ny-kopia.pdf
- https://www.digg.se/digitala-tjanster/digital-post/digital-post-for-dig-som-offentlig-aktor
- https://www.digg.se/digitala-tjanster/digital-post

**Medborgarflöden, väntrum, ombud (konkurrenter & praxis)**
- https://compodium.se/vidicue
- https://digitalasamtal.se/sakra-videosamtal-for-socialtjansten/
- https://www.visibagroup.com/sv/visiba-care/anpassade-floden
- https://sefos.se/en/sakra-meddelanden/
- https://www.falun.se/utbildning--barnomsorg/e-tjanster-och-blanketter/om-vara-e-tjanster/saker-inloggning.html
- https://anhoriga.se/fragor--svar/vanliga-fragor--svar/hur-kan-jag-hjalpa-min-narstaende-som-har-en-demenssjukdom-eller-annan-sjukdom-att-fa-e-legitimation-t.ex.-bank-id-eller-ett-freija-id/

**Tillgänglighet / WCAG**
- https://www.digg.se/webbriktlinjer/lagar-och-krav/det-har-ar-en-301-549-och-wcag
- https://www.digg.se/analys-och-uppfoljning/lagen-om-tillganglighet-till-digital-offentlig-service-dos-lagen/om-lagen

**OSL / sekretess / HSLF-FS (från grundningsanalyserna)**
- https://www.socialstyrelsen.se/kunskapsstod-och-regler/regler-och-riktlinjer/juridiskt-stod-for-dokumentation/kommunicera-over-internet-eller-andra-oppna-nat/
- https://www.esamverka.se/download/18.43a3add4188b9f2345a2fe78/1687332814480/ES2023-06%20V%C3%A4gledning%20Utkontraktering%20-%20sekretess%20och%20dataskydd.pdf
