<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Controller;

use OCA\AgentEngine\Protocol;
use OCP\IUserSession;

/**
 * Authorization guard for the bot endpoints: callers are bot users
 * authenticated with app passwords (CONTRACTS §3 "bot" rows).
 *
 * Rules:
 *  - the caller must be a known bot uid (CONTRACTS §1 table + bot-engine);
 *  - agent-scoped endpoints (claim/queue/ledger) additionally require that
 *    the caller IS that agent's bot — except bot-engine, which is the
 *    system relay (capture-bot's !status, admin tooling).
 */
class BotGuard {
    public function __construct(
        private IUserSession $userSession,
    ) {
    }

    public function callerUid(): string {
        return $this->userSession->getUser()?->getUID() ?? '';
    }

    public function isBotCaller(): bool {
        return Protocol::isBot($this->callerUid());
    }

    /** The caller's own agent code (null for bot-engine / non-bots). */
    public function callerAgentCode(): ?string {
        return Protocol::agentCodeForBot($this->callerUid());
    }

    /** May the caller act for $agentCode? */
    public function mayActFor(string $agentCode): bool {
        $uid = $this->callerUid();
        if ($uid === Protocol::ENGINE_BOT) {
            return true; // system relay
        }
        return Protocol::agentCodeForBot($uid) === $agentCode;
    }
}
