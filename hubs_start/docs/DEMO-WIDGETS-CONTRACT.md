# Demo widget descriptors — contract

The persona dashboards render 37 widgets. 8 are real components fed by the live
demo summary. The other 29 render through **4 flexible presentational variants**
driven by **demo descriptors** (rich Swedish fixtures). This file is the contract
for those descriptors and the variant components.

## Tone enum (everywhere `tone` appears)
`'info' | 'neutral' | 'warning' | 'error' | 'success'` → maps to `--hs-status-*`.

## Channel enum (optional `channel` on queue rows)
`'sdk' | 'secure' | 'internal' | 'fax' | 'sms'` → rendered via `channelMeta()`.

## Icon fields
`icon` values are **vue-material-design-icons** component names (e.g. `'ClockAlert'`,
`'FileSign'`). The variant components import a curated icon set; unknown names fall
back to a generic dot — so prefer common MDI names.

## The 4 variants & their descriptor shapes

### `queue` — a list of action rows (most common)
```js
{
  variant: 'queue',
  emptyText: 'Inget att hantera',           // optional
  headerStat: { label, value, tone },        // optional one-line summary under title
  rows: [{
    id: 'q1',
    title: 'Besvara orosanmälan',            // verb-first, anonymised (no PII)
    subtitle: 'Socialkontoret · inkom idag', // optional
    status: { label: 'Ny', tone: 'info' },   // optional
    deadline: { label: '3 dagar kvar', tone: 'warning' }, // optional
    badges: [{ label: 'LOA3', tone: 'success', icon: 'ShieldCheck' }], // optional
    channel: 'sdk',                          // optional → channel chip
    primaryAction: { label: 'Ta ärendet' },  // optional → demo shows a notice
  }],
}
```

### `progress` — a campaign / progress card
```js
{
  variant: 'progress',
  headline: { value: 312, total: 540, unit: 'granskade', caption: 'Årsräkningar 2026' },
  deadline: { label: '18 dagar till 1 mars', tone: 'warning' }, // optional
  breakdown: [                                // optional status rows with mini-bars
    { label: 'Ej påbörjade', count: 181, tone: 'neutral' },
    { label: 'Väntar på komplettering', count: 47, tone: 'warning' },
  ],
  note: 'Förtur: 12 förstagångsredovisare',   // optional
}
```

### `stat` — KPI tiles / checklist / statements (compliance, frister, ID)
```js
{
  variant: 'stat',
  overallTone: 'success',                     // optional → card accent
  tiles: [{ value: '100 %', label: 'MFA-täckning', tone: 'success' }], // optional
  checks: [{ label: 'Anmäld till MCF', ok: true, detail: '2026-01-14' }], // optional
  statements: ['All data i er driftmiljö', '0 tredjelandsöverföringar'],  // optional
  note: 'Mappad mot Infosäkkollen nivå 3',    // optional
}
```
(Used for: fristStrip = tiles with deadline tone; complianceStatus = checks +
overallTone; authLoa = tiles; dataSuveranitet = statements; loggSparbarhet = tiles
+ a static search line.)

### `files` — document / ärenderum rows
```js
{
  variant: 'files',
  emptyText: 'Inga filer',                    // optional
  rows: [{
    id: 'f1',
    name: 'Utredning SN 2026-0142',
    meta: 'Ärenderum · 3 olästa · delad med vårdnadshavare', // optional
    status: { label: 'Väntar på signatur', tone: 'warning' }, // optional
    deadline: { label: 'Gallras 2031', tone: 'neutral' },     // optional
    badges: [{ label: 'Medborgardelad', tone: 'info', icon: 'AccountShare' }], // optional
  }],
}
```

## Variant component prop contract (built separately)
Each variant component is `props: { title: String, descriptor: Object }`, renders a
`.hs-card` with the title + a `LeadIcon` slot, and emits `@action(payload)` when a
row's `primaryAction` (or a campaign CTA) is clicked. All strings via
`t('hubs_start', …)`; brand rule applies; targets ≥24px; WCAG 2.2.

## Per-widget variant assignment (the 29 demo-rendered widgets)
The descriptor module (`src/services/demoWidgets.js` + `src/services/demo/*.js`)
must provide one descriptor per id below, grounded in the matching
`analysis-output/extended/persona-*.md` and `research-*.md`.

**queue:** orosanmalningar, utskrivningsbevakning, samverkansavvikelser,
granskningsko, rehabarenden, kansligInkorg, minaUppgifter, attSignera,
skickatForSignering, incidentrapporter, sakerhetshandelser, registreraFordela,
utlamnande, uppdragskontroll, provisionering, justeringAnslag, mallarSamtycke

**progress:** arsrakningar, namndcykel, complianceStatus

**stat:** fristStrip, authLoa, dataSuveranitet, loggSparbarhet

**files:** arenderum, senasteFiler, arkivGallring, kunskapsbank

**already real (do NOT make descriptors):** attHantera, dagensMoten, kvittenser,
funktionsbrevlador, bevakningar, bokningsbaraTider, nytta, systemhalsa

Make the content realistic and persona-coherent (e.g. orosanmalningar shows
14-day förhandsbedömning countdowns; utskrivningsbevakning shows betalningsansvar
dygnsräknare; arsrakningar progress toward 1 mars; incidentrapporter shows MCF
24h/72h/1mån clocks; attSignera shows AES/QES badges). Reuse the same widget id's
descriptor across personas (it's one catalog).
