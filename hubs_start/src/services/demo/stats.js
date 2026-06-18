/**
 * Demo fixture descriptors — `stat` variant widgets (förvaltare / infosäk persona).
 *
 * Static Swedish fixtures for the 4 KPI-tile / statement / checklist widgets.
 * Each descriptor matches the `stat` shape in docs/DEMO-WIDGETS-CONTRACT.md exactly:
 *   { variant: 'stat', overallTone?, tiles?, checks?, statements?, note?, searchLine? }
 *
 * Grounded in: persona-forvaltare.md, research-compliance-nis2.md,
 * research-citizen-id-onboarding.md. Anonymised, no PII, brand rule respected.
 */
export default {
	// fristStrip — lagstadgade frister som KPI-brickor med deadline-tone.
	// MCF-incidentkedjan (24 h / 72 h / 1 mån) + SDK-loggens avstämningsmöte.
	fristStrip: {
		variant: 'stat',
		overallTone: 'warning',
		tiles: [
			{
				value: '18 h 40 min',
				label: 'Dag 8 · tidig varning till MCF',
				tone: 'warning',
			},
			{
				value: '2 dygn 6 h',
				label: 'Dag 30 · incidentanmälan (72 h)',
				tone: 'info',
			},
			{
				value: '21 dagar',
				label: '60-dagarsfrist · läges-/slutrapport',
				tone: 'neutral',
			},
			{
				value: 'Tors 09:00',
				label: 'Avstämningsmöte SDK-logg',
				tone: 'success',
			},
		],
		note: 'Lagstadgade frister enligt cybersäkerhetslagen (2025:1506). 0 missade hittills i år.',
	},

	// authLoa — tillitsnivå/MFA som brickor + eIDAS2-redo som check.
	// LOA3 (BankID/Freja/SITHS), HSLF-FS 2016:40, Sverige-id dec 2026, EUDI-wallet.
	authLoa: {
		variant: 'stat',
		overallTone: 'success',
		tiles: [
			{
				value: '98,6 %',
				label: 'Sessioner på tillitsnivå 3',
				tone: 'success',
			},
			{
				value: '100 %',
				label: 'MFA-täckning, sekretessflöden',
				tone: 'success',
			},
			{
				value: '3 st',
				label: 'Inloggningar under tröskel (avvikelse)',
				tone: 'warning',
			},
		],
		checks: [
			{ label: 'BankID aktiverad (LOA3)', ok: true, detail: 'Leverantörsoberoende ID-core' },
			{ label: 'Freja eID+ aktiverad (LOA3, även icke-bofast)', ok: true, detail: 'Nyanlända / EU-medborgare' },
			{ label: 'SITHS aktiverad (tjänstelegitimation)', ok: true, detail: 'Mötesledare / HSL-personal' },
			{ label: 'SMS-OTP spärrad som ensam faktor', ok: true, detail: 'NIST restricted — endast komplement' },
			{ label: 'Sverige-id (LOA4) — inkopplingsbar', ok: false, detail: 'Förbereds inför lansering dec 2026' },
			{ label: 'EUDI-plånbok (eIDAS2) — accept förberedd', ok: false, detail: 'Acceptanskrav ~dec 2026' },
		],
		note: 'eIDAS2-redo. Krypterat till endast avsedd mottagare (HSLF-FS 2016:40).',
	},

	// dataSuveranitet — statements (OSL 10:2a + eSam ES2023-06, on-prem-modellen).
	dataSuveranitet: {
		variant: 'stat',
		overallTone: 'success',
		statements: [
			'All data i er driftmiljö — inget lämnar kommunens egna servrar.',
			'0 tredjelandsöverföringar (ingen CLOUD Act-exponering).',
			'Senaste externa åtkomst: ingen.',
			'Lokal AI-assistans körs on-prem — data lämnar aldrig miljön.',
		],
		note: 'OSL 10 kap. 2 a § + eSam ES2023-06. Lämplighetsbedömningen bortfaller — ingen extern part får informationen.',
	},

	// loggSparbarhet — SDK-loggretention som brickor + statisk sökrad mot AS4 ID.
	// Digg-kravet: minst 12 mån, läsbar/sökbar; logg utan meddelandeinnehåll.
	loggSparbarhet: {
		variant: 'stat',
		overallTone: 'success',
		tiles: [
			{
				value: '12 / 12 mån',
				label: 'SDK-loggretention — uppfylld',
				tone: 'success',
			},
			{
				value: '12 / 12 mån',
				label: 'Sökbar/läsbar form (Digg-krav)',
				tone: 'success',
			},
			{
				value: '1 247 st',
				label: 'Lyckade logguppslag mot AS4 ID',
				tone: 'info',
			},
		],
		searchLine: {
			label: 'Sök i SDK-loggen mot AS4 Message/Conversation ID',
			placeholder: 'AS4 Message ID eller Conversation ID…',
			example: 'urn:as4:msg:9f3c0a21-7b4e-4d28-8c11-0a5e2f74d9b1',
			result: {
				messageType: 'Meddelandeleverans (SDK)',
				accessPoint: 'AP-KOMMUN-03',
				sender: 'orosanmalan@ (funktionsadress)',
				recipient: 'AP-REGION-01 · mottagande deltagare',
				timestamp: '2026-06-12 14:02',
				conversationId: 'urn:as4:conv:b21d77e4-1c9a-4f60-ae33-6d2c8819f045',
				note: 'Endast metadata — loggen omfattar inte meddelandeinnehåll (Digg, Bilaga IT-säkerhet).',
				tone: 'success',
			},
		},
		note: 'SDK-loggretention 12/12 mån — sökbar. Underlag för felsökning och tillsyn.',
	},
}
