<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Db;

use DateTime;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method string getRecipient()
 * @method void setRecipient(string $recipient)
 * @method string getMessageId()
 * @method void setMessageId(string $messageId)
 * @method DateTime getSentAt()
 * @method void setSentAt(DateTime $sentAt)
 */
class MailboxNotificationLog extends Entity implements \JsonSerializable {
    protected string $recipient = '';
    protected string $messageId = '';
    protected DateTime $sentAt;

    public function __construct() {
        $this->sentAt = new DateTime();
        $this->addType('id', 'integer');
        $this->addType('recipient', 'string');
        $this->addType('messageId', 'string');
        $this->addType('sentAt', 'datetime');
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize() {
        return [
            'id' => $this->id,
            'recipient' => $this->recipient,
            'message_id' => $this->messageId,
            'sent_at' => $this->sentAt->format('Y-m-d H:i:s'),
        ];
    }
}
