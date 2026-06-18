<!--
  - SPDX-FileCopyrightText: ITSL <info@itsl.se>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<section class="hs-card dagens-moten">
		<h2 class="hs-card__title">
			<CalendarClock :size="20" />
			{{ t('hubs_start', 'Dagens säkra möten') }}
		</h2>

		<NcEmptyContent
			v-if="!meetings.length"
			:name="t('hubs_start', 'Inga möten idag')">
			<template #icon>
				<CalendarBlank :size="20" />
			</template>
		</NcEmptyContent>

		<ul v-else class="dagens-moten__list">
			<li
				v-for="meeting in sortedMeetings"
				:key="meeting.token"
				class="dagens-moten__item">
				<div class="dagens-moten__head">
					<div class="dagens-moten__title">
						{{ meeting.title }}
					</div>
					<span
						v-if="badgeOf(meeting)"
						class="dagens-moten__badge"
						:class="'dagens-moten__badge--' + badgeOf(meeting).tone"
						:title="badgeOf(meeting).label">
						<ShieldCheck :size="16" />
						{{ badgeOf(meeting).label }}
					</span>
				</div>

				<div class="dagens-moten__time">
					<Clock :size="16" />
					<span>{{ timeRange(meeting) }}</span>
					<span class="dagens-moten__countdown">{{ countdown(meeting) }}</span>
				</div>

				<p
					v-if="lobbyCount(meeting) > 0"
					class="dagens-moten__lobby"
					aria-live="polite">
					<AccountClock :size="16" />
					{{ lobbyLine(meeting) }}
				</p>

				<div class="dagens-moten__actions">
					<a
						class="dagens-moten__check hs-target"
						:href="callHref(meeting)"
						@click="onJoin(meeting, $event)">
						<VideoCheck :size="16" />
						{{ t('hubs_start', 'Kontrollera kamera & mikrofon') }}
					</a>
					<NcButton
						type="primary"
						@click="onJoin(meeting)">
						<template #icon>
							<VideoOutline :size="20" />
						</template>
						{{ t('hubs_start', 'Gå till mötet') }}
					</NcButton>
				</div>
			</li>
		</ul>
	</section>
</template>

<script>
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcEmptyContent from '@nextcloud/vue/dist/Components/NcEmptyContent.js'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'

import AccountClock from 'vue-material-design-icons/AccountClock.vue'
import CalendarBlank from 'vue-material-design-icons/CalendarBlank.vue'
import CalendarClock from 'vue-material-design-icons/CalendarClock.vue'
import Clock from 'vue-material-design-icons/ClockOutline.vue'
import ShieldCheck from 'vue-material-design-icons/ShieldCheck.vue'
import VideoCheck from 'vue-material-design-icons/VideoCheckOutline.vue'
import VideoOutline from 'vue-material-design-icons/VideoOutline.vue'

import api from '../services/api.js'
import deepLinks from '../services/deepLinks.js'

/** Poll the lobby for meetings starting within this window (ms). */
const LOBBY_WINDOW_MS = 15 * 60 * 1000
const LOBBY_POLL_MS = 30000

