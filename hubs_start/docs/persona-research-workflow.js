export const meta = {
	name: 'hubs-persona-design',
	description: 'Market-research new feature areas + design persona-personalized dashboards for 6 public-sector personas',
	phases: [
		{ title: 'Research' },
		{ title: 'Personas' },
		{ title: 'Synthesize' },
	],
}

const ROOT = 'C:\\Users\\fredrik.jonasson\\Cursor\\Nextcloud-vanilla'
const AO = ROOT + '\\analysis-output'
const EXT = AO + '\\extended'

const GROUNDING = `
GROUNDING — read these existing analysis files (already richly sourced) before researching:
- ${AO}\\market-personas-anvandningsfall.json  (personas, use cases, volumes, legal refs)
- ${AO}\\market-regulatorik.json                (NIS2, OSL, eIDAS2, LOA, cybermiljarden)
- ${AO}\\market-konkurrenter-meddelanden.json   (SDK, säker e-post, fax competitors)
- ${AO}\\market-konkurrenter-video.json         (secure video competitors)
- ${AO}\\market-ux-trender.json                 (dashboard UX, GOV.UK, WCAG 2.2, design systems)
- ${AO}\\market-nextcloud-ekosystem.json        (Nextcloud apps, openDesk, Files/Talk roadmap)
Then use WebSearch/WebFetch for fresh Swedish 2025-2026 specifics (if the web tools are deferred, load them via ToolSearch query "WebSearch WebFetch"). Prefer Swedish public-sector primary sources (Digg, SKR, Inera, Socialstyrelsen, regions/kommuner, vendor sites, eIDAS/BankID). Cite real URLs.
CONTEXT: This is for "Hubs" (ITSL) — a Nextcloud-based secure-communication suite for Swedish public sector (SDK, säker e-post, digital fax, secure Talk video, secure files), sold to kommuner/regioner. Brand rule: never say "Nextcloud"/"Talk" in product-facing wording.
`

function research(label, file, topic) {
	return () => agent(
		`You are a market/feature researcher for the Hubs product. ${GROUNDING}

YOUR TOPIC: ${topic}

Deliverable: write a thorough, well-structured markdown file to ${file} with sections: ## Sammanfattning, ## Marknad & aktörer (real vendors/products + Swedish public-sector adoption), ## Juridik & krav (relevant lagkrav: eIDAS/eIDAS2, OSL, GDPR, HSLF-FS, arkivlagen, DOS-lagen/WCAG as applicable), ## Funktioner att bygga (concrete dashboard widget + flow ideas for this area, including which persona benefits), ## Rekommendation för Hubs, ## Källor (real URLs). Be concrete and Sweden-specific. Aim for depth, not breadth padding.
After writing the file, reply with ONE line: file path + 1-sentence headline finding.`,
		{ label, phase: 'Research' },
	)
}

const PERSONAS = [
	{ id: 'socialsekreterare', label: 'Socialsekreterare (barn & familj)', focus: 'orosanmälningar (514k/år), utredningar, SIP-möten, beslut som ska signeras, klientkommunikation via SDK/säker e-post, sekretess (OSL), dokumentationsbörda' },
	{ id: 'registrator', label: 'Registrator / nämndsekreterare', focus: 'hög volym diarieföring av inkommande SDK/post/fax, fördelning till handläggare, märkning/dnr, massregistrering, nämndhandlingar, arkivlagen' },
	{ id: 'hsl_skoterska', label: 'Kommunsjuksköterska / HSL', focus: 'utskrivningsbevakning (Lifecare SP), inkommande remisser/SDK från region, samordnad vårdplan/SIP, fax-in från vårdcentraler, HSLF-FS 2016:40 (kryptering+MFA), avvikelser' },
	{ id: 'hr_chef', label: 'HR / chef', focus: 'rehab-ärenden, läkarintyg, FK-kontakt, företagshälsovård, medarbetarsamtal, anställningsavtal som ska signeras, känsliga personalärenden (66% saknar verktyg)' },
	{ id: 'overformyndare', label: 'Överförmyndarhandläggare', focus: 'årsräkningar (deadline 1 mars), granskningskö, ställföreträdarkommunikation, e-underskrift av redovisning, Provisum/Aider, deadline-driven' },
	{ id: 'forvaltare', label: 'Förvaltare / IT / informationssäkerhet', focus: 'NIS2/cybersäkerhetslagen (ledningsansvar), compliance-fönster, incidentrapportering till MCF, SDK-loggar (Digg 12 mån), systemhälsa, statistik/ROI (Diggs 30-min-schablon), användarprovisionering, gallring' },
]

