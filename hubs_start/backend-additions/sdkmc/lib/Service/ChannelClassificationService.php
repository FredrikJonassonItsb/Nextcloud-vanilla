<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * NEW FILE for the sdkmc app. Target: lib/Service/ChannelClassificationService.php
 */

namespace OCA\SdkMc\Service;

use OCP\IL10N;

/**
 * THE single, authoritative home for Hubs channel classification.
 *
 * Historically this suffix logic was duplicated on the client (mail's
 * initITSL.js `getIconTypeForEmail` and `messageTypeUtils.parseAddressInfoFromString`).
 * Smart mottagare and the triage queue both depend on it, so it is consolidated
 * here server-side and returned to clients already resolved. NOTHING else in the
 * stack should re-derive a channel from an address suffix.
 *
 * Suffix rules (kept in sync with mail overlay constants MESSAGE_TYPES):
 *   *@sdk                         → sdk        / sdk_message
 *   *@personlig | *@gruppbox      → internal   / internal_message
 *   *@fax                         → fax        / fax_message
 *   *@sms                         → sms        / sms_message
 *   *.securemail                  → secure     / secure_email
 *   else                          → unknown
 */
class ChannelClassificationService {

    public const CHANNEL_SDK = 'sdk';
    public const CHANNEL_INTERNAL = 'internal';
    public const CHANNEL_SECURE = 'secure';
    public const CHANNEL_FAX = 'fax';
    public const CHANNEL_SMS = 'sms';
    public const CHANNEL_UNKNOWN = 'unknown';

    private const MESSAGE_TYPE = [
        self::CHANNEL_SDK => 'sdk_message',
        self::CHANNEL_INTERNAL => 'internal_message',
        self::CHANNEL_SECURE => 'secure_email',
        self::CHANNEL_FAX => 'fax_message',
        self::CHANNEL_SMS => 'sms_message',
        self::CHANNEL_UNKNOWN => '',
    ];

    public function __construct(
        private IL10N $l,
    ) {
    }

    /**
     * Classify an internal/routable address by its Hubs suffix.
     *
     * @return array{channel: string, channelLabel: string, messageType: string}
     */
    public function classifyAddress(string $address): array {
        $address = strtolower(trim($address));
        $channel = self::CHANNEL_UNKNOWN;

        if ($address === '') {
            return $this->describe($channel);
        }

        if (str_ends_with($address, '@sdk')) {
            $channel = self::CHANNEL_SDK;
        } elseif (str_ends_with($address, '@personlig') || str_ends_with($address, '@gruppbox')) {
            $channel = self::CHANNEL_INTERNAL;
        } elseif (str_ends_with($address, '@fax')) {
            $channel = self::CHANNEL_FAX;
        } elseif (str_ends_with($address, '@sms')) {
            $channel = self::CHANNEL_SMS;
        } elseif (str_ends_with($address, '.securemail')) {
            $channel = self::CHANNEL_SECURE;
        }

        return $this->describe($channel);
    }

    /**
     * Classify a free, human-entered recipient value (citizen email, ssn, fax or
     * mobile number). This is the "Smart mottagare" entry point for values that
     * are NOT yet Hubs pseudo-addresses.
     *
     * Heuristics (deliberately conservative — the user can override, and the
     * override is logged by the caller):
     *   - looks like an e-mail        → secure (säker e-post to citizen)
     *   - 10–13 digits (ssn-like)     → secure (citizen identified by personnummer)
     *   - already a Hubs pseudo-addr  → classifyAddress()
     *   - other digit string          → unknown (let the UI ask sms vs fax)
     *
     * @return array{channel: string, channelLabel: string, messageType: string}
     */
    public function classifyRecipientValue(string $value): array {
        $value = trim($value);
        if ($value === '') {
            return $this->describe(self::CHANNEL_UNKNOWN);
        }

        // Already a Hubs pseudo-address?
        if (preg_match('/@(sdk|personlig|gruppbox|fax|sms)$/i', $value)
            || str_ends_with(strtolower($value), '.securemail')) {
            return $this->classifyAddress($value);
        }

        // E-mail address → citizen secure e-mail.
        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return $this->describe(self::CHANNEL_SECURE);
        }

        // Personnummer-like (10–13 digits, optional separators) → citizen secure.
        $digits = preg_replace('/\D/', '', $value);
        if ($digits !== null && strlen($digits) >= 10 && strlen($digits) <= 13) {
            return $this->describe(self::CHANNEL_SECURE);
        }

        return $this->describe(self::CHANNEL_UNKNOWN);
    }

    /**
     * @return array{channel: string, channelLabel: string, messageType: string}
     */
    private function describe(string $channel): array {
        return [
            'channel' => $channel,
            'channelLabel' => $this->label($channel),
            'messageType' => self::MESSAGE_TYPE[$channel] ?? '',
        ];
    }

    private function label(string $channel): string {
        switch ($channel) {
            case self::CHANNEL_SDK:
                return $this->l->t('SDK-Meddelande');
            case self::CHANNEL_INTERNAL:
                return $this->l->t('Internpost');
            case self::CHANNEL_SECURE:
                return $this->l->t('Säker E-post');
            case self::CHANNEL_FAX:
                return $this->l->t('Fax');
            case self::CHANNEL_SMS:
                return $this->l->t('SMS');
            default:
                return $this->l->t('Okänd kanal');
        }
    }
}
