/**
 * Hubs Start — DEMO DATA for the redesigned socialsekreterare view ("Mina ärenden").
 *
 * ⚠️ DEMO ONLY. Drives the ärende-centric redesign (UX-REDESIGN-SOCIALSEKRETERARE.md)
 * with no backend. Assumes all blockers solved: Treserva-commit via Frends, Inera
 * signing, local transcription, Retention-paus — so actions are "real".
 *
 * Shapes: Arende (per ärendekort), TriageRad (Zon 1 inflöde), Puls (Dagspulsen),
 * Möte (Zon 4). Pseudonymised — never clear-text PII.
 */

// 🔌 DEMO-STUBBAR (se docs/DEMO-STUBS.md): den stateful Treserva/Frends-konnektorn
// och Kontakter-favorit-resolvern. Mutationer i demon går genom dessa så att UI:t
// hänger ihop (skapa→committa→gallra syns konsekvent).
import * as treserva from './treserva.js'
import favoriter from './favoriter.js'

const STEG = ['inflode', 'forhandsbedomning', 'utredning', 'beslut', 'uppfoljning', 'avslutat']

// --- helpers --------------------------------------------------------------
const frist = (typ, label, due, start, daysLeft, tone, extra = {}) => ({
	typ, label, due, start, daysLeft, tone,
	kalla: extra.kalla || ('Inkom ' + start),
	agare: 'Treserva (speglad via Frends)',
	paminnelser: extra.paminnelser || [],
})
const prov = (state, dnr, gallrasDatum, bevarasDatum) => ({ state, dnr: dnr || null, gallrasDatum: gallrasDatum || null, bevarasDatum: bevarasDatum || null })
const nasta = (action, label, flik, vantarPaMig = true) => ({ action, label, flik, vantarPaMig })

// --- Zon 1: otrierat inflöde (TriageRad) ----------------------------------
const triage = [
	{
		id: 'tri-1',
		channel: { channel: 'sdk', channelLabel: 'SDK-Meddelande', messageType: 'sdk_message' },
		avsandare: 'Skolkurator, Lindängsskolan',
		identitet: { badge: 'SITHS · LOA3', verifierad: true },
		titel: 'Orosanmälan – Barn 2026-0151',
		inkomDatum: '2026-06-14T07:58:00',
		frist: frist('forhandsbedomning', 'Förhandsbedömning', '2026-06-28', '2026-06-14', 13, 'neutral'),
		provenance: prov('ej_registrerad'),
	},
	{
		id: 'tri-2',
		channel: { channel: 'fax', channelLabel: 'Fax', messageType: 'fax_message' },
		avsandare: 'Privat anmälare (anonym)',
		identitet: { badge: 'Ej verifierad — anonym', verifierad: false },
		titel: 'Orosanmälan – Barn 2026-0152',
		inkomDatum: '2026-06-14T06:40:00',
		frist: frist('forhandsbedomning', 'Förhandsbedömning', '2026-06-28', '2026-06-14', 13, 'neutral'),
		provenance: prov('ej_registrerad'),
	},
	{
		id: 'tri-3',
		channel: { channel: 'secure', channelLabel: 'Säker E-post', messageType: 'secure_email' },
		avsandare: 'BUP Malmö',
		identitet: { badge: 'BankID · LOA3', verifierad: true },
		titel: 'Komplettering – läkarutlåtande (Barn 2026-0098)',
		inkomDatum: '2026-06-14T08:14:00',
		frist: null,
		provenance: prov('ej_registrerad'),
	},
	{
		id: 'tri-4',
		channel: { channel: 'sdk', channelLabel: 'SDK-Meddelande', messageType: 'sdk_message' },
		avsandare: 'Polismyndigheten',
		identitet: { badge: 'SITHS · LOA3', verifierad: true },
		titel: 'Orosanmälan – Barn 2026-0153',
		inkomDatum: '2026-06-13T16:20:00',
		frist: frist('forhandsbedomning', 'Förhandsbedömning', '2026-06-27', '2026-06-13', 12, 'neutral', { paminnelser: [] }),
		provenance: prov('ej_registrerad'),
	},
]

