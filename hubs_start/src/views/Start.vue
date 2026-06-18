<template>
	<div class="hubs-start" :class="{ 'hubs-start--keyboard': prefs.keyboardMode }">
		<!-- Socialsekreteraren har en omdesignad ärende-centrisk vy -->
		<MinaArenden v-if="isSocialsekreterare" />

		<template v-else>
		<!-- Onboarding replaces firstrunwizard; only on first visit -->
		<Onboarding
			v-if="!prefs.onboardingSeen"
			:loa="state.loa"
			:apps="state.apps"
			:mailboxes="state.mailboxes"
			@finish="onOnboardingFinish" />

		<!-- 1. Header: greeting, persona switcher (demo), LOA chip, residency badge -->
		<HeaderBar
			:loa="state.loa"
			:profile="state.profile"
			:demo-mode="state.demoMode"
			:personas="personas"
			:active-persona="state.activePersona"
			:persona-label="personaLabel"
			:persona-tagline="personaTagline"
			@persona-change="onPersonaChange"
			@upgrade-loa="onUpgradeLoa" />

		<!-- 2. Persona's primary actions -->
		<ActionBar
			:actions="personaActions"
			@action="onPrimaryAction" />

		<NcLoadingIcon v-if="state.loading" :size="44" class="hubs-start__loading" />

		<!-- 3. Persona-driven widget layout -->
		<div v-else class="hubs-start__grid">
			<main class="hubs-start__main">
				<WidgetRenderer
					v-for="id in mainWidgets"
					:key="'main-' + id"
					:widget-id="id"
					@take="onTake"
					@open="onOpen"
					@done="onDone"
					@join="onJoin"
					@action="onWidgetAction" />
			</main>

			<aside class="hubs-start__aside">
				<WidgetRenderer
					v-for="id in sideWidgets"
					:key="'side-' + id"
					:widget-id="id"
					@take="onTake"
					@open="onOpen"
					@done="onDone"
					@join="onJoin"
					@action="onWidgetAction" />
			</aside>
		</div>

		<!-- Modals / overlays -->
		<SmartMottagare
			v-if="smartMottagareOpen"
			@close="smartMottagareOpen = false"
			@chosen="onRecipientChosen" />
		<MeetingWizard
			v-if="meetingWizardOpen"
			:start-now="meetingStartNow"
			@close="meetingWizardOpen = false"
			@booked="onMeetingBooked" />
		<CommandPalette
			v-if="commandPaletteOpen"
			@close="commandPaletteOpen = false"
			@new-message="openSmartMottagare"
			@book-meeting="openMeetingWizard"
			@start-meeting="startInstantMeeting" />
		</template>
	</div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import { showError, showSuccess, showInfo } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'

import store from '../store/index.js'
import api from '../services/api.js'
import deepLinks from '../services/deepLinks.js'
import { CHANNEL_TO_MESSAGE_TYPE } from '../services/channels.js'
import { getPersona, actionsForPersona, listPersonas } from '../services/personas.js'
import { provenanceFor, isNative, appUrl } from '../services/appProvenance.js'

import HeaderBar from '../components/HeaderBar.vue'
import ActionBar from '../components/ActionBar.vue'
import WidgetRenderer from '../components/WidgetRenderer.vue'
import MinaArenden from '../components/socialsekreterare/MinaArenden.vue'
import SmartMottagare from '../components/SmartMottagare.vue'
import MeetingWizard from '../components/MeetingWizard.vue'
import CommandPalette from '../components/CommandPalette.vue'
import Onboarding from '../components/Onboarding.vue'

