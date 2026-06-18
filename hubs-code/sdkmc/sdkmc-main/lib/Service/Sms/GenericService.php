<?php

/*
 * SPDX-FileCopyrightText: 2025 ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Service\Sms;

use OCA\SdkMc\Interface\ISmsService;
use Psr\Log\LoggerInterface;
use Exception;

class GenericService implements ISmsService {
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    public function sendSms(string $recipient, string $message): string {
        $this->logger->error('GenericService SMS gateway is not implemented — set smsGateway to 46elks or log');
        throw new Exception('Generic SMS gateway is not implemented');
    }

    public function getStatus(string $id): string {
        throw new Exception('Generic SMS gateway is not implemented');
    }
}
