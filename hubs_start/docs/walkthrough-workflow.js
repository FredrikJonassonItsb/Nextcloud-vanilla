export const meta = {
	name: 'hubs-socialsekreterare-walkthrough',
	description: 'Detailed system-by-system walkthrough of the social worker flow + gap analysis + Inera signing research',
	phases: [
		{ title: 'Walkthrough' },
		{ title: 'Signing' },
		{ title: 'Synthesize' },
	],
}

const ROOT = 'C:\\Users\\fredrik.jonasson\\Cursor\\Nextcloud-vanilla'
const HS = ROOT + '\\hubs_start'
const EXT = ROOT + '\\analysis-output\\extended'

const GROUNDING = `
CONTEXT: "Hubs" (ITSL) = a Nextcloud-based secure-communication suite for Swedish kommun/region. ARCHITECTURE: Hubs is MELLANLAGRING (mellanarkiv) — the SYSTEM OF RECORD (slutarkiv) is the verksamhetens ärendehanteringssystem (socialtjänst: Treserva/Lifecare/Viva/Combine; arkiv: Sydarkivera FGS). The dashboard "Hubs Start" shows persona-personalised widgets that deep-link into native NC apps (deck, tasks, calendar, files/groupfolders, collectives, spreed/Talk, libresign, forms, tables, whiteboard, assistant). PRODUCTION assumption: Spreed has HPB (High-Performance Backend) + recording server, and Collabora/OnlyOffice is set up (so secure video, transcription and on-prem co-editing all work). Brand rule: in product-facing wording never write "Nextcloud"/"Talk" — but here you MAY name the underlying apps.
GROUND in: ${EXT}\\persona-usage-socialsekreterare.md, ${EXT}\\persona-socialsekreterare.md, ${EXT}\\native-apps-map.md, ${EXT}\\arendehantering-map.md, ${EXT}\\middleware-architecture.md, ${EXT}\\transcription-ai.md, ${EXT}\\esign-todo-native.md, ${HS}\\src\\services\\widgetApps.js, ${HS}\\docs\\WIDGET-APP-MAP.md. Use WebSearch for Swedish specifics (SoL/LVU/BBIC frister, OSL, Treserva/Lifecare behaviour, Inera) — load WebSearch/WebFetch via ToolSearch if deferred.
THE GOAL: a walkthrough so concrete that a reviewer can judge whether the reasoning HOLDS or has LUCKOR (gaps). So for EVERY step you MUST: name the exact handläggare-action, the exact Hubs widget/action, what happens in the underlying NC app (data created/moved), what happens in the system of record (Treserva etc.), the data DIRECTION (in/out, which handoff pattern A/B/C/D), and FLAG explicitly any gap/assumption/uncertainty with "⚠ LUCKA:" or "⚠ ANTAGANDE:".`

function flow(label, file, scope) {
	return () => agent(
		`You are documenting ONE flow of a Swedish socialsekreterare (barn & familj) using Hubs. ${GROUNDING}

YOUR FLOW: ${scope}

Write ${file} as a NUMBERED, step-by-step walkthrough. For each step use this structure:
**Steg N — <kort titel>**
- Handläggaren: <vad hen gör, var (vilken widget/knapp i Hubs Start eller vilken app)>
- I Hubs (mellanlagring): <vad som skapas/flyttas, vilken NC-app, vilket API/route, vilken status>
- I facksystemet (slutlagring): <vad som händer i Treserva/Lifecare etc., när och hur (mönster A/B/C/D), eller "inget ännu — committas i steg X">
- Data: <riktning, vad, sekretess/LOA, gallring/retention>
- ⚠ LUCKA/ANTAGANDE: <om något inte hänger ihop, saknas, eller förutsätter något — var ärlig och specifik; annars "—">

End with a short "## Systemöversikt för detta flöde" table (Steg | Hubs-app | Facksystem | Handoff) and a "## Identifierade luckor" list. Be concrete and realistic about Swedish socialtjänst (frister: förhandsbedömning 14 dgr, utredning 4 mån; BBIC; SoL/LVU; OSL-sekretess; dokumentationsskyldighet).
After writing, reply ONE line: file + number of gaps flagged.`,
		{ label, phase: 'Walkthrough' },
	)
}

