<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Persona-driven primary action bar. Renders the active persona's 3–5 primary
  - actions (verb-first). The first is the primary button; the rest secondary.
  - Emits @action(action) — the Start view routes it (open a real modal or, for a
  - proposed feature in demo, show a notice).
-->
<template>
	<div class="hs-actionbar" role="toolbar" :aria-label="t('hubs_start', 'Primära åtgärder')">
		<NcButton
			v-for="(action, index) in actions"
			:key="action.id"
			:type="index === 0 ? 'primary' : 'secondary'"
			class="hs-actionbar__btn"
			@click="$emit('action', action)">
			<template #icon>
				<component :is="iconFor(action.icon)" :size="20" />
			</template>
			{{ action.label }}
		</NcButton>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import { translate as t } from '@nextcloud/l10n'
import { iconFor } from '../services/icons.js'

export default {
	name: 'ActionBar',
	components: { NcButton },
	props: {
		/** @type {Array<{id:string,label:string,icon:string,feature:string}>} */
		actions: { type: Array, default: () => [] },
	},
	methods: { t, iconFor },
}
</script>

<style scoped lang="scss">
.hs-actionbar {
	display: flex;
	flex-wrap: wrap;
	gap: 10px;
	margin: 12px 0 4px;

	&__btn {
		min-height: 44px;
	}
}
</style>
