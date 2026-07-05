// !queue bridge (BYGGPLAN §4.3): create a card in the Inbox stack on the
// "Agent Engine" board via Deck REST as bot-engine. Board/stack ids come from
// bootstrap.json (written by deck-bootstrap, mounted read-only). Cards land in
// Inbox WITHOUT the agent-instructions label — never directly claimable.
import { readFileSync } from 'node:fs';

const DECK_API = '/index.php/apps/deck/api/v1.0';
const INBOX_TITLE = 'Inbox';
const ENRICH_LABEL = 'needs-enrichment';
const ENRICH_COLOR = '999999';

function pickBoardId(bootstrap) {
  return (
    bootstrap?.board?.id ??
    bootstrap?.boardId ??
    bootstrap?.board_id ??
    null
  );
}

function pickStackId(bootstrap) {
  const stacks = bootstrap?.stacks ?? bootstrap?.stackIds ?? null;
  if (!stacks) return null;
  if (Array.isArray(stacks)) {
    const hit = stacks.find((s) => s?.title === INBOX_TITLE);
    return hit?.id ?? null;
  }
  return stacks[INBOX_TITLE] ?? stacks.inbox ?? null;
}

/**
 * @param {object} p
 * @param {typeof fetch} p.fetchFn
 * @param {string} p.ncBase
 * @param {string} p.botUser  bot-engine
 * @param {string} p.botPassword  BOT_APP_PASSWORD_ENGINE
 * @param {string} p.bootstrapPath  /opt/openstack/state/bootstrap.json (ro mount)
 * @param {{info:Function,error:Function}} p.log
 */
export function createDeckClient({ fetchFn, ncBase, botUser, botPassword, bootstrapPath, log }) {
  const authHeader = `Basic ${Buffer.from(`${botUser}:${botPassword}`).toString('base64')}`;
  let cached = null; // { boardId, stackId, labelId }

  async function api(method, path, body) {
    const res = await fetchFn(`${ncBase}${DECK_API}${path}`, {
      method,
      headers: {
        Authorization: authHeader,
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'OCS-APIRequest': 'true',
      },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    if (!res.ok) throw new Error(`Deck API ${method} ${path} -> ${res.status}`);
    if (res.status === 204) return null;
    return res.json();
  }

  async function resolveTargets() {
    if (cached) return cached;
    const bootstrap = JSON.parse(readFileSync(bootstrapPath, 'utf8'));
    const boardId = pickBoardId(bootstrap);
    if (!boardId) throw new Error(`bootstrap.json (${bootstrapPath}) has no board id`);
    let stackId = pickStackId(bootstrap);
    if (!stackId) {
      const stacks = await api('GET', `/boards/${boardId}/stacks`);
      stackId = stacks.find((s) => s.title === INBOX_TITLE)?.id ?? null;
      if (!stackId) throw new Error(`no "${INBOX_TITLE}" stack on board ${boardId}`);
    }
    // Resolve-or-create the needs-enrichment label (idempotent; the sweep heals).
    let labelId = null;
    try {
      const board = await api('GET', `/boards/${boardId}`);
      labelId = (board.labels || []).find((l) => l.title === ENRICH_LABEL)?.id ?? null;
      if (!labelId) {
        const created = await api('POST', `/boards/${boardId}/labels`, {
          title: ENRICH_LABEL,
          color: ENRICH_COLOR,
        });
        labelId = created?.id ?? null;
      }
    } catch (err) {
      log.error({ msg: 'needs-enrichment label resolve failed (card still created)', err: String(err) });
    }
    cached = { boardId, stackId, labelId };
    return cached;
  }

  return {
    /**
     * Create the Inbox card. Label + assignee are best-effort; the card itself
     * and its link are the contract.
     * @returns {Promise<{cardId:number, url:string}>}
     */
    async createInboxCard({ title, description, assigneeUid }) {
      const { boardId, stackId, labelId } = await resolveTargets();
      const card = await api('POST', `/boards/${boardId}/stacks/${stackId}/cards`, {
        title,
        type: 'plain',
        order: 999,
        description,
      });
      const cardId = card.id;
      if (labelId) {
        try {
          await api('PUT', `/boards/${boardId}/stacks/${stackId}/cards/${cardId}/assignLabel`, {
            labelId,
          });
        } catch (err) {
          log.error({ msg: 'assignLabel failed (non-fatal)', cardId, err: String(err) });
        }
      }
      if (assigneeUid) {
        try {
          await api('PUT', `/boards/${boardId}/stacks/${stackId}/cards/${cardId}/assignUser`, {
            userId: assigneeUid,
          });
        } catch (err) {
          log.error({ msg: 'assignUser failed (non-fatal)', cardId, err: String(err) });
        }
      }
      return { cardId, url: `${ncBase}/apps/deck/card/${cardId}` };
    },
  };
}
