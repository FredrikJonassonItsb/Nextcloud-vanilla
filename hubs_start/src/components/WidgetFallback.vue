<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Graceful placeholder for a widget that a persona layout references but whose
  - component is not (yet) implemented. Keeps the persona dashboard renderable and
  - honestly signals a proposed/coming feature instead of crashing on an unknown id.
-->
<template>
	<section class="hs-card hs-fallback" :aria-label="title">
		<h3 class="hs-card__title">
			<LightbulbOutlineIcon :size="20" />
			{{ title }}
		</h3>
		<p class="hs-fallback__body">
			<span class="hs-fallback__badge">{{ t('hubs_start', 'Föreslagen funktion') }}</span>
			{{ description }}
		</p>
		<p v-if="feature" class="hs-fallback__feature">
			{{ t('hubs_start', 'Bygger på:') }} {{ feature }}
		</p>
	</section>
</template>

<script>
import LightbulbOutlineIcon from 'vue-material-design-icons/LightbulbOnOutline.vue'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'WidgetFallback',
	components: { LightbulbOutlineIcon },
	props: {
		title: { type: String, default: '' },
		description: { type: String, default: '' },
		feature: { type: String, default: '' },
	},
	methods: { t },
}
</script>

<style scoped lang="scss">
.hs-fallback {
	border-style: dashed;

	&__body {
		margin: 0 0 8px;
		color: var(--color-text-maxcontrast);
		line-height: 1.4;
	}

	&__badge {
		display: inline-block;
		font-size: 0.72rem;
		font-weight: 600;
		text-transform: uppercase;
		letter-spacing: 0.04em;
		padding: 1px 8px;
		margin-inline-end: 6px;
		border-radius: var(--border-radius-pill, 16px);
		background: var(--color-warning, #c7870b);
		color: #fff;
	}

	&__feature {
		margin: 0;
		font-size: 0.82rem;
		color: var(--color-text-maxcontrast);
	}
}
</style>
