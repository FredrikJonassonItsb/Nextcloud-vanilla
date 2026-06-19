<template>
	<div class="mina-arenden">
		<OnboardingTour
			v-if="!prefs.onboardingSeen"
			:seen="prefs.onboardingSeen"
			@finish="onTourFinish" />

		<!-- Demo: persona-växlare (i en riktig install härleds personan ur roll/grupp) -->
		<div v-if="state.demoMode" class="mina-arenden__personbar">
			<!-- Roll-läge: handläggare vs gruppledare (i prod styrt av fördelarroll i korgens team) -->
			<div class="mina-arenden__lagevaxel" role="group" :aria-label="t('hubs_start', 'Växla arbetsläge')">
				<button
					type="button"
					class="mina-arenden__lageknapp hs-target"
					:class="{ 'mina-arenden__lageknapp--aktiv': A.lage === 'utredning' }"
					:aria-pressed="String(A.lage === 'utredning')"
					@click="store.setLage('utredning')">
					<AccountIcon :size="16" /> {{ t('hubs_start', 'Mitt arbete') }}
				</button>
				<button
					type="button"
					class="mina-arenden__lageknapp hs-target"
					:class="{ 'mina-arenden__lageknapp--aktiv': A.lage === 'fordelning' }"
					:aria-pressed="String(A.lage === 'fordelning')"
					@click="store.setLage('fordelning')">
					<AccountSupervisorIcon :size="16" /> {{ t('hubs_start', 'Fördela (gruppledare)') }}
				</button>
			</div>
			<PersonaSwitcher
				:personas="personas"
				:active="state.activePersona"
				@change="store.setPersona" />
		</div>

		<!-- Zon 0: sidhuvud + Dagspulsen (sticky) -->
		<MinDagHeader
			:loa="state.loa"
			:profile="state.profile"
			:puls="A.puls"
			:aktivt-filter="A.pulsFilter"
			@filter="store.setPulsFilter"
			@open-palette="commandPaletteOpen = true"
			@upgrade-loa="onUpgradeLoa"
			@open-help="onOpenHelp" />

		<!-- Zon V: verbingång -->
		<VadVillDuGora
			:dismissed="prefs.verbEntryDismissed"
			@verb="onVerb"
			@dismiss="onDismissVerb" />

		<NcLoadingIcon v-if="A.loading" :size="44" class="mina-arenden__loading" />

		<!-- Roll-läge: gruppledarens fördelningsvy (ovanpå samma kort/zon-arkitektur) -->
		<FordelningsVy
			v-else-if="A.lage === 'fordelning'"
			:summary="A.fordelning"
			:loading="A.fordelning.loading"
			@fordela="onFordela"
			@omfordela="onOmfordela" />

		<template v-else>
			<!-- Zon 1 topp: korg-väljare (korg-piller + typ-filter) -->
			<KorgValjare
				:korgar="A.korgar"
				:aktiv-korg="A.aktivKorg"
				:aktiv-typ="A.aktivTyp"
				@valj-korg="store.setKorgFilter"
				@valj-typ="store.setTypFilter" />

			<!-- Zon 1a: att ta emot (nytt-ärende-inflöde) -->
			<AttTaEmotSektion
				:items="taEmotItems"
				:aktivt-filter="A.pulsFilter"
				@triage="onTriage"
				@open="onOpenTriage" />

			<!-- Zon 1b: att hantera (inflöde som hör till mina pågående ärenden) -->
			<AttHanteraSektion
				:items="attHanteraItems"
				:gruppering="attHanteraGruppering"
				:aktiv-korg="A.aktivKorg"
				:aktiv-typ="A.aktivTyp"
				@satt-gruppering="attHanteraGruppering = $event"
				@spara-i-rum="onInflode('spara-i-rum', $event)"
				@skapa-bevakning="onInflode('skapa-bevakning', $event)"
				@besvara="onInflode('besvara', $event)"
				@open-arende="onOpenKoppling" />

			<!-- Zon 1c: ej ärendekopplat (hink + gallringsgrind ägs av sektionen) -->
			<EjKoppladSektion
				:items="ejKoppladItems"
				:aktiv-korg="A.aktivKorg"
				:aktiv-typ="A.aktivTyp"
				@koppla="onInflode('koppla', $event)"
				@skapa="onInflode('skapa', $event)"
				@besvara="onInflode('besvara', $event)"
				@vidarebefordra="onVidarebefordra"
				@gallra="onInflode('gallra', $event)"
				@registrera="onInflode('registrera', $event)" />

			<!-- Zon 2: kräver åtgärd nu (heta, pinnade) -->
			<ArendeZon
				:arenden="hetaArenden"
				:pinned="true"
				:title="t('hubs_start', 'Kräver åtgärd nu')"
				:keyboard-mode="prefs.keyboardMode"
				@nasta-atgard="onNastaAtgard"
				@open-rum="onOpenRum"
				@skicka="onSkicka"
				@boka-mote="openMeetingWizard"
				@signera="onSignera"
				@commit="onCommit"
				@bevakning="onBevakning"
				@godkann="onGodkann"
				@expand="onExpand" />

			<!-- Zon 3: mina ärenden (alla aktiva, filtrerbar) -->
			<ArendeZon
				:arenden="aktivaArenden"
				:pinned="false"
				:title="t('hubs_start', 'Mina ärenden')"
				:filter-steg="A.stegFilter"
				:keyboard-mode="prefs.keyboardMode"
				@filter-steg="store.setStegFilter"
				@nasta-atgard="onNastaAtgard"
				@open-rum="onOpenRum"
				@skicka="onSkicka"
				@boka-mote="openMeetingWizard"
				@signera="onSignera"
				@commit="onCommit"
				@bevakning="onBevakning"
				@godkann="onGodkann"
				@expand="onExpand" />

			<!-- Zon 4: mina möten idag (mötesanteckning-godkännande sker i ärendekortet) -->
			<MotesRemsa
				:meetings="meetings"
				@join="onJoin" />

			<!-- Zon 4.5: kvittenser & gallring (Treserva = slutlagring; Hubs = mellanlagring) -->
			<TreservaKvittens :receipts="A.receipts" />

			<!-- Zon 5: foten -->
			<footer class="mina-arenden__foten hs-card">
				<span class="mina-arenden__klart">
					<CheckAllIcon :size="18" />
					{{ t('hubs_start', 'Klart idag: {n}', { n: A.klartIdag }) }}
				</span>
				<button type="button" class="mina-arenden__lank mina-arenden__chatt hs-target" @click="store.toggleEnhetschatt(true)">
					<ForumIcon :size="18" /> {{ t('hubs_start', 'Enhetschatt') }}
					<NcCounterBubble v-if="enhetschattOmnamnanden" class="mina-arenden__chatt-badge">@{{ enhetschattOmnamnanden }}</NcCounterBubble>
					<span v-else-if="enhetschattOlasta" class="mina-arenden__chatt-olasta">{{ enhetschattOlasta }}</span>
					<ChevronRightIcon :size="16" />
				</button>
				<!-- #3 — Egna anteckningar är PERSONLIGA (per handläggare), hör inte till
				     något ärende. Därför en egen plats i foten, inte en kort-åtgärd. -->
				<button type="button" class="mina-arenden__lank hs-target" @click="onAnteckningar()">
					<TextBoxOutlineIcon :size="18" /> {{ t('hubs_start', 'Egna anteckningar') }}
				</button>
				<a class="mina-arenden__lank" :href="link('/apps/collectives/')">
					<BookOpenIcon :size="18" /> {{ t('hubs_start', 'Kunskapsbank & mallar') }}
				</a>
				<a class="mina-arenden__lank" :href="link('/apps/files/?dir=/Ärenderum')">
					<FolderLockIcon :size="18" /> {{ t('hubs_start', 'Senaste säkra filer') }}
				</a>
				<!-- #1 — förhandsvisa hela UI:t i demoläge (fixtures) utan att röra serverns
				     config. Default är demoläge AV på en skarp instans. -->
				<a class="mina-arenden__lank mina-arenden__demo" :href="demoLank">
					<FlaskOutlineIcon :size="18" /> {{ state.demoMode ? t('hubs_start', 'Lämna demoläge') : t('hubs_start', 'Visa i demoläge') }}
				</a>
			</footer>
		</template>

		<!-- Modaler -->
		<CommitGrind
			v-if="commitOpen"
			:arende="commitArende"
			:payload="commitPayload"
			@committed="onCommitted"
			@close="commitOpen = false" />
		<!-- #6 — signerings-grind: bekräfta signering → öppnar CommitGrind -->
		<SigneringsGrind
			v-if="signeringOpen"
			:arende="signeringArende"
			@nasta-steg="onSigneringNastaSteg"
			@close="signeringOpen = false" />
		<!-- #5 — avsluta-grind: terminalt steg → 'avslutat' (ren steg-övergång) -->
		<AvslutaGrind
			v-if="avslutaOpen"
			:arende="avslutaArende"
			:is-running="avslutaRunning"
			@avsluta="onAvslutaConfirmed"
			@close="avslutaOpen = false" />
		<!-- #12 — privata anteckningar (per-användare, aldrig delade) -->
		<MinaAnteckningar
			v-if="anteckningarOpen"
			:arende="anteckningarArende"
			@close="anteckningarOpen = false" />
		<MeetingWizard
			v-if="meetingWizardOpen"
			:start-now="false"
			@close="meetingWizardOpen = false"
			@booked="onMeetingBooked" />
		<CommandPalette
			v-if="commandPaletteOpen"
			@close="commandPaletteOpen = false"
			@new-message="openMeetingWizard"
			@book-meeting="openMeetingWizard"
			@start-meeting="openMeetingWizard" />

		<!-- Enhetschatt: lugn sidopanel, aldrig ett kort i ärendeströmmen -->
		<EnhetschattPanel
			v-if="A.enhetschattOppen"
			:team="A.team"
			:open="A.enhetschattOppen"
			@close="store.toggleEnhetschatt(false)"
			@oppna-tradar="onOppnaTradar"
			@starta-fordelningsmote="onStartaFordelningsmote" />

		<!-- Mottagar-/favoritväljare: öppnas vid Vidarebefordra (Kontakter-favoriter) -->
		<FavoritValjare
			v-if="favoritOpen"
			:favoriter="A.favoriter"
			:open="favoritOpen"
			:titel="t('hubs_start', 'Vidarebefordra till…')"
			@valj="onFavoritValj"
			@sok="onFavoritSok"
			@close="favoritOpen = false" />

		<!-- Fas F3: koppla-väljare — välj målärende för ett inflöde-meddelande -->
		<KopplaValjare
			v-if="kopplaRad"
			:rad="kopplaRad"
			:arenden="A.arenden"
			@valj="onKopplaValj"
			@close="kopplaRad = null" />
	</div>
