export const meta = {
	name: 'hubs-app-mapping',
	description: 'Map every widget to its backing app + system-of-record, design daily usage patterns, add missing use cases',
	phases: [
		{ title: 'Research' },
		{ title: 'UsagePatterns' },
		{ title: 'Synthesize' },
	],
}

const ROOT = 'C:\\Users\\fredrik.jonasson\\Cursor\\Nextcloud-vanilla'
const HS = ROOT + '\\hubs_start'
const EXT = ROOT + '\\analysis-output\\extended'

const ALL_WIDGETS = 'attHantera, dagensMoten, kvittenser, funktionsbrevlador, bevakningar, bokningsbaraTider, nytta, systemhalsa, attSignera, skickatForSignering, minaUppgifter, arenderum, senasteFiler, orosanmalningar, utskrivningsbevakning, samverkansavvikelser, arsrakningar, granskningsko, uppdragskontroll, rehabarenden, kansligInkorg, fristStrip, mallarSamtycke, registreraFordela, utlamnande, namndcykel, justeringAnslag, arkivGallring, complianceStatus, incidentrapporter, sakerhetshandelser, loggSparbarhet, authLoa, provisionering, dataSuveranitet, kunskapsbank, identitetsBadge'

const GROUNDING = `
CONTEXT: "Hubs" (ITSL) is a Nextcloud-based secure-communication suite for Swedish public sector. CRITICAL ARCHITECTURAL FRAMING (from the customer): **Hubs is the MIDDLEWARE / mellanlagring of data — the system of record (slutlagring) is always the verksamhetens ärendehanteringssystem** (e.g. socialtjänst: Treserva, Lifecare/Procapita, Viva, Combine; HSL: Lifecare, Cosmic, Treserva HSL; överförmyndare: Provisum/Aider/Wärna; registratur/nämnd: W3D3, Public360, Ciceron, Platina, Evolution, LEX; HR: Visma, Heroma, Personec). Hubs stages secure communication, signing, meetings and files, then the handläggare commits the outcome into the case system. Every widget/flow must reflect this: where does the data come FROM, and where is it ultimately stored.
GROUND in these files (read what's relevant): ${EXT}\\*.md (existing research + persona specs), ${HS}\\src\\services\\personaConfig.js (the 37-widget catalog + 6 personas), ${HS}\\docs\\PERSONA-DASHBOARD-SPEC.md.
Use WebSearch/WebFetch for fresh Swedish 2025-2026 specifics on Nextcloud apps and Swedish case systems (load via ToolSearch "WebSearch WebFetch" if deferred). Brand rule: in product-facing wording never write "Nextcloud" or "Talk" — but in THIS internal analysis you may name the underlying apps (Deck, Tables, Talk/spreed, Files, Collectives, LibreSign, Forms, Whiteboard, Calendar) so we can wire them.`

function research(label, file, topic) {
	return () => agent(
		`Researcher for the Hubs dashboard. ${GROUNDING}

YOUR TOPIC: ${topic}

Write a thorough markdown file to ${file}. Be concrete, Sweden-specific, cite real URLs in a ## Källor section. Name exact Nextcloud app ids and deep-link routes where relevant, and exact Swedish products where relevant.
After writing, reply ONE line: file + headline finding.`,
		{ label, phase: 'Research' },
	)
}

const PERSONAS = [
	{ id: 'socialsekreterare', label: 'Socialsekreterare (barn & familj)', sor: 'Treserva / Lifecare / Viva / Combine' },
	{ id: 'registrator', label: 'Registrator / nämndsekreterare', sor: 'W3D3 / Public360 / Ciceron / Platina / Evolution' },
	{ id: 'hsl_skoterska', label: 'Kommunsjuksköterska (HSL)', sor: 'Lifecare / Treserva HSL / Cosmic; Lifecare SP för utskrivning' },
	{ id: 'hr_chef', label: 'HR / chef (rehab & personal)', sor: 'Visma / Heroma / Personec; Adato/rehabstöd' },
	{ id: 'overformyndare', label: 'Överförmyndarhandläggare', sor: 'Provisum / Aider / Wärna' },
	{ id: 'forvaltare', label: 'Förvaltare / IT / informationssäkerhet', sor: 'SIEM/loggsystem; e-arkiv (Sydarkivera/FGS)' },
]

