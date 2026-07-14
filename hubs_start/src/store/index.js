/**
 * Hubs Start — lightweight reactive store (Vue 2.7 `Vue.observable`).
 *
 * No Pinia/Vuex dependency: the app's state is small and a single observable
 * module keeps it self-contained. Components import `store` and read `store.state`
 * (reactive) and call the action methods. Polling is incremental via sinceIds.
 *
 * THE STATE SHAPE HERE IS A CONTRACT — components and the workflow agents rely on
 * these exact field names. See docs/CONTRACTS.md.
 */

import Vue from 'vue'
import { loadState } from '@nextcloud/initial-state'
import api from '../services/api.js'
import { isDemo } from '../services/demoData.js'
import { defaultPersonaId } from '../services/personas.js'

const POLL_INTERVAL_MS = 30000

const state = Vue.observable({
	/** @type {('LOA1'|'LOA2'|'LOA3')} */
	loa: 'LOA3',
	/** Demo mode: UI rendered from fixtures, deep links inert (see DEMO.md). */
	demoMode: false,
	/** Which Hubs apps are installed/enabled (from initial state). */
	apps: { sdkmc: false, mail: false, spreed: false, calendar: false, securemail: false },
	/** Simplified role profile: 'handlaggare' | 'registrator' | 'forvaltare'. */
	profile: 'handlaggare',
	/** Active persona id — drives the personalised dashboard layout + actions. */
	activePersona: null,
	/** Channels actually monitored for this user (honest coverage declaration). */
	channelCoverage: [],
	/** UI preferences. */
	prefs: { onboardingSeen: false, keyboardMode: false },

	/** Triage. */
	items: [],
	counts: { kravAtgard: 0, otilldelat: 0, nytt: 0, bevakas: 0, klartIdag: 0, problem: 0 },
	mailboxes: [],
	watching: [],
	receipts: [],
	meetings: [],
	appointmentConfigs: [],

	/** Socialsekreterare "Mina ärenden" redesign slice. */
	arende: {
		puls: { fristerBrinner: 0, motenIdag: 0, attSignera: 0, nyaInflode: 0, omnamnanden: 0 },
		triage: [],
		arenden: [],
		moten: [],
		klartIdag: 0,
		loading: true,
		pulsFilter: null,
		stegFilter: null,
		full: {},
		/**
		 * A6 — evidens-bundlar per ärende (cache-nyckel = triageRef|ref). Byggs ur
		 * REDAN hämtade signaler (historik/bevakningar/parter) så processteppern kan
		 * härleda delmoment-status (arendeFlow.harledStatus) utan ny backend. Fylls
		 * av loadStegEvidens(); läses synkront via stegEvidens(). Se docs/CONTRACTS.md.
		 */
		historikCache: {},
		bevakningarCache: {},
		parterCache: {},
		/** Refs vars evidens-laddning pågår (dedup mot parallella stepper-hovers). */
		stegEvidensLaddar: {},
		/** Multi-korg inflöde (KorgValjare + de tre banden 1a/1b/1c). */
		korgar: [],
		inflode: [],
		aktivKorg: null,
		aktivTyp: null,
		/** Roll-läge: 'utredning' (handläggare) | 'fordelning' (gruppledare). */
		lage: 'utredning',
		fordelning: { attFordela: [], utredare: [], mottagningPagaende: 0, loading: true },
		/** Team-/enhetschatt (sidopanel). */
		team: [],
		enhetschattOppen: false,
		/** Verifierade Treserva-kvittenser (kvittens-/retention-ytan). */
		receipts: [],
		/** Kontakter-favoriter (resolverat aggregat). */
		favoriter: [],
	},

	/** Active channel filter tab ('all' | channel id). */
	activeChannel: 'all',

	/** Loading / error flags. */
	loading: true,
	error: null,
	maxSinceId: null,
	lastUpdated: null,
})

let pollTimer = null

