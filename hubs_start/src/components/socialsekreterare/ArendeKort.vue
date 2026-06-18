<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -
  - Vyns kärnkomponent: ETT ärende i alla processteg. Stepper + frist + provenans
  - + EN lysande Nästa-åtgärd. Expanderar till Quick View (flikar) utan sidbyte.
  - Pliktmarkör (röd) spärrar stepper-framsteg tills plikten committas.
-->
<template>
	<article
		class="arende-kort hs-card"
		:class="{ 'arende-kort--het': pinned, 'arende-kort--plikt': pliktAktiv }">
		<!-- Pliktmarkör -->
		<p v-if="pliktAktiv" class="arende-kort__plikt">
			<AlertOctagonIcon :size="16" />
			{{ arende.plikt.label }}
		</p>

		<!-- Titel + sekretess/LOA -->
		<header class="arende-kort__head">
			<button class="arende-kort__titel" type="button" @click="toggleExpand">
				<ChevronRightIcon :size="18" class="arende-kort__chev" :class="{ 'arende-kort__chev--open': isExpanded }" />
				<span>{{ arende.barnRef }}<span v-if="arende.dnr" class="arende-kort__dnr"> · dnr {{ arende.dnr }}</span></span>
			</button>
			<span class="arende-kort__badges">
				<span class="arende-kort__sekretess">{{ arende.sekretess && arende.sekretess.kod }}</span>
				<span class="arende-kort__loa">{{ arende.loa }}</span>
			</span>
		</header>

		<!-- Processteg -->
		<ProcessStepper
			:steg="arende.steg"
			:substeg="arende.substeg"
			:plikt="arende.plikt"
			@goto-flik="onStepperGoto" />

		<!-- Frist + provenans + ärendechatt-chip -->
		<div class="arende-kort__meta">
			<FristChip :frist="arende.frist" />
			<ProvenansChip :provenance="arende.provenance" @commit="$emit('commit', arende)" />
			<DiskussionChip
				v-if="arende.diskussion"
				:diskussion="arende.diskussion"
				@open-diskussion="openDiskussion" />
		</div>

		<!-- Tilldelning + ursprung (diskret rad, aldrig ett eget kort) -->
		<TilldelningBand
			v-if="arende.tilldelning"
			:tilldelning="arende.tilldelning"
			@omfordela-begaran="$emit('omfordela-begaran', arende)" />

		<!-- Provenanskedja (utökar provenans-raden till ett band) -->
		<p v-if="full.provenansKedja && full.provenansKedja.length" class="arende-kort__kedja">
			<span v-for="(led, i) in full.provenansKedja" :key="i" class="arende-kort__kedja-led">
				<ChevronRightIcon v-if="i" :size="13" class="arende-kort__kedja-pil" />{{ led }}
			</span>
		</p>

		<!-- Nästa åtgärd -->
		<div class="arende-kort__nasta">
			<span class="arende-kort__nasta-label">{{ t('hubs_start', 'Nästa åtgärd:') }}</span>
			<NastaAtgardKnapp
				:arende="arende"
				@nasta-atgard="$emit('nasta-atgard', arende)"
				@action="onMenuAction" />
		</div>

		<!-- Sekundära snabbåtgärder -->
		<div class="arende-kort__quick">
			<button v-for="q in quickActions" :key="q.key" class="arende-kort__q hs-target" type="button" :title="q.label" @click="$emit(q.key, arende)">
				<component :is="iconFor(q.icon)" :size="18" />
				<span class="arende-kort__q-txt">{{ q.short }}</span>
			</button>
		</div>

		<!-- Quick View (flikar) -->
		<section v-if="isExpanded" class="arende-kort__expand">
			<div class="arende-kort__tabs" role="tablist">
				<button
					v-for="f in flikar"
					:key="f.id"
					class="arende-kort__tab hs-target"
					:class="{ 'arende-kort__tab--active': activeFlik === f.id }"
					role="tab"
					:aria-selected="String(activeFlik === f.id)"
					@click="activeFlik = f.id">
					{{ f.label }}
				</button>
			</div>

			<div class="arende-kort__flik">
				<!-- Dokument -->
				<ul v-if="activeFlik === 'dokument'" class="arende-kort__list">
					<li v-if="full.rum" class="arende-kort__muted">{{ t('hubs_start', 'Behörighet:') }} {{ full.rum.acl }} · {{ n('hubs_start', '%n oläst', '%n olästa', full.rum.olasta || 0) }}</li>
					<li v-for="(d, i) in (full.rum && full.rum.dokument) || []" :key="i"><FileDocumentIcon :size="16" /> {{ d }}</li>
					<li v-if="!full.rum || !full.rum.dokument || !full.rum.dokument.length" class="arende-kort__muted">{{ t('hubs_start', 'Inga dokument än.') }}</li>
				</ul>

				<!-- Säkra meddelanden (extern, kvittensbärande kommunikation) -->
				<ul v-else-if="activeFlik === 'meddelanden'" class="arende-kort__list">
					<li v-for="(m, i) in full.meddelanden || []" :key="i">
						<MessageTextLockIcon :size="16" /> {{ m.titel }} <span class="arende-kort__muted">· {{ m.status }}</span>
						<KopplingBadge v-if="m.koppling" :koppling="m.koppling" @open-arende="$emit('open-rum', arende)" />
					</li>
					<li v-if="!(full.meddelanden && full.meddelanden.length)" class="arende-kort__muted">{{ t('hubs_start', 'Inga säkra meddelanden i tråden.') }}</li>
				</ul>

				<!-- Diskussion (intern ärende-chatt — separat från extern kommunikation) -->
				<ArendeDiskussion
					v-else-if="activeFlik === 'diskussion'"
					:arende="arende"
					:diskussion="full.diskussion || { meddelanden: [], deltagare: [], sekretess: {} }"
					@gor-till-handling="onGorTillHandling"
					@lyft-enhetschatt="$emit('lyft-enhetschatt', arende)"
					@skicka="$emit('skicka-diskussion', $event)" />

				<!-- Möten + efterspel -->
				<div v-else-if="activeFlik === 'moten'">
					<div v-for="(mo, i) in full.moten || []" :key="i" class="arende-kort__mote">
						<p class="arende-kort__mote-titel"><VideoIcon :size="16" /> {{ mo.titel }} <span class="arende-kort__muted">· {{ fmtTime(mo.start) }}</span></p>
						<div v-if="mo.transkript && !mo.godkand" class="arende-kort__godkann">
							<div class="arende-kort__sbs">
								<div><strong>{{ t('hubs_start', 'Transkript (lokalt)') }}</strong><p>{{ mo.transkript }}</p></div>
								<div><strong>{{ t('hubs_start', 'AI-utkast (lokalt)') }}</strong><p>{{ mo.aiUtkast }}</p></div>
							</div>
							<NcButton type="primary" @click="$emit('godkann', arende, mo)">
								<template #icon><CheckCircleIcon :size="18" /></template>
								{{ t('hubs_start', 'Granska & godkänn → för till Treserva') }}
							</NcButton>
						</div>
						<p v-else-if="mo.godkand" class="arende-kort__muted"><CheckCircleIcon :size="14" /> {{ t('hubs_start', 'Anteckning godkänd och sparad.') }}</p>
					</div>
					<p v-if="!(full.moten && full.moten.length)" class="arende-kort__muted">{{ t('hubs_start', 'Inga möten kopplade.') }}</p>
				</div>

				<!-- Bevakningar -->
				<ul v-else-if="activeFlik === 'bevakningar'" class="arende-kort__list">
					<li v-for="(b, i) in full.bevakningar || []" :key="i"><BellRingIcon :size="16" /> {{ b.titel }} <span class="arende-kort__muted">· {{ b.delad ? t('hubs_start', 'delad') : t('hubs_start', 'personlig') }}</span></li>
					<li v-if="!(full.bevakningar && full.bevakningar.length)" class="arende-kort__muted">{{ t('hubs_start', 'Inga bevakningar.') }}</li>
				</ul>

				<!-- Beslut -->
				<div v-else-if="activeFlik === 'beslut'">
					<template v-if="full.beslut">
						<p>{{ t('hubs_start', 'Kravnivå:') }} <strong>{{ full.beslut.kravniva }}</strong> · {{ full.beslut.signStatus }}</p>
						<p class="arende-kort__bevarande">
							<span :class="bevClass(full.beslut.bevarande.pades)">PAdES</span>
							<span :class="bevClass(full.beslut.bevarande.pdfa)">PDF/A-1</span>
							<span :class="bevClass(full.beslut.bevarande.ltv)">LTV</span>
							<span class="arende-kort__muted">{{ t('hubs_start', 'Giltig nu / Giltig då') }}</span>
						</p>
					</template>
					<p v-else class="arende-kort__muted">{{ t('hubs_start', 'Inget beslut framtaget än.') }}</p>
				</div>
			</div>
		</section>
	</article>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import AlertOctagonIcon from 'vue-material-design-icons/AlertOctagon.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import FileDocumentIcon from 'vue-material-design-icons/FileDocument.vue'
