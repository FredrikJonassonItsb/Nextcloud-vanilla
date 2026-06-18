<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - The teaching footer under each widget: which app powers it + where the data is
  - ultimately stored (Hubs = mellanlagring, ärendehanteringssystem = slutlagring).
  - For installed native apps it offers a real "open" link; for proposed/external
  - integrations it explains which app + what prerequisites are needed.
-->
<template>
	<footer v-if="provenance" class="hs-prov" :class="'hs-prov--' + provenance.status">
		<button
			class="hs-prov__summary"
			type="button"
			:aria-expanded="String(open)"
			@click="open = !open">
			<span class="hs-prov__dot" :title="statusLabel" />
			<span class="hs-prov__app">{{ provenance.backingApp }}</span>
			<ArrowRightThinIcon :size="14" class="hs-prov__arrow" />
			<span class="hs-prov__sor">{{ provenance.systemOfRecord }}</span>
			<ChevronDownIcon :size="16" class="hs-prov__chev" :class="{ 'hs-prov__chev--open': open }" />
		</button>

		<div v-if="open" class="hs-prov__detail">
			<p class="hs-prov__line">
				<span class="hs-prov__badge">{{ statusLabel }}</span>
				<span v-if="provenance.ncAppId" class="hs-prov__appid">app-id: {{ provenance.ncAppId }}</span>
			</p>
			<p v-if="provenance.prerequisites" class="hs-prov__prereq">
				<strong>{{ t('hubs_start', 'Förutsättningar:') }}</strong> {{ provenance.prerequisites }}
			</p>
			<p class="hs-prov__flow">
				<InformationOutlineIcon :size="14" />
				{{ t('hubs_start', 'Hubs mellanlagrar — slutlagras i {sor}.', { sor: provenance.systemOfRecord }) }}
			</p>
			<NcButton
				v-if="canOpen"
				type="secondary"
				class="hs-prov__open"
				@click="openApp">
				<template #icon><OpenInNewIcon :size="16" /></template>
				{{ t('hubs_start', 'Öppna {app}', { app: provenance.backingApp }) }}
			</NcButton>
		</div>
	</footer>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import ArrowRightThinIcon from 'vue-material-design-icons/ArrowRightThin.vue'
import ChevronDownIcon from 'vue-material-design-icons/ChevronDown.vue'
import InformationOutlineIcon from 'vue-material-design-icons/InformationOutline.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import { translate as t } from '@nextcloud/l10n'
import { isNative, appUrl } from '../services/appProvenance.js'

export default {
	name: 'WidgetProvenance',
	components: { NcButton, ArrowRightThinIcon, ChevronDownIcon, InformationOutlineIcon, OpenInNewIcon },
	props: {
		provenance: { type: Object, default: null },
	},
	data() {
		return { open: false }
	},
	computed: {
		statusLabel() {
			switch (this.provenance && this.provenance.status) {
			case 'native':
				return this.t('hubs_start', 'Inkopplad app')
			case 'proposed-integration':
				return this.t('hubs_start', 'Föreslagen integration')
			case 'external':
				return this.t('hubs_start', 'Externt system')
			default:
				return ''
			}
		},
		canOpen() {
			return isNative(this.provenance) && !!appUrl(this.provenance)
		},
	},
	methods: {
		t,
		openApp() {
			const url = appUrl(this.provenance)
			if (url) {
				window.location.href = url
			}
		},
	},
}
</script>

<style scoped lang="scss">
.hs-prov {
	margin-top: 2px;
	border-top: 1px dashed var(--color-border);

	&__summary {
		display: flex;
		align-items: center;
		gap: 6px;
		width: 100%;
		padding: 6px 4px;
		background: transparent;
		border: none;
		cursor: pointer;
		font-size: 0.78rem;
		color: var(--color-text-maxcontrast);
		text-align: start;

		&:hover { color: var(--color-main-text); }
	}

	&__dot {
		width: 8px;
		height: 8px;
		border-radius: 50%;
		flex: 0 0 auto;
		background: var(--color-text-maxcontrast);
	}

	&__app { font-weight: 600; white-space: nowrap; }
	&__arrow { flex: 0 0 auto; opacity: 0.6; }
	&__sor { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
	&__chev { flex: 0 0 auto; transition: transform 0.15s ease; }
	&__chev--open { transform: rotate(180deg); }

	&__detail {
		padding: 4px 4px 10px;
		font-size: 0.8rem;
		color: var(--color-text-maxcontrast);
		display: flex;
		flex-direction: column;
		gap: 6px;
	}

	&__line { margin: 0; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

	&__badge {
		font-size: 0.7rem;
		font-weight: 600;
		text-transform: uppercase;
		letter-spacing: 0.03em;
		padding: 1px 8px;
		border-radius: var(--border-radius-pill, 16px);
		color: #fff;
	}

	&__appid { font-family: var(--font-face-monospace, monospace); font-size: 0.72rem; }
	&__prereq, &__flow { margin: 0; line-height: 1.4; }
	&__flow { display: flex; align-items: flex-start; gap: 5px; }
	&__open { align-self: flex-start; margin-top: 2px; }

	// status accents
	&--native &__dot { background: var(--hs-status-success); }
	&--native &__badge { background: var(--hs-status-success); }
	&--proposed-integration &__dot { background: var(--hs-status-warning); }
	&--proposed-integration &__badge { background: var(--hs-status-warning); }
	&--external &__dot { background: var(--hs-status-info); }
	&--external &__badge { background: var(--hs-status-info); }
}
</style>
