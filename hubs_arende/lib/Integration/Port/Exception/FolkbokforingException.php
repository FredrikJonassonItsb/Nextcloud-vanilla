<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Integration\Port\Exception;

/**
 * Fel i folkbokföringsuppslaget (Navet via kommunens interna Frends-API).
 *
 * Kastas av {@see \OCA\HubsArende\Integration\Port\FolkbokforingPort}-
 * implementationer vid transportfel, ogiltigt svar eller — viktigast —
 * FAIL-CLOSED-brott: en personpost utan giltigt `skydd`-fält får ALDRIG
 * släppas igenom (KRAVSTALLNING-NAVET-FOLKBOKFORING.md K-NAV-3.3).
 *
 * Meddelandet får ALDRIG innehålla personnummer eller namn (PII-doktrinen) —
 * använd korrelations-id och antal för felsökning.
 */
class FolkbokforingException extends IntegrationException {
}