</template>

<script>
import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import NcCounterBubble from '@nextcloud/vue/dist/Components/NcCounterBubble.js'
import CheckAllIcon from 'vue-material-design-icons/CheckAll.vue'
import BookOpenIcon from 'vue-material-design-icons/BookOpenVariant.vue'
import FolderLockIcon from 'vue-material-design-icons/FolderLock.vue'
import ForumIcon from 'vue-material-design-icons/Forum.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import AccountIcon from 'vue-material-design-icons/Account.vue'
import AccountSupervisorIcon from 'vue-material-design-icons/AccountSupervisor.vue'
import TextBoxOutlineIcon from 'vue-material-design-icons/TextBoxOutline.vue'
import FlaskOutlineIcon from 'vue-material-design-icons/FlaskOutline.vue'
import { showSuccess, showInfo, showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

import store from '../../store/index.js'
import deepLinks from '../../services/deepLinks.js'
import { NASTA_ATGARD, PROCESS_STEG } from '../../services/arendeFlow.js'
import { listPersonas } from '../../services/personas.js'
import PersonaSwitcher from '../PersonaSwitcher.vue'

import MinDagHeader from './MinDagHeader.vue'
import Dagspulsen from './Dagspulsen.vue'
import VadVillDuGora from './VadVillDuGora.vue'
import AttTaEmotSektion from './AttTaEmotSektion.vue'
import AttHanteraSektion from './AttHanteraSektion.vue'
import EjKoppladSektion from './EjKoppladSektion.vue'
import KopplaValjare from './KopplaValjare.vue'
import KorgValjare from './KorgValjare.vue'
import EnhetschattPanel from './EnhetschattPanel.vue'
import FordelningsVy from './FordelningsVy.vue'
import FavoritValjare from './FavoritValjare.vue'
import TreservaKvittens from './TreservaKvittens.vue'
import ArendeZon from './ArendeZon.vue'
import MotesRemsa from './MotesRemsa.vue'
import CommitGrind from './CommitGrind.vue'
import SigneringsGrind from './SigneringsGrind.vue'
import AvslutaGrind from './AvslutaGrind.vue'
import MinaAnteckningar from './MinaAnteckningar.vue'
import OnboardingTour from './OnboardingTour.vue'
import MeetingWizard from '../MeetingWizard.vue'
import CommandPalette from '../CommandPalette.vue'

export default {
	name: 'MinaArenden',

	components: {
		NcLoadingIcon, NcCounterBubble, CheckAllIcon, BookOpenIcon, FolderLockIcon, ForumIcon, ChevronRightIcon,
		AccountIcon, AccountSupervisorIcon, TextBoxOutlineIcon, FlaskOutlineIcon,
		MinDagHeader, Dagspulsen, VadVillDuGora, AttTaEmotSektion, AttHanteraSektion, EjKoppladSektion,
		KopplaValjare,
		KorgValjare, EnhetschattPanel, FordelningsVy, FavoritValjare, TreservaKvittens, ArendeZon,
		MotesRemsa, CommitGrind, SigneringsGrind, AvslutaGrind, MinaAnteckningar, OnboardingTour, MeetingWizard, CommandPalette,
		PersonaSwitcher,
	},

	data() {
		return {
			store,
			state: store.state,
			commandPaletteOpen: false,
			meetingWizardOpen: false,
			commitOpen: false,
			commitArende: null,
			commitPayload: null,
			// #6 signerings-grind + #5 avsluta-grind + #12 egna anteckningar (modaler).
			signeringOpen: false,
			signeringArende: null,
			avslutaOpen: false,
			avslutaArende: null,
			avslutaRunning: false,
			anteckningarOpen: false,
			anteckningarArende: null,
			attHanteraGruppering: 'arende',
			favoritOpen: false,
			favoritRad: null,
			// Fas F3 — inflöde-raden vars koppling väntar i KopplaValjare (null = stängd).
			kopplaRad: null,
		}
	},

	computed: {
		A() {
			return this.state.arende
		},
		/** #17 — "Mina möten idag" läser den LIVE mötesytan (GET /meetings/today via
		 * refreshMeetings), inte arende-summary.moten. Ett nybokat fristående möte
		 * landar i /meetings/today men inte i arende-summary, så den måste bindas hit. */
		meetings() {
			return this.state.meetings || []
		},
		prefs() {
			return this.state.prefs
		},
		personas() {
			return listPersonas()
		},

		/** Pure zonOf selector (mindag architecture). */
		hetaArenden() {
			const heta = this.A.arenden.filter((a) => this.zonOf(a) === 'het')
			// gap33 — Dagspulse 'frist' narrows the hot zone to burning frister.
			if (this.A.pulsFilter === 'frist') {
				return heta.filter((a) => a.frist && (a.frist.tone === 'error' || a.frist.tone === 'warning'))
			}
			return heta
		},
		aktivaArenden() {
			let aktiva = this.A.arenden.filter((a) => this.zonOf(a) !== 'het')
			if (this.A.stegFilter) {
				aktiva = aktiva.filter((a) => a.steg === this.A.stegFilter)
			}
			// gap33 — Dagspulse 'signera' narrows to ärenden waiting for signature (beslut-steget).
			if (this.A.pulsFilter === 'signera') {
				return aktiva.filter((a) => a.steg === 'beslut')
			}
			return aktiva
		},
		triageFiltered() {
			// Dagspulse 'inflode' filter narrows to triage; other filters leave it.
			return this.A.triage
		},

		/** Korg ∩ typ-filtrerat inflöde (delas av de tre banden 1a/1b/1c). */
		filteredInflode() {
			const { aktivKorg, aktivTyp } = this.A
			return this.A.inflode.filter((r) =>
				(!aktivKorg || (r.korg && r.korg.addr === aktivKorg))
				&& (!aktivTyp || r.messageType === aktivTyp))
		},
		/** 1a — nytt ärende (orosanmälningar): triage-känslan, oförändrad. */
		taEmotItems() {
			const items = this.filteredInflode.filter((r) => this.zonOf(r) === 'attTaEmot')
			// gap33 — när en ärende-orienterad dagspuls-filter är aktiv (frist/signera/mote/omnamnanden)
			// är nytt-inflödet inte relevant; visa det bara utan filter eller på 'inflode'-filtret.
			if (this.A.pulsFilter && this.A.pulsFilter !== 'inflode') {
				return []
			}
			return items
		},
		/** 1b — hör till ett av mina pågående ärenden: ärendearbete, inte triage. */
		attHanteraItems() {
			return this.filteredInflode.filter((r) => this.zonOf(r) === 'attHantera')
		},
		/** 1c — ej ärendekopplat: hinken som inget får försvinna ur utan stöd. */
		ejKoppladItems() {
			return this.filteredInflode.filter((r) => this.zonOf(r) === 'ejKopplad')
		},

		enhetschattOmnamnanden() {
			return (this.A.team || []).reduce((s, tt) => s + (tt.omnamnanden || 0), 0)
		},
		enhetschattOlasta() {
			return (this.A.team || []).reduce((s, tt) => s + (tt.olasta || 0), 0)
		},
		/** #1 — länk som slår PÅ/AV demoläge (?demo=1/0); full omladdning så isDemo()
		 * läser om flaggan. Default på skarp instans = AV. */
		demoLank() {
			return this.link('/apps/hubs_start/') + (this.state.demoMode ? '?demo=0' : '?demo=1')
		},
	},

	created() {
		// Boot + polling are owned by the parent Start view; we just load ärende data.
		store.loadArendeSummary()
	},

	mounted() {
		document.addEventListener('keydown', this.onGlobalKeydown)
	},

	beforeDestroy() {
		document.removeEventListener('keydown', this.onGlobalKeydown)
	},

	methods: {
		t,

		link(path) {
			return generateUrl(path)
		},

		/** zonOf — pure selector. Inflöde routas på ärendekoppling; ärendekort på het/aktiv. */
		zonOf(a) {
			// Inflöde-rader (multi-korg): ärendekopplingen avgör vilket band.
			if (a.kind === 'inflode') {
				if (a.arendekoppling === 'hor_till') {
					return 'attHantera'
				}
				if (a.arendekoppling === 'ej_kopplat') {
					return 'ejKopplad'
				}
				return 'attTaEmot'
			}
			// Ärendekort-logik (oförändrad).
			if (a.steg === 'inflode') {
				return 'taEmot'
			}
			if (a.frist && (a.frist.tone === 'error' || a.frist.tone === 'warning')) {
				return 'het'
			}
			if (a.plikt && !a.plikt.kvitterad) {
				return 'het'
			}
			if (a.nastaAtgard && a.nastaAtgard.vantarPaMig) {
				return 'het'
			}
			// Omnämnande till mig i ärendechatten lyfter kortet till "Kräver åtgärd nu".
			if (a.diskussion && a.diskussion.omnamnandeTillMig) {
				return 'het'
			}
			return 'aktiv'
		},

		onGlobalKeydown(e) {
			if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
				e.preventDefault()
				this.commandPaletteOpen = true
			}
		},

		// --- Nästa åtgärd (state machine) -----------------------------------
		onNastaAtgard(arende) {
			const target = (arende.vantar && NASTA_ATGARD.overrides[arende.vantar])
				|| NASTA_ATGARD.steg[arende.steg]
			if (!target) {
				return
			}
			switch (target.action) {
			case 'signera':
				return this.onSignera(arende)
			case 'commit-utredning':
			case 'beslut-inleda':
			case 'skyddsbedomning':
			case 'godkann-anteckning':
				return this.onCommit(arende, { typ: target.action, arende })
			case 'bevakning':
				return this.onBevakning(arende)
			case 'avsluta':
				return this.onAvsluta(arende)
			case 'open-rum':
				return this.onOpenRum(arende)
			default:
				return this.onExpand(arende, target.flik)
			}
		},

		// --- Triage (1a) ----------------------------------------------------
		onTriage(rad, mode) {
			if (mode === 'start') {
				// Orchestrate (blockers solved): skapa register-id (hubsCaseId) → ärenderum
				// + ACL + Deck-kort + klocka, parat mot Treserva-dnr via Frends. Demo: ta bort raden.
				this.skapaArendeFromRad(rad)
			} else if (mode === 'koppla') {
				// Fas F3 — öppna koppla-väljaren så handläggaren VÄLJER målärende
				// (människo-bekräftat; felkoppling = sekretessincident).
				this.kopplaRad = rad
			} else {
				showInfo(this.t('hubs_start', 'Välj befintligt ärende att koppla meddelandet till.'))
			}
		},
		onOpenTriage(rad) {
			// #2 — "Visa" öppnar det faktiska meddelandet i mail-klienten via radens
			// deepLink ({app:'thread', params:{itslMailboxId, mid}}) → sdkmc mailbox-link-
			// redirect som alltid landar i rätt konto/tråd. Fallback: info-toast.
			if (rad && rad.deepLink) {
				window.location.href = deepLinks.resolve(rad.deepLink)
				return
			}
			showInfo(this.t('hubs_start', 'Kunde inte öppna meddelandet — saknar länk.'))
		},
		/**
		 * Skapa ett ärende ur en inflöde-rad och VÄNTA på utfallet innan kvittot
		 * visas. Tidigare visades success-toasten ovillkorligt (fire-and-forget),
		 * så ett 400/403 från motorn (t.ex. okänd ärendetyp) såg ut som en lyckad
		 * registrering trots att inget ärende skapades. Nu: success bara på ett
		 * verkligt ärende, annars ett ärligt felmeddelande med motorns orsak.
		 */
		async skapaArendeFromRad(rad) {
			try {
				const r = await store.inflodeAction('skapa', { id: rad.id, rad })
				if (r && r.ok && r.arende) {
					showSuccess(this.t('hubs_start', 'Ärende startat — ärenderum skapat, 14-dagarsklockan tickar.'))
				} else {
					showError(this.t('hubs_start', 'Kunde inte starta ärendet: {orsak}', { orsak: (r && r.error) || this.t('hubs_start', 'okänt fel') }))
				}
			} catch (e) {
				showError(this.t('hubs_start', 'Kunde inte starta ärendet. Kontrollera ärendetyp och försök igen.'))
			}
		},

		// --- Multi-korg inflöde (1b/1c) -------------------------------------
		/** Generisk inflöde-/ej-kopplat-åtgärd → store-orkestrering (demo: optimistisk). */
		async onInflode(action, rad) {
			const r = rad && (rad.rad || rad)
			// Fas F3 — "koppla" går ALDRIG direkt: öppna väljaren så handläggaren VÄLJER
			// målärende (felkoppling = sekretessincident → människo-bekräftelse krävs).
			if (action === 'koppla') {
				this.kopplaRad = r
				return
			}
			const msg = {
				'spara-i-rum': this.t('hubs_start', 'Sparat i ärenderummet — speglat till ärendet.'),
				'skapa-bevakning': this.t('hubs_start', 'Bevakning skapad.'),
				besvara: this.t('hubs_start', 'Besvarat — hålls ordnat.'),
				vidarebefordra: this.t('hubs_start', 'Vidarebefordrat — säker överlämning loggad.'),
				gallra: this.t('hubs_start', 'Gallrat med dokumenterat stöd — loggat.'),
				registrera: this.t('hubs_start', 'Registrerat utan ärende — fört till diarium.'),
			}
			// Invänta utfallet: success-toast bara när åtgärden faktiskt gick igenom.
			// Ett uttryckligt fel (ok:false/error/avvisad) eller kastat fel ger ett
			// ärligt felmeddelande i stället för en falsk "klart"-bekräftelse.
			try {
				const res = await store.inflodeAction(action, { id: r && r.id, rad: r })
				if (res && (res.ok === false || res.error || res.avvisad)) {
					showError(this.t('hubs_start', 'Åtgärden kunde inte slutföras: {orsak}', { orsak: res.error || res.reason || this.t('hubs_start', 'okänt fel') }))
				} else if (msg[action]) {
					showSuccess(msg[action])
				}
			} catch (e) {
				showError(this.t('hubs_start', 'Åtgärden kunde inte slutföras. Försök igen.'))
			}
		},

		/**
		 * Fas F3 — handläggaren valde målärende i KopplaValjare. Kör den durabla,
		 * IDOR-säkra Väg A-kopplingen (synliga taggar i mail-klienten + referens-fil i
		 * ärendemappen). Visar "kopplat" först på verifierad=true, annars att taggen
		 * ännu inte bekräftats (ingen falsk koppling).
		 */
		async onKopplaValj(hubsCaseId) {
			const rad = this.kopplaRad
			this.kopplaRad = null
			if (!rad || !hubsCaseId) {
				return
			}
			try {
				const kvitto = await store.kopplaMeddelandeTillArende({ messageId: rad.id, hubsCaseId, radId: rad.id })
				if (kvitto && kvitto.verifierad) {
					showSuccess(this.t('hubs_start', 'Kopplat till ärendet — taggat och sparat i ärendemappen.'))
				} else {
					showInfo(this.t('hubs_start', 'Koppling registrerad — referens sparad i ärendemappen. Taggen bekräftas när meddelandet är tillgängligt.'))
				}
			} catch (e) {
				showError(this.t('hubs_start', 'Kunde inte koppla meddelandet. Försök igen.'))
			}
		},
		onOpenKoppling(payload) {
			const ref = payload && (payload.dnr || payload.barnRef || (payload.koppling && payload.koppling.dnr) || (payload.rad && payload.rad.koppling && payload.rad.koppling.dnr))
			showInfo(this.t('hubs_start', 'Öppnar kopplat ärende: {ref}', { ref: ref || '—' }))
		},

		// --- Vidarebefordra via Kontakter-favorit (mottagar-väljaren) -------
		onVidarebefordra(rad) {
			this.favoritRad = rad && (rad.rad || rad)
			this.favoritOpen = true
		},
		async onFavoritValj(favorit) {
			const rad = this.favoritRad
			this.favoritOpen = false
			this.favoritRad = null
			try {
				const res = await store.inflodeAction('vidarebefordra', { id: rad && rad.id, rad, mottagare: favorit })
				if (res && (res.ok === false || res.error || res.avvisad)) {
					showError(this.t('hubs_start', 'Kunde inte vidarebefordra: {orsak}', { orsak: res.error || res.reason || this.t('hubs_start', 'okänt fel') }))
				} else {
					showSuccess(this.t('hubs_start', 'Vidarebefordrat till {namn} — säker överlämning loggad.', { namn: favorit && favorit.namn }))
				}
			} catch (e) {
				showError(this.t('hubs_start', 'Kunde inte vidarebefordra meddelandet. Försök igen.'))
			}
		},
		onFavoritSok() {
			this.favoritOpen = false
			window.location.href = generateUrl('/apps/contacts/')
		},

		// --- Fördelningsläge (gruppledare) ----------------------------------
		async onFordela({ ref, utredareUid }) {
			try {
				const res = await store.inflodeAction('tilldela', { ref, utredareUid })
				if (res && (res.ok === false || res.error || res.avvisad)) {
					showError(this.t('hubs_start', 'Kunde inte fördela: {orsak}', { orsak: res.error || res.reason || this.t('hubs_start', 'okänt fel') }))
				} else {
					showSuccess(this.t('hubs_start', 'Fördelat till {uid} — skrivåtkomst och fristpåminnelser flyttade.', { uid: utredareUid }))
				}
			} catch (e) {
				showError(this.t('hubs_start', 'Kunde inte fördela ärendet. Försök igen.'))
			}
		},
		async onOmfordela({ ref, utredareUid }) {
			try {
				const res = await store.inflodeAction('omfordela', { ref, utredareUid })
				if (res && (res.ok === false || res.error || res.avvisad)) {
					showError(this.t('hubs_start', 'Kunde inte omfördela: {orsak}', { orsak: res.error || res.reason || this.t('hubs_start', 'okänt fel') }))
				} else {
					showSuccess(this.t('hubs_start', 'Omfördelat till {uid}.', { uid: utredareUid }))
				}
			} catch (e) {
				showError(this.t('hubs_start', 'Kunde inte omfördela ärendet. Försök igen.'))
			}
		},

		// --- Enhetschatt (sidopanel) ----------------------------------------
		onOppnaTradar(team) {
			// #18 — öppna enhetens diskussionsrum via dess token (ur team-rowen). null
			// på t.ex. dev15 där inget grupp-rum ännu finns → ärlig info-fallback.
			const url = deepLinks.spreedRoomLink(team && team.token)
			if (url) {
				window.location.href = url
				return
			}
			showInfo(this.t('hubs_start', 'Enhetschatten saknar rum ännu: {label}', { label: (team && team.label) || '' }))
		},
		onStartaFordelningsmote() {
			store.toggleEnhetschatt(false)
			this.openMeetingWizard()
		},

		// --- Ärendekort-åtgärder (riktiga — blockers lösta) -----------------
		onOpenRum(arende) {
			// gap20/24 — öppna det specifika ärenderummet (groupfolder mount_point = hubsCaseId),
			// inte den statiska /Ärenderum-roten.
			window.location.href = deepLinks.arenderumLink(arende && arende.hubsCaseId)
		},
		onSkicka(arende) {
			// #9 — öppna säker compose med ärende-kontext (dnr/hubsCaseId) så det sända
			// meddelandet pre-taggas case: på ärendet direkt (kräver mail-routerhook honorerar
			// &case=). Typ = secure_email (säker kanal till medborgare).
			const ref = arende && (arende.dnr || arende.hubsCaseId)
			window.location.href = deepLinks.composerLink('secure_email', null, ref)
		},
		onSignera(arende) {
			// #6 — öppna signerings-grinden (bekräfta-kryssruta) i stället för en abrupt
			// full-sides-redirect till underskriftstjänsten. Bekräftelsen leder vidare
			// till CommitGrind (typ 'signerat-beslut'). Grinden avancerar aldrig steg själv.
			this.signeringArende = arende
			this.signeringOpen = true
		},
		/** #6 — handläggaren bekräftade signering → öppna CommitGrind för överföringen. */
		onSigneringNastaSteg(arende) {
			this.signeringOpen = false
			this.onCommit(arende, { typ: 'signerat-beslut', arende })
		},
		/** #12 — öppna privata anteckningar (per-användare, ej ärende-bundna). */
		onAnteckningar(arende) {
			this.anteckningarArende = arende || null
			this.anteckningarOpen = true
		},
		/** #5 — terminalt steg: öppna avsluta-grinden (bekräftelse). */
		onAvsluta(arende) {
			this.avslutaArende = arende
			this.avslutaRunning = false
			this.avslutaOpen = true
		},
		/**
		 * #5 — bekräftat avslut: en REN steg-övergång till 'avslutat' (ingen ny
		 * Treserva-commit — akten är redan registrerad; avslut ≠ registrering). På ok
		 * patchar store det lokala steget och kortet faller till terminal-läget.
		 */
		async onAvslutaConfirmed(arende) {
			const ref = arende && (arende.hubsCaseId || arende.dnr || arende.triageRef)
			if (!ref) {
				this.avslutaOpen = false
				return
			}
			this.avslutaRunning = true
			try {
				const r = await store.transitionSteg(ref, 'avslutat')
				if (r && r.ok !== false) {
					showSuccess(this.t('hubs_start', 'Ärendet avslutat — gallringen av Hubs-rummet har startat.'))
				} else {
					showError(this.t('hubs_start', 'Kunde inte avsluta ärendet: {orsak}', { orsak: (r && (r.error || r.reason)) || this.t('hubs_start', 'okänt fel') }))
				}
			} catch (e) {
				showError(this.t('hubs_start', 'Kunde inte avsluta ärendet. Försök igen.'))
			} finally {
				this.avslutaRunning = false
				this.avslutaOpen = false
			}
		},
		onBevakning(arende) {
			// #7/11 — öppna ärendets/enhetens bevaknings-board (bevakningBoardId ur
			// arende-summary när det finns), annars Deck-board-listan (404-säker fallback
			// i st.f. hårdkodad board som kanske inte finns i denna kommun).
			window.location.href = deepLinks.deckLink(arende && arende.bevakningBoardId)
		},
		onExpand(arende, flik) {
			// Stabil cache-nyckel: triageRef (alltid satt) — inte den ibland-null:a dnr.
			store.loadArende(arende.triageRef || arende.dnr)
			// expansion handled inside ArendeKort via its own state; flik hint optional
		},

		// --- Commit (Frends → Treserva) -------------------------------------
		onCommit(arende, payload) {
			this.commitArende = arende
			// #5 — bär med ärenderummets dokumentlista (om ArendeKort skickade den) så
			// CommitGrind kan visa den granskbara urvalslistan (alla förvalda).
			const bas = payload || { typ: 'beslut', arende }
			this.commitPayload = { ...bas, arende, dokument: (payload && payload.dokument) || [] }
			this.commitOpen = true
		},
		async onCommitted(result, valdaDokument) {
			this.commitOpen = false
			const arende = this.commitArende
			// "Hela vägen": commit to the facksystem (Treserva via Frends). Only show
			// the registered-kvittens on a VERIFIED receipt (r.ok && r.verifierad); a
			// backend failure previously either threw silently (no feedback) or — on a
			// non-throwing ok:false — showed a false "registrerat" toast. Now both
			// surface honestly. (gap12)
			try {
				// #5 — tråda CommitGrindens dokumenturval till det (idempotenta) andra
				// store-committet så urvalet är identiskt i båda anropen.
				const r = await store.commitArende({ ...this.commitPayload, arende, valdaDokument: valdaDokument || (this.commitPayload && this.commitPayload.valdaDokument) })
				if (r && r.ok && r.verifierad) {
					showSuccess(this.t('hubs_start', 'Fört till Treserva — registrerat i akten.'))
					// gap1 — efter en VERIFIERAD commit: advancera ärendet ett steg i grafen
					// (forhandsbedomning→utredning→beslut→uppfoljning→avslutat).
					const next = this.nextSteg(arende && arende.steg)
					if (arende && next) {
						const ref = arende.hubsCaseId || arende.dnr
						if (ref) {
							try {
								// ORO-1: en VERIFIERAD commit av skyddsbedömningen ÄR kvitteringen
								// av plikt-grinden — skicka kvittensen så förhandsbedömning→utredning
								// släpps förbi fas-spärren för pliktGrind-typer (orosanmälan).
								await store.transitionSteg(ref, next, true)
							} catch (e) { /* steg-advance är best-effort; commit är redan verifierad */ }
						}
					}
				} else {
					showError(this.t('hubs_start', 'Treserva bekräftade inte registreringen — inget kvitto skapades. Försök igen.'))
				}
			} catch (e) {
				showError(this.t('hubs_start', 'Kunde inte föra till Treserva. Försök igen.'))
			}
		},

		/**
		 * gap1 — nästa processteg enligt livscykel-grafen
		 * (forhandsbedomning→utredning→beslut→uppfoljning→avslutat). Returnerar null
		 * om steget saknas eller redan är i slutläget (avslutat) → ingen transition.
		 */
		nextSteg(steg) {
			if (!steg || steg === 'avslutat') {
				return null
			}
			const i = PROCESS_STEG.findIndex((s) => s.id === steg)
			if (i < 0 || i >= PROCESS_STEG.length - 1) {
				return null
			}
			return PROCESS_STEG[i + 1].id
		},

		// --- Möten ----------------------------------------------------------
		openMeetingWizard() {
			this.commandPaletteOpen = false
			this.meetingWizardOpen = true
		},
		async onMeetingBooked() {
			this.meetingWizardOpen = false
			showSuccess(this.t('hubs_start', 'Säkert möte bokat'))
			// #17 — det nybokade mötet ligger i /meetings/today, så ladda om den LIVE
			// mötesytan (refreshMeetings → state.meetings) så MotesRemsa visar det direkt.
			// Ladda även om arende-summary (ärende-bundna möten / puls) som tidigare.
			try {
				await Promise.all([store.refreshMeetings(), store.loadArendeSummary()])
			} catch (e) { /* non-fatal; mötet är redan bokat */ }
		},
		onJoin(meeting) {
			window.location.href = deepLinks.callLink(meeting.token)
		},
		onGodkann(arende, anteckning) {
			this.onCommit(arende, { typ: 'motesanteckning', arende, artefakter: { anteckning } })
		},

		// --- Verbingång / header --------------------------------------------
		onVerb(id) {
			switch (id) {
			case 'mote':
				return this.openMeetingWizard()
			case 'signera':
				return store.setStegFilter('beslut')
			case 'utredning':
				return store.setStegFilter('utredning')
			case 'foljUpp':
				return store.setStegFilter('uppfoljning')
			case 'taEmot':
				return store.setPulsFilter('inflode')
			default:
			}
		},
		onDismissVerb() {
			this.state.prefs.verbEntryDismissed = true
			store.savePref && store.savePref('verbEntryDismissed', true)
		},
		onUpgradeLoa() {
			window.location.href = deepLinks.loa3UpgradeLink()
		},
		onOpenHelp() {
			window.location.href = generateUrl('/apps/collectives/')
		},
		onTourFinish() {
			store.markOnboardingSeen()
		},
	},
}
</script>

