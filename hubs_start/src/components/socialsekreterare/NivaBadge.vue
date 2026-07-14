<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Kravnivå-badge (K-SIGN-1/6) ur nivåmatrisen: 'godkann' → neutral "Godkänn",
  - 'ades' → accent "e-underskrift". K-SIGN-2 (ärlig etikettering): godkann-nivån
  - får ALDRIG benämnas underskrift/signatur — den ÄR ett digitalt godkännande.
-->
<template>
	<span
		class="niva-badge"
		:class="{ 'niva-badge--ades': arAdes }"
		:aria-label="ariaLabel"
		:title="titelText">
		<DrawPenIcon v-if="arAdes" :size="13" />
		<CheckIcon v-else :size="13" />
		{{ label }}
	</span>
</template>

<script>
import CheckIcon from 'vue-material-design-icons/Check.vue'
import DrawPenIcon from 'vue-material-design-icons/DrawPen.vue'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'NivaBadge',
	components: { CheckIcon, DrawPenIcon },
	props: {
		/** Kravnivå ur motorns niva_matris: 'godkann' | 'ades'. */
		niva: { type: String, required: true },
	},
	computed: {
		arAdes() {
			return this.niva === 'ades'
		},
		label() {
			return this.arAdes ? t('hubs_start', 'e-underskrift') : t('hubs_start', 'Godkänn')
		},
		ariaLabel() {
			return t('hubs_start', 'Kravnivå: {niva}', { niva: this.label })
		},
		titelText() {
			return this.arAdes
				? t('hubs_start', 'Handlingstypen kräver avancerad e-underskrift')
				: t('hubs_start', 'Handlingstypen kräver digitalt godkännande (journalförs)')
		},
	},
	methods: { t },
}
</script>

<style scoped lang="scss">
.niva-badge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 1px 8px;
	border-radius: var(--border-radius-pill, 16px);
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	font-size: 0.78rem;
	font-weight: 600;
	white-space: nowrap;

	&--ades {
		background: var(--color-primary-element);
		color: var(--color-primary-element-text);
	}
}
</style>
