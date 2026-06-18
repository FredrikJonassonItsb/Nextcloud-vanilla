<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Event;

use OCA\Mail\Controller\MessagesController;
use OCP\EventDispatcher\Event;

class FetchEmailEvent extends Event {
    public function __construct(
        private MessagesController &$messagesController,
        private int $id,
        /** @var Array<mixed> $json */
        private array &$json,
        private string $source = 'message',
    ) {
        parent::__construct();
    }

    public function &getMessagesController(): MessagesController {
        return $this->messagesController;
    }

    /**
     * @return Array<mixed>
     */
    public function &getJson(): array {
        return $this->json;
    }

    public function getId(): int {
        return $this->id;
    }

    public function getSource(): string {
        return $this->source;
    }
}
