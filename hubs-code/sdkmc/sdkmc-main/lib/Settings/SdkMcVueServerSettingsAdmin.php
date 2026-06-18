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
use OCP\Util;

/**
 * @AuthorizedAdminSetting(settings=OCA\SdkMc\Settings\SdkMcVueServerSettingsAdmin)
 */
class SdkMcVueServerSettingsAdmin implements IDelegatedSettings {
    public function __construct(
        private IL10N $l,
    ) {
    }

    /**
     * @return TemplateResponse<200, array{}>
     */
    public function getForm(): TemplateResponse {
        Util::addScript('sdkmc', 'server-settings-page');

        return new TemplateResponse('sdkmc', 'server-settings.vue', []);
    }

    public function getSection(): string {
        return 'SDKServerSettings';
    }

    public function getName(): ?string {
        return $this->l->t('ITSL Server Settings');
    }

    public function getPriority(): int {
        return 1;
    }

    /**
     * @return Array<string, Array<string>>
     */
    public function getAuthorizedAppConfig(): array {
        return ['sdkmc' => []];
    }
}
