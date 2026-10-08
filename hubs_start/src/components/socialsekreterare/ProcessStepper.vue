<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Processindikatorn — gör "var är ärendet?" begripligt på en blick och lär ut
  - ärendelivscykeln utan manual. Noderna byggs ur stepperNoder() (villkorad
  - inflöde-nod) och varje nods tillstånd ur stegNodState() (6 states, A5). Hover/
  - klick öppnar en RIK panel (A4) ur STEG_INNEHALL: syfte, delmoment-checklista
  - (ikon + text, aldrig bara färg), frist, lagref och — för klara delmoment —
  - vem/när ur journalen. Okvitterad plikt spärrar framåt-övergång.
-->
<template>
	<ol class="stepper" :aria-label="t('hubs_start', 'Ärendets processteg')">
		<li
			v-for="(s, i) in noder"
			:key="s.id"
			class="stepper__node"
			:class="nodKlass(s, i)"
			:aria-current="s.id === aktuellSteg ? 'step' : null">
			<!-- hover/fokus = snabb-titt (~150 ms); klick = pinna (floating-vues
			     click-trigger håller panelen tills utanför-klick). Esc stänger. -->
			<NcPopover
				:shown.sync="oppenNod[s.id]"
				:triggers="['hover', 'focus', 'click']"
				popup-role="dialog"
				:no-focus-trap="true"
				:delay="{ show: 150, hide: 80 }"
				@after-show="onPanelOppen(s)">
				<template #trigger="{ attrs }">
					<!-- Noden är en knapp: hover/fokus öppnar panelen (~150 ms), klick
					     pinnar den öppen + navigerar. Framtids-/spärrnoder navigerar ej
					     men panelen (låsförklaringen) når man ändå. attrs = NcPopovers
					     a11y-attribut (aria-haspopup/expanded) som MÅSTE bindas på en
					     custom trigger-knapp. -->
					<button
						v-bind="attrs"
						class="stepper__btn hs-target"
						type="button"
						:aria-label="knappAria(s, i)"
						@click="onKlick(s, i)"
						@keydown.esc="stangPanel(s)">
						<span class="stepper__marker">
							<CheckIcon v-if="markerIkon(s) === 'check'" :size="14" />
							<AlertIcon v-else-if="markerIkon(s) === 'lucka'" :size="14" />
							<MinusIcon v-else-if="markerIkon(s) === 'overhoppat'" :size="14" />
							<LockIcon v-else-if="markerIkon(s) === 'blocked'" :size="12" />
							<span v-else class="stepper__dot" />
						</span>
						<span class="stepper__label">{{ s.label }}</span>
						<!-- Progress-badge: klara/obligatoriska på en lucka (gul "2/3"). -->
						<span
							v-if="visaBadge(s)"
							class="stepper__badge"
							:class="'stepper__badge--' + nodState(s, i).state">
							{{ nodState(s, i).klara }}/{{ nodState(s, i).obligatoriska }}
						</span>
					</button>
				</template>

				<!-- A4 — den rika panelen. Renderas ur STEG_INNEHALL + stegNodState(). -->
				<div class="stegpanel" :aria-label="t('hubs_start', 'Steg: {steg}', { steg: s.label })">
					<header class="stegpanel__head">
						<span class="stegpanel__ikon" :class="'stegpanel__ikon--' + nodState(s, i).state">
							<CheckIcon v-if="markerIkon(s) === 'check'" :size="15" />
							<AlertIcon v-else-if="markerIkon(s) === 'lucka'" :size="15" />
							<MinusIcon v-else-if="markerIkon(s) === 'overhoppat'" :size="15" />
							<LockIcon v-else-if="markerIkon(s) === 'blocked'" :size="13" />
							<ProgressClockIcon v-else-if="nodState(s, i).state === 'current'" :size="15" />
							<ClockOutlineIcon v-else :size="15" />
						</span>
						<span class="stegpanel__titel">{{ innehall(s).label || s.label }}</span>
						<span class="stegpanel__state">{{ stateText(nodState(s, i).state) }}</span>
					</header>

					<p v-if="innehall(s).kortBeskrivning" class="stegpanel__syfte">
						{{ innehall(s).kortBeskrivning }}
					</p>

					<!-- Frist för steget (om satt). -->
					<p v-if="fristText(s)" class="stegpanel__frist">
						<ClockOutlineIcon :size="14" /> {{ fristText(s) }}
					</p>

					<!-- Delmoment-checklista: status-ikon + text per delmoment (A4). -->
					<ul v-if="innehall(s).delmoment && innehall(s).delmoment.length" class="stegpanel__lista">
						<li
							v-for="dm in nodState(s, i).delmoment"
							:key="dm.id"
							class="stegpanel__dm"
							:class="'stegpanel__dm--' + dm.status">
							<span class="stegpanel__dm-ikon">
								<CheckCircleIcon v-if="dm.status === 'klar'" :size="15" />
								<ProgressClockIcon v-else-if="dm.status === 'pagar'" :size="15" />
								<CircleOutlineIcon v-else :size="15" />
							</span>
							<span class="stegpanel__dm-txt">
								<span class="stegpanel__dm-rad">
									<span class="stegpanel__dm-label">{{ dm.label }}</span>
									<span v-if="dm.niva === 'rekommenderad'" class="stegpanel__dm-valfri">{{ t('hubs_start', 'rekommenderad') }}</span>
									<span v-if="lagref(dm)" class="stegpanel__dm-lag" :title="lagrefTitel(dm)">{{ lagref(dm) }}</span>
								</span>
								<!-- Klart delmoment: vem + när ur journalen (aldrig PII). -->
								<span v-if="dm.status === 'klar' && bevis(s, dm)" class="stegpanel__dm-bevis">
									<CheckIcon :size="12" />
									{{ bevisText(bevis(s, dm)) }}
									<a
										v-if="bevisLank(s, dm)"
										class="stegpanel__dm-lank"
										:href="bevisLank(s, dm)">{{ t('hubs_start', 'öppna') }}</a>
								</span>
								<!-- Aktuellt steg, ej klart delmoment: "detta återstår". -->
								<span v-else-if="nodState(s, i).state === 'current' && dm.status !== 'klar' && dm.vadDetAr" class="stegpanel__dm-hint">
									{{ dm.vadDetAr }}
								</span>
							</span>
						</li>
					</ul>

					<!-- "Detta återstår"-sammandrag för aktuellt steg. -->
					<p v-if="nodState(s, i).state === 'current' && aterstarText(s, i)" class="stegpanel__aterstar">
						<ArrowRightIcon :size="14" /> {{ aterstarText(s, i) }}
					</p>

					<!-- Framtidsnod: låsförklaring. -->
					<p v-if="nodState(s, i).state === 'future'" class="stegpanel__last">
						<LockIcon :size="13" /> {{ t('hubs_start', 'Kommande steg — låses upp när ärendet når hit.') }}
					</p>
					<p v-else-if="nodState(s, i).state === 'blocked'" class="stegpanel__last">
						<LockIcon :size="13" /> {{ pliktLabel }}
					</p>
				</div>
			</NcPopover>
			<span v-if="i < noder.length - 1" class="stepper__line" aria-hidden="true" />
		</li>
	</ol>
