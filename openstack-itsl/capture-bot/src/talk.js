// Talk bot reply/reaction client (outbound, HMAC-signed per the Talk bot spec):
// POST {NC_BASE}/ocs/v2.php/apps/spreed/api/v1/bot/{token}/message
// POST {NC_BASE}/ocs/v2.php/apps/spreed/api/v1/bot/{token}/reaction/{messageId}
// Signature = hex(hmac_sha256(secret, RANDOM + message|reaction)).
import { randomBytes, createHash } from 'node:crypto';
import { signOutbound } from './verify.js';

/**
 * @param {object} p
 * @param {typeof fetch} p.fetchFn
 * @param {string} p.ncBase e.g. https://dev15.hubs.se (no trailing slash)
 * @param {Record<string,string>} p.botSecrets keyed by botEnv (REB/ATLAS/ADA/MARVIN)
 * @param {{info:Function,error:Function}} p.log
 */
export function createTalkClient({ fetchFn, ncBase, botSecrets, log }) {
  async function signedPost(botEnv, url, signedValue, body) {
    const secret = botSecrets[botEnv];
    if (!secret) throw new Error(`no TALK_BOT_SECRET for bot env ${botEnv}`);
    const random = randomBytes(32).toString('hex'); // 64 chars
    const res = await fetchFn(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'OCS-APIRequest': 'true',
        'X-Nextcloud-Talk-Bot-Random': random,
        'X-Nextcloud-Talk-Bot-Signature': signOutbound({ random, message: signedValue, secret }),
      },
      body: JSON.stringify(body),
    });
    if (!res.ok) {
      throw new Error(`Talk bot API ${res.status} for ${url.replace(ncBase, '')}`);
    }
    return res;
  }

  return {
    /** Threaded reply. Throws on failure — callers decide whether that is fatal (it never is for capture). */
    async reply(botEnv, roomToken, text, replyToMessageId) {
      const body = {
        message: text,
        // Idempotency on the Talk side across webhook retries.
        referenceId: createHash('sha256').update(`${roomToken}:${replyToMessageId}:${text}`).digest('hex'),
      };
      const replyTo = Number.parseInt(replyToMessageId, 10);
      if (Number.isFinite(replyTo)) body.replyTo = replyTo;
      await signedPost(
        botEnv,
        `${ncBase}/ocs/v2.php/apps/spreed/api/v1/bot/${encodeURIComponent(roomToken)}/message`,
        text,
        body,
      );
    },

    async react(botEnv, roomToken, messageId, reaction) {
      await signedPost(
        botEnv,
        `${ncBase}/ocs/v2.php/apps/spreed/api/v1/bot/${encodeURIComponent(roomToken)}/reaction/${encodeURIComponent(messageId)}`,
        reaction,
        { reaction },
      );
    },
  };
}

/**
 * Best-effort wrapper: reply/reaction failures are logged, never thrown —
 * the capture is already safely ingested (ingest happens BEFORE reply).
 */
export function safeNotify(talk, log) {
  return {
    async reply(botEnv, roomToken, text, replyTo) {
      try {
        await talk.reply(botEnv, roomToken, text, replyTo);
      } catch (err) {
        log.error({ msg: 'talk reply failed (non-fatal)', roomToken, err: String(err) });
      }
    },
    async react(botEnv, roomToken, messageId, reaction) {
      try {
        await talk.react(botEnv, roomToken, messageId, reaction);
      } catch (err) {
        log.error({ msg: 'talk reaction failed (non-fatal)', roomToken, err: String(err) });
      }
    },
  };
}
