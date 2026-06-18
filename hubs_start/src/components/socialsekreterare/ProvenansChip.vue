<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Mellanlagring→facksystem-känslan. Två tillstånd: "→ Treserva — ej registrerad"
  - (öppen åtgärd → @commit) och "Registrerad i Treserva, dnr X · Hubs-rum gallras
  - {datum}" (dubbel countdown: facksystemets bevarande + Hubs-rensning).
-->
<template>
	<button
		v-if="provenance"
		class="prov-chip hs-target"
		:class="'prov-chip--' + provenance.state"
		type="button"
		:disabled="isRegistrerad"
		:title="title"
		@click.stop="onClick">
		<ArrowRightThinIcon v-if="!isRegistrerad" :size="13" />
		<CheckDecagramIcon v-else :size="13" />
		<span class="prov-chip__text">{{ label }}</span>
	</button>
</template>

<script>
import ArrowRightThinIcon from 'vue-material-design-icons/ArrowRightThin.vue'
import CheckDecagramIcon from 'vue-material-design-icons/CheckDecagram.vue'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'ProvenansChip',
	components: { ArrowRightThinIcon, CheckDecagramIcon },
	props: {
		provenance: { type: Object, default: null },
	},
	computed: {
		isRegistrerad() {
			return this.provenance && this.provenance.state === 'registrerad'
		},
		label() {
			if (!this.provenance) {
				return ''
			}
			if (this.isRegistrerad) {
				const dnr = this.provenance.dnr || ''
				const g = this.fmt(this.provenance.gallrasDatum)
				return g
					? this.t('hubs_start', 'Registrerad i Treserva, {dnr} · Hubs-rum gallras {date}', { dnr, date: g })
					: this.t('hubs_start', 'Registrerad i Treserva, {dnr}', { dnr })
			}
			return this.t('hubs_start', '→ Treserva — ej registrerad')
		},
		title() {
			if (this.isRegistrerad) {
				const b = this.provenance.bevarasDatum
				return this.t('hubs_start', 'Bevaras i facksystemet: {b}. Hubs-kopian gallras efter bekräftad överföring.', { b: b || '—' })
			}
			return this.t('hubs_start', 'Klicka för att föra över till Treserva (via Frends).')
		},
	},
	methods: {
		t,
		fmt(d) {
			if (!d) {
				return ''
			}
			try {
				return new Date(d).toLocaleDateString('sv-SE', { month: 'short', year: 'numeric' })
			} catch (e) {
				return d
			}
		},
		onClick() {
			if (!this.isRegistrerad) {
				this.$emit('commit')
			}
		},
	},
}
</script>

<style scoped lang="scss">
.prov-chip {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 2px 9px;
	border-radius: var(--border-radius-pill, 16px);
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	font-size: 0.76rem;
	cursor: pointer;
	white-space: nowrap;
	color: var(--color-text-maxcontrast);

	&--ej_registrerad {
		border-style: dashed;
		&:hover { background: var(--color-background-hover); color: var(--color-main-text); }
	}

	&--registrerad {
		border-color: var(--hs-status-success);
		color: var(--hs-status-success);
		cursor: default;
	}

	&__text { overflow: hidden; text-overflow: ellipsis; }
}
</style>
