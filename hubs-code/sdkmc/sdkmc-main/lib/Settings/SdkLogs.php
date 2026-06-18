<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\Settings\IDelegatedSettings;
use OCP\AppFramework\Http;
use OCP\Util;

class SdkLogs implements IDelegatedSettings {
    public function __construct(
        private string $appName,
        private IL10N $l,
    ) {
    }

    /**
     * @return TemplateResponse<Http::STATUS_*, array<string, mixed>>
     */
    public function getForm(): TemplateResponse {
        Util::addScript('sdkmc', 'sdkmc-logs-page');

        return (new TemplateResponse($this->appName, 'SdkLogs'))->setParams([]);
    }

    public function getSection(): string {
        return 'SdkMcLog';
    }

    public function getPriority(): int {
        return 100;
    }

    public function getName(): ?string {
        return $this->l->t('Sdk Logs');
    }

    /**
     * @return Array<string, Array<string>>
     */
    public function getAuthorizedAppConfig(): array {
        return [ 'sdkmc' => [] ];
    }
}
