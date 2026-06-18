/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║  🔌 SEAM[treserva] — TRESERVA / FRENDS-KONNEKTOR  (DEMO-STUB, EJ PROD)     ║
 * ╠══════════════════════════════════════════════════════════════════════════╣
 * ║  VAD DETTA STUBBAR:                                                        ║
 * ║   • Det kanoniska ärenderegistret `hubs_arenden` (Tables) — hubsCaseId↔dnr ║
 * ║   • Frends iPaaS-konnektorn mot facksystemet Treserva (slutlagring)        ║
 * ║   • sdkmc:s atomära orkestrering "skapa ärende" (register+rum+Deck+klocka)  ║
 * ║                                                                            ║
 * ║  I PRODUKTION ERSÄTTS DETTA AV:                                            ║
 * ║   • `hubs_arenden`-registret i Nextcloud Tables (skrivs ENBART av sdkmc)   ║
 * ║   • OCS-routes i sdkmc: POST /treserva/commit, POST /arende (skapa)         ║
 * ║   • Frends-flöde med VERIFIERAD återkallning (callback) → då, och först då, ║
 * ║     flippas provenans och retention-klockan startar (stänger GAP-007).     ║
 * ║                                                                            ║
 * ║  Se docs/DEMO-STUBS.md → seam "treserva" för hela utbytesbeskrivningen.    ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * Detta är en STATEFUL in-memory-stub: den håller ett litet "Treserva" i minnet
 * så att demon hänger ihop — skapa ärende, committa handling, spegla frist och
 * starta gallring beter sig konsekvent och syns i UI:t. INGEN nätverkstrafik.
 */

// --- minimala självständiga helpers (medvetet duplicerade för att undvika ----
// --- cirkulärt beroende mot socialsekreterare.js) ---------------------------
const pad4 = (n) => String(n).padStart(4, '0')
const isoNow = () => new Date().toISOString()
const addDays = (iso, days) => {
	const d = new Date(iso)
	d.setDate(d.getDate() + days)
	return d.toISOString().slice(0, 10)
}
/** Stabil, deterministisk hubsCaseId ur dnr/triageRef (UUID-ersättning i demon). */
export function caseIdFor(arende) {
	const key = (arende.dnr || arende.triageRef || arende.barnRef || 'okand').replace(/\W+/g, '').toLowerCase()
	return 'hc-' + key
}

// --- registret (motsvarar Tables-registret hubs_arenden) --------------------
/** hubsCaseId -> registerpost. SINGLE SOURCE OF TRUTH för demons "röda tråd". */
const REGISTER = new Map()
/** Kvittenser från verifierade Frends→Treserva-commits (driver kvittens-ytan). */
const RECEIPTS = []
/** Räknare för nya dnr (Treserva delar ut diarienummer vid registrering). */
let dnrSeq = 500

/**
 * 🔌 SEAM[treserva.seed] — seedar registret ur demo-ärendena vid uppstart.
 * I prod finns registret redan i Tables; ingen seed behövs.
 * @param {object[]} arenden demo-ärenden (socialsekreterare.js)
 */
