/* AUTO-GENERATED from analysis-output/widget-apps.json (app-mapping workflow). Hubs = mellanlagring; case system = slutlagring. */
export default {
  "attHantera": {
    "backingApp": "Säkra meddelanden + summary-endpoint (sdkmc/securemail/mail)",
    "ncAppId": "",
    "status": "external",
    "deepLink": "/apps/sdkmc/",
    "prerequisites": "sdkmc + securemail + mail/fax-brygga; server-side kanalklassning via /ocs/v2.php/apps/sdkmc/api/v1/summary; SDK-accesspunkt (AS4)",
    "systemOfRecord": "Facksystemet per rad (Treserva/W3D3/Lifecare SP/Provisum/Adato) — Hubs stagar inflödet, mönster B/A"
  },
  "dagensMoten": {
    "backingApp": "Kalender + säkert videomöte",
    "ncAppId": "calendar",
    "status": "native",
    "deepLink": "/apps/calendar/",
    "prerequisites": "calendar + spreed-itsl; P2P räcker för demo, HPB för grupp/lobby-skalning/inspelning",
    "systemOfRecord": "Mötet äger inget rekord — SIP-plan/anteckning förs in i facksystemet (transit, D/A)"
  },
  "kvittenser": {
    "backingApp": "Säkra meddelanden (AS4-kvittens)",
    "ncAppId": "",
    "status": "external",
    "deepLink": "/apps/sdkmc/",
    "prerequisites": "sdkmc receipt-data + ID-core (leverans/läs-LOA3); SDK-loggretention 12 mån",
    "systemOfRecord": "Kvittensbeviset bevaras med handlingen i facksystemet/diariet; loggas i SDK-loggen"
  },
  "funktionsbrevlador": {
    "backingApp": "Säkra meddelanden (funktionsadress)",
    "ncAppId": "",
    "status": "external",
    "deepLink": "/apps/sdkmc/",
    "prerequisites": "sdkmc funktionsadress-stöd (SKR 2025); behörighet = OSL-säkerhetsgräns (IConditionalWidget)",
    "systemOfRecord": "Det plockade ärendet registreras i facksystemet (mönster B)"
  },
  "bevakningar": {
    "backingApp": "Uppgifts-/bevakningsmodulen (delad board + personlig)",
    "ncAppId": "deck",
    "status": "native",
    "deepLink": "/apps/deck/board/2",
    "prerequisites": "deck + tasks på v32; Hubs bygger påminnelse T-7/T-3/T-0 bara till tilldelad (Deck #1549/#566) + WCAG 2.5.7-knappalternativ",
    "systemOfRecord": "Facksystemet äger den formella fristbevakningen (Treserva/W3D3/Provisum); Hubs-kort gallras (D)"
  },
  "bokningsbaraTider": {
    "backingApp": "Kalender (Appointments) + auto-videorum",
    "ncAppId": "calendar",
    "status": "native",
    "deepLink": "/apps/calendar/",
    "prerequisites": "Appointments; auto-videorum kräver spreed installerad; publik bokningssida oautentiserad → Hubs lägger LOA3/lobby framför",
    "systemOfRecord": "Mötet är transit; utfall förs in i facksystemet"
  },
  "nytta": {
    "backingApp": "Strukturerat register (ROI)",
    "ncAppId": "tables",
    "status": "proposed-integration",
    "deepLink": "/apps/tables/#/table/{tableId}",
    "prerequisites": "Tables-register matat av sdkmc-kanalstatistik; renderas som widget, aldrig rå tabell",
    "systemOfRecord": "Internt internkontroll-/ledningsunderlag (inget ärenderekord)"
  },
  "systemhalsa": {
    "backingApp": "sdkmc driftstatus (accesspunkt/kvittens)",
    "ncAppId": "",
    "status": "external",
    "deepLink": "/apps/sdkmc/",
    "prerequisites": "sdkmc accesspunkt-upptid, fellager, ej kvitterade leveranser",
    "systemOfRecord": "NIS2 kontinuitetsbevis (internkontroll); allvarlig avvikelse → incidentrapporter → MCF"
  },
  "attSignera": {
    "backingApp": "E-underskrift (LibreSign demo / Inera Underskriftstjänst-API el. Sweden Connect prod)",
    "ncAppId": "libresign",
    "status": "proposed-integration",
    "deepLink": "/apps/libresign/",
    "prerequisites": "occ libresign:install (cfssl/JSignPDF/pdftk) → bara konto/SMS-identitet (SES/svag AES). Riktig AES kräver Inera (mTLS + SITHS funktionscert, BankID/Freja/SITHS) eller Sweden Connect-nod",
    "systemOfRecord": "Signerad PAdES/PDF/A + valideringsintyg committas till facksystemet/diariet; LTV-bevis bevaras"
  },
  "skickatForSignering": {
    "backingApp": "E-underskrift + säkra meddelanden",
    "ncAppId": "libresign",
    "status": "proposed-integration",
    "deepLink": "/apps/libresign/",
    "prerequisites": "Samma signeringsadapter som attSignera; per-part-status, Påminn-knapp; sdkmc för säkert utskick",
    "systemOfRecord": "Klart → signerad handling committas till facksystemet (D/A)"
  },
  "minaUppgifter": {
    "backingApp": "Uppgifts-/bevakningsmodulen (personlig VTODO)",
    "ncAppId": "tasks",
    "status": "native",
    "deepLink": "/apps/tasks/",
    "prerequisites": "Tasks/VTODO native VALARM-påminnelser; CalDAV",
    "systemOfRecord": "Genomförandet dokumenteras i facksystemet; uppgiften gallras som personlig notering (D)"
  },
  "arenderum": {
    "backingApp": "Säkra filer / ärenderum (Groupfolders + ACL + Retention)",
    "ncAppId": "groupfolders",
    "status": "native",
    "deepLink": "/apps/files/",
    "prerequisites": "groupfolders + ACL + files_versions + files_retention; samredigering kräver Collabora/OnlyOffice (WOPI)",
    "systemOfRecord": "Originalhandlingen committas till facksystemet; ärenderum → FGS-export till e-arkiv vid avslut + Retention-gallring (A/C/D). Dubbel countdown"
  },
  "senasteFiler": {
    "backingApp": "Säkra filer (versioner)",
    "ncAppId": "files",
    "status": "native",
    "deepLink": "/apps/files/",
    "prerequisites": "files + groupfolders + files_versions",
    "systemOfRecord": "Originalet bor i ärenderummet → committas till facksystem/e-arkiv; Hubs-kopia gallras"
  },
  "orosanmalningar": {
    "backingApp": "Tables-register (status/frist) + Forms (internt led) + sdkmc-inflöde",
    "ncAppId": "tables",
    "status": "proposed-integration",
    "deepLink": "/apps/tables/#/table/{tableId}",
    "prerequisites": "Tables för 14-dgr-countdown/status; Forms internt anmälningsled (ej publik e-tjänst — saknar fildropp/förgrening)",
    "systemOfRecord": "Beslut + aktualisering registreras i Treserva-akten (B/A)"
  },
  "utskrivningsbevakning": {
    "backingApp": "sdkmc-inflöde + Deck/Tasks (bevakning) + Tables (dygnsregister)",
    "ncAppId": "tables",
    "status": "proposed-integration",
    "deepLink": "/apps/tables/#/table/{tableId}",
    "prerequisites": "Tables för dygnsräknare mot betalningsansvar (lag 2017:612; belopp HSLF-FS 2025:74); kr-riskindikator; deck för delad kö",
    "systemOfRecord": "Lifecare SP (planering) + Treserva HSL (journalanteckning vid hemtagning) (A/D)"
  },
  "samverkansavvikelser": {
    "backingApp": "Strukturerat register (Tables) + säkert utskick (SDK)",
    "ncAppId": "tables",
    "status": "proposed-integration",
    "deepLink": "/apps/tables/#/table/{tableId}",
    "prerequisites": "Förifylld avvikelse (patient-id, motpart, bristtyp, tidsstämplar); säkert utskick via SDK; forms för internt fält",
    "systemOfRecord": "Regionens/kommunens avvikelsesystem (RLDatix/Platina); MAS följer trend (A/B)"
  },
  "arsrakningar": {
    "backingApp": "Deck/Tasks (kampanj-rendering) + Tables (statusspegel)",
    "ncAppId": "tables",
    "status": "proposed-integration",
    "deepLink": "/apps/tables/#/table/{tableId}",
    "prerequisites": "Läskonnektor mot Provisum/Aider (mönster A / CSV-spegling) så '312 av 540 · 1 mars' är SANN; FB 14:15",
    "systemOfRecord": "Provisum/Aider (granskningsstatus) — Hubs renderar siffran, äger den inte"
  },
  "granskningsko": {
    "backingApp": "Deck (plockbar delad kö) + ärenderum",
    "ncAppId": "deck",
    "status": "proposed-integration",
    "deepLink": "/apps/deck/board/{boardId}",
    "prerequisites": "Plockbar kö; årsräkning + verifikat side-by-side i ärenderum (groupfolders); källkanal-ikon (e-tjänst/papper/post)",
    "systemOfRecord": "Provisum/Aider (granskningsresultat, anmärkning) (A/B/D)"
  },
  "uppdragskontroll": {
    "backingApp": "Strukturerat register (Tables-regelmotor)",
    "ncAppId": "tables",
    "status": "proposed-integration",
    "deepLink": "/apps/tables/#/table/{tableId}",
    "prerequisites": "Tables-regelmotor + Flow; flaggar många uppdrag / upprepade anmärkningar (JO dec 2025); läser facksystemets uppdragsdata",
    "systemOfRecord": "Provisum (tillsynsbeslut/notering)"
  },
  "rehabarenden": {
    "backingApp": "Säkra filer / ärenderum + statusregister",
    "ncAppId": "groupfolders",
    "status": "proposed-integration",
    "deepLink": "/apps/files/?dir=/{rehabrum}",
    "prerequisites": "groupfolders/ACL (HR + ansvarig chef) + files_retention; statusflöde i Tables; dubbel retention",
    "systemOfRecord": "Rehab-akten = Adato; anställningshändelse = Personec/Visma/Heroma (D, A om API)"
  },
  "kansligInkorg": {
    "backingApp": "Säkra meddelanden (HR-kontextfiltrerad)",
    "ncAppId": "",
    "status": "external",
    "deepLink": "/apps/sdkmc/",
    "prerequisites": "Summary-endpoint kontextfiltrerad till HR; avskild från allmän kommunikation; behörighet = säkerhetsgräns",
    "systemOfRecord": "Adato (rehab) / Personec/Visma/Heroma (PA) (D, A om API)"
  },
  "fristStrip": {
    "backingApp": "Deadline-register (Tables) + bevakning (Deck/Tasks)",
    "ncAppId": "tables",
    "status": "proposed-integration",
    "deepLink": "/apps/tables/#/table/{tableId}",
    "prerequisites": "Härleds ur intygsdatum, sjukperiod, FK-kallelser; dag 8 / dag 30 (plan) / 60-dagar / avstämningsmöte; deck+tasks-påminnelser",
    "systemOfRecord": "Formell frist-/aktivitetsbevakning bor i Adato (PA-integrerad) (A/D)"
  },
  "mallarSamtycke": {
    "backingApp": "Forms (internt) + mallbibliotek + e-underskrift",
    "ncAppId": "forms",
    "status": "proposed-integration",
    "deepLink": "/apps/forms/{hash}",
    "prerequisites": "Forms + BankID-signering (ersätter 'samtycke per post'); FK 7459, rehaböverenskommelse, SIP-samtycke; collectives/files mallbibliotek; libresign",
    "systemOfRecord": "Signerat samtycke/plan → rehab-rum → Adato (D)"
  },
  "registreraFordela": {
    "backingApp": "Diarie-/registreringsstöd (förifyllning ur sdkmc-metadata)",
    "ncAppId": "tables",
    "status": "proposed-integration",
    "deepLink": "/apps/tables/#/table/{tableId}",
    "prerequisites": "Förifyllt formulär (avsändare/datum/föreslaget dnr/ärendemening/sekretess); tunn diarie-konnektor per facksystem (B/A); D dag 1",
    "systemOfRecord": "W3D3 / Public 360° / Ciceron / Platina / Evolution / LEX (dnr, allmän handling)"
  },
  "utlamnande": {
    "backingApp": "Diariesök + utlämnandelogg + säkert utskick",
    "ncAppId": "",
    "status": "proposed-integration",
    "deepLink": "/apps/sdkmc/",
    "prerequisites": "'Skyndsamt'-timer, sekretessprövnings-checklista, maskering; diariesök-deep-link (W3D3); securemail/sdkmc för säkert utskick",
    "systemOfRecord": "Originalet bor i diariet; utlämnandet loggas i Hubs (GDPR art. 30/32)"
  },
  "namndcykel": {
    "backingApp": "Ärenderum + säker delning + kalender + Tables",
    "ncAppId": "groupfolders",
    "status": "proposed-integration",
    "deepLink": "/apps/files/?dir=/{namndrum}",
    "prerequisites": "groupfolders/ACL + calendar + säker delning + tables; helt digitalt sammanträde (Prop. 2025/26:164, 1 juli 2026) via spreed-itsl",
    "systemOfRecord": "Diariet (kallelse/underlag/protokoll som allmänna handlingar) → e-arkiv (B/C)"
  },
  "justeringAnslag": {
    "backingApp": "E-underskrift + anslagstavla + säker kanal",
    "ncAppId": "libresign",
    "status": "proposed-integration",
    "deepLink": "/apps/libresign/",
    "prerequisites": "Digital justering (BankID, PAdES/PDF/A via Inera prod), anslag + laga-kraft-klocka (21 dgr), expediering med delgivningskvittens",
    "systemOfRecord": "Det justerade protokollet → diariet → e-arkiv (FGS); LTV-bevis (B/D + C)"
  },
  "arkivGallring": {
    "backingApp": "Säkra filer + Retention + FGS-byggare",
    "ncAppId": "files_retention",
    "status": "proposed-integration",
    "deepLink": "/settings/admin/groupfolders",
    "prerequisites": "files_retention (restricted-tagg) + groupfolders/files + FGS Paketstruktur 2.0-byggare (E-ARK CSIP/SIP)",
    "systemOfRecord": "E-arkiv (Sydarkivera, FGS) — mönster C. Hubs-kopian gallras efter överföring"
  },
  "complianceStatus": {
    "backingApp": "Compliance-/NIS2-modul (härledd ur activity + authLoa + SDK-status + Retention)",
    "ncAppId": "activity",
    "status": "proposed-integration",
    "deepLink": "/apps/activity/",
    "prerequisites": "Härledd, ingen manuell inmatning; mappas mot Infosäkkollen (mål ≥ nivå 3); cybersäkerhetslagen 2025:1506",
    "systemOfRecord": "Kommunstyrelsen/ledningen (ledningsgenomgång) + MSB Infosäkkollen-självskattning"
  },
  "incidentrapporter": {
    "backingApp": "Incidenthantering (klock-logik) + Tables (incidentregister)",
    "ncAppId": "tables",
    "status": "proposed-integration",
    "deepLink": "/apps/tables/#/table/{tableId}",
    "prerequisites": "Klock-kedja 24h/72h/1 mån; MCF-rapportgenerator förfyller ur sdkmc-feed/activity (D nu via IRON/blankett, A på sikt); WCAG: text + ikon ej bara färg",
    "systemOfRecord": "MCF/PTS (tidig varning → anmälan → läges-/slutrapport); arkivpliktig rapport → e-arkiv (FGS)"
  },
  "sakerhetshandelser": {
    "backingApp": "sdkmc säkerhetshändelse-feed + Activity OCS-API v2",
    "ncAppId": "activity",
    "status": "external",
    "deepLink": "/apps/activity/",
    "prerequisites": "Auth-/delnings-/routing-logg via sdkmc; activity OCS-API v2; lokal llm2 föreslår prio (avstängbar, transparent)",
    "systemOfRecord": "Bedömd händelse → internkontroll-logg (transient) eller eskaleras → MCF; korrelat → SIEM"
  },
  "loggSparbarhet": {
    "backingApp": "Logg- & spårbarhetspanel (SDK-loggindex + sökindex + activity)",
    "ncAppId": "activity",
    "status": "proposed-integration",
    "deepLink": "/apps/activity/",
    "prerequisites": "12/12 mån sökbar; sök mot AS4 Message/Conversation ID (utan innehåll, Diggs krav); 'vem har sett vad'-export; SIEM-exportkonnektor",
    "systemOfRecord": "Kommunens SIEM (maskinell export) + DSO/tillsyn (PDF). Hubs-loggen själv 12 mån (transient)"
  },
  "authLoa": {
    "backingApp": "ID-core / auth (sessionsdata)",
    "ncAppId": "",
    "status": "external",
    "deepLink": "",
    "prerequisites": "BankID/Freja/SITHS-sessioner; MFA-status; eIDAS2/EUDI-redo-markör; SMS-OTP spärrad för LOA3",
    "systemOfRecord": "Internkontroll/Infosäkkollen-bevis (HSLF-FS 2016:40 + Diggs tillitsramverk)"
  },
  "provisionering": {
    "backingApp": "Användar-/grupphantering + funktionsadress-admin",
    "ncAppId": "provisioning_api",
    "status": "proposed-integration",
    "deepLink": "/settings/users",
    "prerequisites": "In/ut/vilande/överbehörig-kö; 'Lägg till i funktionsadress'/'Avetablera' loggas; groupfolders; läskonnektor mot Personec/Heroma/Visma för auto-livscykel",
    "systemOfRecord": "Auktoritativ identitet bor i HR-systemet/IAM; åtkomstlivscykeln → SIEM (A om API)"
  },
  "dataSuveranitet": {
    "backingApp": "Compliance-modul (statisk + åtkomstlogg-härledd)",
    "ncAppId": "",
    "status": "proposed-integration",
    "deepLink": "",
    "prerequisites": "Driftmiljö-fakta + extern-åtkomst-logg; diskret markör i varje persona-vy, ingen åtgärd",
    "systemOfRecord": "Inget rekord — beviset att slutlagringen sker on-prem (svar på OSL 10:2a + CLOUD Act)"
  },
  "kunskapsbank": {
    "backingApp": "Kunskapsbank (wiki on-prem)",
    "ncAppId": "collectives",
    "status": "native",
    "deepLink": "/apps/collectives/",
    "prerequisites": "collectives + circles (Team) — finns i core",
    "systemOfRecord": "Statiskt referensmaterial — inget ärenderekord. Låst utanför skalet (WCAG 3.2.6)"
  },
  "identitetsBadge": {
    "backingApp": "ID-core (BankID/Freja completionData)",
    "ncAppId": "",
    "status": "external",
    "deepLink": "/call/{token}",
    "prerequisites": "ID-core (BankID/Freja/SITHS); lobby completionData via spreed; SMS-OTP märkt nödutgång, spärrad för LOA3",
    "systemOfRecord": "Identitetsbevis bevaras med handlingen/kvittensen i facksystemet"
  },
  "todolista": {
    "backingApp": "Socialtjänst-todo (delad utredningslista, Deck-backad)",
    "ncAppId": "deck",
    "status": "native",
    "deepLink": "/apps/deck/board/2",
    "prerequisites": "Deck-board per barn/dnr; BBIC-checklista ur kunskapsbank; 'Skapa bevakning från meddelande'; korttext = ärendereferens (GDPR-dataminimering); påminnelse-före-deadline bara till tilldelad (Deck #1549/#566)",
    "systemOfRecord": "Mellanlagring — formell bevakning/journal committas i Treserva/Lifecare/Viva/Combine; uppgift gallras eller länkas (D)"
  },
  "motesanteckningar": {
    "backingApp": "Mötestranskribering + lokal AI-sammanfattning (recording + KB-Whisper + llm2/Assistant)",
    "ncAppId": "spreed",
    "status": "proposed-integration",
    "deepLink": "/call/{token}",
    "prerequisites": "recording server + HPB; stt_whisper2 + KB-Whisper (Apache-2.0, GPU ≥4GB); llm2 (grön GGUF, ≥8GB VRAM) + chunkning/map-reduce; recording_consent påtvingat; human-in-the-loop. Sekretessbelagda klientsamtal: dokumentera, kör inte skarpt än (IMY/SKR/Socialstyrelsen)",
    "systemOfRecord": "Godkänd mötesanteckning committas till facksystemet (Treserva/Lifecare SP/Adato/Provisum/diariet); rå-WebM + rå-transkript gallras (transient)"
  }
}
