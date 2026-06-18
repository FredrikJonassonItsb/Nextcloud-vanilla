<?php

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Sections;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class SdkMcLog implements IIconSection {
    public function __construct(
        private IL10N $l,
        private IURLGenerator $urlGenerator,
    ) {
    }

    public function getIcon(): string {
        return $this->urlGenerator->imagePath('core', 'actions/toggle-filelist.svg');
    }

    public function getID(): string {
        //return $this->appName;
        return 'SdkMcLog';
    }

    public function getName(): string {
        return $this->l->t('SDK Logs');
    }

    public function getPriority(): int {
        return 2;
    }
}
