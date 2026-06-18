<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Event;

use OCA\Mail\Db\Message;
use OCA\Mail\Account;
use OCP\EventDispatcher\Event;

class SerializeMailMessageEvent extends Event {
    public function __construct(
        private Message &$message,
        private Account &$account,
        /** @var Array<mixed> $json */
        private array &$json,
    ) {
        parent::__construct();
    }

    public function &getMessage(): Message {
        return $this->message;
    }

    public function &getAccount(): Account {
        return $this->account;
    }

    /**
     * @return Array<mixed>
     */
    public function &getJson(): array {
        return $this->json;
    }
}
