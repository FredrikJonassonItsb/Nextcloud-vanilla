<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Event;

use OCA\Mail\Account;
use OCA\Mail\Db\LocalMessage;
use OCP\EventDispatcher\Event;

class DraftSentEvent extends Event {
    public function __construct(
        private Account $account,
        private LocalMessage $message,
        string $raw,
    ) {
        $this->message->setRaw($raw);
        parent::__construct();
    }

    public function getAccount(): Account {
        return $this->account;
    }

    public function getLocalMessage(): LocalMessage {
        return $this->message;
    }
}
