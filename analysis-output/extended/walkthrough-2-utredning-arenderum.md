<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Walkthrough 2 — Utredning & ärenderum (socialsekreterare, barn & familj)

> **Persona:** `socialsekreterare` · **System of record (slutlagring):** Treserva (CGI) / Lifecare (Tietoevry) / Viva / Combine (Pulsen) — socialakten/BBIC-journalen. · **Plattform:** server v32 (Hub 25 Autumn) · **Datum:** 2026-06-14.
>
> **Avgränsning för detta flöde:** vi följer en *inledd* barnutredning enligt 11 kap. 2 § SoL — från att förhandsbedömningen mynnat ut i beslut "inleda utredning", genom skapandet/återöppnandet av ett **ärenderum** (Groupfolder per dnr/barn), insamling av handlingar, **on-prem samredigering** av utredningsdokument (Collabora), **säker delning av utvalda handlingar med vårdnadshavare** (med BankID-läskvittens), BBIC-struktur, **4-månadersfristen**, versionshantering, ACL/behörighet — och slutar med vad som committas till Treserva/socialakten och hur Hubs-kopian gallras. Förhandsbedömningssteget (14 dgr) och det signerade utredningsbeslutet/delgivningen ligger i Walkthrough 1 respektive 3; här refererar vi bara övergångarna.
>
> **Bärande modell:** Hubs = **mellanlagring (mellanarkiv)**. Slutlagring/slutarkiv = facksystemet (Treserva m.fl.) + i förlängningen e-arkiv (Sydarkivera, FGS). Originalet av en allmän handling får aldrig bli *enbart* en Hubs-fil. Handoff-mönster: **A** = API/REST (Ena REST-profil), **B** = drag-to-case (registrera/för in i facksystemet), **C** = FGS-export till e-arkiv, **D** = manuell + "Markera som överförd" (dag-1-fallback).
>
> **Brand-regel:** i UI säger vi aldrig "Nextcloud"/"Talk". I detta interna underlag namnger vi apparna (groupfolders/files, files_versions, files_retention, Collabora/OnlyOffice, collectives, forms, deck/tasks, sdkmc/securemail, libresign, activity) för att kunna wire:a.

---

## Förutsättning (var flödet börjar)

Förhandsbedömningen är klar och beslut **"inleda utredning"** är fattat (Walkthrough 1). I `orosanmalningar` (Tables-statusregister) byter raden status från *Under förhandsbedömning* → *Beslut inleda*. I facksystemet finns redan en **aktualisering/ett ärende registrerat i Treserva** (mönster B från Walkthrough 1) med dnr (ex. `2026-IFO-1234`). Detta walkthrough beskriver arbetslagret *runt* det Treserva-ärendet under utredningstiden.

⚠ ANTAGANDE: Ett dnr finns redan i Treserva när utredningen inleds (förhandsbedömningen registrerades). Om kommunen *inte* registrerar förhandsbedömningar förrän utredning inleds, skapas dnr först i Steg 1–2 och ärenderummets namn måste döpas om i efterhand (eller skapas med ett temporärt Hubs-token tills dnr finns). Hanteras nedan.

---

**Steg 1 — Öppna/återanvänd ärenderummet för barnet**

- **Handläggaren:** klickar widgeten **`arenderum`** ("Mina ärenderum") på Hubs Start → kortet för barnet (dnr 2026-IFO-1234) → "Öppna ärenderum". Rummet skapades redan vid förhandsbedömningen (Walkthrough 1, steg "Skapa ärenderum"); nu *återanvänds* det och växlar till utredningsläge. Om inget rum finns: knappen **"Skapa ärenderum"** (primäråtgärd).
- **I Hubs (mellanlagring):** en **Groupfolder** per dnr/barn (`/{ärenderum}/2026-IFO-1234-BarnNN/`) via `groupfolders`. Skapas/öppnas via admin-API `POST/GET /ocs/v2.php/apps/groupfolders/folders`; öppnas i UI via deep-link `/apps/files/?dir=/2026-IFO-1234-BarnNN` (eller `/f/{fileId}`). Status på `arenderum`-kortet: *Påbörjad*. Hubs **orkestrerar** skapandet (sätter ACL, taggar, mappstruktur) — handläggaren gör det inte för hand.
- **I facksystemet (slutlagring):** inget nytt skapas i detta steg — Treserva-ärendet finns redan. Ärenderummet *speglar* dnr men är inte Treserva-akten.
- **Data:** ingen extern datarörelse. Mappen är tom på nytt innehåll, ärver sekretessmarkering (OSL 26 kap. socialtjänstsekretess). LOA: handläggaren är inloggad LOA3 (BankID/Freja/SITHS).
- ⚠ LUCKA: `arenderum`-widgeten är `native` i app-kartan men **orkestreringslagret** ("ett klick → Groupfolder + ACL + tagg + BBIC-mappstruktur i rätt ordning") är inte färdigbyggt. Idag krävs flera manuella steg (skapa Groupfolder, sätt ACL i admin, applicera tagg). Den ihopkopplade "ett klick"-upplevelsen är att bygga.

