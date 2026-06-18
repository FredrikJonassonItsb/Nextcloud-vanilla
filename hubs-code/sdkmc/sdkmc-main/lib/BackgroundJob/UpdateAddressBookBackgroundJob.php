<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\BackgroundJob;

use OCA\SdkMc\Service\UpdateAddressBookService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Background job that syncs the address book from an external API.
 * Interval is configurable via addressBookUpdateFrequency app setting.
 */
class UpdateAddressBookBackgroundJob extends SignaledJob {
    public function __construct(
        ITimeFactory $time,
        IAppConfig $appConfig,
        private UpdateAddressBookService $abservice,
    ) {
        parent::__construct($time, $appConfig);

        // measured in seconds
        $updateInterval = $this->appConfig->getAppValueInt('addressBookUpdateFrequency', 86400);
        $this->setInterval($updateInterval);
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    protected function doWork(mixed $argument): void {
        $this->abservice->updateAddressBook();
    }
}
