/**
 * Demo fixture descriptors — supplementary widgets.
 *
 * `provisionering` (queue) — förvaltare/CISO: provisioneringskö för konton och
 * funktionsadress-medlemskap. Each access change is logged as an åtkomsthändelse.
 * Static Swedish fixtures; matches the `queue` shape in DEMO-WIDGETS-CONTRACT.md.
 */
export default {
	provisionering: {
		variant: 'queue',
		headerStat: { value: 4, label: 'väntar på åtgärd · loggas som åtkomsthändelse', tone: 'info' },
		rows: [
			{
				id: 'prov1',
				title: 'Lägg till i funktionsadress — ny socialsekreterare',
				subtitle: 'Anställd 2026-06-09 · grupp socialtjanst',
				status: { label: 'Att etablera', tone: 'info' },
				badges: [{ label: 'orosanmalan@', tone: 'neutral', icon: 'AccountGroup' }],
				primaryAction: { label: 'Lägg till' },
			},
			{
				id: 'prov2',
				title: 'Avetablera — avslutad anställning',
				subtitle: 'Slutdatum 2026-05-31 · konto fortfarande aktivt',
				status: { label: 'Brådskande', tone: 'error' },
				deadline: { label: '9 dagar försenat', tone: 'error' },
				badges: [{ label: 'Behörig till 3 funktionsadresser', tone: 'warning', icon: 'AccountKey' }],
				primaryAction: { label: 'Avetablera' },
			},
			{
				id: 'prov3',
				title: 'Granska överbehörighet — handläggare',
				subtitle: 'Medlem i 7 funktionsadresser (snitt 2)',
				status: { label: 'Avvikelse', tone: 'warning' },
				badges: [{ label: 'Minsta behörighet', tone: 'neutral', icon: 'ShieldLock' }],
				primaryAction: { label: 'Granska' },
			},
			{
				id: 'prov4',
				title: 'Vilande konto — ingen inloggning 90 dagar',
				subtitle: 'Senast aktiv 2026-03-12',
				status: { label: 'Vilande', tone: 'neutral' },
				primaryAction: { label: 'Spärra' },
			},
		],
	},
}
