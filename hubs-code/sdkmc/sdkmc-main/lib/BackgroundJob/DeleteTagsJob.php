<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\BackgroundJob;

use OCA\SdkMc\Service\ItslTagService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Background job for cleaning up soft-deleted tags.
 *
 * Runs daily as a safety net, but can be triggered immediately via
 * BackgroundJobService::executeNow() when a tag is deleted.
 *
 * For each pending tag deletion:
 * 1. Removes IMAP labels from all tagged messages
 * 2. Deletes DB associations in sdkmc_itsl_message_tag
 * 3. Hard deletes the tag record
 */
class DeleteTagsJob extends SignaledJob {
    public function __construct(
        ITimeFactory $time,
        IAppConfig $appConfig,
        private ItslTagService $tagService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time, $appConfig);
        $this->setInterval(24 * 60 * 60);  // Daily scheduled run
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);  // Run during off-hours
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    protected function doWork(mixed $argument): void {
        $this->logger->info('DeleteTagsJob starting');

        try {
            $this->tagService->processAllPendingDeletions();
            $this->logger->info('DeleteTagsJob completed successfully');
        } catch (\Throwable $e) {
            $this->logger->error(
                'DeleteTagsJob failed: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
