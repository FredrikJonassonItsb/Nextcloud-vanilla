export const meta = {
	name: 'hubs-ss-build',
	description: 'Build the chrome components for the redesigned socialsekreterare view against the UX spec',
	phases: [
		{ title: 'Build' },
		{ title: 'Review' },
	],
}

const ROOT = 'C:\\Users\\fredrik.jonasson\\Cursor\\Nextcloud-vanilla'
const HS = ROOT + '\\hubs_start'
const SS = HS + '\\src\\components\\socialsekreterare'

const RULES = `
You implement ONE part of the redesigned socialsekreterare view ("Mina ärenden"). REPO ROOT: ${ROOT}.
READ FIRST (binding):
- ${HS}\\docs\\UX-REDESIGN-SOCIALSEKRETERARE.md  (the design spec — find YOUR component's section, follow props/events/look EXACTLY)
- ${SS}\\MinaArenden.vue  (the container — shows how your component is wired: exact props passed and events listened to)
- ${HS}\\src\\services\\demo\\socialsekreterare.js  (the demo data shapes you render: Arende, TriageRad, Puls, Möte)
- ${HS}\\src\\services\\arendeFlow.js, ${HS}\\src\\services\\channels.js, ${HS}\\src\\services\\tones.js, ${HS}\\src\\services\\icons.js
HARD RULES:
- Vue 2.7 + @nextcloud/vue v8. Per-component imports (import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'). Options API: export default { name, components, props, data, computed, methods }.
- Strings via t('hubs_start','…') (import { translate as t } from '@nextcloud/l10n'); Swedish. BRAND RULE: never "Nextcloud"/"Talk" in UI text — say Hubs, ärenderum, säkert möte, facksystemet/Treserva.
- Props/events MUST match how MinaArenden.vue wires your component. Do not invent prop/event names.
- WCAG 2.2 AA: targets ≥24×24px, color never the only signal (icon+text+colour), no drag-only, aria where relevant.
- Scoped <style lang="scss">; use .hs-card shell + css/variables.scss tokens; tone colours via toneColor() from services/tones.js; channel chips via channelMeta() from services/channels.js; icons via iconFor() from services/icons.js where a name is given.
- SHARED ATOMS exist and you must IMPORT (not rebuild) them when needed: ./FristChip.vue, ./ProvenansChip.vue, ./ProcessStepper.vue, ./NastaAtgardKnapp.vue (in the same folder ${SS}); LoaChip at ../LoaChip.vue.
- This is a SHARP tool (blockers solved) — actions are real; no "föreslagen funktion"/demoBlocked wording in the socialsekreterare view.
- Write your file(s) into ${SS}\\. Do NOT run builds. Do NOT edit other files. Reply ONE line: file(s) + any deviation.`

function build(label, files, spec) {
	return () => agent(`${RULES}\n\nYOUR TASK — create ${files.join(' AND ')}:\n${spec}`, { label, phase: 'Build' })
}

