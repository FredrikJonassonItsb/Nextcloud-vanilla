/**
 * Hubs Start — socialsekreterare ärende state machine.
 *
 * (processteg + tillstånd) → "Nästa åtgärd". Shared by NastaAtgardKnapp (label)
 * and MinaArenden (routing). The walkthrough's 51 steps collapse to: read the
 * cards, press the button that lights up. See UX-REDESIGN-SOCIALSEKRETERARE.md.
 */

export const NASTA_ATGARD = {
	steg: {
		inflode: { label: 'Ta emot & starta förhandsbedömning', action: 'triage', flik: 'oversikt' },
		forhandsbedomning: { label: 'Fatta beslut: inleda / inte inleda utredning', action: 'beslut-inleda', flik: 'beslut' },
		utredning: { label: 'Färdigställ utredning & för till Treserva', action: 'commit-utredning', flik: 'dokument' },
		beslut: { label: 'Skicka beslut för underskrift', action: 'signera', flik: 'beslut' },
		// Terminal-steget: avsluta ärendet (steg → 'avslutat'). Uppföljnings-bevakning
		// sätts via 🔔-snabbåtgärden; nästa-åtgärd här DRIVER mot avslut så hela resan
		// går att slutföra (förut 'bevakning' → Deck, som aldrig nådde 'avslutat').
		uppfoljning: { label: 'Avsluta ärende', action: 'avsluta', flik: 'oversikt' },
		// Avslutade ärenden visas inte i arbetsvyn (backend filtrerar bort
		// steg=avslutat ur dashboardArenden), så en åtgärdsknapp här vore död/
		// vilseledande. Detta är en ren status-fallback — INTE en åtgärd:
		// action: null gör att MinaArenden.onNastaAtgard faller till default
		// (expandera kortet), och nastaFor() kan fortsatt använda posten som
		// krasch-säkert fallback (NastaAtgardKnapp läser .label ovillkorligt).
		// Steppern behåller Avslutat-noden som processindikator (PROCESS_STEG).
		avslutat: { label: 'Arkiverat i verksamhetssystemet', action: null, flik: 'oversikt' },
	},
	overrides: {
		motesanteckning: { label: 'Granska & godkänn mötesanteckning', action: 'godkann-anteckning', flik: 'moten' },
		signaturkvittens: { label: 'Delge beslut', action: 'commit', flik: 'beslut' },
	},
}

/** Ordered process steps for the stepper. */
export const PROCESS_STEG = [
	{ id: 'forhandsbedomning', label: 'Förhandsbedömning' },
	{ id: 'utredning', label: 'Utredning' },
	{ id: 'beslut', label: 'Beslut' },
	{ id: 'uppfoljning', label: 'Uppföljning' },
	{ id: 'avslutat', label: 'Avslutat' },
]

/**
 * STEG_INNEHALL — den deklarativa steg-innehållsmodellen (A3). ENDA sanningskällan
 * för (1) processteppern (avläsning/hover), (2) härledningen klar/saknas per
 * delmoment ({@see harledStatus}), (3) grindarnas UI-copy och (4) HandlingModals
 * mall-gruppering. Definition-of-done skrivs på ETT ställe.
 *
 * Lagreferenser verifierade mot Socialtjänstlag (2025:400, i kraft 2026-07-01):
 * 20 kap. 1 § omedelbar skyddsbedömning, 20 kap. 2 § förhandsbedömning (14 dgr),
 * 20 kap. 3 § utredning (4 mån). `verifieraParagraf: true` = paragrafnumret bör
 * dubbelkollas (t.ex. övervägande/genomförandeplan i nya SoL) och renderas med *.
 *
 * Varje delmoment:
 *  - id/label/vadDetAr  — visning
 *  - lagreferens        — {lag, paragraf, verifieraParagraf}
 *  - frist              — {dagar, ankare} eller null
 *  - niva               — 'obligatorisk' | 'rekommenderad'
 *  - artefakt           — mall-nyckel (semantisk klass, matchas mot journalens detalj.mall)
 *  - klarNar            — {signal, match}: hur "klar" härleds ur befintliga signaler
 *      signal ∈ 'handling' | 'kvittens' | 'commit' | 'bevakning' | 'part' | 'system' | 'manuell'
 *      match  = nyckelord (substring, skiftlägesokänsligt) mot mall-id/bevaknings-typ/part-roll
 */