export default {
	name: 'DagensMoten',

	components: {
		NcButton,
		NcEmptyContent,
		AccountClock,
		CalendarBlank,
		CalendarClock,
		Clock,
		ShieldCheck,
		VideoCheck,
		VideoOutline,
	},

	props: {
		meetings: {
			type: Array,
			default: () => [],
		},
	},

	data() {
		return {
			/** ticking clock so countdown + soon-window recompute reactively */
			now: Date.now(),
			/** token → verified lobby count from fetchLobbyStatus */
			lobbyCounts: {},
			clockTimer: null,
			lobbyTimer: null,
		}
	},

	computed: {
		sortedMeetings() {
			return [...this.meetings].sort((a, b) => {
				const sa = new Date(a.start).getTime() || 0
				const sb = new Date(b.start).getTime() || 0
				return sa - sb
			})
		},
	},

	mounted() {
		this.clockTimer = setInterval(() => {
			this.now = Date.now()
		}, 30000)
		this.lobbyTimer = setInterval(this.refreshLobbies, LOBBY_POLL_MS)
		this.refreshLobbies()
	},

	beforeDestroy() {
		if (this.clockTimer) {
			clearInterval(this.clockTimer)
		}
		if (this.lobbyTimer) {
			clearInterval(this.lobbyTimer)
		}
	},

	methods: {
		t,
		n,

		/** Localised HH:MM–HH:MM range. */
		timeRange(meeting) {
			const start = this.formatTime(meeting.start)
			const end = this.formatTime(meeting.end)
			return end ? start + '–' + end : start
		},

		formatTime(iso) {
			if (!iso) {
				return ''
			}
			const d = new Date(iso)
			if (isNaN(d.getTime())) {
				return ''
			}
			return d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
		},

		/** Human countdown relative to start (or "pågår" once started). */
		countdown(meeting) {
			const start = new Date(meeting.start).getTime()
			if (isNaN(start)) {
				return ''
			}
			const diffMin = Math.round((start - this.now) / 60000)
			if (diffMin <= 0) {
				return this.t('hubs_start', 'Pågår nu')
			}
			if (diffMin < 60) {
				return this.t('hubs_start', 'Börjar om {min} min', { min: diffMin })
			}
			const h = Math.floor(diffMin / 60)
			const m = diffMin % 60
			return this.t('hubs_start', 'Börjar om {h} h {min} min', { h, min: m })
		},

		/**
		 * BankID badge. green = BankID + personnummer, purple = enbart BankID.
		 * Server already classifies via verificationBadge; bankIdRequired gates it.
		 * @param {object} meeting
		 * @return {?object}
		 */
		badgeOf(meeting) {
			if (meeting.verificationBadge === 'green') {
				return { tone: 'green', label: this.t('hubs_start', 'BankID + personnummer') }
			}
			if (meeting.verificationBadge === 'purple') {
				return { tone: 'purple', label: this.t('hubs_start', 'Enbart BankID') }
			}
			return null
		},

		/** Whether the meeting starts soon enough to poll its lobby. */
		isSoon(meeting) {
			const start = new Date(meeting.start).getTime()
			if (isNaN(start)) {
				return false
			}
			const end = new Date(meeting.end).getTime()
			const upperOk = isNaN(end) ? true : this.now <= end
			return start - this.now <= LOBBY_WINDOW_MS && upperOk
		},

		lobbyCount(meeting) {
			return this.lobbyCounts[meeting.token] || 0
		},

		/** Plural lobby line via n(). */
		lobbyLine(meeting) {
			const count = this.lobbyCount(meeting)
			return this.n(
				'hubs_start',
				'%n verifierad deltagare väntar i lobbyn',
				'%n verifierade deltagare väntar i lobbyn',
				count,
			)
		},

		/** Poll lobby status for soon-starting meetings. */
		async refreshLobbies() {
			const soon = this.meetings.filter((m) => m.token && this.isSoon(m))
			await Promise.all(soon.map(async (m) => {
				try {
					const status = await api.fetchLobbyStatus(m.token)
					this.$set(this.lobbyCounts, m.token, status?.verifiedCount || 0)
				} catch (e) {
					/* non-fatal: lobby line simply hides */
				}
			}))
		},

		callHref(meeting) {
			return deepLinks.callLink(meeting.token)
		},

		onJoin(meeting, event) {
			if (event) {
				event.preventDefault()
			}
			this.$emit('join', meeting)
		},
	},
}
</script>

<style scoped lang="scss">
.dagens-moten {
	&__list {
		list-style: none;
		margin: 0;
		padding: 0;
		display: flex;
		flex-direction: column;
		gap: 12px;
	}

	&__item {
		padding: 12px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large, 8px);
		background: var(--color-background-hover);
	}

	&__head {
		display: flex;
		align-items: flex-start;
		justify-content: space-between;
		gap: 8px;
	}

	&__title {
		font-weight: 600;
	}

	&__badge {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		flex-shrink: 0;
		padding: 2px 8px;
		border-radius: 16px;
		font-size: 0.8rem;
		font-weight: 600;
		color: #fff;
		min-height: var(--hs-min-target);
		box-sizing: border-box;

		&--green {
			background: var(--hs-status-success);
		}

		&--purple {
			background: var(--hs-channel-secure);
		}
	}

	&__time {
		display: flex;
		align-items: center;
		gap: 6px;
		margin-top: 6px;
		color: var(--color-text-maxcontrast);
		font-size: 0.9rem;
	}

	&__countdown {
		font-weight: 600;
		color: var(--color-main-text);
	}

	&__lobby {
		display: flex;
		align-items: center;
		gap: 6px;
		margin: 8px 0 0;
		color: var(--hs-status-info);
		font-size: 0.9rem;
	}

	&__actions {
		display: flex;
		align-items: center;
		justify-content: space-between;
		flex-wrap: wrap;
		gap: 8px;
		margin-top: 12px;
	}

	&__check {
		display: inline-flex;
		align-items: center;
		gap: 4px;
		min-height: var(--hs-min-target);
		padding: 0 4px;
		color: var(--color-primary-element);
		text-decoration: underline;
		border-radius: var(--border-radius, 4px);

		&:hover {
			text-decoration: none;
		}

		&:focus-visible {
			outline: 2px solid var(--color-primary-element);
			outline-offset: 2px;
		}
	}
}
</style>