function usageAgent(p) {
	return () => agent(
		`Design the REAL daily usage pattern for ONE Hubs persona. ${GROUNDING}
ALSO read every native-app / case-system research file just written in ${EXT} (native-apps-map.md, arendehantering-map.md, transcription-ai.md, esign-todo-native.md, middleware-architecture.md).

PERSONA: ${p.label} (id: ${p.id}). Likely system(s) of record: ${p.sor}.

Write ${EXT}\\persona-usage-${p.id}.md with:
## En dag i arbetet (08:00→17:00, kronologiskt, konkret — vilka ärenden, vilka beslut, vilka kontakter)
## Hur Hubs + dashboarden faktiskt används (vilka widgetar öppnas när, i vilken ordning, vilka åtgärder)
## Widget → app → system-of-record-karta (för VARJE widget i denna personas layout: vilken Nextcloud-app/funktion driver den, och i vilket ärendehanteringssystem hamnar resultatet till slut — gör mellanlagrings-modellen explicit: "Hubs stagar X → handläggaren för över till {system}")
## Typiska arbetsmönster & återkommande flöden (3-4 end-to-end, inkl. var data tas emot och var den slutlagras)
## Saknade funktioner för denna persona (t.ex. todolista för socialtjänst, mötestranskribering + AI-sammanfattning) och hur de skulle byggas/wire:as
Be concrete and realistic. After writing, reply ONE line: persona id + the system(s) of record + 1 missing function.`,
		{ label: 'usage:' + p.id, phase: 'UsagePatterns' },
	)
}

phase('Research')
const researchResults = await parallel([
	research('research:native-apps', EXT + '\\native-apps-map.md',
		'Native Nextcloud apps (NC 32) that can back the Hubs dashboard widgets. For EACH relevant app give: app id, what it does, the deep-link route(s) (e.g. /apps/deck/board/{id}, /apps/files/?dir=, /apps/calendar, /apps/collectives, /apps/tables, /call/{token}, /apps/libresign), capabilities + limitations, and which dashboard widgets it can power. Cover: Deck (tasks/kanban), Tasks (CalDAV todo), Calendar + Appointments, Forms, Tables, Collectives, Files + Groupfolders (ACL/retention), Whiteboard, Talk/spreed (rooms, lobby, recording, transcription), LibreSign (document signing), Activity, Flow/workflow_engine, Assistant/AI (text processing). State clearly which can be installed+wired today vs which need extra backends (Collabora/OnlyOffice, Talk recording server, LibreSign cfssl/jsignpdf, AI/LLM backend, BankID/SDK).'),
	research('research:arendehantering', EXT + '\\arendehantering-map.md',
		'Swedish ärendehanteringssystem (system of record) per verksamhet/persona and how a middleware like Hubs hands off to them. Socialtjänst: Treserva (CGI), Lifecare/Procapita (Tietoevry), Viva (CGI/Flexite), Combine (Pulsen). HSL: Lifecare, Cosmic (Cambio), Treserva HSL; Lifecare SP för samordnad planering. Överförmyndare: Provisum (Sambruk), Aider, Wärna. Registratur/nämnd/diarium: W3D3 (Formpipe), Public360 (Tieto/Sokigo), Ciceron, Platina (Formpipe), Evolution (Sokigo), LEX (Sokigo). HR/personal: Visma, Heroma (CGI), Personec (Aditro/CGI), Adato (rehab). Integration patterns Hubs↔case-system: API/REST, e-arkiv FGS-export, drag-to-case, manuell överföring. Make the "mellanlagring vs slutlagring" model concrete per persona.'),
	research('research:transcription-ai', EXT + '\\transcription-ai.md',
		'Meeting transcription + AI note summarisation in a Nextcloud/secure-video context. Talk call recording (recording server / High-Performance Backend), speech-to-text transcription (languages incl. Swedish), Nextcloud Assistant + a LOCAL LLM (data suveränitet — must run on-prem, no cloud) for summarising notes/transcripts, the meeting-notes flow (record → transcribe → summarise → save to ärende), and the GDPR/OSL/sekretess constraints on storing transcripts of sekretessbelagda möten. List exact prerequisites (HPB signaling/recording, STT model, GPU, llm models) and what is realistic to demo vs document.'),
	research('research:esign-todo', EXT + '\\esign-todo-native.md',
		'Two things: (1) Native e-signing in Nextcloud — LibreSign app (capabilities, setup prereqs cfssl/jsignpdf/java, certificate model) and the GAP to Swedish BankID/Freja AES + Inera Underskriftstjänst/Sweden Connect; how to wire "Att signera"/"Skickat för signering" with LibreSign today + what to document as the real integration. (2) Todolista for socialtjänsten — what social workers actually ask for (they explicitly requested a todo list), Deck vs Tasks, personal+shared lists tied to BBIC/utredning, deadlines/påminnelser, and how it should map to the case system (Treserva/Lifecare) as system of record.'),
	research('research:middleware', EXT + '\\middleware-architecture.md',
		'The core architecture narrative: "Hubs = mellanlagring (staging) of secure communication/signing/meetings/files; the ärendehanteringssystem = slutlagring (system of record)". Why staging (sekretess, retention/gallring in Hubs vs archive in case system, OSL 10:2a on-prem), the data lifecycle (mottag i Hubs → handlägg → för över till ärendesystem → gallra i Hubs), integration patterns, and how the DASHBOARD should make this visible to a handläggare (provenance: var kommer detta ifrån, var hamnar det). This becomes the teaching story for customer/developer demos.'),
])

