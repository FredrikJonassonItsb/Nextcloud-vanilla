<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Event;

use OCA\Mail\Account;
use OCA\Mail\Db\Mailbox;
use OCA\Mail\Db\Message;
use OCA\Mail\Db\Tag;
use OCP\EventDispatcher\Event;

/**
 * Event dispatched when a message is classified as important by NewMessagesClassifier.
 *
 * sdkmc listens for this event and handles:
 * - Storing the tag association in sdkmc tables (email-scoped)
 * - Setting the IMAP flag on the message
 */
class MessageImportantClassifiedEvent extends Event {
    public function __construct(
        private Account $account,
        private Mailbox $mailbox,
        private Message $message,
        private Tag $tag,
    ) {
        parent::__construct();
    }

    public function getAccount(): Account {
        return $this->account;
    }

    public function getMailbox(): Mailbox {
        return $this->mailbox;
    }

    public function getMessage(): Message {
        return $this->message;
    }

    public function getTag(): Tag {
        return $this->tag;
    }
}