// --- Zon 2/3: ärenden (Arende) --------------------------------------------
const arenden = [
	// HOT 1 — gul 14-dgr-frist, nästa åtgärd "Fatta beslut"
	{
		dnr: '2026-IFO-0142', triageRef: 'SN 2026-0142', barnRef: 'Barn 2026-0142',
		steg: 'forhandsbedomning', substeg: null,
		sekretess: { kod: 'OSL 26 kap.', skyddadeUppgifter: false }, loa: 'LOA3',
		frist: frist('forhandsbedomning', 'Förhandsbedömning', '2026-06-16', '2026-06-02', 2, 'warning', { paminnelser: ['T-7 skickad', 'T-3 idag'] }),
		provenance: prov('registrerad', '2026-IFO-0142', '2026-09-16', '2031-06-16'),
		plikt: null,
		nastaAtgard: nasta('beslut-inleda', 'Fatta beslut: inleda / inte inleda utredning', 'beslut'),
		vantar: null,
		rum: { groupfolderId: 0, olasta: 3, acl: 'du skriver, gruppledare läser', dokument: ['Orosanmälan (SDK)', 'Förhandsbedömning (utkast)', 'Samtycke vårdnadshavare'] },
		meddelanden: [{ titel: 'Svar från skola', status: 'last', dnr: '2026-IFO-0142' }],
		moten: [{ titel: 'Säkert möte – vårdnadshavare', start: '2026-06-13T10:10:00', godkand: true }],
		bevakningar: [{ titel: 'Fatta beslut inom 14 dgr', frist: '2026-06-16', delad: true }],
		beslut: null,
	},
	// HOT 2 — röd förfallen frist + okvitterad pliktmarkör (skyddsbedömning)
	{
		dnr: null, triageRef: 'SN 2026-0149', barnRef: 'Barn 2026-0149',
		steg: 'forhandsbedomning', substeg: null,
		sekretess: { kod: 'OSL 26 kap.', skyddadeUppgifter: true }, loa: 'LOA3',
		frist: frist('forhandsbedomning', 'Förhandsbedömning', '2026-06-13', '2026-05-30', -1, 'error', { paminnelser: ['T-7 skickad', 'T-3 skickad', 'T-0 idag'] }),
		provenance: prov('ej_registrerad'),
		plikt: { typ: 'skyddsbedomning', label: 'Skyddsbedömning krävs idag — måste committas', kvitterad: false },
		nastaAtgard: nasta('skyddsbedomning', 'Dokumentera & committa skyddsbedömning', 'beslut'),
		vantar: null,
		rum: { groupfolderId: 0, olasta: 1, acl: 'du skriver', dokument: ['Orosanmälan (polis, SDK)'] },
		meddelanden: [], moten: [], bevakningar: [], beslut: null,
	},
	// HOT 3 — väntar på godkännande av mötesanteckning
	{
		dnr: '2026-IFO-0412', triageRef: 'SN 2026-0412', barnRef: 'Barn 2026-0412',
		steg: 'utredning', substeg: 'kommunicera',
		sekretess: { kod: 'OSL 26 kap.', skyddadeUppgifter: false }, loa: 'LOA3',
		frist: frist('utredning', 'Utredning', '2026-09-30', '2026-05-30', 108, 'neutral'),
		provenance: prov('registrerad', '2026-IFO-0412', '2026-12-30', '2031-09-30'),
		plikt: null,
		nastaAtgard: nasta('godkann-anteckning', 'Granska & godkänn mötesanteckning', 'moten'),
		vantar: 'motesanteckning',
		rum: { groupfolderId: 0, olasta: 0, acl: 'du skriver, gruppledare läser', dokument: ['Utredning BBIC (utkast)', 'Läkarintyg (region)'] },
		meddelanden: [],
		moten: [{ titel: 'SIP-möte hemtjänst', start: '2026-06-14T10:00:00', godkand: false, transkript: 'Transkript klart (KB-Whisper, lokalt).', aiUtkast: 'AI-utkast: sammanfattning, beslut, åtgärdslista. Flaggat: innehåller känsliga uppgifter — sekretessprövas.' }],
		bevakningar: [{ titel: 'Godkänn mötesanteckning', frist: '2026-06-16', delad: false }],
		beslut: null,
	},
	// Active — utredning, signering pågår (AES, Öppnat 0/1)
	{
		dnr: '2026-IFO-0071', triageRef: 'SN 2026-0071', barnRef: 'Barn 2026-0071',
		steg: 'beslut', substeg: null,
		sekretess: { kod: 'OSL 26 kap.', skyddadeUppgifter: false }, loa: 'LOA3',
		frist: frist('overklagande', 'Inväntar underskrift', '2026-06-16', '2026-06-14', 2, 'warning'),
		provenance: prov('ej_registrerad'),
		plikt: null,
		nastaAtgard: nasta('signera', 'Skicka beslut för underskrift', 'beslut'),
		vantar: null,
		rum: { groupfolderId: 0, olasta: 0, acl: 'du skriver', dokument: ['Beslut om insats (PDF/A)'] },
		meddelanden: [], moten: [],
		bevakningar: [],
		beslut: { kravniva: 'AES', signStatus: 'Öppnat 0/1', bevarande: { pades: true, pdfa: true, ltv: true } },
	},
	// Active — beslut delgivet, uppföljning, överklagandefrist löper
	{
		dnr: '2026-IFO-0033', triageRef: 'SN 2026-0033', barnRef: 'Barn 2026-0033',
		steg: 'uppfoljning', substeg: null,
		sekretess: { kod: 'OSL 26 kap.', skyddadeUppgifter: false }, loa: 'LOA3',
		frist: frist('overklagande', 'Överklagandefrist', '2026-07-01', '2026-06-10', 17, 'neutral'),
		provenance: prov('registrerad', '2026-IFO-0033', '2026-12-10', 'Bevaras'),
		plikt: null,
		nastaAtgard: nasta('bevakning', 'Sätt uppföljningsbevakning', 'bevakningar', false),
		vantar: null,
		rum: { groupfolderId: 0, olasta: 0, acl: 'du skriver', dokument: ['SIP-plan', 'Samtycke SIP'] },
		meddelanden: [], moten: [], bevakningar: [{ titel: 'Följ upp insats', frist: '2026-07-15', delad: false }], beslut: null,
	},
	// Active — tidsbegränsat beslut upphör snart (uppföljning)
	{
		dnr: '2026-IFO-0054', triageRef: 'SN 2026-0054', barnRef: 'Barn 2026-0054',
		steg: 'uppfoljning', substeg: null,
		sekretess: { kod: 'OSL 26 kap.', skyddadeUppgifter: false }, loa: 'LOA3',
		frist: frist('tidsbegransat', 'Tidsbegränsat beslut upphör', '2026-06-30', '2026-03-30', 16, 'neutral', { paminnelser: ['T-7 schemalagd'] }),
		provenance: prov('registrerad', '2026-IFO-0054', '2026-12-30', 'Bevaras'),
		plikt: null,
		nastaAtgard: nasta('bevakning', 'Boka uppföljningsmöte före 30/6', 'bevakningar', false),
		vantar: null,
		rum: { groupfolderId: 0, olasta: 1, acl: 'du skriver', dokument: [] }, meddelanden: [], moten: [], bevakningar: [], beslut: null,
	},
]

