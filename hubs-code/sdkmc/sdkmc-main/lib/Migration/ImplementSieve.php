<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Migration;

use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use OCA\Mail\Db\MailAccountMapper;
use OCP\AppFramework\Services\IAppConfig;

class ImplementSieve implements IRepairStep {
    public function __construct(
        private MailAccountMapper $mapper,
        private IAppConfig $appConfig,
    ) {
    }

    public function getName(): string {
        return 'Add sieve to existing accounts';
    }

    public function run(IOutput $output): void {
        $imapHost = $this->appConfig->getAppValueString('imapHost');
        $allAccounts = &$this->mapper->getAllAccounts();
        $output->startProgress(count($allAccounts));
        foreach ($allAccounts as &$mailAccount) {
            $mailAccount->setSieveEnabled(true);
            $mailAccount->setSieveHost($imapHost);
            $mailAccount->setSievePort(4190);
            $mailAccount->setSieveUser(null);
            $mailAccount->setSievePassword(null);
            $mailAccount->setSieveSslMode('none');
            $this->mapper->save($mailAccount);
            $output->advance();
        }
        $output->finishProgress();
    }
}