export default {
	name: 'StartView',
	components: {
		NcLoadingIcon,
		HeaderBar, ActionBar, WidgetRenderer, MinaArenden,
		SmartMottagare, MeetingWizard, CommandPalette, Onboarding,
	},

	data() {
		return {
			store,
			state: store.state,
			smartMottagareOpen: false,
			meetingWizardOpen: false,
			meetingStartNow: false,
			commandPaletteOpen: false,
		}
	},

	computed: {
		prefs() {
			return this.state.prefs
		},
		personas() {
			return listPersonas()
		},
		currentPersona() {
			return getPersona(this.state.activePersona)
		},
		personaLabel() {
			return this.currentPersona ? this.currentPersona.label : ''
		},
		personaTagline() {
			return this.currentPersona ? this.currentPersona.tagline : ''
		},
		personaActions() {
			return actionsForPersona(this.currentPersona)
		},
		isSocialsekreterare() {
			return this.state.activePersona === 'socialsekreterare'
		},
		mainWidgets() {
			return this.currentPersona && this.currentPersona.layout ? this.currentPersona.layout.main : []
		},
		sideWidgets() {
			return this.currentPersona && this.currentPersona.layout ? this.currentPersona.layout.side : []
		},
	},

	created() {
		store.bootFromInitialState()
		store.loadAll()
		store.startPolling()
	},

	mounted() {
		document.addEventListener('keydown', this.onGlobalKeydown)
	},

	beforeDestroy() {
		document.removeEventListener('keydown', this.onGlobalKeydown)
		store.stopPolling()
	},

	methods: {
		t,

		onGlobalKeydown(e) {
			if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
				e.preventDefault()
				this.openCommandPalette()
			}
		},

		onPersonaChange(id) {
			store.setPersona(id)
		},

		openSmartMottagare() {
			this.commandPaletteOpen = false
			this.smartMottagareOpen = true
		},
		openMeetingWizard() {
			this.commandPaletteOpen = false
			this.meetingStartNow = false
			this.meetingWizardOpen = true
		},
		openCommandPalette() {
			this.commandPaletteOpen = true
		},

		/**
		 * Route a persona primary action. A few map to real demo flows (Smart
		 * mottagare, mötes-wizard); the rest are proposed features → friendly notice.
		 */
		onPrimaryAction(action) {
			const feature = action && action.feature
			if (feature === 'attHantera') {
				return this.openSmartMottagare()
			}
			if (feature === 'bokningsbaraTider' || feature === 'dagensMoten') {
				return this.openMeetingWizard()
			}
			// Native-backed action → open the real installed app.
			if (this.openNative(feature)) {
				return
			}
			this.proposedNotice(action ? action.label : '')
		},

		/** A widget row CTA was clicked. Native widgets open the real app; others show a notice. */
		onWidgetAction(payload) {
			if (payload && payload.widgetId && this.openNative(payload.widgetId)) {
				return
			}
			const label = payload && payload.row ? payload.row.title : (payload && payload.widget) || ''
			this.proposedNotice(label)
		},

		/** If the widget/feature is backed by an installed native app, open it. */
		openNative(widgetId) {
			const prov = provenanceFor(widgetId)
			if (prov && isNative(prov) && appUrl(prov)) {
				window.location.href = appUrl(prov)
				return true
			}
			return false
		},

		/** Friendly notice for a proposed/demo feature. */
		proposedNotice(label) {
			showInfo(this.t('hubs_start', '{label} — demonstration. På en riktig Hubs-installation startar detta flödet här.', { label }))
		},

		/**
		 * In demo mode outbound deep links would 404 (sibling apps absent) — show a
		 * notice and stay put.
		 */
		demoBlocked() {
			if (this.state.demoMode) {
				showInfo(this.t('hubs_start', 'Demoläge: länken är inaktiv. På en riktig Hubs-installation öppnas rätt vy här.'))
				return true
			}
			return false
		},

		onUpgradeLoa() {
			if (this.demoBlocked()) {
				return
			}
			window.location.href = deepLinks.loa3UpgradeLink()
		},

		onRecipientChosen(recipient) {
			this.smartMottagareOpen = false
			if (this.demoBlocked()) {
				return
			}
			const messageType = recipient?.classification?.messageType
				|| CHANNEL_TO_MESSAGE_TYPE[recipient?.classification?.channel]
			window.location.href = deepLinks.composerLink(messageType, recipient.address)
		},

		startInstantMeeting() {
			this.commandPaletteOpen = false
			this.meetingStartNow = true
			this.meetingWizardOpen = true
		},

		onMeetingBooked(result) {
			this.meetingWizardOpen = false
			showSuccess(this.t('hubs_start', 'Möte bokat'))
			store.refreshMeetings()
		},

		onOnboardingFinish() {
			store.markOnboardingSeen()
		},

		// Queue item events (real widgets) ------------------------------------
		async onTake(item) {
			if (!item.assignment) {
				return
			}
			try {
				await api.takeThread(item.assignment.imapLabel, {
					accountId: item.assignment.accountId,
					threadRootIds: [item.assignment.threadRootId],
				})
				store.removeItem(item.id)
				showSuccess(this.t('hubs_start', 'Ärendet är ditt'))
			} catch (e) {
				showError(this.t('hubs_start', 'Kunde inte ta ärendet'))
			}
		},

		onOpen(item) {
			if (this.demoBlocked()) {
				return
			}
			window.location.href = deepLinks.resolve(item.deepLink)
		},

		async onDone(item) {
			try {
				if (item.doneTag) {
					await api.setMessageTag(item.messageId, item.doneTag, true)
				}
				store.removeItem(item.id)
			} catch (e) {
				showError(this.t('hubs_start', 'Kunde inte markera som klar'))
			}
		},

		onJoin(meeting) {
			if (this.demoBlocked()) {
				return
			}
			window.location.href = deepLinks.callLink(meeting.token)
		},
	},
}
</script>

<style scoped lang="scss">
.hubs-start {
	max-width: 1320px;
	margin: 0 auto;
	padding: 16px 24px 64px;

	&__loading {
		margin: 80px auto;
	}

	&__grid {
		display: grid;
		grid-template-columns: 3fr 2fr;
		gap: 20px;
		margin-top: 16px;
		align-items: start;
	}

	&__main,
	&__aside {
		display: flex;
		flex-direction: column;
		gap: 16px;
		min-width: 0;
	}

	@media (max-width: 1024px) {
		&__grid {
			grid-template-columns: 1fr;
		}
	}
}
</style>
