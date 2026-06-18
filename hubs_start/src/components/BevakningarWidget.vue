<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section class="hs-card bevakningar">
		<h2 class="hs-card__title">
			<EyeOutlineIcon :size="20" />
			{{ t('hubs_start', 'Bevakningar') }}
		</h2>

		<ul v-if="watching.length" class="bevakningar__list">
			<li
				v-for="(entry, index) in watching"
				:key="index"
				class="bevakningar__item">
				<component
					:is="entry.direction === 'outgoing' ? 'ArrowRightIcon' : 'ArrowLeftIcon'"
					:size="18"
					class="bevakningar__icon" />
				<span class="bevakningar__text">{{ lineFor(entry) }}</span>
			</li>
		</ul>

		<NcEmptyContent
			v-else
			:name="t('hubs_start', 'Inga bevakningar')">
			<template #icon>
				<EyeOutlineIcon :size="20" />
			</template>
		</NcEmptyContent>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'

import EyeOutlineIcon from 'vue-material-design-icons/EyeOutline.vue'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import ArrowRightIcon from 'vue-material-design-icons/ArrowRight.vue'

export default {
	name: 'BevakningarWidget',

	components: {
		NcEmptyContent,
		EyeOutlineIcon,
		ArrowLeftIcon,
		ArrowRightIcon,
	},

	props: {
		watching: {
			type: Array,
			required: true,
		},
	},

	methods: {
		t,

		/**
		 * Localised date for a watching entry (Swedish source).
		 * @param {?string} untilDate ISO date string
		 * @return {string}
		 */
		formatDate(untilDate) {
			if (!untilDate) {
				return ''
			}
			const d = new Date(untilDate)
			if (Number.isNaN(d.getTime())) {
				return String(untilDate)
			}
			return d.toLocaleDateString('sv-SE', { year: 'numeric', month: 'long', day: 'numeric' })
		},

		/**
		 * Build the watching line for an entry. `outgoing` = you watch someone
		 * else's mailbox; `incoming` = someone else watches your mailbox.
		 * @param {object} entry { mailbox, owner, untilDate, direction }
		 * @return {string}
		 */
		lineFor(entry) {
			const date = this.formatDate(entry.untilDate)
			if (entry.direction === 'incoming') {
				return t('hubs_start', '{owner} bevakar din brevlåda t.o.m. {date}', {
					owner: entry.owner,
					date,
				})
			}
			return t('hubs_start', 'Du bevakar {owner}s brevlåda t.o.m. {date}', {
				owner: entry.owner,
				date,
			})
		},
	},
}
</script>

<style scoped lang="scss">
.bevakningar {
	&__list {
		display: flex;
		flex-direction: column;
		gap: 4px;
		list-style: none;
		margin: 0;
		padding: 0;
	}

	&__item {
		display: flex;
		align-items: flex-start;
		gap: 8px;
		padding: 6px 0;

		& + & {
			border-top: 1px solid var(--color-border);
		}
	}

	&__icon {
		flex: 0 0 auto;
		color: var(--color-text-maxcontrast);
		margin-top: 1px;
	}

	&__text {
		line-height: 1.4;
	}
}
</style>