phase('UsagePatterns')
const usageResults = await parallel(PERSONAS.map(usageAgent))

phase('Synthesize')

const SCHEMA = {
	type: 'object', additionalProperties: false,
	required: ['widgetApps', 'newWidgets', 'newActions', 'placements'],
	properties: {
		widgetApps: {
			type: 'array',
			description: 'one entry per widget id in the catalog (cover ALL of them) + the new ones',
			items: {
				type: 'object', additionalProperties: false,
				required: ['widgetId', 'backingApp', 'status', 'systemOfRecord', 'prerequisites'],
				properties: {
					widgetId: { type: 'string' },
					backingApp: { type: 'string', description: 'human label, e.g. "Deck (uppgifter)" or "SDK-klient (sdkmc)"' },
					ncAppId: { type: 'string', description: 'installable Nextcloud app id if native, else empty string' },
					status: { type: 'string', enum: ['native', 'proposed-integration', 'external'] },
					deepLink: { type: 'string', description: 'route to open the app, or empty string' },
					prerequisites: { type: 'string', description: 'what must exist to wire it for real (backends, BankID, SDK, etc.)' },
					systemOfRecord: { type: 'string', description: 'where the data is ultimately stored (the ärendehanteringssystem / arkiv), or "Hubs (mellanlagring)" if it stays' },
				},
			},
		},
		newWidgets: {
			type: 'array',
			description: 'missing use cases to add, esp. todolista (socialtjänst), motesanteckningar (transkribering+AI-sammanfattning)',
			items: {
				type: 'object', additionalProperties: false,
				required: ['id', 'title', 'category', 'variant', 'feature', 'dataSource', 'description'],
				properties: {
					id: { type: 'string' },
					title: { type: 'string' },
					category: { type: 'string', enum: ['kommunikation', 'signering', 'uppgifter', 'filer', 'mote', 'ärende', 'compliance', 'statistik', 'persona'] },
					variant: { type: 'string', enum: ['queue', 'progress', 'stat', 'files'] },
					feature: { type: 'string' },
					dataSource: { type: 'string', enum: ['real', 'proposed'] },
					description: { type: 'string' },
				},
			},
		},
		newActions: {
			type: 'array',
			items: {
				type: 'object', additionalProperties: false,
				required: ['id', 'label', 'icon', 'feature'],
				properties: { id: { type: 'string' }, label: { type: 'string' }, icon: { type: 'string' }, feature: { type: 'string' } },
			},
		},
		placements: {
			type: 'array',
			description: 'where to insert the new widgets into persona layouts',
			items: {
				type: 'object', additionalProperties: false,
				required: ['personaId', 'column', 'widgetId'],
				properties: {
					personaId: { type: 'string' },
					column: { type: 'string', enum: ['main', 'side'] },
					widgetId: { type: 'string' },
				},
			},
		},
	},
}

const synthesis = await agent(
	`You are the lead architect. Read ALL files in ${EXT} (native-apps-map.md, arendehantering-map.md, transcription-ai.md, esign-todo-native.md, middleware-architecture.md, and the 6 persona-usage-*.md) plus ${HS}\\src\\services\\personaConfig.js. Produce:

1) Human docs:
- ${HS}\\docs\\WIDGET-APP-MAP.md — for EVERY widget: backing app, NC app id (or external), status, deep link, prerequisites, system-of-record. Group by persona.
- ${HS}\\docs\\PERSONA-USAGE-PATTERNS.md — the day-in-life + data-flow per persona (synthesise the 6 usage files), making the middleware→case-system handoff explicit.
- ${HS}\\docs\\NATIVE-APPS-INSTALL.md — which NC apps to install (occ commands), what each powers, and detailed prerequisites for the ones that can't be fully wired (BankID e-sign, SDK, Talk transcription/recording, Collabora, local LLM).

2) Return (StructuredOutput) the config:
- widgetApps: ONE entry per widget id (cover ALL of these: ${ALL_WIDGETS}) PLUS the new widgets — each with backingApp, ncAppId, status, deepLink, prerequisites, systemOfRecord. Use real NC app ids (deck, tasks, calendar, forms, tables, collectives, groupfolders, files, whiteboard, spreed, libresign, activity) and real Swedish case systems for systemOfRecord.
- newWidgets: the missing use cases — at minimum \`todolista\` (socialtjänst-todo, queue, Deck-backed) and \`motesanteckningar\` (mötestranskribering + AI-sammanfattning, queue, Talk recording + Assistant/LLM) — plus any other gaps you find. Give each a variant so it renders.
- newActions: any new primary actions (e.g. starta transkribering, lägg till uppgift).
- placements: insert the new widgets into the relevant persona layouts (socialsekreterare gets todolista; personas with meetings get motesanteckningar, etc.).
Keep brand rule in product-facing titles.`,
	{ label: 'synthesize-appmap', phase: 'Synthesize', schema: SCHEMA },
)

return { research: researchResults, usage: usageResults, config: synthesis }
