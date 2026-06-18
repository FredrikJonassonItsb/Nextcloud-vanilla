<?php

/*
 * SPDX-FileCopyrightText: 2025 ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Service;

use OCP\AppFramework\Services\IAppConfig;
use OCA\SdkMc\Interface\ISmsService;
use OCA\SdkMc\Service\Sms\ElkService;
use OCA\SdkMc\Service\Sms\LogService;
use OCA\SdkMc\Service\Sms\GenericService;

class SmsService implements ISmsService {
    private ISmsService $service;
    public function __construct(
        private IAppConfig $appConfig,
        ElkService $elkService,
        LogService $logService,
        GenericService $genericService,
    ) {
        $this->service = match ($this->appConfig->getAppValueString('smsGateway')) {
            '46elks' => $elkService,
            'generic' => $genericService,
            'mock' => $logService,
            default => $logService,
        };
    }

    public function sendSms(string $recipient, string $message): string {
        return $this->service->sendSms($recipient, $message);
    }

    public function getStatus(string $id): string {
        return $this->service->getStatus($id);
    }
}
