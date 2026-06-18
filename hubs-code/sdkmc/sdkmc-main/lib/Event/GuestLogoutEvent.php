<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Event;

use OCP\EventDispatcher\Event;

class GuestLogoutEvent extends Event {
    private string $token;

    public function __construct(string $token) {
        parent::__construct();
        $this->token = $token;
    }

    public function getForce(): bool {
        return true;
    }

    public function getToken(): string {
        return $this->token;
    }
}
