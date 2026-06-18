<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Integration\Port\Exception;

/** Facksystemet avvisade commiten (valideringsfel, dubblett, modul-fel). */
class CommitFailedException extends IntegrationException {
}
