<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Exception;

/**
 * Thrown by {@see \OCA\HubsArende\Service\ArendeService::createCase()} when the
 * fail-closed {@see \OCA\HubsArende\Service\SakerhetsskyddGrind} (saga step R0)
 * rejects an inflow.
 *
 * When this is thrown NOTHING has been created — no register row, no case:-tag,
 * no Groupfolder, no Spreed room, no Deck card, no klocka. It carries the
 * avvisningskvitto so the OCS layer can surface it verbatim.
 */
class AvvisadException extends \RuntimeException {
    /**
     * @param string $reason Stable reason code (SakerhetsskyddGrind::REASON_*).
     * @param array<string,mixed> $kvitto The avvisningskvitto to return to the caller.
     * @param bool $retroaktiv True when a pre-existing case for the same anchor
     *        must be retroactively quarantined.
     */
    public function __construct(
        string $reason,
        private array $kvitto = [],
        private bool $retroaktiv = false,
    ) {
        parent::__construct($reason);
    }

    /**
     * @return array<string,mixed>
     */
    public function getKvitto(): array {
        return $this->kvitto;
    }

    public function isRetroaktiv(): bool {
        return $this->retroaktiv;
    }
}