// Bulk of Zon 3 — many active across stages so the segment filter has content.
const stageBulk = [
	['utredning', 8, 'Färdigställ utredning & för till Treserva', 'commit-utredning', 'dokument'],
	['beslut', 3, 'Skicka beslut för underskrift', 'signera', 'beslut'],
	['uppfoljning', 4, 'Sätt uppföljningsbevakning', 'bevakning', 'bevakningar'],
	['forhandsbedomning', 1, 'Fatta beslut: inleda / inte inleda utredning', 'beslut-inleda', 'beslut'],
]
let seq = 200
for (const [steg, n, label, action, flik] of stageBulk) {
	for (let i = 0; i < n; i++) {
		seq++
		const dnr = '2026-IFO-0' + seq
		arenden.push({
			dnr, triageRef: 'SN 2026-0' + seq, barnRef: 'Barn 2026-0' + seq,
			steg, substeg: null,
			sekretess: { kod: 'OSL 26 kap.', skyddadeUppgifter: false }, loa: 'LOA3',
			frist: steg === 'utredning'
				? frist('utredning', 'Utredning', '2026-09-20', '2026-05-20', 98, 'neutral')
				: steg === 'forhandsbedomning'
					? frist('forhandsbedomning', 'Förhandsbedömning', '2026-06-24', '2026-06-10', 10, 'neutral')
					: frist('overklagande', 'Uppföljning', '2026-08-01', '2026-06-01', 48, 'neutral'),
			provenance: prov('registrerad', dnr, '2027-01-01', 'Bevaras'),
			plikt: null,
			nastaAtgard: nasta(action, label, flik, false),
			vantar: null,
			rum: { groupfolderId: 0, olasta: 0, acl: 'du skriver', dokument: [] },
			meddelanden: [], moten: [], bevakningar: [], beslut: steg === 'beslut' ? { kravniva: 'AES', signStatus: 'Skickat 0/1', bevarande: { pades: true, pdfa: true, ltv: false } } : null,
		})
	}
}