import MessageTextLockIcon from 'vue-material-design-icons/MessageTextLock.vue'
import VideoIcon from 'vue-material-design-icons/Video.vue'
import CheckCircleIcon from 'vue-material-design-icons/CheckCircle.vue'
import BellRingIcon from 'vue-material-design-icons/BellRing.vue'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'

import store from '../../store/index.js'
import { iconFor } from '../../services/icons.js'
import ProcessStepper from './ProcessStepper.vue'
import FristChip from './FristChip.vue'
import ProvenansChip from './ProvenansChip.vue'
import NastaAtgardKnapp from './NastaAtgardKnapp.vue'
import DiskussionChip from './DiskussionChip.vue'
import TilldelningBand from './TilldelningBand.vue'
import KopplingBadge from './KopplingBadge.vue'
import ArendeDiskussion from './ArendeDiskussion.vue'

export default {
	name: 'ArendeKort',
	components: {
		NcButton, AlertOctagonIcon, ChevronRightIcon, FileDocumentIcon, MessageTextLockIcon,
		VideoIcon, CheckCircleIcon, BellRingIcon,
		ProcessStepper, FristChip, ProvenansChip, NastaAtgardKnapp,
		DiskussionChip, TilldelningBand, KopplingBadge, ArendeDiskussion,
	},
	props: {
		arende: { type: Object, required: true },
		expanded: { type: Boolean, default: false },
		pinned: { type: Boolean, default: false },
		keyboardMode: { type: Boolean, default: false },
	},
	data() {
		return { localExpanded: this.expanded, activeFlik: 'dokument' }
	},
	computed: {
		isExpanded() {
			return this.localExpanded
		},
		pliktAktiv() {
			return !!(this.arende.plikt && !this.arende.plikt.kvitterad)
		},
		/** Lazily-loaded full ärende (flik content) merged over the collapsed one. */
		full() {
			const f = store.state.arende.full[this.arende.dnr]
			return f ? { ...this.arende, ...f } : this.arende
		},
		flikar() {
			return [
				{ id: 'dokument', label: t('hubs_start', 'Dokument') },
				{ id: 'meddelanden', label: t('hubs_start', 'Säkra meddelanden') },
				{ id: 'diskussion', label: t('hubs_start', 'Diskussion') },
				{ id: 'moten', label: t('hubs_start', 'Möten') },
				{ id: 'bevakningar', label: t('hubs_start', 'Bevakningar') },
				{ id: 'beslut', label: t('hubs_start', 'Beslut') },
			]
		},
		quickActions() {
			return [
				{ key: 'open-rum', icon: 'FolderLock', short: t('hubs_start', 'Ärenderum'), label: t('hubs_start', 'Öppna ärenderum') },
				{ key: 'skicka', icon: 'EmailFast', short: t('hubs_start', 'Skicka'), label: t('hubs_start', 'Skicka säkert meddelande') },
				{ key: 'boka-mote', icon: 'VideoPlus', short: t('hubs_start', 'Möte'), label: t('hubs_start', 'Boka säkert möte') },
				{ key: 'signera', icon: 'FileSign', short: t('hubs_start', 'Signera'), label: t('hubs_start', 'Skicka för underskrift') },
				{ key: 'bevakning', icon: 'BellPlus', short: t('hubs_start', 'Bevakning'), label: t('hubs_start', 'Skapa bevakning') },
			]
		},
	},
	watch: {
		expanded(v) {
			this.localExpanded = v
		},
	},
	methods: {
		t,
		n,
		iconFor,
		toggleExpand() {
			this.localExpanded = !this.localExpanded
			if (this.localExpanded && this.arende.dnr) {
				store.loadArende(this.arende.dnr)
			}
			this.$emit('expand', this.arende)
		},
		onStepperGoto({ steg }) {
			const map = { beslut: 'beslut', utredning: 'dokument', uppfoljning: 'bevakningar', avslutat: 'dokument', forhandsbedomning: 'dokument' }
			this.activeFlik = map[steg] || 'dokument'
			if (!this.localExpanded) {
				this.toggleExpand()
			}
		},
		onMenuAction(key) {
			this.$emit(key, this.arende)
		},
		/** Öppna ärendechatten: expandera kortet och hoppa till Diskussion-fliken. */
		openDiskussion() {
			this.activeFlik = 'diskussion'
			if (!this.localExpanded) {
				this.toggleExpand()
			}
		},
		/** "Gör detta till en handling" → human-in-the-loop → commit-grinden (förälder). */
		onGorTillHandling(payload) {
			this.$emit('commit', this.arende)
		},
		bevClass(ok) {
			return ok ? 'arende-kort__bev arende-kort__bev--ok' : 'arende-kort__bev'
		},
		fmtTime(s) {
			try {
				return new Date(s).toLocaleString('sv-SE', { hour: '2-digit', minute: '2-digit', day: 'numeric', month: 'short' })
			} catch (e) {
				return s
			}
		},
	},
}
</script>

