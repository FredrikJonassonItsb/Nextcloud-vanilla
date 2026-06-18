export const meta = {
	name: 'hubs-demo-content',
	description: 'Generate rich Swedish demo descriptors for the 29 persona widgets + review',
	phases: [
		{ title: 'Content' },
		{ title: 'Review' },
	],
}

const ROOT = 'C:\\Users\\fredrik.jonasson\\Cursor\\Nextcloud-vanilla'
const HS = ROOT + '\\hubs_start'
const DEMO = HS + '\\src\\services\\demo'
const EXT = ROOT + '\\analysis-output\\extended'

const RULES = `
You write DEMO FIXTURE DATA (no UI components). REPO ROOT: ${ROOT}.
FIRST read IN FULL:
- ${HS}\\docs\\DEMO-WIDGETS-CONTRACT.md  (the descriptor shapes + tone/channel/icon enums — follow EXACTLY)
- the persona/research files named in your task (for authentic Swedish public-sector content)
HARD RULES:
- Output a single ES module: \`export default { <widgetId>: <descriptor>, ... }\` — plain JS object literals, NO imports, NO Vue, NO functions, NO Date.now (static fixtures).
- Each descriptor MUST match its assigned variant shape from the contract exactly (variant, rows/headline/tiles/etc., tone enum, channel enum).
- Content in SWEDISH, realistic and persona-coherent, anonymised (no real personal data; use dnr/case ids and role labels). Brand rule: never the words "Nextcloud" or "Talk".
- 4–7 rows per queue/files widget; meaningful deadlines/statuses/badges; icons = common vue-material-design-icons names.
- Do NOT wrap strings in t() here — these are data; the components translate/label. Plain Swedish strings are fine as values.
Do NOT run anything. After writing, reply ONE line: file + widget ids covered.`

function content(label, file, widgets, sources) {
	return () => agent(
		`${RULES}

SOURCES to ground in: ${sources.map((s) => EXT + '\\' + s).join(' ; ')}

YOUR FILE: ${file}
YOUR WIDGETS (id → variant): ${widgets}`,
		{ label, phase: 'Content' },
	)
}

phase('Content')
const contentResults = await parallel([
	content('demo:queues-a', DEMO + '\\queues-a.js',
		'orosanmalningar(queue, 14-dagars förhandsbedömning-countdown, källa skola/vård/polis), utskrivningsbevakning(queue, dygnsräknare mot betalningsansvar + kr-riskindikator), samverkansavvikelser(queue), granskningsko(queue, plockbar redovisningskö, saknas-verifikat), rehabarenden(queue, status Ny/Pågående/Väntar/Plan upprättad/Avslutad), kansligInkorg(queue, avskild rehab/personal-triage med channel-chips), minaUppgifter(queue, GOV.UK task-list)',
		['persona-socialsekreterare.md', 'persona-hsl_skoterska.md', 'persona-hr_chef.md', 'persona-overformyndare.md', 'research-uppgifter.md', 'research-utskrivning-hsl.md']),
	content('demo:queues-b', DEMO + '\\queues-b.js',
		'attSignera(queue, kravnivå-badge SES/AES/QES + deadline), skickatForSignering(queue, per-part status Skickat→Öppnat→Signerat X av Y, Påminn), incidentrapporter(queue, MCF-klockor 24h/72h/1mån i tone, verb-knappar), sakerhetshandelser(queue, misslyckade inloggningar/avvikande delningar, Eskalera-knapp), registreraFordela(queue, förifylld diarieföring), utlamnande(queue, sekretessprövning + skyndsamt-timer), uppdragskontroll(queue, flaggade ställföreträdare), justeringAnslag(queue, protokoll väntar på justering + laga-kraft-nedräkning), mallarSamtycke(queue, mallbibliotek samtycke/plan för återgång)',
		['persona-registrator.md', 'persona-overformyndare.md', 'persona-hr_chef.md', 'persona-forvaltare.md', 'research-esignering.md', 'research-compliance-nis2.md']),
	content('demo:progress', DEMO + '\\progress.js',
		'arsrakningar(progress, 312/540 granskade, 18 dagar till 1 mars, breakdown), namndcykel(progress, GOV.UK task-list mot sammanträde: ärenden klara/saknar underlag/kallelse skickad/handlingar delade/protokoll att justera), complianceStatus(progress OR stat — use progress with breakdown of kravområden grön/gul/röd; headline = sammanvägd status)',
		['persona-overformyndare.md', 'persona-registrator.md', 'persona-forvaltare.md', 'research-compliance-nis2.md']),
	content('demo:stats', DEMO + '\\stats.js',
		'fristStrip(stat, tiles = lagstadgade frister dag 8/dag 30/60-dagar/avstämningsmöte med tone), authLoa(stat, tiles LOA3-andel/MFA-täckning + eIDAS2-redo, checks), dataSuveranitet(stat, statements: all data i er driftmiljö/0 tredjelandsöverföringar/senaste externa åtkomst ingen), loggSparbarhet(stat, tiles loggretention 12/12 mån + en statisk sökrad mot AS4 Message/Conversation ID)',
		['persona-forvaltare.md', 'persona-hr_chef.md', 'research-compliance-nis2.md', 'research-citizen-id-onboarding.md']),
	content('demo:files', DEMO + '\\files.js',
		'arenderum(files, ärenderum per dnr: olästa dokument/väntar-på-signatur/gallrings-countdown/medborgardelning), senasteFiler(files, delad med medborgare/ny version/väntar på granskning/signerad/uppladdad av motpart), arkivGallring(files, avslutade ärenden Gallras 2031/Bevaras + Leverera till e-arkiv FGS), kunskapsbank(files, rutiner/BBIC-/rehab-/granskningsmallar/gallringsplaner)',
		['persona-socialsekreterare.md', 'persona-registrator.md', 'research-filer.md']),
])

phase('Review')
const REVIEW_SCHEMA = {
	type: 'object', additionalProperties: false, required: ['summary', 'findings'],
	properties: {
		summary: { type: 'string' },
		findings: {
			type: 'array',
			items: {
				type: 'object', additionalProperties: false,
				required: ['severity', 'file', 'issue', 'fix'],
				properties: {
					severity: { type: 'string', enum: ['blocker', 'major', 'minor'] },
					file: { type: 'string' }, issue: { type: 'string' }, fix: { type: 'string' },
				},
			},
		},
	},
}
const review = await agent(
	`REVIEW the demo descriptor files in ${DEMO} against ${HS}\\docs\\DEMO-WIDGETS-CONTRACT.md. Read every *.js file there. Check: each is a valid ES module \`export default { id: {...} }\`; every descriptor has the correct \`variant\` and a shape matching that variant; tone values are in the enum; channel values in the enum; no imports/functions/Date usage; Swedish content; no "Nextcloud"/"Talk"; all 29 assigned widget ids present across the files (list any missing). Report findings (severity/file/issue/fix) and in the summary list which widget ids are covered.`,
	{ label: 'review:descriptors', phase: 'Review', schema: REVIEW_SCHEMA },
)

return { content: contentResults, review }
