<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Utils;

class SSNHelper {
    public static function formatSsn(string $ssn): string {
        $trimmed = trim($ssn);
        $originalLength = strlen($trimmed);

        if ($originalLength < 10 || $originalLength > 13) {
            return '';
        }

        // Detect separator before stripping (+ means 100+ years old)
        $hasPlus = str_contains($trimmed, '+');

        $numeric = preg_replace('/\D/', '', $trimmed);
        if ($numeric === null) {
            return '';
        }

        $numericLength = strlen($numeric);
        if ($numericLength !== 10 && $numericLength !== 12) {
            return '';
        }

        if ($numericLength === 10) {
            $yy = (int)substr($numeric, 0, 2);
            // Dynamic threshold: YY > current 2-digit year → 19xx, else 20xx
            $currentYY = (int)date('y');
            $century = $yy > $currentYY ? '19' : '20';
            if ($hasPlus) {
                // + separator means person is 100+
                // Pick the century that makes age >= 100
                $currentYear = (int)date('Y');
                $century = ($currentYear - (2000 + $yy) >= 100) ? '20' : '19';
            }
            $numeric = $century . $numeric;
        }

        return substr($numeric, 0, 8) . '-' . substr($numeric, 8, 4);
    }

    /**
     * Try both century expansions for ambiguous 10-digit SSNs without separator.
     *
     * Returns the primary (from formatSsn) first, then the alternative.
     * For unambiguous inputs (has separator or 12 digits), returns only the primary.
     *
     * @return list<string>
     */
    public static function formatSsnTryBoth(string $ssn): array {
        $primary = self::formatSsn($ssn);
        if ($primary === '') {
            return [];
        }

        // Only ambiguous for bare 10-digit input (no separator, no century)
        $trimmed = trim($ssn);
        if (str_contains($trimmed, '+') || str_contains($trimmed, '-')) {
            return [$primary];
        }
        $numeric = preg_replace('/\D/', '', $trimmed);
        if ($numeric === null || strlen($numeric) !== 10) {
            return [$primary];
        }

        // Build alternative century expansion
        $rest = substr($numeric, 2);
        $alt = (str_starts_with($primary, '19') ? '20' : '19') . substr($numeric, 0, 2) . $rest;
        $altFormatted = substr($alt, 0, 8) . '-' . substr($alt, 8, 4);

        return [$primary, $altFormatted];
    }
}
