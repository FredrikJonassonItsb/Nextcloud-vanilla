// Pseudonymisering/maskning (GDPR) — Node-port av kb-pipelines pii.py.
// Se skills/ingestion/pii-pseudonymize/SKILL.md.
//   pseudonymize: e-post -> stabil [EPOST_<sha8>], personnummer -> [PERSONNUMMER],
//                 telefon -> [TELEFON] (validerad så löp-id inte över-maskas).
//   redact:       e-post -> [EPOST] (icke-korrelerbart), övrigt samma.
// KÄND lucka (medvetet, som referensen): 12-siffrigt personnummer UTAN separator
// (t.ex. BankID-uid) fångas inte — separator krävs för att undvika över-maskning.
import { createHash } from 'node:crypto';

const EMAIL = /[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/g;
// Svenskt personnummer YYMMDD±XXXX / YYYYMMDD±XXXX.
const PNR = /\b(?:\d{6}|\d{8})[-+]\d{4}\b/g;
// Telefon-KANDIDAT (validering i isPhone) — literalt mellanslag, ej \s (ingen radbrytning).
const PHONE_CANDIDATE = /(?<![\w.])\+?\d[\d \-]{5,}\d(?![\w.])/g;

function token(prefix, value) {
  const h = createHash('sha256').update(String(value).toLowerCase()).digest('hex').slice(0, 8);
  return `[${prefix}_${h}]`;
}

function isPhone(s) {
  const digits = s.replace(/\D/g, '');
  if (digits.length < 7 || digits.length > 13) return false;
  // Telefonliknande: internationellt ('+') eller svenskt (riktnummer '0' / '46').
  return s.trimStart().startsWith('+') || digits.startsWith('0') || digits.startsWith('46');
}

function maskPhones(text, repl) {
  return text.replace(PHONE_CANDIDATE, (m) => (isPhone(m) ? repl : m));
}

export function pseudonymize(text) {
  if (!text) return '';
  let t = String(text);
  t = t.replace(EMAIL, (m) => token('EPOST', m));
  t = t.replace(PNR, '[PERSONNUMMER]');
  t = maskPhones(t, '[TELEFON]');
  return t;
}

export function redact(text) {
  if (!text) return '';
  let t = String(text);
  t = t.replace(EMAIL, '[EPOST]');
  t = t.replace(PNR, '[PERSONNUMMER]');
  t = maskPhones(t, '[TELEFON]');
  return t;
}
