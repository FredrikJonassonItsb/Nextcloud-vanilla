/**
 * Hubs Start — DEMO FIXTURE DATA (queues, batch A).
 *
 * ⚠️ STUB / DEMO ONLY. Static descriptors for the persona-dashboard demo, rendered
 * through the flexible `queue` variant component (see docs/DEMO-WIDGETS-CONTRACT.md).
 * Plain JS object literals — no imports, no Vue, no functions, no Date.now.
 *
 * Content is in Swedish, anonymised (dnr/case-ids + role labels, never real PII),
 * grounded in the persona/research files under analysis-output/extended/. Brand rule:
 * never the words "Nextcloud" or "Talk" — we say säkra meddelanden / säkert möte /
 * ärenderum / bevakning. These are DATA values; the components translate/label them.
 *
 * Widgets covered (id → variant):
 *   orosanmalningar        — queue (14-dagars förhandsbedömning-countdown, källa skola/vård/polis)
 *   utskrivningsbevakning  — queue (dygnsräknare mot betalningsansvar + kr-riskindikator)
 *   samverkansavvikelser   — queue
 *   granskningsko          — queue (plockbar redovisningskö, saknas-verifikat)
 *   rehabarenden           — queue (status Ny/Pågående/Väntar/Plan upprättad/Avslutad)
 *   kansligInkorg          — queue (avskild rehab/personal-triage med channel-chips)
 *   minaUppgifter          — queue (GOV.UK task-list)
 */

