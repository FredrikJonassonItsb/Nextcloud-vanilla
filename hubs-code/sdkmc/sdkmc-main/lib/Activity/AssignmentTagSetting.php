<?php

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Activity;

use OCP\Activity\ActivitySettings;
use OCP\IL10N;

class AssignmentTagSetting extends ActivitySettings {
    public function __construct(
        protected IL10N $l,
    ) {
    }

    public function getIdentifier(): string {
        return 'tag_assignment';
    }

    public function getName(): string {
        return $this->l->t('Assigned to me');
    }

    public function getGroupIdentifier() {
        return 'secure_messages';
    }

    public function getGroupName() {
        return $this->l->t('Secure messages');
    }

    public function getPriority(): int {
        return 70;
    }

    public function canChangeStream(): bool {
        return true;
    }

    public function isDefaultEnabledStream(): bool {
        return true;
    }

    public function canChangeMail(): bool {
        return true;
    }

    public function isDefaultEnabledMail(): bool {
        return true;
    }
}
