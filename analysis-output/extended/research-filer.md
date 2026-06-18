# Säkra filer & dokumentsamarbete i offentlig sektor — research för Hubs

*Underlag för Hubs (ITSL): säker fil- och dokumentyta ovanpå Nextcloud-basen, sålt mot svenska kommuner och regioner. Brand-regel: i produktnära text säger vi aldrig "Nextcloud" eller "Talk" — vi säger "Hubs Filer", "säker dokumentyta", "säkert möte". Den tekniska basen namnges bara i interna underlag som detta.*

## Sammanfattning

Säker fillagring och dokumentsamarbete är den mest mogna och mest konkurrensutsatta delen av Hubs-erbjudandet — men också den där Hubs har en strukturell fördel som ingen ren molnkonkurrent kan matcha: **filer och samredigering på kundens egna järn, utan att ett enda dokument lämnar driftmiljön till en tredje part.** Det löser i grunden OSL-röjandefrågan (10 kap. 2 a § OSL + eSam ES2023-06) på samma sätt som för meddelanden och video, och eliminerar CLOUD Act-exponeringen som plågar Microsoft 365 / SharePoint / OneDrive och de svenska "compliant cloud"-aktörerna.

De viktigaste slutsatserna:

- **Den tekniska grunden finns redan i basplattformen och är produktionsmogen.** Funktionsmappar (Groupfolders / Team folders) med avancerad behörighet (ACL per fil/mapp: Read/Write/Create/Delete/Share, allow/deny), automatisk versionshantering, Files Retention-app för regelstyrd gallring via taggar, system-/samarbetstaggar, och on-prem kontorssvit (Collabora eller OnlyOffice via WOPI) som renderar dokument i kundens egen miljö. Hubs behöver inte bygga fillagret — Hubs behöver bygga **arbetsytan och rättssäkerhetslagret ovanpå det**, plus två dashboard-widgetar: "Senaste säkra filer" och "Ärenderum".
- **Den svenska konkurrensen på säker fildelning är reell men smal.** Storegate är godkänd av eSam (en av två leverantörer för säker lagring/fildelning för kommuner/regioner), kör i svenska datacenter, har BankID/Freja-delning och -signering, versionshantering, automatisk gallring och LoA3-inloggning — men är SaaS hos leverantören, inte på kundens server. SecureAppbox, Sefos och TDialog har filhantering men som bilaga till meddelanden, inte som fullvärdig dokumentyta. Microsoft 365 är de facto-standarden som underkänns för sekretess.
- **Juridiken pekar åt Hubs fördel men kräver konkret stöd i produkten.** Utöver OSL/GDPR styr **arkivlagen (1990:782)** med bevarande som huvudregel och gallring bara enligt kommunens egna gallringsbeslut; **arkivförordningen uppdaterades 1 aug 2024** med krav på att data ska kunna exporteras och raderas *innan* upphandling/införande av informationssystem — ett krav Hubs kan möta direkt med export i FGS-format och Retention-styrd gallring. **HSLF-FS 2016:40** (kryptering + stark autentisering) gäller för vård-/omsorgshandlingar. **DOS-lagen + EN 301 549 / WCAG** gäller medborgarriktade delningsgränssnitt.
- **Den vinnande produktidén är "ärenderum / dokumentyta per dnr".** En funktionsmapp per ärende (diariefört eller ej), med rätt personer inne, versionshistorik, gallringsregel kopplad till handlingstyp, säker delning med medborgaren via BankID-länk, och allt synligt och spårbart i en dashboard-widget. Detta är den naturliga bryggan mellan Hubs säkra meddelanden (SDK/säker e-post) och Hubs säkra video — bilagorna och planerna *bor* i ärenderummet, kommunikationen *refererar* till det.
- **Collectives blir kunskapsbanken.** En lågtröskel-wiki (sidor/undersidor, full-textsök, markdown i Files, åtkomst via team) för rutiner, mallar, gallringsplaner och lathundar — det "interna 1177-stödet" för handläggaren, helt on-prem.

## Marknad & aktörer

### Reella produkter och leverantörer

