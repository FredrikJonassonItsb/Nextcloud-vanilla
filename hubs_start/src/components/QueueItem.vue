<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->

<template>
	<NcListItem
		class="queue-item"
		:name="item.title"
		:bold="false"
		:force-display-actions="true"
		@click="onOpen">
		<!-- Channel icon (server-classified channel, presentation only) -->
		<template #icon>
			<span
				class="queue-item__channel"
				:style="{ color: 'var(' + channel.colorVar + ')' }"
				:aria-label="channel.label"
				:title="channel.label">
				<component :is="channel.icon" :size="20" />
			</span>
		</template>

		<!-- Mailbox + dnr line -->
		<template #subname>
			<span class="queue-item__meta">
				<span v-if="item.mailbox" class="queue-item__mailbox">{{ item.mailbox }}</span>
				<span v-if="item.mailbox && item.dnr" class="queue-item__sep" aria-hidden="true">·</span>
				<span v-if="item.dnr" class="queue-item__dnr">{{ dnrLabel }}</span>
			</span>
		</template>

		<!-- Status tag + optional LOA badge -->
		<template #indicator>
			<span class="queue-item__tags">
				<span
					class="queue-item__status"
					:class="'queue-item__status--' + statusToneFor(item.status)">
					{{ statusLabelFor(item.status) }}
				</span>
				<span v-if="item.loa" class="queue-item__loa">{{ loaLabel }}</span>
			</span>
		</template>

		<!-- Actions -->
		<template #actions>
			<NcActionButton
				:close-after-click="true"
				@click="onOpen">
				<template #icon>
					<IconOpen :size="20" />
				</template>
				{{ t('hubs_start', 'Öppna') }}
			</NcActionButton>
			<NcActionButton
				v-if="canTake"
				:close-after-click="true"
				@click="onTake">
				<template #icon>
					<IconTake :size="20" />
				</template>
				{{ t('hubs_start', 'Ta ärendet') }}
			</NcActionButton>
			<NcActionButton
				v-if="item.doneTag"
				:close-after-click="true"
				@click="onDone">
				<template #icon>
					<IconDone :size="20" />
				</template>
				{{ t('hubs_start', 'Klart') }}
			</NcActionButton>
		</template>
	</NcListItem>
</template>

<script>
import NcListItem from '@nextcloud/vue/dist/Components/NcListItem.js'
import NcActionButton from '@nextcloud/vue/dist/Components/NcActionButton.js'

import IconOpen from 'vue-material-design-icons/OpenInNew.vue'
import IconTake from 'vue-material-design-icons/HandBackRight.vue'
import IconDone from 'vue-material-design-icons/CheckCircleOutline.vue'

import { translate as t } from '@nextcloud/l10n'
import { channelMeta } from '../services/channels.js'
import { statusLabel, statusTone } from '../services/sections.js'

export default {
	name: 'QueueItem',

	components: {
		NcListItem,
		NcActionButton,
		IconOpen,
		IconTake,
		IconDone,
	},

	props: {
		/** A single QueueItem (see api.js typedef). */
		item: {
			type: Object,
			required: true,
		},
	},

	computed: {
		/** Presentation descriptor for the server-resolved channel. */
		channel() {
			return channelMeta(this.item.channel?.channel)
		},
		/** "Ta ärendet" only when there is an assignment and the item sits in Otilldelat. */
		canTake() {
			return !!this.item.assignment && this.item.section === 'otilldelat'
		},
		dnrLabel() {
			return t('hubs_start', 'Dnr {dnr}', { dnr: this.item.dnr })
		},
		loaLabel() {
			return t('hubs_start', 'Tillitsnivå {loa}', { loa: this.loaNumber })
		},
		loaNumber() {
			// 'LOA3' → '3'; render the level digit only.
			return String(this.item.loa || '').replace(/[^0-9]/g, '') || this.item.loa
		},
	},

	methods: {
		t,
		statusLabelFor(id) {
			return statusLabel(id)
		},
		statusToneFor(id) {
			return statusTone(id)
		},
		onOpen() {
			this.$emit('open', this.item)
		},
		onTake() {
			this.$emit('take', this.item)
		},
		onDone() {
			this.$emit('done', this.item)
		},
	},
}
</script>

<style scoped lang="scss">
.queue-item {
	&__channel {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		min-width: var(--hs-min-target);
		min-height: var(--hs-min-target);
	}

	&__meta {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		flex-wrap: wrap;
		color: var(--color-text-maxcontrast);
		font-size: 0.85rem;
	}

	&__sep {
		opacity: 0.6;
	}

	&__dnr {
		white-space: nowrap;
	}

	&__tags {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		flex-wrap: wrap;
	}

	&__status {
		display: inline-flex;
		align-items: center;
		min-height: var(--hs-min-target);
		padding: 2px 8px;
		border-radius: var(--border-radius-pill, 100px);
		font-size: 0.8rem;
		font-weight: 600;
		line-height: 1.2;
		color: var(--color-main-background);
		background: var(--hs-status-neutral);

		&--info { background: var(--hs-status-info); }
		&--warning { background: var(--hs-status-warning); }
		&--error { background: var(--hs-status-error); }
		&--success { background: var(--hs-status-success); }
		&--neutral { background: var(--hs-status-neutral); }
	}

	&__loa {
		display: inline-flex;
		align-items: center;
		min-height: var(--hs-min-target);
		padding: 2px 8px;
		border-radius: var(--border-radius-pill, 100px);
		font-size: 0.8rem;
		font-weight: 600;
		line-height: 1.2;
		border: 1px solid var(--color-border-dark);
		color: var(--color-main-text);
	}
}
</style>
