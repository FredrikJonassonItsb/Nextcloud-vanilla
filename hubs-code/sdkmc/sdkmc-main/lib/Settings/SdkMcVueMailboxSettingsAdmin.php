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
 * @AuthorizedAdminSetting(settings=OCA\SdkMc\Settings\SdkMcVueMailboxSettingsAdmin)
 */
class SdkMcVueMailboxSettingsAdmin implements IDelegatedSettings {
    public function __construct(
        private IL10N $l,
    ) {
    }

    /**
     * @return TemplateResponse<200, array{}>
     */
    public function getForm(): TemplateResponse {
        Util::addScript('sdkmc', 'mailbox-settings-page');

        return new TemplateResponse('sdkmc', 'mailbox-settings.vue', []);
    }

    public function getSection(): string {
        return 'SDKMailboxSettings';
    }

    public function getName(): ?string {
        return $this->l->t('ITSL Mailbox Settings');
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