export function seedRegister(arenden) {
	let seededReceipts = 0
	for (const a of arenden) {
		const hubsCaseId = caseIdFor(a)
		a.hubsCaseId = hubsCaseId
		// Seeda ett par historiska kvittenser så kvittens-/retention-ytan har innehåll
		// från start (de speglar redan-registrerade ärenden).
		if (a.dnr && a.provenance && a.provenance.state === 'registrerad' && seededReceipts < 3) {
			RECEIPTS.push({
				id: 'kv-seed-' + (++seededReceipts),
				hubsCaseId, dnr: a.dnr, barnRef: a.barnRef,
				typ: a.steg === 'uppfoljning' ? 'beslut' : 'utredning',
				committedAt: a.frist && a.frist.start ? a.frist.start + 'T09:30:00' : isoNow(),
				gallrasDatum: (a.provenance && a.provenance.gallrasDatum) || null,
				verifierad: true, kalla: 'Frends → Treserva (stub)',
			})
		}
		REGISTER.set(hubsCaseId, {
			hubsCaseId,
			dnr: a.dnr || null,
			barnRef: a.barnRef,
			steg: a.steg,
			conversationIds: a.triageRef ? [a.triageRef] : [],
			// Två-vägs-pekare till objekt (Deck-kort, ärenderum, chattrum) — stubbade id:n.
			deckBoardId: 2,
			deckCardId: hubsCaseId + '-card',
			talkToken: 'demo' + hubsCaseId.slice(-6),
			groupfolderId: (a.rum && a.rum.groupfolderId) || 0,
			provenance: a.provenance || { state: 'ej_registrerad' },
			retention: a.provenance && a.provenance.gallrasDatum
				? { state: 'gallras_efter_commit', gallrasDatum: a.provenance.gallrasDatum, verifierad: true }
				: { state: 'ej_startad', gallrasDatum: null, verifierad: false },
			handlingar: [],
		})
	}
}

/** Hämta en registerpost (pekar-uppslag för UI/övriga stubbar). */
export function getEntry(hubsCaseId) {
	return REGISTER.get(hubsCaseId) || null
}

/**
 * 🔌 SEAM[treserva.skapa] — atomär "skapa ärende"-orkestrering.
 * Mintar hubsCaseId, ber Treserva om dnr, skapar ärenderum/Deck/chattrum (stubb),
 * startar 14-dagarsklockan bunden till inkom-datum. Returnerar ett FÄRDIGT
 * ärende-objekt (samma shape som socialsekreterare.js) redo för "Mina ärenden".
 * PROD: ett enda sdkmc-OCS-anrop som gör allt detta i en transaktion (GAP-010).
 * @param {object} rad InflodeRad (triage) som blir ärende
 * @return {object} nytt ärende
 */
export function skapaArende(rad) {
	const barnRef = (rad.koppling && rad.koppling.barnRef) || rad.titel || ('Barn 2026-' + pad4(dnrSeq))
	const triageRef = rad.triageRef || ('SN 2026-' + pad4(dnrSeq))
	const dnr = '2026-IFO-' + pad4(++dnrSeq)
	const inkom = rad.inkomDatum || isoNow()
	const startDag = inkom.slice(0, 10)
	const arende = {
		dnr,
		triageRef,
		barnRef,
		hubsCaseId: 'hc-' + dnr.replace(/\W+/g, '').toLowerCase(),
		steg: 'forhandsbedomning',
		substeg: null,
		sekretess: { kod: 'OSL 26 kap.', skyddadeUppgifter: false },
		loa: 'LOA3',
		// 14-dgr-klockan bunden till INKOM-datum (ej plock) — speglad ur Treserva via Frends.
		frist: {
			typ: 'forhandsbedomning', label: 'Förhandsbedömning',
			due: addDays(startDag, 14), start: startDag, daysLeft: 14, tone: 'neutral',
			kalla: 'Inkom ' + startDag, agare: 'Treserva (speglad via Frends)', paminnelser: [],
		},
		provenance: { state: 'registrerad', dnr, gallrasDatum: null, bevarasDatum: null },
		plikt: { typ: 'skyddsbedomning', label: 'Skyddsbedömning krävs idag — måste committas', kvitterad: false },
		nastaAtgard: { action: 'skyddsbedomning', label: 'Dokumentera & committa skyddsbedömning', flik: 'beslut', vantarPaMig: true },
		vantar: null,
		tilldelning: { status: 'tilldelat', agareUid: 'anna', agareNamn: 'Anna', fran: 'mottagning', tilldeladAv: 'Eva', tilldeladDatum: startDag, nyFor24h: true },
		diskussion: { olasta: 0, omnamnandeTillMig: false },
		rum: { groupfolderId: 0, olasta: 1, acl: 'du skriver', dokument: ['Orosanmälan (' + (rad.channel && rad.channel.channelLabel || 'inkom') + ')'] },
		meddelanden: [], moten: [], bevakningar: [{ titel: 'Skyddsbedömning + beslut inom 14 dgr', frist: addDays(startDag, 14), delad: true }], beslut: null,
	}
	REGISTER.set(arende.hubsCaseId, {
		hubsCaseId: arende.hubsCaseId, dnr, barnRef, steg: 'forhandsbedomning',
		conversationIds: [triageRef], deckBoardId: 2, deckCardId: arende.hubsCaseId + '-card',
		talkToken: 'demo' + arende.hubsCaseId.slice(-6), groupfolderId: 0,
		provenance: arende.provenance,
		retention: { state: 'ej_startad', gallrasDatum: null, verifierad: false },
		handlingar: [{ typ: 'aktualisering', tid: isoNow(), kalla: 'orosanmälan' }],
	})
	return arende
}

