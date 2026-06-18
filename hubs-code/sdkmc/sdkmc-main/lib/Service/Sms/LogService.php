<?php

/*
 * SPDX-FileCopyrightText: 2025 ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Service\Sms;

use OCA\SdkMc\Interface\ISmsService;
use Psr\Log\LoggerInterface;

class LogService implements ISmsService {
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function sendSms(string $recipient, string $message): string {
        $this->logger->debug('Mock SMS send triggered', ['recipient_length' => strlen($recipient)]);
        return '123';
    }

    public function getStatus(string $id): string {
        return 'sent';
    }
}