phase('Walkthrough')
const walkResults = await parallel([
	flow('wt:inflode', EXT + '\\walkthrough-1-inflode-triage.md',
		'INFLÖDE & TRIAGE: en orosanmälan kommer in (via SDK från annan myndighet / säker e-post från skola / digital fax) till funktionsbrevlådan orosanmalan@. Hur den syns i "Att hantera"/"Orosanmälningar", hur registrator/handläggare tilldelar ("Ta ärendet"/"Plocka ärendet"), 14-dagars förhandsbedömnings-frist, och var/ när den registreras (aktualisering) i Treserva/Lifecare. Inkl. funktionsadress-behörighet (OSL).'),
	flow('wt:utredning', EXT + '\\walkthrough-2-utredning-arenderum.md',
		'UTREDNING & ÄRENDERUM: handläggaren öppnar/skapar ett ärenderum (Groupfolder per dnr/barn), samlar handlingar (säkra filer), samredigerar utredningsdokument on-prem (Collabora), delar utvalda handlingar säkert med vårdnadshavare (med BankID-läskvittens), BBIC-struktur, 4-mån utredningsfrist, versionshantering, ACL/behörighet. Var bor originalet, vad committas till Treserva/socialakten, gallring i Hubs.'),
	flow('wt:mote', EXT + '\\walkthrough-3-mote-transkribering.md',
		'SÄKERT MÖTE & TRANSKRIBERING: handläggaren bokar ett säkert videomöte med vårdnadshavare (Calendar appointment → auto Talk-rum + BankID-lobby), genomför mötet (Talk + HPB), spelar in med samtycke, transkriberar lokalt (recording server → KB-Whisper), AI-sammanfattar (llm2/Assistant), granskar (human-in-the-loop) och godkänner → den godkända anteckningen förs in i Treserva-journalen; rådata (WebM + rått transkript) gallras. Inkl. sekretess på transkript (allmän handling), samtycke.'),
	flow('wt:beslut-signering', EXT + '\\walkthrough-4-beslut-signering-delgivning.md',
		'BESLUT, E-SIGNERING & DELGIVNING: handläggaren tar fram ett beslut (Collabora), skickar det för underskrift ("Att signera" → LibreSign idag / Inera Underskriftstjänst AES med BankID i prod), signerar (PAdES/PDF-A, valideringsintyg), delger medborgaren säkert (säker e-post/SDK med läskvittens), och den signerade handlingen + delgivningsbevis committas till Treserva + arkiveras (FGS). Beskriv BÅDE LibreSign-vägen (vad den klarar) OCH Inera-vägen (vad som krävs).'),
	flow('wt:bevakning-todo', EXT + '\\walkthrough-5-bevakning-todo.md',
		'BEVAKNING & TODO: handläggaren skapar en bevakning/uppgift från ett meddelande ("Skapa bevakning från meddelande" → Deck/Tasks), Hubs lägger påminnelser T-7/T-3/T-0 (bara till tilldelad), frister (utredning, tidsbegränsade beslut, FL 6-mån), "Att göra (socialtjänst)"-listan, och hur den formella bevakningen ändå ägs/dokumenteras i Treserva. Personlig VTODO vs delad Deck-board.'),
])

phase('Signing')
const signResults = await parallel([
	() => agent(
		`${GROUNDING}

DEEP-DIVE: Inera Underskriftstjänsten (Inera Underskriftstjänst / "Signeringstjänst") API — exactly what is required to e-sign a document with BankID/Freja/SITHS at AES level via Inera, for a kommun. Cover: the signing flow (sign request → Sweden Connect / underskriftstjänst → eID (BankID/Freja/SITHS) → signature → PAdES/PDF-A + validering), the technical prerequisites (mTLS/TLS client cert, SITHS funktionscertifikat, anslutning till Ineras tjänst/avtal, SAML/Sweden Connect-metadata, DSS/Signature Service Protocol, message formats), what a kommun must have (Inera-kundavtal, SITHS, eIDAS), how it differs from LibreSign (which only has local CA / account / SMS identity, NO BankID), and HOW one would wire it into a Nextcloud/Hubs flow (custom signing adapter app vs LibreSign extension vs external service). Be concrete with real Inera/Sweden Connect/Digg references (URLs). Write ${EXT}\\signing-inera-api.md. Reply ONE line: the single biggest blocker for a kommun to use Inera signing.`,
		{ label: 'sign:inera', phase: 'Signing' },
	),
	() => agent(
		`${GROUNDING}

EVALUATE Nextcloud signing apps (NC 32) and recommend the right one for Hubs. Cover: LibreSign (app id libresign — capabilities, identity methods, what occ libresign:install/configure sets up: cfssl, JSignPDF, root CA; PAdES/PDF support; signature request flow + API), any alternative NC signing apps in the appstore, and OnlyOffice/Collabora built-in signing. Recommend: which to install + how to set it up for a demo (occ commands), and clearly state LibreSign's BankID/AES gap and that real AES must go via Inera (cross-reference the Inera dive). Write ${EXT}\\signing-apps-eval.md. Reply ONE line: recommended app + one-line setup.`,
		{ label: 'sign:apps', phase: 'Signing' },
	),
])

phase('Synthesize')
const synthesis = await agent(
	`Assemble the deliverables. Read ALL files in ${EXT} that start with walkthrough-* and signing-*, plus ${HS}\\docs\\WIDGET-APP-MAP.md and ${EXT}\\persona-usage-socialsekreterare.md. Write THREE documents:

1) ${HS}\\docs\\SOCIALSEKRETERARE-WALKTHROUGH.md — the master end-to-end step-by-step (merge the 5 flows into one coherent narrative of a socialsekreterares ärende från orosanmälan till arkiverat beslut), with the per-step "Handläggaren / I Hubs / I facksystemet / Data" structure and a final consolidated "Systemöversikt"-table (every step → Hubs-app → facksystem → handoff A/B/C/D).

2) ${HS}\\docs\\GAP-ANALYSIS.md — collect EVERY ⚠ LUCKA/ANTAGANDE from the 5 walkthroughs into a prioritised gap register: id, var (steg/flöde), beskrivning, allvarlighet (blocker/major/minor), vad som krävs för att lösa, och om det är tekniskt (NC-app/integration), juridiskt (OSL/arkivlag/IMY) eller process. This is the document that tells us "fungerar resonemanget eller finns det luckor".

3) ${HS}\\docs\\SIGNING-INERA.md — merge the two signing files into one: hur man signerar i Hubs idag (LibreSign), exakt vad som krävs för Inera Underskriftstjänst-API (mTLS, SITHS funktionscert, Sweden Connect, avtal, flöde, format), och en rekommenderad väg framåt + en setup-checklista för demo vs prod.

Reply with a 4-6 sentence executive summary: does the socialsekreterare-flödet hold end-to-end, the top 3 gaps, and the Inera signing bottom-line.`,
	{ label: 'synthesize-walkthrough', phase: 'Synthesize' },
)

return { walkthroughs: walkResults, signing: signResults, synthesis }
