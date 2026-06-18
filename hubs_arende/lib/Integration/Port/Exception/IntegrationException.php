<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Integration\Port\Exception;

/**
 * Bas för alla integrations-/port-fel. Låter ärende-motorn fånga ETT
 * exception-träd oavsett vilken port (facksystem/signering/ediarium) som felade,
 * och köra sagans kompensering deterministiskt.
 */
class IntegrationException extends \RuntimeException {
}