**Microsoft 365 (SharePoint Online, OneDrive, Teams-filer, Office co-authoring)** — de facto-standarden för dokumentsamarbete i svensk offentlig sektor och den kategorin Hubs positionerar sig mot. Underkänns för sekretessbelagd information av två skäl som återkommer i alla underlag: Schrems II-tredjelandsöverföring och OSL-röjande till extern driftleverantör (SOU 2021:1, eSam ES2023-06). IMY har avrått från Microsofts molntjänster för känsliga uppgifter. Microsofts motdrag är "sovereign cloud"-budskapet i Sverige (maj 2026) — men det adresserar datacenterplacering, inte ägarskap/jurisdiktion/öppen kod. Kommuner kör i praktiken tvåspår: M365 för vardagsdokument, en separat säker lösning för sekretess. Hubs ska vinna det andra spåret, inte hela M365.

**Storegate** (svenskt, Karlskrona) — den närmaste rena konkurrenten på *säker fildelning*. Godkänd av eSam som en av två leverantörer för säker lagring och fildelning i molnet för kommuner/regioner. All data i svenska datacenter under svensk lag; uttalat att CLOUD Act inte kan tvinga fram utlämnande. Funktioner som direkt överlappar Hubs: publika länkar med kontroller (lösenord, BankID, Freja, utgångsdatum), digital signering enligt eIDAS, versionshantering, automatisk gallring/radering, papperskorg, händelselogg, inloggning på LoA3 + EntraID, produktlinjen "SOSA" mot offentlig sektor. **Svaghet mot Hubs:** SaaS hos Storegate — kunden äger inte driftmiljön, och det är inte en samlad arbetsyta över meddelanden/video/ärende, bara fillagring + delning + signering.

**SecureAppbox** (svenskt) — SecureMailbox/SharedMailbox + GDPR e-Fax; filhantering finns men som bilaga i säkra meddelanden, inte som dokumentyta. Kvalificerad i Adda DIS "Säker digital kommunikation".

**Sefos (Meaplus)** och **TDialog (Compodium)** — säkra meddelanden med fil-/bilagehantering och e-underskrift (TDialog via PhenixID); funktionsbredd men filerna lever i meddelandekontexten, inte i en delad mapp-/ärendestruktur. Båda kvalificerade i Adda DIS.

**Kontorssviterna (samredigering):**
- **Collabora Online (Nextcloud Office)** — LibreOffice-baserad, levereras integrerad med basplattformen, körs som egen dokumentserver via WOPI i kundens miljö. Standardvalet för en helt on-prem-stack. Stöd för realtidssamredigering, spårade ändringar, kommentarer.
- **OnlyOffice Docs** — separat dokumentserver via WOPI/connector, ofta upplevd som mer prestanda- och MS-formatstrogen (docx/xlsx/pptx). Kan också köras helt on-prem. Kommuner som har mycket Office-format föredrar ofta OnlyOffice för formattrohet.
- Båda renderar och samredigerar **utan att dokumentet lämnar kundens infrastruktur** — det är hela poängen jämfört med Office for the web i M365. För Hubs är valet konfigurerbart per kund; OnlyOffice som default vid tung MS-formatmigrering, Collabora som default för ren OSS-stack.

**E-arkiv / slutarkiv (angränsande, inte konkurrent):**
- **Sydarkivera** — kommunalförbund och Sveriges första gemensamma arkivorganisation (bildat 2015), driver gemensamt e-arkiv för medlemskommuner och driver FGS-arbetet hårt. Tar emot arkivleveranser i **FGS (Förvaltningsgemensamma specifikationer)** — paketstruktur, ärendehantering, m.m. Hubs konkurrerar *inte* med slutarkivet; Hubs är **mellanarkivet/aktiva lagret** vars uppgift är att kunna **exportera till FGS** när handlingar ska levereras vidare. Att tala FGS är ett upphandlingskrav i Sydarkivera-kommuner.
- **Riksarkivet** — äger FGS (`riksarkivet.se/fgs-earkiv`), återupptog FGS-arbetet; utfärdar föreskrifter (RA-FS) för statliga myndigheter och *allmänna råd* för kommunsektorn (Riksarkivet utfärdar inte gallringsföreskrifter för kommuner — det gör varje kommun själv).
- **Tietoevry, Ida Infront (iipax), Sambruk m.fl.** — ärendehanterings- och e-arkivsystem. Hubs lever *bredvid* ärendehanteringssystemet (W3D3, Ciceron, Public360, Lifecare, Treserva, ProCapita osv.), inte istället för det — Hubs tar de sekretessflöden och dokumentytor som inte ryms eller inte ska ligga i diariet.

