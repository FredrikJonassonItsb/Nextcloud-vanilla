// POST /bot orchestration. Invariants:
//   1. HMAC verify before anything else acts on the payload.
//   2. Dedupe claim before side effects (Talk retries webhooks).
//   3. Firewall BEFORE anything leaves the house.
//   4. Ingest BEFORE reply — a lost Talk reply may never lose a capture.
//   5. Transient failure => release claim + 500 so Talk retries.
// NOTE: message content is never logged (PII invariant).
import { verifySignature } from './verify.js';
import {
  parseTalkPayload,
  parseCommand,
  resolveRoute,
  agentCodeFor,
  agentCodeForUid,
  brainLabel,
} from './route.js';

const BLOCKED_PREFIX = 'Blockerat:';

export function createWebhookHandler({ config, dedupe, firewall, brains, notify, deck, engine, formatLedger, log }) {
  async function handleStatus(msg, route, command) {
    // Explicit agent arg wins; else the sender's own agent; else the room's brain.
    const agentCode =
      agentCodeFor(command.agent) ??
      agentCodeForUid(msg.actorUid) ??
      agentCodeFor(route.brain);
    if (!agentCode) {
      await notify.reply(route.botEnv, msg.roomToken, 'Okänd agent. Användning: !status [reb|atlas|ada|marvin]', msg.messageId);
      return;
    }
    try {
      const data = await engine.getLedger(agentCode);
      await notify.reply(route.botEnv, msg.roomToken, formatLedger(agentCode, data), msg.messageId);
    } catch (err) {
      log.error({ msg: 'ledger fetch failed', agentCode, err: String(err) });
      await notify.reply(route.botEnv, msg.roomToken, `Kunde inte hämta status för ${agentCode.replace(/-claude$/, '')} just nu.`, msg.messageId);
    }
  }

  async function handleQueue(msg, route, command) {
    const agentCode =
      agentCodeForUid(msg.actorUid) ?? agentCodeFor(route.brain) ?? 'all agents';
    const firstLine = command.text.split('\n')[0].trim().slice(0, 120);
    let cardNote;
    try {
      const { url } = await deck.createInboxCard({
        title: `[inbox][${agentCode}] ${firstLine}`,
        description: command.text,
        assigneeUid: msg.actorUid,
      });
      cardNote = `Kort skapat i Inbox: ${url}`;
    } catch (err) {
      log.error({ msg: 'deck inbox card failed', roomToken: msg.roomToken, err: String(err) });
      cardNote = 'Kortet kunde inte skapas i Deck just nu — tanken är sparad, skapa kortet manuellt.';
    }
    return cardNote;
  }

  /**
   * @param {string} rawBody
   * @param {Record<string,string|undefined>} headers lower-cased header map
   * @returns {Promise<{status:number, body:object}>}
   */
  return async function handle(rawBody, headers, botSlug) {
    let payload;
    try {
      payload = JSON.parse(rawBody);
    } catch {
      return { status: 400, body: { error: 'invalid JSON' } };
    }

    const roomToken = String(payload?.target?.id ?? '');
    const route = resolveRoute(config.rooms, roomToken);
    if (!route) {
      log.info({ msg: 'unrouted room ignored', roomToken });
      return { status: 200, body: { ignored: 'unrouted room' } };
    }
    // The URL slug (/bot/<slug>) identifies WHICH bot delivered this webhook —
    // in multi-bot rooms (Team minne) each installed bot delivers a copy signed
    // with its own secret. Fall back to the room's bot for the legacy /bot path.
    const secretEnv = botSlug ? botSlug.toUpperCase() : route.botEnv;
    const secret = config.botSecrets[secretEnv];
    if (!secret) {
      log.error({ msg: 'missing bot secret', botEnv: route.botEnv });
      return { status: 500, body: { error: 'bot secret not configured' } };
    }
    if (
      !verifySignature({
        random: headers['x-nextcloud-talk-random'],
        signature: headers['x-nextcloud-talk-signature'],
        body: rawBody,
        secret,
      })
    ) {
      log.info({ msg: 'signature verification failed', roomToken });
      return { status: 401, body: { error: 'invalid signature' } };
    }

    const msg = parseTalkPayload(payload);
    if (msg.skip) {
      return { status: 200, body: { ignored: msg.reason } };
    }

    const claimed = await dedupe.claim(msg.roomToken, msg.messageId);
    if (!claimed) {
      log.info({ msg: 'duplicate delivery deduped', roomToken: msg.roomToken, messageId: msg.messageId });
      return { status: 200, body: { deduped: true } };
    }

    try {
      const command = parseCommand(msg.text);

      // !status — pure query, no capture.
      if (command?.cmd === 'status') {
        await handleStatus(msg, route, command);
        await dedupe.markStatus(msg.roomToken, msg.messageId, 'command');
        return { status: 200, body: { command: 'status' } };
      }
      // !queue with no text — usage hint, no capture.
      if (command?.cmd === 'queue' && !command.text) {
        await notify.reply(route.botEnv, msg.roomToken, 'Användning: !queue <beskrivning av uppgiften>', msg.messageId);
        await dedupe.markStatus(msg.roomToken, msg.messageId, 'command');
        return { status: 200, body: { command: 'queue-usage' } };
      }

      // Firewall BEFORE anything external (embedding calls live behind /ingest).
      const hit = firewall.check(msg.text);
      if (hit) {
        await dedupe.markStatus(msg.roomToken, msg.messageId, 'blocked');
        await notify.reply(
          route.botEnv,
          msg.roomToken,
          `${BLOCKED_PREFIX} innehållet matchar mönster som inte får lagras i hjärnor (PII/secrets) — ${hit.message}.`,
          msg.messageId,
        );
        log.info({ msg: 'firewall block', roomToken: msg.roomToken, messageId: msg.messageId, pattern: hit.id });
        return { status: 422, body: { blocked: hit.id } };
      }

      // Capture — team room gets structural author attribution (speaker uid).
      const metadata = {
        source: 'talk',
        talk_id: msg.messageId,
        talk_room: msg.roomToken,
        talk_actor: msg.actorUid,
      };
      const ingestBody = {
        content: msg.text,
        source: 'talk',
        metadata,
      };
      if (route.mode === 'team') {
        ingestBody.author = msg.actorUid;
      }
      const result = await brains.ingest(route.brain, ingestBody);

      if (result.blocked) {
        await dedupe.markStatus(msg.roomToken, msg.messageId, 'blocked');
        await notify.reply(route.botEnv, msg.roomToken, `${BLOCKED_PREFIX} ${result.reason}`, msg.messageId);
        return { status: 422, body: { blocked: 'brain-422' } };
      }

      await dedupe.markStatus(msg.roomToken, msg.messageId, 'stored');

      // Everything after this point is best-effort notification/bridge:
      // the capture is safe, so failures reply degraded but never 5xx.
      let confirmation = `Sparat i ${brainLabel(route.brain)} ✓`;
      if (command?.cmd === 'queue') {
        const cardNote = await handleQueue(msg, route, command);
        confirmation = `${confirmation}\n${cardNote}`;
      } else {
        await notify.react(route.botEnv, msg.roomToken, msg.messageId, '👍');
      }
      await notify.reply(route.botEnv, msg.roomToken, confirmation, msg.messageId);

      return { status: 200, body: { stored: true } };
    } catch (err) {
      // Transient failure before the capture was stored: release the claim so
      // the Talk retry re-processes it. Capture must never be lost.
      log.error({ msg: 'capture failed, releasing claim for retry', roomToken: msg.roomToken, messageId: msg.messageId, err: String(err) });
      try {
        await dedupe.release(msg.roomToken, msg.messageId);
      } catch (releaseErr) {
        log.error({ msg: 'claim release failed', err: String(releaseErr) });
      }
      return { status: 500, body: { error: 'capture failed, retry expected' } };
    }
  };
}