phase('Build')
const results = await parallel([
	build('MinDagHeader+Dagspulsen', [SS + '\\MinDagHeader.vue', SS + '\\Dagspulsen.vue'],
		'MinDagHeader (Zon 0, sticky): props loa(String), profile(String), puls(Object), aktivtFilter(String|null). Visar "God morgon, {namn} · {veckodag} {datum}" (localised; demo name "Anna"), <LoaChip> (import ../LoaChip.vue), statisk datasuveränitets-prick "Säker kanal · all data i er driftmiljö", en Ctrl/⌘K-knapp och en fast Hjälp-"?"; innehåller <Dagspulsen :puls :aktivtFilter @filter> som barn. Emits @open-palette, @upgrade-loa (bubbla från LoaChip), @open-help, @filter (re-bubblad från Dagspulsen). Sticky (position:sticky;top:0;z-index:10;bakgrund). — Dagspulsen: props puls({fristerBrinner,motenIdag,attSignera,nyaInflode}), aktivtFilter(String|null). Fyra klickbara "piller"-knappar (≥44px) var med ikon+tal+text: "⏰ {n} frister brinner"(ClockAlert, röd när >0), "📹 {n} möten idag"(Video), "✍ {n} att signera"(FileSign), "📥 {n} nya"(InboxArrowDown). Emits @filter(key) key∈frist|mote|signera|inflode; aktiv räknare aria-pressed + accent. Wrap till 2×2 i smal vy. Aldrig bara färg.'),
	build('VadVillDuGora', [SS + '\\VadVillDuGora.vue'],
		'Verbingång (Zon V). props dismissed(Boolean). Utfälld: fem stora verbknappar med ikon+undertext: "Ta emot anmälan"(undertext "starta förhandsbedömning", InboxArrowDown), "Arbeta med utredning"(FileDocumentEdit), "Boka möte"(VideoPlus), "Signera beslut"(FileSign), "Följ upp"(BellRing). Kollapsad (dismissed=true): en tunn rad "Vad vill du göra? ▸" som expanderar igen + en liten "stäng tips"-länk. Emits @verb(id) id∈taEmot|utredning|mote|signera|foljUpp och @dismiss(). Fem jämnbreda kort i rad, wrap i smal vy.'),
	build('AttTaEmot', [SS + '\\AttTaEmotSektion.vue', SS + '\\AttTaEmotRad.vue'],
		'AttTaEmotSektion (Zon 1 triage): props items(Array<TriageRad>), aktivtFilter(String|null). Rubrik "Att ta emot" + NcCounterBubble(antal). Renderar <AttTaEmotRad :rad @triage @open> per item, aria-live="polite". Tom → undervisande NcEmptyContent: "Här landar nya orosanmälningar. Inget otriagerat just nu — allt inkommande är omhändertaget. När en kommer ser du en 14-dagars nedräkning direkt." — AttTaEmotRad: props rad(TriageRad). Smal rad (NcListItem-stil): kanalikon via channelMeta(rad.channel.channel), avsändare + identitets-badge (rad.identitet.badge; visa legitimt "Ej verifierad — anonym" när !verifierad), inkom-tid (rad.inkomDatum, lokaliserad), <FristChip :frist="rad.frist"> (import ./FristChip.vue) som redan tickar (visa inget om rad.frist är null), <ProvenansChip :provenance="rad.provenance"> (import ./ProvenansChip.vue). Två primärknappar: "Ta emot & starta förhandsbedömning"(@triage(rad,"start")) och "Koppla till befintligt ärende"(@triage(rad,"koppla")) + "Visa"(@open(rad)). Titel = rad.titel (ärendereferens, ingen PII).'),
	build('MotesRemsa', [SS + '\\MotesRemsa.vue', SS + '\\MotesRad.vue'],
		'MotesRemsa (Zon 4): props meetings(Array). .hs-card rubrik "Mina möten idag". Renderar <MotesRad :meeting @join @godkann> per möte; tom → NcEmptyContent "Inga säkra möten idag". — MotesRad: props meeting({token,title,dnr,start,countdownMin,participants,verificationBadge,lobbyState:{waiting},hasCall}). Visar tid (start lokaliserad) + nedräkning ("börjar om {n} min" via meeting.countdownMin), dnr-chip (ärendekoppling), lobbystatus ("{n} i lobby · BankID-verifierad", grön/lila bock via verificationBadge), "Anslut säkert"-knapp @join(meeting). Kompakt en rad per möte. (Mötesefterspel/godkänn hanteras i ärendekortet; @godkann lämnas oanvänd här om ej relevant.)'),
	build('CommitGrind', [SS + '\\CommitGrind.vue'],
		'CommitGrind (överföringsdialog, KRITISK). props arende(Object), payload({typ,artefakter}). NcDialog. Visar: vad som förs över (handling + provenance + ev. signatur/kvittens beroende på payload.typ), destination "Treserva-akt · dnr {arende.dnr|| nytt}", och en TRE-STEGS Frends-progressindikator: "Skickat → Bekräftat (API-svar) → Registrerat" (spinner→bock per steg). Stor "För över"-knapp som kör en simulerad 3-stegs-progress (setTimeout i demo) och vid "Registrerat" emit @committed(result). Konsekvenstext: "Handlingen blir allmän handling i akten. Hubs-rummet gallras {datum} efter bekräftad överföring." Emit @close. Vid fel: stanna på "Skickat", felton (men i demo lyckas det). Steg-ikoner aldrig bara färg.'),
	build('OnboardingTour', [SS + '\\OnboardingTour.vue'],
		'OnboardingTour (dag-1-guide). props seen(Boolean). Dismissbar banner "Ny här? Så här hänger vyn ihop →" + 3 coach-steg i tur (enkel egen overlay/NcDialog, ej blockerande): (1) Dagspulsen ("dagens läge i fyra tal"), (2) ärendekortets stepper ("så här går ett barnärende — uppifrån och ned"), (3) Nästa-åtgärd-knappen ("tryck på knappen som lyser"). "Nästa"/"Hoppa över" alltid synlig. Sista steget + Hoppa över → emit @finish(). Lättviktig, "Hoppa över" alltid synlig.'),
])

phase('Review')
const REVIEW_SCHEMA = {
	type: 'object', additionalProperties: false, required: ['summary', 'findings'],
	properties: {
		summary: { type: 'string' },
		findings: { type: 'array', items: { type: 'object', additionalProperties: false, required: ['severity', 'file', 'issue', 'fix'], properties: { severity: { type: 'string', enum: ['blocker', 'major', 'minor'] }, file: { type: 'string' }, issue: { type: 'string' }, fix: { type: 'string' } } } },
	},
}
const review = await agent(
	`REVIEW the new socialsekreterare chrome components in ${SS} (MinDagHeader, Dagspulsen, VadVillDuGora, AttTaEmotSektion, AttTaEmotRad, MotesRemsa, MotesRad, CommitGrind, OnboardingTour) against ${HS}\\docs\\UX-REDESIGN-SOCIALSEKRETERARE.md and ${SS}\\MinaArenden.vue. Check: prop/event names match MinaArenden's wiring exactly; correct @nextcloud/vue v8 per-component imports; shared atoms imported (not rebuilt); Vue 2.7 options-API correctness; t('hubs_start',...) on all strings; no "Nextcloud"/"Talk"; no obvious template/script syntax errors; WCAG (targets, colour+icon+text). Return findings (severity/file/issue/fix).`,
	{ label: 'review:ss', phase: 'Review', schema: REVIEW_SCHEMA },
)

return { build: results, review }
