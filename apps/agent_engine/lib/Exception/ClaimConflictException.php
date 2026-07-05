<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Exception;

/**
 * The claim mutex already belongs to another agent — HTTP 409 {claimedBy}.
 */
class ClaimConflictException extends \RuntimeException {
    public function __construct(
        private readonly string $claimedBy,
    ) {
        parent::__construct('already claimed');
    }

    public function getClaimedBy(): string {
        return $this->claimedBy;
    }
}