### Svensk offentlig adoption av Nextcloud-basen

- Svenska driftpartner marknadsför aktivt Nextcloud som "GDPR-säker lagring inom EU/Sverige" mot offentlig verksamhet (WebbPlatsen, Projalpha, Software in the Cloud m.fl.) — bevis på att basplattformen redan säljs in i svensk offentlig sektor som M365-alternativ för filer.
- Europeisk offentlig sektor skalar kraftigt: ~2 miljoner nya "sovereign workspace"-platser 2025; Schleswig-Holstein (25 000 anställda) ersätter Microsoft med LibreOffice + Nextcloud; Frankrikes La Suite numérique och Tysklands openDesk använder Nextcloud som fillager bakom egna portaler; Île-de-France 550 000 användare. Sverige saknar en nationell motsvarighet — utrymmet Hubs siktar på.

## Juridik & krav

**OSL (Offentlighets- och sekretesslagen 2009:400) + tystnadsplikt.** Grundbulten: sekretessreglerade uppgifter får inte röjas. Att lägga en sekretesshandling i en molntjänst hos extern leverantör är i sig ett "röjande" (SOU 2021:1). Sedan 1 juli 2023 finns sekretessbrytande 10 kap. 2 a § OSL för utlämnande till leverantör för *endast teknisk bearbetning/lagring* "om det inte är olämpligt" — men det kräver lämplighetsbedömning i varje fall (eSam ES2023-06). **Hubs on-prem-modell gör hela den bedömningen överflödig: ingen extern part får informationen.** Detta är det starkaste juridiska argumentet och ska vara synligt i dokumentytan ("all data på er server / i er driftmiljö").

**GDPR + tredjelandsöverföring.** On-prem i Sverige = ingen tredjelandsöverföring, ingen DPF-skörhet ("Schrems III"-risk), ingen CLOUD Act. Storegate gör samma poäng från svenskt datacenter; Hubs går steget längre med kundens egen server.

**Arkivlagen (1990:782) och arkivförordningen (1991:446).**
- Huvudregel: **allmänna handlingar ska bevaras**; gallring (förstöring) får ske men kräver gallringsbeslut. För kommuner beslutar **kommunen själv** om gallring (Riksarkivet utfärdar bara allmänna råd för kommunsektorn, t.ex. "Bevara eller gallra"-serien framtagen med SKR). Det betyder att Hubs gallringsfunktion måste vara **konfigurerbar per handlingstyp enligt kommunens egen dokumenthanteringsplan** — inte en hårdkodad retention.
- **Arkivförordningen uppdaterades 1 augusti 2024** (baserat på arkivutredningen *Härifrån till evigheten*, SOU 2019:58): myndigheter ska säkerställa att information i arkivbildande informationssystem **kan exporteras och raderas innan upphandling/införande**. Riksarkivet fick utökat mandat (samråd, förelägganden för statliga myndigheter). **Direkt relevant för Hubs:** en kund kan inte upphandla ett system som inte klarar export + radering — Hubs ska kunna demonstrera FGS-export och Retention-styrd radering som standardfunktion. Detta är ett *upphandlingskrav*, inte bara en nice-to-have.
- En större *ny arkivlag* (efter SOU 2019:58 / "Härifrån till evigheten") har remissbehandlats men ännu inte trätt i kraft som helhet (per juni 2026 är det förordningsändringarna från 2024 som gäller). Bevaka detta — men bygg redan nu mot FGS + konfigurerbar gallring, vilket täcker båda utfallen.