// --- Multi-korg inflöde, koppling, tilldelning, diskussion, fördelning -------

// Korgar (KorgValjare-piller) — behörighetsfiltrerade i prod.
const korgar = [
	{ addr: 'personlig', label: 'Personlig', scope: 'personlig', otriagerat: 2 },
	{ addr: 'mottagningen@', label: 'mottagningen@', scope: 'grupp', otriagerat: 5 },
	{ addr: 'barn-familj@', label: 'barn-familj@', scope: 'grupp', otriagerat: 4 },
	{ addr: 'orosanmalan@', label: 'orosanmalan@', scope: 'grupp', otriagerat: 3 },
	{ addr: 'fax', label: 'Fax', scope: 'fax', otriagerat: 1 },
	{ addr: 'sdk', label: 'SDK', scope: 'sdk', otriagerat: 2 },
]

const korg = (addr, label, scope) => ({ addr, label: label || addr, scope })
const id6 = (() => { let i = 0; return () => 'inf-' + (++i) })()

// Inflöde över alla tre band: nytt-ärende (1a), hör-till (1b), ej-kopplat (1c).
const inflode = [
	// 1a — nytt ärende (orosanmälningar) — speglar triage
	{ id: id6(), kind: 'inflode', arendekoppling: 'nytt', korg: korg('orosanmalan@', 'orosanmalan@', 'grupp'), channel: { channel: 'sdk', channelLabel: 'SDK-Meddelande', messageType: 'orosanmalan' }, messageType: 'orosanmalan', koppling: { status: 'ej' }, avsandare: 'Skolkurator, Lindängsskolan', identitet: { badge: 'SITHS · LOA3', verifierad: true }, titel: 'Orosanmälan – Barn 2026-0151', inkomDatum: '2026-06-14T07:58:00', frist: frist('forhandsbedomning', 'Förhandsbedömning', '2026-06-28', '2026-06-14', 13, 'neutral'), provenance: prov('ej_registrerad') },
	{ id: id6(), kind: 'inflode', arendekoppling: 'nytt', korg: korg('fax', 'Fax', 'fax'), channel: { channel: 'fax', channelLabel: 'Fax', messageType: 'orosanmalan' }, messageType: 'orosanmalan', koppling: { status: 'ej' }, avsandare: 'Privat anmälare (anonym)', identitet: { badge: 'Ej verifierad — anonym', verifierad: false }, titel: 'Orosanmälan – Barn 2026-0152', inkomDatum: '2026-06-14T06:40:00', frist: frist('forhandsbedomning', 'Förhandsbedömning', '2026-06-28', '2026-06-14', 13, 'neutral'), provenance: prov('ej_registrerad') },
	// 1b — hör till befintligt ärende (komplettering/medborgarsvar/remiss/internpost)
	{ id: id6(), kind: 'inflode', arendekoppling: 'hor_till', korg: korg('barn-familj@', 'barn-familj@', 'grupp'), channel: { channel: 'sdk', channelLabel: 'SDK-Meddelande', messageType: 'komplettering' }, messageType: 'komplettering', koppling: { status: 'kopplad', barnRef: 'Barn 2026-0142', dnr: '2026-IFO-0142', konfidens: 0.96 }, avsandare: 'Skola, Lindängsskolan', identitet: { badge: 'SITHS · LOA3', verifierad: true }, titel: 'Pedagogisk kartläggning (komplettering)', inkomDatum: '2026-06-14T08:14:00', frist: null, provenance: prov('registrerad', '2026-IFO-0142') },
	{ id: id6(), kind: 'inflode', arendekoppling: 'hor_till', korg: korg('personlig', 'Personlig', 'personlig'), channel: { channel: 'secure', channelLabel: 'Säker E-post', messageType: 'fraga' }, messageType: 'fraga', koppling: { status: 'kopplad', barnRef: 'Barn 2026-0412', dnr: '2026-IFO-0412', konfidens: 0.98 }, avsandare: 'Vårdnadshavare (BankID)', identitet: { badge: 'BankID · LOA3', verifierad: true }, titel: 'Medborgarsvar – fråga om mötestid', inkomDatum: '2026-06-14T07:30:00', frist: null, provenance: prov('registrerad', '2026-IFO-0412') },
	{ id: id6(), kind: 'inflode', arendekoppling: 'hor_till', korg: korg('barn-familj@', 'barn-familj@', 'grupp'), channel: { channel: 'secure', channelLabel: 'Säker E-post', messageType: 'remiss' }, messageType: 'remiss', koppling: { status: 'kopplad', barnRef: 'Barn 2026-0098', dnr: '2026-IFO-0098', konfidens: 0.93 }, avsandare: 'BUP Malmö', identitet: { badge: 'BankID · LOA3', verifierad: true }, titel: 'Läkarutlåtande (remissvar)', inkomDatum: '2026-06-14T08:02:00', frist: null, provenance: prov('registrerad', '2026-IFO-0098') },
	{ id: id6(), kind: 'inflode', arendekoppling: 'hor_till', korg: korg('personlig', 'Personlig', 'personlig'), channel: { channel: 'internal', channelLabel: 'Internpost', messageType: 'internpost' }, messageType: 'internpost', koppling: { status: 'kopplad', barnRef: 'Barn 2026-0142', dnr: '2026-IFO-0142', konfidens: 0.99 }, avsandare: 'Eva (gruppledare)', identitet: { badge: 'Internt · LOA3', verifierad: true }, titel: 'Internpost – inför beslut inleda', inkomDatum: '2026-06-14T09:05:00', frist: null, provenance: prov('registrerad', '2026-IFO-0142') },
	// 1c — ej ärendekopplat (oklart/skräp/fel mottagare)
	{ id: id6(), kind: 'inflode', arendekoppling: 'ej_kopplat', korg: korg('mottagningen@', 'mottagningen@', 'grupp'), channel: { channel: 'fax', channelLabel: 'Fax', messageType: 'fax' }, messageType: 'fax', klassning: { typ: 'oklart', forslag: 'gallra', varfor: 'autosvar-mönster' }, koppling: { status: 'ej' }, avsandare: 'Okänd', identitet: { badge: 'Ej verifierad', verifierad: false }, titel: 'Inkommande fax – oklassat', inkomDatum: '2026-06-11T13:02:00', alder: { dagar: 3, overSla: true }, foreslagenAtgard: 'gallra', frist: null, provenance: prov('ej_registrerad') },
	{ id: id6(), kind: 'inflode', arendekoppling: 'ej_kopplat', korg: korg('mottagningen@', 'mottagningen@', 'grupp'), channel: { channel: 'secure', channelLabel: 'Säker E-post', messageType: 'fraga' }, messageType: 'fraga', klassning: { typ: 'allman_fraga', forslag: 'besvara', varfor: 'allmän fråga, inget barn nämns' }, koppling: { status: 'ej' }, avsandare: 'Medborgare', identitet: { badge: 'BankID · LOA3', verifierad: true }, titel: 'Allmän fråga om handläggningstider', inkomDatum: '2026-06-13T10:20:00', alder: { dagar: 1, overSla: false }, foreslagenAtgard: 'besvara', frist: null, provenance: prov('ej_registrerad') },
	{ id: id6(), kind: 'inflode', arendekoppling: 'ej_kopplat', korg: korg('barn-familj@', 'barn-familj@', 'grupp'), channel: { channel: 'sdk', channelLabel: 'SDK-Meddelande', messageType: 'fel_mottagare' }, messageType: 'skrap', klassning: { typ: 'fel_mottagare', forslag: 'vidarebefordra', varfor: 'avser vuxenenheten' }, koppling: { status: 'foreslagen', barnRef: 'Barn 2026-0207', dnr: null, triageRef: 'SN 2026-0207', konfidens: 0.62 }, avsandare: 'Region – vuxenpsykiatri', identitet: { badge: 'SITHS · LOA3', verifierad: true }, titel: 'Meddelande – möjlig felrouting', inkomDatum: '2026-06-12T15:40:00', alder: { dagar: 2, overSla: false }, foreslagenAtgard: 'vidarebefordra', frist: null, provenance: prov('ej_registrerad') },
]

