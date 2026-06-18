<?php

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Sections;

use OCP\Settings\IIconSection;
use OCP\IL10N;
use OCP\IURLGenerator;

class SdkMcVueServerSectionAdmin implements IIconSection {
    public function __construct(
        private IL10N $l,
        private IURLGenerator $urlGenerator,
    ) {
    }
    public function getIcon(): string {
        return $this->urlGenerator->imagePath('core', 'actions/mail.svg');
    }
    public function getID(): string {
        return 'SDKServerSettings';
    }

    public function getName(): string {
        return $this->l->t('ITSL Server Settings');
    }

    public function getPriority(): int {
        return 2;
    }
}