---

**Steg 2 — Sätt ACL (behörighet = säkerhetsgräns, least permission)**

- **Handläggaren:** ser (men konfigurerar normalt inte själv) behörigheten i rummets "Deltagare & behörighet"-panel: hon själv = skriv; gruppledare/2:a-handläggare = läs; resten av enheten = ingen åtkomst. Vid behov lägger hon till en medhandläggare.
- **I Hubs:** **Advanced ACL** på Groupfolder (`groupfolders` ACL: Read/Write/Create/Delete/Share, allow/deny per användare/grupp/Team). Sätts av orkestreringen vid skapande; ändras via groupfolders-ACL-API. Default = **least permission**: bara tilldelad handläggare skriver. Aktivitet loggas i `activity` (OCS-API v2).
- **I facksystemet:** inget. (Behörighetsstyrningen i Treserva-akten är separat och styrs av Treservas egen rollmodell — Hubs ACL gäller bara mellanlagrings-kopian.)
- **Data:** ingen rörelse. ACL = OSL-säkerhetsgräns: en kollega utan behörighet får inte ens se att rummet/filerna finns (IConditionalWidget-principen gäller också `arenderum`-kortet — saknar man åtkomst syns inte rubrik/antal).
- ⚠ ANTAGANDE: kommunen har en disciplinerad ACL-mall per handlingstyp. Om ACL sätts ad-hoc per rum finns risk för "deny-glapp" (kollega råkar ärva läs via en föräldermapp). ACL-modellen är kraftfull men "kräver disciplinerad orkestrering" (native-apps-map) — det är ett driftansvar, inte automatik.

---

**Steg 3 — Lägg BBIC-strukturen i rummet (mallar ur kunskapsbanken)**

- **Handläggaren:** öppnar **`kunskapsbank`** (Collectives) → "BBIC — utredningsmall barn" → instansierar mappstruktur + dokumentmallar i ärenderummet (utredningsplan, informationskällor, barnets behov/föräldrarnas förmåga/familj & miljö, bedömning, beslutsunderlag).
- **I Hubs:** mallar bor som markdown/dokument i **`collectives`** (deep-link `/apps/collectives/{collective}/{page}`); kopieras in som tomma arbetsdokument i Groupfoldern. Undermappar enligt BBIC:s tre sidor (behov / förmåga / familj-miljö) + "Inhämtade uppgifter" + "Kommunikation med vårdnadshavare". Filtyp: `.docx`/`.odt` för samredigering i nästa steg.
- **I facksystemet:** inget ännu — BBIC-journalen i Treserva fylls när texten är klar (committas i Steg 10–11). Treserva/Lifecare har egen BBIC-struktur; Hubs-rummet är *arbetsytan före* den.
- **Data:** internt mallmaterial (ingen personuppgift förrän handläggaren skriver). Kunskapsbanken är statiskt referensmaterial, inget ärenderekord.
- ⚠ LUCKA/ANTAGANDE: BBIC är upphovsrättsskyddat/licensierat av Socialstyrelsen; **att replikera BBIC-mallar i Collectives kan kräva licens/överenskommelse**. ⚠ Dessutom: om utredningen ändå ska skrivas i Treservas *egna* BBIC-formulär (vissa kommuner skriver direkt i facksystemet) blir Hubs-samredigeringen en parallell yta som måste klippas/klistras in — då är värdet av on-prem-samredigering lägre. Detta är en **central luckа**: var skrivs utredningstexten *egentligen* — i Hubs/Collabora eller direkt i Treserva? Se Steg 10–11.