export const STEG_INNEHALL = {
	inflode: {
		label: 'Inflöde',
		villkorad: true,
		kortBeskrivning: 'Anmälan tas emot och dokumenteras; 14-dagarsklockan startar på inkomstdatum.',
		frist: null,
		delmoment: [
			{ id: 'anmalan_dokumenterad', label: 'Anmälan dokumenterad', vadDetAr: 'Inkomstdatum, källa och oro i sak registreras.',
				lagreferens: { lag: 'SoL', paragraf: '20 kap.', verifieraParagraf: true }, frist: null, niva: 'obligatorisk',
				artefakt: 'mottagen-orosanmalan', klarNar: { signal: 'system', match: '' } },
			{ id: 'klocka_startad', label: '14-dagarsklockan startad', vadDetAr: 'Förhandsbedömningsfristen ankras på inkomstdatum.',
				lagreferens: { lag: 'SoL', paragraf: '20 kap. 2 §', verifieraParagraf: false }, frist: null, niva: 'obligatorisk',
				artefakt: null, klarNar: { signal: 'system', match: '' } },
		],
	},
	forhandsbedomning: {
		label: 'Förhandsbedömning',
		kortBeskrivning: 'Omedelbar skyddsbedömning samma dag och beslut inleda/inte inleda utredning inom 14 dagar.',
		frist: { dagar: 14, ankare: 'inkom', lag: 'SoL 20 kap. 2 §' },
		delmoment: [
			{ id: 'skyddsbedomning', label: 'Omedelbar skyddsbedömning', vadDetAr: 'Akut skyddsbehov bedöms samma dag – även vid Nej.',
				lagreferens: { lag: 'SoL', paragraf: '20 kap. 1 §', verifieraParagraf: false }, frist: { dagar: 0, ankare: 'inkom' }, niva: 'obligatorisk',
				artefakt: 'skyddsbedomning', klarNar: { signal: 'handling', match: 'skyddsbedom' } },
			{ id: 'skyddsbedomning_kvittens', label: 'Skyddsbedömning kvitterad', vadDetAr: 'Grinden mot utredning – kräver att skyddsbedömningen finns som handling.',
				lagreferens: { lag: 'SoL', paragraf: '20 kap. 1 §', verifieraParagraf: false }, frist: null, niva: 'obligatorisk',
				artefakt: null, klarNar: { signal: 'kvittens', match: 'skyddsbedomning' } },
			{ id: 'beslut_inleda', label: 'Beslut: inleda / inte inleda', vadDetAr: 'Motiverat beslut inom 14 dagar; motivering särskilt viktig vid "inte inleda".',
				lagreferens: { lag: 'SoL', paragraf: '20 kap. 2 §', verifieraParagraf: false }, frist: { dagar: 14, ankare: 'inkom' }, niva: 'obligatorisk',
				artefakt: 'forhandsbedomning', klarNar: { signal: 'handling', match: 'forhandsbedom' } },
		],
	},
	utredning: {
		label: 'Utredning',
		kortBeskrivning: 'Utredningsplan, BBIC-utredning med barnets egen röst, och kommunicering med parterna innan beslut.',
		frist: { dagar: 120, ankare: 'steg', lag: 'SoL 20 kap. 3 §' },
		delmoment: [
			{ id: 'utredningsplan', label: 'Utredningsplan', vadDetAr: 'Frågeställningar, BBIC-urval, samtycke och tidsplan.',
				lagreferens: { lag: 'SoL', paragraf: '20 kap. 3 §', verifieraParagraf: false }, frist: null, niva: 'obligatorisk',
				artefakt: 'utredningsplan', klarNar: { signal: 'handling', match: 'utredningsplan' } },
			{ id: 'bbic_utredning', label: 'BBIC-utredning', vadDetAr: 'Triangelns tre sidor, källa vs bedömning, analys och förslag.',
				lagreferens: { lag: 'SoL', paragraf: '20 kap. 3 §', verifieraParagraf: false }, frist: { dagar: 120, ankare: 'steg' }, niva: 'obligatorisk',
				artefakt: 'bbic-utredning', klarNar: { signal: 'handling', match: 'bbic' } },
			{ id: 'barnets_rost', label: 'Barnets egen röst', vadDetAr: 'Enskilt barnsamtal dokumenterat.',
				lagreferens: { lag: 'Barnkonventionen', paragraf: 'art. 12', verifieraParagraf: false }, frist: null, niva: 'obligatorisk',
				artefakt: 'barnsamtal', klarNar: { signal: 'handling', match: 'barnsamtal' } },
			{ id: 'kommunicering', label: 'Kommunicering med parterna', vadDetAr: 'Parterna får ta del och yttra sig före beslut.',
				lagreferens: { lag: 'FL', paragraf: '25 §', verifieraParagraf: false }, frist: null, niva: 'obligatorisk',
				artefakt: 'kommunicering', klarNar: { signal: 'handling', match: 'kommunicer' } },
			{ id: 'lopande_journal', label: 'Löpande journal', vadDetAr: 'Fortlöpande anteckningar under utredningen.',
				lagreferens: { lag: 'SoL', paragraf: '15 kap.', verifieraParagraf: true }, frist: null, niva: 'rekommenderad',
				artefakt: 'journalanteckning', klarNar: { signal: 'handling', match: 'journal' } },
		],
	},
	beslut: {
		label: 'Beslut',
		kortBeskrivning: 'Kommunicering inför beslut, motiverat beslut med underskrift, och delgivning med styrkt delfående.',
		frist: { dagar: 21, ankare: 'delgivning', lag: 'FL 44 § (överklagande)' },
		delmoment: [
			{ id: 'kommunicering_beslut', label: 'Kommunicering inför beslut', vadDetAr: 'Menprövning tredje man, skälig svarstid.',
				lagreferens: { lag: 'FL', paragraf: '25 §', verifieraParagraf: false }, frist: null, niva: 'obligatorisk',
				artefakt: 'kommunicering', klarNar: { signal: 'handling', match: 'kommunicer' } },
			{ id: 'beslut_committat', label: 'Beslut fattat & registrerat', vadDetAr: 'Verifierad registrering i facksystemet – det starkaste beviset.',
				lagreferens: { lag: 'SoL', paragraf: '28 kap.', verifieraParagraf: true }, frist: null, niva: 'obligatorisk',
				artefakt: 'beslut', klarNar: { signal: 'commit', match: '' } },
			{ id: 'delgivning', label: 'Delgivning m. styrkt delfående', vadDetAr: 'Underrättelse + överklagandehänvisning; fristen startar på delfående.',
				lagreferens: { lag: 'FL', paragraf: '33 §, 43–44 §§', verifieraParagraf: false }, frist: null, niva: 'obligatorisk',
				artefakt: 'delgivning', klarNar: { signal: 'bevakning', match: 'overklagande' } },
		],
	},
	uppfoljning: {
		label: 'Uppföljning',
		kortBeskrivning: 'Genomförandeplan och lagstadgat övervägande/omprövning minst var sjätte månad.',
		frist: { dagar: 180, ankare: 'steg', lag: 'LVU 13 § / SoL övervägande' },
		delmoment: [
			{ id: 'genomforandeplan', label: 'Genomförandeplan', vadDetAr: 'Mål, aktiviteter med ansvarig och tidpunkt.',
				lagreferens: { lag: 'SoL', paragraf: '20 kap.', verifieraParagraf: true }, frist: null, niva: 'obligatorisk',
				artefakt: 'genomforandeplan', klarNar: { signal: 'handling', match: 'genomforande' } },
			{ id: 'omprovning', label: 'Övervägande / omprövning satt', vadDetAr: '6-månadersbevakning skapas automatiskt av systemet.',
				lagreferens: { lag: 'LVU', paragraf: '13 §', verifieraParagraf: false }, frist: { dagar: 180, ankare: 'steg' }, niva: 'obligatorisk',
				artefakt: null, klarNar: { signal: 'bevakning', match: 'overvagande' } },
		],
	},
	avslutat: {
		label: 'Avslutat',
		kortBeskrivning: 'Avslutsanteckning med utfall och gallrings-/bevarandebedömning.',
		frist: null,
		delmoment: [
			{ id: 'avslutsanteckning', label: 'Avslutsanteckning m. utfall', vadDetAr: 'Utfall, sammanfattning, kvarstående behov och överlämning.',
				lagreferens: { lag: 'SoL', paragraf: '15 kap.', verifieraParagraf: true }, frist: null, niva: 'obligatorisk',
				artefakt: 'avslutsanteckning', klarNar: { signal: 'handling', match: 'avslut' } },
			{ id: 'gallringsbedomning', label: 'Gallringsbedömning', vadDetAr: 'Bevarande enligt dokumenthanteringsplan; gallring först på verifierad commit.',
				lagreferens: { lag: 'Arkivlagen', paragraf: '', verifieraParagraf: false }, frist: null, niva: 'obligatorisk',
				artefakt: null, klarNar: { signal: 'commit', match: '' } },
		],
	},
}

