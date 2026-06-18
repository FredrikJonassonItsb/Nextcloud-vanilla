/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║  🔌 SEAM[favoriter] — KONTAKTER-FAVORITER / SDKMC-RESOLVERLAGER (DEMO-STUB)║
 * ╠══════════════════════════════════════════════════════════════════════════╣
 * ║  VAD DETTA STUBBAR: det tunna sdkmc-resolverlagret ovanpå Kontakter-appen. ║
 * ║   En favorit är en PEKARE (X-HUBS-SDK-REF / X-HUBS-USER-REF), inte en post;║
 * ║   de föränderliga fälten resolvas FÄRSKT mot DIGG (källa till sanning).    ║
 * ║                                                                            ║
 * ║  I PRODUKTION ERSÄTTS DETTA AV: sdkmc OCS-route GET /favoriter som kör     ║
 * ║   IManager::search över personlig + funktions-delad favorit-adressbok      ║
 * ║   (ett anrop, ingen klient-fan-out) och batch-resolvar pekarna mot         ║
 * ║   DIGG-/användarkatalog-cachen → {färska fält, resolvedAt, stale?, removed?}║
 * ║                                                                            ║
 * ║  Tre klasser: (a) SDK-pekare ur DIGG · (b) Hubs-ägd extern fax-vCard ·     ║
 * ║   (c) intern-användar-pekare. MEDBORGAR-PII blockeras (hör i ärendet).     ║
 * ║  Se docs/KONTAKTER-FAVORITER.md + docs/DEMO-STUBS.md → seam "favoriter".   ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * Datan speglar de tre vCard:en som live-seedats i Kontakter-appens "Favoriter"-
 * adressbok (CardDAV) — här returnerade som RESOLVADE DTO:er som vyn konsumerar.
 */

// Resolvade favorit-DTO:er (pekare + färsk-resolvade fält + proveniens).
const FAVORITER = [
	{
		id: 'fav-a-vuxenpsyk',
		klass: 'sdk-pekare',                 // klass (a) — ren DIGG-pekare
		listor: ['mottagningen@'],            // funktions-delad
		namn: 'Vuxenpsykiatrin, mottagning',
		org: 'Region Skåne',
		kanal: 'sdk',
		sdkRef: 'SE2120001234',               // X-HUBS-SDK-REF (pekaren)
		adress: 'sdk://se2120001234/vuxenpsyk-mottagning',  // resolvat färskt ur DIGG
		identitet: { badge: 'SITHS · LOA3', verifierad: true },
		resolvedAt: '2026-06-15T08:00:00',
		stale: false, removed: false,
		proveniens: 'Uppdaterad via DIGG 15/6',
	},
	{
		id: 'fav-b-lindang-fax',
		klass: 'extern-funktion',             // klass (b) — Hubs-ägd fax-post
		listor: ['mottagningen@'],
		namn: 'Lindängsskolan, expedition',
		org: 'Malmö stad / Lindängsskolan',
		kanal: 'fax',
		fax: '+46 40 12 34 56',               // Hubs äger detta värde
		owner: 'funktion:mottagningen@',      // X-HUBS-OWNER (funktion, ej individ)
		identitet: { badge: 'Hubs-förvaltad', verifierad: false },
		resolvedAt: '2026-06-15T08:00:00',
		stale: false, removed: false,
		proveniens: 'Hubs-förvaltad · årlig översyn',
	},
	{
		id: 'fav-c-eva',
		klass: 'intern-anvandare',            // klass (c) — pekare till användarkatalogen
		listor: ['personlig'],
		namn: 'Eva (gruppledare)',
		kanal: 'internal',
		userRef: 'eva',                       // X-HUBS-USER-REF (pekaren)
		identitet: { badge: 'Intern · LOA3', verifierad: true },
		narvaro: 'online',
		resolvedAt: '2026-06-15T08:00:00',
		stale: false, removed: false,
		proveniens: 'intern katalog',
	},
	{
		id: 'fav-d-gammal-mott',
		klass: 'sdk-pekare',
		listor: ['mottagningen@'],
		namn: 'Gamla mottagningen',
		kanal: 'sdk',
		sdkRef: 'SE2120009999',
		// 🔌 SEAM[favoriter.tombstone]: pekaren finns inte längre i DIGG → icke-väljbar.
		stale: true, removed: true,
		proveniens: 'Finns inte längre i DIGG — kan inte användas som mottagare',
	},
]

/**
 * Resolverat favorit-aggregat (personlig ∪ funktions-delad), ett "anrop".
 * @param {object} opts { lista?: 'personlig'|'mottagningen@'|null }
 * @return {object[]} resolvade favorit-DTO:er
 */
export function fetchFavoriter(opts = {}) {
	const lista = opts && opts.lista
	const ut = FAVORITER.filter((f) => !lista || f.listor.includes(lista))
	return ut.map((f) => ({ ...f }))
}

export default { fetchFavoriter }
