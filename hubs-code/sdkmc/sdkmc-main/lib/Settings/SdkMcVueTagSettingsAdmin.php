<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Settings;

use OCP\Settings\IDelegatedSettings;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;

class SdkMcVueTagSettingsAdmin implements IDelegatedSettings {
    public function __construct(
        private IL10N $l,
    ) {
    }

    /**
     * @return TemplateResponse<200, array{}>
     */
    public function getForm(): TemplateResponse {
        return new TemplateResponse('sdkmc', 'empty', []);
    }

    public function getSection(): string {
        return 'SDKServerSettings';
    }

    public function getName(): ?string {
        return $this->l->t('Mail Tag Management');
    }

    public function getPriority(): int {
        return 99;
    }

    /**
     * @return Array<string, Array<string>>
     */
    public function getAuthorizedAppConfig(): array {
        return ['sdkmc' => []];
    }
}