</template>

<script>
import NcPopover from '@nextcloud/vue/dist/Components/NcPopover.js'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import CheckCircleIcon from 'vue-material-design-icons/CheckCircle.vue'
import CircleOutlineIcon from 'vue-material-design-icons/CircleOutline.vue'
import AlertIcon from 'vue-material-design-icons/AlertOutline.vue'
import MinusIcon from 'vue-material-design-icons/Minus.vue'
import LockIcon from 'vue-material-design-icons/Lock.vue'
import ClockOutlineIcon from 'vue-material-design-icons/ClockOutline.vue'
import ProgressClockIcon from 'vue-material-design-icons/ProgressClock.vue'
import ArrowRightIcon from 'vue-material-design-icons/ArrowRight.vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import store from '../../store/index.js'
import deepLinks from '../../services/deepLinks.js'
import { STEG_INNEHALL, stepperNoder, stegNodState } from '../../services/arendeFlow.js'

export default {
	name: 'ProcessStepper',
	components: {
		NcPopover, CheckIcon, CheckCircleIcon, CircleOutlineIcon, AlertIcon,
		MinusIcon, LockIcon, ClockOutlineIcon, ProgressClockIcon, ArrowRightIcon,
	},
	props: {
		/** Hela ärendet (A1/A5): stegNodState läser arende.steg + arende.plikt. */
		arende: { type: Object, default: null },
		/** Bakåtkompatibla enkel-props (används när `arende` ej skickas). */
		steg: { type: String, default: 'forhandsbedomning' },
		substeg: { type: String, default: null },
		plikt: { type: Object, default: null },
	},
	data() {
		return {
			// Per-nod öppet-tillstånd (.sync mot NcPopover). Hover/fokus styrs av
			// NcPopovers triggers; klick tvingar öppet (pin), Esc stänger.
			oppenNod: {},
		}
	},
	computed: {
		/** Ett stabilt ärende-objekt oavsett om `arende` eller enkel-props gavs. */
		arendeObj() {
			if (this.arende) {
				return this.arende
			}
			// Syntetiskt ärende ur enkel-props (bakåtkompatibilitet).
			return { steg: this.steg, substeg: this.substeg, plikt: this.plikt }
		},
		aktuellSteg() {
			return this.arendeObj.steg
		},
		/** A1 — noderna ur stepperNoder(): inflöde-noden bara när ärendet står där. */
		noder() {
			return stepperNoder(this.aktuellSteg)
		},
		/** Cache-nyckeln för evidens (samma som ArendeKort.cacheKey). */
		evidensRef() {
			const a = this.arendeObj
			return a.triageRef || a.dnr || a.hubsCaseId || null
		},
		/**
		 * A6 — evidens-bundeln ur store (reaktiv: läser historik-/bevaknings-/parts-
		 * cachen). Tom tills loadStegEvidens() fyllt den; då degraderar node-staten
		 * till den positionella modellen (se nodState) för att inte ljuga.
		 */
		evidens() {
			if (!this.evidensRef) {
				return null
			}
			return store.stegEvidens(this.evidensRef)
		},
		/** Har evidensen faktiskt hämtats? (annars positionell fallback). */
		evidensLaddad() {
			return !!(this.evidensRef && store.state.arende.historikCache[this.evidensRef])
		},
		blocked() {
			const p = this.arendeObj.plikt
			return !!(p && !p.kvitterad)
		},
		pliktLabel() {
			const p = this.arendeObj.plikt
			return (p && p.label) || this.t('hubs_start', 'Kvittera skyddsbedömningen först')
		},
	},
	methods: {
		t,
		n,
		/** STEG_INNEHALL-posten för en nod (tom-shape om okänt steg). */
		innehall(s) {
			return STEG_INNEHALL[s.id] || { label: s.label, delmoment: [] }
		},
		/**
		 * A5 — nodens tillstånd. När evidensen är laddad används stegNodState() fullt
		 * ut (done/lucka/overhoppat/current/blocked/future). Innan dess (dashboard,
		 * inget kort expanderat) faller vi till en POSITIONELL modell så att avklarade
		 * steg inte felaktigt läses som "överhoppat" (evidens saknas ≠ inget gjordes).
		 */
		nodState(s, i) {
			if (this.evidensLaddad) {
				return stegNodState(s.id, this.arendeObj, this.evidens)
			}
			const curIdx = this.noder.findIndex((nod) => nod.id === this.aktuellSteg)
			const cur = curIdx === -1 ? 0 : curIdx
			let state
			if (i < cur) {
				state = 'done'
			} else if (i === cur) {
				state = 'current'
			} else if (this.blocked && i === cur + 1) {
				state = 'blocked'
			} else {
				state = 'future'
			}
			// Delmoment tas fortfarande med (checklistan visar då bara "saknas"/syfte).
			const modell = STEG_INNEHALL[s.id] || { delmoment: [] }
			const dm = (modell.delmoment || []).map((d) => ({ ...d, status: 'saknas' }))
			const obl = dm.filter((d) => d.niva === 'obligatorisk')
			return { state, klara: 0, obligatoriska: obl.length, delmoment: dm }
		},
		nodKlass(s, i) {
			return 'stepper__node--' + this.nodState(s, i).state
		},
		/** Vilken markör-glyf noden ska visa (aldrig bara färg). */
		markerIkon(s) {
			const idx = this.noder.findIndex((nod) => nod.id === s.id)
			const st = this.nodState(s, idx).state
			if (st === 'done') {
				return 'check'
			}
			if (st === 'lucka') {
				return 'lucka'
			}
			if (st === 'overhoppat') {
				return 'overhoppat'
			}
			if (st === 'blocked') {
				return 'blocked'
			}
			return 'dot'
		},
		/** Progress-badge visas på en lucka (delvis avklarat obligatoriskt steg). */
		visaBadge(s) {
			const idx = this.noder.findIndex((nod) => nod.id === s.id)
			const ns = this.nodState(s, idx)
			return ns.state === 'lucka' && ns.obligatoriska > 0
		},
		stateText(state) {
			const map = {
				done: this.t('hubs_start', 'Klart'),
				lucka: this.t('hubs_start', 'Delvis — luckor kvar'),
				overhoppat: this.t('hubs_start', 'Överhoppat'),
				current: this.t('hubs_start', 'Pågår nu'),
				blocked: this.t('hubs_start', 'Spärrat'),
				future: this.t('hubs_start', 'Kommande'),
			}
			return map[state] || ''
		},
		/** Stegets frist som läsbar text (STEG_INNEHALL[steg].frist). */
		fristText(s) {
			const f = this.innehall(s).frist
			if (!f || !f.dagar) {
				return ''
			}
			const ankare = {
				inkom: this.t('hubs_start', 'från inkomstdatum'),
				steg: this.t('hubs_start', 'från stegets start'),
				delgivning: this.t('hubs_start', 'från delgivning'),
			}
			const bas = f.dagar === 0
				? this.t('hubs_start', 'Samma dag')
				: this.n('hubs_start', 'Inom %n dag', 'Inom %n dagar', f.dagar)
			const ank = ankare[f.ankare] ? ' ' + ankare[f.ankare] : ''
			const lag = f.lag ? ' · ' + f.lag : ''
			return bas + ank + lag
		},
		/** Lagref-chip för ett delmoment (asterisk vid verifieraParagraf). */
		lagref(dm) {
			const l = dm.lagreferens
			if (!l || !l.lag) {
				return ''
			}
			const p = l.paragraf ? ' ' + l.paragraf : ''
			return l.lag + p + (l.verifieraParagraf ? ' *' : '')
		},
		lagrefTitel(dm) {
			const l = dm.lagreferens || {}
			if (l.verifieraParagraf) {
				return this.t('hubs_start', 'Lagrum: {lag} {p} (paragrafnumret bör dubbelkollas mot nya SoL).', { lag: l.lag || '', p: l.paragraf || '' })
			}
			return this.t('hubs_start', 'Lagrum: {lag} {p}', { lag: l.lag || '', p: l.paragraf || '' })
		},
		/**
		 * Hitta det journal-/bevaknings-BEVIS som gjorde ett delmoment klart, för att
		 * visa vem/när (+ ev. artefakt-länk). Speglar arendeFlow.harledStatus matchning
		 * men returnerar den underliggande posten (display-lager, ej ny sanning).
		 */
		bevis(s, dm) {
			if (!this.evidens || dm.status !== 'klar') {
				return null
			}
			const { signal, match } = dm.klarNar || {}
			const nyckel = String(match || '').toLowerCase()
			const journal = this.evidens.journal || []
			const bevakningar = this.evidens.bevakningar || []
			const detalj = (h) => {
				if (h && typeof h.detalj === 'object' && h.detalj) {
					return h.detalj
				}
				if (h && typeof h.detalj === 'string') {
					try {
						return JSON.parse(h.detalj)
					} catch (e) {
						return {}
					}
				}
				return {}
			}
			const handlingMatch = (h) => h.typ === 'handling' && String(detalj(h).mall || '').toLowerCase().includes(nyckel)
			switch (signal) {
			case 'handling':
				return journal.find(handlingMatch) || null
			case 'kvittens':
				return journal.find((h) => h.typ === 'grindval' && String(detalj(h).grind || '').toLowerCase().includes(nyckel))
					|| journal.find(handlingMatch) || null
			case 'commit':
				return journal.find((h) => h.typ === 'registrerad') || null
			case 'bevakning':
				return bevakningar.find((b) => String(b.typ || '').toLowerCase().includes(nyckel) && b.status !== 'avbruten') || null
			default:
				return null
			}
		},
		/** Text för ett bevis: aktör + datum (PII-fritt — uid/roll, aldrig namn). */
		bevisText(post) {
			if (!post) {
				return ''
			}
			const nar = this.fmtDatum(post.tid || post.skapad || post.fristDue)
			const vem = post.aktorUid || ''
			if (vem && nar) {
				return this.t('hubs_start', '{vem} · {nar}', { vem, nar })
			}
			return vem || nar || this.t('hubs_start', 'klart')
		},
		/** Artefakt-länk för ett handlings-bevis (samma fileHref-mönster som kortet). */
		bevisLank(s, dm) {
			const post = this.bevis(s, dm)
			if (!post) {
				return null
			}
			const d = (post.detalj && typeof post.detalj === 'object') ? post.detalj : {}
			const fil = d.fil || d.filnamn || d.namn
			const caseId = this.arendeObj.hubsCaseId
			if (fil && caseId) {
				return deepLinks.fileLink('/' + caseId + '/' + fil)
			}
			return null
		},
		/** "Detta återstår"-sammandrag: de obligatoriska delmoment som ej är klara. */
		aterstarText(s, i) {
			const kvar = this.nodState(s, i).delmoment
				.filter((dm) => dm.niva === 'obligatorisk' && dm.status !== 'klar')
				.map((dm) => dm.label)
			if (!kvar.length) {
				return this.t('hubs_start', 'Alla obligatoriska moment klara.')
			}
			return this.t('hubs_start', 'Återstår: {lista}', { lista: kvar.join(', ') })
		},
		knappAria(s, i) {
			return s.label + ' — ' + this.stateText(this.nodState(s, i).state)
		},
		/**
		 * Klick: NcPopovers click-trigger sköter pin-öppningen; denna handler
		 * navigerar kortet till rätt flik (framtid/spärr navigerar ej — men panelen/
		 * låsförklaringen öppnas ändå av click-triggern).
		 */
		onKlick(s, i) {
			const st = this.nodState(s, i).state
			if (st !== 'future' && st !== 'blocked') {
				this.$emit('goto-flik', { steg: s.id, historik: st === 'done' || st === 'lucka' || st === 'overhoppat' })
			}
		},
		/** Panelen öppnades (hover/fokus/klick) → säkerställ att evidensen laddas. */
		onPanelOppen() {
			if (this.evidensRef) {
				store.loadStegEvidens(this.evidensRef)
			}
		},
		/** Esc stänger den pinnade panelen (a11y). */
		stangPanel(s) {
			this.$set(this.oppenNod, s.id, false)
		},
		fmtDatum(s) {
			if (!s) {
				return ''
			}
			try {
				return new Date(s).toLocaleDateString('sv-SE', { day: 'numeric', month: 'short', year: 'numeric' })
			} catch (e) {
				return s
			}
		},
	},
}
</script>

