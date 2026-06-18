/**
 * Hubs Start — DEMO FIXTURE DATA (files variant).
 *
 * ⚠️ STATIC DEMO ONLY. Plain JS descriptors for the four `files`-variant demo
 * widgets, consumed by the presentational FilesCard component. No imports, no Vue,
 * no functions, no Date.now — these are inert fixtures the component translates and
 * renders. Shapes follow docs/DEMO-WIDGETS-CONTRACT.md exactly.
 *
 * Grounded in:
 *   analysis-output/extended/persona-socialsekreterare.md (ärenderum per dnr/barn,
 *     14-dgr förhandsbedömning, 4-mån utredning, BBIC, medborgardelning, gallring)
 *   analysis-output/extended/persona-registrator.md (avslutade ärenden, gallring per
 *     handlingstyp, Bevaras/Gallras, FGS-leverans till e-arkiv/Sydarkivera)
 *   analysis-output/extended/research-filer.md ("Senaste säkra filer" + "Ärenderum"
 *     widgetinnehåll, säker medborgardelning, versioner, signering, kunskapsbank)
 *
 * Brand rule: never the underlying platform/app names — "ärenderum", "säker
 * dokumentyta", "säkra meddelanden". Content anonymised: dnr + role labels only.
 */

export default {
	// W4 (soc) / "Ärenderum" — översikt över öppna barn-/familjeärenden som säkra
	// dokumentytor per dnr: olästa dokument, väntar-på-signatur, gallrings-countdown,
	// medborgardelning. Status ur GOV.UK-uppsättningen (Påbörjad / Väntar på motpart /
	// Klar för beslut).
	arenderum: {
		variant: 'files',
		emptyText: 'Inga öppna ärenderum',
		rows: [
			{
				id: 'rum-0142',
				name: 'Ärenderum SN 2026-0142 · barn & familj',
				meta: 'Förhandsbedömning · 3 olästa dokument · delat med vårdnadshavare',
				status: { label: 'Påbörjad', tone: 'info' },
				deadline: { label: '14-dgr frist: 4 dagar kvar', tone: 'warning' },
				badges: [
					{ label: 'Medborgardelad', tone: 'info', icon: 'AccountShare' },
					{ label: 'LOA3', tone: 'success', icon: 'ShieldCheck' },
				],
			},
			{
				id: 'rum-0098',
				name: 'Ärenderum SN 2026-0098 · BBIC-utredning',
				meta: 'Utredning · samredigeras on-prem · 1 oläst från region',
				status: { label: 'Väntar på motpart', tone: 'warning' },
				deadline: { label: '4-mån utredning: 23 dagar kvar', tone: 'warning' },
				badges: [
					{ label: '2 versioner idag', tone: 'neutral', icon: 'History' },
				],
			},
			{
				id: 'rum-0071',
				name: 'Ärenderum SN 2026-0071 · insatsbeslut',
				meta: 'Beslut framtaget · väntar på handläggarens underskrift',
				status: { label: 'Klar för beslut', tone: 'info' },
				deadline: { label: 'Signera senast 16/6', tone: 'warning' },
				badges: [
					{ label: 'Väntar på signatur', tone: 'warning', icon: 'FileSign' },
					{ label: 'AES', tone: 'neutral', icon: 'DrawPen' },
				],
			},
			{
				id: 'rum-0054',
				name: 'Ärenderum SN 2026-0054 · uppföljning insats',
				meta: 'Tidsbegränsat beslut upphör 30/6 · 1 oläst komplettering',
				status: { label: 'Påbörjad', tone: 'info' },
				deadline: { label: 'Följ upp: 17 dagar kvar', tone: 'neutral' },
				badges: [
					{ label: 'Medborgardelad', tone: 'info', icon: 'AccountShare' },
				],
			},
			{
				id: 'rum-0033',
				name: 'Ärenderum SN 2026-0033 · SIP-vårdplan',
				meta: 'Samverkan skola/region · samtycke inhämtat · inga olästa',
				status: { label: 'Påbörjad', tone: 'info' },
				deadline: { label: 'Gallras 2031', tone: 'neutral' },
				badges: [
					{ label: 'HSLF-FS 2016:40', tone: 'success', icon: 'LockCheck' },
				],
			},
			{
				id: 'rum-0019',
				name: 'Ärenderum SN 2025-0461 · avslutas',
				meta: 'Beslut delgivet och läst · klart för arkivering',
				status: { label: 'Klar', tone: 'success' },
				deadline: { label: 'Gallras 2031 (handlingstyp 4.1)', tone: 'neutral' },
				badges: [
					{ label: 'Klar för e-arkiv', tone: 'neutral', icon: 'ArchiveArrowDown' },
				],
			},
		],
	},

	// W82 (research "Senaste säkra filer") — vad har hänt med mina dokument senast:
	// delad med medborgare / ny version / väntar på granskning / signerad / uppladdad
	// av motpart. Ärende- och sekretessmedveten, dnr som kontext, säker-kanal-markör.
	senasteFiler: {
		variant: 'files',
		emptyText: 'Inga dokument väntar på åtgärd',
		rows: [
			{
				id: 'fil-1',
				name: 'Beslut förhandsbedömning · SN 2026-0142',
				meta: 'Ärenderum SN 2026-0142 · delad med vårdnadshavare 09:12',
				status: { label: 'Delad med medborgare', tone: 'info' },
				badges: [
					{ label: 'Väntar läskvittens', tone: 'neutral', icon: 'EmailCheck' },
					{ label: 'På er server', tone: 'success', icon: 'ServerSecurity' },
				],
			},
			{
				id: 'fil-2',
				name: 'Utredning BBIC · SN 2026-0098',
				meta: 'Ärenderum SN 2026-0098 · ny version av kollega 08:47',
				status: { label: 'Ny version', tone: 'info' },
				badges: [
					{ label: 'Granska ändring', tone: 'neutral', icon: 'History' },
				],
			},
			{
				id: 'fil-3',
				name: 'Insatsbeslut · SN 2026-0071',
				meta: 'Ärenderum SN 2026-0071 · ditt godkännande krävs före utskick',
				status: { label: 'Väntar på granskning', tone: 'warning' },
				deadline: { label: 'Granska idag', tone: 'warning' },
				badges: [
					{ label: 'AES', tone: 'neutral', icon: 'DrawPen' },
				],
			},
			{
				id: 'fil-4',
				name: 'Samtyckesblankett SIP · SN 2026-0033',
				meta: 'Ärenderum SN 2026-0033 · signerad med BankID 08:21',
				status: { label: 'Signerad', tone: 'success' },
				badges: [
					{ label: 'PAdES/PDF-A', tone: 'success', icon: 'FileCertificate' },
				],
			},
			{
				id: 'fil-5',
				name: 'Komplettering från skola · SN 2026-0054',
				meta: 'Ärenderum SN 2026-0054 · uppladdad av motpart 07:58',
				status: { label: 'Uppladdad av motpart', tone: 'info' },
				badges: [
					{ label: 'Oläst', tone: 'warning', icon: 'FileAlert' },
					{ label: 'Skapa bevakning', tone: 'neutral', icon: 'BellPlus' },
				],
			},
			{
				id: 'fil-6',
				name: 'Läkarintyg · SN 2026-0098',
				meta: 'Ärenderum SN 2026-0098 · uppladdat av motpart (region) igår',
				status: { label: 'Uppladdad av motpart', tone: 'info' },
				badges: [
					{ label: 'HSLF-FS 2016:40', tone: 'success', icon: 'LockCheck' },
				],
			},
		],
	},

	// W8 (reg) "Arkiv & leverans" — avslutade ärenden med gallringsstatus
	// (Gallras 2031 enligt handlingstyp / Bevaras), notis innan radering, och
	// "Leverera till e-arkiv (FGS)" till Sydarkivera/e-arkiv.
	arkivGallring: {
		variant: 'files',
		emptyText: 'Inga avslutade ärenden att leverera',
		rows: [
			{
				id: 'ark-1',
				name: 'Ärende KS 2024-0211 · bygglovsremiss',
				meta: 'Avslutat · komplett · klart att paketera enligt FGS',
				status: { label: 'Klar för e-arkiv', tone: 'info' },
				deadline: { label: 'Leverera till e-arkiv (FGS)', tone: 'info' },
				badges: [
					{ label: 'Bevaras', tone: 'success', icon: 'ArchiveCheck' },
					{ label: 'Sydarkivera', tone: 'neutral', icon: 'DatabaseArrowUp' },
				],
			},
			{
				id: 'ark-2',
				name: 'Ärende SN 2025-0461 · avslutad insats',
				meta: 'Avslutat · handlingstyp 4.1 social dokumentation',
				status: { label: 'Avslutad', tone: 'neutral' },
				deadline: { label: 'Gallras 2031-12-31', tone: 'neutral' },
				badges: [
					{ label: 'Gallras enligt plan', tone: 'warning', icon: 'DeleteClock' },
				],
			},
			{
				id: 'ark-3',
				name: 'Ärende MN 2024-0087 · tillsynsbeslut',
				meta: 'Avslutat · laga kraft · diarieförda handlingar kompletta',
				status: { label: 'Laga kraft', tone: 'success' },
				deadline: { label: 'Bevaras', tone: 'success' },
				badges: [
					{ label: 'Bevaras', tone: 'success', icon: 'ArchiveCheck' },
				],
			},
			{
				id: 'ark-4',
				name: 'Ärende KS 2020-0142 · upphandlingsunderlag',
				meta: 'Avslutat · gallringsfrist passeras snart · ägarnotis skickad',
				status: { label: 'Åtgärd krävs', tone: 'warning' },
				deadline: { label: 'Raderas om 12 dagar', tone: 'error' },
				badges: [
					{ label: 'Gallras 2026-06-25', tone: 'error', icon: 'DeleteAlert' },
				],
			},
			{
				id: 'ark-5',
				name: 'Ärende SN 2024-0309 · överklagat beslut',
				meta: 'Avslutat · överklagandetid ute · klart för leverans',
				status: { label: 'Klar för e-arkiv', tone: 'info' },
				deadline: { label: 'Leverera till e-arkiv (FGS)', tone: 'info' },
				badges: [
					{ label: 'Bevaras', tone: 'success', icon: 'ArchiveCheck' },
					{ label: 'FGS-paket klart', tone: 'neutral', icon: 'PackageVariantClosed' },
				],
			},
			{
				id: 'ark-6',
				name: 'Ärende BUN 2023-0518 · elevärende',
				meta: 'Avslutat · handlingstyp med tidsbegränsad gallring',
				status: { label: 'Avslutad', tone: 'neutral' },
				deadline: { label: 'Gallras 2028-08-31', tone: 'neutral' },
				badges: [
					{ label: 'Gallras enligt plan', tone: 'warning', icon: 'DeleteClock' },
				],
			},
		],
	},

	// W8 (soc) "Kunskapsbank & mallar" — rutiner, BBIC-mallar, rehab-/granskningsmallar,
	// gallringsplaner, samtyckesmallar. On-prem wiki som minskar dokumentationsfriktion.
	kunskapsbank: {
		variant: 'files',
		emptyText: 'Inga mallar tillgängliga',
		rows: [
			{
				id: 'kb-1',
				name: 'Rutin: Så skapar du en orosanmälan-yta',
				meta: 'Kunskapsbank · steg-för-steg · uppdaterad 2026-05-30',
				badges: [
					{ label: 'Rutin', tone: 'info', icon: 'BookOpenVariant' },
				],
			},
			{
				id: 'kb-2',
				name: 'BBIC-mall: Förhandsbedömning & utredning',
				meta: 'Kunskapsbank · dokumentmall för ärenderum',
				badges: [
					{ label: 'Mall', tone: 'neutral', icon: 'FileDocumentOutline' },
				],
			},
			{
				id: 'kb-3',
				name: 'Rehabmall: Plan för återgång i arbete',
				meta: 'Kunskapsbank · personalärende · samtyckessteg inkluderat',
				badges: [
					{ label: 'Mall', tone: 'neutral', icon: 'FileDocumentOutline' },
				],
			},
			{
				id: 'kb-4',
				name: 'Granskningsmall: Sekretessprövning vid utlämnande',
				meta: 'Kunskapsbank · checklista enligt OSL · maskering',
				badges: [
					{ label: 'Checklista', tone: 'neutral', icon: 'FormatListChecks' },
				],
			},
			{
				id: 'kb-5',
				name: 'Gallringsplan: Handlingstyper & frister',
				meta: 'Kunskapsbank · dokumenthanteringsplan · Bevaras/Gallras',
				badges: [
					{ label: 'Gallringsplan', tone: 'warning', icon: 'CalendarClock' },
				],
			},
			{
				id: 'kb-6',
				name: 'Samtyckesmall: SIP & samverkansmöte',
				meta: 'Kunskapsbank · för signeringssteg · BankID-loggad',
				badges: [
					{ label: 'Mall', tone: 'neutral', icon: 'FileDocumentOutline' },
				],
			},
			{
				id: 'kb-7',
				name: 'Lathund: Säker medborgardelning med BankID',
				meta: 'Kunskapsbank · dela utvalda dokument · läskvittens',
				badges: [
					{ label: 'Lathund', tone: 'success', icon: 'LightbulbOnOutline' },
				],
			},
		],
	},
}
