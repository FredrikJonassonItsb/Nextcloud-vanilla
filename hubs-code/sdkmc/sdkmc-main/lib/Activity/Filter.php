<?php

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Activity;

use OCP\Activity\IFilter;
use OCP\IL10N;
use OCP\IURLGenerator;

class Filter implements IFilter {
    public function __construct(
        protected IL10N $l,
        protected IURLGenerator $url,
    ) {
    }

    public function getIdentifier(): string {
        return 'secure_messages';
    }

    public function getName(): string {
        return $this->l->t('Secure messages'); //  http://localhost:8080/apps/activity
    }

    public function getPriority(): int {
        return 10;
    }

    public function getIcon(): string {
        return $this->url->getAbsoluteURL($this->url->imagePath('core', 'actions/mail.svg'));
    }

    public function filterTypes(array $types): array {
        return $types;
    }

    public function allowedApps(): array {
        return ['mail'];
    }
}
