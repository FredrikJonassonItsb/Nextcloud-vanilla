<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->

<template>
	<section class="hs-card att-ta-emot" :aria-label="t('hubs_start', 'Att ta emot')">
		<h2 class="hs-card__title att-ta-emot__title">
			<!-- Tom panel kollapsas till en rad (samma mönster som Att hantera):
			     tom yta med stor bock ska inte stjäla höjd från arbetet. -->
			<button
				class="att-ta-emot__collapse hs-target"
				type="button"
				:aria-expanded="String(!kollapsad)"
				:aria-label="kollapsad ? t('hubs_start', 'Visa Att ta emot') : t('hubs_start', 'Dölj Att ta emot')"
				@click="kollapsad = !kollapsad; anvandarStyrd = true">
				<ChevronDownIcon v-if="!kollapsad" :size="20" />
				<ChevronRightIcon v-else :size="20" />
			</button>
			<IconInbox :size="20" />
			{{ t('hubs_start', 'Att ta emot') }}
			<NcCounterBubble class="att-ta-emot__count">{{ items.length }}</NcCounterBubble>
		</h2>

		<!-- Triage-strömmen — announces incoming items politely -->
		<ul
			v-if="!kollapsad"
			class="att-ta-emot__list"
			aria-live="polite"
			:aria-label="t('hubs_start', 'Otrierat inflöde')">
			<AttTaEmotRad
				v-for="rad in items"
				:key="rad.id"
				:rad="rad"
				:pending="pendingIds.includes(rad.id)"
				@triage="onTriage"
				@open="onOpen" />
		</ul>

		<NcEmptyContent
			v-if="!items.length && !kollapsad"
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
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'

import { translate as t } from '@nextcloud/l10n'

import AttTaEmotRad from './AttTaEmotRad.vue'

export default {
	name: 'AttTaEmotSektion',

	components: {
		NcCounterBubble,
		NcEmptyContent,
		IconInbox,
		IconInboxCheck,
		ChevronDownIcon,
		ChevronRightIcon,
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
		/** rad-id:n med pågående skapa-anrop — deras knappar inaktiveras
		 * (dubbelklicksgard: ett meddelande = högst ett anrop i luften). */
		pendingIds: {
			type: Array,
			default: () => [],
		},
	},

	data() {
		return {
			// TOM panel startar kollapsad; auto-expanderar när inflöde kommer,
			// tills användaren själv togglar.
			kollapsad: (this.items || []).length === 0,
			anvandarStyrd: false,
		}
	},
	watch: {
		items(nya) {
			if (!this.anvandarStyrd) {
				this.kollapsad = (nya || []).length === 0
			}
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

	&__collapse {
		display: inline-flex;
		align-items: center;
		background: none;
		border: none;
		padding: 0 4px 0 0;
		cursor: pointer;
		color: inherit;
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
