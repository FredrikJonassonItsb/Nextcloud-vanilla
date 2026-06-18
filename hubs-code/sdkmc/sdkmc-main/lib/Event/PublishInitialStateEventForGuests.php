<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Event;

use OCP\EventDispatcher\Event;
use OCP\AppFramework\Services\IInitialState;

class PublishInitialStateEventForGuests extends Event {
    public function __construct(
        private IInitialState &$initialState,
    ) {
        parent::__construct();
    }

    public function &getInitialState(): IInitialState {
        return $this->initialState;
    }
}
