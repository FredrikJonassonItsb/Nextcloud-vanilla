<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Utredarnas belastning för gruppledaren — EN stapel per utredare som speglar
  - antalet aktiva ärenden (relativt max), aldrig något ärendeinnehåll. Chefen har
  - inte automatiskt sekretess-åtkomst till varje barn, bara aggregat: tal + stapel.
  - Röda frister visas som tal + ikon (färg är aldrig enda bärare). "Nära tak"
  - markeras med ikon + text. Klick väljer utredaren (filtrerar inget innehåll här).
-->
<template>
	<ul class="utredar-last" :aria-label="t('hubs_start', 'Utredarnas belastning')">
		<li
			v-for="u in utredare"
			:key="u.namn"
			class="utredar-last__rad">
			<button
				class="utredar-last__knapp hs-target"
				type="button"
				:aria-label="ariaLabel(u)"
				@click="$emit('valj-utredare', u)">
				<span class="utredar-last__namn">{{ u.namn }}</span>

				<span class="utredar-last__matare">
					<span
						class="utredar-last__stapel"
						:class="{ 'utredar-last__stapel--nara-tak': u.naraTak }"
						:style="{ width: stapelBredd(u) }"
						aria-hidden="true" />
				</span>

				<span class="utredar-last__tal">
					{{ n('hubs_start', '%n aktivt', '%n aktiva', talet(u.aktiva)) }}
				</span>

				<span
					v-if="talet(u.roda) > 0"
					class="utredar-last__roda"
					:title="t('hubs_start', 'ärenden med röd frist')">
					<ClockAlertIcon :size="14" />
					{{ n('hubs_start', '%n röd frist', '%n röda frister', talet(u.roda)) }}
				</span>

				<span
					v-if="u.naraTak"
					class="utredar-last__tak">
					<AlertOutlineIcon :size="14" />
					{{ t('hubs_start', 'nära tak') }}
				</span>
			</button>
		</li>
	</ul>
</template>

<script>
import ClockAlertIcon from 'vue-material-design-icons/ClockAlert.vue'
import AlertOutlineIcon from 'vue-material-design-icons/AlertOutline.vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'

export default {
	name: 'UtredarLast',
	components: { ClockAlertIcon, AlertOutlineIcon },
	props: {
		/** [{ namn, aktiva:Number, roda:Number, naraTak:Boolean }] */
		utredare: { type: Array, default: () => [] },
	},
	computed: {
		/** Skalan: längsta stapeln är den mest belastade utredaren (minst 1 för att undvika division med noll). */
		maxAktiva() {
			return Math.max(1, ...this.utredare.map((u) => this.talet(u.aktiva)))
		},
	},
	methods: {
		t,
		n,
		/** Robust mot saknade/odefinierade tal. */
		talet(v) {
			return Number(v || 0)
		},
		stapelBredd(u) {
			const andel = this.talet(u.aktiva) / this.maxAktiva
			// Golv på 6 % så även en utredare med 1 ärende får en synlig stapel.
			return Math.max(6, Math.round(andel * 100)) + '%'
		},
		ariaLabel(u) {
			const delar = [
				u.namn,
				this.n('hubs_start', '%n aktivt ärende', '%n aktiva ärenden', this.talet(u.aktiva)),
			]
			if (this.talet(u.roda) > 0) {
				delar.push(this.n('hubs_start', '%n ärende med röd frist', '%n ärenden med röd frist', this.talet(u.roda)))
			}
			if (u.naraTak) {
				delar.push(this.t('hubs_start', 'nära tak'))
			}
			return delar.join(' — ')
		},
	},
}
</script>

<style scoped lang="scss">
.utredar-last {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin: 0;
	padding: 0;
	list-style: none;

	&__rad {
		list-style: none;
	}

	&__knapp {
		display: grid;
		grid-template-columns: minmax(96px, 1fr) minmax(80px, 2fr) auto auto auto;
		align-items: center;
		gap: 10px;
		width: 100%;
		padding: 6px 8px;
		border: 1px solid transparent;
		border-radius: var(--border-radius-large, 12px);
		background: transparent;
		color: var(--color-main-text);
		text-align: start;
		cursor: pointer;

		&:hover {
			background: var(--color-background-hover);
		}

		&:focus-visible {
			border-color: var(--color-primary-element);
			outline: none;
		}
	}

	&__namn {
		font-weight: 600;
		font-size: 0.9rem;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	// Spår + ifylld stapel. Längden bär belastningen; talet intill bär samma signal.
	&__matare {
		display: block;
		width: 100%;
		height: 10px;
		border-radius: var(--border-radius-pill, 16px);
		background: var(--color-background-dark);
		overflow: hidden;
	}

	&__stapel {
		display: block;
		height: 100%;
		min-width: 4px;
		border-radius: var(--border-radius-pill, 16px);
		background: var(--color-primary-element);

		// Nära tak: varningston + mönster, men ikon + text "nära tak" bär samma signal.
		&--nara-tak {
			background: var(--hs-status-warning);
		}
	}

	&__tal {
		font-size: 0.85rem;
		font-weight: 600;
		color: var(--color-main-text);
		white-space: nowrap;
	}

	&__roda {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		padding: 2px 9px;
		border-radius: var(--border-radius-pill, 16px);
		border: 1px solid var(--hs-status-error);
		background: color-mix(in srgb, var(--hs-status-error) 12%, var(--color-main-background));
		color: var(--hs-status-error);
		font-size: 0.76rem;
		font-weight: 600;
		white-space: nowrap;
	}

	&__tak {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		color: var(--hs-status-warning);
		font-size: 0.78rem;
		font-weight: 600;
		white-space: nowrap;
	}

	// Reflow @720px: tillåt radbrytning, ingen horisontell scroll.
	@media (max-width: 720px) {
		&__knapp {
			grid-template-columns: 1fr 1fr;
			grid-auto-rows: auto;
		}

		&__matare {
			grid-column: 1 / -1;
		}
	}
}
</style>
