<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Widget → app → system-of-record-karta

> **Vad detta är:** den auktoritativa kopplingstabellen mellan varje dashboard-widget i `hubs_start`
> och (a) den underliggande appen som driver den, (b) appens NC-app-id (eller "extern"/"sdkmc"),
> (c) status (native / proposed-integration / external), (d) deep-link-rutten, (e) prerequisites för
> att wire:a den på riktigt, och (f) **system-of-record** — var datan slutlagras.
>
> **Bärande arkitektur:** Hubs är **mellanlagring (middleware/staging)**. Slutlagringen är alltid
> verksamhetens ärendehanteringssystem (Treserva, Lifecare, W3D3, Provisum, Adato, MCF/e-arkiv …).
> Hubs-appen tar emot, triagerar, signerar, möter och samredigerar — sedan **för handläggaren över**
> utfallet till facksystemet, och Hubs-kopian gallras. Varje widget måste därför svara: *varifrån kom
> datan in?* och *var hamnar utfallet till slut?*
>
> **Brand-regel:** i produkt-/UI-text säger vi aldrig "Nextcloud" eller "Talk". I denna interna karta
> namnger vi app-id (deck, tasks, calendar, spreed, files, groupfolders, libresign, …) för att kunna
> wire:a. Datum: 2026-06-13 · Plattform: server v32 (Hub 25 Autumn).

---

## Statusvärden

| Status | Betydelse |
|---|---|
| **native** | Appen finns/installeras på ren v32 och kan öppnas för riktigt via deep-link idag (ev. + lätt backend). |
| **proposed-integration** | Widgeten renderas, men det skarpa värdet kräver en konnektor/backend som ska byggas (facksystem-API, BankID/Inera, Tables-regelmotor, AI/GPU, recording/HPB). |
| **external** | Drivs primärt av ITSL:s egna tjänster (sdkmc / securemail / ID-core) eller en extern nationell tjänst (Inera, Sweden Connect, MCF) — inte en installerbar NC-community-app. |

**Handoff-mönster (genomgående):** **A** = API/REST (Ena REST-profil), **B** = drag-to-case
(registrera i diariet), **C** = FGS-export till e-arkiv, **D** = manuell (dag-1-fallback).

---

## 0. Tvärgående widgetar (finns i flera personavyer)

