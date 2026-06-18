<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Frist-detalj (popover): källa, påminnelsestatus, och att fristen ägs av
  - Treserva och bara speglas i Hubs (läst via Frends, inte självständigt räknad).
-->
<template>
	<div class="frist-panel" role="dialog" :aria-label="t('hubs_start', 'Fristdetaljer')">
		<p class="frist-panel__row">
			<strong>{{ frist.label }}</strong>
			<span v-if="frist.due"> · {{ t('hubs_start', 'förfaller {date}', { date: dateText }) }}</span>
		</p>
		<p v-if="frist.kalla" class="frist-panel__muted">
			{{ t('hubs_start', 'Härledd ur') }}: {{ frist.kalla }}
		</p>
		<ul v-if="frist.paminnelser && frist.paminnelser.length" class="frist-panel__rem">
			<li v-for="(p, i) in frist.paminnelser" :key="i">
				<BellRingIcon :size="13" /> {{ p }}
			</li>
		</ul>
		<p class="frist-panel__owner">
			<DatabaseSyncIcon :size="13" />
			{{ t('hubs_start', 'Ägs av Treserva — speglad här via Frends (Hubs räknar inte själv).') }}
		</p>
	</div>
</template>

<script>
import BellRingIcon from 'vue-material-design-icons/BellRing.vue'
import DatabaseSyncIcon from 'vue-material-design-icons/DatabaseSync.vue'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'FristPanel',
	components: { BellRingIcon, DatabaseSyncIcon },
	props: {
		frist: { type: Object, required: true },
	},
	computed: {
		dateText() {
			try {
				return new Date(this.frist.due).toLocaleDateString('sv-SE', { day: 'numeric', month: 'long', year: 'numeric' })
			} catch (e) {
				return this.frist.due
			}
		},
	},
	methods: { t },
}
</script>

<style scoped lang="scss">
.frist-panel {
	position: absolute;
	z-index: 20;
	top: calc(100% + 4px);
	left: 0;
	min-width: 240px;
	max-width: 320px;
	padding: 10px 12px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 12px);
	box-shadow: 0 4px 16px var(--color-box-shadow, rgba(0, 0, 0, 0.2));
	font-size: 0.82rem;
	text-align: start;

	&__row { margin: 0 0 6px; }
	&__muted { margin: 0 0 6px; color: var(--color-text-maxcontrast); }

	&__rem {
		list-style: none;
		margin: 0 0 6px;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 3px;
		color: var(--color-text-maxcontrast);

		li { display: flex; align-items: center; gap: 4px; }
	}

	&__owner {
		display: flex;
		align-items: center;
		gap: 5px;
		margin: 0;
		color: var(--color-text-maxcontrast);
		border-top: 1px solid var(--color-border);
		padding-top: 6px;
	}
}
</style>
