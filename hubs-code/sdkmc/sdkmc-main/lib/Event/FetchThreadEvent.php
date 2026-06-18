<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Event;

use OCA\Mail\Controller\MessagesController;
use OCP\EventDispatcher\Event;
use OCA\Mail\Db\Message;

class FetchThreadEvent extends Event {
    /**
     * @param array<Message|array{databaseId: int, mailboxId: int, uid: int, itsl?: array{snoozedUntil?: int, snoozeSrcMailboxId?: int|null}}> $thread
     */
    public function __construct(
        private MessagesController &$messagesController,
        private array &$thread,
    ) {
        parent::__construct();
    }

    public function &getMessagesController(): MessagesController {
        return $this->messagesController;
    }

    /**
     * Returns the thread messages.
     *
     * Note: After FetchThreadListener::handle() serializes messages,
     * this array contains arrays (from jsonSerialize()), not Message objects.
     *
     * @return array<Message|array{databaseId: int, mailboxId: int, uid: int, itsl?: array{snoozedUntil?: int, snoozeSrcMailboxId?: int|null}}>
     */
    public function &getThread(): array {
        return $this->thread;
    }
}
