<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Event;

use OCA\Mail\Db\LocalMessage;
use OCP\EventDispatcher\Event;

class SerializeLocalMessageEvent extends Event {
    public function __construct(
        private LocalMessage &$message,
        /** @var Array<mixed> $json */
        private array &$json,
    ) {
        parent::__construct();
    }

    public function &getMessage(): LocalMessage {
        return $this->message;
    }

    /**
     * @return Array<mixed>
     */
    public function &getJson(): array {
        return $this->json;
    }
}
