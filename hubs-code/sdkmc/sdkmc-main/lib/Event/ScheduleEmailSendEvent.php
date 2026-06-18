<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Event;

use OCA\Mail\Db\LocalMessage;
use OCP\EventDispatcher\Event;

class ScheduleEmailSendEvent extends Event {
    public function __construct(
        private LocalMessage &$message,
    ) {
        parent::__construct();
    }

    public function &getMessage(): LocalMessage {
        return $this->message;
    }
}