<style scoped lang="scss">
.arende-kort {
	display: flex;
	flex-direction: column;
	gap: 10px;

	&--het { border: 1px solid var(--color-primary-element); box-shadow: 0 2px 10px var(--color-box-shadow, rgba(0, 0, 0, 0.1)); }
	&--plikt { border-color: var(--hs-status-error); }

	&__plikt {
		display: flex;
		align-items: center;
		gap: 6px;
		margin: 0;
		padding: 4px 10px;
		border-radius: var(--border-radius-pill, 16px);
		background: var(--hs-status-error);
		color: #fff;
		font-size: 0.82rem;
		font-weight: 600;
		align-self: flex-start;
	}

	&__head { display: flex; align-items: center; justify-content: space-between; gap: 10px; }

	&__titel {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		background: transparent;
		border: none;
		cursor: pointer;
		font-size: 1.05rem;
		font-weight: 700;
		color: var(--color-main-text);
		text-align: start;
		min-width: 0;
	}

	&__chev { flex: 0 0 auto; transition: transform 0.15s ease; color: var(--color-text-maxcontrast); }
	&__chev--open { transform: rotate(90deg); }
	&__dnr { font-weight: 400; font-size: 0.85rem; color: var(--color-text-maxcontrast); }

	&__badges { display: inline-flex; gap: 6px; flex: 0 0 auto; }
	&__sekretess, &__loa {
		font-size: 0.7rem;
		font-weight: 600;
		padding: 1px 7px;
		border-radius: var(--border-radius-pill, 16px);
		background: var(--color-background-dark);
		color: var(--color-text-maxcontrast);
		white-space: nowrap;
	}

	&__meta { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }

	&__kedja {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 2px 4px;
		margin: 0;
		font-size: 0.74rem;
		color: var(--color-text-maxcontrast);
	}
	&__kedja-led { display: inline-flex; align-items: center; gap: 2px; }
	&__kedja-pil { color: var(--color-border); }

	&__nasta { display: flex; align-items: center; gap: 10px; }
	&__nasta-label { font-size: 0.85rem; color: var(--color-text-maxcontrast); white-space: nowrap; flex: 0 0 auto; }

	&__quick { display: flex; flex-wrap: wrap; gap: 6px; }
	&__q {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		padding: 4px 10px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large, 12px);
		background: var(--color-main-background);
		color: var(--color-main-text);
		font-size: 0.8rem;
		cursor: pointer;
		&:hover { background: var(--color-background-hover); }
	}

	&__expand { border-top: 1px solid var(--color-border); padding-top: 10px; }
	&__tabs { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 8px; }
	&__tab {
		padding: 4px 12px;
		border: none;
		border-radius: var(--border-radius-pill, 16px);
		background: var(--color-background-dark);
		color: var(--color-main-text);
		font-size: 0.82rem;
		cursor: pointer;
		&--active { background: var(--color-primary-element); color: var(--color-primary-element-text); }
	}

	&__list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 5px; font-size: 0.88rem;
		li { display: flex; align-items: center; gap: 6px; } }
	&__muted { color: var(--color-text-maxcontrast); font-size: 0.82rem; }

	&__mote { margin-bottom: 10px; }
	&__mote-titel { display: flex; align-items: center; gap: 6px; margin: 0 0 6px; font-weight: 600; }
	&__sbs { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 8px; font-size: 0.82rem;
		p { margin: 4px 0 0; color: var(--color-text-maxcontrast); }
		@media (max-width: 600px) { grid-template-columns: 1fr; } }

	&__bevarande { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
	&__bev { font-size: 0.72rem; padding: 1px 8px; border-radius: var(--border-radius-pill, 16px); border: 1px solid var(--color-border); color: var(--color-text-maxcontrast); }
	&__bev--ok { border-color: var(--hs-status-success); color: var(--hs-status-success); }
}
</style>
