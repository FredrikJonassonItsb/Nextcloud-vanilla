export const meta = {
	name: 'hubs-ux-redesign-socialsekreterare',
	description: 'Design competition: redesign the socialsekreterare dashboard for optimal usability, judge, synthesize the winner',
	phases: [
		{ title: 'Concepts' },
		{ title: 'Judge' },
		{ title: 'Synthesize' },
	],
}

const ROOT = 'C:\\Users\\fredrik.jonasson\\Cursor\\Nextcloud-vanilla'
const HS = ROOT + '\\hubs_start'
const EXT = ROOT + '\\analysis-output\\extended'

const GROUNDING = `
PRODUKT: "Hubs Start" — förstavyn/dashboarden i ITSL Hubs (Nextcloud-baserad säker myndighetskommunikation för svensk socialtjänst). Hubs är MELLANLAGRING; facksystemet (Treserva/Lifecare) är system of record.
UPPGIFT: göra ett UX-OMTAG av SOCIALSEKRETERARENS vy så att den blir en optimal, LÄTTBEGRIPLIG arbetsyta som stödjer den faktiska arbetsgången. Idag är vyn "widget-soppa" (~13 parallella widgetar) — målet är en vy som en socialsekreterare snabbt förstår och som leder hen genom arbetet.
GRUNDA HÅRT I: ${HS}\\docs\\SOCIALSEKRETERARE-WALKTHROUGH.md (51 steg i 5 akter: Akt 1 Inflöde&triage, Akt 2 Utredning&ärenderum, Akt 3 Möte&transkribering, Akt 4 Beslut&signering&delgivning, Akt 5 Bevakning&todo), ${HS}\\docs\\GAP-ANALYSIS.md, ${EXT}\\persona-usage-socialsekreterare.md, ${HS}\\docs\\WIDGET-APP-MAP.md, ${HS}\\docs\\PERSONA-DASHBOARD-SPEC.md. Använd ${EXT}\\market-ux-trender.md för UX-mönster (GOV.UK task-list, triage, progressive disclosure, WCAG 2.2).
ANTAGANDE (viktigt): ALLA blockers är lösta — Treserva-integration via **Frends** (iPaaS) är klar, **Inera Underskriftstjänst** signering finns på plats, laglig+lokal **transkribering** är juridiskt klarställd, **Retention-paus** finns i Hubs. Designa alltså som ett SKARPT verktyg — inga "föreslagen funktion"-reservationer behövs i socialsekreterarvyn; åtgärder är riktiga.
DESIGNMÅL (prioritetsordning): (1) LÄTT ATT FÖRSTÅ — en ny socialsekreterare ska direkt fatta hur hen arbetar; (2) STÖDJER ARBETSGÅNGEN i walkthrough:en; (3) LÅG KOGNITIV BELASTNING / fokus — visa nästa åtgärd, inte allt på en gång; (4) FRIST-/SEKRETESS-SÄKERHET — frister (14 dgr förhandsbedömning, 4 mån utredning, FL 6 mån, tidsbegränsade beslut) syns och inget missas; (5) tydlig mellanlagring→facksystem-känsla utan att störa. Tekniskt byggs det i Vue 2.7 + @nextcloud/vue v8.
VARUMÄRKESREGEL: aldrig "Nextcloud"/"Talk" i UI-text.`

const CONCEPTS = [
	{ id: 'arende', label: 'Ärende-centrisk ("Mina ärenden" med processteg)',
		angle: 'Organisera ALLT kring ärendet. Central vy = socialsekreterarens aktiva ärenden som kort, vart och ett med en synlig PROCESS-STEPPER (Förhandsbedömning → Utredning → Beslut → Uppföljning → Avslutat), "nästa åtgärd"-knapp, frist-indikator och inline-snabbåtgärder (öppna ärenderum, boka möte, skicka säkert, signera). Inkommande/otriagerat ligger som en separat "att ta emot"-ström. Designa hur ett ärende ser ut i varje processteg.' },
	{ id: 'mindag', label: 'Dag-/tidsstyrd ("Min dag")',
		angle: 'Organisera kring TIDEN och vad som måste göras IDAG. Topp = "Min dag"-tidslinje (frister som förfaller, dagens säkra möten, dokument att signera, svar som väntar). Under = EN prioriterad "att göra"-lista sorterad efter brådska/frist, där varje rad leder in i rätt ärende/app. Minimalt med parallella widgetar. Designa hur brådska/frister visualiseras och hur "tom dag" ser ut.' },
	{ id: 'processboard', label: 'Processtavla (kanban över ärendelivscykeln)',
		angle: 'En kanban-tavla där kolumnerna ÄR processstegen (Inkommet/triage → Under förhandsbedömning → Under utredning → Klart för beslut → Bevakas/uppföljning → Avslutat) och varje ärende är ett kort som rör sig genom flödet. Frister och nästa åtgärd på korten. Designa hur det skiljer sig från Deck och hur det känns tryggt (inget ramlar mellan stolar).' },
	{ id: 'guidad', label: 'Guidad/uppgiftsorienterad (learnability-first)',
		angle: 'Designa för att en NY socialsekreterare ska förstå direkt. Tydlig "Vad vill du göra?"-ingång, uppgiftsorienterade sektioner med verb ("Ta emot ny anmälan", "Arbeta med pågående utredning", "Fatta & signera beslut", "Följ upp"), inbäddad mikro-vägledning/hjälp, tomma tillstånd som förklarar, och progressiv avtäckning så avancerat döljs tills det behövs. Hur ser första 30 sekunderna ut för en nyanställd?' },
]

