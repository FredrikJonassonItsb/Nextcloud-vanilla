<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Event;

use OCA\Mail\Db\LocalMessage;
use OCP\EventDispatcher\Event;

class SaveOrUpdateDraftEvent extends Event {
    public function __construct(
        private LocalMessage &$message,
        private ?int $draftId,
        private ?int $oldAccountId = null,
    ) {
        parent::__construct();
    }

    public function &getMessage(): LocalMessage {
        return $this->message;
    }

    public function getDraftId(): ?int {
        return $this->draftId;
    }

    public function getOldAccountId(): ?int {
        return $this->oldAccountId;
    }
}
