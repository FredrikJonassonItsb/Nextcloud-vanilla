<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Exception;

/**
 * The card fails a protocol precondition (wrong stack, missing label, title
 * grammar mismatch, unknown card, invalid token …) — HTTP 422.
 */
class NotEligibleException extends \RuntimeException {
}
