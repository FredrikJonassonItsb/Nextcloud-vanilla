import { test } from 'node:test';
import assert from 'node:assert/strict';
import { pseudonymize, redact } from '../src/pseudonymize.js';

test('pseudonymize: email -> stable token (same input -> same token)', () => {
  const a = pseudonymize('kontakt kund@kommun.se');
  const b = pseudonymize('igen kund@kommun.se');
  const ta = a.match(/\[EPOST_[0-9a-f]{8}\]/)[0];
  const tb = b.match(/\[EPOST_[0-9a-f]{8}\]/)[0];
  assert.equal(ta, tb); // konsekvent
  assert.ok(!a.includes('kund@kommun.se'));
});

test('pseudonymize: personnummer (med separator) maskas; 12-siffrig utan separator är känd lucka', () => {
  assert.equal(pseudonymize('900101-1234').trim(), '[PERSONNUMMER]');
  assert.equal(pseudonymize('19900101-1234').trim(), '[PERSONNUMMER]');
  // Medveten lucka (som pii.py): 12-siffrig BankID-form UTAN separator fångas inte.
  assert.equal(pseudonymize('bankid 197411040293 utan bindestreck').includes('[PERSONNUMMER]'), false);
});

test('pseudonymize: telefon maskas, löp-id INTE', () => {
  assert.equal(pseudonymize('ring 070-123 45 67').includes('[TELEFON]'), true);
  assert.equal(pseudonymize('+46 8 123 456').includes('[TELEFON]'), true);
  // Ordernummer/löp-id (börjar inte med +/0/46, men >6 siffror) ska INTE maskas.
  assert.equal(pseudonymize('order 5512349'), 'order 5512349');
});

test('redact: email -> [EPOST] (icke-korrelerbart)', () => {
  assert.equal(redact('a@b.se'), '[EPOST]');
});

test('tomt/null -> tom sträng', () => {
  assert.equal(pseudonymize(null), '');
  assert.equal(pseudonymize(''), '');
});
