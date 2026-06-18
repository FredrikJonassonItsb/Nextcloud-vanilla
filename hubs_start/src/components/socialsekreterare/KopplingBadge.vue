<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Den synliga röda tråden: gör ärende-identiteten synlig PÅ objektet (meddelande/
  - fil/uppgift) utan att någonsin visa rå hubsCaseId (UUID är join-nyckeln; pseudonymen
  - "Barn 2026-0142" är vad människan ser). Tre tillstånd, alltid ikon + text + färg.
  - Mappar tekniskt till systemtaggen case:{hubsCaseId} / register-pekaren — osynligt här.
-->
<template>
	<span class="koppling-badge-wrap">
		<!-- Kopplad: klickbar länk till ärendet -->
		<button
			v-if="status === 'kopplad'"
			class="koppling-badge koppling-badge--kopplad hs-target"
			type="button"
			:aria-label="t('hubs_start', 'Kopplad till {ref} — öppna ärendet', { ref: barnRef })"
			@click.stop="$emit('open-arende', koppling)">
			<LinkVariantIcon :size="13" />
			<span class="koppling-badge__text">{{ t('hubs_start', 'Kopplad till {ref}', { ref: barnRef }) }}</span>
		</button>

		<!-- Föreslagen: bekräfta/avvisa (om åtkomst), annars eskalera -->
		<span
			v-else-if="status === 'foreslagen'"
			class="koppling-badge koppling-badge--foreslagen">
			<LinkVariantPlusIcon :size="13" />
			<template v-if="harAtkomst">
				<span class="koppling-badge__text">{{ t('hubs_start', 'Liknar {ref} — bekräfta?', { ref: barnRef }) }}</span>
				<button class="koppling-badge__mini koppling-badge__mini--ja hs-target" type="button" :aria-label="t('hubs_start', 'Bekräfta koppling till {ref}', { ref: barnRef })" @click.stop="$emit('bekrafta', koppling)">
					<CheckIcon :size="13" />
				</button>
				<button class="koppling-badge__mini hs-target" type="button" :aria-label="t('hubs_start', 'Avvisa föreslagen koppling')" @click.stop="$emit('avvisa', koppling)">
					<CloseIcon :size="13" />
				</button>
			</template>
			<span v-else class="koppling-badge__text">{{ t('hubs_start', 'Möjlig koppling — eskalera till gruppledare') }}</span>
		</span>

		<!-- Ej kopplad: neutral, leder till ej-kopplat-åtgärderna -->
		<span
			v-else
			class="koppling-badge koppling-badge--ej">
			<LinkVariantOffIcon :size="13" />
			<span class="koppling-badge__text">{{ t('hubs_start', 'Ej ärendekopplat') }}</span>
		</span>
	</span>
</template>

<script>
import LinkVariantIcon from 'vue-material-design-icons/LinkVariant.vue'
import LinkVariantPlusIcon from 'vue-material-design-icons/LinkVariantPlus.vue'
import LinkVariantOffIcon from 'vue-material-design-icons/LinkVariantOff.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'KopplingBadge',
	components: { LinkVariantIcon, LinkVariantPlusIcon, LinkVariantOffIcon, CheckIcon, CloseIcon },
	props: {
		/** { status:('kopplad'|'foreslagen'|'ej'), barnRef?, dnr?, triageRef?, konfidens? } */
		koppling: { type: Object, default: () => ({ status: 'ej' }) },
	},
	computed: {
		status() {
			return (this.koppling && this.koppling.status) || 'ej'
		},
		barnRef() {
			return (this.koppling && this.koppling.barnRef) || (this.koppling && this.koppling.triageRef) || this.t('hubs_start', 'ärende')
		},
		/** Föreslagen koppling visas med bekräfta/avvisa bara om handläggaren har åtkomst till målärendet. */
		harAtkomst() {
			return !!(this.koppling && (this.koppling.dnr || this.koppling.harAtkomst))
		},
	},
	methods: { t },
}
</script>

<style scoped lang="scss">
.koppling-badge-wrap { display: inline-flex; }

.koppling-badge {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 2px 9px;
	border-radius: var(--border-radius-pill, 16px);
	border: 1px solid var(--kb-color, var(--color-border));
	background: color-mix(in srgb, var(--kb-color, var(--color-border)) 12%, var(--color-main-background));
	color: var(--color-main-text);
	font-size: 0.76rem;
	font-weight: 600;
	white-space: nowrap;
	max-width: 100%;

	&__text { overflow: hidden; text-overflow: ellipsis; }

	&--kopplad {
		--kb-color: var(--hs-status-success);
		cursor: pointer;
		&:hover { background: color-mix(in srgb, var(--hs-status-success) 22%, var(--color-main-background)); }
	}
	&--foreslagen { --kb-color: var(--hs-status-warning); }
	&--ej {
		--kb-color: var(--color-border);
		color: var(--color-text-maxcontrast);
		font-weight: 500;
	}

	&__mini {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		min-width: 24px;
		min-height: 24px;
		padding: 0;
		border: 1px solid var(--color-border);
		border-radius: 50%;
		background: var(--color-main-background);
		color: var(--color-main-text);
		cursor: pointer;
		&:hover { background: var(--color-background-hover); }
		&--ja { color: var(--hs-status-success); border-color: var(--hs-status-success); }
	}
}
</style>
