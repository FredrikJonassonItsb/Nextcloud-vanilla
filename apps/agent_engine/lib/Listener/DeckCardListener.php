<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Listener;

use OCA\AgentEngine\Protocol;
use OCA\AgentEngine\Service\EngineConfig;
use OCA\AgentEngine\Service\TakeoverService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * In-process Deck card listener — the LATENCY path (assign → takeover ≤2 s).
 * The 2-min sweep is the CORRECTNESS mechanism; anything missed here only
 * degrades to sweep latency (INTERAKTIONSDESIGN constraint 1).
 *
 * DEFENSIVE BINDING (documented, M0-verified on the deployed Deck):
 * Application::register subscribes this listener to every OCA\Deck\Event
 * card-event class that exists at runtime:
 *
 *   - OCA\Deck\Event\CardCreatedEvent
 *   - OCA\Deck\Event\CardUpdatedEvent   ← fires on card PUT (title/desc/due);
 *                                          on the deployed Deck 1.x it ALSO
 *                                          fires around assignment changes
 *                                          via CardService, but this is NOT
 *                                          relied upon —
 *   - there is no dedicated public assign/unassign event in Deck's OCP
 *     surface, so assignment diffs are detected by comparing the event
 *     card's assignedUsers against our own card_links state (reconcileCard
 *     is a pure invariant — it needs no before/after payload), and the sweep
 *     re-runs the same invariant every 2 min as the floor.
 *
 * All ACardEvent subclasses expose getCard() (an OCA\Deck\Db\Card entity);
 * we serialize defensively via jsonSerialize() when available.
 */
class DeckCardListener implements IEventListener {
    public function __construct(
        private TakeoverService $takeover,
        private EngineConfig $config,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        try {
            if (!method_exists($event, 'getCard')) {
                return;
            }
            $cardObj = $event->getCard();
            $card = $this->toArray($cardObj);
            if ($card === null) {
                return;
            }

            // Actor filter (loop brake 1): engine/bot-driven writes never
            // trigger pipelines — all glue writes are bot-authored.
            $actor = $this->userSession->getUser()?->getUID() ?? '';
            if ($actor !== '' && Protocol::isBot($actor)) {
                return;
            }

            $cardId = (int)($card['id'] ?? 0);
            $boardId = $this->resolveBoardId($cardObj, $card);
            if ($boardId <= 0 || $cardId <= 0 || $boardId === $this->config->engineBoardId()) {
                // Engine-board edits are handled by the sweep (power-path
                // approve/rework detection) — not takeover subjects.
                return;
            }

            // Re-fetch the card fresh: the event payload lacks assignedUsers,
            // so reconcileCardById reads the authoritative card (with assignees)
            // before enforcing the invariant. This is the ≤2 s latency path.
            $this->takeover->reconcileCardById($boardId, $cardId, $actor);
        } catch (\Throwable $e) {
            // A listener exception must never break the user's Deck action;
            // the sweep replays the same invariant within 2 minutes.
            $this->logger->warning('agent_engine: card listener failed (sweep will reconcile)', [
                'app' => 'agent_engine',
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /** @return array<string,mixed>|null */
    private function toArray(mixed $cardObj): ?array {
        if (is_array($cardObj)) {
            return $cardObj;
        }
        if ($cardObj instanceof \JsonSerializable) {
            $data = $cardObj->jsonSerialize();
            return is_array($data) ? $data : null;
        }
        return null;
    }

    /**
     * The card payload does not carry boardId directly on every Deck version;
     * try the common shapes, else resolve stack→board via related objects.
     */
    private function resolveBoardId(mixed $cardObj, array $card): int {
        foreach (['boardId', 'board'] as $key) {
            if (isset($card[$key])) {
                if (is_numeric($card[$key])) {
                    return (int)$card[$key];
                }
                if (is_array($card[$key]) && isset($card[$key]['id'])) {
                    return (int)$card[$key]['id'];
                }
            }
        }
        if (is_object($cardObj) && method_exists($cardObj, 'getRelatedBoard')) {
            try {
                $board = $cardObj->getRelatedBoard();
                if (is_object($board) && method_exists($board, 'getId')) {
                    return (int)$board->getId();
                }
            } catch (\Throwable) {
                // fall through — sweep will pick the card up
            }
        }
        return 0;
    }
}