// Per-ärende-anrikning (full diskussion + provenanskedja, lazy via fetchArende).
const enrichments = {
	'2026-IFO-0142': {
		diskussion: {
			olasta: 3, omnamnandeTillMig: true,
			deltagare: [{ uid: 'anna', namn: 'Anna (handläggare)', roll: 'skriv' }, { uid: 'eva', namn: 'Eva (gruppledare)', roll: 'läs/skriv' }],
			sekretess: { kod: 'OSL 26 kap.', niva: 'intern_arbetsmaterial' },
			meddelanden: [
				{ id: 'm1', fran: 'Anna', text: '@Eva kan du medbedöma inför beslut att inleda?', tid: '2026-06-14T09:10', mention: ['eva'] },
				{ id: 'm2', fran: 'Eva', text: 'Ja — historiken talar för utredning. Jag tittar på den i eftermiddag.', tid: '2026-06-14T09:18', mention: [] },
			],
		},
		provenansKedja: ['Inkom via SDK 10/6', 'förhandsbedömd av Anna (mottagning)', 'inledd 13/6', 'fördelad till mig av Eva 14/6', '→ Treserva: registrerad, dnr 2026-IFO-0142'],
	},
	'2026-IFO-0412': {
		diskussion: { olasta: 1, omnamnandeTillMig: false, deltagare: [{ uid: 'anna', namn: 'Anna', roll: 'skriv' }], sekretess: { kod: 'OSL 26 kap.', niva: 'intern_arbetsmaterial' }, meddelanden: [{ id: 'm1', fran: 'Mia', text: 'SIP-mötet är bokat, samtycke inhämtat.', tid: '2026-06-13T16:00', mention: [] }] },
		provenansKedja: ['Inkom via säker e-post 30/5', 'inledd 30/5', '→ Treserva: registrerad, dnr 2026-IFO-0412'],
	},
}

