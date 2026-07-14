<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Service;

use OCP\AppFramework\Services\IAppConfig;

/**
 * Feature-flaggor för utredningskedjans TVINGANDE grindar (A7/A8/A9).
 *
 * Beslut 2026-07-08 (Fredrik): "config-flaggor, på i dev / av i prod". Kod-default
 * är därför '0' (AV) så en skarp driftmiljö aldrig får överraskande enforcement
 * vid en uppgradering; dev15 slår PÅ dem explicit vid deploy
 * (`occ config:app:set hubs_arende <flagga> --value 1`). SYNLIGHETEN (fas 1–2:
 * stepper, hover, härledning) är ALDRIG gatead — bara det tvingande beteendet.
 *
 * TRAILING OPTIONAL appConfig (positionell testharness) ⇒ alla flaggor AV
 * (grindarna degraderar till sitt gamla, icke-tvingande beteende).
 */
class GrindConfig {
    /** A7 — hård skyddsbedömnings-existens-grind (förhandsbedömning→utredning). */
    public const FLAGGA_SKYDDSBEDOMNING = 'grind_skyddsbedomning';
    /** A9a — kräv strukturerat skäl vid "inte inleda" (förhandsbedömning→avslutat). */
    public const FLAGGA_INTE_INLEDA = 'grind_inte_inleda_motiv';
    /** A9b — kräv dokument + kommunicerings-val vid beslutscommit (utredning→beslut). */
    public const FLAGGA_BESLUT_DOKUMENT = 'grind_beslut_dokument';
    /** A9c — kräv avslutsmotiv (uppföljning/övrigt→avslutat). */
    public const FLAGGA_AVSLUT_MOTIV = 'grind_avslut_motiv';
    /** A8 — skapa lagstadgad omprövningsbevakning automatiskt vid uppföljning. */
    public const FLAGGA_AUTO_OMPROVNING = 'auto_omprovning';

    public function __construct(
        private readonly ?IAppConfig $appConfig = null,
    ) {
    }

    private function pa(string $flagga): bool {
        if ($this->appConfig === null) {
            return false;
        }
        return $this->appConfig->getAppValueString($flagga, '0') === '1';
    }

    public function skyddsbedomningGrind(): bool {
        return $this->pa(self::FLAGGA_SKYDDSBEDOMNING);
    }

    public function inteInledaMotiv(): bool {
        return $this->pa(self::FLAGGA_INTE_INLEDA);
    }

    public function beslutDokument(): bool {
        return $this->pa(self::FLAGGA_BESLUT_DOKUMENT);
    }

    public function avslutMotiv(): bool {
        return $this->pa(self::FLAGGA_AVSLUT_MOTIV);
    }

    public function autoOmprovning(): bool {
        return $this->pa(self::FLAGGA_AUTO_OMPROVNING);
    }
}
