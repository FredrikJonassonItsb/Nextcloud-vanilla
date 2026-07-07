#!/usr/bin/env node
/**
 * Genererar ANALYS-FORIFYLLNAD-BILAGA.md ur mallanalysernas JSON
 * (mallanalyser-del1.json + ev. mallanalyser-del2.json).
 * Deterministisk — kör om när fler mallar analyserats.
 *
 * Användning: node generate-bilaga.js
 */
const fs = require('fs')
const path = require('path')

const DIR = __dirname
const OUT = path.resolve(DIR, '../../hubs_start/docs/ANALYS-FORIFYLLNAD-BILAGA.md')

// Läs alla delar, dedupe per mall (senare fil vinner)
const byMall = new Map()
for (const f of fs.readdirSync(DIR).filter(f => /^mallanalyser-del\d+\.json$/.test(f)).sort()) {
  for (const r of JSON.parse(fs.readFileSync(path.join(DIR, f), 'utf8'))) byMall.set(r.mall, r)
}
const mallar = [...byMall.values()].sort((a, b) => a.mall.localeCompare(b.mall))

const TYPLABEL = { FAKTA: 'Fakta', HARLEDD: 'Härledd', SAMMANSTALLNING: 'Sammanst.', BEDOMNING: '**BEDÖMNING**', KVITTO: 'Kvitto' }
const ANSVAR = { system: 'system', forslag_bekrafta: 'förslag+bekräfta', handlaggare: '**handläggaren**' }
const esc = s => String(s || '').replace(/\|/g, '\\|').replace(/\n/g, ' ')

// Aggregat
const agg = { typ: {}, ansvar: {}, fas: {}, kalla: {}, tot: 0 }
for (const m of mallar) for (const f of m.falt) {
  agg.tot++
  agg.typ[f.typ] = (agg.typ[f.typ] || 0) + 1
  agg.ansvar[f.ansvar] = (agg.ansvar[f.ansvar] || 0) + 1
  agg.fas[f.fas] = (agg.fas[f.fas] || 0) + 1
  for (const k of f.kalla) agg.kalla[k] = (agg.kalla[k] || 0) + 1
}

let md = `---
titel: BILAGA — fält-för-fält-analys av samtliga mallar (förifyllnad + ansvarsklass)
status: Genererad ur mallanalyser (${mallar.length}/18 mallar, ${agg.tot} fält) — ${new Date().toISOString().slice(0, 10)}
relaterat: ANALYS-FORIFYLLNAD-FALTKARTLAGGNING.md (huvudanalysen med modellen)
---

# BILAGA — fält-för-fält-analys per mall

Genererad ur strukturerad analys (\`analysis-output/forifyllnad-kartlaggning/\`).
Typskala och ansvarsmodell: se huvudanalysen §1.

## Aggregat (${mallar.length} mallar, ${agg.tot} fält)

| Typ | Antal | | Ansvar | Antal | | Fas | Antal |
|---|---|---|---|---|---|---|---|
`
const typK = Object.keys(agg.typ).sort(); const ansK = Object.keys(agg.ansvar).sort(); const fasK = Object.keys(agg.fas).sort()
const rows = Math.max(typK.length, ansK.length, fasK.length)
for (let i = 0; i < rows; i++) {
  md += `| ${typK[i] || ''} | ${typK[i] ? agg.typ[typK[i]] : ''} | | ${ansK[i] || ''} | ${ansK[i] ? agg.ansvar[ansK[i]] : ''} | | ${fasK[i] || ''} | ${fasK[i] ? agg.fas[fasK[i]] : ''} |\n`
}
md += `\n**Källor (fält kan ha flera):** ${Object.entries(agg.kalla).sort((a, b) => b[1] - a[1]).map(([k, v]) => `${k} ${v}`).join(' · ')}\n`

for (const m of mallar) {
  md += `\n---\n\n## ${m.mall} — ${m.mallTitel}\n\n*Steg:* ${m.steg}`
  if (m.kedja && m.kedja.length) md += ` · *Dokumentkedja (matas av):* ${m.kedja.join(', ')}`
  md += `\n\n| Fält | Plats | Typ | Källa | Fas | Ansvar | Förifyllnad |\n|---|---|---|---|---|---|---|\n`
  for (const f of m.falt) {
    md += `| ${esc(f.id)} | ${esc(f.plats)} | ${TYPLABEL[f.typ] || f.typ} | ${f.kalla.join(', ')} | ${f.fas} | ${ANSVAR[f.ansvar] || f.ansvar} | ${esc(f.forifyllnad)} |\n`
  }
  if (m.observationer && m.observationer.length) {
    md += `\n**Observationer:**\n${m.observationer.map(o => `- ${esc(o)}`).join('\n')}\n`
  }
}
fs.writeFileSync(OUT, md, 'utf8')
console.log(`Skrev ${OUT}: ${mallar.length} mallar, ${agg.tot} fält`)
console.log('AGGREGAT typ:', JSON.stringify(agg.typ), 'ansvar:', JSON.stringify(agg.ansvar), 'fas:', JSON.stringify(agg.fas))
