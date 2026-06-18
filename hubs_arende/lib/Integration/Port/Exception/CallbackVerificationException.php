<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Integration\Port\Exception;

/** En inkommande callback kunde inte verifieras (okänd/förbrukad/ogiltig token). */
class CallbackVerificationException extends IntegrationException {
}
