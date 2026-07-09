<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Service\Brain;

/**
 * HITL-konflikt vid ett AI-utkast-beslut — mappas av OCS-lagret till HTTP 409
 * (SPEC-BRAIN-PER-ARENDE kap 8.0.7). Bär en maskinläsbar `felkod` så att
 * controllern kan skilja fallen åt i svaret utan att läcka utkastexistens/innehåll:
 *
 *   - {@see FELKOD_REDAN_AVGJORT}  utkastet är inte längre i status=utkast.
 *   - {@see FELKOD_UTFALLSSPARR}   fn_draft_beslutsformulering: människans utfall
 *                                  matchar inte utkastets utfall_eko, ELLER
 *                                  beslutstexten träffar utfalls-/rekommendations-
 *                                  lexikonet (serverside-dubbelkoll, 8.0.5/8.8).
 *
 * H1 (existens läcker aldrig): okänt/ej-behörigt ärende ELLER okänt utkast kastar
 * DoesNotExistException (404), ALDRIG denna. Denna kastas först när anroparen
 * bevisligen ser utkastet men beslutet kolliderar med invarianten.
 */
class AiUtkastKonfliktException extends \RuntimeException {
    public const FELKOD_REDAN_AVGJORT = 'redan_avgjord';
    public const FELKOD_UTFALLSSPARR = 'utfallssparr';

    public function __construct(
        private string $felkod,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : $felkod, 0, $previous);
    }

    /** Maskinläsbar felkod för OCS-svaret (aldrig ärendeinnehåll). */
    public function getFelkod(): string {
        return $this->felkod;
    }

    public static function redanAvgjort(string $status): self {
        return new self(
            self::FELKOD_REDAN_AVGJORT,
            'AI-utkastet är redan avgjort (status=' . $status . ')',
        );
    }

    public static function utfallssparr(string $message = 'Beslutsutkastets utfall avviker eller innehåller utfallsförslag'): self {
        return new self(self::FELKOD_UTFALLSSPARR, $message);
    }
}
