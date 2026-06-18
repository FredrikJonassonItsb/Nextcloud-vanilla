<?php

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Utils;

use Symfony\Component\String\UnicodeString;

class NameCleaner {
    public static function cleanName(string $name): string {
        return (new UnicodeString($name))
            ->replaceMatches('/\p{Extended_Pictographic}/u', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->toString();
    }
}