**FGS (Förvaltningsgemensamma specifikationer), Riksarkivet/Sydarkivera.** Gemensamma utbytesformat för leverans mellan verksamhetssystem, till e-arkiv och mellan organisationer (FGS Paketstruktur, FGS Ärendehantering). Hubs export-/leveransfunktion bör tala FGS för att passa Sydarkivera-kommuner och e-arkivkrav.

**HSLF-FS 2016:40 (Socialstyrelsen).** För hälso- och sjukvård/omsorg: elektroniska meddelanden med personuppgifter ska krypteras så att endast avsedd mottagare kan läsa dem, och stark autentisering (MFA) krävs vid elektronisk åtkomst. Träffar Hubs när dokumentytan används för vård-/omsorgshandlingar (kommunal HSL, hemtjänst, SIP-dokument). Kraven kan inte avtalas bort med den enskildes samtycke. Hubs ska visa att kryptering + LoA3-inloggning uppfylls.

**eIDAS / eIDAS2 (EU 2024/1183) + Diggs tillitsramverk (LOA).** Inloggning och e-underskrift: LoA3 (BankID, Freja eID Plus, SITHS för vård) som golv. Säker delning till medborgare ska ske med BankID/Freja-verifiering, inte enbart länk/lösenord. e-Underskrift på dokument bör följa eIDAS (avancerad/kvalificerad signatur). Arkitekturen ska förberedas för statlig e-legitimation (nov 2026) och EUDI-plånbok (2026/27–2028). "eIDAS2-redo" är ett differentierande upphandlingsbudskap redan 2026.

**Cybersäkerhetslagen (2025:1506) / NIS2 (i kraft 15 jan 2026).** Alla kommuner och regioner omfattas. Loggning, spårbarhet, incidenthantering och åtkomstkontroll på dokument blir lagkrav, med personligt ledningsansvar. Funktionsmappar med ACL + fullständig händelselogg + versionshistorik är konkret NIS2-stöd. Öronmärkta statsbidrag (200 mkr/år kommuner, 50 mkr/år regioner från 2026) ska omsättas i sådana åtgärder.

**DOS-lagen (2018:1937) + EN 301 549 / WCAG.** De **medborgarriktade** delningsgränssnitten (där medborgaren loggar in med BankID och hämtar/laddar upp dokument) lyder under DOS-lagen. Bygg mot **WCAG 2.2 AA** redan nu (EN 301 549 v4.1.1 väntas 2026): klickytor ≥24×24 px, fokus aldrig dolt av sticky-paneler, alternativ till drag-and-drop, hjälp på konsekvent plats, inloggning utan kognitiva test. Tillgänglighet är dessutom ett tilldelningskriterium i upphandling.

## Funktioner att bygga

### Kärnkoncept: "Ärenderum" (dokumentyta per dnr/ärende)

En **funktionsmapp (Groupfolder/Team folder) per ärende** är den bärande idén. Tekniskt finns allt: Groupfolders med avancerad ACL (Read/Write/Create/Delete/Share per fil/mapp, allow/deny, per användare/grupp/team), versionshantering, taggar, Retention-app, papperskorg, händelselogg.

Hubs lägger ovanpå:
- **Ärenderum skapas från ärendet, inte från filsystemet.** En registrator/handläggare väljer "Skapa ärenderum", anger dnr (eller får ett genererat ID om ärendet inte är diariefört), handlingstyp och deltagare. Hubs skapar funktionsmappen, sätter ACL, applicerar rätt **gallringsregel via restricted/invisible-tagg** (Retention-appen kräver icke-borttagbar tagg för att gallringen ska hålla) enligt kommunens dokumenthanteringsplan, och kopplar rummet till ärendet.
- **Rätt personer, rätt rättigheter.** Handläggaren skriver, kollegor läser, medborgaren får en avgränsad, BankID-skyddad delning av *utvalda* dokument (aldrig hela rummet). ACL "least permission + tilläggsregel" är exakt Groupfolders-modellen.
- **Samredigering on-prem** (Collabora/OnlyOffice) direkt i rummet — planer, utredningar, beslut samredigeras utan att lämna driftmiljön.
- **Versionshistorik + spårbarhet** per dokument (NIS2/arkiv). "Vem ändrade vad när", återställning av tidigare version.
- **Gallringsstatus synlig i rummet:** "Gallras 2031-12-31 enligt handlingstyp X" eller "Bevaras". Innan automatisk radering: notis till ägaren dagen innan (Retention-funktion).
- **FGS-export:** "Leverera till e-arkiv" paketerar rummet enligt FGS för Sydarkivera/e-arkiv.
- **Brygga till kommunikation:** ärenderummet är dit Hubs säkra meddelanden (SDK/säker e-post) och säkra möten *refererar* — bilagor, mötesunderlag och SIP-planer bor i rummet; meddelandet länkar dit i stället för att skicka kopior.