// Tilldelning + diskussion-summary på varje ärendekort (för TilldelningBand + DiskussionChip).
arenden.forEach((a, i) => {
	a.tilldelning = a.tilldelning || {
		status: 'tilldelat', agareUid: 'anna', agareNamn: 'Anna',
		fran: i % 4 === 0 ? 'mottagning' : null,
		tilldeladAv: 'Eva', tilldeladDatum: '2026-06-14', nyFor24h: false,
	}
	a.diskussion = a.diskussion || { olasta: 0, omnamnandeTillMig: false }
})
const _byDnr = Object.fromEntries(arenden.filter((a) => a.dnr).map((a) => [a.dnr, a]))
if (_byDnr['2026-IFO-0142']) { _byDnr['2026-IFO-0142'].tilldelning.nyFor24h = true; _byDnr['2026-IFO-0142'].tilldelning.fran = 'mottagning'; _byDnr['2026-IFO-0142'].diskussion = { olasta: 3, omnamnandeTillMig: true } }
if (_byDnr['2026-IFO-0412']) { _byDnr['2026-IFO-0412'].diskussion = { olasta: 1, omnamnandeTillMig: false } }

// Gruppledarens fördelningsvy.
const fordelningSummary = {
	attFordela: [
		{ dnr: null, triageRef: 'SN 2026-0149', barnRef: 'Barn 2026-0149', steg: 'utredning', sekretess: { kod: 'OSL 26 kap.', skyddadeUppgifter: true }, loa: 'LOA3', frist: frist('utredning', 'Utredning', '2026-10-13', '2026-06-13', 121, 'neutral'), provenance: prov('registrerad', '2026-IFO-0149'), plikt: null, nastaAtgard: nasta('fordela', 'Fördela till utredare', 'oversikt'), forslag: { utfall: 'inleda', motiv: 'Tidigare LVU-historik' }, ofordeladDagar: 1, tilldelning: { status: 'otilldelat' }, diskussion: { olasta: 0, omnamnandeTillMig: false } },
		{ dnr: null, triageRef: 'SN 2026-0155', barnRef: 'Barn 2026-0155', steg: 'utredning', sekretess: { kod: 'OSL 26 kap.', skyddadeUppgifter: false }, loa: 'LOA3', frist: frist('utredning', 'Utredning', '2026-10-14', '2026-06-14', 122, 'neutral'), provenance: prov('registrerad', '2026-IFO-0155'), plikt: null, nastaAtgard: nasta('fordela', 'Fördela till utredare', 'oversikt'), forslag: { utfall: 'inleda', motiv: 'Upprepade anmälningar' }, ofordeladDagar: 0, tilldelning: { status: 'otilldelat' }, diskussion: { olasta: 0, omnamnandeTillMig: false } },
		{ dnr: null, triageRef: 'SN 2026-0158', barnRef: 'Barn 2026-0158', steg: 'utredning', sekretess: { kod: 'OSL 26 kap.', skyddadeUppgifter: false }, loa: 'LOA3', frist: frist('utredning', 'Utredning', '2026-10-10', '2026-06-10', 118, 'warning'), provenance: prov('registrerad', '2026-IFO-0158'), plikt: null, nastaAtgard: nasta('fordela', 'Fördela till utredare', 'oversikt'), forslag: { utfall: 'inleda', motiv: 'Skola + BVC samstämmiga' }, ofordeladDagar: 4, tilldelning: { status: 'otilldelat' }, diskussion: { olasta: 0, omnamnandeTillMig: false } },
	],
	utredare: [
		{ namn: 'Anna', aktiva: 12, roda: 1, naraTak: false },
		{ namn: 'Sara', aktiva: 8, roda: 0, naraTak: false },
		{ namn: 'Mia', aktiva: 19, roda: 2, naraTak: true },
		{ namn: 'Johan', aktiva: 6, roda: 0, naraTak: false },
		{ namn: 'Karin', aktiva: 14, roda: 1, naraTak: false },
		{ namn: 'Omar', aktiva: 10, roda: 0, naraTak: false },
	],
	mottagningPagaende: 5,
}

