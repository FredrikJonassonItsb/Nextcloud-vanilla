<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\BackgroundJob;

use OCA\SdkMc\Service\ExpungeService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Background job that runs the email expunge operation daily.
 * Deletes emails older than the configured retention periods.
 */
class ExpungeJob extends SignaledJob {
    public function __construct(
        ITimeFactory $time,
        IAppConfig $appConfig,
        private ExpungeService $expungeService,
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
        $this->logger->debug('ExpungeJob starting');

        try {
            $result = $this->expungeService->executeExpunge();

            $this->logger->info('ExpungeJob completed', [
                'success' => $result['success'],
                'users_processed' => $result['stats']['processed'] ?? 0,
                'total_operations' => $result['total_operations'],
                'warnings' => count($result['warnings']),
                'errors' => count($result['errors']),
            ]);

            // Log any errors at error level
            foreach ($result['errors'] as $error) {
                $this->logger->error('ExpungeJob error: ' . $error);
            }

            // Log warnings at warning level
            foreach ($result['warnings'] as $warning) {
                $this->logger->warning('ExpungeJob warning: ' . $warning);
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'ExpungeJob failed: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
