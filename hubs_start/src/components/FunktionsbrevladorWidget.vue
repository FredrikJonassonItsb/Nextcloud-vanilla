<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<section class="hs-card funktionsbrevlador">
		<h2 class="hs-card__title">
			<EmailMultipleIcon :size="20" />
			{{ t('hubs_start', 'Funktionsbrevlådor') }}
		</h2>

		<ul v-if="mailboxes.length" class="funktionsbrevlador__list">
			<li
				v-for="mailbox in mailboxes"
				:key="mailbox.id"
				class="funktionsbrevlador__item">
				<div class="funktionsbrevlador__head">
					<span class="funktionsbrevlador__name">{{ mailbox.name }}</span>
					<NcCounterBubble
						v-if="mailbox.unread"
						:count="mailbox.unread"
						type="highlighted"
						:aria-label="t('hubs_start', 'olästa')" />
				</div>
				<a
					:href="unassignedLink"
					class="funktionsbrevlador__unassigned hs-target"
					:aria-label="unassignedAriaLabel(mailbox)">
					{{ t('hubs_start', 'Ej tilldelad') }}
					<span class="funktionsbrevlador__unassigned-count">{{ mailbox.unassigned }}</span>
				</a>
			</li>
		</ul>

		<NcEmptyContent
			v-else
			:name="t('hubs_start', 'Inga funktionsbrevlådor')">
			<template #icon>
				<EmailMultipleIcon :size="20" />
			</template>
		</NcEmptyContent>
	</section>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

import NcCounterBubble from '@nextcloud/vue/dist/Components/NcCounterBubble.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'

import EmailMultipleIcon from 'vue-material-design-icons/EmailMultiple.vue'

import deepLinks from '../services/deepLinks.js'

export default {
	name: 'FunktionsbrevladorWidget',

	components: {
		NcCounterBubble,
		NcEmptyContent,
		EmailMultipleIcon,
	},

	props: {
		mailboxes: {
			type: Array,
			required: true,
		},
	},

	computed: {
		/** Deep link to the virtual "unassigned" mailbox. */
		unassignedLink() {
			return deepLinks.mailboxLink('unassigned')
		},
	},

	methods: {
		t,

		/**
		 * Accessible label for the "Ej tilldelad" link of a given mailbox.
		 * @param {object} mailbox the mailbox row { id, name, unread, unassigned }
		 * @return {string}
		 */
		unassignedAriaLabel(mailbox) {
			return t('hubs_start', '{count} ej tilldelade i {name}', {
				count: mailbox.unassigned,
				name: mailbox.name,
			})
		},
	},
}
</script>

<style scoped lang="scss">
.funktionsbrevlador {
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
		align-items: center;
		justify-content: space-between;
		gap: 12px;
		padding: 6px 0;

		& + & {
			border-top: 1px solid var(--color-border);
		}
	}

	&__head {
		display: flex;
		align-items: center;
		gap: 8px;
		min-width: 0;
	}

	&__name {
		font-weight: 500;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__unassigned {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		padding: 4px 8px;
		min-height: var(--hs-min-target);
		border-radius: var(--border-radius);
		color: var(--color-main-text);
		text-decoration: none;
		white-space: nowrap;

		&:hover,
		&:focus-visible {
			background-color: var(--color-background-hover);
		}

		&:focus-visible {
			outline: 2px solid var(--color-primary-element);
			outline-offset: 1px;
		}
	}

	&__unassigned-count {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		min-width: 22px;
		height: 22px;
		padding: 0 6px;
		border-radius: 11px;
		background-color: var(--color-background-dark);
		font-weight: 600;
		font-size: 0.85rem;
	}
}
</style>
