# Uppgifts-/ärendehantering & todo i offentlig handläggning

Forskningsunderlag för Hubs (ITSL) — "Mina uppgifter"/"Bevakningar"-funktionalitet i dashboarden.
Brandregel: i produktnära text säg aldrig "Nextcloud" eller "Talk" — använd "Hubs", "kanban-/uppgiftsmodulen", "säkra videomöten".

---

## Sammanfattning

Handläggare i svensk offentlig sektor lever idag i två separata världar: **inflödet** (orosanmälningar, SDK-meddelanden, säker e-post, fax, remisser) och **bevakningen** (vad måste jag göra och *när*). Inflödet hanteras i meddelandeklienter; bevakningen hanteras i verksamhetssystemets fristlistor, i kalendern, på post-it-lappar och i huvudet. Hubs unika möjlighet är att **brygga dessa två** — att göra varje inkommande säkert meddelande till en spårbar uppgift med deadline, ansvarig och status, och att samla allt i en aggregerad "Mina uppgifter / Bevakningar"-vy.

Tre fynd styr designen:

1. **Deadline-drivna massflöden finns redan och är lagstadgade.** Det tydligaste är överförmyndarens **årsräkningar som ska vara inne före 1 mars** varje år (14 kap. 15 § föräldrabalken) — ett kalenderlåst flöde med tusentals ärenden per enhet. Liknande tidslås finns i socialtjänstens **uppföljning av tidsbegränsade beslut "i god tid innan beslutet upphör"** och i **förvaltningslagens dröjsmålsregler** (6-månaders­gräns + 4 veckor att avgöra efter begäran, 11–12 §§ FL 2017:900). Detta är inte "trevligt att ha" — det är rättssäkerhet med datum.

2. **Det dominerande UX-mönstret för myndighetsuppgifter är GOV.UK:s task-list**, med en *minimal* uppsättning statusar (börja med "Klar" / "Ej påbörjad", lägg bara till fler när användarforskning kräver det), verb-inledda uppgiftsnamn, tematisk gruppering och möjlighet att arbeta i valfri ordning över flera sessioner. Det är direkt återanvändbart för en "Mina uppgifter"-widget.

3. **Den tekniska grunden finns redan i plattformen** (kanban-modulen Deck med kort, listor, etiketter, deadlines, tilldelning, kommentarer, kort↔kort-relationer och CalDAV-spegling av deadlines till kalendern; plus en VTODO/uppgifts-app). Men ingen av dem är *task-orienterad mot handläggning* eller kopplar todo↔meddelande↔ärende. Det är gapet Hubs ska fylla — inte genom att bygga ett nytt kanban-verktyg, utan genom en **rollstyrd bevaknings-widget** ovanpå befintliga byggblock.

Rekommendationen: bygg en **"Mina uppgifter / Bevakningar"-widget** som förstaklassobjekt i dashboarden, med GOV.UK-statusmodell, deadlinesortering med eskalering, och en **"skapa bevakning från meddelande"-åtgärd** som är limmet mellan SDK-/e-post-inkorgen och uppgiftslistan. Differentiera på *kopplingen* (meddelande → uppgift → ärende → påminnelse), inte på kanban-funktioner i sig.

---

## Marknad & aktörer

### Plattformens egna byggblock (det Hubs redan kan bygga på)