*Persona-nytta:* **socialsekreteraren** (ett rum per barn-/familjeärende, orosanmälningsbilagor, utredning, beslut, gallring enligt social dokumentation), **kommunsjuksköterskan/hemtjänsten** (SIP-/vårdplansrum delat mot region, HSLF-FS-krav uppfyllda), **överförmyndarhandläggaren** (rum per ställföreträdarärende, årsräkning + verifikat, deadline 1 mars), **HR** (avskilt rum per rehab-/personalärende, läkarintyg, samtycken — adresserar gapet där 66 % av chefer saknar säkert verktyg), **registratorn** (skapar och fördelar rum, ser gallring och leveransstatus).

### Widget 1: "Senaste säkra filer"

En kompakt dashboard-widget (Viva-modellen Card View + Quick View, progressive disclosure) som svarar på "vad har hänt med mina dokument senast" — den säkra motsvarigheten till Nextclouds favoritfil-widget men ärende- och sekretessmedveten.

Innehåll per rad:
- Dokumentnamn + ärenderum/dnr det tillhör (kontext, inte bara filnamn).
- Vad som hänt: *delad med medborgare*, *ny version*, *väntar på din granskning*, *signerad*, *uppladdad av motpart*.
- **Säker-kanal-markering** + var datan ligger ("på er server").
- Tidsstämpel + vem.
- Snabbåtgärd i kortet: öppna för samredigering, granska ny version, dela säkert (BankID), leverera till e-arkiv.

Designkrav: WCAG 2.2 AA (≥24×24 px klickytor, synlig fokus), verbledda åtgärdsnamn ("Granska ändring", "Dela säkert"), och ett tomt-tillstånd som är ett *positivt* besked ("Inga dokument väntar på åtgärd"). Bygg som widget via basplattformens dashboard-API (IAPIWidgetV2 + IButtonWidget + IConditionalWidget för rollstyrning) så den syns även i mobil/standardvy, men med Hubs-logik.

*Persona-nytta:* alla handläggarroller; särskilt den som jonglerar många parallella ärenden och behöver "nästa åtgärd" snarare än en filträd-vy.

### Widget 2: "Ärenderum"

En översiktswidget över handläggarens (eller funktionens) öppna ärenderum — task-orienterad, inte en mapplista. Inspirerad av GOV.UK task-list-statusar och triage-paradigmet.

Innehåll per rum:
- Dnr + kort ärendetitel + handlingstyp.
- **Status med fast statusuppsättning:** *Ny / Påbörjad / Väntar på motpart / Klar för beslut / Klar / Problem* (GOV.UK-mönster — håll antalet statusar lågt initialt).
- Indikatorer: antal nya/olästa dokument, väntar på medborgarens signatur, kommande gallring (countdown), om medborgardelning är aktiv.
- Deadline-markering (t.ex. överförmyndarens 1 mars; svarsfrist på SDK-meddelande kopplat till rummet).
- Snabbåtgärd: öppna rum, boka säkert möte i ärendet, skicka säkert meddelande som refererar rummet, leverera till e-arkiv.

Filter/uppdelning à la Superhuman Split Inbox / Linear Triage: "Mina rum" / "Funktionens rum" / "Väntar på mig". Tom kö = inget missat (ett *compliance-värde* för sekretess, inte bara bekvämlighet).

*Persona-nytta:* registratorn och funktionsbrevlådans ägare (fördela och bevaka), handläggaren (egna ärenden + deadlines), förvaltningschefen (volym och eftersläp).