/** Stegens ordning inkl. det villkorade inflöde-steget (fixar stepper-buggen A1/A8). */
export const STEG_ORDNING = ['inflode', 'forhandsbedomning', 'utredning', 'beslut', 'uppfoljning', 'avslutat']

/**
 * Bygg stepper-noderna för ett ärende. Inflöde-noden tas MED endast när ärendet
 * faktiskt står i inflöde (annars föds case-typer i förhandsbedömning och noden
 * vore missvisande). Fixar ProcessStepper.currentIndex-buggen (A1/GAP-U8).
 * @param {string} steg
 * @return {{id:string,label:string}[]}
 */
export function stepperNoder(steg) {
	const base = STEG_ORDNING.filter((id) => id !== 'inflode').map((id) => ({ id, label: STEG_INNEHALL[id].label }))
	if (steg === 'inflode') {
		return [{ id: 'inflode', label: STEG_INNEHALL.inflode.label }, ...base]
	}
	return base
}

/**
 * Härled ett delmoments status ur BEFINTLIGA signaler (A6). Ingen ny lagring för
 * de flesta — signalerna finns redan i journal/registret, de avläses bara.
 * @param {object} delmoment  En post ur STEG_INNEHALL[steg].delmoment
 * @param {object} evidens    {journal:[], commit:{registrerad,verifierad,dnr}, bevakningar:[], parter:[]}
 * @return {'klar'|'pagar'|'saknas'}
 */
