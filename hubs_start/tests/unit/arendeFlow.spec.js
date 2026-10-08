/**
 * Enhetstester för src/services/arendeFlow.js — frontend-kontraktets sanningskälla
 * för utredningskedjan (A1/A5/A6). Ren logik, inga externa beroenden: vi matar in
 * STEG_INNEHALL-delmoment + en syntetisk evidens-bundel och asserterar härledningen.
 *
 * Tre ytor pinnas:
 *  - harledStatus(delmoment, evidens): signal-avläsningen (handling/kvittens/commit/
 *    bevakning/part/system) → 'klar'|'pagar'|'saknas'. SPEGLAR backendens
 *    EvidensService::harArtefakt/harKvittens (samma nyckelordsmatchning), så stepper
 *    och grind aldrig divergerar.
 *  - stegNodState(stegId, arende, evidens): rullar upp delmomenten till nod-state
 *    (done/lucka/overhoppat/current/blocked/future) + klara/obligatoriska.
 *  - stepperNoder(steg): den villkorade inflöde-noden (A1-buggen).
 */

import arendeFlow, {
	STEG_INNEHALL,
	STEG_ORDNING,
	stepperNoder,
	harledStatus,
	stegNodState,
} from '../../src/services/arendeFlow.js'

/** Ett delmoment ur STEG_INNEHALL via steg+id (så testerna binds mot den verkliga modellen). */
function delmoment(stegId, id) {
	const dm = STEG_INNEHALL[stegId].delmoment.find((d) => d.id === id)
	if (!dm) { throw new Error(`okänt delmoment ${stegId}/${id}`) }
	return dm
}

/** En handling-journalpost (detalj.mall är beviset EvidensService/harledStatus läser). */
function handling(mall) {
	return { typ: 'handling', detalj: { mall } }
}

/** Ett grindval-journalpost (detalj.grind är beviset 'kvittens'-signalen läser). */
function grindval(grind) {
	return { typ: 'grindval', detalj: { grind } }
}

describe('harledStatus — handling-signal', () => {
	it('är klar när en journal-handling ur mall matchar nyckelordet', () => {
		const dm = delmoment('forhandsbedomning', 'skyddsbedomning') // klarNar.match = 'skyddsbedom'
		const ev = { journal: [handling('bbic-skyddsbedomning-barn')] }
		expect(harledStatus(dm, ev)).toBe('klar')
	})

	it('är saknas när ingen handling matchar', () => {
		const dm = delmoment('forhandsbedomning', 'skyddsbedomning')
		const ev = { journal: [handling('nagot-helt-annat')] }
		expect(harledStatus(dm, ev)).toBe('saknas')
	})

	it('matchar skiftlägesokänsligt och som substring', () => {
		const dm = delmoment('utredning', 'bbic_utredning') // match = 'bbic'
		const ev = { journal: [handling('BBIC-Utredning-2026')] }
		expect(harledStatus(dm, ev)).toBe('klar')
	})

	it('läser detalj som JSON-sträng (backend serialiserar detalj)', () => {
		const dm = delmoment('utredning', 'utredningsplan')
		const ev = { journal: [{ typ: 'handling', detalj: JSON.stringify({ mall: 'utredningsplan-barn' }) }] }
		expect(harledStatus(dm, ev)).toBe('klar')
	})

	it('saknas på tom/utelämnad evidens (fail-safe, inte krasch)', () => {
		const dm = delmoment('utredning', 'bbic_utredning')
		expect(harledStatus(dm, undefined)).toBe('saknas')
		expect(harledStatus(dm, {})).toBe('saknas')
	})
})

