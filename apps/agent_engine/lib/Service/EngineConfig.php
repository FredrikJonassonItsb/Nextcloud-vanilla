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
        $agents = $this->routingAgents();
        if (isset($agents[$agentCode]) && $agents[$agentCode] !== '') {
            return $agents[$agentCode];
        }
        return Protocol::defaultOwnerForAgentCode($agentCode);
    }

    /**
     * Normalize the routing map to a flat {agentCode => humanUid} array.
     * occ-provision.sh writes the NESTED shape
     *   {"version":"v1","agents":{"atlas-claude":{"human":"…","bot":"…"}}}
     * — but a flat {"atlas-claude":"…"} is also accepted for forward-compat.
     *
     * @return array<string,string>
     */
    private function routingAgents(): array {
        $raw = $this->appConfig->getAppValueString('routing_map', '');
        if ($raw === '') {
            return [];
        }
        $map = json_decode($raw, true);
        if (!is_array($map)) {
            return [];
        }
        $agents = (isset($map['agents']) && is_array($map['agents'])) ? $map['agents'] : $map;
        $out = [];
        foreach ($agents as $agentCode => $entry) {
            if (!is_string($agentCode)) {
                continue;
            }
            $uid = is_array($entry) ? ($entry['human'] ?? null) : $entry;
            if (is_string($uid) && $uid !== '') {
                $out[$agentCode] = $uid;
            }
        }
        return $out;
    }

    /**
     * Reverse of ownerForAgentCode(): every agent code owned by a human uid.
     * The app-config routing map wins (VERIFIED uids, CONTRACTS §1); the
     * identity-table default fills in any agent not present in the map. Used by
     * the "Min agent" dashboard widget to find the logged-in human's agent(s).
     *
     * @return string[] agent codes (may be empty when the uid owns no agent)
     */
    public function agentCodesForOwner(string $ownerUid): array {
        $codes = [];
        $agents = $this->routingAgents();
        foreach ($agents as $agentCode => $uid) {
            if ($uid === $ownerUid) {
                $codes[$agentCode] = true;
            }
        }
        // Identity-table fallback for agents the map does not (yet) pin.
        foreach (Protocol::IDENTITIES as $row) {
            $agentCode = $row['agentCode'];
            // The map is authoritative when it lists this agent — do not let the
            // default owner re-add an agent the map deliberately reassigned.
            if (!isset($agents[$agentCode]) && $row['owner'] === $ownerUid) {
                $codes[$agentCode] = true;
            }
        }
        return array_keys($codes);
    }

    /**
     * Rich per-agent metadata for a human uid — the settings UI's connection
     * view. Reads the nested routing map (agent display name + bot uid) and
     * falls back to the identity table for anything the map does not pin.
     *
     * @return array<int,array{agentCode:string,agent:string,bot:string,human:string}>
     */
    public function agentInfoForOwner(string $ownerUid): array {
        $raw = $this->appConfig->getAppValueString('routing_map', '');
        $map = $raw !== '' ? json_decode($raw, true) : null;
        $agents = (is_array($map) && isset($map['agents']) && is_array($map['agents']))
            ? $map['agents']
            : (is_array($map) ? $map : []);

        $out = [];
        foreach ($this->agentCodesForOwner($ownerUid) as $agentCode) {
            $entry = $agents[$agentCode] ?? null;
            $identity = Protocol::identityForAgentCode($agentCode);
            $out[] = [
                'agentCode' => $agentCode,
                'agent' => is_array($entry) && isset($entry['agent'])
                    ? (string)$entry['agent']
                    : ($identity['display'] ?? $agentCode),
                'bot' => is_array($entry) && isset($entry['bot'])
                    ? (string)$entry['bot']
                    : ((string)(Protocol::botForAgentCode($agentCode) ?? '')),
                'human' => $ownerUid,
            ];
        }
        return $out;
    }

    /**
     * The full routing map as human uid ↔ agent metadata rows — the admin
     * view's read-only table. Map wins; identity-table default fills gaps.
     *
     * @return array<int,array{agentCode:string,agent:string,bot:string,human:string}>
     */
    public function routingRows(): array {
        $raw = $this->appConfig->getAppValueString('routing_map', '');
        $map = $raw !== '' ? json_decode($raw, true) : null;
        $agents = (is_array($map) && isset($map['agents']) && is_array($map['agents']))
            ? $map['agents']
            : (is_array($map) ? $map : []);

        $rows = [];
        foreach (Protocol::IDENTITIES as $bot => $identity) {
            $agentCode = $identity['agentCode'];
            $entry = $agents[$agentCode] ?? null;
            $human = is_array($entry) && isset($entry['human']) && $entry['human'] !== ''
                ? (string)$entry['human']
                : (Protocol::defaultOwnerForAgentCode($agentCode) ?? '');
            $rows[] = [
                'agentCode' => $agentCode,
                'agent' => is_array($entry) && isset($entry['agent']) ? (string)$entry['agent'] : $identity['display'],
                'bot' => $bot,
                'human' => $human,
            ];
        }
        return $rows;
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