function personaAgent(p) {
	return () => agent(
		`You are designing a PERSONALISED dashboard for ONE persona of the Hubs product. ${GROUNDING}
ALSO read every file already written in ${EXT} (the fresh feature research: e-signering, todo/uppgifter, säkra filer, forms, etc.) — your design must weave those proposed features in.

PERSONA: ${p.label} (id: ${p.id})
Their reality / focus: ${p.focus}

Take FULL HEIGHT: do NOT limit to what Hubs ships today. Assume we can build/integrate: e-signering (BankID/Freja-underskrift), uppgifter/todo (Deck-style), säkra filer & ärenderum (Files/Groupfolders/Collabora), e-tjänster/formulär (Forms), kalender & säkra möten, SDK/säker e-post/fax, samt persona-specifika moduler. Design the dashboard EXACTLY around this persona's day and legal duties.

Write ${EXT}\\persona-${p.id}.md with: ## Persona & en dag i arbetet, ## Mål & nyckeltal (KPI), ## Primära åtgärder (3-5, verb-först, med vilken funktion/app), ## Widgetar (ordnad lista: id, titel, syfte, datakälla [befintlig/föreslagen], vilken app/funktion), ## Föreslagna appar/moduler (befintlig vs föreslagen + motivering), ## Terminologi (persona-anpassade ord), ## Flöden (2-3 konkreta end-to-end flöden, t.ex. "ta emot orosanmälan → utred → signera beslut → delge"), ## Tillgänglighet & sekretess (WCAG 2.2, OSL/HSLF-FS hänsyn). Be concrete; name widgets that the build phase can implement.
After writing, reply ONE line: persona id + the 3 most distinctive widgets.`,
		{ label: 'persona:' + p.id, phase: 'Personas' },
	)
}

// --- Phase 1: research ------------------------------------------------------
phase('Research')
const researchThunks = [
	research('research:esignering', EXT + '\\research-esignering.md',
		'Digital e-signering / e-underskrift för svensk offentlig sektor: aktörer (BankID Sign/underskrift, Freja Sign, Scrive, Assently, Visma Addo, GetAccept, Verified, eSkd, Comfact, Egreement), eIDAS AES/QES-nivåer, juridisk giltighet av e-underskrift på myndighetsbeslut/avtal/SIP/årsräkningar/intyg, on-prem vs moln, integration mot ärende. Hur en "Att signera"-kö + "Skickat för signering"-spårning bör se ut.'),
	research('research:uppgifter', EXT + '\\research-uppgifter.md',
		'Uppgifts-/ärendehantering & todo i offentlig handläggning: Nextcloud Deck (kanban/kort/deadlines), personliga vs delade listor, bevakning/påminnelser, koppling todo↔meddelande↔ärende, deadline-drivna flöden (t.ex. årsräkningar 1 mars), GOV.UK task-list-mönster. Hur en "Mina uppgifter"/"Bevakningar"-widget bör fungera.'),
	research('research:filer', EXT + '\\research-filer.md',
		'Säkra filer & dokumentsamarbete i offentlig sektor: Nextcloud Files, Groupfolders (funktionsmappar), Collabora/OnlyOffice, säker delning med medborgare, ärenderum/dokumentyta per dnr, versionshantering, retention/gallring, Collectives för kunskapsbank. Hur "Senaste säkra filer" + "Ärenderum"-widgetar bör se ut.'),
	research('research:forms-apps', EXT + '\\research-forms-apps.md',
		'Övriga Nextcloud-appar relevanta för Hubs personas: Forms (e-tjänst/orosanmälan-formulär, samtycke), Tables (strukturerade register), Whiteboard (samverkansmöten/SIP), Calendar (bokning/appointments), Maps (hembesök?), Notes. Vilka ger mest värde per persona och hur de blir widgetar.'),
	research('research:utskrivning-hsl', EXT + '\\research-utskrivning-hsl.md',
		'Kommunal hälso- och sjukvård / utskrivningsprocess: samordnad planering vid utskrivning (Lifecare SP/Tietoevry, SVPL), lagen om samverkan vid utskrivning, betalningsansvar, remiss-/meddelandeflöden region↔kommun via SDK, HSLF-FS 2016:40, vårdplan/SIP, avvikelsehantering. Vad en kommunsjuksköterska behöver bevaka på sin dashboard.'),
	research('research:personalisering', EXT + '\\research-personalisering.md',
		'Personalisering & rollbaserade dashboards: kuraterade vs konfigurerbara vyer, Microsoft Viva Connections adaptive cards, role-based default layouts, adaptiva/AI-prioriterade vyer (lokal AI pga datasuveränitet), progressive disclosure, WCAG 2.2 för personaliserade vyer, hur offentlig sektor balanserar enhetlighet och personlig anpassning. Hur Hubs bör implementera persona-vyer (auto från roll/grupp + viss egen anpassning).'),
	research('research:compliance-nis2', EXT + '\\research-compliance-nis2.md',
		'Compliance-/säkerhets-dashboard för förvaltare: NIS2/cybersäkerhetslagen (2025:1506, 15 jan 2026), ledningens personliga ansvar, incidentrapportering till MCF, SDK-loggkrav (Digg 12 mån sökbarhet), informationssäkerhetsmått, ROI/nyttomätning (Diggs 1 620 mnkr/30-min-schablon), cybermiljarden 200 mkr/år. Vilka KPI/widgetar en informationssäkerhetsansvarig vill se.'),
	research('research:signering-citizen-id', EXT + '\\research-citizen-id-onboarding.md',
		'Medborgaridentifiering & onboarding i medborgarriktade flöden: BankID/Freja LOA3, SMS-OTP-fallback, säker länk utan konto, EUDI-wallet/eIDAS2 (2026/27), statlig e-legitimation (nov 2026), digital brevlåda (Kivra/Mina meddelanden) som utkanal. Hur identifiering/utskick bör visualiseras för handläggaren.'),
]
const researchResults = await parallel(researchThunks)