export default {
	// ── Socialsekreterare ────────────────────────────────────────────────────────
	// Dedikerad kö för nya orosanmälningar. Förhandsbedömning: max 14 dagar från det
	// att anmälan inkommit till beslut att inleda/inte inleda utredning.
	orosanmalningar: {
		variant: 'queue',
		emptyText: 'Inga orosanmälningar väntar på förhandsbedömning',
		headerStat: { label: 'Förfaller denna vecka', value: '3 förhandsbedömningar', tone: 'warning' },
		rows: [
			{
				id: 'oa1',
				title: 'Gör förhandsbedömning — anmälan från skola',
				subtitle: 'Funktionsbrevlåda orosanmalan@kommunen · inkom idag 08:12',
				status: { label: 'Ny', tone: 'info' },
				deadline: { label: '14 dagar kvar', tone: 'neutral' },
				badges: [
					{ label: 'Källa: skola', tone: 'info', icon: 'School' },
					{ label: 'LOA3', tone: 'success', icon: 'ShieldCheck' },
				],
				channel: 'sdk',
				primaryAction: { label: 'Plocka ärendet' },
			},
			{
				id: 'oa2',
				title: 'Gör förhandsbedömning — anmälan från hälso- och sjukvård',
				subtitle: 'Dnr SN 2026-0148 · inkom igår',
				status: { label: 'Under förhandsbedömning', tone: 'neutral' },
				deadline: { label: '6 dagar kvar', tone: 'warning' },
				badges: [{ label: 'Källa: vård', tone: 'info', icon: 'MedicalBag' }],
				channel: 'secure',
				primaryAction: { label: 'Öppna ärenderum' },
			},
			{
				id: 'oa3',
				title: 'Gör förhandsbedömning — anmälan från polis',
				subtitle: 'Dnr SN 2026-0151 · inkom 11 juni',
				status: { label: 'Under förhandsbedömning', tone: 'neutral' },
				deadline: { label: '2 dagar kvar', tone: 'error' },
				badges: [
					{ label: 'Källa: polis', tone: 'warning', icon: 'PoliceBadge' },
					{ label: 'Förtur', tone: 'warning', icon: 'AlertCircle' },
				],
				channel: 'sdk',
				primaryAction: { label: 'Granska & besluta' },
			},
			{
				id: 'oa4',
				title: 'Besluta att inleda/inte inleda utredning',
				subtitle: 'Dnr SN 2026-0139 · förhandsbedömning klar',
				status: { label: 'Klar för beslut', tone: 'success' },
				deadline: { label: '4 dagar kvar', tone: 'warning' },
				badges: [{ label: 'Källa: privat anmälare', tone: 'neutral', icon: 'AccountQuestion' }],
				channel: 'fax',
				primaryAction: { label: 'Fatta beslut' },
			},
			{
				id: 'oa5',
				title: 'Gör förhandsbedömning — anmälan från skola',
				subtitle: 'Dnr SN 2026-0153 · inkom 09 juni',
				status: { label: 'Under förhandsbedömning', tone: 'neutral' },
				deadline: { label: '9 dagar kvar', tone: 'neutral' },
				badges: [{ label: 'Källa: skola', tone: 'info', icon: 'School' }],
				channel: 'sdk',
				primaryAction: { label: 'Öppna ärenderum' },
			},
			{
				id: 'oa6',
				title: 'Fördela otilldelad anmälan — funktionsbrevlåda',
				subtitle: 'Otilldelad i 4 tim · syns för hela enheten',
				status: { label: 'Otilldelad', tone: 'warning' },
				deadline: { label: '13 dagar kvar', tone: 'neutral' },
				badges: [{ label: 'Källa: vård', tone: 'info', icon: 'MedicalBag' }],
				channel: 'secure',
				primaryAction: { label: 'Ta ansvar' },
			},
		],
	},

	// ── Kommunsjuksköterska (HSL) ────────────────────────────────────────────────
	// Utskrivningar att bevaka. För "utskrivningsklar"-rader: dygn sedan utskrivningsklar
	// + kr-riskindikator mot regionens genomsnittsmodell (3 kalenderdagar; belopp per
	// vårddygn enligt HSLF-FS 2025:74 för 2026). Röd = överskjutande dygn = kostar pengar.
	utskrivningsbevakning: {
		variant: 'queue',
		emptyText: 'Inga utskrivningar att bevaka',
		headerStat: { label: 'Kr-exponering denna månad', value: '1 patient över gräns', tone: 'error' },
		rows: [
			{
				id: 'ub1',
				title: 'Ta hem utskrivningsklar patient — dygn 4',
				subtitle: 'Ärende UTS-2026-0631 · slutenvård medicinklinik',
				status: { label: 'Utskrivningsklar', tone: 'error' },
				deadline: { label: 'Dygn 4 · 1 dygn över gräns (~11 200 kr)', tone: 'error' },
				badges: [{ label: 'Betalningsansvar', tone: 'error', icon: 'CashRemove' }],
				channel: 'sdk',
				primaryAction: { label: 'Ordna hemtagning' },
			},
			{
				id: 'ub2',
				title: 'Ta hem utskrivningsklar patient — dygn 2',
				subtitle: 'Ärende UTS-2026-0628 · slutenvård geriatrik',
				status: { label: 'Utskrivningsklar', tone: 'warning' },
				deadline: { label: 'Dygn 2 · nära gräns', tone: 'warning' },
				badges: [{ label: 'Inom genomsnitt', tone: 'success', icon: 'CashCheck' }],
				channel: 'sdk',
				primaryAction: { label: 'Bekräfta hemtagning' },
			},
			{
				id: 'ub3',
				title: 'Planera inför utskrivning — ordna hemsjukvård',
				subtitle: 'Ärende UTS-2026-0634 · prel. utskrivning 16 juni',
				status: { label: 'Planering pågår', tone: 'neutral' },
				deadline: { label: 'Dygn 0 · inom snitt', tone: 'success' },
				badges: [{ label: 'Hjälpmedel saknas', tone: 'warning', icon: 'WheelchairAccessibility' }],
				channel: 'fax',
				primaryAction: { label: 'Öppna planeringsunderlag' },
			},
			{
				id: 'ub4',
				title: 'Bekräfta nytt inskrivningsmeddelande (inom 24 tim)',
				subtitle: 'Ärende UTS-2026-0637 · inkom 07:55 idag',
				status: { label: 'Nytt inskrivningsmeddelande', tone: 'info' },
				deadline: { label: 'Kvittera inom 24 tim', tone: 'warning' },
				badges: [{ label: 'LOA3 / SITHS', tone: 'success', icon: 'ShieldCheck' }],
				channel: 'sdk',
				primaryAction: { label: 'Kvittera' },
			},
			{
				id: 'ub5',
				title: 'Kalla till SIP inför hemgång',
				subtitle: 'Ärende UTS-2026-0625 · fast vårdkontakt utsedd',
				status: { label: 'SIP kallad', tone: 'neutral' },
				deadline: { label: 'Möte bokat 15 juni', tone: 'neutral' },
				badges: [{ label: 'Anhörig i lobby', tone: 'info', icon: 'AccountGroup' }],
				channel: 'secure',
				primaryAction: { label: 'Öppna mötesrum' },
			},
			{
				id: 'ub6',
				title: 'Begär saknad läkemedelslista från region',
				subtitle: 'Ärende UTS-2026-0640 · komplettering behövs',
				status: { label: 'Väntar på motpart', tone: 'warning' },
				deadline: { label: 'Svar väntas idag', tone: 'warning' },
				badges: [{ label: 'Komplettering', tone: 'warning', icon: 'FileAlert' }],
				channel: 'fax',
				primaryAction: { label: 'Skicka säkert meddelande' },
			},
		],
	},

	// ── Kommunsjuksköterska / MAS ────────────────────────────────────────────────
	// Samverkansavvikelser: brister i informationsöverföring vid vårdens övergångar
	// ska avvikelserapporteras (PSL 2010:659). Skapas i ett klick från meddelandet,
	// följs upp för trender av MAS.
	samverkansavvikelser: {
		variant: 'queue',
		emptyText: 'Inga öppna samverkansavvikelser',
		headerStat: { label: 'Öppna avvikelser', value: '5 mot region', tone: 'warning' },
		rows: [
			{
				id: 'sa1',
				title: 'Utred avvikelse — uteblivet inskrivningsmeddelande',
				subtitle: 'AVV-2026-0094 · motpart: slutenvård medicinklinik',
				status: { label: 'Ny', tone: 'info' },
				deadline: { label: 'Återkoppling 5 dagar', tone: 'neutral' },
				badges: [{ label: 'Bristtyp: för sen underrättelse', tone: 'warning', icon: 'ClockAlert' }],
				channel: 'sdk',
				primaryAction: { label: 'Skicka till regionens avvikelsefunktion' },
			},
			{
				id: 'sa2',
				title: 'Utred avvikelse — saknad läkemedelslista vid hemgång',
				subtitle: 'AVV-2026-0091 · motpart: geriatrik',
				status: { label: 'Väntar på motpart', tone: 'warning' },
				deadline: { label: 'Svar väntas 14 juni', tone: 'warning' },
				badges: [{ label: 'Patientsäkerhet', tone: 'error', icon: 'AlertOctagon' }],
				channel: 'secure',
				primaryAction: { label: 'Påminn motpart' },
			},
			{
				id: 'sa3',
				title: 'Utred avvikelse — felaktig info i utskrivningsunderlag',
				subtitle: 'AVV-2026-0088 · motpart: ortopedklinik',
				status: { label: 'Pågående', tone: 'neutral' },
				deadline: { label: 'Återkoppling 8 dagar', tone: 'neutral' },
				badges: [{ label: 'Bristtyp: felaktig info', tone: 'warning', icon: 'FileAlert' }],
				channel: 'sdk',
				primaryAction: { label: 'Komplettera utredning' },
			},
			{
				id: 'sa4',
				title: 'Följ upp avvikelsetrend — kvartalssammanställning',
				subtitle: 'AVV-trend Q2 · MAS patientsäkerhetsarbete',
				status: { label: 'Pågående', tone: 'neutral' },
				deadline: { label: 'Klar 30 juni', tone: 'neutral' },
				badges: [{ label: 'MAS-uppföljning', tone: 'info', icon: 'ChartLine' }],
				primaryAction: { label: 'Öppna sammanställning' },
			},
			{
				id: 'sa5',
				title: 'Stäng avvikelse — åtgärd vidtagen',
				subtitle: 'AVV-2026-0079 · motpart: primärvård',
				status: { label: 'Klar för avslut', tone: 'success' },
				deadline: { label: 'Klarmarkera idag', tone: 'neutral' },
				badges: [{ label: 'Åtgärdad', tone: 'success', icon: 'CheckDecagram' }],
				channel: 'secure',
				primaryAction: { label: 'Klarmarkera' },
			},
		],
	},

	// ── Överförmyndarhandläggare ─────────────────────────────────────────────────
	// Granskningskö: plockbar kö över otilldelade/tilldelade redovisningar (årsräkningar).
	// Källkanal-ikon (e-tjänst / inskannat papper / post), saknas-verifikat-markering,
	// röd markering nära 7-mån/FL-frist. "Granska nästa" som primäråtgärd.
	granskningsko: {
		variant: 'queue',
		emptyText: 'Inga redovisningar väntar på granskning',
		headerStat: { label: 'Kvar att granska', value: '228 årsräkningar', tone: 'warning' },
		rows: [
			{
				id: 'gk1',
				title: 'Granska nästa årsräkning — huvudman HM-0412',
				subtitle: 'Otilldelad · inkom via e-tjänst · förstagångsredovisare',
				status: { label: 'Inkommen', tone: 'info' },
				deadline: { label: 'Förtur', tone: 'warning' },
				badges: [
					{ label: 'Förtur', tone: 'warning', icon: 'StarOutline' },
					{ label: 'E-tjänst', tone: 'info', icon: 'Web' },
				],
				channel: 'sdk',
				primaryAction: { label: 'Ta ansvar & granska' },
			},
			{
				id: 'gk2',
				title: 'Granska årsräkning — huvudman HM-0388',
				subtitle: 'Tilldelad mig · 2 verifikat saknas',
				status: { label: 'Under granskning', tone: 'neutral' },
				deadline: { label: 'Saknar verifikat', tone: 'warning' },
				badges: [{ label: 'Saknas: 2 verifikat', tone: 'warning', icon: 'FileAlert' }],
				channel: 'secure',
				primaryAction: { label: 'Begär komplettering' },
			},
			{
				id: 'gk3',
				title: 'Granska årsräkning — huvudman HM-0357',
				subtitle: 'Inskannat papper · närmar sig 7-månadersfrist',
				status: { label: 'Under granskning', tone: 'neutral' },
				deadline: { label: 'Frist om 12 dagar', tone: 'error' },
				badges: [{ label: 'Papper (inskannat)', tone: 'neutral', icon: 'Scanner' }],
				primaryAction: { label: 'Prioritera granskning' },
			},
			{
				id: 'gk4',
				title: 'Granska sluträkning — huvudman HM-0301',
				subtitle: 'Tilldelad mig · uppdraget upphört',
				status: { label: 'Under granskning', tone: 'neutral' },
				deadline: { label: 'Frist om 21 dagar', tone: 'warning' },
				badges: [{ label: 'Sluträkning', tone: 'info', icon: 'FileDocumentRemove' }],
				channel: 'secure',
				primaryAction: { label: 'Granska' },
			},
			{
				id: 'gk5',
				title: 'Granska nästa årsräkning — huvudman HM-0420',
				subtitle: 'Otilldelad · post · tidigare anmärkt',
				status: { label: 'Inkommen', tone: 'info' },
				deadline: { label: 'Förtur', tone: 'warning' },
				badges: [
					{ label: 'Tidigare anmärkt', tone: 'warning', icon: 'AlertCircle' },
					{ label: 'Post', tone: 'neutral', icon: 'EmailOutline' },
				],
				primaryAction: { label: 'Ta ansvar & granska' },
			},
			{
				id: 'gk6',
				title: 'Klarmarkera & skriv arvodesbeslut — huvudman HM-0344',
				subtitle: 'Granskning klar · klar för arvode',
				status: { label: 'Klar för arvode', tone: 'success' },
				deadline: { label: 'Skicka för underskrift', tone: 'neutral' },
				badges: [{ label: 'Inga anmärkningar', tone: 'success', icon: 'CheckDecagram' }],
				primaryAction: { label: 'Skapa arvodesbeslut' },
			},
		],
	},

	// ── HR / chef ────────────────────────────────────────────────────────────────
	// Rehab- & personalärenden med fast statusuppsättning (Ny / Pågående / Väntar på
	// motpart / Plan upprättad / Avslutad). Dataminimering: titlar är ärendereferens/
	// roll-token, aldrig klartext-diagnos eller medarbetarnamn.
	rehabarenden: {
		variant: 'queue',
		emptyText: 'Inga aktiva rehab- eller personalärenden',
		headerStat: { label: 'Aktiva ärenden', value: '6 · 1 väntar på mig', tone: 'info' },
		rows: [
			{
				id: 'ra1',
				title: 'Upprätta plan för återgång i arbete (FK 7459)',
				subtitle: 'Rehab-rum REH-2026-017 · medarbetare passerar dag 30',
				status: { label: 'Ny', tone: 'info' },
				deadline: { label: 'Senast dag 30 — 3 dagar kvar', tone: 'error' },
				badges: [{ label: 'Dag 30-tröskel', tone: 'error', icon: 'CalendarAlert' }],
				channel: 'secure',
				primaryAction: { label: 'Starta mallen' },
			},
			{
				id: 'ra2',
				title: 'Begär förlängt läkarintyg',
				subtitle: 'Rehab-rum REH-2026-014 · intyg går ut om 3 dagar',
				status: { label: 'Pågående', tone: 'neutral' },
				deadline: { label: '3 dagar kvar', tone: 'warning' },
				badges: [{ label: 'Läkarintyg', tone: 'info', icon: 'FileDocumentOutline' }],
				channel: 'secure',
				primaryAction: { label: 'Skicka säkert meddelande' },
			},
			{
				id: 'ra3',
				title: 'Avvakta svar från företagshälsovård',
				subtitle: 'Rehab-rum REH-2026-011 · avstämningsmöte föreslaget',
				status: { label: 'Väntar på motpart', tone: 'warning' },
				deadline: { label: 'Svar väntas 16 juni', tone: 'neutral' },
				badges: [{ label: 'Företagshälsovård', tone: 'info', icon: 'MedicalBag' }],
				channel: 'sdk',
				primaryAction: { label: 'Påminn motpart' },
			},
			{
				id: 'ra4',
				title: 'Skicka rehaböverenskommelse för underskrift',
				subtitle: 'Rehab-rum REH-2026-009 · arbetsanpassning klar',
				status: { label: 'Plan upprättad', tone: 'success' },
				deadline: { label: 'Signera & delge', tone: 'neutral' },
				badges: [{ label: 'AES / BankID', tone: 'success', icon: 'FileSign' }],
				channel: 'secure',
				primaryAction: { label: 'Begär underskrift' },
			},
			{
				id: 'ra5',
				title: 'Sätt nästa uppföljningsdatum',
				subtitle: 'Rehab-rum REH-2026-006 · plan upprättad och delgiven',
				status: { label: 'Plan upprättad', tone: 'success' },
				deadline: { label: 'Uppföljning 1 juli', tone: 'neutral' },
				badges: [{ label: 'Bevakning aktiv', tone: 'info', icon: 'BellRing' }],
				primaryAction: { label: 'Skapa bevakning' },
			},
			{
				id: 'ra6',
				title: 'Avsluta och arkivera personalärende',
				subtitle: 'Personalakt PA-2026-022 · återgång genomförd',
				status: { label: 'Avslutad', tone: 'neutral' },
				deadline: { label: 'Gallras enligt plan', tone: 'neutral' },
				badges: [{ label: 'Arkivklart', tone: 'success', icon: 'ArchiveCheck' }],
				primaryAction: { label: 'Bekräfta avslut' },
			},
		],
	},

	// ── HR / chef ────────────────────────────────────────────────────────────────
	// Känslig inkorg (rehab & personal): avskild sekretess-triage, separerad från
	// allmän kommunikation. Aldrig öppen e-post om rehab. Kanal-chip per rad
	// (säkert meddelande / SDK / fax), oläst/kvittens-status. Tom kö = inget missat.
	kansligInkorg: {
		variant: 'queue',
		emptyText: 'Inget oläst i den känsliga inkorgen',
		headerStat: { label: 'Säker kanal · all data i er driftmiljö', value: '4 nya · 1 frist idag', tone: 'info' },
		rows: [
			{
				id: 'ki1',
				title: 'Läs nytt läkarintyg från medarbetare',
				subtitle: 'Avskild HR-kö · oläst · inkom 08:05',
				status: { label: 'Oläst', tone: 'info' },
				deadline: { label: 'Frist idag — koppla till dag 30', tone: 'error' },
				badges: [{ label: 'Hälsodata', tone: 'warning', icon: 'ShieldLock' }],
				channel: 'secure',
				primaryAction: { label: 'Skapa bevakning' },
			},
			{
				id: 'ki2',
				title: 'Besvara kallelse till avstämningsmöte (Försäkringskassan)',
				subtitle: 'Org-till-org via SDK · oläst',
				status: { label: 'Oläst', tone: 'info' },
				deadline: { label: 'Svara inom 5 dagar', tone: 'warning' },
				badges: [{ label: 'Försäkringskassan', tone: 'info', icon: 'Bank' }],
				channel: 'sdk',
				primaryAction: { label: 'Öppna & svara' },
			},
			{
				id: 'ki3',
				title: 'Hantera svar från företagshälsovård',
				subtitle: 'Säker e-post · läst · väntar på åtgärd',
				status: { label: 'Väntar på motpart', tone: 'warning' },
				deadline: { label: 'Boka uppföljning', tone: 'neutral' },
				badges: [{ label: 'Företagshälsovård', tone: 'info', icon: 'MedicalBag' }],
				channel: 'secure',
				primaryAction: { label: 'Boka säkert möte' },
			},
			{
				id: 'ki4',
				title: 'Hantera facklig begäran om förhandling',
				subtitle: 'Internpost · oläst',
				status: { label: 'Oläst', tone: 'info' },
				deadline: { label: 'Återkoppla 3 dagar', tone: 'warning' },
				badges: [{ label: 'Facklig part', tone: 'neutral', icon: 'AccountTie' }],
				channel: 'internal',
				primaryAction: { label: 'Skapa bevakning' },
			},
			{
				id: 'ki5',
				title: 'Hantera inkommet intyg via fax-brygga',
				subtitle: 'Liten vårdgivare utan SDK · oläst',
				status: { label: 'Oläst', tone: 'info' },
				deadline: { label: 'Koppla till rehab-rum', tone: 'neutral' },
				badges: [{ label: 'Migreringsbrygga', tone: 'neutral', icon: 'Fax' }],
				channel: 'fax',
				primaryAction: { label: 'Koppla till ärende' },
			},
		],
	},

	// ── Tvärgående (alla personor) ───────────────────────────────────────────────
	// Mina uppgifter / bevakningar — GOV.UK task-list: minimal statusuppsättning,
	// verb-inledda titlar, deadline-eskalering grå→gul→röd, källikon (kanal-chip).
	// Räknare överst: "X bevakningar förfaller denna vecka".
	minaUppgifter: {
		variant: 'queue',
		emptyText: 'Inga bevakningar förfaller denna vecka',
		headerStat: { label: 'Förfaller denna vecka', value: '4 bevakningar', tone: 'warning' },
		rows: [
			{
				id: 'mu1',
				title: 'Besvara säkert meddelande från region',
				subtitle: 'Bevakning skapad från meddelande · dnr 2026-114',
				status: { label: 'Ny', tone: 'info' },
				deadline: { label: 'Förfaller idag', tone: 'error' },
				badges: [{ label: 'Svarsfrist', tone: 'warning', icon: 'ClockAlert' }],
				channel: 'sdk',
				primaryAction: { label: 'Öppna & svara' },
			},
			{
				id: 'mu2',
				title: 'Följ upp beslut — insats upphör 30 juni',
				subtitle: 'Bevakning på tidsbegränsat beslut · påminnelse T-7',
				status: { label: 'Påbörjad', tone: 'neutral' },
				deadline: { label: '3 dagar kvar', tone: 'warning' },
				badges: [{ label: 'Uppföljning', tone: 'info', icon: 'CalendarClock' }],
				primaryAction: { label: 'Öppna ärende' },
			},
			{
				id: 'mu3',
				title: 'Slutför utredning — ärende 2026-114',
				subtitle: '4-månadersfrist · säker klientkommunikation pågår',
				status: { label: 'Påbörjad', tone: 'neutral' },
				deadline: { label: '12 dagar kvar', tone: 'neutral' },
				badges: [{ label: 'Utredningsfrist', tone: 'info', icon: 'FileDocumentEdit' }],
				channel: 'secure',
				primaryAction: { label: 'Öppna ärenderum' },
			},
			{
				id: 'mu4',
				title: 'Skicka underrättelse om dröjsmål (FL 6 mån)',
				subtitle: 'Ärende närmar sig 6-månadersgränsen',
				status: { label: 'Åtgärd krävs', tone: 'error' },
				deadline: { label: 'Förfallen — 1 dag sen', tone: 'error' },
				badges: [{ label: 'Förvaltningslagen', tone: 'error', icon: 'GavelOutline' }],
				primaryAction: { label: 'Skicka underrättelse' },
			},
			{
				id: 'mu5',
				title: 'Påminn motpart om komplettering',
				subtitle: 'Öppnad men ej besvarad · skickad 11 juni',
				status: { label: 'Väntar på motpart', tone: 'warning' },
				deadline: { label: 'Påminn nu', tone: 'warning' },
				badges: [{ label: 'Öppnad, ej besvarad', tone: 'warning', icon: 'EmailAlert' }],
				channel: 'secure',
				primaryAction: { label: 'Påminn' },
			},
			{
				id: 'mu6',
				title: 'Klarmarkera — svar skickat och kvitterat',
				subtitle: 'Svar 12 juni · läskvittens mottagen',
				status: { label: 'Klar', tone: 'success' },
				deadline: { label: 'För till ärendet eller gallra', tone: 'neutral' },
				badges: [{ label: 'Kvitterad', tone: 'success', icon: 'CheckCircle' }],
				primaryAction: { label: 'Klarmarkera' },
			},
		],
	},
}