<style scoped lang="scss">
.mina-arenden {
	max-width: 1080px;
	margin: 0 auto;
	padding: 0 24px 64px;
	display: flex;
	flex-direction: column;
	gap: 16px;

	&__loading {
		margin: 80px auto;
	}

	&__personbar {
		display: flex;
		flex-wrap: wrap;
		justify-content: space-between;
		align-items: center;
		gap: 8px;
		padding: 8px 0 0;
	}

	&__lagevaxel {
		display: inline-flex;
		gap: 4px;
		padding: 3px;
		border-radius: var(--border-radius-large, 12px);
		background: var(--color-background-dark);
	}
	&__lageknapp {
		display: inline-flex;
		align-items: center;
		gap: 5px;
		padding: 5px 12px;
		border: none;
		border-radius: var(--border-radius, 8px);
		background: transparent;
		color: var(--color-text-maxcontrast);
		font-size: 0.85rem;
		font-weight: 600;
		cursor: pointer;
		&:hover { background: var(--color-background-hover); }
		&--aktiv { background: var(--color-main-background); color: var(--color-main-text); box-shadow: 0 1px 3px var(--color-box-shadow, rgba(0, 0, 0, 0.1)); }
	}

	&__chatt {
		border: none;
		background: transparent;
		cursor: pointer;
		font: inherit;
	}
	&__chatt-badge { background: var(--color-primary-element); color: var(--color-primary-element-text); }
	&__chatt-olasta {
		font-size: 0.72rem;
		padding: 0 6px;
		border-radius: var(--border-radius-pill, 16px);
		background: var(--color-background-dark);
		color: var(--color-text-maxcontrast);
	}

	&__foten {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 20px;
		font-size: 0.9rem;
	}

	&__klart {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		font-weight: 600;
	}

	&__lank {
		display: inline-flex;
		align-items: center;
		gap: 6px;
		color: var(--color-text-maxcontrast);
		text-decoration: none;

		&:hover { color: var(--color-main-text); text-decoration: underline; }
	}
}
</style>
