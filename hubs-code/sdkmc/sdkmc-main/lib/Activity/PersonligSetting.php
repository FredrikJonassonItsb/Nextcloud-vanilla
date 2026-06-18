<?php

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Activity;

use OCP\Activity\ActivitySettings;
use OCP\IL10N;

class PersonligSetting extends ActivitySettings {
    public function __construct(
        protected IL10N $l,
    ) {
    }

    public function getIdentifier(): string {
        return 'messages_personlig'; // the identifier of the category it falls under, lowercase and underscore only
    }

    public function getName(): string {
        return $this->l->t('Personal Messages');
    }

    public function getGroupIdentifier() {
        return 'secure_messages'; // the identifyer of the category it falls under
    }

    public function getGroupName() {
        return $this->l->t('Secure messages');
    }

    public function getPriority(): int {
        return 30;
    }

    public function canChangeStream(): bool {
        return true;
    }

    public function isDefaultEnabledStream(): bool {
        return false;
    }

    public function canChangeMail(): bool {
        return true;
    }

    public function isDefaultEnabledMail(): bool {
        return true;
    }
}
