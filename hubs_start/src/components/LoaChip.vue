<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->

<template>
	<div class="loa-chip" :class="isVerified ? 'loa-chip--green' : 'loa-chip--amber'">
		<span class="loa-chip__badge" role="status">
			<component :is="badgeIcon" :size="18" class="loa-chip__icon" />
			<span class="loa-chip__label">{{ chipLabel }}</span>
		</span>

		<NcButton v-if="!isVerified"
			type="primary"
			class="loa-chip__action"
			@click="onUpgrade">
			<template #icon>
				<IconBankId :size="20" />
			</template>
			{{ t('hubs_start', 'Legitimera med BankID') }}
		</NcButton>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'

import IconShieldCheck from 'vue-material-design-icons/ShieldCheck.vue'
import IconShieldAlert from 'vue-material-design-icons/ShieldAlertOutline.vue'
import IconBankId from 'vue-material-design-icons/CardAccountDetailsOutline.vue'

export default {
	name: 'LoaChip',

	components: {
		NcButton,
		IconShieldCheck,
		IconShieldAlert,
		IconBankId,
	},

	props: {
		loa: {
			type: String,
			default: 'LOA1',
		},
	},

	computed: {
		isVerified() {
			return this.loa === 'LOA3'
		},

		badgeIcon() {
			return this.isVerified ? 'IconShieldCheck' : 'IconShieldAlert'
		},

		chipLabel() {
			return this.isVerified
				? t('hubs_start', 'Inloggad med BankID — Tillitsnivå 3')
				: t('hubs_start', 'Lägre tillitsnivå')
		},
	},

	methods: {
		t,

		onUpgrade() {
			this.$emit('upgrade')
		},
	},
}
</script>

<style scoped lang="scss">
.loa-chip {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 12px;

	&__badge {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		min-height: var(--hs-min-target);
		padding: 4px 12px;
		border-radius: var(--border-radius-pill, 16px);
		font-weight: 600;
		font-size: 0.9rem;
		line-height: 1.2;
	}

	&__icon {
		flex: 0 0 auto;
	}

	&--green &__badge {
		color: var(--hs-status-success);
		background: rgba(45, 125, 68, 0.12);
		border: 1px solid var(--hs-status-success);
	}

	&--amber &__badge {
		color: var(--hs-status-warning);
		background: rgba(199, 135, 11, 0.12);
		border: 1px solid var(--hs-status-warning);
	}
}
</style>
