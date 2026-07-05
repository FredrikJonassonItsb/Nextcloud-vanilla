<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Exception;

/**
 * The PII firewall blocked a copy across the authorization boundary — HTTP 422
 * with a human-readable refusal (never a silent drop).
 */
class PiiRejectedException extends \RuntimeException {
    public function __construct(
        private readonly string $patternId,
        string $humanMessage,
    ) {
        parent::__construct($humanMessage);
    }

    public function getPatternId(): string {
        return $this->patternId;
    }
}