/**
 * 🔌 SEAM[treserva.commit] — committa en handling till Treserva via Frends.
 * I demon: registrerar handlingen, returnerar en VERIFIERAD callback och startar
 * retention-klockan FÖRST på den verifierade callbacken (inte på en kryssruta).
 * PROD: Frends-flöde POST → Treserva → verifierad återkallning; sdkmc flippar
 * provenans + sätter retention-tagg först då (stänger GAP-007 / GAP-019).
 * @param {object} payload { arende, typ, artefakter }
 * @return {object} verifierat kvitto
 */
export function commitHandling(payload) {
	const arende = (payload && payload.arende) || {}
	const hubsCaseId = arende.hubsCaseId || caseIdFor(arende)
	const committedAt = isoNow()
	let dnr = arende.dnr
	const entry = REGISTER.get(hubsCaseId)
	// Treserva delar ut dnr vid första registreringen om det saknas.
	if (!dnr) {
		dnr = '2026-IFO-' + pad4(++dnrSeq)
	}
	const gallrasDatum = addDays(committedAt, 90)
	const handling = { typ: (payload && payload.typ) || 'handling', tid: committedAt, dnr, verifierad: true }
	if (entry) {
		entry.dnr = dnr
		entry.provenance = { state: 'registrerad', dnr, gallrasDatum, bevarasDatum: entry.provenance && entry.provenance.bevarasDatum }
		// Retention startar ENBART på verifierad commit (GAP-007-mönstret, i stub-form).
		entry.retention = { state: 'gallras_efter_commit', gallrasDatum, verifierad: true, startadAv: 'verifierad Frends-callback', committedAt }
		entry.handlingar.push(handling)
	}
	const receipt = {
		id: 'kv-' + (RECEIPTS.length + 1),
		hubsCaseId, dnr, barnRef: arende.barnRef,
		typ: handling.typ, committedAt, gallrasDatum,
		verifierad: true, kalla: 'Frends → Treserva (stub)',
	}
	RECEIPTS.unshift(receipt)
	return { ok: true, dnr, committedAt, gallrasDatum, verifierad: true, hubsCaseId, receipt }
}

/** Lista verifierade Treserva-kvittenser (driver kvittens-/retention-ytan). */
export function listReceipts() {
	return RECEIPTS.map((r) => ({ ...r }))
}

/**
 * 🔌 SEAM[treserva.koppla] — koppla ett inflöde till ett befintligt ärende.
 * Demon: speglar bilagan in i ärenderummet + noterar handling. PROD: sdkmc
 * sätter systemtaggen case:{hubsCaseId} på objektet + speglar fil till rummet.
 */
export function kopplaInflode(rad, targetHubsCaseId) {
	const entry = REGISTER.get(targetHubsCaseId)
	if (entry) {
		entry.handlingar.push({ typ: 'inkommande', tid: isoNow(), titel: rad.titel })
	}
	return { ok: true, hubsCaseId: targetHubsCaseId }
}

/** Endast för demo-introspektion (dev-overlay / tester). */
export function _dumpRegister() {
	return [...REGISTER.values()].map((e) => ({ ...e }))
}
