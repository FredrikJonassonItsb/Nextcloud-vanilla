<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->

<template>
	<section class="hs-card att-ta-emot" :aria-label="t('hubs_start', 'Att ta emot')">
		<h2 class="hs-card__title att-ta-emot__title">
			<IconInbox :size="20" />
			{{ t('hubs_start', 'Att ta emot') }}
			<NcCounterBubble class="att-ta-emot__count">{{ items.length }}</NcCounterBubble>
		</h2>

		<!-- Triage-strömmen — announces incoming items politely -->
		<ul
			class="att-ta-emot__list"
			aria-live="polite"
			:aria-label="t('hubs_start', 'Otrierat inflöde')">
			<AttTaEmotRad
				v-for="rad in items"
				:key="rad.id"
				:rad="rad"
				@triage="onTriage"
				@open="onOpen" />
		</ul>

		<NcEmptyContent
			v-if="!items.length"
			class="att-ta-emot__empty"
			:name="t('hubs_start', 'Inget otriagerat just nu')"
			:description="emptyDescription">
			<template #icon>
				<IconInboxCheck :size="40" />
			</template>
		</NcEmptyContent>
	</section>
</template>

<script>
import NcCounterBubble from '@nextcloud/vue/dist/Components/NcCounterBubble.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'

import IconInbox from 'vue-material-design-icons/InboxArrowDown.vue'
import IconInboxCheck from 'vue-material-design-icons/CheckCircleOutline.vue'

import { translate as t } from '@nextcloud/l10n'

import AttTaEmotRad from './AttTaEmotRad.vue'

export default {
	name: 'AttTaEmotSektion',

	components: {
		NcCounterBubble,
		NcEmptyContent,
		IconInbox,
		IconInboxCheck,
		AttTaEmotRad,
	},

	props: {
		items: {
			type: Array,
			default: () => [],
		},
		aktivtFilter: {
			type: String,
			default: null,
		},
	},

	computed: {
		/** Teaching empty state — compliance value, not just "tomt". */
		emptyDescription() {
			return t('hubs_start', 'Här landar nya orosanmälningar. Inget otriagerat just nu — allt inkommande är omhändertaget. När en kommer ser du en 14-dagars nedräkning direkt.')
		},
	},

	methods: {
		t,

		onTriage(rad, mode) {
			this.$emit('triage', rad, mode)
		},

		onOpen(rad) {
			this.$emit('open', rad)
		},
	},
}
</script>

<style scoped lang="scss">
.att-ta-emot {
	&__title {
		margin-bottom: 12px;
	}

	&__count {
		margin-inline-start: 2px;
	}

	&__list {
		display: flex;
		flex-direction: column;
		gap: 8px;
		margin: 0;
		padding: 0;
		list-style: none;
	}

	&__empty {
		margin: 8px 0 0;
	}
}
</style>