---

**Steg 4 — Samla in handlingar (inkommande säkra filer → rummet)**

- **Handläggaren:** i `attHantera`/`kvittenser` ser hon inkommande svar (BUP, skola, region) på begäran om uppgifter. Hon **sparar bilagan till ärenderummet** (knapp "Spara i ärenderum → 2026-IFO-1234"). Begäran om uppgifter skickades via `attHantera` → "Skicka säkert meddelande" (SDK org-till-org / säker e-post + BankID-länk till privatpersoner).
- **I Hubs:** bilagan flyttas från säker-meddelande-lagret (sdkmc) in i Groupfoldern, undermapp "Inhämtade uppgifter". Versionshantering (`files_versions`) aktiv från första byte. Provenance fångas: kanal (SDK), avsändare (BankID/SITHS-verifierad LOA), tidsstämpel. `senasteFiler` visar "Skola laddade upp X · 2026-06-14".
- **I facksystemet:** inget ännu — committas i Steg 10–11. Varje inhämtad uppgift är dock potentiellt en **allmän handling** som måste registreras/journalföras i Treserva (dokumentationsskyldighet, SoL 2025:400). Provenance-bandet visar "→ Treserva — ej registrerad".
- **Data:** riktning **IN** (extern part → Hubs). Sekretess: OSL 26 kap.; HSLF-FS 2016:40 om uppgiften är HSL-nära (BUP). Handoff senare: B/A. Retention: ärver rummets gallringstagg.
- ⚠ ANTAGANDE: att "spara bilaga till ärenderum" flyttar/kopierar från sdkmc-lagret till Groupfolder förutsätter en bygd brygga sdkmc↔files. Den är inte beskriven som färdig — sdkmc är `external`. ⚠ LUCKA: under förhandsbedömning får bara vårdnadshavare/anmälare/barn kontaktas; *uppgiftsinhämtning från BUP/skola hör hemma i utredningsfasen*. Widgeten måste veta vilken fas ärendet är i så att den inte föreslår otillåten inhämtning för tidigt — den fasvalideringen finns inte i datamodellen idag.

---

**Steg 5 — Samredigera utredningsdokumentet on-prem (Collabora)**

- **Handläggaren:** öppnar utredningsdokumentet i ärenderummet → det öppnas i **on-prem kontorssvit** (Collabora/Nextcloud Office). Hon och ev. medhandläggare skriver samtidigt; texten struktureras enligt BBIC. Inga uppgifter lämnar driftmiljön.
- **I Hubs:** Collabora/OnlyOffice över **WOPI** mot filen i Groupfoldern. Varje sparning skapar en **version** (`files_versions`) — full historik vem/när. Ingen molntjänst; suveränitet bevarad (svar på OSL 10:2a — ingen lämplighetsbedömning behövs).
- **I facksystemet:** inget — utkastet lever i mellanlagringen. Den färdiga texten förs över i Steg 10–11.
- **Data:** internt arbetsmaterial (utkast). Versioner = spårbarhet (NIS2/arkivlag). Default korttext/metadata = ärendereferens, inte klartextcitat (GDPR-dataminimering) — *men själva utredningstexten* innehåller självklart känsliga personuppgifter, skyddad av ACL + on-prem.
- ⚠ ANTAGANDE: Collabora/OnlyOffice är driftsatt (uttalat i prompt-kontexten: "Collabora/OnlyOffice är set up"). På ren v32 utan WOPI-server fungerar inte samredigering — då degraderas steget till "ladda ner / redigera lokalt / ladda upp", vilket bryter on-prem-samredigeringslöftet och versionsspårningen. Detta är ett **Nivå 2-backendberoende** (WIDGET-APP-MAP).

---

**Steg 6 — Versionshantering & utkastdisciplin**