export function harledStatus(delmoment, evidens) {
	const ev = evidens || {}
	const journal = Array.isArray(ev.journal) ? ev.journal : []
	const bevakningar = Array.isArray(ev.bevakningar) ? ev.bevakningar : []
	const parter = Array.isArray(ev.parter) ? ev.parter : []
	const { signal, match } = delmoment.klarNar || {}
	const nyckel = String(match || '').toLowerCase()
	const detalj = (h) => {
		if (h && typeof h.detalj === 'object' && h.detalj) { return h.detalj }
		if (h && typeof h.detalj === 'string') { try { return JSON.parse(h.detalj) } catch (e) { return {} } }
		return {}
	}
	// KANONISK matchning (T4-rotfix): en handling som bär den stämplade
	// `detalj.dokumenttyp` (satt av DokumenttypRegistry vid generering) matchar
	// delmomentets artefakt-KLASS exakt. Äldre journalrader utan stämpel faller
	// tillbaka på substring-match mot mall-sluggen. Detta dödar barnets_rost-
	// buggen (mall "08-barnets-installning" ⇒ dokumenttyp "barnsamtal" ⇒ grön)
	// utan att förlita sig på att sluggen råkar innehålla ett nyckelord.
	const harHandling = (klass, kwFallback) => journal.some((h) => {
		if (h.typ !== 'handling') { return false }
		const d = detalj(h)
		const dt = String(d.dokumenttyp || '').toLowerCase()
		if (dt) { return !!klass && dt === String(klass).toLowerCase() }
		return !!kwFallback && String(d.mall || '').toLowerCase().includes(String(kwFallback).toLowerCase())
	})
	switch (signal) {
	case 'system':
		return 'klar'
	case 'handling':
		return harHandling(delmoment.artefakt, nyckel) ? 'klar' : 'saknas'
	case 'kvittens':
		// Kvittensen kan skrivas som ett grindval (grind=momentet) ELLER en ren
		// TYP_KVITTENS (detalj.moment). Matcha båda + fall tillbaka på artefakten
		// (kvittens-delmomentets artefakt är null, så klassen = nyckeln).
		if (journal.some((h) => h.typ === 'grindval' && String(detalj(h).grind || '').toLowerCase().includes(nyckel))) { return 'klar' }
		if (journal.some((h) => h.typ === 'kvittens' && String(detalj(h).moment || '').toLowerCase().includes(nyckel))) { return 'klar' }
		return harHandling(nyckel, nyckel) ? 'klar' : 'saknas'
	case 'commit':
		if (ev.commit && ev.commit.verifierad) { return 'klar' }
		if (journal.some((h) => h.typ === 'handling')) { return 'pagar' }
		return 'saknas'
	case 'bevakning':
		return bevakningar.some((b) => String(b.typ || '').toLowerCase().includes(nyckel)
			&& b.status !== 'avbruten') ? 'klar' : 'saknas'
	case 'part':
		return parter.some((p) => String(p.roll || '').toLowerCase().includes(nyckel)) ? 'klar' : 'saknas'
	default:
		return 'saknas'
	}
}