// --- Phase 2: per-persona design (reads the research just written) ----------
phase('Personas')
const personaResults = await parallel(PERSONAS.map(personaAgent))

// --- Phase 3: synthesise into a build-ready spec + machine config -----------
phase('Synthesize')

const PERSONA_CONFIG_SCHEMA = {
	type: 'object',
	additionalProperties: false,
	required: ['widgets', 'primaryActions', 'proposedApps', 'personas'],
	properties: {
		widgets: {
			type: 'array',
			items: {
				type: 'object', additionalProperties: false,
				required: ['id', 'title', 'category', 'feature', 'dataSource', 'description'],
				properties: {
					id: { type: 'string', description: 'camelCase widget id, e.g. attSignera' },
					title: { type: 'string', description: 'Swedish, Academy terminology, no Nextcloud/Talk' },
					category: { type: 'string', enum: ['kommunikation', 'signering', 'uppgifter', 'filer', 'mote', 'ärende', 'compliance', 'statistik', 'persona'] },
					feature: { type: 'string', description: 'which app/feature backs it (e.g. e-signering, Deck, Files, SDK)' },
					dataSource: { type: 'string', enum: ['real', 'proposed'] },
					description: { type: 'string' },
				},
			},
		},
		primaryActions: {
			type: 'array',
			items: {
				type: 'object', additionalProperties: false,
				required: ['id', 'label', 'icon', 'feature'],
				properties: {
					id: { type: 'string' },
					label: { type: 'string' },
					icon: { type: 'string', description: 'vue-material-design-icons component name, e.g. FileSign' },
					feature: { type: 'string' },
				},
			},
		},
		proposedApps: {
			type: 'array',
			items: {
				type: 'object', additionalProperties: false,
				required: ['id', 'name', 'status', 'rationale'],
				properties: {
					id: { type: 'string' },
					name: { type: 'string' },
					status: { type: 'string', enum: ['befintlig', 'föreslagen'] },
					rationale: { type: 'string' },
				},
			},
		},
		personas: {
			type: 'array',
			items: {
				type: 'object', additionalProperties: false,
				required: ['id', 'label', 'role', 'tagline', 'primaryActionIds', 'layout', 'kpis'],
				properties: {
					id: { type: 'string' },
					label: { type: 'string' },
					role: { type: 'string' },
					tagline: { type: 'string', description: 'one line describing this persona\'s dashboard focus' },
					primaryActionIds: { type: 'array', items: { type: 'string' } },
					layout: {
						type: 'object', additionalProperties: false,
						required: ['main', 'side'],
						properties: {
							main: { type: 'array', items: { type: 'string' }, description: 'ordered widget ids, left/primary column' },
							side: { type: 'array', items: { type: 'string' }, description: 'ordered widget ids, right column' },
						},
					},
					kpis: { type: 'array', items: { type: 'string' } },
				},
			},
		},
	},
}

const synthesis = await agent(
	`You are the lead designer. Read EVERY file in ${EXT} (all research-*.md and all persona-*.md) plus ${AO}\\market-personas-anvandningsfall.json. Produce TWO things:

1) Write a human design doc to ${ROOT}\\hubs_start\\docs\\PERSONA-DASHBOARD-SPEC.md — the master spec: the vision (persona-personalised dashboards), the full WIDGET CATALOG (every widget with id/title/feature/dataSource/which personas), the PROPOSED APPS roadmap (e-signering, uppgifter/Deck, säkra filer/ärenderum, Forms, etc. — befintlig vs föreslagen, with market grounding + sources), and ONE section per persona (tagline, primary actions, widget layout main/side, KPIs, terminology, signature flow). Keep brand rule. Reference real sources.

2) Return (via the StructuredOutput tool) a machine-usable persona-config.json matching the schema: a widget catalog, primaryActions catalog (with vue-material-design-icons icon names), proposedApps, and the 6 personas each with primaryActionIds + layout{main[],side[]} of widget ids + kpis. Widget ids must be camelCase and REUSED across personas where shared. Cover all 6 personas: socialsekreterare, registrator, hsl_skoterska, hr_chef, overformyndare, forvaltare. Include the already-built core widgets (attHantera, dagensMoten, kvittenser, funktionsbrevlador, bevakningar, bokningsbaraTider, nytta, systemhalsa) AND the new proposed ones (e.g. attSignera, skickatForSignering, minaUppgifter, arenderum, senasteFiler, orosanmalningar, utskrivningsbevakning, arsrakningar, rehabarenden, complianceStatus, incidentrapporter, etc.). Make each persona's layout genuinely distinct and fit-for-purpose.`,
	{ label: 'synthesize-spec', phase: 'Synthesize', schema: PERSONA_CONFIG_SCHEMA },
)

return { research: researchResults, personas: personaResults, config: synthesis }
