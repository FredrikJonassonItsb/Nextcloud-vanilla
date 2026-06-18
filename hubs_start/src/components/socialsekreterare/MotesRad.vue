<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="motes-rad" :class="{ 'motes-rad--soon': brinner }">
		<!-- Tid + nedräkning -->
		<span class="motes-rad__tid">
			<CalendarClockIcon class="motes-rad__tid-ikon" :size="20" />
			<span class="motes-rad__klocka">{{ tidLabel }}</span>
			<span v-if="hasCountdown"
				class="motes-rad__nedrakning"
				:class="{ 'motes-rad__nedrakning--soon': brinner }">
				{{ nedrakningLabel }}
			</span>
		</span>

		<!-- Titel + ärendekoppling (dnr-chip) -->
		<span class="motes-rad__mitt">
			<span class="motes-rad__titel">{{ meeting.title }}</span>
			<span v-if="meeting.dnr" class="motes-rad__dnr" :title="dnrTitle">
				<FolderLockIcon :size="14" />
				{{ meeting.dnr }}
			</span>
		</span>

		<!-- Lobbystatus + verifieringsbock -->
		<span v-if="lobbyWaiting > 0"
			class="motes-rad__lobby"
			:style="{ '--verifiering-color': verificationColor }">
			<component :is="verificationIcon" class="motes-rad__verifiering" :size="18" />
			<span class="motes-rad__lobby-text">{{ lobbyLabel }}</span>
		</span>

		<!-- Anslut säkert -->
		<NcButton type="primary"
			class="motes-rad__anslut"
			:disabled="!meeting.hasCall"
			@click="$emit('join', meeting)">
			<template #icon>
				<VideoIcon :size="20" />
			</template>
			{{ t('hubs_start', 'Anslut säkert') }}
		</NcButton>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import CalendarClockIcon from 'vue-material-design-icons/CalendarClock.vue'
import FolderLockIcon from 'vue-material-design-icons/FolderLock.vue'
import VideoIcon from 'vue-material-design-icons/Video.vue'
import ShieldCheckIcon from 'vue-material-design-icons/ShieldCheck.vue'
import CheckCircleIcon from 'vue-material-design-icons/CheckCircle.vue'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'MotesRad',

	components: {
		NcButton,
		CalendarClockIcon,
		FolderLockIcon,
		VideoIcon,
		ShieldCheckIcon,
		CheckCircleIcon,
	},

	props: {
		meeting: {
			type: Object,
			required: true,
		},
	},

	computed: {
		/** Localised start time (HH:mm). Falls back to the raw value. */
		tidLabel() {
			const raw = this.meeting.start
			if (!raw) {
				return ''
			}
			const d = new Date(raw)
			if (isNaN(d.getTime())) {
				return String(raw)
			}
			return d.toLocaleTimeString('sv-SE', { hour: '2-digit', minute: '2-digit' })
		},

		hasCountdown() {
			return typeof this.meeting.countdownMin === 'number' && this.meeting.countdownMin >= 0
		},

		nedrakningLabel() {
			return t('hubs_start', 'börjar om {n} min', { n: this.meeting.countdownMin })
		},

		/** Imminent meeting (≤15 min) — amber emphasis + text cue, never colour alone. */
		brinner() {
			return this.hasCountdown && this.meeting.countdownMin <= 15
		},

		lobbyWaiting() {
			const lobby = this.meeting.lobbyState || {}
			return Number(lobby.waiting) || 0
		},

		/** Verified e-ID (BankID/Freja) → grön bock; annars lila (säker, ej e-leg). */
		isVerified() {
			return this.meeting.verificationBadge === 'green'
		},

		verificationIcon() {
			return this.isVerified ? CheckCircleIcon : ShieldCheckIcon
		},

		verificationColor() {
			// grön = BankID/Freja-verifierad, lila = säker kanal men ej e-legitimerad
			return this.isVerified ? 'var(--hs-status-success)' : 'var(--hs-channel-secure)'
		},

		lobbyLabel() {
			const n = this.lobbyWaiting
			return this.isVerified
				? t('hubs_start', '{n} i lobby · BankID-verifierad', { n })
				: t('hubs_start', '{n} i lobby · säker kanal', { n })
		},

		dnrTitle() {
			return t('hubs_start', 'Kopplat till ärende {dnr}', { dnr: this.meeting.dnr })
		},
	},

	methods: {
		t,
	},
}
</script>

<style scoped lang="scss">
.motes-rad {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 8px 16px;
	padding: 8px 4px;

	& + & {
		border-top: 1px solid var(--color-border);
	}

	&__tid {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		min-width: 0;
	}

	&__tid-ikon {
		color: var(--color-text-maxcontrast);
		flex-shrink: 0;
	}

	&__klocka {
		font-weight: 600;
		font-variant-numeric: tabular-nums;
	}

	&__nedrakning {
		font-size: 0.85rem;
		color: var(--color-text-maxcontrast);
		white-space: nowrap;

		&--soon {
			color: var(--hs-status-warning);
			font-weight: 600;
		}
	}

	&__mitt {
		display: inline-flex;
		align-items: center;
		flex-wrap: wrap;
		gap: 6px 10px;
		flex: 1 1 200px;
		min-width: 0;
	}

	&__titel {
		font-weight: 500;
		overflow: hidden;
		text-overflow: ellipsis;
		white-space: nowrap;
	}

	&__dnr {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		padding: 2px 8px;
		min-height: var(--hs-min-target);
		box-sizing: border-box;
		border-radius: var(--border-radius-pill, 16px);
		background: var(--color-background-dark);
		color: var(--color-text-maxcontrast);
		font-size: 0.8rem;
		font-variant-numeric: tabular-nums;
		white-space: nowrap;
	}

	&__lobby {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		padding: 2px 8px;
		min-height: var(--hs-min-target);
		box-sizing: border-box;
		border-radius: var(--border-radius-pill, 16px);
		background: var(--color-background-hover);
		font-size: 0.82rem;
		white-space: nowrap;
	}

	&__verifiering {
		color: var(--verifiering-color);
		flex-shrink: 0;
	}

	&__anslut {
		flex-shrink: 0;
		margin-inline-start: auto;
	}
}
</style>
