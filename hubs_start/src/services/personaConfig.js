/* AUTO-GENERATED from analysis-output/persona-config.json. Persona-personalised dashboard catalog. */
export default {
  "widgets": [
    {
      "id": "attHantera",
      "title": "Att hantera",
      "category": "kommunikation",
      "feature": "säkra meddelanden (sdkmc/securemail/fax)",
      "dataSource": "real",
      "description": "Aggregerad triagekö över allt inkommande som kräver åtgärd, med kanalikon (SDK/säker e-post/fax/möte) och fristräknare. Förstavyns nav, matad av summary-endpointen (server-side kanalklassning)."
    },
    {
      "id": "dagensMoten",
      "title": "Dagens & veckans säkra möten",
      "category": "mote",
      "feature": "kalender + säkert videomöte",
      "dataSource": "real",
      "description": "Bokade/kommande säkra videomöten med en-klicks-anslut och lobby-/väntrumsstatus (BankID/Freja-verifierade deltagare, LOA-nivå per person)."
    },
    {
      "id": "kvittenser",
      "title": "Leveranser & kvittens",
      "category": "kommunikation",
      "feature": "säkra meddelanden (kvittensdata)",
      "dataSource": "real",
      "description": "Leveranstidslinje per utgående meddelande/delgivning: Skickad → Levererad → Öppnad → Inloggad (LOA3) → Läst → Besvarad, med feltillstånd. Den emotionella ersättningen för att ringa och kolla att faxen kom fram."
    },
    {
      "id": "funktionsbrevlador",
      "title": "Funktionsbrevlådor",
      "category": "kommunikation",
      "feature": "säkra meddelanden (funktionsadress)",
      "dataSource": "real",
      "description": "Delade funktionsadress-köer (SKR 2025): oläst/otilldelat per brevlåda, plocka/fördela ärende, eskalering. Behörighetsstyrd – visar bara brevlådor användaren har OSL-behörighet till."
    },
    {
      "id": "bevakningar",
      "title": "Mina bevakningar & frister",
      "category": "uppgifter",
      "feature": "uppgifts-/bevakningsmodulen",
      "dataSource": "real",
      "description": "Deadline-sorterad lista med eskaleringsfärg (grå→gul ≤3 dgr→röd förfallen). Verb-inledda titlar, Skapa bevakning från meddelande, påminnelser T-7/T-3/T-0 bara till tilldelad, toggle Mina/Enhetens."
    },
    {
      "id": "bokningsbaraTider",
      "title": "Bokningsbara tider",
      "category": "mote",
      "feature": "kalender (Appointments) + auto-videorum",
      "dataSource": "real",
      "description": "Skapa bokningsbar tid → auto-skapat säkert videorum + BankID/Freja-lobby för externa. Översikt över egna bokningssidor."
    },
    {
      "id": "nytta",
      "title": "Nytta hittills",
      "category": "statistik",
      "feature": "strukturerat register (Tables)",
      "dataSource": "proposed",
      "description": "ROI-räknare: ersatta fax/rek-brev/okrypterad e-post × Diggs schablon ~30 min/ärende → sparad tid; andel SDK vs fax/månad (faxavvecklingskurva). Underlag till nämnd/cybermiljards-äskande."
    },
    {
      "id": "systemhalsa",
      "title": "Systemhälsa & leverans",
      "category": "statistik",
      "feature": "sdkmc driftstatus",
      "dataSource": "real",
      "description": "Accesspunkt/SDK-anslutning upptid, meddelanden i fellager, ej kvitterade leveranser, ej hanterade säkra meddelanden över X dagar (internkontroll)."
    },
    {
      "id": "attSignera",
      "title": "Att signera",
      "category": "signering",
      "feature": "e-signering (Inera Underskriftstjänst-API / Sweden Connect)",
      "dataSource": "proposed",
      "description": "Personlig + funktionsbaserad kö över dokument som väntar på min underskrift, med kravnivå-badge (SES/AES/QES – AES via BankID standard) och deadline. Lågrisk visar Godkänn (loggat) i stället för Signera per SKR:s riskmodell."
    },
    {
      "id": "skickatForSignering",
      "title": "Skickat för signering",
      "category": "signering",
      "feature": "e-signering + säkra meddelanden",
      "dataSource": "proposed",
      "description": "Spegelvy av utgående: Skickat → Öppnat → Signerat av X av Y → Klart/Arkiverat (+ Avvisat / Påminnelse skickad / Utgånget). Per-part-status, Påminn-knapp. Flerpart inkl. externa medborgare via säker länk + BankID."
    },
    {
      "id": "minaUppgifter",
      "title": "Mina uppgifter",
      "category": "uppgifter",
      "feature": "uppgifts-/bevakningsmodulen",
      "dataSource": "real",
      "description": "Mina uppgifter som GOV.UK task-list: personliga att-göra knutna till ärenden, med status och nästa åtgärd. Arbets-/genomförandefokus som komplement till frist-fokuserade bevakningar."
    },
    {
      "id": "arenderum",
      "title": "Mina ärenderum",
      "category": "filer",
      "feature": "säkra filer / ärenderum (Groupfolders + ACL + Retention)",
      "dataSource": "real",
      "description": "Översikt över öppna ärenderum (en säker dokumentyta per dnr/barn/uppdrag): status, olästa dokument, väntar-på-signatur, gallrings-countdown, om medborgardelning är aktiv. Bilagor bor i rummet, kommunikationen refererar dit."
    },
    {
      "id": "senasteFiler",
      "title": "Senaste säkra filer",
      "category": "filer",
      "feature": "säkra filer (versioner)",
      "dataSource": "real",
      "description": "Vad hände med mina dokument senast: delad med medborgare / ny version / väntar på din granskning / signerad / uppladdad av motpart – med ärenderum-kontext och säker-kanal-markering."
    },
    {
      "id": "orosanmalningar",
      "title": "Orosanmälningar – förhandsbedömning",
      "category": "ärende",
      "feature": "uppgifts-/bevakning + Forms + sdkmc-inflöde",
      "dataSource": "proposed",
      "description": "Dedikerad kö för nya anmälningar med 14-dagars countdown per anmälan, status (Ny / Under förhandsbedömning / Beslut inleda / Beslut ej inleda), källa (skola/vård/polis/privat) och kanal. Strip: 3 förhandsbedömningar förfaller denna vecka."
    },
    {
      "id": "utskrivningsbevakning",
      "title": "Utskrivningar att bevaka",
      "category": "ärende",
      "feature": "säkra meddelanden + bevakning (lag 2017:612)",
      "dataSource": "proposed",
      "description": "Deadline-driven kö över inkommande utskrivningsmeddelanden (inskrivningsmeddelande / planering / utskrivningsklar / SIP kallad / hemtagen). Per utskrivningsklar-rad: dygnsräknare mot betalningsansvar + kr-riskindikator (grön <3 dygn-snitt / gul / röd överskjutande)."
    },
    {
      "id": "samverkansavvikelser",
      "title": "Samverkansavvikelser",
      "category": "ärende",
      "feature": "strukturerat register (Tables) + säkra meddelanden",
      "dataSource": "proposed",
      "description": "Avvikelse-i-ett-klick direkt från meddelandet: förifyller patient (ärende-id), motpart (region/enhet), bristtyp (saknad läkemedelslista / för sen underrättelse / uteblivet inskrivningsmeddelande), tidsstämplar; skickas säkert till regionens avvikelsefunktion via SDK."
    },
    {
      "id": "arsrakningar",
      "title": "Årsräkningar – granskningsläget",
      "category": "ärende",
      "feature": "uppgifts-/bevakning (kampanjvy) + Provisum/Aider-integration",
      "dataSource": "proposed",
      "description": "Den deadline-låsta toppen som GOV.UK-kampanj: aggregerad progress 312 av 540 granskade · 18 dagar till 1 mars · 47 saknar verifikat, per-ärende-status, förtursflagga (förstagångsredovisare/tidigare anmärkta)."
    },
    {
      "id": "granskningsko",
      "title": "Granskningskö – nästa att granska",
      "category": "uppgifter",
      "feature": "uppgifts-/bevakning + ärenderum + facksystem",
      "dataSource": "proposed",
      "description": "Plockbar kö över otilldelade/tilldelade redovisningar: ta ansvar, källkanal-ikon (e-tjänst/papper-inskannat/post), saknas-verifikat-markering, Granska nästa-primäråtgärd. Visar ärenden nära 7-mån/FL-frist i rött."
    },
    {
      "id": "uppdragskontroll",
      "title": "Uppdragsöverblick (flaggning)",
      "category": "compliance",
      "feature": "strukturerat register (Tables-regelmotor) + facksystem",
      "dataSource": "proposed",
      "description": "JO-kravet (dec 2025) operationaliserat: flaggar ställföreträdare med ovanligt många uppdrag eller upprepade anmärkningar för fördjupad tillsyn/stickprov."
    },
    {
      "id": "rehabarenden",
      "title": "Rehab- & personalärenden",
      "category": "ärende",
      "feature": "säkra filer / ärenderum + bevakning",
      "dataSource": "proposed",
      "description": "Task-orienterad lista över aktiva personalärenden med rehab-statusflöde (Ny / Pågående / Väntar på motpart / Plan upprättad / Avslutad), deadline-markör, nya dokument, om medarbetardelning/-signatur är aktiv. Avskild från allmän kommunikation."
    },
    {
      "id": "kansligInkorg",
      "title": "Känslig inkorg (rehab & personal)",
      "category": "kommunikation",
      "feature": "säkra meddelanden (kontextfiltrerad)",
      "dataSource": "real",
      "description": "Avskild triagekö för säkra meddelanden/SDK/fax som rör personalärenden, separerad från allmän kommunikation. Kanalikon + oläst/kvittens-status per rad. Aldrig i öppen e-post."
    },
    {
      "id": "fristStrip",
      "title": "Frister denna vecka",
      "category": "uppgifter",
      "feature": "bevakning + deadline-register (Tables)",
      "dataSource": "proposed",
      "description": "Eskaleringsstrip för lagstadgade tider. För HR: dag 8 (läkarintyg), dag 30 (plan för återgång), 60-dagarströskel, avstämningsmöte, intygsförlängning. Grå→gul (≤3 dgr)→röd (förfallen)."
    },
    {
      "id": "mallarSamtycke",
      "title": "Mallar & samtycke",
      "category": "filer",
      "feature": "Forms (internt) + mallbibliotek + e-underskrift",
      "dataSource": "proposed",
      "description": "Snabbstart av återkommande dokument: samtyckesblankett (vårdgivarkontakt), plan för återgång (FK 7459), rehaböverenskommelse, SIP-samtycke, kallelser. Säkert formulär + BankID/signering."
    },
    {
      "id": "registreraFordela",
      "title": "Registrera & fördela",
      "category": "ärende",
      "feature": "diarie-/registreringsstöd",
      "dataSource": "proposed",
      "description": "Card View som öppnar förifyllt registreringsformulär: avsändare, inkommen-datum, föreslaget dnr, ärendemening, sekretessmarkering, tilldela handläggare/nämnd. Stänger gapet meddelande↔diarium (integrerar mot, ersätter inte, diariesystemet)."
    },
    {
      "id": "utlamnande",
      "title": "Lämna ut allmän handling",
      "category": "ärende",
      "feature": "diariesök + utlämnandelogg + säker e-post/SDK",
      "dataSource": "proposed",
      "description": "Diariesök + framställan-kö med sekretessprövnings-checklista, maskering och säkert utskick. Skyndsamt-timer per begäran."
    },
    {
      "id": "namndcykel",
      "title": "Nämndcykeln",
      "category": "ärende",
      "feature": "ärenderum + säker delning + kalender + Tables",
      "dataSource": "proposed",
      "description": "Status för kommande sammanträde som GOV.UK-task-list: ärenden på dagordningen, vilka som saknar komplett underlag, kallelse skickad?, handlingar delade?, protokoll att justera, anslag aktivt, expediering kvar."
    },
    {
      "id": "justeringAnslag",
      "title": "Justering & anslag",
      "category": "signering",
      "feature": "e-underskrift + anslagstavla + säker kanal",
      "dataSource": "proposed",
      "description": "Protokoll som väntar på digital justering (BankID-underskrift av ordförande/justerare); aktiva anslag med laga-kraft-nedräkning (3 v); expediering/delgivning kvar."
    },
    {
      "id": "arkivGallring",
      "title": "Arkiv & leverans",
      "category": "compliance",
      "feature": "säkra filer + Retention + FGS-export",
      "dataSource": "proposed",
      "description": "Avslutade ärenden med gallringsstatus (Gallras 2031 enligt handlingstyp X / Bevaras), notis innan radering, Leverera till e-arkiv (FGS) för Sydarkivera/e-arkiv."
    },
    {
      "id": "complianceStatus",
      "title": "Efterlevnad – cybersäkerhetslagen",
      "category": "compliance",
      "feature": "compliance-modul (härledd ur logg/auth/SDK)",
      "dataSource": "proposed",
      "description": "Sammanvägd grön/gul/röd mot kravområden: anmäld MCF, incidentrutin, logg 12 mån, MFA/LOA3 100 %, data i egen miljö, ledningsgenomgång daterad. Quick View = kravlista mappad mot Infosäkkollen-nivåer (mål ≥ nivå 3)."
    },
    {
      "id": "incidentrapporter",
      "title": "Incidenter & MCF-frister",
      "category": "compliance",
      "feature": "incidenthantering (klock-logik ovanpå händelse-feed)",
      "dataSource": "proposed",
      "description": "Triagekö över öppna säkerhetshändelser/incidenter med nedräkningsklockor (24 h tidig varning / 72 h anmälan / 1 mån läges-/slutrapport) i färg + ej-bara-färg-markör. Verb-knappar Skicka tidig varning, Komplettera anmälan. Förfyller MCF-rapportgenerator."
    },
    {
      "id": "sakerhetshandelser",
      "title": "Säkerhetshändelser",
      "category": "compliance",
      "feature": "sdkmc säkerhetshändelse-feed",
      "dataSource": "real",
      "description": "Aggregerar verksamhetsnära signaler: misslyckade inloggningar, utomgrupps-/avvikande delningar, meddelande till oväntad funktionsadress, inloggning under tröskel-LOA. Knapp Eskalera till incident förfyller incidentrapporter."
    },
    {
      "id": "loggSparbarhet",
      "title": "Loggretention & spårbarhet",
      "category": "compliance",
      "feature": "logg- & spårbarhetspanel (SDK-logg + sökindex)",
      "dataSource": "proposed",
      "description": "SDK-loggretention 12/12 mån, sökbar (Diggs krav, grön bock). Sökruta mot AS4 Message/Conversation ID, avsändare/mottagare, tidpunkt – utan meddelandeinnehåll. Tillsyn + felsökning i ett."
    },
    {
      "id": "authLoa",
      "title": "Autentisering & tillitsnivå",
      "category": "compliance",
      "feature": "ID-core / auth (sessionsdata)",
      "dataSource": "real",
      "description": "Andel sessioner på LOA3 (BankID/Freja/SITHS), MFA-täckning, eIDAS2/EUDI-redo-markör, lista över inloggningar under tröskelnivå. HSLF-FS 2016:40-bevis."
    },
    {
      "id": "provisionering",
      "title": "Användare & funktionsadresser",
      "category": "compliance",
      "feature": "användar-/grupphantering + funktionsadress-admin",
      "dataSource": "real",
      "description": "Provisioneringskö: nya som ska in, avslutade som ska av, vilande/överbehöriga konton, funktionsadressmedlemskap. Verb-knappar Lägg till i funktionsadress, Avetablera (loggas som åtkomsthändelse)."
    },
    {
      "id": "dataSuveranitet",
      "title": "Datasuveränitet",
      "category": "compliance",
      "feature": "compliance-modul (statisk + åtkomstlogg-härledd)",
      "dataSource": "proposed",
      "description": "All data i er driftmiljö · 0 tredjelandsöverföringar · senaste externa åtkomst: ingen. Svar på OSL 10:2a + CLOUD Act-oron. Liten diskret säker-kanal-variant finns i varje persona-vy."
    },
    {
      "id": "kunskapsbank",
      "title": "Kunskapsbank & mallar",
      "category": "filer",
      "feature": "kunskapsbank (Collectives)",
      "dataSource": "real",
      "description": "Genväg till rutiner, BBIC-/rehab-/granskningsmallar, gallringsplaner, samtyckesmallar – on-prem. Minskar kognitiv börda. Låst utanför det konfigurerbara skalet (WCAG 3.2.6 Consistent Help)."
    },
    {
      "id": "identitetsBadge",
      "title": "Identitet & leverans",
      "category": "kommunikation",
      "feature": "ID-core (BankID/Freja completionData)",
      "dataSource": "real",
      "description": "Identitets-badge per motpart: metod + LOA + tidpunkt (Verifierad med BankID · LOA3), varningsläge (Ej verifierad – SMS-kod), ombud (Erik E. företräder Karin K.). Leverantörsoberoende, eIDAS2-redo."
    },
    {
      "id": "todolista",
      "title": "Att göra (socialtjänst)",
      "category": "uppgifter",
      "feature": "uppgifts-/bevakningsmodulen (Deck-backad socialtjänst-todo)",
      "dataSource": "real",
      "description": "Deadline-bärande att-göra-lista för socialtjänsten — inte en lös anteckning, inte en generisk kanban. Personlig + delad utredningslista per barn/dnr med GOV.UK task-list-status (Ny · Påbörjad · Väntar på motpart · Klar + rött Åtgärd krävs), verb-inledda titlar, BBIC-checklista per utredning. Signaturfunktion: 'Skapa bevakning från meddelande' (förifyller titel + frist + ärendereferens). Mellanlagring: fångar inflödet innan det blir ett ärende; vid klarmarkering val 'gallra (personlig notering)' vs 'för till Treserva/Lifecare'. Dubblerar inte facksystemets fristbevakning."
    },
    {
      "id": "motesanteckningar",
      "title": "Mötesanteckningar & AI-sammanfattning (lokal)",
      "category": "mote",
      "feature": "mötestranskribering + lokal AI-sammanfattning (säkert möte → recording → KB-Whisper → llm2)",
      "dataSource": "proposed",
      "description": "Kö över säkra möten med transkribering och AI-sammanfattnings-utkast att granska och godkänna. Flöde: inspelning (recording_consent påtvingat) → efterhands-transkript via KB-Whisper (KBLab, Apache-2.0, svensk-tränad, ~47% lägre WER än large-v3) → lokal LLM (llm2, grön-ratad) föreslår utkast: kort sammanfattning + fattade beslut + åtgärdslista med ansvarig. Human-in-the-loop obligatoriskt — handläggaren redigerar och 'Godkänner' (loggat). Allt lokalt, 0 tredjelandsöverföringar. Bara godkänd text committas till ärendet; rå-WebM + rå-transkript gallras. Sekretessbelagda klientsamtal: dokumenteras, körs inte skarpt än (IMY/SKR/Socialstyrelsen); demobart på internt/nämndberedningsmöte."
    }
  ],
  "primaryActions": [
    {
      "id": "taEmotOrosanmalan",
      "label": "Ta emot & fördela orosanmälan",
      "icon": "InboxArrowDown",
      "feature": "funktionsbrevlador"
    },
    {
      "id": "skapaArenderum",
      "label": "Skapa ärenderum",
      "icon": "FolderLockOpen",
      "feature": "arenderum"
    },
    {
      "id": "skickaSaktMeddelande",
      "label": "Skicka säkert meddelande",
      "icon": "EmailFast",
      "feature": "attHantera"
    },
    {
      "id": "kallaSaktMote",
      "label": "Kalla till säkert möte",
      "icon": "VideoPlus",
      "feature": "bokningsbaraTider"
    },
    {
      "id": "skickaForUnderskrift",
      "label": "Skicka beslut för underskrift",
      "icon": "FileSign",
      "feature": "attSignera"
    },
    {
      "id": "skapaBevakning",
      "label": "Skapa bevakning från meddelande",
      "icon": "BellPlus",
      "feature": "bevakningar"
    },
    {
      "id": "registreraFordelaHandling",
      "label": "Registrera & fördela handling",
      "icon": "FileDocumentEdit",
      "feature": "registreraFordela"
    },
    {
      "id": "lamnaUtHandling",
      "label": "Lämna ut allmän handling",
      "icon": "FileSearch",
      "feature": "utlamnande"
    },
    {
      "id": "byggNamndkallelse",
      "label": "Bygg & skicka nämndkallelse",
      "icon": "CalendarAccount",
      "feature": "namndcykel"
    },
    {
      "id": "justeraExpediera",
      "label": "Justera & expediera beslut",
      "icon": "Gavel",
      "feature": "justeringAnslag"
    },
    {
      "id": "levereraEarkiv",
      "label": "Leverera ärende till e-arkiv",
      "icon": "ArchiveArrowDown",
      "feature": "arkivGallring"
    },
    {
      "id": "kvitteraUtskrivning",
      "label": "Kvittera utskrivningsmeddelande",
      "icon": "CheckDecagram",
      "feature": "utskrivningsbevakning"
    },
    {
      "id": "skapaSamverkansavvikelse",
      "label": "Skapa samverkansavvikelse",
      "icon": "AlertDecagram",
      "feature": "samverkansavvikelser"
    },
    {
      "id": "kallaSip",
      "label": "Kalla till SIP-möte",
      "icon": "AccountGroup",
      "feature": "dagensMoten"
    },
    {
      "id": "skapaRehabrum",
      "label": "Skapa rehab-ärenderum",
      "icon": "FolderHeart",
      "feature": "rehabarenden"
    },
    {
      "id": "bokaRehabmote",
      "label": "Boka & starta säkert rehabmöte",
      "icon": "VideoAccount",
      "feature": "bokningsbaraTider"
    },
    {
      "id": "begarUnderskrift",
      "label": "Begär underskrift",
      "icon": "DrawPen",
      "feature": "attSignera"
    },
    {
      "id": "granskaNastaArsrakning",
      "label": "Granska nästa årsräkning",
      "icon": "FileChart",
      "feature": "granskningsko"
    },
    {
      "id": "begarKomplettering",
      "label": "Begär komplettering",
      "icon": "EmailAlert",
      "feature": "skickatForSignering"
    },
    {
      "id": "signeraDelgeBeslut",
      "label": "Signera & delge beslut",
      "icon": "FileSign",
      "feature": "attSignera"
    },
    {
      "id": "bokaSaktMote",
      "label": "Boka säkert möte",
      "icon": "CalendarClock",
      "feature": "bokningsbaraTider"
    },
    {
      "id": "eskaleraIncident",
      "label": "Eskalera till incident & starta MCF-klockan",
      "icon": "ShieldAlert",
      "feature": "incidentrapporter"
    },
    {
      "id": "genereraMcfRapport",
      "label": "Generera MCF-rapportunderlag",
      "icon": "FileExport",
      "feature": "incidentrapporter"
    },
    {
      "id": "sokSdkLogg",
      "label": "Sök i SDK-loggen / exportera åtkomstlogg",
      "icon": "DatabaseSearch",
      "feature": "loggSparbarhet"
    },
    {
      "id": "provisioneraAnvandare",
      "label": "Provisionera / avetablera användare",
      "icon": "AccountKey",
      "feature": "provisionering"
    },
    {
      "id": "sammanstallNytta",
      "label": "Sammanställ nytta & efterlevnad för ledningen",
      "icon": "ChartBoxOutline",
      "feature": "nytta"
    },
    {
      "id": "laggTillUppgift",
      "label": "Lägg till uppgift",
      "icon": "PlaylistPlus",
      "feature": "todolista"
    },
    {
      "id": "skapaBevakningTodo",
      "label": "Skapa bevakning från meddelande",
      "icon": "BellPlus",
      "feature": "todolista"
    },
    {
      "id": "startaTranskribering",
      "label": "Transkribera & sammanfatta möte (lokalt)",
      "icon": "TextBoxOutline",
      "feature": "motesanteckningar"
    },
    {
      "id": "godkannSparaAnteckning",
      "label": "Godkänn & spara till ärende",
      "icon": "CheckCircleOutline",
      "feature": "motesanteckningar"
    }
  ],
  "proposedApps": [
    {
      "id": "sakraMeddelanden",
      "name": "Säkra meddelanden (SDK + säker e-post + digital fax)",
      "status": "befintlig",
      "rationale": "Kärnkanalen. Digg: SDK ersätter fax/rek-brev/bud/telefon → ~1 620 mnkr/år, ~3 500 årsarbetskrafter, ~30 min/ärende. SKR: 2026 = standardrutin. HSLF-FS 2016:40 kräver krypterad, mottagarverifierad kanal."
    },
    {
      "id": "funktionsadresser",
      "name": "Funktionsadresser (delade verksamhetsbrevlådor)",
      "status": "befintlig",
      "rationale": "SKR:s 2025-rekommendation gör delad brevlåda (orosanmalan@, hemsjukvard@) till förstaklassobjekt och teamets kärnvy med plocka/fördela."
    },
    {
      "id": "uppgiftsBevakning",
      "name": "Uppgifts-/bevakningsmodulen",
      "status": "föreslagen",
      "rationale": "Befintlig bas (Deck/Tasks) + föreslagen widgetlogik. Skapa bevakning från meddelande (meddelande→uppgift→ärende→påminnelse) är differentieraren ingen vertikal (Provisum/Aider) eller generisk (Planner/Trello) löser i samma sekretessäkra miljö. Bygg påminnelse-före-deadline + avisering bara till tilldelad (Deck-luckor #1549/#566)."
    },
    {
      "id": "sakraFilerArenderum",
      "name": "Säkra filer / ärenderum",
      "status": "föreslagen",
      "rationale": "Befintlig bas (Groupfolders/ACL/versioner/Retention/Collabora-OnlyOffice) + föreslagen orkestrering. Ett ärenderum per dnr binder ihop meddelanden, video och filer. On-prem eliminerar OSL 10:2a-bedömningen (eSam ES2023-06). Arkivförordningen 2024 (export+radering före införande) → FGS-export + Retention. Mot Storegate (SaaS) och M365 (CLOUD Act)."
    },
    {
      "id": "eUnderskrift",
      "name": "E-underskrift (avancerad e-underskrift)",
      "status": "föreslagen",
      "rationale": "Kö/spårning/bevarande ovanpå Inera Underskriftstjänst-API eller egen Sweden Connect-nod (Digg open source). SKR-vägledning dec 2025: riskbaserad nivåval (SES internt / AES arbetshäst / QES bara där lag kräver) + Godkänn vs Signera. Bevarande (PAdES/PDF/A/LTV, Giltig nu/Giltig då) är gapet ingen säljer bra. Bygg inte kryptokärnan; mot Scrive/Assently/Visma (moln → OSL/CLOUD Act)."
    },
    {
      "id": "kalenderSaktMote",
      "name": "Kalender-bokning + säkert videomöte",
      "status": "befintlig",
      "rationale": "calendar (Appointments) + spreed-itsl med auto-videorum + BankID/Freja-lobby. Löser gapet att Region Uppsala 2022 valde Skype som säkraste plattformen för SIP-video."
    },
    {
      "id": "sakertFormular",
      "name": "Säkert formulär / samtycke (Forms, internt)",
      "status": "föreslagen",
      "rationale": "SIP-/rehab-samtycke och internt anmälnings-/avvikelseformulär. Konkurrera inte med publik orosanmälan-e-tjänst (Open ePlatform/Abou) – Forms saknar native filuppladdning + förgrening. Lös signerings-/fildropp-gapet före demo."
    },
    {
      "id": "strukturreratRegister",
      "name": "Strukturerat register (Tables, osynlig motor)",
      "status": "föreslagen",
      "rationale": "Backend för triage-status, deadline-/nytto-/avvikelse-/incidentregister, uppdragskontroll-flaggning. Renderas som Hubs-widgets, aldrig rå tabell."
    },
    {
      "id": "diarieRegistreringsstod",
      "name": "Diarie-/registreringsstöd",
      "status": "föreslagen",
      "rationale": "Kärndifferentiator för registrator. Förifylld registrering (avsändare/datum/dnr/ärendemening/sekretess) + tilldelning, integrerar mot – ersätter inte – W3D3/Public360/Ciceron/Lifecare. Stänger gapet meddelande↔diarium. OSL 5:1–2 + JO (registrering senast nästa arbetsdag)."
    },
    {
      "id": "namndsammantradesmodul",
      "name": "Nämnd-/sammanträdesmodul",
      "status": "föreslagen",
      "rationale": "Kallelse, beslutsunderlag, protokoll, digital justering (BankID), anslag (laga-kraft-klocka), expediering/delgivning. Gör nämndsekreterar-rollen komplett. Kommunallag 2017:725; eIDAS art. 25."
    },
    {
      "id": "complianceNis2Modul",
      "name": "Compliance-/NIS2-modul",
      "status": "föreslagen",
      "rationale": "Kärnan för forvaltare. Cybersäkerhetslagen 2025:1506 (i kraft 15 jan 2026, alla kommuner/regioner); ledningens personliga ansvar; MCF-rapportkedja 24h/72h/1 mån; SDK-logg 12 mån; cybermiljarden 200/50 mkr/år. Differentiering mot Secify/Secure State Cyber/Purview: operativ kommunikationsdata → automatisk efterlevnadsbild. Mappa mot Infosäkkollen (31 %/69 %-luckan)."
    },
    {
      "id": "idCore",
      "name": "ID-core (leverantörsoberoende identitet)",
      "status": "föreslagen",
      "rationale": "Befintlig bas (BankID/Freja/SITHS) + föreslagen abstraktion (Sverige-id dec 2026 + EUDI-plånbok 2026/27 inkopplingsbara). Identitets-badge, leveranstidslinje, väntrums-kort. SMS-OTP som märkt nödutgång (NIST restricted), spärrad för LOA3. Ombud för ~5–10 % utan eID. eIDAS2-redo = upphandlingsargument."
    },
    {
      "id": "lokalAiPrioritering",
      "name": "Lokal AI-prioritering",
      "status": "föreslagen",
      "rationale": "Valfri & avstängbar, endast lokala grön-ratade modeller (llm2, t.ex. OLMo 2). Triage-stöd vid hög volym (514k orosanmälningar/år). Transparent (varför), aldrig destruktiv, prioriterar ärendeegenskaper inte användarbeteende (GDPR art. 22). AI utan att data lämnar er server."
    },
    {
      "id": "kunskapsbankCollectives",
      "name": "Kunskapsbank (Collectives)",
      "status": "befintlig",
      "rationale": "Rutiner, mallar, gallringsplaner on-prem – en ingång, inte system nummer åtta (Arbetsmiljöverket/Suntarbetsliv)."
    },
    {
      "id": "minaMeddelandenKoppling",
      "name": "Mina meddelanden-koppling (utkanal)",
      "status": "föreslagen",
      "rationale": "Dialog ≠ massutskick: positionera Hubs som tvåvägsdialogen, bygg på sikt skicka beslut till digital brevlåda från handläggarvyn (SOU 2024:47 sannolikt obligatoriskt). Konkurrera inte med Kivra om massutskick."
    }
  ],
  "personas": [
    {
      "id": "socialsekreterare",
      "label": "Socialsekreterare (barn & familj)",
      "role": "Myndighetsutövare barn & familj (SoL 2025:400, BBIC); härleds från grupp socialtjanst",
      "tagline": "Triage av orosanmälningar och säkra ärenden – ingen frist i huvudet, inget barn mellan stolarna.",
      "primaryActionIds": [
        "taEmotOrosanmalan",
        "skapaArenderum",
        "skickaSaktMeddelande",
        "kallaSaktMote",
        "skickaForUnderskrift"
      ],
      "layout": {
        "main": [
          "attHantera",
          "orosanmalningar",
          "bevakningar",
          "arenderum",
          "attSignera",
          "todolista"
        ],
        "side": [
          "dagensMoten",
          "kvittenser",
          "funktionsbrevlador",
          "minaUppgifter",
          "senasteFiler",
          "kunskapsbank",
          "motesanteckningar"
        ]
      },
      "kpis": [
        "Andel förhandsbedömningar avslutade inom 14 dagar",
        "Andel utredningar klara inom 4 månader",
        "Röda (förfallna) bevakningar – mål 0",
        "Median tid-till-fördelat för ny orosanmälan",
        "Andel utskick med läskvittens",
        "Andel beslut e-signerade vs utskrivet/postat"
      ]
    },
    {
      "id": "registrator",
      "label": "Registrator / nämndsekreterare",
      "role": "Spindeln i informationsflödet – registrerar, fördelar, bygger nämndhandlingar, justerar, anslår, expedierar; härleds från funktionsadress-grupp registrator",
      "tagline": "Allt inkommande registrerat i tid, fördelat och spårbart – hela beslutskedjan digital och kvitterad.",
      "primaryActionIds": [
        "registreraFordelaHandling",
        "lamnaUtHandling",
        "byggNamndkallelse",
        "justeraExpediera",
        "levereraEarkiv"
      ],
      "layout": {
        "main": [
          "attHantera",
          "registreraFordela",
          "funktionsbrevlador",
          "namndcykel",
          "justeringAnslag"
        ],
        "side": [
          "kvittenser",
          "bevakningar",
          "utlamnande",
          "arkivGallring",
          "dataSuveranitet",
          "nytta",
          "motesanteckningar"
        ]
      },
      "kpis": [
        "Tid till registrering (mål: senast nästa arbetsdag)",
        "Oregistrerat över 1 arbetsdag",
        "Otilldelade i funktionskön",
        "Leverans-/läskvittensgrad",
        "Felskickat undvikt (verifierad funktionsadress vs faxnummer)",
        "Nämndcykel-genomströmning (komplett underlag vid kallelse)",
        "Gallrings-/FGS-leveransstatus"
      ]
    },
    {
      "id": "hsl_skoterska",
      "label": "Kommunsjuksköterska (HSL)",
      "role": "Kommunal HSL/hemsjukvård – bevakar utskrivning (lag 2017:612), SIP, betalningsansvar, avvikelser; härleds från grupp kommunal-hsl",
      "tagline": "Aldrig en missad utskrivningsklar – säker kanal och bevakning runt hela utskrivningen.",
      "primaryActionIds": [
        "kvitteraUtskrivning",
        "skapaSamverkansavvikelse",
        "kallaSip",
        "skickaSaktMeddelande",
        "skapaBevakning"
      ],
      "layout": {
        "main": [
          "attHantera",
          "utskrivningsbevakning",
          "samverkansavvikelser",
          "bevakningar",
          "arenderum"
        ],
        "side": [
          "dagensMoten",
          "funktionsbrevlador",
          "kvittenser",
          "minaUppgifter",
          "senasteFiler",
          "kunskapsbank",
          "motesanteckningar"
        ]
      },
      "kpis": [
        "Dygn över betalningsansvarsgräns denna månad (kr-exponering)",
        "Antal utskrivningsklar-meddelanden obekräftade",
        "Antal samverkansavvikelser + trend",
        "Obesvarade säkra meddelanden från region > X dagar",
        "Andel meddelanden i säker kanal vs fax",
        "Tid-till-kvittens på inkommande"
      ]
    },
    {
      "id": "hr_chef",
      "label": "HR / chef – rehab & känsliga personalärenden",
      "role": "HR-partner/enhetschef med personalansvar – hälsodata, läkarintyg, rehab, FK-kontakter, anställning; härleds från grupp hr",
      "tagline": "En avskild, sekretessmärkt yta för rehab och personalärenden – rätt frist, rätt kanal, aldrig öppen e-post.",
      "primaryActionIds": [
        "skapaRehabrum",
        "skickaSaktMeddelande",
        "bokaRehabmote",
        "begarUnderskrift",
        "skapaBevakning"
      ],
      "layout": {
        "main": [
          "kansligInkorg",
          "fristStrip",
          "rehabarenden",
          "attSignera",
          "bevakningar"
        ],
        "side": [
          "dagensMoten",
          "skickatForSignering",
          "mallarSamtycke",
          "kvittenser",
          "senasteFiler",
          "kunskapsbank",
          "nytta",
          "motesanteckningar",
          "todolista"
        ]
      },
      "kpis": [
        "Andel rehab-/personalkommunikation i säker kanal (vs öppen e-post)",
        "Plan för återgång upprättad i tid (senast dag 30)",
        "Aktiva rehabärenden per status",
        "Frister/uppföljningar som förfaller denna vecka",
        "Dokument som väntar på signatur / signerade i tid",
        "Ej kvitterade utgående delgivningar"
      ]
    },
    {
      "id": "overformyndare",
      "label": "Överförmyndarhandläggare",
      "role": "Granskar ställföreträdares redovisningar, fattar beslut, utövar tillsyn; deadline-drivet årshjul med topp 1 mars; facksystem Provisum/Aider; härleds från grupp overformyndare",
      "tagline": "Granskningskön i takt mot 1 mars – komplettering, beslut och e-underskrift utan att känsliga uppgifter lämnar er server.",
      "primaryActionIds": [
        "granskaNastaArsrakning",
        "begarKomplettering",
        "signeraDelgeBeslut",
        "bokaSaktMote",
        "skapaBevakning"
      ],
      "layout": {
        "main": [
          "arsrakningar",
          "granskningsko",
          "attSignera",
          "skickatForSignering",
          "bevakningar"
        ],
        "side": [
          "funktionsbrevlador",
          "arenderum",
          "dagensMoten",
          "uppdragskontroll",
          "kvittenser",
          "kunskapsbank",
          "nytta",
          "motesanteckningar"
        ]
      },
      "kpis": [
        "Andel årsräkningar färdiggranskade (mål 80 % per 30 juni)",
        "Granskningskö: ej påbörjade / under granskning / väntar på komplettering",
        "Dagar till 1 mars + antal som saknar verifikat",
        "Ärenden över fristgränsen (7 mån / FL 6 mån)",
        "Dokument som väntar på min e-underskrift + utskickade som väntar på motpartens",
        "Andel digital vs pappersredovisning",
        "Ställföreträdare med ovanligt många uppdrag (flaggning)"
      ]
    },
    {
      "id": "forvaltare",
      "label": "Förvaltare / IT / informationssäkerhet",
      "role": "CISO/IS-samordnare/systemförvaltare – compliance, incidenter, provisionering, gallring, logg, ROI uppåt; härleds från grupp forvaltning/infosak/it-drift/ledning",
      "tagline": "Är vi säkra nu? Kan vi bevisa att vi följer lagen? Är det värt pengarna? – svar i den ordningen, utan sju system.",
      "primaryActionIds": [
        "eskaleraIncident",
        "genereraMcfRapport",
        "sokSdkLogg",
        "provisioneraAnvandare",
        "sammanstallNytta"
      ],
      "layout": {
        "main": [
          "complianceStatus",
          "incidentrapporter",
          "sakerhetshandelser",
          "loggSparbarhet",
          "authLoa"
        ],
        "side": [
          "systemhalsa",
          "provisionering",
          "arkivGallring",
          "dataSuveranitet",
          "nytta"
        ]
      },
      "kpis": [
        "Compliance-status (sammanvägd, mål grön; mappad mot Infosäkkollen ≥ nivå 3)",
        "Öppna säkerhetshändelser → incidenter + andel MCF-deadlines hållna (0 missade)",
        "SDK-loggretention 12/12 mån sökbar",
        "Andel sessioner LOA3 + MFA-täckning 100 %",
        "Systemhälsa/upptid + ej hanterade meddelanden > X dagar",
        "Tredjelandsöverföringar (mål 0)",
        "Nytta/ROI (ersatta fax/brev × ~30 min) + provisioneringshygien"
      ]
    }
  ]
}
