<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Sekretess-/deltagar-rad överst på varje chatt-yta (ärendechatt + enhetschatt).
  - Gör synligt VEM som ser tråden (= ärenderummets ACL-krets) och under vilken
  - sekretess/klassning — intern arbetsmaterial, OSL 26 kap. Aldrig klartext-PII.
-->
<template>
	<div class="sekretess-rad" role="note" :aria-label="ariaLabel">
		<span class="sekretess-rad__krets">
			<AccountGroupIcon :size="15" />
			{{ n('hubs_start', '%n deltagare = ärenderummets behörighet', '%n deltagare = ärenderummets behörighet', antalDeltagare) }}
		</span>
		<span v-if="kod" class="sekretess-rad__kod">
			<ShieldLockIcon :size="14" /> {{ kod }}
		</span>
		<span v-if="nivaText" class="sekretess-rad__niva">{{ nivaText }}</span>
	</div>
</template>

<script>
import AccountGroupIcon from 'vue-material-design-icons/AccountGroup.vue'
import ShieldLockIcon from 'vue-material-design-icons/ShieldLock.vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'

export default {
	name: 'SekretessRad',
	components: { AccountGroupIcon, ShieldLockIcon },
	props: {
		/** { kod:'OSL 26 kap.', niva:'intern_arbetsmaterial' } */
		sekretess: { type: Object, default: () => ({}) },
		/** ACL-kretsen som ser tråden: [{ uid, namn, roll }] */
		deltagare: { type: Array, default: () => [] },
	},
	computed: {
		antalDeltagare() {
			return this.deltagare.length || 0
		},
		kod() {
			return (this.sekretess && this.sekretess.kod) || ''
		},
		nivaText() {
			const m = {
				intern_arbetsmaterial: this.t('hubs_start', 'internt arbetsmaterial'),
				allman_handling: this.t('hubs_start', 'allmän handling'),
			}
			return (this.sekretess && m[this.sekretess.niva]) || ''
		},
		ariaLabel() {
			return this.t('hubs_start', 'Sekretess: {kod}. {antal} deltagare i ärenderummets behörighetskrets.', { kod: this.kod || '—', antal: this.antalDeltagare })
		},
	},
	methods: { t, n },
}
</script>

<style scoped lang="scss">
.sekretess-rad {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px 12px;
	padding: 6px 10px;
	margin-bottom: 8px;
	border-radius: var(--border-radius, 8px);
	background: var(--color-background-dark);
	font-size: 0.78rem;
	color: var(--color-text-maxcontrast);

	&__krets,
	&__kod {
		display: inline-flex;
		align-items: center;
		gap: 4px;
	}

	&__kod { font-weight: 600; }

	&__niva {
		font-style: italic;
	}
}
</style>