### Stödfunktioner

- **Säker medborgardelning som förstaklassflöde:** "Dela med medborgare" → välj dokument → medborgaren får SMS/e-postnotis med länk → loggar in med BankID/Freja → läser/laddar upp i avgränsad vy (aldrig konto-krav, aldrig hela rummet). Mönstret är de facto-standard (TDialog/Sefos/Storegate) — avvik inte, men gör det till en knapp i ärenderummet och visa läs-/uppladdningskvittens i widgeten.
- **e-Underskrift i flödet** (eIDAS, via integrationspartner) — begär signatur på beslut/avtal direkt från dokumentet, status syns i widgeten.
- **Collectives som kunskapsbank:** sidor/undersidor + full-textsök + markdown i Files, åtkomst per team. Lägg rutiner, gallringsplaner per handlingstyp, mallar, lathundar och "så här gör du en orosanmälan-yta" här. Lågtröskel, on-prem, portabelt. En liten "Kunskapsbank"-genväg på dashboarden räcker.
- **Command palette (Ctrl+K)** ovanpå unified search: "Skapa ärenderum för dnr…", "Dela [dokument] säkert", "Leverera [rum] till e-arkiv", "Sök ärende". Billigt, skalar nybörjare→expert, differentierar mot standardplattformen.

## Rekommendation för Hubs

1. **Bygg inte fillagret — bygg arbetsytan och rättssäkerhetslagret.** Funktionsmappar, ACL, versioner, samredigering och Retention finns i basen och är produktionsmogna. Hubs värde är **ärenderummet** (funktionsmapp orkestrerad från ärendet, med rätt ACL, gallringstagg, deltagare och kommunikationsbryggor) plus de två dashboard-widgetarna. Det är det ingen konkurrent har samlat.

2. **Gör "ärenderum per dnr" till den bärande berättelsen för hela Hubs.** Det binder ihop meddelanden, video och filer: kommunikationen *refererar* rummet, dokumenten *bor* i rummet, gallring och leverans *händer* i rummet. Det är skillnaden mellan "Nextcloud med appar" och "en produkt för kommunens sekretessflöden".

3. **Led med on-prem som juridiskt, inte bara tekniskt, argument.** Mot Storegate/SaaS och M365: "ingen extern part får informationen → ingen OSL-lämplighetsbedömning, ingen CLOUD Act, ingen tredjelandsfråga". Visa det i gränssnittet ("all data i er driftmiljö").

4. **Bygg arkiv- och gallringsstöd från dag ett — det är ett upphandlingskrav, inte en extrafunktion.** Konfigurerbar gallring per handlingstyp enligt kommunens dokumenthanteringsplan (via restricted-taggar i Retention-appen, med ägarnotis innan radering), och **FGS-export till e-arkiv/Sydarkivera**. Arkivförordningens 2024-krav (export + radering före införande) gör detta till en kvalificeringsgrund.

5. **Default kontorssvit per kund:** OnlyOffice vid tung MS-formatmigrering (formattrohet), Collabora för ren OSS-stack. Båda on-prem via WOPI. Aldrig samredigering som lämnar driftmiljön.

6. **Tillgänglighet och LoA3 som hygien + säljargument.** WCAG 2.2 AA i alla medborgarytor; BankID/Freja-verifierad delning som standard; HSLF-FS-efterlevnad (kryptering + MFA) visad i vårdrelaterade rum; eIDAS2-redo-budskap.

7. **Differentiera widgetarna mot M365/SharePoint genom att vara task-orienterade, inte filträd.** "Senaste säkra filer" = nästa åtgärd per dokument; "Ärenderum" = status per ärende med GOV.UK-statusar och tom-kö-tillstånd. Mät tid-till-åtgärd, inte tid-på-dashboard.

8. **Collectives som kunskapsbank** är lågt hängande frukt med hög upplevd nytta för att minska kognitiv belastning (Arbetsmiljöverket/Suntarbetsliv-argumentet "en ingång, inte system nummer åtta").

## Källor