/**
 * Rulla upp ett stegs delmoment-status till en nod-state för steppern (A5).
 * @return {{state:'done'|'lucka'|'overhoppat'|'current'|'blocked'|'future',
 *   klara:number, obligatoriska:number, delmoment:Array<{id:string,status:string}>}}
 */
export function stegNodState(stegId, arende, evidens) {
	const modell = STEG_INNEHALL[stegId]
	const dm = modell ? modell.delmoment : []
	const per = dm.map((d) => ({ ...d, status: harledStatus(d, evidens) }))
	const obl = per.filter((d) => d.niva === 'obligatorisk')
	const klara = obl.filter((d) => d.status === 'klar').length
	const noder = stepperNoder(arende && arende.steg)
	const curIdx = Math.max(0, noder.findIndex((n) => n.id === (arende && arende.steg)))
	const thisIdx = noder.findIndex((n) => n.id === stegId)
	let state
	if (thisIdx === -1) {
		state = 'future'
	} else if (thisIdx < curIdx) {
		state = klara === 0 && obl.length > 0 ? 'overhoppat' : (klara < obl.length ? 'lucka' : 'done')
	} else if (thisIdx === curIdx) {
		state = 'current'
	} else if (arende && arende.plikt && !arende.plikt.kvitterad && thisIdx === curIdx + 1) {
		state = 'blocked'
	} else {
		state = 'future'
	}
	return { state, klara, obligatoriska: obl.length, delmoment: per }
}

/**
 * Resolve the leading "Nästa åtgärd" for an ärende, honouring plikt + väntar.
 * @param {object} a Arende
 * @return {{label:string, action:string, flik:string, vantarPaMig?:boolean}}
 */
export function nastaFor(a) {
	if (!a) {
		return NASTA_ATGARD.steg.avslutat
	}
	if (a.plikt && !a.plikt.kvitterad) {
		return { label: a.plikt.label || 'Kvittera skyddsbedömning', action: 'skyddsbedomning', flik: 'beslut', vantarPaMig: true }
	}
	if (a.vantar && NASTA_ATGARD.overrides[a.vantar]) {
		return NASTA_ATGARD.overrides[a.vantar]
	}
	if (a.nastaAtgard && a.nastaAtgard.label) {
		return a.nastaAtgard
	}
	return NASTA_ATGARD.steg[a.steg] || NASTA_ATGARD.steg.avslutat
}

export default { NASTA_ATGARD, PROCESS_STEG, STEG_INNEHALL, STEG_ORDNING, stepperNoder, harledStatus, stegNodState, nastaFor }