function concept(c) {
	return () => agent(
		`${GROUNDING}

DITT KONCEPT: ${c.label}
VINKEL: ${c.angle}

Skriv ${EXT}\\ux-concept-${c.id}.md — ett komplett UX-koncept för socialsekreterarens omdesignade Hubs Start-vy, med:
## Bärande idé (1 stycke) & varför det är lätt att förstå
## Informationsarkitektur (zoner/sektioner uppifrån och ned — vad ser man, i vilken ordning, varför)
## Nyckelkomponenter (lista: namn, syfte, vad den visar, vilka åtgärder; var konkret nog att bygga i Vue)
## Hur arbetsgången stöds (mappa Akt 1–5 ur walkthrough:en till UI: var i gränssnittet sker varje akt, hur leds handläggaren vidare, hur committas till Treserva via Frends och hur syns det)
## Frister & sekretess i UI (hur 14-dgr/4-mån/FL-6-mån/tidsbegränsade beslut visas; hur "inget missas" garanteras; sekretess/LOA-markering)
## Lättbegriplighet & onboarding (första 30 sek för ny socialsekreterare; etiketter; tomma tillstånd; mikrohjälp; WCAG 2.2)
## Primära åtgärder (3-5 verb-först)
## Ett konkret exempel-scenario (följ ett ärende SN 2026-0142 genom vyn, steg för steg, vad användaren ser och klickar)
Var konkret och visuell i beskrivningen. After writing, reply ONE line: koncept-id + den enskilt starkaste idén.`,
		{ label: 'concept:' + c.id, phase: 'Concepts' },
	)
}

phase('Concepts')
const conceptResults = await parallel(CONCEPTS.map(concept))

phase('Judge')
const VERDICT_SCHEMA = {
	type: 'object', additionalProperties: false,
	required: ['scores', 'winner', 'graft'],
	properties: {
		scores: {
			type: 'array',
			items: {
				type: 'object', additionalProperties: false,
				required: ['conceptId', 'learnability', 'workflowFit', 'cognitiveLoad', 'fristSafety', 'feasibility', 'total', 'motivation'],
				properties: {
					conceptId: { type: 'string' },
					learnability: { type: 'number' },
					workflowFit: { type: 'number' },
					cognitiveLoad: { type: 'number' },
					fristSafety: { type: 'number' },
					feasibility: { type: 'number' },
					total: { type: 'number' },
					motivation: { type: 'string' },
				},
			},
		},
		winner: { type: 'string' },
		graft: { type: 'string', description: 'best ideas from the non-winning concepts to graft into the winner' },
	},
}
const judges = [1, 2, 3].map((n) => () => agent(
	`${GROUNDING}

Du är domare ${n} i en UX-tävling. Läs ALLA fyra koncept i ${EXT}: ux-concept-arende.md, ux-concept-mindag.md, ux-concept-processboard.md, ux-concept-guidad.md.
Betygsätt VARJE koncept 1-10 på: learnability (lätt för socialsekreterare att förstå), workflowFit (stödjer arbetsgången i walkthrough:en), cognitiveLoad (fokus/låg belastning — högre=bättre), fristSafety (frister syns, inget missas), feasibility (byggbart i Vue+@nextcloud/vue mot seedade appar). total = summan. Välj en winner (conceptId) och beskriv vilka idéer från de andra som bör ympas in i vinnaren (graft). Var kritisk och konkret.`,
	{ label: 'judge:' + n, phase: 'Judge', schema: VERDICT_SCHEMA },
))
const verdicts = await parallel(judges)

phase('Synthesize')
const synthesis = await agent(
	`Du är lead UX-arkitekt. Läs alla fyra ux-concept-*.md i ${EXT} och domarutslagen (sammanvägn nedan: ${JSON.stringify(verdicts)}). Välj den vinnande riktningen, ympa in de bästa idéerna från övriga, och skriv en KOMPLETT, BYGGBAR designspec till ${HS}\\docs\\UX-REDESIGN-SOCIALSEKRETERARE.md med:
## Vald riktning & motiv (varför detta är lättast att förstå + stödjer arbetsgången)
## Vyns layout (zoner uppifrån och ned, med ASCII-wireframe)
## Komponenter att bygga — för VAR OCH EN: namn (PascalCase Vue), syfte, props, vad den visar, åtgärder, och hur den ser ut (konkret). Inkludera processteg-/ärendekort-komponenter, "Min dag"/idag-zon, triage-ingång, och hur frister visualiseras.
## Demodata som krävs (shape per komponent — ärenden med processteg, frister, möten, signeringar, etc.)
## Hur arbetsgången (Akt 1–5) stöds steg för steg i den nya vyn (med Treserva-commit via Frends + Inera-signering + transkribering som RIKTIGA åtgärder)
## Lättbegriplighet & onboarding (konkreta element: etiketter, tomma tillstånd, mikrohjälp, första-gången-guide, WCAG 2.2)
## Primära åtgärder (slutlig lista)
## Migrationsnot: hur detta ersätter den nuvarande widget-layouten för socialsekreteraren (andra personas behåller sin nuvarande layout tills vidare)
Var så konkret att en utvecklare kan bygga direkt. Returnera en 6-8-meningars executive summary: vald riktning, de 4-6 komponenterna som ska byggas, och hur vyn gör arbetsgången uppenbar för en ny socialsekreterare.`,
	{ label: 'synthesize-ux', phase: 'Synthesize' },
)

return { concepts: conceptResults, verdicts, synthesis }
