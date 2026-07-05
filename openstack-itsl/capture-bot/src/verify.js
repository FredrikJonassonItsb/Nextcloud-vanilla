// Inbound Talk bot webhook signature verification.
// Talk signs: X-Nextcloud-Talk-Signature = hex(hmac_sha256(secret, RANDOM + BODY))
// where RANDOM = X-Nextcloud-Talk-Random header and BODY = raw request body.
// Ref: https://nextcloud-talk.readthedocs.io/en/latest/bots/
import { createHmac, timingSafeEqual } from 'node:crypto';

/**
 * @param {object} p
 * @param {string|undefined} p.random    X-Nextcloud-Talk-Random header
 * @param {string|undefined} p.signature X-Nextcloud-Talk-Signature header
 * @param {string|Buffer}    p.body      raw request body
 * @param {string|undefined} p.secret    shared bot secret
 * @returns {boolean}
 */
export function verifySignature({ random, signature, body, secret }) {
  if (!random || !signature || !secret || body === undefined || body === null) {
    return false;
  }
  const expected = createHmac('sha256', secret)
    .update(random)
    .update(body)
    .digest('hex');
  const given = String(signature).trim().toLowerCase();
  const a = Buffer.from(expected, 'utf8');
  const b = Buffer.from(given, 'utf8');
  if (a.length !== b.length) return false;
  return timingSafeEqual(a, b);
}

/**
 * Outbound signing for the Talk bot reply/reaction API:
 * signature = hex(hmac_sha256(secret, RANDOM + message)) where message is the
 * `message` (or `reaction`) request parameter.
 */
export function signOutbound({ random, message, secret }) {
  return createHmac('sha256', secret).update(random).update(message).digest('hex');
}
