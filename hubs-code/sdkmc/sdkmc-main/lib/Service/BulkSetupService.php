<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Service;

use OCA\Mail\Account;
use OCA\Mail\Service\SetupService;

/**
 * SetupService subclass that skips the IMAP/SMTP connectivity test.
 *
 * The upstream SetupService::testConnectivity() opens SMTP connections that
 * are never explicitly closed, causing "too many connections" errors during
 * bulk provisioning. Since we control the mail server configuration, the
 * connectivity test is unnecessary for programmatic account creation.
 */
class BulkSetupService extends SetupService {
    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    protected function testConnectivity(Account $account): void {
        // No-op: skip connectivity test during bulk provisioning
    }
}
