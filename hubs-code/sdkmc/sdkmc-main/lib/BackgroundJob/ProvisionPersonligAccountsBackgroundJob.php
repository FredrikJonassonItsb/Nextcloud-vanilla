<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\BackgroundJob;

use OCA\SdkMc\Service\ProvisionPersonligAccountsService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Background job that provisions personlig mailbox accounts for users.
 * Triggered on demand by admin action; row is deleted after execution.
 */
class ProvisionPersonligAccountsBackgroundJob extends SignaledJob {
    public function __construct(
        ITimeFactory $time,
        IAppConfig $appConfig,
        private ProvisionPersonligAccountsService $service,
    ) {
        parent::__construct($time, $appConfig);
        $this->setDeleteAfterRun();
        $this->setInterval(24 * 60 * 60);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    protected function doWork(mixed $argument): void {
        $groupId = null;
        if (is_array($argument) && isset($argument['groupId'])) {
            $groupId = is_string($argument['groupId']) ? $argument['groupId'] : null;
        }
        $this->service->provisionAccounts($groupId);
    }
}
