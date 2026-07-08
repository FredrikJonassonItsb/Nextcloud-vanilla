<template>
	<div class="mina-arenden">
		<OnboardingTour
			v-if="!prefs.onboardingSeen"
			:seen="prefs.onboardingSeen"
			@finish="onTourFinish" />

		<!-- Persona-växlare (demo) + roll-läge (rollstyrt: fördelare/förvaltare — inte demo-gated) -->
		<div v-if="state.demoMode || arFordelare" class="mina-arenden__personbar">
			<!-- Roll-läge: handläggare vs gruppledare. Visas för fördelarrollen
			     (profil 'forvaltare') även i skarpt läge — tidigare demo-gated,
			     vilket gjorde fördelningsvyn onåbar i drift. -->
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
				v-if="state.demoMode"
				:personas="personas"
				:active="state.activePersona"
				@change="store.setPersona" />
		</div>

		<!-- Fel-banner: backend-fel får ALDRIG sväljas tyst (audit 2026-07-07) —
		     en tom vy utan förklaring ser ut som "inget att göra". -->
		<div v-if="state.error" class="mina-arenden__fel" role="alert">
			<AlertOctagonIcon :size="18" />
			<span>{{ t('hubs_start', 'Allt innehåll kunde inte hämtas — vyn kan vara ofullständig.') }}</span>
			<NcButton type="secondary" @click="onForsokIgen">
				{{ t('hubs_start', 'Försök igen') }}
			</NcButton>
		</div>

		<!-- Zon 0: sidhuvud + Dagspulsen (sticky). Pulsen är HÄRLEDD ur riktig
		     laddad data (möten/beslut/inflöde/omnämnanden) — aldrig döda nollor. -->
		<MinDagHeader
			:loa="state.loa"
			:profile="state.profile"
			:puls="pulsBeriknad"
			:aktivt-filter="A.pulsFilter"
			@filter="onPulsFilter"
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
				:pending-ids="taEmotPending"
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
				@skapa-bevakning="onSkapaBevakningInflode"
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
				@registrera="onInflode('registrera', $event)"
				@avvisa-forslag="onAvvisaForslag" />

			<!-- Zon 2: KRÄVER ÅTGÄRD NU — varsel-LISTA som länkar NER till kortet.
			     Ett ärende = ett kort = en arbetsyta; kortet flyttas aldrig hit. -->
			<VarselLista
				:varsel="varsel"
				@ga-till="gaTillArende" />

			<!-- Zon 3: MINA ÄRENDEN — ALLA ärenden jag är ansluten till
			     (medlemsbaserad summary, frist-sorterad: närmast brinner överst). -->
			<ArendeZon
				:arenden="aktivaArenden"
				:pinned="false"
				:title="t('hubs_start', 'Mina ärenden')"
				:filter-steg="A.stegFilter"
				:keyboard-mode="prefs.keyboardMode"
				:markerad-ref="markeradRef"
				:varslade-refs="varsladeRefs"
				@filter-steg="store.setStegFilter"
				@nasta-atgard="onNastaAtgard"
				@open-rum="onOpenRum"
				@open-team="onOpenTeam"
				@ny-chatt="onNyChatt"
				@skapa-handling="onSkapaHandling"
				@omfordela-kollega="onOmfordelaKollega"
				@skicka="onSkicka"
				@boka-mote="openMeetingWizard"
				@signera="onSignera"
				@commit="onCommit"
				@bevakning="onBevakning"
				@godkann="onGodkann"
				@expand="onExpand" />

			<!-- Zon 4: mina möten idag — TOM panel kollapsas till en rad -->
			<button
				v-if="!meetings.length && !motenVisas"
				class="mina-arenden__kollapsad hs-card hs-target"
				type="button"
				:aria-expanded="'false'"
				@click="motenVisas = true">
				<ChevronRightIcon :size="16" /> {{ t('hubs_start', 'Mina möten idag') }}
				<span class="mina-arenden__kollapsad-antal">0</span>
			</button>
			<MotesRemsa
				v-else
				:meetings="meetings"
				@join="onJoin" />

			<!-- Kvittenser & gallring BORTTAGEN ur Min dag (Fredrik 2026-07-07):
			     registrerings-/gallringskvitton är fördelnings-/uppföljningsdata,
			     inte handläggarens dagsflöde — de bor i Fördelningsvyn. -->

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
				<!-- Egna anteckningar + Senaste säkra filer BORTTAGNA ur foten
				     (Fredrik 2026-07-07): hör inte hemma i Min dag. -->
				<a class="mina-arenden__lank" :href="link('/apps/collectives/')">
					<BookOpenIcon :size="18" /> {{ t('hubs_start', 'Kunskapsbank & mallar') }}
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
		<!-- #5 — avsluta-grind: terminalt steg → 'avslutat' (ren steg-övergång).
		     A9a/A9c: grinden samlar inteInledaVal/avslutsmotiv och emittar dem. -->
		<AvslutaGrind
			v-if="avslutaOpen"
			:arende="avslutaArende"
			:is-running="avslutaRunning"
			:bevakningar="avslutaBevakningar"
			@avsluta="onAvslutaConfirmed"
			@close="avslutaOpen = false" />

		<!-- A7 — skyddsbedömnings-override-grind (inline). Öppnas när motorn spärrar
		     forhandsbedomning→utredning för att skyddsbedömningen saknas som artefakt.
		     Handläggaren anger ett dokumenterat skäl (enum) → kontext.override.skal.
		     3 klick, ingen uppsats; skälet journalförs PII-fritt av motorn. -->
		<NcModal
			v-if="overrideOpen"
			:show="true"
			:name="t('hubs_start', 'Skyddsbedömning saknas')"
			size="small"
			:can-close="!overrideRunning"
			@close="overrideOpen = false">
			<div class="mina-arenden__override">
				<p class="mina-arenden__override-lead">
					<ShieldAlertIcon :size="18" />
					<span>{{ t('hubs_start', 'Ingen omedelbar skyddsbedömning är dokumenterad i Hubs. Utredning får bara inledas när skyddsbedömningen finns. Ange varför den kan anses gjord — skälet journalförs.') }}</span>
				</p>
				<fieldset class="mina-arenden__override-val">
					<legend class="mina-arenden__override-legend">{{ t('hubs_start', 'Skäl') }}</legend>
					<NcCheckboxRadioSwitch
						:checked.sync="overrideSkal"
						value="gjord_i_facksystem"
						name="override-skal"
						type="radio"
						:disabled="overrideRunning">
						{{ t('hubs_start', 'Skyddsbedömningen är gjord och dokumenterad i facksystemet') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						:checked.sync="overrideSkal"
						value="gjord_utanfor_hubs"
						name="override-skal"
						type="radio"
						:disabled="overrideRunning">
						{{ t('hubs_start', 'Skyddsbedömningen är gjord utanför Hubs (t.ex. på papper/annat system)') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch
						:checked.sync="overrideSkal"
						value="bradskande"
						name="override-skal"
						type="radio"
						:disabled="overrideRunning">
						{{ t('hubs_start', 'Brådskande — skyddsbedömning görs omgående och dokumenteras') }}
					</NcCheckboxRadioSwitch>
				</fieldset>
				<div class="mina-arenden__override-foot">
					<NcButton :disabled="overrideRunning" @click="overrideOpen = false">
						{{ t('hubs_start', 'Avbryt') }}
					</NcButton>
					<NcButton type="primary" :disabled="overrideRunning || !overrideSkal" @click="onOverrideConfirmed">
						<template #icon>
							<NcLoadingIcon v-if="overrideRunning" :size="18" />
							<ArrowRightIcon v-else :size="18" />
						</template>
						{{ t('hubs_start', 'Dokumentera & gå vidare') }}
					</NcButton>
				</div>
			</div>
		</NcModal>
		<!-- Ny chatt i ärenderummet (1:n — motorn kopplar team + åtkomstlista) -->
		<NyChattModal
			v-if="nyChattOpen"
			:arende="nyChattArende"
			:is-running="nyChattRunning"
			@skapa="onNyChattSkapa"
			@close="nyChattOpen = false" />
		<!-- Skapa handling från mall: mallbibliotek + förifyllda fält (skyddsgrindade) -->
		<HandlingModal
			v-if="handlingOpen"
			:arende="handlingArende"
			:is-running="handlingRunning"
			@skapa="onHandlingSkapa"
			@close="handlingOpen = false" />
		<!-- Omfördela ett ägt ärende till en namngiven kollega (menyval på kortet) -->
		<OmfordelaModal
			v-if="omfordelaArende"
			:arende="omfordelaArende"
			:is-running="omfordelaRunning"
			@omfordela="onOmfordelaSkapa"
			@close="omfordelaArende = null" />
		<MeetingWizard
			v-if="meetingWizardOpen"
			:start-now="false"
			:arende="wizardArende"
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
import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'
import NcCheckboxRadioSwitch from '@nextcloud/vue/dist/Components/NcCheckboxRadioSwitch.js'
import CheckAllIcon from 'vue-material-design-icons/CheckAll.vue'
import BookOpenIcon from 'vue-material-design-icons/BookOpenVariant.vue'
import ForumIcon from 'vue-material-design-icons/Forum.vue'
import ChevronRightIcon from 'vue-material-design-icons/ChevronRight.vue'
import AccountIcon from 'vue-material-design-icons/Account.vue'
import AccountSupervisorIcon from 'vue-material-design-icons/AccountSupervisor.vue'
import FlaskOutlineIcon from 'vue-material-design-icons/FlaskOutline.vue'
import ShieldAlertIcon from 'vue-material-design-icons/ShieldAlert.vue'
import ArrowRightIcon from 'vue-material-design-icons/ArrowRight.vue'
import { showSuccess, showInfo, showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

import store from '../../store/index.js'
import deepLinks from '../../services/deepLinks.js'
import { skapaArendeChatt, skapaHandling, tilldela, grindKravFel } from '../../services/api.js'
import { NASTA_ATGARD, PROCESS_STEG } from '../../services/arendeFlow.js'
import { typLabel } from '../../services/messageTypes.js'

/** Processtegets svenska etikett (aldrig rå steg-token i UI). */
function stegEtikett(steg) {
	const traff = PROCESS_STEG.find((s) => s.id === steg)
	return traff ? traff.label : ''
}
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
import ArendeZon from './ArendeZon.vue'
import MotesRemsa from './MotesRemsa.vue'
import VarselLista from './VarselLista.vue'
import CommitGrind from './CommitGrind.vue'
import AvslutaGrind from './AvslutaGrind.vue'
import NyChattModal from './NyChattModal.vue'
import HandlingModal from './HandlingModal.vue'
import OmfordelaModal from './OmfordelaModal.vue'
import OnboardingTour from './OnboardingTour.vue'
import MeetingWizard from '../MeetingWizard.vue'
import CommandPalette from '../CommandPalette.vue'

export default {
	name: 'MinaArenden',

	components: {
		NcLoadingIcon, NcCounterBubble, NcModal, NcButton, NcCheckboxRadioSwitch,
		CheckAllIcon, BookOpenIcon, ForumIcon, ChevronRightIcon,
		AccountIcon, AccountSupervisorIcon, FlaskOutlineIcon, ShieldAlertIcon, ArrowRightIcon,
		MinDagHeader, Dagspulsen, VadVillDuGora, AttTaEmotSektion, AttHanteraSektion, EjKoppladSektion,
		KopplaValjare,
		KorgValjare, EnhetschattPanel, FordelningsVy, FavoritValjare, ArendeZon,
		MotesRemsa, VarselLista, CommitGrind, AvslutaGrind, NyChattModal, HandlingModal, OmfordelaModal, OnboardingTour, MeetingWizard, CommandPalette,
		PersonaSwitcher,
	},

	data() {
		return {
			store,
			state: store.state,
			commandPaletteOpen: false,
			meetingWizardOpen: false,
			wizardArende: null,
			markeradRef: null,
			motenVisas: false,
			taEmotPending: [],
			omfordelaArende: null,
			omfordelaRunning: false,
			commitOpen: false,
			commitArende: null,
			commitPayload: null,
			// #5 avsluta-grind + #12 egna anteckningar (modaler). #6-signering är nu
			// inbäddad i CommitGrind (ingen egen modal → ingen modal-stapling).
			avslutaOpen: false,
			avslutaArende: null,
			avslutaRunning: false,
			nyChattOpen: false,
			nyChattArende: null,
			nyChattRunning: false,
			// Handling-från-mall (mallbibliotek + förifyllda fält).
			handlingOpen: false,
			handlingArende: null,
			handlingRunning: false,
			attHanteraGruppering: 'arende',
			favoritOpen: false,
			favoritRad: null,
			// Fas F3 — inflöde-raden vars koppling väntar i KopplaValjare (null = stängd).
			kopplaRad: null,
			// A7 — skyddsbedömnings-override-grind (inline modal). Öppnas när motorn
			// 400:ar forhandsbedomning→utredning för att skyddsbedömningen saknas som
			// artefakt/kvittens. Handläggaren anger ett dokumenterat skäl (enum) som
			// skickas som kontext.override {skal} vid omförsöket.
			overrideOpen: false,
			overrideArende: null,
			overrideNyttSteg: null,
			overrideSkal: 'gjord_i_facksystem',
			overrideRunning: false,
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
		/** Heta ärenden — numera underlag för VARSEL-listan (korten flyttas ALDRIG
		 * ut ur Mina ärenden; ett ärende = ett kort = en arbetsyta). */
		hetaArenden() {
			const heta = this.A.arenden.filter((a) => this.zonOf(a) === 'het')
			// gap33 — Dagspulse 'frist' narrows the hot zone to burning frister.
			if (this.A.pulsFilter === 'frist') {
				return heta.filter((a) => a.frist && (a.frist.tone === 'error' || a.frist.tone === 'warning'))
			}
			return heta
		},
		/** Varsel-raderna: VAD som ska göras + länk ner till kortet. */
		varsel() {
			return this.hetaArenden.map((a) => {
				let sym = '🔥'
				let rubrik = (a.nastaAtgard && a.nastaAtgard.label) || this.t('hubs_start', 'Ärendet väntar på dig')
				if (a.frist && (a.frist.tone === 'error' || a.frist.tone === 'warning')) {
					sym = '⏰'
					rubrik = this.t('hubs_start', '{frist} — {atgard}', {
						frist: a.frist.label || this.t('hubs_start', 'Fristen brinner'),
						atgard: (a.nastaAtgard && a.nastaAtgard.label) || this.t('hubs_start', 'åtgärd krävs'),
					})
				} else if (a.plikt && !a.plikt.kvitterad) {
					sym = '⛔'
					rubrik = a.plikt.label || this.t('hubs_start', 'Plikt att kvittera')
				} else if (a.diskussion && a.diskussion.omnamnandeTillMig) {
					sym = '@'
					rubrik = this.t('hubs_start', 'Du är omnämnd i ärendets diskussion')
				}
				return {
					sym,
					rubrik,
					// Kort referens i st.f. rå UUID: dnr → barnRef → 6-siffrigt kort-id.
					// Etiketter, aldrig råa maskintokens (audit 2026-07-07): typ via
				// kanon-mappen, steg via processtegens svenska label.
				under: (a.dnr ? 'dnr ' + a.dnr : a.barnRef || this.t('hubs_start', 'Ärende {ref}', { ref: a.kortRef || String(a.triageRef || '').slice(0, 6) }))
					+ (typLabel(a.arendeTyp) ? ' · ' + typLabel(a.arendeTyp) : '')
					+ (stegEtikett(a.steg) ? ' · ' + stegEtikett(a.steg) : ''),
					ref: a.triageRef,
				}
			})
		},
		varsladeRefs() {
			return this.hetaArenden.map((a) => a.triageRef)
		},
		/** ALLA mina ärenden (medlemsbaserad summary), frist-sorterade — det som
		 * brinner ligger överst I listan i stället för i en egen container. */
		aktivaArenden() {
			let aktiva = this.A.arenden.slice()
			if (this.A.stegFilter) {
				aktiva = aktiva.filter((a) => a.steg === this.A.stegFilter)
			}
			// gap33 — Dagspulse 'signera' narrows to ärenden waiting for signature (beslut-steget).
			if (this.A.pulsFilter === 'signera') {
				aktiva = aktiva.filter((a) => a.steg === 'beslut')
			}
			const vikt = { error: 0, warning: 1 }
			return aktiva.sort((x, y) => {
				const vx = (x.frist && vikt[x.frist.tone] !== undefined) ? vikt[x.frist.tone] : 2
				const vy = (y.frist && vikt[y.frist.tone] !== undefined) ? vikt[y.frist.tone] : 2
				if (vx !== vy) {
					return vx - vy
				}
				return String(x.frist && x.frist.due || '~') < String(y.frist && y.frist.due || '~') ? -1 : 1
			})
		},
		/** Fördelarrollen (gruppledare/förvaltare) får läge-växeln även i skarpt läge. */
		arFordelare() {
			return this.state.profile === 'forvaltare'
		},
		/**
		 * A9 — bevakningarna för det ärende som håller på att avslutas (ur A6-cachen),
		 * så AvslutaGrind kan visa den mjuka varningen om aktiv övervägande/omprövning.
		 * Tom lista när cachen ännu ej fyllts (ingen falsk varning).
		 */
		avslutaBevakningar() {
			const a = this.avslutaArende
			const ref = a && (a.triageRef || a.dnr || a.hubsCaseId)
			return (ref && this.A.bevakningarCache && this.A.bevakningarCache[ref]) || []
		},
		/** Dagspuls HÄRLEDD ur laddad data — döda motor-nollor visas aldrig som sanning. */
		pulsBeriknad() {
			const p = this.A.puls || {}
			const omn = this.A.arenden.filter((a) => a.diskussion && a.diskussion.omnamnandeTillMig).length
			return {
				fristerBrinner: p.fristerBrinner || 0,
				motenIdag: (this.meetings && this.meetings.length) || 0,
				attSignera: this.A.arenden.filter((a) => a.steg === 'beslut').length,
				nyaInflode: (this.taEmotItems && this.taEmotItems.length) || 0,
				omnamnanden: omn,
			}
		},
		triageFiltered() {
			// Dagspulse 'inflode' filter narrows to triage; other filters leave it.
			return this.A.triage
		},

		/** Korg ∩ typ-filtrerat inflöde (delas av de tre banden 1a/1b/1c). */
		filteredInflode() {
			const { aktivKorg, aktivTyp } = this.A
			// '@alla-grupper' = KorgValjarens aggregat-sentinel: alla
			// funktionsbrevlådor (scope !== 'personlig') i ett val.
			let korgTraff = (r) => !aktivKorg || (r.korg && r.korg.addr === aktivKorg)
			if (aktivKorg === '@alla-grupper') {
				const gruppAddrs = new Set(
					(this.A.korgar || [])
						.filter((k) => k.scope !== 'personlig')
						.map((k) => k.addr),
				)
				korgTraff = (r) => !!(r.korg && gruppAddrs.has(r.korg.addr))
			}
			return this.A.inflode.filter((r) =>
				korgTraff(r)
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

		/**
		 * A7/A9 — vilken grind kräver övergången (fromSteg → nyttSteg)? Rutas på
		 * grafens deterministiska kanter (inte på felmeddelandets text), så nyckeln
		 * matchar kontrakt-tabellen exakt:
		 *  - forhandsbedomning→utredning  → 'override'      (skyddsbedömning saknas, A7)
		 *  - forhandsbedomning→avslutat   → 'inteInleda'    (A9a)
		 *  - utredning→beslut             → 'kommunicering' (A9b)
		 *  - X→avslutat (ej forhb.)       → 'avslutsmotiv'  (A9c)
		 * @return {?string}
		 */
		grindForTransition(fromSteg, nyttSteg) {
			if (nyttSteg === 'avslutat') {
				return fromSteg === 'forhandsbedomning' ? 'inteInleda' : 'avslutsmotiv'
			}
			if (fromSteg === 'forhandsbedomning' && nyttSteg === 'utredning') {
				return 'override'
			}
			if (fromSteg === 'utredning' && nyttSteg === 'beslut') {
				return 'kommunicering'
			}
			return null
		},
		/**
		 * A7/A9 — kör en steg-övergång och HANTERA grind-kravet. Skickar hela kontexten
		 * (override/inteInledaVal/kommuniceringVal/avslutsmotiv) till motorn; om motorn
		 * 400:ar med grindKravs öppnas rätt grind-dialog så handläggaren kan komplettera
		 * och skicka om. Returnerar { ok } eller { grind } (dialogen öppnad, väntar val).
		 * Andra fel bubblar som vanligt (callern visar sitt eget felmeddelande).
		 * @param {object} arende
		 * @param {string} nyttSteg
		 * @param {object} [kontext] grind-kontext (kan vara tom vid första försöket)
		 * @return {Promise<{ok?:boolean, grind?:string, error?:string, r?:object}>}
		 */
		async transitionMedGrind(arende, nyttSteg, kontext = {}) {
			const ref = arende && (arende.hubsCaseId || arende.dnr || arende.triageRef)
			if (!ref) {
				return { ok: false }
			}
			try {
				const r = await store.transitionSteg(ref, nyttSteg, kontext)
				return { ok: !!(r && r.ok !== false), r }
			} catch (e) {
				const grindFel = grindKravFel(e)
				if (grindFel.grindKravs) {
					// Öppna rätt dialog för det som fattas och kom ihåg målsteget.
					const grind = this.grindForTransition(arende.steg, nyttSteg)
					this.oppnaGrindDialog(grind, arende, nyttSteg)
					return { grind: grind || 'okand', error: grindFel.error }
				}
				throw e
			}
		},
		/**
		 * A7/A9 — öppna grind-dialogen som motsvarar ett grind-krav. Skyddsbedömnings-
		 * override sköts av den inline-modalen här; inte-inleda/avslutsmotiv av
		 * AvslutaGrind; kommunicering av CommitGrind (beslut-committet).
		 */
		oppnaGrindDialog(grind, arende, nyttSteg) {
			switch (grind) {
			case 'override':
				this.overrideArende = arende
				this.overrideNyttSteg = nyttSteg
				this.overrideSkal = 'gjord_i_facksystem'
				this.overrideRunning = false
				this.overrideOpen = true
				break
			case 'inteInleda':
			case 'avslutsmotiv':
				// AvslutaGrind samlar rätt fält utifrån ärendets steg (forhandsbedomning
				// ⇒ inteInledaVal; annars avslutsmotiv). Öppna den.
				this.avslutaArende = arende
				this.avslutaRunning = false
				this.avslutaOpen = true
				break
			case 'kommunicering':
				// Beslut-committet (CommitGrind) bär kommuniceringVal. Öppna commit-grinden.
				this.onCommit(arende, { typ: 'beslut', arende })
				break
			default:
				showInfo(this.t('hubs_start', 'Ett obligatoriskt beslut saknas för att gå vidare.'))
			}
		},
		/** A7 — bekräfta skyddsbedömnings-overriden: skicka om med kontext.override. */
		async onOverrideConfirmed() {
			const arende = this.overrideArende
			const nyttSteg = this.overrideNyttSteg
			if (!arende || !nyttSteg || this.overrideRunning) {
				return
			}
			this.overrideRunning = true
			try {
				const res = await this.transitionMedGrind(arende, nyttSteg, { override: { skal: this.overrideSkal } })
				if (res.ok) {
					showSuccess(this.t('hubs_start', 'Skyddsbedömningen är dokumenterad — ärendet gick vidare till utredning.'))
					this.overrideOpen = false
				} else if (res.grind) {
					// Motorn krävde ännu ett val (ska normalt inte hända med giltigt skäl)
					// — transitionMedGrind har återöppnat rätt dialog; lämna den öppen.
				} else {
					showError(this.t('hubs_start', 'Kunde inte gå vidare: {orsak}', { orsak: res.error || this.t('hubs_start', 'okänt fel') }))
				}
			} catch (e) {
				showError(this.t('hubs_start', 'Kunde inte gå vidare. Försök igen.'))
			} finally {
				this.overrideRunning = false
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
			// DUBBELKLICKSGARD: ett meddelande får bara ha ETT skapa-anrop i luften.
			// (Motorn är dessutom idempotent på conversationId — bältet + hängslen.)
			if (this.taEmotPending.includes(rad.id)) {
				return
			}
			this.taEmotPending = this.taEmotPending.concat([rad.id])
			try {
				const r = await store.inflodeAction('skapa', { id: rad.id, rad })
				if (r && r.ok && r.arende) {
					showSuccess(this.t('hubs_start', 'Ärende startat — ärenderum skapat, 14-dagarsklockan tickar.'))
				} else {
					showError(this.t('hubs_start', 'Kunde inte starta ärendet: {orsak}', { orsak: (r && r.error) || this.t('hubs_start', 'okänt fel') }))
				}
			} catch (e) {
				showError(this.t('hubs_start', 'Kunde inte starta ärendet. Kontrollera ärendetyp och försök igen.'))
			} finally {
				this.taEmotPending = this.taEmotPending.filter((id) => id !== rad.id)
			}
		},

		// --- Multi-korg inflöde (1b/1c) -------------------------------------
		/** Generisk inflöde-/ej-kopplat-åtgärd → store-orkestrering (demo: optimistisk). */
		async onInflode(action, rad) {
			const r = rad && (rad.rad || rad)
			// Gallringsgrindens juridiska stöd (handlingstyp + gallringsbeslut)
			// följer med i payloaden — det VAR det som journalfördes-löftet lovade
			// men som tidigare tappades på vägen (audit 2026-07-07).
			const handlingstyp = (rad && rad.handlingstyp) || null
			// Fas F3 — "koppla" går ALDRIG direkt: öppna väljaren så handläggaren VÄLJER
			// målärende (felkoppling = sekretessincident → människo-bekräftelse krävs).
			if (action === 'koppla') {
				this.kopplaRad = r
				return
			}
			const msg = {
				'spara-i-rum': this.t('hubs_start', 'Sparat i ärenderummet — speglat till ärendet.'),
				besvara: this.t('hubs_start', 'Besvarat — hålls ordnat.'),
				vidarebefordra: this.t('hubs_start', 'Vidarebefordrat — säker överlämning loggad.'),
				gallra: this.t('hubs_start', 'Gallrat med dokumenterat stöd — loggat.'),
				registrera: this.t('hubs_start', 'Registrerat utan ärende — fört till diarium.'),
			}
			// Invänta utfallet: success-toast bara när åtgärden faktiskt gick igenom.
			// Ett uttryckligt fel (ok:false/error/avvisad) eller kastat fel ger ett
			// ärligt felmeddelande i stället för en falsk "klart"-bekräftelse.
			try {
				const res = await store.inflodeAction(action, { id: r && r.id, rad: r, ...(handlingstyp ? { handlingstyp } : {}) })
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
			// ÖPPNA kortet på riktigt (audit 2026-07-07: tidigare bara en toast).
			// Resolva raden → matchande ärendekort i listan → scroll + markering
			// (samma landningsmekanik som varsel-länkarna).
			const koppling = payload && (payload.koppling || (payload.rad && payload.rad.koppling)) || {}
			const dnr = koppling.dnr || (payload && payload.dnr)
			const hubsCaseId = koppling.hubsCaseId || (payload && payload.hubsCaseId)
			const kort = (this.A.arenden || []).find((a) =>
				(hubsCaseId && a.hubsCaseId === hubsCaseId) || (dnr && a.dnr === dnr))
			if (kort) {
				this.gaTillArende(kort.triageRef || kort.hubsCaseId)
			} else {
				showInfo(this.t('hubs_start', 'Ärendet {ref} finns inte i din lista — det kan tillhöra en annan handläggare.', { ref: dnr || hubsCaseId || '—' }))
			}
		},

		/**
		 * "Skapa bevakning" på en inflöde-rad (1b). Bevakningar skapas nu mot
		 * MOTORN (skapaBevakning() → /arende/{ref}/bevakning), inte via det döda
		 * sdkmc-verbet 'skapa-bevakning' som föll till ett obefintligt endpoint
		 * bakom en falsk "Bevakning skapad."-toast (audit 2026-07-07). Vi öppnar
		 * radens ärendekort så handläggaren skapar bevakningen i Bevakningar-fliken
		 * (samma landningsmekanik som "Öppna ärendet" — ett ärende = ett kort).
		 */
		onSkapaBevakningInflode(rad) {
			const koppling = (rad && (rad.koppling || (rad.rad && rad.rad.koppling))) || {}
			const dnr = koppling.dnr || (rad && rad.dnr)
			const hubsCaseId = koppling.hubsCaseId || (rad && rad.hubsCaseId)
			const kort = (this.A.arenden || []).find((a) =>
				(hubsCaseId && a.hubsCaseId === hubsCaseId) || (dnr && a.dnr === dnr))
			if (kort) {
				this.gaTillArende(kort.triageRef || kort.hubsCaseId)
			} else {
				showInfo(this.t('hubs_start', 'Ärendet {ref} finns inte i din lista — öppna ärendet för att skapa en bevakning.', { ref: dnr || hubsCaseId || '—' }))
			}
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
		/**
		 * Avvisa ett kopplingsförslag (audit 2026-07-07: avvisa startade tidigare
		 * "skapa nytt ärende"!). Förslaget rensas lokalt — raden ligger kvar i
		 * hinken för fortsatt triage; ingen durabel åtgärd sker.
		 */
		onAvvisaForslag(rad) {
			const r = rad && (rad.rad || rad)
			if (r && r.koppling) {
				r.koppling = null
			}
			showInfo(this.t('hubs_start', 'Kopplingsförslaget avvisat — raden ligger kvar för triage.'))
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
		onOpenTeam(arende) {
			// T — öppna ärendets team (den samlade rums-vyn: medlemmar, akt,
			// diskussion). teamId ur motorns pekare-block; kollapsad fallback via
			// kortets teamId-fält. Ärlig frånvaro: utan team → info, aldrig 404.
			const ref = arende && (arende.triageRef || arende.dnr)
			const full = (ref && store.state.arende.full[ref]) || arende || {}
			const teamId = (full.pekare && full.pekare.teamId) || full.teamId || (arende && arende.teamId) || null
			const url = deepLinks.teamLink(teamId)
			if (!url) {
				showInfo(this.t('hubs_start', 'Ärendet saknar team ännu.'))
				return
			}
			window.location.href = url
		},
		/** Ny chatt i ärenderummet (1:n) — öppna namn-modalen. */
		onNyChatt(arende) {
			this.nyChattArende = arende
			this.nyChattOpen = true
		},
		async onNyChattSkapa(arende, namn) {
			const ref = arende && (arende.hubsCaseId || arende.dnr || arende.triageRef)
			if (!ref) {
				this.nyChattOpen = false
				return
			}
			this.nyChattRunning = true
			try {
				const r = await skapaArendeChatt(ref, namn)
				if (r && r.ok && r.talkToken) {
					// Rakt in i den nya chatten (teamet + åtkomstlistan är redan deltagare).
					window.location.href = deepLinks.spreedRoomLink(r.talkToken)
				} else if (r && r.ok) {
					showSuccess(this.t('hubs_start', 'Chatten är skapad och kopplad till ärendet.'))
					this.nyChattOpen = false
				} else {
					showError(this.t('hubs_start', 'Kunde inte skapa chatten: {orsak}', { orsak: (r && r.error) || this.t('hubs_start', 'okänt fel') }))
				}
			} catch (e) {
				showError(this.t('hubs_start', 'Kunde inte skapa chatten. Försök igen.'))
			} finally {
				this.nyChattRunning = false
			}
		},
		/** Skapa handling från mall (menyval på kortet) — öppna mall-modalen. */
		onSkapaHandling(arende) {
			this.handlingArende = arende
			this.handlingOpen = true
		},
		async onHandlingSkapa(payload) {
			// OBS: HandlingModal emittar ETT payload-objekt {mallId, falt, mallNamn}
			// (inte NyChattModal-mönstrets (arende, värde)) — ärendet hämtas ur state.
			const arende = this.handlingArende
			const ref = arende && (arende.hubsCaseId || arende.dnr || arende.triageRef)
			if (!ref || !payload || !payload.mallId) {
				showError(this.t('hubs_start', 'Kunde inte skapa handlingen — ärendereferens eller mall saknas.'))
				this.handlingOpen = false
				return
			}
			this.handlingRunning = true
			try {
				const r = await skapaHandling(ref, { mallId: payload.mallId, falt: payload.falt || {} })
				if (r && r.ok && r.filnamn) {
					showSuccess(this.t('hubs_start', 'Handlingen {fil} är skapad i akten.', { fil: r.filnamn }))
					this.handlingOpen = false
					// Öppna dokumentet i akten (Filer → dubbelklick öppnar redigeraren).
					window.location.href = deepLinks.fileLink('/' + (arende.hubsCaseId || '') + '/' + r.filnamn)
				} else {
					showError(this.t('hubs_start', 'Kunde inte skapa handlingen: {orsak}', { orsak: (r && r.error) || this.t('hubs_start', 'okänt fel') }))
				}
			} catch (e) {
				const orsak = e && e.response && e.response.data && e.response.data.ocs && e.response.data.ocs.data && e.response.data.ocs.data.error
				showError(this.t('hubs_start', 'Kunde inte skapa handlingen: {orsak}', { orsak: orsak || this.t('hubs_start', 'okänt fel') }))
			} finally {
				this.handlingRunning = false
			}
		},
		/** Omfördela (menyval på kortet) — öppna kollega-modalen. */
		onOmfordelaKollega(arende) {
			this.omfordelaArende = arende
		},
		async onOmfordelaSkapa(arende, uid) {
			const ref = arende && (arende.hubsCaseId || arende.dnr || arende.triageRef)
			if (!ref || !uid) {
				this.omfordelaArende = null
				return
			}
			this.omfordelaRunning = true
			try {
				const r = await tilldela(ref, uid)
				if (r && r.ok !== false) {
					showSuccess(this.t('hubs_start', 'Omfördelat till {uid} — hen är nu handläggare och har notifierats.', { uid }))
					this.omfordelaArende = null
					// Ägarbytet ändrar tilldelning + ev. min åtkomst — läs om summaryn.
					try {
						await store.loadArendeSummary()
					} catch (e) { /* omfördelningen är redan genomförd */ }
				} else {
					showError(this.t('hubs_start', 'Kunde inte omfördela: {orsak}', { orsak: (r && r.error) || this.t('hubs_start', 'okänt fel') }))
				}
			} catch (e) {
				// Motorns 400 (t.ex. okänd användare) bär orsaken i OCS-kroppen.
				const orsak = e && e.response && e.response.data && e.response.data.ocs
					&& e.response.data.ocs.data && e.response.data.ocs.data.error
				showError(orsak
					? this.t('hubs_start', 'Kunde inte omfördela: {orsak}', { orsak })
					: this.t('hubs_start', 'Kunde inte omfördela ärendet. Försök igen.'))
			} finally {
				this.omfordelaRunning = false
			}
		},
		onSkicka(arende) {
			// #9 — öppna säker compose med ärende-kontext (dnr/hubsCaseId) så det sända
			// meddelandet pre-taggas case: på ärendet direkt (kräver mail-routerhook honorerar
			// &case=). Typ = secure_email (säker kanal till medborgare).
			const ref = arende && (arende.dnr || arende.hubsCaseId)
			window.location.href = deepLinks.composerLink('secure_email', null, ref)
		},
		onSignera(arende) {
			// #6 — öppna CommitGrind DIREKT med signerings-bekräftelsen inbäddad (en
			// enda modal). Tidigare öppnades en separat SigneringsGrind-modal som i sin
			// tur öppnade CommitGrind → två staplade NcModaler monterades/avmonterades i
			// samma tick och deadlockade focus-trap/scroll-lock (UI:t "hängde"). "För
			// över" gateas tills handläggaren kryssat "Jag har signerat dokumentet".
			this.onCommit(arende, { typ: 'signerat-beslut', arende, kraverSignering: true })
		},
		/** #5 — terminalt steg: öppna avsluta-grinden (bekräftelse + A9-motiv). */
		onAvsluta(arende) {
			this.avslutaArende = arende
			this.avslutaRunning = false
			this.avslutaOpen = true
			// A9 — ladda evidensen så AvslutaGrindens mjuka omprövnings-varning blir
			// korrekt (bevakningarna hamnar i A6-cachen som avslutaBevakningar läser).
			const ref = arende && (arende.triageRef || arende.dnr || arende.hubsCaseId)
			if (ref) {
				store.loadStegEvidens(ref)
			}
		},
		/**
		 * #5 + A9a/A9c — bekräftat avslut: en REN steg-övergång till 'avslutat' (ingen ny
		 * Treserva-commit — akten är redan registrerad; avslut ≠ registrering). AvslutaGrind
		 * samlar in grind-kontexten utifrån ärendets steg och emittar den:
		 *  - forhandsbedomning ⇒ { inteInledaVal: { orsak, beslutsfattare } }  (A9a)
		 *  - annars            ⇒ { avslutsmotiv: { utfall, kvarstaende? } }     (A9c)
		 * Vi trådar hela payloaden som kontext till motorn. Bakåtkompatibelt: en
		 * payload utan grind-fält (t.ex. flaggan AV) ger en ren övergång som förut.
		 * Om motorn ändå 400:ar med grindKravs öppnas rätt dialog av transitionMedGrind.
		 *
		 * Robust mot AvslutaGrindens emit-form: ärendet tas ur komponentens state
		 * (this.avslutaArende), och kontexten letas fram bland emit-argumenten oavsett
		 * om grinden emittar (arende, kontext), (kontext) eller bara (arende).
		 * @param {...*} args emit-argument från AvslutaGrind
		 */
		async onAvslutaConfirmed(...args) {
			const arende = this.avslutaArende
			const ref = arende && (arende.hubsCaseId || arende.dnr || arende.triageRef)
			if (!ref) {
				this.avslutaOpen = false
				return
			}
			// Hitta grind-payloaden (det arg som bär inteInledaVal/avslutsmotiv) och
			// plocka bara ut de kontrakt-nycklar motorn förstår — aldrig hela objektet.
			const payload = args.find((a) => a && typeof a === 'object' && (a.inteInledaVal || a.avslutsmotiv)) || {}
			const kontext = {}
			if (payload.inteInledaVal) {
				kontext.inteInledaVal = payload.inteInledaVal
			}
			if (payload.avslutsmotiv) {
				kontext.avslutsmotiv = payload.avslutsmotiv
			}
			this.avslutaRunning = true
			try {
				const res = await this.transitionMedGrind(arende, 'avslutat', kontext)
				if (res.ok) {
					showSuccess(this.t('hubs_start', 'Ärendet avslutat — gallringen av Hubs-rummet har startat.'))
					this.avslutaOpen = false
				} else if (res.grind) {
					// Grind-dialogen är (åter)öppnad av transitionMedGrind — lämna den.
				} else {
					showError(this.t('hubs_start', 'Kunde inte avsluta ärendet: {orsak}', { orsak: res.error || this.t('hubs_start', 'okänt fel') }))
					this.avslutaOpen = false
				}
			} catch (e) {
				showError(this.t('hubs_start', 'Kunde inte avsluta ärendet. Försök igen.'))
				this.avslutaOpen = false
			} finally {
				this.avslutaRunning = false
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
		/**
		 * @param {object} result CommitGrindens verifierade kvitto
		 * @param {Array} [valdaDokument] granskade dokument (trådas till andra committet)
		 * @param {?object} [kommuniceringValArg] A9b — CommitGrind emittar kommunicerings-
		 *        valet {gjord, skal?} (eller null) som TREDJE arg inför utredning→beslut.
		 */
		async onCommitted(result, valdaDokument, kommuniceringValArg) {
			this.commitOpen = false
			const arende = this.commitArende
			// A9b — bygg kontexten under rätt nyckel (kommuniceringVal). CommitGrind
			// skickar valet som rått {gjord, skal?}-objekt; var ändå robust mot en ev.
			// {kommuniceringVal:{…}}-inpackning (grindens emit-form kan variera).
			const grindKontext = {}
			const kv = (kommuniceringValArg && kommuniceringValArg.kommuniceringVal)
				|| kommuniceringValArg
				|| (result && result.kommuniceringVal)
			if (kv && typeof kv === 'object') {
				grindKontext.kommuniceringVal = kv
			}
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
					// gap1/A7/A9 — efter en VERIFIERAD commit: advancera ärendet ett steg
					// i grafen (forhandsbedomning→utredning→beslut→uppfoljning→avslutat).
					const next = this.nextSteg(arende && arende.steg)
					if (arende && next) {
						try {
							// A7: den hårdkodade skyddsbedomningKvitterad=true är BORTTAGEN —
							// grinden sköts server-side. En VERIFIERAD commit av skyddsbedöm-
							// ningen skapar artefakten som A7-grinden läser, så övergången
							// släpps utan override. A9b: kommuniceringVal (från CommitGrind)
							// trådas som kontext inför utredning→beslut. Saknas ett obligato-
							// riskt grind-val 400:ar motorn och transitionMedGrind öppnar rätt
							// dialog. Best-effort: commiten är redan verifierad oavsett.
							await this.transitionMedGrind(arende, next, grindKontext)
						} catch (e) { /* steg-advance är best-effort; commit är redan verifierad */ }
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
		openMeetingWizard(arende = null) {
			this.commandPaletteOpen = false
			// gap17 — kortets "Boka möte" bär ärendet in i wizarden så bokningen
			// dnr-märks och landar i kortets Möten-flik.
			this.wizardArende = (arende && (arende.dnr || arende.hubsCaseId)) ? arende : null
			this.meetingWizardOpen = true
		},
		/** Varsel-länken: scrolla till + markera kortet i Mina ärenden. */
		/**
		 * Dagspulsens filter — 'mote' och 'omnamnanden' var tidigare DÖDA
		 * (audit 2026-07-07): inget lyssnade på dem. Ärlig wiring: mote-pulsen
		 * tar handläggaren till Mötesremsan, omnämnanden öppnar enhetschatten;
		 * övriga är riktiga listfilter (frist/signera/inflode) som förut.
		 */
		/** Fel-bannern: nollställ och hämta om grunddatat. */
		async onForsokIgen() {
			state.error = null
			await store.loadArendeSummary()
		},

		onPulsFilter(f) {
			if (f === 'mote') {
				this.motenVisas = true
				this.$nextTick(() => {
					const el = this.$el.querySelector('.motes-remsa, .mina-arenden__kollapsad')
					el && el.scrollIntoView({ behavior: 'smooth', block: 'start' })
				})
				return
			}
			if (f === 'omnamnanden') {
				store.toggleEnhetschatt(true)
				return
			}
			store.setPulsFilter(f)
		},

		gaTillArende(ref) {
			this.markeradRef = ref
			this.$nextTick(() => {
				const el = document.getElementById('arende-' + ref)
				if (el) {
					el.scrollIntoView({ behavior: 'smooth', block: 'start' })
					el.focus && el.focus({ preventScroll: true })
				}
			})
			// Markeringen är en landningshjälp, inte ett permanent tillstånd.
			window.setTimeout(() => {
				if (this.markeradRef === ref) {
					this.markeradRef = null
				}
			}, 4000)
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

	// Fel-bannern: synligt men lugnt — varningston, aldrig blockerande.
	&__fel {
		display: flex;
		align-items: center;
		gap: 10px;
		padding: 10px 14px;
		margin-bottom: 12px;
		border: 1px solid var(--color-warning, #b07c00);
		border-radius: var(--border-radius-large, 10px);
		background: var(--color-background-hover);
		color: var(--color-main-text);
		font-size: 0.9rem;
	}

	&__kollapsad {
		display: flex;
		align-items: center;
		gap: 8px;
		width: 100%;
		text-align: left;
		font: inherit;
		font-weight: 600;
		color: var(--color-text-maxcontrast);
		cursor: pointer;
		border: 1px solid var(--color-border);
	}
	&__kollapsad-antal {
		padding: 0 8px;
		border-radius: var(--border-radius-pill, 16px);
		background: var(--color-background-dark);
		font-variant-numeric: tabular-nums;
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

	// A7 — inline skyddsbedömnings-override-grind.
	&__override {
		display: flex;
		flex-direction: column;
		gap: 16px;
		padding: 8px 4px;
	}
	&__override-lead {
		display: flex;
		align-items: flex-start;
		gap: 8px;
		margin: 0;
		svg { flex-shrink: 0; margin-top: 1px; color: var(--hs-status-error); }
	}
	&__override-val {
		display: flex;
		flex-direction: column;
		gap: 6px;
		margin: 0;
		padding: 0;
		border: none;
	}
	&__override-legend {
		font-weight: 600;
		font-size: 0.9rem;
		margin-bottom: 2px;
		padding: 0;
	}
	&__override-foot {
		display: flex;
		justify-content: flex-end;
		gap: 8px;
		padding-top: 4px;
	}
}
</style>
