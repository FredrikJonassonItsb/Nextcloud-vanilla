<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Frist-indikatorn — garanterar att ingen lagstadgad klocka missas. Ikon + text
  - + dagsiffra (aldrig bara färg). Eskaleringsfärg grå→gul(≤3)→röd(förfallen).
  - Klick öppnar FristPanel (källa, påminnelser, "ägs av Treserva — speglad").
-->
<template>
	<span v-if="frist" class="frist-chip-wrap">
		<button
			class="frist-chip hs-target"
			type="button"
			:style="{ '--frist-color': toneColor(frist.tone) }"
			:aria-expanded="String(open)"
			:aria-label="ariaLabel"
			@click.stop="open = !open">
			<ClockAlertIcon v-if="frist.tone === 'error'" :size="14" />
			<ClockOutlineIcon v-else :size="14" />
			<span class="frist-chip__text">{{ frist.label }} · {{ daysText }}</span>
		</button>
		<FristPanel v-if="open" :frist="frist" @close="open = false" />
	</span>
</template>

<script>
import ClockAlertIcon from 'vue-material-design-icons/ClockAlert.vue'
import ClockOutlineIcon from 'vue-material-design-icons/ClockOutline.vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { toneColor } from '../../services/tones.js'
import FristPanel from './FristPanel.vue'

export default {
	name: 'FristChip',
	components: { ClockAlertIcon, ClockOutlineIcon, FristPanel },
	props: {
		frist: { type: Object, default: null },
	},
	data() {
		return { open: false }
	},
	computed: {
		daysText() {
			const d = this.frist ? this.frist.daysLeft : null
			if (d === null || d === undefined) {
				return this.dateText
			}
			if (d < 0) {
				return this.n('hubs_start', 'förfallen %n dag', 'förfallen %n dagar', Math.abs(d))
			}
			if (d === 0) {
				return this.t('hubs_start', 'förfaller idag')
			}
			return this.n('hubs_start', '%n dag kvar', '%n dagar kvar', d)
		},
		dateText() {
			if (!this.frist || !this.frist.due) {
				return ''
			}
			try {
				return new Date(this.frist.due).toLocaleDateString('sv-SE', { day: 'numeric', month: 'short' })
			} catch (e) {
				return this.frist.due
			}
		},
		ariaLabel() {
			return this.frist ? (this.frist.label + ' — ' + this.daysText + ' — visa detaljer') : ''
		},
	},
	methods: {
		t,
		n,
		toneColor,
	},
}
</script>

<style scoped lang="scss">
.frist-chip-wrap {
	position: relative;
	display: inline-block;
}

.frist-chip {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 2px 9px;
	border-radius: var(--border-radius-pill, 16px);
	border: 1px solid var(--frist-color);
	background: color-mix(in srgb, var(--frist-color) 12%, var(--color-main-background));
	color: var(--frist-color);
	font-size: 0.78rem;
	font-weight: 600;
	cursor: pointer;
	white-space: nowrap;

	&:hover {
		background: color-mix(in srgb, var(--frist-color) 22%, var(--color-main-background));
	}

	&__text { color: var(--color-main-text); }
}
</style>
