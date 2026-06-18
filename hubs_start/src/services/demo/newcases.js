/**
 * Demo fixture descriptors — the missing use cases the customer asked for.
 *
 *  - todolista        : socialtjänst-todo (Deck-backed). Personal + shared tasks
 *                       tied to ärenden, deadline-bearing. Hubs stages the task;
 *                       the formal bevakning is committed into Treserva/Lifecare.
 *  - motesanteckningar: meeting transcription + LOCAL AI summary (record →
 *                       KB-Whisper → llm2 → human-in-the-loop "Godkänn" → save to
 *                       ärende). Raw recording is transient and gallras.
 *
 * Static Swedish fixtures; `queue` variant (see DEMO-WIDGETS-CONTRACT.md).
 */
export default {
	todolista: {
		variant: 'queue',
		headerStat: { value: 4, label: 'egna uppgifter · 2 förfaller denna vecka', tone: 'info' },
		rows: [
			{
				id: 'td1',
				title: 'Ring vårdnadshavare inför utredningsstart',
				subtitle: 'Dnr SN 2026-0142 · skapad från SDK-meddelande',
				status: { label: 'Att göra', tone: 'info' },
				deadline: { label: 'Idag 15:00', tone: 'warning' },
				badges: [{ label: 'Bevakning', tone: 'neutral', icon: 'BellRing' }],
				primaryAction: { label: 'Öppna i Deck' },
			},
			{
				id: 'td2',
				title: 'Begär komplettering — inkomstuppgifter',
				subtitle: 'Dnr SN 2026-0131 · ekonomiskt bistånd',
				status: { label: 'Pågående', tone: 'neutral' },
				deadline: { label: '2 dagar kvar', tone: 'warning' },
				primaryAction: { label: 'Öppna i Deck' },
			},
			{
				id: 'td3',
				title: 'Skriv utredning klar för granskning',
				subtitle: 'Dnr SN 2026-0140 · BBIC',
				status: { label: 'Pågående', tone: 'neutral' },
				deadline: { label: '4-månadersfrist: 18 dagar', tone: 'neutral' },
				badges: [{ label: 'Delad med enheten', tone: 'info', icon: 'AccountGroup' }],
				primaryAction: { label: 'Öppna i Deck' },
			},
			{
				id: 'td4',
				title: 'Boka uppföljande SIP',
				subtitle: 'Dnr SN 2026-0128',
				status: { label: 'Att göra', tone: 'info' },
				deadline: { label: 'Nästa vecka', tone: 'neutral' },
				primaryAction: { label: 'Öppna i Deck' },
			},
		],
	},

	motesanteckningar: {
		variant: 'queue',
		headerStat: { value: 'Lokalt', label: 'transkribering & sammanfattning körs i er driftmiljö', tone: 'success' },
		rows: [
			{
				id: 'mn1',
				title: 'SIP-möte hemtjänst — sammanfattning klar',
				subtitle: 'Idag 13:30 · KB-Whisper + lokal AI (llm2)',
				status: { label: 'Väntar på godkännande', tone: 'warning' },
				badges: [
					{ label: 'Transkriberat', tone: 'success', icon: 'TextBoxOutline' },
					{ label: 'Rådata gallras', tone: 'neutral', icon: 'TimerSand' },
				],
				primaryAction: { label: 'Granska & godkänn' },
			},
			{
				id: 'mn2',
				title: 'Nämndberedning — protokollsunderlag',
				subtitle: 'Igår 09:00 · ej sekretess',
				status: { label: 'Godkänd & sparad', tone: 'success' },
				badges: [{ label: 'Sparad till ärende', tone: 'info', icon: 'FileCheck' }],
			},
			{
				id: 'mn3',
				title: 'Rehab-avstämning — sammanfattning klar',
				subtitle: 'Idag 10:15 · samtycke inhämtat',
				status: { label: 'Väntar på godkännande', tone: 'warning' },
				badges: [{ label: 'Human-in-the-loop', tone: 'neutral', icon: 'AccountSupervisor' }],
				primaryAction: { label: 'Granska & godkänn' },
			},
			{
				id: 'mn4',
				title: 'Möte med medborgare — transkribering pausad',
				subtitle: 'Sekretessbelagt · inväntar IMY/SKR-vägledning',
				status: { label: 'Ej aktiverad', tone: 'neutral' },
				badges: [{ label: 'Endast dokumenterad', tone: 'neutral', icon: 'AlertOutline' }],
			},
		],
	},
}
