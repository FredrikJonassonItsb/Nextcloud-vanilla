/**
 * Component tests for VarselLista.vue — "Kräver åtgärd nu" som varsel-lista.
 * Principen: kortet flyttas ALDRIG hit; varje rad länkar ner till kortet via
 * `ga-till` med kortets triageRef. Tom lista ⇒ sektionen renderas inte alls.
 */
import { shallowMount } from '@vue/test-utils'
import VarselLista from '../../src/components/socialsekreterare/VarselLista.vue'

describe('VarselLista', () => {
	it('renders one row per varsel and emits ga-till with the ref', async () => {
		const w = shallowMount(VarselLista, {
			propsData: {
				varsel: [
					{ sym: '⏰', rubrik: 'Frist förfallen', under: 'dnr X · orosanmalan', ref: 'ref-1' },
					{ sym: '⛔', rubrik: 'Kvittera skyddsbedömning', under: 'dnr Y', ref: 'ref-2' },
				],
			},
		})
		const knappar = w.findAll('button')
		expect(knappar.length).toBe(2)
		await knappar.at(1).trigger('click')
		expect(w.emitted('ga-till')[0][0]).toBe('ref-2')
	})

	it('renders nothing at all when there are no varsel (no empty red box)', () => {
		const w = shallowMount(VarselLista, { propsData: { varsel: [] } })
		expect(w.find('section').exists()).toBe(false)
	})
})
