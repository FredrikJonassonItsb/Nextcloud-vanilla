<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Event;

use OCA\Mail\Db\LocalMessage;
use OCP\EventDispatcher\Event;

class SendEmailEvent extends Event {
    public function __construct(
        /** @var Array<string, string> $headers */
        private array &$headers,
        private LocalMessage $localMessage,
    ) {
        parent::__construct();
    }

    /**
     * @return Array<string, string>
     */
    public function &getHeaders(): array {
        return $this->headers;
    }

    public function getLocalMessage(): LocalMessage {
        return $this->localMessage;
    }
}