| Widget | UI-titel | Backing app | NC-app-id | Status | Deep-link | Prerequisites | System-of-record |
|---|---|---|---|---|---|---|---|
| `attHantera` | Att hantera | Säkra meddelanden + summary-endpoint | — (sdkmc/securemail/mail) | external | `/ocs/v2.php/apps/sdkmc/api/v1/summary` → `/apps/sdkmc/` | sdkmc + securemail + mail/fax-brygga; server-side kanalklassning | Facksystemet per rad (Treserva/W3D3/Lifecare SP/Provisum/Adato). Hubs stagar inflödet (mönster B/A) |
| `kvittenser` | Leveranser & kvittens | Säkra meddelanden (AS4-kvittens) | — (sdkmc) | external | `/apps/sdkmc/` (kvittensvy) | sdkmc receipt-data + ID-core (leverans/läs-LOA3) | Kvittensbeviset bevaras med handlingen i facksystemet/diariet; loggas i SDK-loggen (12 mån) |
| `funktionsbrevlador` | Funktionsbrevlådor | Säkra meddelanden (funktionsadress) | — (sdkmc) | external | `/apps/sdkmc/?mailbox={addr}` | sdkmc funktionsadress-stöd (SKR 2025); behörighet = OSL-säkerhetsgräns (`IConditionalWidget`) | Det plockade ärendet → registreras i facksystemet (B) |
| `bevakningar` | Mina bevakningar & frister | Uppgifts-/bevakningsmodulen | `deck` (+ `tasks`) | native | `/apps/deck/board/{boardId}` | Deck + Tasks finns på v32; Hubs bygger påminnelse-före-deadline T-7/T-3/T-0 bara till tilldelad (Deck #1549/#566) + WCAG 2.5.7-knappalternativ | Facksystemet äger den **formella** fristbevakningen (Treserva/W3D3/Provisum rödmarkerar passerat). Hubs dubblerar inte; Hubs-kort gallras (D) |
| `minaUppgifter` | Mina uppgifter | Uppgifts-/bevakningsmodulen (personlig) | `tasks` | native | `/apps/tasks/` | Tasks/VTODO (native VALARM-påminnelser); CalDAV | Genomförandet dokumenteras i facksystemet; uppgiften gallras som personlig notering (D) |
| `arenderum` | Mina ärenderum | Säkra filer / ärenderum | `groupfolders` (+ `files`, `files_versions`, `files_retention`) | native | `/apps/files/?dir=/{ärenderum}` · `/f/{fileId}` | Groupfolders + ACL + versioner + Retention; samredigering kräver Collabora/OnlyOffice | Originalhandlingen committas till facksystemet; ärenderummet → FGS-export till e-arkiv vid avslut + Retention-gallring (A/C/D). **Dubbel countdown** (facksystem-bevarande + Hubs-rensning) |
| `senasteFiler` | Senaste säkra filer | Säkra filer (versioner) | `files` (+ `groupfolders`, `files_versions`) | native | `/apps/files/` · `/f/{fileId}` | Files + Groupfolders-versioner | Originalet bor i ärenderummet → committas till facksystem/e-arkiv; Hubs-kopia gallras |
| `dagensMoten` | Dagens & veckans säkra möten | Kalender + säkert videomöte | `calendar` + `spreed` | native | `/apps/calendar/` · `/call/{token}` | calendar (Appointments) + spreed-itsl; P2P räcker för demo, **HPB** för grupp/lobby-skalning/inspelning | Mötet äger inget rekord — SIP-plan/anteckning förs in i facksystemet (D/A, transit) |
| `bokningsbaraTider` | Bokningsbara tider | Kalender (Appointments) + auto-videorum | `calendar` (+ `spreed`) | native | `/apps/calendar/appointment/{userId}/{configToken}` | Appointments; auto-videorum kräver spreed installerad; publik bokningssida oautentiserad → Hubs lägger LOA3/lobby framför | Mötet är transit; utfall → facksystemet |
| `attSignera` | Att signera | E-underskrift | `libresign` (demo) / **Inera Underskriftstjänst-API** el. **Sweden Connect-nod** (prod) | proposed-integration | `/apps/libresign/` · prod: extern signeringsadapter | LibreSign: `occ libresign:install` (cfssl/JSignPDF/pdftk) → bara konto/SMS-identitet (SES/svag AES). **Riktig AES kräver Inera (mTLS + SITHS funktionscert, BankID/Freja/SITHS)** | Signerad PAdES/PDF/A + valideringsintyg → committas till facksystemet; LTV-bevis bevaras |
| `skickatForSignering` | Skickat för signering | E-underskrift + säkra meddelanden | `libresign` (+ sdkmc) / Inera (prod) | proposed-integration | `/apps/libresign/` | Samma signeringsadapter som `attSignera`; per-part-status, Påminn-knapp | Klart → signerad handling committas till facksystemet (D/A) |
| `nytta` | Nytta hittills | Strukturerat register (ROI) | `tables` | proposed-integration | `/apps/tables/#/table/{tableId}` | Tables-register matat av sdkmc-kanalstatistik; renderas som widget, aldrig rå tabell | Internt internkontroll-/lednings­underlag (inget ärenderekord) |
| `dataSuveranitet` | Datasuveränitet | Compliance-modul (statisk + åtkomstlogg-härledd) | — (compliance/activity) | proposed-integration | — (diskret markör, ingen åtgärd) | Driftmiljö-fakta + extern-åtkomst-logg | Inget rekord — **beviset** att slutlagringen sker on-prem (svar på OSL 10:2a + CLOUD Act) |
| `kunskapsbank` | Kunskapsbank & mallar | Kunskapsbank (wiki) | `collectives` | native | `/apps/collectives/{collective}/{page}` | Collectives + `circles` (Team) — finns i core | Statiskt referensmaterial — inget ärenderekord. Låst utanför skalet (WCAG 3.2.6) |
| `identitetsBadge` | Identitet & leverans | ID-core (BankID/Freja completionData) | — (ID-core / spreed lobby) | external | `/call/{token}` (lobby completionData) | ID-core (BankID/Freja/SITHS); SMS-OTP märkt nödutgång, spärrad för LOA3 | Identitetsbevis bevaras med handlingen/kvittensen i facksystemet |

---

## 1. Persona: `socialsekreterare` (barn & familj)

**System of record:** Treserva (CGI) / Lifecare (Tietoevry) / Viva / Combine (Pulsen) — socialakten/BBIC-journalen.
**Layout:** main = `attHantera`, `orosanmalningar`, `bevakningar`, `arenderum`, `attSignera` · side = `dagensMoten`, `kvittenser`, `funktionsbrevlador`, `minaUppgifter`, `senasteFiler`, `kunskapsbank` · **(nytt)** `todolista`, `motesanteckningar`.

| Widget | Backing app | NC-app-id | Status | Deep-link | Prerequisites | System-of-record |
|---|---|---|---|---|---|---|
| `orosanmalningar` | Tables-register (status/frist) + Forms (internt led) + sdkmc-inflöde | `tables` (+ `forms`) | proposed-integration | `/apps/tables/#/table/{tableId}` | Tables för 14-dgr-countdown/status; Forms internt anmälningsled (ej publik e-tjänst — saknar fildropp/förgrening) | Beslut + aktualisering registreras i **Treserva**-akten (B/A) |
| `todolista` *(ny)* | Socialtjänst-todo (delad utredningslista) | `deck` | native | `/apps/deck/board/{boardId}` | Deck-board per barn/dnr; BBIC-checklista ur `kunskapsbank`; "Skapa bevakning från meddelande"; korttext = ärendereferens (GDPR-dataminimering) | Mellanlagring — formell bevakning/journal committas i **Treserva/Lifecare**; uppgift gallras eller länkas (D) |
| `motesanteckningar` *(ny)* | Mötestranskribering + lokal AI-sammanfattning | `spreed` + `stt_whisper2` + `llm2` | proposed-integration | `/call/{token}` → `/apps/files/?dir=/{ärenderum}` | recording server + **HPB**; `stt_whisper2` + **KB-Whisper** (Apache-2.0); `llm2` (grön GGUF) + chunkning; `recording_consent` påtvingat; human-in-the-loop. **Sekretessbelagda klientsamtal: dokumentera, kör inte skarpt än** (IMY/SKR) | Godkänd anteckning committas till **Treserva**; rå-WebM/-transkript gallras (transient) |

*De tvärgående widgetarna (`attHantera`, `bevakningar`, `arenderum`, `attSignera`, `dagensMoten`, `kvittenser`, `funktionsbrevlador`, `minaUppgifter`, `senasteFiler`, `kunskapsbank`) — se §0.*

---

## 2. Persona: `registrator` (registrator / nämndsekreterare)

**System of record:** W3D3 / Public 360° / Ciceron / Platina / Evolution / LEX (diariet) → e-arkiv (Sydarkivera, FGS).
**Layout:** main = `attHantera`, `registreraFordela`, `funktionsbrevlador`, `namndcykel`, `justeringAnslag` · side = `kvittenser`, `bevakningar`, `utlamnande`, `arkivGallring`, `dataSuveranitet`, `nytta` · **(nytt)** `motesanteckningar`.

| Widget | Backing app | NC-app-id | Status | Deep-link | Prerequisites | System-of-record |
|---|---|---|---|---|---|---|
| `registreraFordela` | Diarie-/registreringsstöd (förifyllning ur sdkmc-metadata) | `tables` (+ sdkmc) | proposed-integration | `/apps/tables/#/table/{tableId}` → diarie-deep-link | Förifyllt formulär (avsändare/datum/föreslaget dnr/ärendemening/sekretess); **tunn diarie-konnektor per facksystem** (B/A); D dag 1 | **W3D3 / Public 360° / Ciceron / Platina / Evolution / LEX** (dnr, allmän handling) |
| `namndcykel` | Ärenderum + säker delning + kalender + Tables | `groupfolders` + `calendar` + `spreed` + `tables` | proposed-integration | `/apps/files/?dir=/{namndrum}` · `/apps/calendar/` | Groupfolders/ACL + Calendar + säker delning; helt digitalt sammanträde (Prop. 2025/26:164, 1 juli 2026) via spreed-itsl | Diariet (kallelse/underlag/protokoll som allmänna handlingar) → e-arkiv (B/C) |
| `justeringAnslag` | E-underskrift + anslagstavla + säker kanal | `libresign` / Inera (prod) | proposed-integration | `/apps/libresign/` | Digital justering (BankID, PAdES/PDF/A), anslag + laga-kraft-klocka (21 dgr), expediering | Det justerade protokollet → diariet → e-arkiv (FGS); LTV-bevis (B/D + C) |
| `utlamnande` | Diariesök + utlämnandelogg + säkert utskick | — (sdkmc/securemail) + diariesök | proposed-integration | diarie-deep-link (W3D3) → `/apps/sdkmc/` | "Skyndsamt"-timer, sekretessprövnings-checklista, maskering; läser ur diariet | Originalet bor i **diariet**; utlämnandet loggas i Hubs (GDPR art. 30/32) |
| `arkivGallring` | Säkra filer + Retention + FGS-byggare | `files_retention` (+ `groupfolders`, `files`) | proposed-integration | `/settings/admin/groupfolders` · Retention-konfig | Retention (restricted-tagg) + **FGS Paketstruktur 2.0-byggare** (E-ARK CSIP/SIP) | **E-arkiv (Sydarkivera, FGS)** — mönster C. Hubs-kopian gallras efter överföring |

---

## 3. Persona: `hsl_skoterska` (kommunsjuksköterska, HSL)

**System of record (tre lager):** Lifecare SP (planering) · Treserva HSL / Lifecare VoO / Combine / Viva (kommunal HSL-journal) · regionens avvikelsesystem (RLDatix/Platina).
**Layout:** main = `attHantera`, `utskrivningsbevakning`, `samverkansavvikelser`, `bevakningar`, `arenderum` · side = `dagensMoten`, `funktionsbrevlador`, `kvittenser`, `minaUppgifter`, `senasteFiler`, `kunskapsbank` · **(nytt)** `motesanteckningar`.

| Widget | Backing app | NC-app-id | Status | Deep-link | Prerequisites | System-of-record |
|---|---|---|---|---|---|---|
| `utskrivningsbevakning` | sdkmc-inflöde + Deck/Tasks (bevakning) + Tables (dygnsregister) | `tables` (+ `deck`) | proposed-integration | `/apps/tables/#/table/{tableId}` | Tables för dygnsräknare mot betalningsansvar (lag 2017:612; belopp HSLF-FS 2025:74); kr-riskindikator | **Lifecare SP** (planering) + **Treserva HSL** (journalanteckning vid hemtagning) (A/D) |
| `samverkansavvikelser` | Strukturerat register (Tables) + säkert utskick (SDK) | `tables` (+ sdkmc, `forms`) | proposed-integration | `/apps/tables/#/table/{tableId}` | Förifylld avvikelse (patient-id, motpart, bristtyp, tidsstämplar); säkert utskick via SDK | **Regionens/kommunens avvikelsesystem** (RLDatix/Platina); MAS följer trend (A/B) |

---

## 4. Persona: `hr_chef` (HR / chef — rehab & personal)

**System of record:** Adato (Miljödata) — rehab-akten · Personec / Visma HR / Heroma — PA-/lönehändelse.
**Layout:** main = `kansligInkorg`, `fristStrip`, `rehabarenden`, `attSignera`, `bevakningar` · side = `dagensMoten`, `skickatForSignering`, `mallarSamtycke`, `kvittenser`, `senasteFiler`, `kunskapsbank`, `nytta` · **(nytt)** `motesanteckningar`.

| Widget | Backing app | NC-app-id | Status | Deep-link | Prerequisites | System-of-record |
|---|---|---|---|---|---|---|
| `kansligInkorg` | Säkra meddelanden (HR-kontextfiltrerad) | — (sdkmc/securemail/mail) | external | `/apps/sdkmc/?context=hr` | Summary-endpoint kontextfiltrerad till HR; avskild från allmän kommunikation; behörighet = säkerhetsgräns | **Adato** (rehab) / **Personec/Visma/Heroma** (PA) (D, A om API) |
| `rehabarenden` | Säkra filer / ärenderum + statusregister | `groupfolders` (+ `files_retention`, `tables`) | proposed-integration | `/apps/files/?dir=/{rehabrum}` | Groupfolders/ACL (HR + ansvarig chef) + Retention; statusflöde i Tables; dubbel retention | Rehab-akten = **Adato**; anställningshändelse = **Personec/Visma/Heroma** (D, A om API) |
| `fristStrip` | Deadline-register (Tables) + bevakning (Deck/Tasks) | `tables` (+ `deck`, `tasks`) | proposed-integration | `/apps/tables/#/table/{tableId}` | Härleds ur intygsdatum, sjukperiod, FK-kallelser; dag 8 / **dag 30 (plan)** / 60-dagar / avstämningsmöte | Formell frist-/aktivitetsbevakning bor i **Adato** (A/D) |
| `mallarSamtycke` | Forms (internt) + mallbibliotek + e-underskrift | `forms` (+ `collectives`, `libresign`) | proposed-integration | `/apps/forms/{hash}` | Forms + BankID-signering (ersätter "samtycke per post"); FK 7459, rehaböverenskommelse, SIP-samtycke | Signerat samtycke/plan → rehab-rum → **Adato** (D) |

---

## 5. Persona: `overformyndare` (överförmyndarhandläggare)

**System of record:** Provisum (Sambruk/Flowfactory) / Aider (+ Mitt Wärna-inrapportering → nationellt register 2028).
**Layout:** main = `arsrakningar`, `granskningsko`, `attSignera`, `skickatForSignering`, `bevakningar` · side = `funktionsbrevlador`, `arenderum`, `dagensMoten`, `uppdragskontroll`, `kvittenser`, `kunskapsbank`, `nytta` · **(nytt)** `motesanteckningar`.

| Widget | Backing app | NC-app-id | Status | Deep-link | Prerequisites | System-of-record |
|---|---|---|---|---|---|---|
| `arsrakningar` | Deck/Tasks (kampanj-rendering) + Tables (statusspegel) | `tables` (+ `deck`) | proposed-integration | `/apps/tables/#/table/{tableId}` | **Läskonnektor mot Provisum/Aider** (mönster A / CSV-spegling) så "312 av 540 · 1 mars" är SANN; FB 14:15 | **Provisum/Aider** (granskningsstatus). Hubs renderar siffran, äger den inte |
| `granskningsko` | Deck (plockbar delad kö) + ärenderum | `deck` (+ `groupfolders`) | proposed-integration | `/apps/deck/board/{boardId}` | Plockbar kö; årsräkning + verifikat side-by-side i ärenderum; källkanal-ikon (e-tjänst/papper/post) | **Provisum/Aider** (granskningsresultat, anmärkning) (A/B/D) |
| `uppdragskontroll` | Strukturerat register (Tables-regelmotor) | `tables` | proposed-integration | `/apps/tables/#/table/{tableId}` | Tables-regelmotor + Flow; flaggar många uppdrag / upprepade anmärkningar (JO dec 2025); läser facksystemets uppdragsdata | **Provisum** (tillsynsbeslut/notering) |

---

## 6. Persona: `forvaltare` (förvaltare / IT / informationssäkerhet)

**System of record (tredelad):** MCF/PTS (incidentanmälan, cybersäkerhetslagen 2025:1506) · kommunens SIEM/loggsystem · e-arkiv via Sydarkivera/FGS.
**Layout:** main = `complianceStatus`, `incidentrapporter`, `sakerhetshandelser`, `loggSparbarhet`, `authLoa` · side = `systemhalsa`, `provisionering`, `arkivGallring`, `dataSuveranitet`, `nytta`.

| Widget | Backing app | NC-app-id | Status | Deep-link | Prerequisites | System-of-record |
|---|---|---|---|---|---|---|
| `complianceStatus` | Compliance-/NIS2-modul (härledd ur activity + authLoa + SDK-status + Retention) | `activity` (+ compliance-modul) | proposed-integration | `/apps/activity/` · compliance-vy | Härledd, ingen manuell inmatning; mappas mot Infosäkkollen (mål ≥ nivå 3) | **Kommunstyrelsen/ledningen** + MSB Infosäkkollen-självskattning |
| `incidentrapporter` | Incidenthantering (klock-logik) + Tables (incidentregister) | `tables` (+ sdkmc-feed) | proposed-integration | `/apps/tables/#/table/{tableId}` | Klock-kedja 24h/72h/1 mån; **MCF-rapportgenerator** förfyller ur logg (D nu via IRON/blankett, A på sikt); WCAG: text + ikon, ej bara färg | **MCF/PTS** (tidig varning → anmälan → läges-/slutrapport); arkivpliktig rapport → e-arkiv (FGS) |
| `sakerhetshandelser` | sdkmc säkerhetshändelse-feed + Activity OCS-API v2 | `activity` (+ sdkmc) | external | `/apps/activity/api/v2/activity/{filter}` | Auth-/delnings-/routing-logg; lokal `llm2` *föreslår* prio (avstängbar, transparent) | Bedömd händelse → internkontroll-logg (transient) eller eskaleras → MCF; korrelat → SIEM |
| `loggSparbarhet` | Logg- & spårbarhetspanel (SDK-loggindex + sökindex + activity) | — (sdkmc-logg) + `activity` | proposed-integration | `/apps/activity/` · loggsök-vy | 12/12 mån sökbar; sök mot AS4 Message/Conversation ID (utan innehåll, Diggs krav); "vem har sett vad"-export | **Kommunens SIEM** (maskinell export) + DSO/tillsyn (PDF). Hubs-loggen själv 12 mån (transient) |
| `authLoa` | ID-core / auth (sessionsdata) | — (ID-core) | external | auth-/sessionsvy | BankID/Freja/SITHS-sessioner; MFA-status; eIDAS2/EUDI-redo-markör | Internkontroll/Infosäkkollen-bevis (HSLF-FS 2016:40 + Diggs tillitsramverk) |
| `systemhalsa` | sdkmc driftstatus (accesspunkt/kvittens) | — (sdkmc) | external | systemhälsovy | Accesspunkt-upptid, fellager, ej kvitterade leveranser | NIS2 kontinuitetsbevis (internkontroll); allvarlig avvikelse → incidentrapporter → MCF |
| `provisionering` | Användar-/grupphantering + funktionsadress-admin | `provisioning_api` (core) + `groupfolders` | proposed-integration | `/settings/users` · funktionsadress-admin | In/ut/vilande/överbehörig-kö; "Lägg till i funktionsadress"/"Avetablera" loggas; **läskonnektor mot Personec/Heroma/Visma** för auto-livscykel | Auktoritativ identitet bor i **HR-systemet/IAM**; åtkomstlivscykeln → SIEM (A om API) |

---

## Sammanfattning — vad är native idag vs vad kräver backend

**Native på ren v32 idag (öppnas för riktigt):** `bevakningar`, `minaUppgifter`, `arenderum`,
`senasteFiler`, `dagensMoten`, `bokningsbaraTider`, `kunskapsbank`, `todolista`.

**Proposed-integration (renderas, men skarpt värde kräver bygge):** `attSignera`/`skickatForSignering`
(Inera/Sweden Connect-AES), `motesanteckningar` (HPB + recording + KB-Whisper + llm2/GPU),
`orosanmalningar`/`utskrivningsbevakning`/`samverkansavvikelser`/`arsrakningar`/`granskningsko`/
`uppdragskontroll`/`rehabarenden`/`fristStrip`/`nytta`/`registreraFordela`/`namndcykel`/
`justeringAnslag`/`utlamnande`/`arkivGallring`/`mallarSamtycke` (Tables-register / facksystem-konnektor /
Retention+FGS / Forms), `complianceStatus`/`incidentrapporter`/`loggSparbarhet`/`provisionering`
(compliance-modul / MCF-/SIEM-/HR-brygga).

**External (ITSL-tjänster / nationell infrastruktur):** `attHantera`, `kvittenser`, `funktionsbrevlador`,
`kansligInkorg`, `identitetsBadge`, `sakerhetshandelser`, `authLoa`, `systemhalsa`, `dataSuveranitet`
(sdkmc / securemail / ID-core), samt prod-signering (Inera Underskriftstjänst / Sweden Connect) och
incidentdestinationen (MCF/PTS).

**De fyra–fem tunga backend-beroendena** som avgör vilka wow-flöden som är produktionsklara:
(1) Talk **HPB**, (2) Talk **recording-server**, (3) `live_transcription`-GPU (svenska ej verifierat —
använd KB-Whisper-efterhand i stället), (4) LibreSign cfssl/JSignPDF (internt) + **Inera/Sweden Connect**
(skarp BankID-AES), (5) AI via **`llm2`/`stt_whisper2` på Docker/GPU**.

Se `NATIVE-APPS-INSTALL.md` för occ-kommandon och detaljerade prerequisites.
