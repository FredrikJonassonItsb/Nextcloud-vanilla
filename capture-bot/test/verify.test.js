import test from 'node:test';
import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { verifySignature, signOutbound } from '../src/verify.js';

const secret = 'a'.repeat(40);
const body = JSON.stringify({ type: 'Create', object: { id: '42' } });
const random = 'r4nd0m'.repeat(11);

function sign(sec, rnd, bdy) {
  return createHmac('sha256', sec).update(rnd).update(bdy).digest('hex');
}

test('valid signature verifies', () => {
  assert.equal(
    verifySignature({ random, signature: sign(secret, random, body), body, secret }),
    true,
  );
});

test('uppercase hex signature also verifies', () => {
  assert.equal(
    verifySignature({ random, signature: sign(secret, random, body).toUpperCase(), body, secret }),
    true,
  );
});

test('tampered body is rejected', () => {
  assert.equal(
    verifySignature({ random, signature: sign(secret, random, body), body: body + 'x', secret }),
    false,
  );
});

test('wrong secret is rejected', () => {
  assert.equal(
    verifySignature({ random, signature: sign('b'.repeat(40), random, body), body, secret }),
    false,
  );
});

test('tampered random is rejected', () => {
  assert.equal(
    verifySignature({ random: random + 'x', signature: sign(secret, random, body), body, secret }),
    false,
  );
});

test('missing header pieces are rejected without throwing', () => {
  assert.equal(verifySignature({ random: undefined, signature: 'x', body, secret }), false);
  assert.equal(verifySignature({ random, signature: undefined, body, secret }), false);
  assert.equal(verifySignature({ random, signature: 'x', body, secret: undefined }), false);
});

test('wrong-length signature is rejected without throwing', () => {
  assert.equal(verifySignature({ random, signature: 'deadbeef', body, secret }), false);
});

test('outbound signature matches inbound scheme over RANDOM + message', () => {
  const message = 'Sparat i teamhjärnan ✓';
  assert.equal(
    signOutbound({ random, message, secret }),
    createHmac('sha256', secret).update(random).update(message).digest('hex'),
  );
});
