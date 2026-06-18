<template>
	<section class="hs-card bokningsbara-tider">
		<h2 class="hs-card__title">
			<IconCalendarClock :size="20" />
			{{ t('hubs_start', 'Bokningsbara tider') }}
		</h2>

		<NcEmptyContent
			v-if="!configs.length"
			:name="t('hubs_start', 'Inga bokningsbara tider ännu')"
			:description="t('hubs_start', 'Skapa en bokningssida i kalendern för att låta motparter boka säkra möten.')">
			<template #icon>
				<IconCalendarClock :size="20" />
			</template>
		</NcEmptyContent>

		<ul v-else class="bokningsbara-tider__list">
			<li
				v-for="config in configs"
				:key="config.id"
				class="bokningsbara-tider__item">
				<div class="bokningsbara-tider__info">
					<span class="bokningsbara-tider__name">{{ config.name }}</span>
					<span class="bokningsbara-tider__count">
						{{ n('hubs_start', '%n bokning', '%n bokningar', config.totalBookings || 0) }}
					</span>
				</div>
				<NcButton
					type="secondary"
					@click="copyLink(config)">
					<template #icon>
						<IconContentCopy :size="20" />
					</template>
					{{ t('hubs_start', 'Kopiera bokningslänk') }}
				</NcButton>
			</li>
		</ul>
	</section>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { showSuccess, showError } from '@nextcloud/dialogs'

import IconCalendarClock from 'vue-material-design-icons/CalendarClock.vue'
import IconContentCopy from 'vue-material-design-icons/ContentCopy.vue'

export default {
	name: 'BokningsbaraTider',

	components: {
		NcButton,
		NcEmptyContent,
		IconCalendarClock,
		IconContentCopy,
	},

	props: {
		configs: {
			type: Array,
			default: () => [],
		},
	},

	methods: {
		t,
		n,

		async copyLink(config) {
			try {
				await navigator.clipboard.writeText(config.bookingUrl)
				showSuccess(t('hubs_start', 'Bokningslänk kopierad'))
			} catch (e) {
				showError(t('hubs_start', 'Kunde inte kopiera bokningslänken'))
			}
		},
	},
}
</script>

<style scoped lang="scss">
.bokningsbara-tider {
	&__list {
		display: flex;
		flex-direction: column;
		gap: 8px;
		list-style: none;
		margin: 0;
		padding: 0;
	}

	&__item {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 12px;
		padding: 8px 0;

		& + & {
			border-top: 1px solid var(--color-border);
		}
	}

	&__info {
		display: flex;
		flex-direction: column;
		gap: 2px;
		min-width: 0;
	}

	&__name {
		font-weight: 500;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__count {
		font-size: 0.85rem;
		color: var(--color-text-maxcontrast);
	}
}
</style>
