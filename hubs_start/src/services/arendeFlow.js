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

export default { NASTA_ATGARD, PROCESS_STEG, nastaFor }