<style scoped lang="scss">
.stepper {
	display: flex;
	align-items: center;
	list-style: none;
	margin: 0;
	padding: 0;
	flex-wrap: wrap;
	gap: 2px;

	&__node {
		display: flex;
		align-items: center;
		min-width: 0;
	}

	&__btn {
		display: inline-flex;
		align-items: center;
		gap: 5px;
		background: transparent;
		border: none;
		padding: 4px 6px;
		cursor: pointer;
		font-size: 0.8rem;
		color: var(--color-text-maxcontrast);

		&:hover { color: var(--color-main-text); }
		&:focus-visible { outline: 2px solid var(--color-primary-element); outline-offset: 1px; border-radius: var(--border-radius, 6px); }
	}

	&__marker {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		width: 18px;
		height: 18px;
		border-radius: 50%;
		border: 2px solid var(--color-border);
		flex: 0 0 auto;
	}

	&__dot { width: 7px; height: 7px; border-radius: 50%; background: var(--color-border); }

	&__line {
		width: 16px;
		height: 2px;
		background: var(--color-border);
		flex: 0 0 auto;
	}

	&__badge {
		margin-left: 3px;
		padding: 0 6px;
		border-radius: var(--border-radius-pill, 16px);
		font-size: 0.68rem;
		font-weight: 700;
		font-variant-numeric: tabular-nums;
		line-height: 1.5;
		background: var(--color-background-dark);
		color: var(--color-text-maxcontrast);

		// Luckan är gul: delvis klart obligatoriskt steg som lämnats bakom.
		&--lucka {
			background: var(--hs-status-warning-bg, #fdf3e3);
			color: var(--hs-status-warning-text, #7f5900);
		}
	}

	// --- Nod-states (6 st, A5). Markör-färg + label-vikt; glyfen bär betydelsen. ---
	&__node--done &__marker { border-color: var(--hs-status-success); background: var(--hs-status-success); color: #fff; }
	&__node--done &__label { color: var(--color-main-text); }

	&__node--lucka &__marker { border-color: var(--hs-status-warning, #b07c00); background: var(--hs-status-warning, #b07c00); color: #fff; }
	&__node--lucka &__label { color: var(--color-main-text); }

	&__node--overhoppat &__marker { border-color: var(--hs-status-error); color: var(--hs-status-error); }
	&__node--overhoppat &__label { color: var(--color-main-text); }

	&__node--current &__marker { border-color: var(--color-primary-element); }
	&__node--current &__dot { background: var(--color-primary-element); }
	&__node--current &__label { color: var(--color-main-text); font-weight: 700; }

	&__node--blocked &__marker { border-color: var(--hs-status-error); color: var(--hs-status-error); }
	&__node--blocked &__label { color: var(--color-text-maxcontrast); }

	&__node--future &__marker { border-style: dashed; }

	@media (max-width: 600px) {
		&__label { display: none; }
		&__node--current &__label { display: inline; }
		&__badge { display: none; }
	}
}

// --- A4: den rika hover-/pin-panelen ---------------------------------------
.stegpanel {
	max-width: 320px;
	padding: 12px 14px;
	font-size: 0.85rem;
	color: var(--color-main-text);

	&__head {
		display: flex;
		align-items: center;
		gap: 7px;
		margin-bottom: 6px;
	}

	&__ikon {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		width: 22px;
		height: 22px;
		border-radius: 50%;
		flex: 0 0 auto;
		color: var(--color-text-maxcontrast);
		border: 2px solid var(--color-border);

		&--done { border-color: var(--hs-status-success); background: var(--hs-status-success); color: #fff; }
		&--lucka { border-color: var(--hs-status-warning, #b07c00); color: var(--hs-status-warning, #b07c00); }
		&--overhoppat { border-color: var(--hs-status-error); color: var(--hs-status-error); }
		&--current { border-color: var(--color-primary-element); color: var(--color-primary-element); }
		&--blocked { border-color: var(--hs-status-error); color: var(--hs-status-error); }
	}

	&__titel { font-weight: 700; flex: 1 1 auto; min-width: 0; }
	&__state { font-size: 0.72rem; color: var(--color-text-maxcontrast); white-space: nowrap; }

	&__syfte { margin: 0 0 8px; color: var(--color-text-maxcontrast); line-height: 1.35; }

	&__frist {
		display: flex;
		align-items: center;
		gap: 5px;
		margin: 0 0 8px;
		font-size: 0.8rem;
		color: var(--color-main-text);
		svg { flex: 0 0 auto; color: var(--color-text-maxcontrast); }
	}

	&__lista { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 7px; }

	&__dm { display: flex; align-items: flex-start; gap: 7px; }
	&__dm-ikon {
		flex: 0 0 auto;
		margin-top: 1px;
		color: var(--color-text-maxcontrast);
	}
	&__dm--klar &__dm-ikon { color: var(--hs-status-success); }
	&__dm--pagar &__dm-ikon { color: var(--color-primary-element); }

	&__dm-txt { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
	&__dm-rad { display: flex; flex-wrap: wrap; align-items: baseline; gap: 5px; }
	&__dm-label { font-weight: 500; }
	&__dm--klar &__dm-label { color: var(--color-main-text); }
	&__dm--saknas &__dm-label { color: var(--color-text-maxcontrast); }

	&__dm-valfri {
		font-size: 0.68rem;
		padding: 0 6px;
		border-radius: var(--border-radius-pill, 16px);
		background: var(--color-background-dark);
		color: var(--color-text-maxcontrast);
	}
	&__dm-lag { font-size: 0.7rem; color: var(--color-text-maxcontrast); white-space: nowrap; }

	&__dm-bevis {
		display: inline-flex;
		align-items: center;
		flex-wrap: wrap;
		gap: 4px;
		font-size: 0.74rem;
		color: var(--hs-status-success);
		svg { flex: 0 0 auto; }
	}
	&__dm-hint { font-size: 0.76rem; color: var(--color-text-maxcontrast); line-height: 1.3; }
	&__dm-lank {
		color: var(--color-primary-element);
		font-weight: 600;
		text-decoration: none;
		&:hover, &:focus-visible { text-decoration: underline; }
	}

	&__aterstar {
		display: flex;
		align-items: flex-start;
		gap: 5px;
		margin: 10px 0 0;
		padding-top: 8px;
		border-top: 1px solid var(--color-border);
		font-size: 0.8rem;
		color: var(--color-main-text);
		svg { flex: 0 0 auto; margin-top: 1px; color: var(--color-primary-element); }
	}

	&__last {
		display: flex;
		align-items: center;
		gap: 5px;
		margin: 8px 0 0;
		font-size: 0.78rem;
		color: var(--color-text-maxcontrast);
		svg { flex: 0 0 auto; }
	}
}
</style>