const team = [
	{ id: 'mottagningen', label: 'Mottagningsgruppen', olasta: 4, omnamnanden: 1 },
	{ id: 'barn-familj', label: 'Barn & familj-enheten', olasta: 2, omnamnanden: 0 },
	{ id: 'samverkan', label: 'Samverkan skola/region', olasta: 0, omnamnanden: 0 },
]

const puls = { fristerBrinner: 2, motenIdag: 1, attSignera: 1, nyaInflode: inflode.length, omnamnanden: 2 }

const moten = [
	{ token: 'demomotea', title: 'SIP – Barn 2026-0412', dnr: '2026-IFO-0412', start: '2026-06-14T10:00:00', countdownMin: 38, participants: 2, verificationBadge: 'green', lobbyState: { waiting: 2 }, hasCall: true },
]

const klartIdag = 7

// 🔌 SEAM[treserva.seed]: seeda det kanoniska registret ur demo-ärendena (sätter
// hubsCaseId på varje ärende). I prod finns registret redan i Tables (hubs_arenden).
treserva.seedRegister(arenden)

// Collapsed list for the summary (strip heavy flik content).
const collapsed = arenden.map((a) => {
	const { rum, meddelanden, moten: m, bevakningar, beslut, ...rest } = a
	return rest
})

export default {
	fetchArendeSummary: () => JSON.parse(JSON.stringify({ puls, triage, arenden: collapsed, moten, klartIdag, steg: STEG })),
	fetchArende: (dnr) => {
		const a = arenden.find((x) => x.dnr === dnr || x.triageRef === dnr)
		if (!a) return null
		const extra = (a.dnr && enrichments[a.dnr]) || {}
		return JSON.parse(JSON.stringify({ ...a, ...extra }))
	},
	// Multi-korg inflöde (KorgValjare + de tre banden 1a/1b/1c).
	fetchInflodeSummary: () => JSON.parse(JSON.stringify({ korgar, inflode })),
	// Gruppledarens fördelningsvy (roll-läge 'fordelning').
	fetchFordelningSummary: () => JSON.parse(JSON.stringify(fordelningSummary)),
	// Team-/enhetschatt (sidopanel).
	fetchTeam: () => JSON.parse(JSON.stringify(team)),
	// Kontakter-favoriter (resolverlagret) — driver FavoritValjare.
	fetchFavoriter: (opts) => JSON.parse(JSON.stringify(favoriter.fetchFavoriter(opts))),
	// 🔌 SEAM[treserva.commit]: går genom den stateful Treserva-stubben (verifierat
	// kvitto + retention startar på commit). I prod: Frends→Treserva-konnektorn.
	commitToTreserva: (payload) => treserva.commitHandling(payload),
	// 🔌 SEAM[treserva.skapa]: atomär "skapa ärende" → nytt ärende-objekt.
	skapaArende: (rad) => JSON.parse(JSON.stringify(treserva.skapaArende(rad))),
	// 🔌 SEAM[treserva.koppla]: koppla inflöde till befintligt ärende.
	kopplaInflode: (rad, hubsCaseId) => treserva.kopplaInflode(rad, hubsCaseId),
	// Verifierade Treserva-kvittenser (kvittens-/retention-ytan).
	fetchReceipts: () => JSON.parse(JSON.stringify(treserva.listReceipts())),
	triage,
}