- **Kanban-modulen (Deck).** Mogen kanban med boards → listor (stacks) → kort, etiketter, **deadlines (due dates)**, **tilldelning till en eller flera medlemmar**, kommentarer, bilagor, aktivitetsström, **kort↔kort-relationer** och **delning till team/Circles**. Deadlines **speglas automatiskt in i användarens kalender** (CalDAV), och tilldelade kort dyker upp i aktivitetsflödet. Egna dashboard-widgets för "kommande kort" finns. Kända begränsningar som påverkar Hubs: **separat påminnelse före deadline** (ett "reminder" utöver själva due date) saknas i kärnan och är en öppen feature-request (#1549); aviseringar går historiskt till alla boardmedlemmar snarare än bara tilldelad person (#566). Detta är precis den lucka Hubs bevaknings-widget ska täcka. (Källa: deck.readthedocs.io; GitHub nextcloud/deck issues #1549, #566, #1560.)
- **Uppgifts-/VTODO-app.** Separat att-göra-app som synkas via CalDAV (VTODO): titel, beskrivning, start- och **förfallodatum**, **påminnelsetider**, prioritet, kommentarer. Synkar mot Apple Reminders, OpenTasks, DAVx5, Thunderbird m.fl. Detta ger den "personliga lista"-halvan; Deck ger den "delade board"-halvan. (Källa: github.com/nextcloud/tasks; docs.nextcloud.com Calendar/CalDAV.)
- **Dashboard-widget-API.** Widgets registreras via appen; `IConditionalWidget` ger gratis rollstyrning (visa SDK-/bevaknings-widget bara för registrator/handläggare), `IButtonWidget` ger NEW/MORE-knappar, `IReloadableWidget` ger periodisk uppdatering. Standard-dashboarden är dock en passiv widget-grid utan statusar eller triage — Hubs egen task-orienterade logik måste byggas ovanpå.

### Vertikala konkurrenter & angränsande system (svensk offentlig sektor)

- **Provisum (Sambruk).** De facto-verksamhetssystemet för **överförmyndarförvaltningen**. Stödjer **granskning av årsräkningar**, handläggar- och chefsstöd, statistik och **bevakning**, samt en **webbtjänst där ställföreträdare lämnar in** uppgifter. Har funktion för att slumpa ut en angiven **procentandel årsräkningar för fördjupad granskning**. Visar att deadline-driven uppgiftshantering kring 1 mars redan är ett moget, betalt segment — men *inlåst i ett vertikalt facksystem*, inte en generell arbetsyta. (Källa: provisum.se; sambruk.se/overformyndare-provisum.)
- **Aider.** Digitaliserar redovisning ställföreträdare↔handläggare; lanserade **2025 "Aider Bevakning" (Surveillance)** som en fristående webbtjänst som "installeras på minuter" och tar emot digitala redovisningar. Bekräftar att "bevakning" som produktbegrepp säljs separat. (Källa: support.aider.nu.)
- **Verksamhetssystem för socialtjänst/myndighetsutövning** (t.ex. Evolution/Combine/Viva/Lifecare-familjen). Här bor de formella ärendena, fristerna och besluten. Härjedalens riktlinjer beskriver att **ärenden som inte registreras i andra system ska registreras i ärendehanteringssystemet** och att **handläggaren får en påminnelse via e-post om ett ärende blir fördröjt** — dvs. fristbevakning finns redan i facksystemen, men som mejlnotiser, inte som en samlad arbetsyta. Hubs konkurrerar **inte** med själva besluts-/journalsystemet; Hubs äger *flödet runt omkring* (inkommande meddelanden, det som inte ryms i facksystemet, den personliga/delade att-göra-vyn). (Källa: herjedalen.se riktlinjer för ärendehantering.)
- **SKR:s "digitala bastjänster för socialtjänsten" / digital basnivå.** SKR har pekat ut **12 digitala tjänster som varje kommun behöver ha** och driver en **lärprocess med checklista och vägledning** (även som digitalt självskattningsverktyg där ledningen kan "checka av sitt läge, jämföra med andra kommuner och följa över tid"). Detta är i sig ett task-list-mönster på organisationsnivå och en inköpsdrivare: kommuner ska kunna *visa* att rutiner finns. (Källa: skr.se digitalabastjansterforsocialtjansten; extra.skr.se checklista/självskattning.)
- **Generiska samarbets-/projektverktyg** (Trello, Microsoft Planner/To Do/Loop, Asana, Jira). Används informellt i kommuner men träffar **inte** sekretess­kraven för känsliga uppgifter (samma OSL-/molnproblematik som Teams/Outlook). Hubs differentierar genom att uppgiften kan bära en koppling till ett sekretessbelagt meddelande **utan att lämna driftmiljön**.

### Adoption & timing

SDK passerade 100 anslutna organisationer (aug 2025), Region Stockholm anslöt sep 2025, och 2026 pekas ut som året då SDK blir standardrutin. Det betyder att **inflödet av spårbara, kvittenskrävande meddelanden ökar kraftigt just nu** — och varje sådant meddelande är en latent uppgift med svarsfrist. Marknaden för "vad gör jag med allt detta inflöde, och hur missar jag inget?" öppnar samtidigt som inflödet materialiseras.

---

## Juridik & krav

Uppgifts-/bevakningsfunktioner i offentlig handläggning är inte fri UX — de styrs av frist- och dokumentationskrav. Relevanta krav:

- **Förvaltningslagen (2017:900).** 9 § kräver att ärenden handläggs **"så enkelt, snabbt och kostnadseffektivt som möjligt utan att rättssäkerheten eftersätts"**. 11 § kräver att parten **underrättas om avgörandet blir väsentligt försenat**. 12 § ger parten rätt att efter **sex månader** skriftligen begära avgörande, varpå myndigheten inom **fyra veckor** måste avgöra ärendet eller i särskilt beslut avslå begäran. → En bevaknings-widget bör kunna **rösa upp ärenden mot 6-månadersgränsen** och flagga "underrättelse om dröjsmål skickad?". (Källa: riksdagen.se SFS 2017:900; JP Infonet.)
- **Föräldrabalken 14 kap. 15 §.** Gode män/förvaltare ska lämna **årsräkning till överförmyndaren före 1 mars** för föregående års förvaltning. → Det kanoniska deadline-drivna massflödet; en "deadline-kampanj"-vy (alla årsräkningar, status inkommen/granskad/komplettering) är direkt motiverad. (Källa: provisum.se; lagen.nu/sou.)
- **Socialtjänstlagen + Socialstyrelsens stöd.** Vid **tidsbegränsade beslut** ska handläggaren **följa upp behovet i god tid innan beslutet upphör** och nämnden fatta nytt beslut innan det tidigare upphör; tidsbegränsning får inte ske rutinmässigt och ska motiveras. Ny socialtjänstlag (i kraft 1 juli 2025) skärper kraven på uppföljning och "lätt tillgänglig" socialtjänst. → Motiverar **bevakningar på beslutens slutdatum**, inte bara på inkommande post. (Källa: Kunskapsguiden "Besluta"; Socialstyrelsen nya SoL.)
- **OSL (2009:400) + tystnadspliktslagen (2020:914) + OSL 10:2a §.** Sekretessreglerade uppgifter får inte röjas i öppna kanaler; utkontrakterad teknisk lagring tillåts efter lämplighetsbedömning (eSam ES2023-06). → En uppgift/kort som *citerar* eller *länkar* ett sekretessbelagt meddelande blir själv en sekretesskänslig handling och måste ligga i kundens driftmiljö med åtkomststyrning — argument mot Trello/Planner.
- **HSLF-FS 2016:40.** Kräver kryptering så att bara avsedd mottagare kan läsa, samt **stark autentisering** vid elektronisk åtkomst (för HSL-området). → Bevaknings-widgeten ärver kravet på LOA3-inloggning (BankID/Freja/SITHS).
- **GDPR.** Uppgifts-/påminnelsedata (vem ska göra vad om vilken klient/medborgare) är personuppgiftsbehandling; **dataminimering** talar för att korttext default ska vara ärendereferens, inte klartextcitat av känsliga uppgifter. **Gallring/lagringsminimering** av avklarade bevakningar bör kunna styras.
- **Arkivlagen (1990:782) + arkivlagen-relaterad praxis.** En bevakning/uppgift kan utgöra en **allmän handling** om den tillförts ärendet. Hubs bör tydligt skilja på (a) personliga, gallringsbara att-göra-noteringar och (b) handlingar som hör till ärendet och ska bevaras/diarieföras i facksystem/diarium. Felaktig hopblandning skapar arkiv- och offentlighetsproblem.
- **DOS-lagen (2018:1937) + EN 301 549 / WCAG.** Bygg mot **WCAG 2.2 AA** redan nu (EN 301 549 v4.1.1 väntas 2026). Direkt relevanta kriterier för en uppgifts-widget: **Target Size 24×24 px** (status-/kryssrutor, "klarmarkera"-knappar), **Dragging Movements** (drag-and-drop av kort på en kanban-board måste ha ett tangentbords-/knappalternativ — kärn-kanban klarar inte detta), **Focus Not Obscured** (fokuserad uppgiftsrad får inte döljas av sticky filterpanel) och **Consistent Help**. (Källa: digg.se webbriktlinjer; design-system.service.gov.uk.)
- **NIS2 / cybersäkerhetslagen (2025:1506, i kraft 15 jan 2026).** Ledningen har personligt ansvar för systematiskt arbete. → En bevaknings-widget som visar "obesvarade säkra meddelanden över X dagar" och "ej kvitterade leveranser" blir indirekt ett internkontroll-/rättssäkerhetsinstrument som stödjer ledningsansvaret.

---

## Funktioner att bygga

Designprincip genomgående: **aggregera, addera inte** (Arbetsmiljöverkets/Suntarbetslivs fynd om systemträngsel), och **mät tid-till-åtgärd, inte tid-på-dashboarden**.

### 1. "Mina uppgifter / Bevakningar"-widget (kärnan)

En aggregerad lista byggd på GOV.UK task-list-mönstret, default-sorterad på **deadline stigande** med tydlig eskaleringsfärgning.

- **Statusmodell (börja minimalt, GOV.UK-stil):** `Ny` · `Påbörjad` · `Väntar på motpart` · `Klar`, plus ett rött `Åtgärd krävs` (försenad/problem). Lägg inte till fler statusar förrän användarforskning kräver det.
- **Deadline-eskalering:** grå (>14 dgr kvar) → normal → gul (≤3 dgr) → röd (förfallen). Räknare överst: *"Du har 4 bevakningar som förfaller denna vecka."*
- **Verb-inledda titlar** ("Granska årsräkning – Andersson", "Besvara SDK-meddelande från Region X", "Följ upp beslut – insats upphör 30/6").
- **Källikon per rad** som visar varifrån uppgiften kom: SDK / säker e-post / fax / möte / manuell. Det är limmet mot inkorgen.
- **Card View + Quick View** (Viva-mönstret): klarmarkera direkt i raden; expandera för detalj utan sidbyte.
- **Personlig vs delad togglad i samma widget:** "Mina" (tilldelade mig) / "Enhetens" (delad funktionsbevakning, t.ex. funktionsadressens obesvarade meddelanden).
- *Persona som vinner:* **alla handläggarroller**, men starkast **socialsekreteraren** (dokumentationsbördan, risk att journalföring/svar släpar) och **biståndshandläggaren** (uppföljning av tidsbegränsade beslut).

### 2. "Skapa bevakning från meddelande" (todo ↔ meddelande ↔ ärende)

Den enskilt viktigaste differentieraren. På varje inkommande SDK-meddelande/säker e-post/fax: en knapp **"Skapa bevakning"** som förifyller titel (avsändare + ämne), länkar tillbaka till meddelandet, och föreslår deadline (t.ex. svarsfrist). Uppgiften kan kopplas till en **ärendereferens** (diarienummer/personnummer-token) så att flera meddelanden och uppgifter hänger ihop under ett ärende.

- Stäng-loopen: när uppgiften klarmarkeras kan den visa "svar skickat 12/6, kvittens mottagen" — den känslomässiga ersättningen för "ringa och kolla att faxen kom fram".
- *Persona som vinner:* **registrator/funktionsbrevlåde-ägare** (fördela inkommande till rätt handläggare), **kommunsjuksköterskan** (bevaka svar från regionen).

### 3. Deadline-kampanjvy ("Årsräkningar 2026")

En särskild vy för **kalenderlåsta massflöden**: alla ärenden av en typ med gemensam deadline, som en GOV.UK-task-list med per-ärende-status (Inkommen / Under granskning / Komplettering begärd / Klar) och en aggregerad progress-räknare *"312 av 540 årsräkningar granskade · 18 dagar till 1 mars"*.

- *Persona som vinner:* **överförmyndarhandläggaren** (1 mars), men mönstret återanvänds för alla deadline-toppar (t.ex. omprövningsvågor, ansökningsperioder).

### 4. Påminnelser & bevakning *före* deadline

Eftersom kärn-kanban saknar separat "reminder före due date" bygger Hubs detta: konfigurerbara påminnelser (t.ex. T-7 / T-3 / T-0 dagar) som ger **avisering bara till tilldelad person** (löser issue #566/#1549) via dashboardnotis + valfri säker kanal. Visa kommande deadlines som en liten "denna vecka"-strip överst i dashboarden.

### 5. Delad funktionskö ("vem tar detta?")

För funktionsadresser (SKR:s 2025-rekommendation): en delad vy där inkommande visas som otilldelade kort, och en handläggare **tar ansվar** ("plocka") med ett klick. Oläst/otilldelad-status synlig för hela enheten → inget faller mellan stolarna (compliance-värde, inte bara bekvämlighet).
- *Persona som vinner:* **registrator** och **enhetschef** (överblick + lastbalansering).

### 6. Ctrl+K-åtgärder för uppgifter

I command palette: "Skapa bevakning", "Visa mina förfallna", "Tilldela [ärende] till [kollega]". Skalar från nybörjare till daglig expertanvändare (registratorer).

### 7. Arkiv-/gallringsmedvetenhet

Vid klarmarkering: val mellan "gallra (personlig notering)" och "för till ärendet/diariet" — håller isär arkivpliktiga handlingar från privata att-göra-lappar (arkivlagen/OSL).

---

## Rekommendation för Hubs

1. **Bygg en task-orienterad bevaknings-widget, inte ännu ett kanban-verktyg.** Återanvänd de befintliga byggblocken (kanban-modulens kort/deadlines/tilldelning/CalDAV-spegling + VTODO-uppgifter) som *datalager*, men exponera dem genom en egen, rollstyrd **"Mina uppgifter / Bevakningar"-widget** med GOV.UK-statusmodellen. Värdet ligger i aggregering, status och "nästa åtgärd" — inte i fler kanban-funktioner.

2. **Gör "Skapa bevakning från meddelande" till signaturfunktionen.** Kopplingen meddelande → uppgift → ärende → påminnelse är det ingen vertikal konkurrent (Provisum/Aider) eller generiskt verktyg (Planner/Trello) erbjuder *inom samma sekretessäkra driftmiljö*. Det är där Hubs slår både facksystemen (som inte äger inflödet) och samarbetsverktygen (som inte klarar OSL).

3. **Lansera deadline-kampanjvyn med 1 mars-årsräkningar som flaggskeppsdemo.** Det är ett konkret, lagstadgat, kalenderlåst flöde med hög volym och en tydlig persona (överförmyndare) — perfekt upphandlingsdemo som visar hela kedjan inflöde→bevakning→påminnelse→klar. Generalisera sedan mönstret till socialtjänstens uppföljning av tidsbegränsade beslut och förvaltningslagens 6-månadersgräns.

4. **Bygg påminnelser-före-deadline och tilldelad-person-aviseringar själva** — det täcker en känd lucka i kanban-kärnan (#1549/#566) och är billig, hög-synlig nytta.

5. **Kravställ WCAG 2.2 AA från start**, särskilt tangentbordsalternativ till drag-and-drop, 24×24 px klickytor på status-/klarknappar och fokus som inte döljs av filterpaneler — annars klarar inte kanban-baserad UI DOS-lagen, och tillgänglighet är ett upphandlingskriterium.

6. **Håll isär personliga gallringsbara noteringar från ärendebundna allmänna handlingar** (arkivlagen/OSL) i datamodellen från dag ett — det är svårt att retrofitta och är en fråga kommunjurister kommer ställa.

7. **Positionera bevakningen som internkontroll/rättssäkerhet mot ledningen** (förvaltningslagens dröjsmålsregler + NIS2-ledningsansvar): "ingen obesvarad säker post över X dagar, full spårbarhet" är ett säljargument mot förvaltningschef/CISO, inte bara mot handläggaren.

---

## Källor

- GOV.UK Design System – Task list component: https://design-system.service.gov.uk/components/task-list/
- GOV.UK Design System – Complete multiple tasks (statusar, sessioner): https://design-system.service.gov.uk/patterns/complete-multiple-tasks/
- Nextcloud Deck – dokumentation (funktioner): https://deck.readthedocs.io/
- Nextcloud Deck – GitHub repo: https://github.com/nextcloud/deck
- Deck issue #1549 – separat påminnelse utöver deadline: https://github.com/nextcloud/deck/issues/1549
- Deck issue #566 – aviseringar bara till tilldelad användare: https://github.com/nextcloud/deck/issues/566
- Deck issue #1560 – deadline ska synas i kalendern: https://github.com/nextcloud/deck/issues/1560
- Guide to interactive widgets in Nextcloud Hub: https://nextcloud.com/blog/guide-to-interactive-widgets-in-nextcloud-hub/
- Nextcloud Tasks (VTODO/CalDAV) – GitHub: https://github.com/nextcloud/tasks
- Nextcloud Calendar/CalDAV admin-manual: https://docs.nextcloud.com/server/stable/admin_manual/groupware/calendar.html
- Provisum – verksamhetssystem för överförmyndarförvaltningen: https://www.provisum.se/
- Provisum via Sambruk (årsräkning, bevakning, granskning): https://sambruk.se/overformyndare-provisum/
- Aider – Överförmyndare och Aider (Bevakning 2025): https://support.aider.nu/sv/articles/6884612-overformyndare-och-aider
- Förvaltningslag (2017:900) – riksdagen: https://www.riksdagen.se/sv/dokument-och-lagar/dokument/svensk-forfattningssamling/forvaltningslag-2017900_sfs-2017-900/
- JP Infonet – 11 och 12 §§ förvaltningslagen (dröjsmål/frister): https://www.jpinfonet.se/kunskap/nyheter4/2023/september/uppfyller-11-och-12--forvaltningslagen-lagstiftarens-syfte/
- Kunskapsguiden – Besluta (tidsbegränsade beslut, uppföljning): https://kunskapsguiden.se/omraden-och-teman/barn-och-unga/handlaggning-och-dokumentation-med-barnet-i-centrum/besluta/
- Socialstyrelsen – vad är nytt i nya socialtjänstlagen: https://www.socialstyrelsen.se/kunskapsstod-och-regler/omraden/en-socialtjanst-i-forandring/vad-ar-egentligen-nytt-i-nya-socialtjanstlagen/
- Härjedalens kommun – riktlinjer för ärendehantering (påminnelse vid fördröjning): https://www.herjedalen.se/kommun-och-politik/styrdokument-och-regler/interna-styrdokument/riktlinjer-for-arendehantering.html
- SKR – Digitala bastjänster för socialtjänsten (12 tjänster): https://skr.se/digitaliseringivalfarden/digitaliseringsocialtjansten/digitalabastjansterforsocialtjansten.9033.html
- SKR – Digital basnivå socialtjänst: https://skr.se/skr/integrationsocialomsorg/socialomsorg/digitaliseringinomsocialtjansten/verktygochutvecklingsarbeten/digitalbasnivasocialtjanst.81535.html
- SKR – Digital checklista för självskattning (Framtidens socialtjänst): https://extra.skr.se/framtidenssocialtjanst/larprocessframtidenssociatjanst/checklistaochvagledning/digitalchecklistaforsjalvskattning.94612.html
- Digg – EN 301 549 och WCAG (DOS-lagen): https://www.digg.se/webbriktlinjer/lagar-och-krav/det-har-ar-en-301-549-och-wcag
- eSam – ES2023-06 Utkontraktering, sekretess och dataskydd: https://www.esamverka.se/download/18.43a3add4188b9f2345a2fe78/1687332814480/ES2023-06%20V%C3%A4gledning%20Utkontraktering%20-%20sekretess%20och%20dataskydd.pdf
- Microsoft Viva Connections – dashboard cards (Card View/Quick View, referensmodell): https://learn.microsoft.com/en-us/viva/connections/available-dashboard-cards