- **Handläggaren:** vid behov öppnar hon "Versioner" på dokumentet, jämför/återställer en tidigare version, eller låser ett avsnitt som klart.
- **I Hubs:** `files_versions` (inbyggt i core/Groupfolders) — automatisk versionering, papperskorg, återställning. Aktivitet i `activity`.
- **I facksystemet:** inget. Treserva har sin egen versions-/låsmodell på journalanteckningen *efter* att texten committats; Hubs-versionerna är arbetsmaterialets historik och **gallras med rummet** (de följer inte med till akten som separata handlingar).
- **Data:** internt; versionshistoriken är inte i sig en allmän handling som ska bevaras (det är *slutversionen* som blir handling). Riktning: ingen extern.
- ⚠ ANTAGANDE: att versionshistoriken får gallras förutsätter att kommunen bedömt att mellanliggande utkast inte är bevarandepliktiga. Generellt är utkast/arbetsmaterial inte allmän handling förrän expedierat/ärendet slutbehandlat (TF 2 kap.) — men gränsdragningen "utkast vs upprättad handling" är en juristfråga per kommun. Flagga: **gallringsregeln för Hubs-versioner måste vara förankrad i dokumenthanteringsplanen.**

---

**Steg 7 — Inhämta samtycke (Forms + signeringssteg) inför uppgiftsutbyte/SIP**

- **Handläggaren:** skickar ett **säkert samtyckesformulär** till vårdnadshavaren (samtycke att inhämta/utbyta uppgifter med skola/BUP/region, ev. inför SIP) via `mallarSamtycke` (Forms). Vårdnadshavaren fyller i och bekräftar med BankID-loggat steg.
- **I Hubs:** `forms` (deep-link `/apps/forms/{hash}`) + identitetslager (LOA3) framför den annars oautentiserade publika länken. Det ifyllda, BankID-bekräftade samtycket arkiveras som **handling i ärenderummet**. Ett ifyllt samtycke = allmän handling.
- **I facksystemet:** committas senare (B/A) tillsammans med övriga utredningshandlingar; samtycket är en del av akten.
- **Data:** riktning **IN** (vårdnadshavare → Hubs). LOA3. Samtycke bryter sekretess för det specifika uppgiftsutbytet (men HSLF-FS 2016:40-krav kan inte avtalas bort). Retention: bevaras som handling → till akten.
- ⚠ LUCKA: Forms saknar **native filuppladdning** och **förgrening** (native-apps-map). Räcker för ett enkelt samtycke, men om samtyckesblanketten kräver bilaga eller villkorslogik håller den inte. ⚠ ANTAGANDE: "BankID-loggat signeringssteg på ett Forms-svar" förutsätter en bygd brygga Forms↔signeringsadapter — Forms ger inte BankID-signatur native.

---

**Steg 8 — Dela utvalda handlingar säkert med vårdnadshavaren (kommunicering, med BankID-läskvittens)**

- **Handläggaren:** inför kommuniceringen (partsinsyn enligt FL 25 § / 10 §) väljer hon **utvalda** handlingar ur rummet och delar dem säkert med vårdnadshavaren — **inte** hela ärenderummet. Knapp i `arenderum`: "Dela utvalda handlingar säkert" → väljer mottagare (vårdnadshavare, verifierad), väljer filer, skickar.
- **I Hubs:** två möjliga vägar — (a) en **säker delningslänk** (Files-share) bakom LOA3/BankID, eller (b) handlingarna skickas som bilagor via **säkert meddelande** (sdkmc/securemail + BankID-länk). Mottagaren legitimerar sig med BankID (LOA3) för att läsa → **läskvittens** registreras och visas i `kvittenser` (Skickad → Levererad → Notis öppnad → Inloggad LOA3 → Läst). Aktivitet i `activity`.
- **I facksystemet:** själva *kommuniceringen* (att handlingar delgivits parten + kvittens) ska dokumenteras i Treserva-akten (journalanteckning "kommunicerat utredning 2026-06-14, läst 2026-06-15"). Kvittensbeviset bevaras med handlingen i akten.
- **Data:** riktning **UT** (Hubs → vårdnadshavare). Mönster: utkanal till medborgare (E) / säker dialog. Sekretess: bara *utvalda* handlingar delas (uppgifter om tredje man kan behöva maskeras — partsinsyn ≠ rätt till allt). LOA3-läskvittens är beviset.
- ⚠ LUCKA: **maskering/sekretessprövning av vad som får delas** är inte en funktion i `arenderum` idag — handläggaren måste manuellt välja rätt filer och själv bedöma om tredjemansuppgifter måste maskeras. Att dela "fel" fil ur ett rum med känsliga uppgifter är den allvarligaste felrisken i hela flödet. ⚠ ANTAGANDE: BankID-läskvittens på en Files-delningslänk förutsätter ID-core framför delningen; native Files-share ger inte BankID-verifierad läskvittens. Det är `external`/att-bygga, inte native.