**Plattform: filer, mappar, behörighet, versioner, gallring, samredigering**
- https://github.com/nextcloud/groupfolders/blob/master/README.md
- https://nextcloud.com/blog/access-control-lists/
- https://portal.nextcloud.com/article/Operations/Using-Groupfolders---Advanced-Permissions
- https://docs.nextcloud.com/server/stable/admin_manual/file_workflows/retention.html
- https://docs.nextcloud.com/server/stable/admin_manual/configuration_files/file_versioning.html
- https://nextcloud.com/blog/nextcloud-22-introduces-knowledge-management/
- https://apps.nextcloud.com/apps/collectives
- https://www.onlyoffice.com/office-for-nextcloud
- https://help.nextcloud.com/t/onlyoffice-vs-collabora/232279
- https://docs.nextcloud.com/server/latest/developer_manual/digging_deeper/dashboard.html

**Konkurrenter / svensk säker fildelning**
- https://www.storegate.com/vara-losningar/offentliga-verksamheter/
- https://www.storegate.com/tillaggstjanster/dela-filer-med-bankid/
- https://www.storegate.com/tillaggstjanster/digital-signering/
- https://www.storegate.com/nyheter/fler-kontroller-for-publika-lankar/
- https://www.storegate.com/blogg/dokumenthantering-for-offentlig-verksamhet/
- https://secureappbox.com/our-services/securemailbox/
- https://sefos.se/en/sakra-meddelanden/
- https://compodium.com/tdialog
- https://webbplatsen.se/nextcloud/
- https://www.projalpha.se/nextcloud

**Arkiv, gallring, FGS, e-arkiv**
- https://riksarkivet.se/inlagg/uppdaterad-arkivforordning-forbattrad-digital-hantering-och-tydligare-mandat
- https://riksarkivet.se/files/2025/01/vagledning-bevarande-och-gallring-vid-upphandling-ver2-2025-01-09.pdf
- https://riksarkivet.se/fgs-earkiv
- https://riksarkivet.se/arkivutredningen
- https://lagen.nu/sou/2019:58
- https://www.regeringen.se/contentassets/8e78a764094b4f60a1da3e444d7e42fa/harifran-till-evigheten.-en-langsiktig-arkivpolitik-for-forvaltning-och-kulturarv-sou-201958.pdf
- https://www.sydarkivera.se/riksarkivet-aterupptar-arbetet-med-forvaltningsgemensamma-specifikationer-fgs/
- https://wiki.sydarkivera.se/wiki/FGS_Paketstruktur
- https://www.digg.se/styrning-och-samordning/ena---sveriges-digitala-infrastruktur/byggblock/sparbarhet/ramverk-loggning-och-sparbarhet/lagkrav/bevarande--och-gallringsregler

**Juridik: OSL, GDPR, HSLF-FS, eIDAS, NIS2, DOS-lagen/WCAG**
- https://www.esamverka.se/download/18.43a3add4188b9f2345a2fe78/1687332814480/ES2023-06%20V%C3%A4gledning%20Utkontraktering%20-%20sekretess%20och%20dataskydd.pdf
- https://www.riksdagen.se/sv/dokument-och-lagar/dokument/proposition/sekretessgenombrott-vid-utlamnande-for-teknisk_ha0397/html/
- https://www.socialstyrelsen.se/kunskapsstod-och-regler/regler-och-riktlinjer/juridiskt-stod-for-dokumentation/kommunicera-over-internet-eller-andra-oppna-nat/
- https://www.digg.se/kunskap-och-stod/eu-rattsakter/eidas-forordningen
- https://www.fi.se/sv/publicerat/nyheter/2026/det-har-galler-for-nya-cybersakerhetslagen/
- https://www.digg.se/webbriktlinjer/lagar-och-krav/det-har-ar-en-301-549-och-wcag

**UX-mönster för widgetarna**
- https://design-system.service.gov.uk/components/task-list/
- https://design-system.service.gov.uk/patterns/complete-multiple-tasks/
- https://learn.microsoft.com/en-us/viva/connections/available-dashboard-cards
- https://designsystem.forsakringskassan.se/latest/
- https://blog.superhuman.com/how-to-split-your-inbox-in-superhuman/
