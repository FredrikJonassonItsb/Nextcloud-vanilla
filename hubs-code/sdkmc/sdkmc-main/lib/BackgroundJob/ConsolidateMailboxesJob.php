<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\BackgroundJob;

use OCA\SdkMc\Service\ConsolidateMailboxesService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Background job that consolidates mailbox access by syncing Mail accounts
 * with effective permissions (direct user assignments + group memberships).
 * Triggered on demand via signal; daily interval is a safety net.
 */
class ConsolidateMailboxesJob extends SignaledJob {
    public function __construct(
        ITimeFactory $time,
        IAppConfig $appConfig,
        private ConsolidateMailboxesService $service,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time, $appConfig);
        $this->setInterval(24 * 60 * 60);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    protected function doWork(mixed $argument): void {
        $this->logger->info('ConsolidateMailboxesJob starting');

        try {
            $this->service->consolidateAllMailboxes();
            $this->logger->info('ConsolidateMailboxesJob completed successfully');
        } catch (\Throwable $e) {
            $this->logger->error(
                'ConsolidateMailboxesJob failed: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