describe('harledStatus — kanonisk dokumenttyp (T4-rotfix, barnets_rost-buggen)', () => {
	/** En handling med STÄMPLAD dokumenttyp (DokumenttypRegistry) + mall-slug. */
	const stamplad = (dokumenttyp, mall) => ({ typ: 'handling', detalj: { dokumenttyp, mall } })

	it('barnets_rost blir KLAR via stämplad dokumenttyp trots att mall-sluggen aldrig innehåller "barnsamtal"', () => {
		// Detta är exakt buggen: mallen "08-barnets-installning-och-delaktighet"
		// innehåller aldrig nyckelordet "barnsamtal", så den gamla substring-
		// matchningen kunde ALDRIG bli grön. Med stämplad dokumenttyp='barnsamtal'
		// (= delmomentets artefakt) blir den grön.
		const dm = delmoment('utredning', 'barnets_rost') // artefakt = 'barnsamtal'
		const ev = { journal: [stamplad('barnsamtal', '08-barnets-installning-och-delaktighet')] }
		expect(harledStatus(dm, ev)).toBe('klar')
	})

	it('en stämplad handling av ANNAN dokumenttyp uppfyller inte delmomentet (ingen skör mall-gissning på stämplad rad)', () => {
		const dm = delmoment('utredning', 'barnets_rost')
		// Mall-sluggen råkar innehålla "barnets" men typen är en annan — stämpeln vinner.
		const ev = { journal: [stamplad('bbic-utredning', '05-barnavardsutredning-med-barnets-rost')] }
		expect(harledStatus(dm, ev)).toBe('saknas')
	})

	it('faller tillbaka på legacy-nyckelord för äldre journalrader utan stämpel', () => {
		const dm = delmoment('utredning', 'bbic_utredning') // match = 'bbic'
		const ev = { journal: [handling('05-barnavardsutredning-bbic')] } // ingen dokumenttyp
		expect(harledStatus(dm, ev)).toBe('klar')
	})
})

describe('harledStatus — commit-signal', () => {
	const dm = delmoment('beslut', 'beslut_committat') // signal: 'commit'

	it('är klar när commit är verifierad', () => {
		expect(harledStatus(dm, { commit: { verifierad: true, dnr: 'DNR-1' } })).toBe('klar')
	})

	it('är pagar när det finns handling men ingen verifierad commit', () => {
		expect(harledStatus(dm, { journal: [handling('utkast-beslut')], commit: { verifierad: false } })).toBe('pagar')
	})

	it('är saknas utan commit och utan handling', () => {
		expect(harledStatus(dm, { journal: [], commit: { verifierad: false } })).toBe('saknas')
	})
})

describe('harledStatus — bevaknings-signal', () => {
	const dm = delmoment('uppfoljning', 'omprovning') // match = 'overvagande'

	it('är klar när en icke-avbruten bevakning av rätt typ finns', () => {
		const ev = { bevakningar: [{ typ: 'overvagande-6man', status: 'aktiv' }] }
		expect(harledStatus(dm, ev)).toBe('klar')
	})

	it('är saknas när matchande bevakning är avbruten', () => {
		const ev = { bevakningar: [{ typ: 'overvagande-6man', status: 'avbruten' }] }
		expect(harledStatus(dm, ev)).toBe('saknas')
	})

	it('är saknas utan matchande bevakning', () => {
		const ev = { bevakningar: [{ typ: 'komplettering', status: 'aktiv' }] }
		expect(harledStatus(dm, ev)).toBe('saknas')
	})
})

describe('harledStatus — kvittens-signal', () => {
	const dm = delmoment('forhandsbedomning', 'skyddsbedomning_kvittens') // match = 'skyddsbedomning'

	it('är klar via ett journalfört grindval för momentet', () => {
		const ev = { journal: [{ typ: 'grindval', detalj: { grind: 'skyddsbedomning', val: 'override' } }] }
		expect(harledStatus(dm, ev)).toBe('klar')
	})

	it('är klar via en handling som bär momentets nyckelord', () => {
		const ev = { journal: [handling('skyddsbedomning-akut')] }
		expect(harledStatus(dm, ev)).toBe('klar')
	})

	it('är saknas utan grindval och utan handling', () => {
		const ev = { journal: [{ typ: 'grindval', detalj: { grind: 'kommunicering' } }] }
		expect(harledStatus(dm, ev)).toBe('saknas')
	})
})

describe('harledStatus — system-signal', () => {
	it('är alltid klar (systemet garanterar momentet, t.ex. dokumenterad anmälan)', () => {
		const dm = delmoment('inflode', 'anmalan_dokumenterad') // signal: 'system'
		expect(harledStatus(dm, {})).toBe('klar')
	})
})

