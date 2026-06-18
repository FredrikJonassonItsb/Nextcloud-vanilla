<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Presentational variant: document / ärenderum rows (name, meta, status,
  - gallrings-deadline, badges). See docs/DEMO-WIDGETS-CONTRACT.md.
-->
<template>
	<section class="hs-card hs-files" :aria-label="title">
		<h3 class="hs-card__title">
			<component :is="iconFor(leadIcon)" :size="20" />
			{{ title }}
		</h3>

		<ul v-if="rows.length" class="hs-files__list">
			<li v-for="row in rows" :key="row.id" class="hs-files__row">
				<span class="hs-files__icon"><FolderLockIcon :size="20" /></span>
				<span class="hs-files__body">
					<span class="hs-files__name">{{ row.name }}</span>
					<span v-if="row.meta" class="hs-files__meta">{{ row.meta }}</span>
					<span v-if="hasBadges(row)" class="hs-files__badges">
						<span v-if="row.status" class="hs-files__tag" :style="tagStyle(row.status.tone)">{{ row.status.label }}</span>
						<span v-if="row.deadline" class="hs-files__deadline" :style="{ color: toneColor(row.deadline.tone) }">
							<TimerSandIcon :size="13" />
							{{ row.deadline.label }}
						</span>
						<span v-for="(b, i) in (row.badges || [])" :key="i" class="hs-files__badge" :style="{ borderColor: toneColor(b.tone) }">
							<component :is="iconFor(b.icon)" v-if="b.icon" :size="13" />
							{{ b.label }}
						</span>
					</span>
				</span>
			</li>
		</ul>

		<NcEmptyContent v-else :name="descriptor.emptyText || t('hubs_start', 'Inga filer')">
			<template #icon><FolderLockIcon :size="40" /></template>
		</NcEmptyContent>
	</section>
</template>

<script>
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import FolderLockIcon from 'vue-material-design-icons/FolderLock.vue'
import TimerSandIcon from 'vue-material-design-icons/TimerSand.vue'
import { translate as t } from '@nextcloud/l10n'
import { toneColor } from '../services/tones.js'
import { iconFor } from '../services/icons.js'

export default {
	name: 'WidgetFiles',
	components: { NcEmptyContent, FolderLockIcon, TimerSandIcon },
	props: {
		title: { type: String, default: '' },
		descriptor: { type: Object, required: true },
		leadIcon: { type: String, default: 'FolderLock' },
	},
	computed: {
		rows() {
			return Array.isArray(this.descriptor.rows) ? this.descriptor.rows : []
		},
	},
	methods: {
		t,
		toneColor,
		iconFor,
		hasBadges(row) {
			return row.status || row.deadline || (row.badges && row.badges.length)
		},
		tagStyle(tone) {
			return { background: toneColor(tone), color: '#fff' }
		},
	},
}
</script>

<style scoped lang="scss">
.hs-files {
	&__list {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 4px;
	}

	&__row {
		display: flex;
		align-items: flex-start;
		gap: 10px;
		padding: 8px 6px;
		border-radius: var(--border-radius-large, 12px);
		min-height: 44px;

		&:hover { background: var(--color-background-hover); }
	}

	&__icon {
		display: inline-flex;
		align-items: center;
		justify-content: center;
		min-width: 28px;
		height: 28px;
		border-radius: 8px;
		background: var(--color-background-dark);
		color: var(--color-main-text);
		flex: 0 0 auto;
	}

	&__body {
		display: flex;
		flex-direction: column;
		gap: 2px;
		min-width: 0;
		flex: 1;
	}

	&__name { font-weight: 600; line-height: 1.25; }
	&__meta { font-size: 0.83rem; color: var(--color-text-maxcontrast); }

	&__badges {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 6px;
		margin-top: 2px;
	}

	&__tag {
		font-size: 0.72rem;
		font-weight: 600;
		padding: 1px 8px;
		border-radius: var(--border-radius-pill, 16px);
		white-space: nowrap;
	}

	&__deadline {
		display: inline-flex;
		align-items: center;
		gap: 3px;
		font-size: 0.78rem;
		font-weight: 600;
	}

	&__badge {
		display: inline-flex;
		align-items: center;
		gap: 3px;
		font-size: 0.72rem;
		padding: 1px 7px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-pill, 16px);
		color: var(--color-text-maxcontrast);
	}
}
</style>
