<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - DEMO control: lets the viewer switch between persona dashboards to see each
  - tailored layout. In a real install the persona is derived from the user's role
  - /group (RoleService) and this switcher is hidden — it only renders in demo mode.
-->
<template>
	<div class="persona-switcher">
		<label class="persona-switcher__label" for="persona-switcher-select">
			<AccountSwitchIcon :size="18" />
			{{ t('hubs_start', 'Visa som') }}
		</label>
		<select
			id="persona-switcher-select"
			class="persona-switcher__select"
			:value="active"
			@change="onChange">
			<option
				v-for="p in personas"
				:key="p.id"
				:value="p.id">
				{{ p.label }}
			</option>
		</select>
	</div>
</template>

<script>
import AccountSwitchIcon from 'vue-material-design-icons/AccountSwitch.vue'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'PersonaSwitcher',
	components: { AccountSwitchIcon },
	props: {
		/** @type {Array<{id:string,label:string}>} */
		personas: { type: Array, default: () => [] },
		active: { type: String, default: '' },
	},
	methods: {
		t,
		onChange(e) {
			this.$emit('change', e.target.value)
		},
	},
}
</script>

<style scoped lang="scss">
.persona-switcher {
	display: inline-flex;
	align-items: center;
	gap: 8px;

	&__label {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		font-size: 0.85rem;
		color: var(--color-text-maxcontrast);
		white-space: nowrap;
	}

	&__select {
		min-height: 34px;
		padding: 4px 10px;
		border-radius: var(--border-radius-large, 12px);
		border: 1px solid var(--color-border);
		background: var(--color-main-background);
		color: var(--color-main-text);
		font-size: 0.9rem;
		max-width: 260px;
	}
}
</style>