---

**Steg 9 — Bevaka 4-månadersfristen (och ev. förlängning)**

- **Handläggaren:** ser i `bevakningar` ("Mina bevakningar & frister") utredningens **4-månadersfrist** med eskaleringsfärg (grå→gul→röd) och påminnelse T-7/T-3/T-0. Vid särskilda skäl: dokumenterar **beslut om förlängning** (nytt beslut, ny frist) — och en ny bevakning sätts.
- **I Hubs:** `bevakningar` på `deck` (delad board) + `minaUppgifter`/`tasks` (VTODO VALARM-påminnelser). Fristen härleds ur utredningsstartdatum (11 kap. 2 § SoL: klar inom **4 månader**, skyndsamt). Hubs bygger påminnelse-före-deadline *bara till tilldelad* (täcker Deck #1549/#566).
- **I facksystemet:** **Treserva/Lifecare äger den formella fristbevakningen** — registrerade bevakningar visas på handläggarens skrivbord och **texten blir röd när bevakningsdatumet passerats**; man kan ange antal dagar före förfallodatum som varning ska visas. Hubs **dubblerar inte** detta; Hubs-bevakningen täcker det inflöde/arbete som ännu inte är en formell aktivitet i Treserva.
- **Data:** internt; bevakningstext = ärendereferens, inte klartextcitat (GDPR). Vid klarmarkering: "gallra (personlig notering)" vs "för till ärendet". Förlängningsbeslutet *självt* är en handling → till akten.
- ⚠ LUCKA/ANTAGANDE: om både Hubs och Treserva bevakar 4-månadersfristen finns **dubbelbevakningsrisk** (två röda siffror, oklart vilken som "gäller"). Modellen säger Hubs ska täcka *det icke-registrerade inflödet* — men 4-månadersfristen ÄR en formell frist i Treserva. Strikt enligt modellen borde 4-mån-fristen *ägas av Treserva och bara speglas/läsas* i Hubs (kräver läskonnektor mot Treserva, mönster A). Idag finns ingen sådan läskonnektor → Hubs räknar sin egen frist, vilket riskerar att divergera från Treservas. **Detta är en konkret lucka i system-of-record-logiken för just frister.**

---

**Steg 10 — Färdigställ utredningstexten → committa BBIC-journalen till Treserva**

- **Handläggaren:** när utredningen är klar **för hon över** utredningstexten/BBIC-journalen till **Treserva-akten** (klistrar in / importerar / via API). I `arenderum` markerar hon "Förd till facksystemet". Beslutet (inleda insats / avsluta utan insats / ansökan om vård) hanteras i signeringsflödet (Walkthrough 3).
- **I Hubs:** överföringsstatus sätts på rummet ("Förd till Treserva 2026-06-14"). Provenance-band: *"Registrerad i Treserva, dnr 2026-IFO-1234"*. Slutversionen av dokumentet är den handling som committas; utkasthistoriken stannar och gallras.
- **I facksystemet (slutlagring):** utredningen/BBIC-journalanteckningen **skapas i Treserva-akten** — detta är ögonblicket den allmänna handlingen får sin arkivredovisning. Mönster: **B** (drag-to-case / förifylld registrering) som standard; **A** (Treservas öppna API) hos storkund; **D** (manuell kopiering + "Markera som överförd") som dag-1-fallback.
- **Data:** riktning **UT** (Hubs → Treserva). Här uppfylls dokumentationsskyldigheten (SoL 2025:400) och registrerings-/journalföringsplikten. Sekretess följer med (OSL 26 kap.).
- ⚠ LUCKA: **detta är den största icke-byggda biten.** `arenderum`/`registreraFordela` är `proposed-integration`; det finns **ingen färdig Treserva-konnektor** (A) och drag-to-case (B) mot Treserva är inte implementerad. Idag är realistiska läget **mönster D — manuell överföring** (klipp/klistra eller ladda upp i Treserva, klicka "Markera som överförd"). Då finns ett glapp där samma handling lever i *både* Hubs och Treserva tills gallringen kör, och "förd över"-statusen är en **manuell sanningsmarkering** utan teknisk bekräftelse från Treserva. ⚠ ANTAGANDE: om utredningen skrevs direkt i Treservas BBIC-formulär (se Steg 3-luckan) är detta steg trivialt (texten är redan där) — men då har Hubs-samredigeringen i Steg 5 inte använts. De två antagandena (skriv i Hubs / skriv i Treserva) är ömsesidigt uteslutande och **prompten/produkten måste välja vilket som gäller**.

---

**Steg 11 — Knyt övriga handlingar till akten (bilagor, samtycke, kvittenser)**

- **Handläggaren:** för även över de **bevarandepliktiga bilagorna** (inhämtade uppgifter som blev del av beslutsunderlaget, det signerade samtycket, kommuniceringskvittensen) till Treserva-akten/diariet, eller bekräftar att de följer med utredningen.
- **I Hubs:** varje fil får överföringsstatus. `kvittenser`/`activity` levererar leverans-/läsbevisen som metadata till akten. Det som *inte* blev del av underlaget (rena arbetsutkast, dubbletter) markeras för gallring.
- **I facksystemet:** bilagorna/kvittensbevisen **bevaras med utredningen** i Treserva-akten (mönster B/A/D). Treserva→e-arkiv (FGS) är facksystemets ansvar vid akttömning.
- **Data:** riktning **UT**. Allmänna handlingar bevaras i akten; arbetsmaterial gallras i Hubs. Skilj **gallringsbar personlig notering** från **arkivpliktig allmän handling** (arkivlagen 1990:782 / OSL).
- ⚠ ANTAGANDE: handläggaren gör korrekt gallringsbedömning per fil. Utan en checklista/automatik ("denna handlingstyp bevaras / denna gallras") finns risk att (a) en bevarandepliktig handling gallras för tidigt i Hubs innan den committats, eller (b) arbetsmaterial felaktigt förs till akten och blir allmän handling i onödan. Bedömningsstödet finns inte i widgeten idag.

---

**Steg 12 — Gallra Hubs-kopian (dubbel countdown) efter bekräftad överföring**

- **Handläggaren:** behöver normalt inte göra något — gallringen är automatisk. På `arenderum`-kortet ser hon **dubbel countdown**: facksystemets bevarande ("Bevaras i Treserva/e-arkiv") *och* Hubs egen rensning ("Rensas ur Hubs 30 dgr efter överföring"). Vid avslut kan rummet alternativt **FGS-exporteras** om Hubs-rummet självt råkar bära ett original (mönster C).
- **I Hubs:** `files_retention` gallrar Groupfoldern baserat på **restricted-tagg + tidsregel** (ägarnotis innан radering). Taggen sattes vid rummets skapande (Steg 1) enligt handlingstyp. `arkivGallring`/FGS-byggare (E-ARK CSIP/SIP) för de fall ett original ska till e-arkiv.
- **I facksystemet:** originalet bevaras i Treserva-akten och i slutänden i **e-arkiv (Sydarkivera, FGS)** enligt kommunens dokumenthanteringsplan. Hubs blir därmed *inte* oavsiktlig slutlagring.
- **Data:** riktning: radering i Hubs. Retention = mellanlagringens rensningsregel ("gallras X dgr efter överföring"), distinkt från slutlagringens bevarandebeslut. GDPR-dataminimering: ingen permanent skuggdatabas av barnets känsligaste uppgifter.
- ⚠ LUCKA: Retention triggar på **tagg + tid**, inte på "bekräftad överförd till Treserva". Eftersom överföringsstatusen (Steg 10) idag är en **manuell markering** utan teknisk bekräftelse, kan rensningen i värsta fall köra **innan** handlingen faktiskt landat i Treserva (om handläggaren markerade "överförd" felaktigt, eller om tidsregeln löper oberoende av status). Den robusta lösningen — "gallra först N dgr *efter* verifierad commit-händelse från facksystemet" — kräver mönster-A-återkoppling som inte finns. ⚠ ANTAGANDE: restricted-taggen är korrekt satt per handlingstyp vid skapandet; om fel tagg sätts gallras antingen för tidigt eller aldrig.

---

## Var bor originalet? (sammanfattande svar på promptens kärnfråga)

| Artefakt | Original/sanning bor i | Hubs roll | Gallras i Hubs |
|---|---|---|---|
| Orosanmälan + förhandsbedömning | Treserva-akten (registrerad i WT1) | Mottog & triagerade | Ja, efter överföring |
| Inhämtade uppgifter (skola/BUP) | Treserva-akten (när del av underlag) | Mellanlagrade, versionerade | Ja, efter commit |
| Utredningsdokument/BBIC-journal | **Treserva BBIC-journal** | Samredigerings-arbetsyta (utkast) | Ja — slutversion committas, utkast gallras |
| Signerat samtycke (Forms) | Treserva-akten | Inhämtade + arkiverade | Ja, efter commit |
| Kommuniceringskvittens (läskvittens) | Treserva-akten (som metadata) | Genererade beviset | Logg 12 mån, sedan transient |
| Beslut (PAdES/PDF/A) | Treserva-akten + e-arkiv (WT3) | Iscensatte signering | Referens kvar, original committas |
| Versionshistorik/arbetsutkast | Ingenstans (ej bevarandepliktigt) | Ägde det | Ja, med rummet |

**Kort:** under utredningen är Hubs-ärenderummet den *aktiva arbetsytan* (samredigering, säker delning, kvittens), men varje *bestående* handling — utredningstexten, samtycket, beslutet, kvittensbevisen — committas till **Treserva-akten** (och i slutänden e-arkiv via FGS), varefter Hubs-kopian gallras. Avsikten är transient; varaktigheten kan vara månader.

---

## Systemöversikt för detta flöde

| Steg | Hubs-app (intern) | Facksystem (slutlagring) | Handoff |
|---|---|---|---|
| 1 Öppna/skapa ärenderum | `groupfolders`/`files` (`arenderum`) | Treserva (dnr finns redan) | — (spegling) |
| 2 Sätt ACL | `groupfolders` ACL + `activity` | — | — |
| 3 BBIC-struktur | `collectives` (`kunskapsbank`) → `files` | — (Treserva BBIC fylls i steg 10) | — |
| 4 Samla handlingar | sdkmc → `files`/`groupfolders` (`senasteFiler`) | inget ännu | committas steg 10–11 (B/A) |
| 5 Samredigera | Collabora/OnlyOffice (WOPI) + `files_versions` | inget ännu | committas steg 10 |
| 6 Versionshantering | `files_versions` + `activity` | — | — (gallras) |
| 7 Samtycke | `forms` (`mallarSamtycke`) + ID-core | Treserva-akten | committas steg 11 (B/A) |
| 8 Säker delning + läskvittens | `files`-share/sdkmc + ID-core (`kvittenser`) | Treserva (kommunicering journalförs) | E (utkanal) + B (journal) |
| 9 Frist 4 mån | `deck`/`tasks` (`bevakningar`) | Treserva äger formell fristbevakning | A (bör läsa Treserva) |
| 10 Committa utredning | `arenderum`/`registreraFordela` | **Treserva BBIC-journal** | **B/A/D** |
| 11 Knyt bilagor/kvittens | `files` + `kvittenser`/`activity` | Treserva-akten | B/A/D |
| 12 Gallra Hubs-kopian | `files_retention` / FGS-byggare (`arkivGallring`) | e-arkiv (Sydarkivera, FGS) | C (vid ev. original) + retention |

---

## Identifierade luckor

1. **Skrivs utredningen i Hubs eller i Treserva?** (Steg 3, 5, 10) — den mest grundläggande luckan. On-prem-samredigering i Collabora (Steg 5) och "committa texten till Treserva" (Steg 10) förutsätter att texten skrivs i Hubs *och sedan flyttas*. Om kommunen skriver direkt i Treservas BBIC-formulär används inte Hubs-samredigeringen alls. De två modellerna är ömsesidigt uteslutande och produkten har inte valt.

2. **Ingen färdig Treserva-konnektor (mönster A/B).** (Steg 10–11) — `arenderum`/`registreraFordela` är `proposed-integration`. Realistiskt dag-1-läge är **mönster D (manuell)**, vilket gör "Förd till facksystemet"-statusen till en obekräftad manuell markering, inte en teknisk sanning.

3. **Gallring triggar på tagg+tid, inte på verifierad commit.** (Steg 12) — Retention kan köra innan handlingen faktiskt landat i Treserva, eftersom överföringsstatusen är manuell. Robust lösning ("gallra efter verifierad commit-händelse") kräver A-återkoppling som saknas. Risk: original gallras för tidigt.

4. **Dubbelbevakning av 4-månadersfristen.** (Steg 9) — Treserva *äger* den formella fristbevakningen (rödmarkerar passerat datum), men Hubs räknar sin egen frist utan läskonnektor mot Treserva → de kan divergera. Strikt enligt modellen borde fristen läsas ur Treserva (A), inte räknas självständigt.

5. **Ingen maskering/sekretessprövning vid säker delning.** (Steg 8) — handläggaren väljer filer och bedömer tredjemansuppgifter helt manuellt; att dela fel fil ur ett känsligt rum är flödets allvarligaste felrisk. Maskeringsstöd saknas i `arenderum`.

6. **Fasvalidering saknas i datamodellen.** (Steg 4) — widgeten skiljer inte på förhandsbedömnings- vs utredningsfas och kan därför föreslå otillåten uppgiftsinhämtning (endast vårdnadshavare/anmälare/barn får kontaktas under förhandsbedömning).

7. **BBIC-mallar i Collectives kan kräva licens.** (Steg 3) — BBIC ägs/licensieras av Socialstyrelsen; att replikera mallstrukturen on-prem kan behöva överenskommelse.

8. **Backend-beroenden inte garanterat på plats.** Collabora/OnlyOffice WOPI (Steg 5, Nivå 2), ID-core/BankID framför Files-delning och Forms (Steg 7–8), sdkmc↔files-brygga (Steg 4) — alla är `external`/`proposed`, inte native på ren v32. Prompten antar prod-miljö (Collabora set up), men resten av bryggorna är inte färdigbyggda.

9. **Gallringsbedömning per handling är manuell.** (Steg 6, 11) — gränsdragningen utkast/arbetsmaterial vs upprättad allmän handling görs av handläggaren utan stöd; fel åt båda håll skapar arkiv-/offentlighetsproblem. Måste förankras i dokumenthanteringsplanen.

10. **Orkestreringen "ett klick → rum + ACL + tagg + struktur" är inte byggd.** (Steg 1–3) — `arenderum` är native som datalager men den sammanhållna skapandeupplevelsen (rätt ACL-mall, rätt restricted-tagg, BBIC-struktur i rätt ordning) kräver bygge; idag flera manuella admin-steg.

---

### Källor (Swedish specifics verifierade)
- Förhandsbedömning ≤14 dgr; ej-inleda får bara lagras kronologiskt — https://kunskapsguiden.se/omraden-och-teman/barn-och-unga/handlaggning-och-dokumentation-med-barnet-i-centrum/aktualisera/forhandsbedomning/
- Barnutredning klar inom 4 mån, skyndsamt, förlängning bara vid särskilda skäl (11 kap. 2 § SoL); JO-kritik mot kringgående — [Länsstyrelsen: 4-månadersregeln](https://www.lansstyrelsen.se/download/18.1b1d393819324610c374b2fd/1732521237665/Barnen%20i%20f%C3%B6rsta%20hand%20-%204-m%C3%A5nadersregeln%20i%20barnav%C3%A5rdsutredningar.pdf) · [JO: Sundbyberg, överskriden frist 11 kap. 2 §](https://www.jo.se/besluten/social-och-arbetsmarknadsnamnden-i-sundbybergs-kommun-far-allvarlig-kritik-for-att-ha-brustit-i-handlaggningen-av-tva-utredningarenligt-11-kap-1-%C2%A7-sol-bl-a-genom-att-ha-overskridit-den-tidsfr/)
- Treserva bevakning (röd vid passerat datum, antal dagar före varning), BBIC-journal — interna underlag `arendehantering-map.md`, `esign-todo-native.md` + https://www.cgi.com/se/sv/treserva
- (Övriga referenser: se `persona-socialsekreterare.md`, `middleware-architecture.md`, `native-apps-map.md`, `arendehantering-map.md`, `esign-todo-native.md`, `widgetApps.js`, `WIDGET-APP-MAP.md` — detta walkthrough grundas i sin helhet på dessa.)
