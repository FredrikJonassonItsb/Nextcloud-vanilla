// Demo fixture data — `progress` variant descriptors.
// Static Swedish public-sector fixtures rendered by the flexible "progress"
// presentational component. No imports, no functions, no Date — plain data.
// See docs/DEMO-WIDGETS-CONTRACT.md for the descriptor shape.

export default {
  // Överförmyndarhandläggare — den deadline-låsta toppen runt 1 mars.
  // FB 14:15 — årsräkning före 1 mars; granskningsmål 80 % per 30 juni.
  arsrakningar: {
    variant: 'progress',
    headline: {
      value: 312,
      total: 540,
      unit: 'granskade',
      caption: 'Årsräkningar 2026 — granskningsläget',
    },
    deadline: { label: '18 dagar till 1 mars', tone: 'warning' },
    breakdown: [
      { label: 'Ej påbörjade', count: 145, tone: 'neutral' },
      { label: 'Under granskning', count: 36, tone: 'info' },
      { label: 'Komplettering begärd', count: 47, tone: 'warning' },
      { label: 'Klar för arvode', count: 29, tone: 'info' },
      { label: 'Färdiggranskade', count: 283, tone: 'success' },
    ],
    note: 'Förtur: 12 förstagångsredovisare · 8 tidigare anmärkta',
  },

  // Registrator / nämndsekreterare — beslutskedjan mot kommande sammanträde
  // som GOV.UK task-list (kallelse → handlingar → justering → anslag).
  namndcykel: {
    variant: 'progress',
    headline: {
      value: 4,
      total: 7,
      unit: 'steg klara',
      caption: 'Kommunstyrelsen 18 juni — sammanträdescykel',
    },
    deadline: { label: 'Kallelse senast 11 juni (T-2 dgr)', tone: 'warning' },
    breakdown: [
      { label: 'Ärenden med komplett underlag', count: 19, tone: 'success' },
      { label: 'Saknar beslutsunderlag', count: 3, tone: 'warning' },
      { label: 'Kallelse skickad till förtroendevalda', count: 22, tone: 'success' },
      { label: 'Handlingar delade säkert', count: 22, tone: 'success' },
      { label: 'Protokoll att justera (förra mötet)', count: 1, tone: 'error' },
    ],
    note: 'Dnr KS 2026-0418 saknar tjänsteskrivelse — handläggare påmind',
  },

  // Förvaltare / informationssäkerhet — sammanvägd efterlevnad mot
  // cybersäkerhetslagen (2025:1506), mappad mot Infosäkkollen nivå 3.
  // Breakdown = kravområden grön (success) / gul (warning) / röd (error).
  complianceStatus: {
    variant: 'progress',
    headline: {
      value: 4,
      total: 6,
      unit: 'kravområden gröna',
      caption: 'Efterlevnad — cybersäkerhetslagen',
    },
    deadline: { label: 'Ledningsgenomgång inom 19 dagar (T-19)', tone: 'warning' },
    breakdown: [
      { label: 'Anmäld till MCF', count: 1, tone: 'success' },
      { label: 'Loggretention 12/12 mån, sökbar', count: 1, tone: 'success' },
      { label: 'Data i egen driftmiljö · 0 tredjelandsöverföringar', count: 1, tone: 'success' },
      { label: 'MFA / LOA3-täckning', count: 1, tone: 'success' },
      { label: 'Incidentrutin aktiv (MCF-rapportkedja)', count: 1, tone: 'warning' },
      { label: 'Ledningsgenomgång daterad', count: 1, tone: 'error' },
    ],
    note: 'Sammanvägd status: gul — 2 kravområden återstår mot Infosäkkollen nivå 3',
  },
}
