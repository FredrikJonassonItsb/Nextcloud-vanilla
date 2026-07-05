<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Service;

use OCA\AgentEngine\Protocol;
use OCP\AppFramework\Services\IAppConfig;

/**
 * Typed access to the engine's app-config (all set by occ-provision.sh /
 * deck-bootstrap, values sourced from CONTRACTS §§2–3, §8):
 *
 *   engine_board_id   — the "Agent Engine" board (deck-bootstrap → bootstrap.json)
 *   ledger_card_id    — the standing_status ledger card (back-filled on first resolve)
 *   runner_base       — default http://10.43.51.62:8791 (CONTRACTS §3)
 *   push_secret       — ENGINE_PUSH_SECRET (shared with the runner via occ config:app:set)
 *   routing_map       — JSON {agentCode: ownerUid} — VERIFIED uids only; falls back
 *                       to the CONTRACTS §1 identity table per agent
 *   pii_patterns_path — optional path to stack/shared/pii-patterns.json
 */
class EngineConfig {
    public function __construct(
        private IAppConfig $appConfig,
    ) {
    }

    public function engineBoardId(): int {
        return (int)$this->appConfig->getAppValueString('engine_board_id', '0');
    }

    public function ledgerCardId(): int {
        return (int)$this->appConfig->getAppValueString('ledger_card_id', '0');
    }

    public function setLedgerCardId(int $cardId): void {
        $this->appConfig->setAppValueString('ledger_card_id', (string)$cardId);
    }

    public function runnerBase(): string {
        return rtrim($this->appConfig->getAppValueString('runner_base', 'http://10.43.51.62:8791'), '/');
    }

    public function pushSecret(): string {
        return $this->appConfig->getAppValueString('push_secret', '');
    }

    /**
     * Owner uid for an agent (routing map v1). App-config override wins so
     * occ-provision can restrict the map to VERIFIED uids (CONTRACTS §1);
     * fallback is the identity table constant.
     */
    public function ownerForAgentCode(string $agentCode): ?string {
        $raw = $this->appConfig->getAppValueString('routing_map', '');
        if ($raw !== '') {
            $map = json_decode($raw, true);
            if (is_array($map) && isset($map[$agentCode]) && is_string($map[$agentCode]) && $map[$agentCode] !== '') {
                return $map[$agentCode];
            }
        }
        return Protocol::defaultOwnerForAgentCode($agentCode);
    }

    public function piiPatternsPath(): string {
        return $this->appConfig->getAppValueString('pii_patterns_path', '');
    }

    /** Presence check: heartbeat older than this ⇒ stale (2× the 30-min cron stagger). */
    public function heartbeatStaleMinutes(): int {
        return max(1, (int)$this->appConfig->getAppValueString('heartbeat_stale_minutes', '60'));
    }

    /** Pre-claim stall detector threshold (INTERAKTIONSDESIGN §2.8). */
    public function preClaimStallHours(): int {
        return max(1, (int)$this->appConfig->getAppValueString('pre_claim_stall_hours', '4'));
    }
}
