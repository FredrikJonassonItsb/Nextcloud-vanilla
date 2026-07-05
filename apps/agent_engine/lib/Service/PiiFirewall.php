<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Service;

use Psr\Log\LoggerInterface;

/**
 * The PII firewall on the COPY PATH (CONTRACTS §3, INTERAKTIONSDESIGN §2.11).
 *
 * Runs BEFORE every copy across the authorization boundary: takeover
 * (title/description/attachment names) and every mirrored comment, in both
 * directions. A hit means REFUSE with a human-readable message — never a
 * silent drop, never a scrubbed copy.
 *
 * Pattern source: stack/shared/pii-patterns.json when configured
 * (pii_patterns_path app-config); otherwise the built-in list which is
 * byte-equivalent to CONTRACTS §3. The shared file is generated FROM the
 * hubs_arende patterns — the built-ins are the same list, so an absent file
 * degrades to identical behaviour, not to no firewall.
 */
class PiiFirewall {
    /**
     * Built-in patterns = CONTRACTS §3 verbatim.
     * id → PCRE (without delimiters; compiled with /u and case-sensitivity as needed).
     */
    private const BUILTIN_PATTERNS = [
        // Svenskt personnummer (10/12 siffror, valfritt sekelprefix och skiljetecken)
        'personnummer' => '\b(19|20)?\d{6}[-+]?\d{4}\b',
        // Anthropic API key
        'anthropic_key' => 'sk-ant-',
        // OpenRouter API key
        'openrouter_key' => 'sk-or-v1-',
        // AWS access key id
        'aws_key' => 'AKIA[0-9A-Z]{16}',
        // hubsCaseId-UUID:er i case-kontext (UUID v4 — case coordination ids)
        'hubs_case_uuid' => '\b[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\b',
        // BankID-transaktions-/ordernummer (UUID-format används av BankID orderRef)
        'bankid_orderref' => '\bbankid[:\s-]*[0-9a-f-]{8,}\b',
    ];

    /** @var array<string,string>|null lazily loaded id → pattern map */
    private ?array $patterns = null;

    public function __construct(
        private EngineConfig $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Scan one or more text fields. Returns the id of the first matching
     * pattern, or null when the content is clean.
     *
     * @param array<int|string,string|null> $fields
     */
    public function scan(array $fields): ?string {
        foreach ($this->loadPatterns() as $id => $pattern) {
            $regex = '/' . str_replace('/', '\/', $pattern) . '/iu';
            foreach ($fields as $field) {
                if ($field === null || $field === '') {
                    continue;
                }
                $hit = @preg_match($regex, $field);
                if ($hit === 1) {
                    // NEVER log the content — id only.
                    $this->logger->info('agent_engine: PII firewall hit', [
                        'app' => 'agent_engine',
                        'patternId' => $id,
                    ]);
                    return (string)$id;
                }
                if ($hit === false) {
                    $this->logger->warning('agent_engine: PII pattern failed to compile — treating as non-match', [
                        'app' => 'agent_engine',
                        'patternId' => $id,
                    ]);
                }
            }
        }
        return null;
    }

    /** Human-readable refusal (Swedish, INTERAKTIONSDESIGN §2.3 step 1 verbatim). */
    public function refusalMessage(): string {
        return 'Jag kan inte ta det här kortet — innehållet matchar mönster som inte får '
            . 'kopieras in i agent-substratet (PII/secrets). Rensa kortet eller behåll det själv.';
    }

    /** Refusal used when a single mirrored comment (not the card) is blocked. */
    public function commentRefusalMessage(): string {
        return 'Kommentaren speglades INTE till agenten — innehållet matchar mönster som '
            . 'inte får kopieras in i agent-substratet (PII/secrets). Omformulera utan '
            . 'personuppgifter/nycklar så speglas nästa kommentar.';
    }

    public function patternCount(): int {
        return count($this->loadPatterns());
    }

    /** @return array<string,string> */
    private function loadPatterns(): array {
        if ($this->patterns !== null) {
            return $this->patterns;
        }
        $this->patterns = self::BUILTIN_PATTERNS;

        $path = $this->config->piiPatternsPath();
        if ($path !== '' && is_file($path) && is_readable($path)) {
            $decoded = json_decode((string)file_get_contents($path), true);
            $list = is_array($decoded) ? ($decoded['patterns'] ?? $decoded) : null;
            if (is_array($list)) {
                $loaded = [];
                foreach ($list as $key => $entry) {
                    if (is_string($entry)) {
                        $loaded['file_' . $key] = $entry;
                    } elseif (is_array($entry) && isset($entry['regex']) && is_string($entry['regex'])) {
                        $loaded[(string)($entry['id'] ?? ('file_' . $key))] = $entry['regex'];
                    }
                }
                if ($loaded !== []) {
                    // Shared file wins, but the built-ins stay as the floor —
                    // a truncated file must never weaken the firewall.
                    $this->patterns = array_merge(self::BUILTIN_PATTERNS, $loaded);
                }
            } else {
                $this->logger->warning('agent_engine: pii-patterns.json unreadable — using built-ins', [
                    'app' => 'agent_engine',
                ]);
            }
        }
        return $this->patterns;
    }
}
