<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\BackgroundJob;

use OCA\SdkMc\Db\AccountItslMailboxMapper;
use OCA\SdkMc\Service\ConsolidateMailboxesService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Background job that cleans up expired absence-based mailbox access entries.
 * Runs every hour as a safety net in case OutOfOfficeEndedEvent doesn't fire.
 */
class CleanupExpiredAbsenceAccessJob extends SignaledJob {
    public function __construct(
        ITimeFactory $time,
        IAppConfig $appConfig,
        private AccountItslMailboxMapper $accountMailboxMapper,
        private ConsolidateMailboxesService $consolidateService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time, $appConfig);
        $this->setInterval(3600);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    protected function doWork(mixed $argument): void {
        $this->logger->debug('CleanupExpiredAbsenceAccessJob starting');

        try {
            $expiredEntries = $this->accountMailboxMapper->findExpired();

            if (count($expiredEntries) === 0) {
                $this->logger->debug('No expired absence access entries found');
                return;
            }

            $this->logger->info('Found ' . count($expiredEntries) . ' expired absence access entries');

            $deletedCount = 0;
            foreach ($expiredEntries as $entry) {
                try {
                    $this->accountMailboxMapper->delete($entry);
                    $deletedCount++;

                    $this->logger->debug(
                        'Deleted expired absence access: mailbox=' . $entry->getItslMailboxId()
                        . ', replacement=' . $entry->getAccountId()
                        . ', source=' . $entry->getSourceUserId()
                    );
                } catch (\Throwable $e) {
                    $this->logger->error(
                        'Failed to delete expired absence access entry ' . $entry->getId() . ': ' . $e->getMessage(),
                        ['exception' => $e]
                    );
                }
            }

            if ($deletedCount > 0) {
                $this->logger->info("Deleted {$deletedCount} expired absence access entries");

                $this->consolidateService->scheduleConsolidationIfNeeded();
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'CleanupExpiredAbsenceAccessJob failed: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
