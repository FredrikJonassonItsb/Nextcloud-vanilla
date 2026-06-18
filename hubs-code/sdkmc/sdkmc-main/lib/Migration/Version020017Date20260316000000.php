<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Migration;

use Closure;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * See #1: Set default lobby state to active (1) for new Talk conversations
 * when no admin has explicitly configured a value yet.
 *
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 */
class Version020017Date20260316000000 extends SimpleMigrationStep {
    public function __construct(
        private IAppConfig $appConfig,
    ) {
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        if (!$this->appConfig->hasKey('spreed', 'default_lobby_state')) {
            $this->appConfig->setValueInt('spreed', 'default_lobby_state', 1);
            $output->info('Set default_lobby_state to 1 (lobby active) for new Talk conversations');
        }
    }
}
