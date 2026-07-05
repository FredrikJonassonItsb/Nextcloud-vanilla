<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Service;

/**
 * The verbatim title grammar (CONTRACTS §2):
 *
 *   [agent instructions][<agentkod>][task] <titel>
 *   [agent instructions][all agents][standing_skill|standing_status|standing_routing] <titel>
 *
 * Parsing is strict on the bracket structure (three groups, first must be
 * exactly `agent instructions`) and lenient on the free-text tail.
 */
final class TitleGrammar {
    public const PREFIX = 'agent instructions';

    /** Deck card titles cap at 255 chars. */
    public const TITLE_MAX = 255;

    private function __construct() {
    }

    /**
     * Parse a card title against the grammar.
     *
     * @return array{audience:string,type:string,title:string}|null
     *         audience = second bracket (agent code or 'all agents'),
     *         type = third bracket (task|standing_skill|standing_status|standing_routing),
     *         title = the free-text tail. Null when the title is not grammar-shaped.
     */
    public static function parse(string $cardTitle): ?array {
        if (preg_match('/^\[([^\]]+)\]\[([^\]]+)\]\[([^\]]+)\]\s*(.*)$/su', $cardTitle, $m) !== 1) {
            return null;
        }
        if ($m[1] !== self::PREFIX) {
            return null;
        }
        return [
            'audience' => trim($m[2]),
            'type' => trim($m[3]),
            'title' => trim($m[4]),
        ];
    }

    /** Build a task-card title for one agent, truncated to Deck's 255-char cap. */
    public static function buildTask(string $agentCode, string $title): string {
        $prefix = '[' . self::PREFIX . '][' . $agentCode . '][task] ';
        $budget = self::TITLE_MAX - mb_strlen($prefix);
        if ($budget < 1) {
            return mb_substr($prefix, 0, self::TITLE_MAX);
        }
        return $prefix . mb_substr(trim($title), 0, $budget);
    }

    /** True when the parsed title addresses this agent's task queue. */
    public static function isTaskFor(?array $parsed, string $agentCode): bool {
        return $parsed !== null
            && $parsed['type'] === 'task'
            && $parsed['audience'] === $agentCode;
    }
}
