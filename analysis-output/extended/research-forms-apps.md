# Forms, Tables, Whiteboard, Calendar, Maps, Notes — värde per persona och som widgetar i Hubs

> Researchunderlag för Hubs (ITSL). Brand-regel: i produkt-/kundtext heter det aldrig "Nextcloud" eller "Talk" — här i analysen används de underliggande appnamnen för spårbarhet, men föreslagna widget-/funktionsnamn är Hubs-branded.
> Datum: 2026-06-13. Hubs körs på server v32 (Hub 25 Autumn) enligt repo.

## Sammanfattning

De sex apparna i scope levererar **mycket olika värde** för Hubs personas, och flera har konkreta begränsningar som avgör hur de bör paketeras:

- **Calendar (tidsbokning/appointments)** och **Whiteboard (samverkansmöten/SIP)** är de **två tydliga vinnarna**. Båda har mogen, officiellt underhållen funktionalitet som direkt löser dokumenterade behov: Calendar har inbyggd bokningssida med publik länk och *automatiskt skapat säkert videorum* per bokad tid; Whiteboard är en officiell realtidstavla som kan bäddas in mitt i ett säkert videomöte. De är de starkaste kandidaterna att lyfta in i Hubs SIP- och bokningsflöden.
- **Tables** är den **bästa byggklossen för "strukturerade register"** — ett no-code-databaslager med moget API (v2.x), egen dashboard-widget och åtkomststyrning. Värdet är inte att visa Tables rått för slutanvändaren utan att använda det som *backend för triage-/bevakningslistor* (funktionsadress-fördelning, deadline-register för årsräkningar, avvikelse-/incidentlogg för NIS2).
- **Forms** har **högt latent värde men allvarliga produktbegränsningar**: ingen native filuppladdning i svar och ingen villkorslogik/förgrening. Det gör att appen *inte* kan ersätta en kommunal e-tjänst för orosanmälan rakt av (som ofta kräver bilagor och förgrening). Forms passar däremot utmärkt för **internt strukturerad inhämtning** där fält och samtycke är enkla: SIP-samtycke, internt orosanmälnings-/avvikelseformulär, enkäter, bokningsunderlag.
- **Maps** är **den svagaste och mest riskabla** kandidaten: appen är vid årsskiftet 2025/26 endast kompatibel med v31, saknar v32-release och har öppen underhållsfråga i communityt. Hembesöks-/uppsökande-caset (förstärkt av nya socialtjänstlagen 2025:400) är reellt men bör **inte** byggas på Maps-appen i Hubs v32-miljö. Lös geografi senare, t.ex. via en lättviktig kartwidget eller Tables-fält, inte via Maps-appen.
- **Notes** ger lågt särskiljande värde (enkel markdown-anteckningsapp). Användbart som personlig minneslapp/checklista men inte ett säljargument; bör inte prioriteras som egen widget.

Strategisk slutsats: bygg **Calendar-bokning + Whiteboard** in i SIP-/mötespaketeringen, använd **Tables** som osynlig motor under triage- och register-widgets, använd **Forms** för internt samtycke/inrapportering (med tydlig kommunikation om filuppladdningsgränsen), och **avstå** från Maps och Notes som differentierare.

---

## Marknad & aktörer

### E-tjänster/formulär — den etablerade svenska marknaden Hubs INTE ska konkurrera med head-on
Den medborgarriktade e-tjänsten för t.ex. orosanmälan ägs i svenska kommuner av specialiserade e-tjänsteplattformar, inte av generella formulärappar:

- **Open ePlatform** (öppen källkod, ursprung Härnösand/Nordic Peak, idag brett spridd) — används av ett stort antal kommuner; har inbyggt stöd för e-legitimation (BankID/Freja), dokumentsignering och ärendeöverföring till verksamhetssystem. ([Sokigo om öppen e-tjänsteplattform](https://sokigo.com/nyheter/7299/), [Open ePlatform-upphandling Kalmar](https://www.mercell.com/sv-se/upphandling/243464983/e--tjansteplattform-open-eplatform-drift-och-utveckling-upphandling.aspx), [Dokumentsignering med Open ePlatform](https://digitaliseringsguiden.se/losningar/dokumentsignering-med-open-eplatform/))
- **Abou (Sokigo)** — flexibel e-tjänsteplattform med öppen kod, marknadsförs för kommun/region/myndighet; import av andras e-tjänster mellan kommuner. ([Sokigo Abou](https://sokigo.com/produkter/abou/))
- **Visma (t.ex. Visma Sign / e-tjänster)** och övriga kommunala portalleverantörer kompletterar bilden.

Detta betyder att **professionella anmälare möter olika e-tjänstgränssnitt i varje kommun** (Haninge, Eskilstuna, Stockholm, Örebro m.fl. har egna), utan nationell standard — vilket redan dokumenterats i personaunderlaget. Hubs ska **inte** försöka ersätta den publika e-tjänsten; den vinner i stället i ledet *efter* inlämning (säker överföring, triage, samverkan) och i *interna* formulärflöden där dagens lösning är Word-blanketter och e-post.

### De sex apparnas mognad och status (faktagrund för paketeringsbeslut)
- **Forms**: aktiv officiell app. Stöder publik delningslänk, anonyma svar, en-svar-per-inloggad-användare, resultatvy med enkla diagram, CSV-export och **JSON-API** för åtkomst till svar. **Saknar** villkorslogik/förgrening och **saknar native filuppladdning i svar** (workaround: länka till en separat fildropp). ([Nextcloud Forms i App Store](https://github.com/nextcloud/forms), [conditional logic-issue #358](https://github.com/nextcloud/forms/issues/358), [filuppladdning-tråd](https://help.nextcloud.com/t/form-with-file-upload-capability/62189), [MassiveGRID jämförelse](https://massivegrid.com/blog/nextcloud-forms-vs-google-forms-microsoft-forms/))
- **Tables**: mogen (versionsserie upp till **2.1.0**), no-code register med kolumner/rader/vyer, **REST-API** (GET/POST för tabeller, rader, kolumner), **egen dashboard-widget**, mobilklienter och offline. Del av "Nextcloud Flow". ([Bygg appar med Tables](https://nextcloud.com/blog/build-apps-using-nextcloud-tables/), [Tables-releaser](https://apps.nextcloud.com/apps/tables/releases?platform=23), [Tables v2 API-issue](https://github.com/nextcloud/tables/issues/2237))
- **Whiteboard**: officiell realtidstavla (oändlig canvas). Kräver en **WebSocket-backend** för live-samarbete (grundfunktion fungerar utan), och kan **skapas direkt i ett pågående videomöte via chattens bifoga-meny** och delas till alla deltagare. ([Nextcloud Whiteboard-blogg](https://nextcloud.com/blog/nextcloud-whiteboard/), [whiteboard GitHub README](https://github.com/nextcloud/whiteboard/blob/main/README.md))
- **Calendar (Appointments)**: inbyggt **bokningssystem** med konfigurerbar längd/intervall/tillgänglighet; **publika eller privata bokningssidor** (publika syns på användarens profil, privata via hemlig URL); vid videointegration skapas **ett unikt mötesrum automatiskt** i bokningsbekräftelsen. Önskemål om en helt fristående publik bokningssida och auto-videorum är delvis levererade och delvis under arbete uppströms. ([Appointment Booking System – DeepWiki](https://deepwiki.com/nextcloud/calendar/7-appointment-booking-system), [skapa videorum för bokning #3480](https://github.com/nextcloud/calendar/issues/3480), [publik bokningssida #3484](https://github.com/nextcloud/calendar/issues/3484))
- **Maps**: senaste release endast **v31-kompatibel** (per jan 2026), **ingen v32-release** trots inskickade PR:er, och öppen fråga i communityt om appen underhålls aktivt. Gjordes mer kollaborativ efter NextGov-hackathon 2025 (dela plats, tagga bilder). ([Är Maps fortfarande underhållen?](https://help.nextcloud.com/t/is-maps-still-actively-maintained/239281), [Maps GitHub](https://github.com/nextcloud/maps), [Maps nya funktioner](https://nextcloud.com/blog/plan-your-next-trip-with-nextcloud-maps-new-features/))
- **Notes**: enkel markdown-anteckningsapp med API och mobilklienter; stabil men funktionsmässigt smal — ingen strukturerad data, ingen delning utöver fildelning.

### Angränsande svenska aktörer/flöden som apparna rör vid
- **SIP** koordineras av kommun + region + ev. skola/anhöriga och dokumenteras i regionala system (Cosmic LINK/Lifecare); samtycke krävs för att bryta sekretess. ([1177 om SIP](https://www.1177.se/sa-fungerar-varden/sa-samarbetar-vard-och-omsorg/sip---samordnad-individuell-plan/), [SKR SIP](https://skr.se/skr/halsasjukvard/patientinflytande/samordnadindividuellplansip.samordnadindividuellplan.html), [Uppdrag Psykisk Hälsa – SIP](https://www.uppdragpsykiskhalsa.se/sip/))
- **Tidsbokning hos socialtjänst** finns redan som e-tjänst (t.ex. Eskilstuna socialrådgivning, max 45 min, kan vara anonym), och inom vården via 1177 Tidbokning (Inera). ([Eskilstuna boka tid](https://www.eskilstuna.se/e-tjanster-och-blanketter/boka-tid-hos-socialradgivningen), [1177 Tidbokning – Inera](https://www.inera.se/tjanster/alla-tjanster-a-o/1177-tidbokning/))
- **Ny socialtjänstlag (2025:400, i kraft 1 juli 2025)** reglerar tydligare **förebyggande och uppsökande arbete** — vilket aktualiserar hembesök/uppsökande som arbetsmoment och därmed (i teorin) geografi/Maps. ([Socialtjänstlag 2025:400](https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/socialtjanstlag-2025400_sfs-2025-400/), [Socialstyrelsen om nya lagen](https://www.socialstyrelsen.se/aktuellt/ny-socialtjanstlag-2025--forberedelser-och-forandringar/), [SKR lagändringarna i korthet](https://extra.skr.se/framtidenssocialtjanst/nysocialtjanstlag/lagandringarnaikorthet.79975.html))

---

## Juridik & krav

Apparna rör flera kravområden samtidigt; de mest styrande för dessa flöden:

- **OSL (offentlighets- och sekretesslagen).** Sekretessreglerade uppgifter får inte röjas i digitala kanaler utan tillräckligt skydd. Det gäller även **innehållet i ett formulärsvar, en register-rad eller en whiteboard** om det rör en enskild. Konsekvens: Forms/Tables/Whiteboard-data ska ligga i Hubs egen driftmiljö (kundens servrar), inte i extern molnform. SIP förutsätter ett **samtycke** för att bryta sekretess mellan kommun och region — själva samtyckesinhämtningen är ett perfekt Forms-/signeringsflöde. ([Socialstyrelsen – kommunicera över öppna nät](https://www.socialstyrelsen.se/kunskapsstod-och-regler/regler-och-riktlinjer/juridiskt-stod-for-dokumentation/kommunicera-over-internet-eller-andra-oppna-nat/), [NCK om samverkan och sekretess/SIP](https://www.uu.se/centrum/nck/for-yrkesverksamma/webbstodforvarden/samverkan-och-sekretess/om-samverkan-och-sekretess/samordnad-individuell-plan-sip))
- **GDPR.** Strukturerade register (Tables) och formulärsvar (Forms) är ofta särskilda kategorier (hälsa, sociala förhållanden, barn). Kräver laglig grund, ändamålsbegränsning, behörighetsstyrning och gallring. Tables/Forms måste därför användas med rollstyrd åtkomst och definierad gallringsrutin — inte som öppna kalkylblad.
- **HSLF-FS 2016:40.** För hälso- och sjukvårdens (inkl. kommunal HSL) elektroniska hantering av personuppgifter krävs kryptering så att endast avsedd mottagare kan läsa, samt stark autentisering (MFA) vid elektronisk åtkomst. Relevant när Forms/Tables/Whiteboard används i HSL-nära flöden (SIP, vårdplan). Kraven kan inte avtalas bort med den enskildes samtycke.
- **eIDAS / eIDAS2 + Diggs tillitsramverk (LOA).** Tillitsnivå 3 (BankID, Freja eID Plus, SITHS) är de facto-krav för tjänster med känsliga personuppgifter. För **medborgarriktade** formulär/bokningar (t.ex. en publik orosanmälan- eller bokningssida) bör inloggning ske på LOA3; för intern personalåtkomst gäller samma. eIDAS2/EUDI-wallet kommer behöva accepteras i medborgarflöden inom planeringshorisonten. Notera: Nextcloud Forms publika länk och Calendar publika bokningssida är **oautentiserade som standard** — för känsliga ärenden måste Hubs lägga ett identitetslager framför eller begränsa dem till interna/inloggade flöden. ([Digg – tillitsnivåer](https://www.digg.se/digitala-tjanster/e-legitimering/om-e-legitimering/tillitsnivaer-for-e-legitimering), [Digg – eIDAS](https://www.digg.se/kunskap-och-stod/eu-rattsakter/eidas-forordningen))
- **Arkivlagen (1990:782) + arkivförordningen + Riksarkivets föreskrifter (RA-FS).** Ett inkommet formulär, en samtyckeshandling och i många fall register-rader är **allmänna handlingar** som ska bevaras/gallras enligt fastställda regler; gallring kräver stöd i beslut. Från 2025 erbjuder Riksarkivet digital arkiveringstjänst även för kommuner/regioner. Konsekvens för Hubs: Forms/Tables-data måste kunna **exporteras till e-arkiv** (CSV/JSON finns redan) och förses med metadata/gallringsregel — annars blir de en arkivrättslig skuld. ([Digg – bevarande- och gallringsregler](https://www.digg.se/styrning-och-samordning/ena---sveriges-digitala-infrastruktur/byggblock/sparbarhet/ramverk-loggning-och-sparbarhet/lagkrav/bevarande--och-gallringsregler), [SKR – informationsförvaltning](https://skr.se/digitaliseringivalfarden/informationsforvaltning.8430.html))
- **DOS-lagen / WCAG.** Offentliga aktörers webbgränssnitt ska uppfylla EN 301 549 (idag WCAG 2.1 AA, 2.2 AA på väg). Träffar publika Forms-formulär, Calendar-bokningssidor och alla widgetar Hubs renderar. Whiteboard (fri canvas, drag-and-drop) är notoriskt svår att göra tillgänglig — den bör därför positioneras som **komplement i ett möte**, aldrig som enda bärare av beslut/information. WCAG 2.2-kriterierna Target Size 24×24 px och Dragging Movements (alternativ till drag) är direkt relevanta för whiteboard- och widget-interaktion. ([Digg – DOS-lagen](https://www.digg.se/analys-och-uppfoljning/lagen-om-tillganglighet-till-digital-offentlig-service-dos-lagen/om-lagen), [Digg – EN 301 549/WCAG](https://www.digg.se/webbriktlinjer/lagar-och-krav/det-har-ar-en-301-549-och-wcag))
- **NIS2 / cybersäkerhetslagen (2025:1506, i kraft 15 jan 2026).** Ledningen har personligt ansvar och betydande incidenter ska rapporteras till MCF. Det skapar ett konkret användningsfall för **Tables som avvikelse-/incidentregister** med spårbarhet — ett kravdrivet flöde få konkurrenter löser i samma yta.

---

## Funktioner att bygga (widget- och flödesidéer per persona)

Personaförkortningar: **SOC** = socialsekreterare, **REG** = registrator/funktionsbrevlåda-ägare, **KOMSSK** = kommunsjuksköterska/HSL, **HR** = HR/chef rehab, **ÖFM** = överförmyndarhandläggare, **HEMTJ** = hemtjänst, **ELEV** = elevhälsa/skola, **CHEF** = förvaltnings-/verksamhetschef.

### A. Calendar (bokning/appointments) — högsta värde
**A1. "Boka säkert möte"-widget (SIP-/samtals-kallelse).** En kort-vy i dashboarden där handläggaren skapar en bokningsbar tid och delar länk; vid bokning skapas automatiskt ett **säkert videorum** och kalenderhändelse. Card View: "Skapa bokningstid" → Quick View: välj längd/intervall/deltagare.
- *Nytta:* **SOC, KOMSSK, ELEV, HR** (rehabmöte), **ÖFM** (möte med ställföreträdare). Löser det dokumenterade gapet att Region Uppsala 2022 bedömde Skype som "säkraste plattformen" för digitala SIP-möten — Hubs ersätter det med bokning + säkert videorum i ett steg.
**A2. "Dagens & veckans möten"-widget.** Aggregerad lista över bokade/kommande säkra möten med en-klicks-anslut. Nytta: alla mötesintensiva personas, särskilt **KOMSSK/HEMTJ**.
**A3. Publik bokningssida för medborgarkontakt (med identitetslager).** För t.ex. socialrådgivning (jfr Eskilstuna, max 45 min, ev. anonym). Måste föregås av LOA-bedömning: anonym rådgivning kan vara oautentiserad, men ärendebunden bokning bör kräva LOA3. Nytta: **SOC, REG**.

### B. Whiteboard (samverkansmöten/SIP) — högt värde, som möteskomplement
**B1. "SIP-tavla i mötet".** En whiteboard som startas inifrån det säkra videomötet (mål, ansvar, vem-gör-vad-tidslinje) och delas till alla deltagare i realtid; resultatet sparas som fil knuten till ärendet. Nytta: **SOC, KOMSSK, ELEV** + anhöriga/medborgare i mötet.
- *Krav att hantera:* WebSocket-backend måste driftsättas; tillgänglighet (fri canvas) gör att tavlan ska vara stöd, inte protokoll — det formella beslutet dokumenteras i SIP-plan/Forms.
**B2. Mall-tavlor.** Förifyllda SIP-/planeringsmallar (rutor: "Mål", "Insatser", "Ansvarig", "Uppföljningsdatum") som sänker tröskeln och ger igenkänning över kommuner. Nytta: **SOC, CHEF**.

### C. Tables (strukturerade register) — hög hävstång som *motor* bakom widgets
**C1. Triage-register bakom funktionsadress-inkorgen.** Tables som backend för "vem tar detta ärende?" — kolumner för status (Ny/Påbörjad/Väntar/Klar/Problem à la GOV.UK), ansvarig, mottagen-kanal (SDK/säker e-post/fax), tidsfrist. Renderas som Hubs triage-widget, inte som rå tabell. Nytta: **REG, SOC, KOMSSK**.
**C2. Deadline-register för årsräkningar.** Tables med ställföreträdare, status, deadline (1 mars), påminnelse. Dashboard-widget "Att granska före 1 mars". Nytta: **ÖFM**.
**C3. Avvikelse-/incidentregister (NIS2 + verksamhetsavvikelser).** Strukturerad logg över avvikelser i samverkan (kommun↔region) och säkerhetsincidenter, med spårbarhet och export till incidentrapport. Nytta: **KOMSSK, CHEF, REG**. Kopplar direkt till ledningens NIS2-ansvar.
**C4. "Nytta hittills"-register.** Räknar ersatta fax/rek-brev och uppskattad sparad tid (Diggs schablon ~30 min/ärende) som ROI-widget för chefen. Nytta: **CHEF**.

### D. Forms (intern inhämtning + samtycke) — bra inom sina gränser
**D1. SIP-samtycke som signerat formulär.** Forms med tydliga fält + samtyckesruta; resultatet arkiveras som handling knuten till ärendet. Eftersom Forms saknar inbyggd e-signering bör Hubs koppla på ett signeringssteg (eller minst BankID-inloggning + tidsstämplad logg) för rättslig hållbarhet. Nytta: **SOC, KOMSSK**.
**D2. Internt orosanmälnings-/avvikelseformulär.** För personal som *internt* rapporterar (inte den publika e-tjänsten). Strukturerar data och matar C1/C3-registret. Nytta: **ELEV, HEMTJ, SOC**.
- *Begränsning att kommunicera:* ingen native filuppladdning och ingen förgrening — Hubs måste antingen acceptera enkla formulär eller bygga ett tunt eget formulärlager ovanpå för fält som kräver bilaga/villkor.
**D3. Enkät/återkoppling (t.ex. SIP-deltagarupplevelse, jfr SIPkollen).** Lågkänslig, passar Forms rakt av. Nytta: **CHEF, SOC**.

### E. Maps (hembesök) — låt vänta, bygg inte på Maps-appen nu
Den nya socialtjänstlagen (förebyggande/uppsökande) gör hembesöks-/ruttcaset relevant för **SOC/HEMTJ**, men Maps-appen saknar v32-stöd och har osäker underhållsstatus. **Rekommendation:** skjut upp; om geografi behövs på kort sikt, lägg adress-/kartfält i ett Tables-register (C) eller en lättviktig inbäddad kartwidget, inte Maps-appen. Bevaka uppströms v32-release innan något byggs.

### F. Notes — ej prioriterad
Personlig markdown-anteckning/checklista. Marginellt värde, inget säljargument. Möjlig framtida "min checklista"-mikrowidget, men ska inte ta utvecklingstid från A–D.

---

## Rekommendation för Hubs

1. **Prioritera Calendar-bokning + Whiteboard in i SIP-/mötespaketeringen (P1).** Det är de två apparna med mogen, officiell funktionalitet som löser dokumenterade behov (säkert bokat möte, samverkanstavla). Paketera som ett sammanhållet **SIP-flöde**: kallelse/bokning (Calendar, auto-videorum) → möte med samverkanstavla (Whiteboard) → samtycke (Forms, D1) → uppföljning (Tables, C1). Det är ett unikt, Sverige-specifikt flöde ingen av de europeiska sviterna (openDesk, La Suite) marknadsför.
2. **Använd Tables som osynlig motor under triage-/register-widgets (P1).** Visa aldrig rå Tables-vy för slutanvändaren; rendera Hubs-branded widgets (triage, deadlines, avvikelser, nytta) ovanpå Tables-API:t. Det ger snabb time-to-value utan att bygga eget databaslager, och knyter direkt an till NIS2-/ROI-säljargumenten mot ledningen.
3. **Positionera Forms ärligt: internt samtycke och inrapportering, inte publik e-tjänst (P2).** Konkurrera inte mot Open ePlatform/Abou/Visma på medborgar-e-tjänsten. Sälj Forms som ersättare för Word-blanketter + e-post internt. **Lös filuppladdnings- och signeringsgapet** innan D1/D2 demas mot kund (signeringssteg + fildropp), annars blir begränsningen synlig i upphandlingsdemo.
4. **Avstå Maps och Notes som differentierare (P3).** Maps är tekniskt blockerad (ingen v32) och underhållsosäker; Notes är för smal. Bygg inte beroenden till dem. Hembesöks-geografi löses vid behov via Tables-fält.
5. **Bygg in juridiken som synligt värde.** Varje Forms-/Tables-yta ska ha (a) rollstyrd åtkomst, (b) definierad gallringsregel och CSV/JSON-export mot e-arkiv (arkivlagen + Riksarkivets tjänst 2025), (c) LOA3 framför alla känsliga/medborgarriktade flöden, (d) WCAG 2.2-hänsyn (target size, drag-alternativ) — särskilt eftersom de publika Forms-/Calendar-länkarna är oautentiserade som standard och måste inramas av Hubs identitetslager.
6. **Mätetal:** mät tid-till-bokat-möte och andel SIP-möten genomförda i Hubs (Calendar+Whiteboard), antal interna formulär som ersatt Word/e-post (Forms), och register-genomströmning/deadline-träffsäkerhet (Tables) — inte tid på dashboarden.

---

## Källor

Nextcloud-appar (funktion, version, status):
- Forms: https://github.com/nextcloud/forms · https://github.com/nextcloud/forms/issues/358 (villkorslogik) · https://help.nextcloud.com/t/form-with-file-upload-capability/62189 (filuppladdning) · https://massivegrid.com/blog/nextcloud-forms-vs-google-forms-microsoft-forms/
- Tables: https://nextcloud.com/blog/build-apps-using-nextcloud-tables/ · https://apps.nextcloud.com/apps/tables/releases?platform=23 · https://github.com/nextcloud/tables/issues/2237
- Whiteboard: https://nextcloud.com/blog/nextcloud-whiteboard/ · https://github.com/nextcloud/whiteboard/blob/main/README.md
- Calendar (Appointments): https://deepwiki.com/nextcloud/calendar/7-appointment-booking-system · https://github.com/nextcloud/calendar/issues/3480 · https://github.com/nextcloud/calendar/issues/3484
- Maps: https://help.nextcloud.com/t/is-maps-still-actively-maintained/239281 · https://github.com/nextcloud/maps · https://nextcloud.com/blog/plan-your-next-trip-with-nextcloud-maps-new-features/

Svensk e-tjänstmarknad/incumbents:
- Open ePlatform: https://sokigo.com/nyheter/7299/ · https://www.mercell.com/sv-se/upphandling/243464983/e--tjansteplattform-open-eplatform-drift-och-utveckling-upphandling.aspx · https://digitaliseringsguiden.se/losningar/dokumentsignering-med-open-eplatform/
- Abou (Sokigo): https://sokigo.com/produkter/abou/
- Orosanmälan-e-tjänst (exempel): https://www.linkoping.se/e-tjanster-och-blanketter/omsorg-och-stod/orosanmalan-vid-misstanke-eller-kannedom-om-att-barn-far-illa

SIP, samtycke, bokning, lagstiftning:
- 1177 SIP: https://www.1177.se/sa-fungerar-varden/sa-samarbetar-vard-och-omsorg/sip---samordnad-individuell-plan/
- SKR SIP: https://skr.se/skr/halsasjukvard/patientinflytande/samordnadindividuellplansip.samordnadindividuellplan.html
- Uppdrag Psykisk Hälsa SIP: https://www.uppdragpsykiskhalsa.se/sip/
- NCK samverkan/sekretess SIP: https://www.uu.se/centrum/nck/for-yrkesverksamma/webbstodforvarden/samverkan-och-sekretess/om-samverkan-och-sekretess/samordnad-individuell-plan-sip
- Eskilstuna boka tid socialrådgivning: https://www.eskilstuna.se/e-tjanster-och-blanketter/boka-tid-hos-socialradgivningen
- 1177 Tidbokning (Inera): https://www.inera.se/tjanster/alla-tjanster-a-o/1177-tidbokning/
- Socialtjänstlag 2025:400: https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/socialtjanstlag-2025400_sfs-2025-400/
- Socialstyrelsen ny socialtjänstlag: https://www.socialstyrelsen.se/aktuellt/ny-socialtjanstlag-2025--forberedelser-och-forandringar/
- SKR lagändringarna i korthet: https://extra.skr.se/framtidenssocialtjanst/nysocialtjanstlag/lagandringarnaikorthet.79975.html

Juridik & krav:
- Socialstyrelsen – kommunicera över öppna nät: https://www.socialstyrelsen.se/kunskapsstod-och-regler/regler-och-riktlinjer/juridiskt-stod-for-dokumentation/kommunicera-over-internet-eller-andra-oppna-nat/
- Digg – tillitsnivåer för e-legitimering (LOA): https://www.digg.se/digitala-tjanster/e-legitimering/om-e-legitimering/tillitsnivaer-for-e-legitimering
- Digg – eIDAS-förordningen: https://www.digg.se/kunskap-och-stod/eu-rattsakter/eidas-forordningen
- Digg – bevarande- och gallringsregler: https://www.digg.se/styrning-och-samordning/ena---sveriges-digitala-infrastruktur/byggblock/sparbarhet/ramverk-loggning-och-sparbarhet/lagkrav/bevarande--och-gallringsregler
- SKR – informationsförvaltning/e-arkiv: https://skr.se/digitaliseringivalfarden/informationsforvaltning.8430.html
- Digg – DOS-lagen: https://www.digg.se/analys-och-uppfoljning/lagen-om-tillganglighet-till-digital-offentlig-service-dos-lagen/om-lagen
- Digg – EN 301 549/WCAG: https://www.digg.se/webbriktlinjer/lagar-och-krav/det-har-ar-en-301-549-och-wcag