describe('stepperNoder — villkorad inflöde-nod (A1)', () => {
	it('tar MED inflöde-noden endast när ärendet står i inflöde', () => {
		const ids = stepperNoder('inflode').map((n) => n.id)
		expect(ids[0]).toBe('inflode')
		expect(ids).toEqual(['inflode', 'forhandsbedomning', 'utredning', 'beslut', 'uppfoljning', 'avslutat'])
	})

	it('utelämnar inflöde-noden för alla senare steg (case föds i förhandsbedömning)', () => {
		const ids = stepperNoder('utredning').map((n) => n.id)
		expect(ids).not.toContain('inflode')
		expect(ids).toEqual(['forhandsbedomning', 'utredning', 'beslut', 'uppfoljning', 'avslutat'])
	})

	it('etiketterna kommer ur STEG_INNEHALL', () => {
		const nod = stepperNoder('beslut').find((n) => n.id === 'beslut')
		expect(nod.label).toBe(STEG_INNEHALL.beslut.label)
	})
})

describe('stegNodState — nod-rollup (A5)', () => {
	const arende = (steg, extra = {}) => ({ steg, ...extra })

	it('current för det aktuella steget', () => {
		const s = stegNodState('utredning', arende('utredning'), {})
		expect(s.state).toBe('current')
	})

	it('done för ett passerat steg där ALLA obligatoriska delmoment är klara', () => {
		// förhandsbedömning: 3 obligatoriska delmoment (skyddsbedömning, kvittens, beslut_inleda).
		const ev = {
			journal: [
				handling('skyddsbedomning-akut'), // skyddsbedomning (handling) + kvittens (via handling-match)
				grindval('inleda'), // beslut_inleda (A9a-inleda-grinden journalför beslutet)
			],
		}
		const s = stegNodState('forhandsbedomning', arende('utredning'), ev)
		expect(s.state).toBe('done')
		expect(s.klara).toBe(s.obligatoriska)
	})

	it('lucka för ett passerat steg med NÅGRA men inte alla obligatoriska klara (progress-badge)', () => {
		const ev = { journal: [handling('skyddsbedomning-akut')] } // 2/3 klara (skydds + kvittens), beslut saknas
		const s = stegNodState('forhandsbedomning', arende('utredning'), ev)
		expect(s.state).toBe('lucka')
		expect(s.klara).toBeGreaterThan(0)
		expect(s.klara).toBeLessThan(s.obligatoriska)
	})

	it('overhoppat för ett passerat steg där INGET obligatoriskt delmoment är klart', () => {
		const s = stegNodState('forhandsbedomning', arende('beslut'), { journal: [] })
		expect(s.state).toBe('overhoppat')
		expect(s.klara).toBe(0)
		expect(s.obligatoriska).toBeGreaterThan(0)
	})

	it('future för ett steg bortom det aktuella', () => {
		const s = stegNodState('beslut', arende('forhandsbedomning'), {})
		expect(s.state).toBe('future')
	})

	it('blocked för nästa-steg-noden när plikt inte är kvitterad', () => {
		const a = arende('forhandsbedomning', { plikt: { kvitterad: false } })
		const s = stegNodState('utredning', a, {})
		expect(s.state).toBe('blocked')
	})

	it('rapporterar delmoment-status per post för panelen', () => {
		const ev = { journal: [handling('skyddsbedomning-akut')] }
		const s = stegNodState('forhandsbedomning', arende('forhandsbedomning'), ev)
		const skydds = s.delmoment.find((d) => d.id === 'skyddsbedomning')
		const beslut = s.delmoment.find((d) => d.id === 'beslut_inleda')
		expect(skydds.status).toBe('klar')
		expect(beslut.status).toBe('saknas')
	})
})

describe('modell-invarianter', () => {
	it('default-exporten exponerar hela kontraktet', () => {
		expect(arendeFlow.stepperNoder).toBe(stepperNoder)
		expect(arendeFlow.harledStatus).toBe(harledStatus)
		expect(arendeFlow.stegNodState).toBe(stegNodState)
	})

	it('STEG_ORDNING och STEG_INNEHALL täcker samma steg', () => {
		STEG_ORDNING.forEach((id) => expect(STEG_INNEHALL[id]).toBeDefined())
	})
})
