<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<section class="motes-remsa hs-card" aria-labelledby="motes-remsa-rubrik">
		<h2 id="motes-remsa-rubrik" class="hs-card__title">
			<VideoIcon :size="20" />
			{{ t('hubs_start', 'Mina möten idag') }}
			<NcCounterBubble v-if="meetings.length" class="motes-remsa__antal">
				{{ meetings.length }}
			</NcCounterBubble>
		</h2>

		<NcEmptyContent v-if="!meetings.length"
			:name="t('hubs_start', 'Inga säkra möten idag')">
			<template #icon>
				<VideoOffIcon :size="20" />
			</template>
		</NcEmptyContent>

		<div v-else class="motes-remsa__lista">
			<MotesRad
				v-for="m in meetings"
				:key="m.token"
				:meeting="m"
				@join="$emit('join', $event)"
				@godkann="$emit('godkann', $event)" />
		</div>
	</section>
</template>

<script>
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import NcCounterBubble from '@nextcloud/vue/dist/Components/NcCounterBubble.js'
import VideoIcon from 'vue-material-design-icons/Video.vue'
import VideoOffIcon from 'vue-material-design-icons/VideoOff.vue'
import { translate as t } from '@nextcloud/l10n'

import MotesRad from './MotesRad.vue'

export default {
	name: 'MotesRemsa',

	components: {
		NcEmptyContent,
		NcCounterBubble,
		VideoIcon,
		VideoOffIcon,
		MotesRad,
	},

	props: {
		meetings: {
			type: Array,
			default: () => [],
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped lang="scss">
.motes-remsa {
	&__antal {
		margin-inline-start: 4px;
	}

	&__lista {
		display: flex;
		flex-direction: column;
	}
}
</style>