const store = {
	state,

	// --- Bootstrap -----------------------------------------------------------

	/** Hydrate from server-injected initial state (no XHR). */
	bootFromInitialState() {
		const boot = loadState('hubs_start', 'boot', {})
		// isDemo() honours the ?demo= URL override (sessionStorage-persisted) on top
		// of the server boot flag, so "Visa i demoläge" works on a live instance.
		state.demoMode = isDemo()
		if (boot.loa) state.loa = boot.loa
		if (boot.apps) state.apps = { ...state.apps, ...boot.apps }
		if (boot.profile) state.profile = boot.profile
		if (Array.isArray(boot.channelCoverage)) state.channelCoverage = boot.channelCoverage
		if (boot.prefs) state.prefs = { ...state.prefs, ...boot.prefs }
		// Persona: explicit boot.persona wins; else first persona (demo lets the
		// viewer switch freely). On a real install this maps from role/group.
		state.activePersona = boot.persona || defaultPersonaId
	},

	/** Switch the active persona (demo persona switcher). */
	setPersona(personaId) {
		state.activePersona = personaId
	},

	// --- Live data -----------------------------------------------------------

	/** Full (or incremental) refresh of the aggregated summary. */
	async refreshSummary() {
		try {
			const summary = await api.fetchSummary(state.maxSinceId)
			this.applySummary(summary)
			state.error = null
		} catch (e) {
			// Non-fatal: on an instance where sdkmc lacks the aggregation endpoints
			// (e.g. dev15 — the engine, not sdkmc, owns the ärende data), the widget-
			// summary just stays empty. The socialsekreterare "Mina ärenden" view loads
			// independently from hubs_arende and is unaffected, so do NOT block on it.
			state.counts = state.counts || {}
		} finally {
			state.loading = false
			state.lastUpdated = new Date().toISOString()
		}
	},

	/**
	 * Merge a Summary payload into state. For incremental polls (sinceIds set)
	 * items are upserted by id; for a cold load the list is replaced.
	 * @param {object} summary
	 */
	applySummary(summary) {
		if (!summary) return
		state.loa = summary.loa ?? state.loa
		state.counts = summary.counts ?? state.counts
		state.mailboxes = summary.mailboxes ?? state.mailboxes
		state.watching = summary.watching ?? state.watching
		if (Array.isArray(summary.channelCoverage)) state.channelCoverage = summary.channelCoverage

		if (state.maxSinceId && Array.isArray(summary.items)) {
			// Incremental: upsert by id, keep ordering by `since` desc.
			const byId = new Map(state.items.map((i) => [i.id, i]))
			for (const item of summary.items) byId.set(item.id, item)
			state.items = [...byId.values()].sort((a, b) => (a.since < b.since ? 1 : -1))
		} else {
			state.items = summary.items ?? []
		}
		state.maxSinceId = summary.maxSinceId ?? state.maxSinceId
	},

	async refreshMeetings() {
		try {
			state.meetings = await api.fetchTodaysMeetings()
		} catch (e) { /* non-fatal: widget shows its own empty state */ }
	},

	async refreshReceipts() {
		try {
			state.receipts = await api.fetchReceipts({ limit: 20 })
		} catch (e) { /* non-fatal */ }
	},

	async refreshAppointments() {
		try {
			state.appointmentConfigs = await api.fetchAppointmentConfigs()
		} catch (e) { /* non-fatal */ }
	},

	// --- Socialsekreterare "Mina ärenden" -----------------------------------

	async loadArendeSummary() {
		try {
			const s = await api.fetchArendeSummary()
			const a = state.arende
			a.puls = s.puls || a.puls
			a.triage = s.triage || []
			a.arenden = s.arenden || []
			a.moten = s.moten || []
			a.klartIdag = s.klartIdag || 0
		} catch (e) {
			state.error = e
		} finally {
			state.arende.loading = false
		}
		// Multi-korg inflöde + team + kvittenser + favoriter (non-fatal, egna tom-tillstånd).
		this.loadInflodeSummary()
		this.loadTeam()
		this.loadReceipts()
		this.loadFavoriter()
	},

	/**
	 * Lazy-load a full ärende (flik content) when a card expands. `ref` is the
	 * STABLE cache key — always triageRef (= dnr ?? hubsCaseId), never the
	 * sometimes-null dnr (else an unregistered case caches under one key but the
	 * card reads another and the flikar render forever-empty). Components MUST call
	 * with arende.triageRef || arende.dnr and read full[that same key].
	 */
	async loadArende(ref, force = false) {
		if (!force && state.arende.full[ref]) {
			return state.arende.full[ref]
		}
		try {
			const a = await api.fetchArende(ref)
			Vue.set(state.arende.full, ref, a)
			// #3 — berika med ärenderums-innehåll (diskussion-summary) som sdkmc äger.
			// Icke-fatal: motorns ärlig-tomma fält står kvar om berikningen saknas/felar.
			this.enrichArende(ref, a)
			return a
		} catch (e) {
			return null
		}
	},

	/**
	 * #3/#15/#16 — slå upp sdkmc-ägd ärenderums-berikning (diskussion-summary) via
	 * motorns full.pekare.talkToken och MERGE:a in den UTAN att skriva över
	 * motor-ärliga fält (steg/frist/provenance/pekare/rum). Tunn-motor-principen:
	 * motorn äger koordinations-state + pekare, sdkmc berikar PII-innehållet vid expand.
	 * @param {string} ref cache-nyckeln (triageRef)
	 * @param {object} card det redan cachade motor-kortet
	 */
	async enrichArende(ref, card) {
		const talkToken = card && card.pekare && card.pekare.talkToken
		if (!talkToken) return
		try {
			const enr = await api.fetchArendeEnrichment(talkToken)
			const current = state.arende.full[ref]
			if (!current || !enr) return
			Vue.set(state.arende.full, ref, {
				...current,
				// Fyll motorns ärlig-tomma listor; ersätt ALDRIG befintliga motor-fält.
				meddelanden: (enr.meddelanden && enr.meddelanden.length) ? enr.meddelanden : current.meddelanden,
				moten: (enr.moten && enr.moten.length) ? enr.moten : current.moten,
				diskussion: enr.diskussion || current.diskussion,
			})
		} catch (e) { /* non-fatal: kortet visar motorns ärlig-tomma flikar */ }
	},

	/**
	 * A6 — bygg evidens-bundeln {journal, commit, bevakningar, parter} för ett ärende
	 * ur REDAN hämtad data (historik-/bevaknings-/parts-cache + full-registret). Ingen
	 * ny backend: signalerna finns i journalen/registret, de projiceras bara till den
	 * form arendeFlow.harledStatus/stegNodState läser. Synkron (returnerar tomma listor
	 * tills loadStegEvidens() fyllt cachen) så processteppern kan rendera direkt.
	 * @param {string} ref cache-nyckeln (triageRef|dnr|hubsCaseId)
	 * @return {{journal:Array, commit:{registrerad:boolean, verifierad:boolean, dnr:?string}, bevakningar:Array, parter:Array}}
	 */
	stegEvidens(ref) {
		const full = (ref && state.arende.full[ref]) || {}
		const journal = state.arende.historikCache[ref] || []
		const bevakningar = state.arende.bevakningarCache[ref] || []
		const parter = state.arende.parterCache[ref] || []
		// Commit-signalen härleds ur motorns provenance (registrerad = verifierad
		// commit i facksystemet). full.beslut finns bara på ett committat ärende, så
		// dess närvaro är ett andra (svagare) registrerad-tecken. Aldrig fabricerat.
		const prov = full.provenance || {}
		const registrerad = prov.state === 'registrerad' || !!full.beslut
		const commit = {
			registrerad,
			// harledStatus 'commit'-grenen kräver verifierad för 'klar'. Motorns
			// provenance flippas till 'registrerad' först på det VERIFIERADE kvittot
			// (se store.commitArende/gap12), så registrerad ⇒ verifierad här.
			verifierad: registrerad,
			dnr: prov.dnr || full.dnr || null,
		}
		return { journal, commit, bevakningar, parter }
	},

	/**
	 * A6 — hämta och cacha de signaler stegEvidens() bygger på (historik + bevakningar
	 * + parter) för ETT ärende. Återanvänder samma OCS-läsytor som kortets flikar
	 * (fetchArendeHistorik/-Bevakningar/-Parter) — ingen ny backend. Best-effort per
	 * källa (en tom lista är ärligare än en spinner) och deduplicerad så samtidiga
	 * stepper-hovers bara ger EN hämtning. Idempotent: hoppar över när cachen finns
	 * och force ej satt.
	 * @param {string} ref cache-nyckeln (triageRef|dnr|hubsCaseId)
	 * @param {boolean} [force] tvinga omhämtning (efter en mutation)
	 */
	async loadStegEvidens(ref, force = false) {
		if (!ref) return
		if (!force && state.arende.historikCache[ref] && state.arende.bevakningarCache[ref]) {
			return
		}
		if (state.arende.stegEvidensLaddar[ref]) {
			return
		}
		Vue.set(state.arende.stegEvidensLaddar, ref, true)
		try {
			const [journal, bevakningar, parter] = await Promise.all([
				api.fetchArendeHistorik(ref).catch(() => []),
				api.fetchArendeBevakningar(ref).catch(() => []),
				api.fetchArendeParter(ref).catch(() => []),
			])
			Vue.set(state.arende.historikCache, ref, Array.isArray(journal) ? journal : [])
			Vue.set(state.arende.bevakningarCache, ref, Array.isArray(bevakningar) ? bevakningar : [])
			Vue.set(state.arende.parterCache, ref, Array.isArray(parter) ? parter : [])
		} finally {
			Vue.set(state.arende.stegEvidensLaddar, ref, false)
		}
	},

	/**
	 * A6 — write-through av redan hämtad flik-data till evidens-cachen. ArendeKort
	 * hämtar historik/bevakningar/parter för sina flikar; genom att spegla in dem här
	 * slipper processteppern en andra hämtning (och ser samma färska data). Best-effort:
	 * ignorerar icke-arrayer.
	 * @param {string} ref cache-nyckeln
	 * @param {string} slag 'historik' | 'bevakningar' | 'parter'
	 * @param {Array} data
	 */
	setStegEvidensDel(ref, slag, data) {
		if (!ref || !Array.isArray(data)) return
		const karta = { historik: 'historikCache', bevakningar: 'bevakningarCache', parter: 'parterCache' }
		const nyckel = karta[slag]
		if (nyckel) {
			Vue.set(state.arende[nyckel], ref, data)
		}
	},

	/**
	 * Commit a handling to Treserva (via Frends-stubben). På VERIFIERAT kvitto:
	 * flippar provenans, kvitterar plikt, startar retention och lägger kvittot på
	 * kvittens-ytan. 🔌 SEAM[treserva.commit] — se docs/DEMO-STUBS.md.
	 */
	async commitArende(payload) {
		const r = await api.commitToTreserva(payload)
		if (r && r.ok) {
			// Provenans/retention/plikt flippas ENDAST på det VERIFIERADE kvittot (gap12).
			// Receipt + honest-fel-hantering körs redan på r.ok (ej-verifierat → ärendet
			// står kvar i sitt utkast-läge tills den verifierade callbacken kommer).
			if (r.verifierad) {
				const target = payload && payload.arende
				// Matcha på STABIL identitet, inte nullbar dnr: ett ärende som
				// committas är per definition oregistrerat (dnr=null), och
				// `x.dnr === target.dnr` (null===null) matchade FÖRSTA oregistrerade
				// kortet i listan — provenans/plikt/retention flippade på fel kort (B2).
				const nyckel = target && (target.triageRef || target.hubsCaseId)
				const a = nyckel
					? state.arende.arenden.find((x) => x.triageRef === nyckel || x.hubsCaseId === nyckel || (x.dnr !== null && x.dnr === nyckel))
					: null
				if (a) {
					a.provenance = {
						state: 'registrerad',
						dnr: r.dnr,
						gallrasDatum: r.gallrasDatum || null,
						bevarasDatum: (a.provenance && a.provenance.bevarasDatum) || null,
					}
					// Retention startar ENBART på den verifierade callbacken (GAP-007-mönstret).
					a.retention = { state: 'gallras_efter_commit', gallrasDatum: r.gallrasDatum || null, verifierad: true }
					if (a.plikt) {
						a.plikt = { ...a.plikt, kvitterad: true }
					}
				}
			}
			if (r.receipt) {
				state.arende.receipts.unshift(r.receipt)
			}
		}
		return r
	},

	/**
	 * Avancera ett ärendes steg i livscykel-grafen (gap1/gap15 + A7/A9). Anropas av
	 * MinaArenden efter en VERIFIERAD commit ELLER direkt via nästa-åtgärd. Hela
	 * grind-KONTEXTEN trådas vidare till motorn (override/inteInledaVal/
	 * kommuniceringVal/avslutsmotiv) — se api.transitionSteg. Patchar lokalt steg på
	 * det matchade ärendet vid ok-svar. Ett grind-krav (400) propageras som kastat
	 * fel så callern kan öppna rätt grind-dialog (api.grindKravFel). 🔌 SEAM[arende.steg].
	 * @param {string} ref hubsCaseId, dnr eller triageRef
	 * @param {string} nyttSteg målsteget
	 * @param {(object|boolean)} [kontext] grind-kontexten (boolean = legacy
	 *        skyddsbedomningKvitterad; se api.transitionSteg)
	 */
	async transitionSteg(ref, nyttSteg, kontext = {}) {
		const r = await api.transitionSteg(ref, nyttSteg, kontext)
		if (r && (r.ok !== false)) {
			const a = state.arende.arenden.find((x) => x.hubsCaseId === ref || x.dnr === ref || x.triageRef === ref)
			if (a) a.steg = (r.steg || nyttSteg)
		}
		return r
	},

	/** Load verifierade Treserva-kvittenser. */
	async loadReceipts() {
		try { state.arende.receipts = await api.fetchTreservaReceipts() } catch (e) { /* non-fatal */ }
	},

	/** Load Kontakter-favoriter (resolverat aggregat). */
	async loadFavoriter() {
		try { state.arende.favoriter = await api.fetchFavoriter() } catch (e) { /* non-fatal */ }
	},

	setPulsFilter(key) {
		state.arende.pulsFilter = state.arende.pulsFilter === key ? null : key
	},
	setStegFilter(steg) {
		state.arende.stegFilter = steg
	},
	removeTriage(id) {
		state.arende.triage = state.arende.triage.filter((t) => t.id !== id)
	},

	// --- Multi-korg inflöde + fördelning + enhetschatt ----------------------

	/** Load behörighetsfiltrerade korgar + inflöde-rader (de tre banden). */
	async loadInflodeSummary() {
		try {
			const s = await api.fetchInflodeSummary()
			state.arende.korgar = s.korgar || []
			state.arende.inflode = s.inflode || []
		} catch (e) { /* non-fatal: banden visar tom-tillstånd */ }
	},

	/** Load gruppledarens fördelningsvy (roll-läge 'fordelning'). */
	async loadFordelningSummary() {
		try {
			const s = await api.fetchFordelningSummary()
			state.arende.fordelning = { ...s, loading: false }
		} catch (e) {
			// Fel får inte sväljas tyst (audit 2026-07-07): en tom fördelningsvy
			// utan förklaring ser ut som "inget att fördela". Fel-bannern i
			// MinaArenden plockar upp state.error.
			state.arende.fordelning.loading = false
			state.error = e
		}
	},

	/** Load team-/enhetschatt-trådar (sidopanel). */
	async loadTeam() {
		try { state.arende.team = await api.fetchTeam() } catch (e) { /* non-fatal */ }
	},

	setKorgFilter(addr) {
		state.arende.aktivKorg = state.arende.aktivKorg === addr ? null : addr
	},
	setTypFilter(typ) {
		state.arende.aktivTyp = state.arende.aktivTyp === typ ? null : typ
	},
	setLage(lage) {
		state.arende.lage = lage
		if (lage === 'fordelning' && state.arende.fordelning.loading) {
			this.loadFordelningSummary()
		}
	},
	toggleEnhetschatt(open) {
		state.arende.enhetschattOppen = open === undefined ? !state.arende.enhetschattOppen : open
	},

	/** Optimistically remove an inflöde-rad after a triage action resolves it. */
	removeInflode(id) {
		state.arende.inflode = state.arende.inflode.filter((r) => r.id !== id)
	},

	/**
	 * Run an inflöde/ej-kopplat/tilldelnings-åtgärd (koppla|skapa|besvara|
	 * vidarebefordra|gallra|registrera|tilldela|omfordela|gorTillHandling).
	 * Optimistic: removes the resolved rad in demo. In prod sdkmc orchestrates
	 * the atomic register+tag+ACL+Deck+log effect.
	 */
	async inflodeAction(action, payload = {}) {
		// 🔌 SEAM[treserva.skapa]: "Skapa nytt ärende" ur ett inflöde → Treserva-stubben
		// mintar hubsCaseId+dnr och vi visar det FÄRDIGA ärendet överst i Mina ärenden.
		if (action === 'skapa' && (payload.rad || payload.id)) {
			const nytt = await api.skapaArende(payload.rad || payload)
			// Only a payload carrying a real, minted case (hubsCaseId/id) counts as
			// success. A 400/403 returns the engine's error envelope ({ error } /
			// { avvisad, reason }) instead — do NOT unshift it, drop the inflöde-rad
			// or report ok:true (that masked failures behind a success toast).
			if (!nytt || !(nytt.hubsCaseId || nytt.id)) {
				return { ok: false, error: (nytt && (nytt.error || nytt.reason)) || 'skapa_misslyckades' }
			}
			// DEDUP: motorn är idempotent (dubbelklick returnerar SAMMA ärende) —
			// unshifta aldrig ett kort som redan ligger i listan, annars visas
			// samma ärende som två kort tills nästa summary-poll.
			const nyckel = nytt.hubsCaseId || nytt.id
			const finnsRedan = state.arende.arenden.some((a) => (a.hubsCaseId || a.id) === nyckel)
			if (!finnsRedan) {
				state.arende.arenden.unshift(nytt)
			}
			if (payload.id) this.removeInflode(payload.id)
			return { ok: true, arende: nytt }
		}
		const r = await api.inflodeAction(action, payload)
		// These verbs resolve an inflöde-rad out of the band.
		if (payload.id && ['koppla', 'besvara', 'vidarebefordra', 'gallra', 'registrera'].includes(action)) {
			this.removeInflode(payload.id)
		}
		// Fördelning: drop the fördelade ärendet out of "Att fördela".
		if (action === 'tilldela' && payload.ref) {
			state.arende.fordelning.attFordela = state.arende.fordelning.attFordela.filter((a) => (a.dnr || a.triageRef) !== payload.ref)
		}
		return r
	},

	/**
	 * Fas F (Väg A) — koppla ett inflöde-meddelande till ett VALT ärende.
	 * Sätter synliga, user-scopade taggar i mail-klienten (case:{id} → "Ärende {ref}",
	 * + "Behandlad") och skriver referens-fil + pekare i ärendemappen via hubs_arende.
	 * Tar bort raden ur inflödet; returnerar kvittot så vyn kan visa "kopplat" först
	 * på verifierad=true.
	 *
	 * @param {{messageId:(string|number), hubsCaseId:string, radId?:(string|number)}} p
	 */
	async kopplaMeddelandeTillArende({ messageId, hubsCaseId, radId }) {
		const kvitto = await api.kopplaMeddelandeTillArende(messageId, hubsCaseId)
		if (radId) this.removeInflode(radId)
		return kvitto
	},

	/** Initial parallel load of everything the first paint needs. */
	async loadAll() {
		state.loading = true
		await Promise.all([
			this.refreshSummary(),
			this.refreshMeetings(),
			this.refreshReceipts(),
			this.refreshAppointments(),
		])
	},

	// --- Polling -------------------------------------------------------------

	startPolling() {
		this.stopPolling()
		pollTimer = setInterval(() => {
			this.refreshSummary()
			this.refreshMeetings()
		}, POLL_INTERVAL_MS)
	},

	stopPolling() {
		if (pollTimer) {
			clearInterval(pollTimer)
			pollTimer = null
		}
	},

	// --- Mutations / actions -------------------------------------------------

	setActiveChannel(channel) {
		state.activeChannel = channel
	},

	/** Optimistically remove an item (after "Ta ärendet" / "Klart"). */
	removeItem(id) {
		state.items = state.items.filter((i) => i.id !== id)
	},

	async setKeyboardMode(on) {
		state.prefs.keyboardMode = on
		try { await api.savePreferences({ keyboardMode: on }) } catch (e) { /* best effort */ }
	},

	async markOnboardingSeen() {
		state.prefs.onboardingSeen = true
		try { await api.savePreferences({ onboardingSeen: true }) } catch (e) { /* best effort */ }
	},

	/** Generic per-user preference save (e.g. verbEntryDismissed). */
	async savePref(key, value) {
		Vue.set(state.prefs, key, value)
		try { await api.savePreferences({ [key]: value }) } catch (e) { /* best effort */ }
	},
}

export default store
