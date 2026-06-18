<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Db;

use OCP\AppFramework\Db\Entity;
use DateTime;

/**
 * @method ?string getMessageType()
 * @method void setMessageType(?string $messageType)
 * @method ?string getApId()
 * @method void setApId(?string $apId)
 * @method ?DateTime getCreationDateTime()
 * @method void setCreationDateTime(?DateTime $creationDateTime)
 * @method ?DateTime getFromClient()
 * @method void setFromClient(?DateTime $fromClient)
 * @method ?DateTime getToClient()
 * @method void setToClient(?DateTime $toClient)
 * @method ?DateTime getFromAp()
 * @method void setFromAp(?DateTime $fromAp)
 * @method ?DateTime getToAp()
 * @method void setToAp(?DateTime $toAp)
 * @method ?string getSender()
 * @method void setSender(?string $sender)
 * @method ?string getSenderAttention()
 * @method void setSenderAttention(?string $senderAttention)
 * @method ?string getRecipient()
 * @method void setRecipient(?string $recipient)
 * @method ?string getRecipientAttention()
 * @method void setRecipientAttention(?string $recipientAttention)
 * @method ?string getMessageIdAs4()
 * @method void setMessageIdAs4(?string $messageIdAs4)
 * @method ?string getMessageId()
 * @method void setMessageId(?string $messageId)
 * @method ?string getConversationId()
 * @method void setConversationId(?string $conversationId)
 * @method ?string getAddressBookCopy()
 * @method void setAddressBookCopy(?string $addressBookCopy)
 * @method array<mixed> getLogData()
 * @method void setlogData(array<mixed> $logData)
 * @SuppressWarnings("PHPMD.TooManyFields")
 */
class SdkLog extends Entity implements \JsonSerializable {
    protected ?string $messageType = '';
    protected ?string $apId = '';
    protected ?DateTime $creationDateTime = null;
    protected ?DateTime $fromClient = null;
    protected ?DateTime $toClient = null;
    protected ?DateTime $fromAp = null;
    protected ?DateTime $toAp = null;
    protected ?string $sender = '';
    protected ?string $senderAttention = '';
    protected ?string $recipient = '';
    protected ?string $recipientAttention = '';
    protected ?string $messageIdAs4 = '';
    protected ?string $messageId = '';
    protected ?string $conversationId = '';
    protected ?string $addressBookCopy = '';
    /** @var array<mixed> $logData */
    protected array $logData = [];

    public function __construct() {
        $this->addType('messageType', 'string');
        $this->addType('apId', 'string');
        $this->addType('creationDateTime', 'datetime');
        $this->addType('fromClient', 'datetime');
        $this->addType('toClient', 'datetime');
        $this->addType('fromAp', 'datetime');
        $this->addType('toAp', 'datetime');
        $this->addType('sender', 'string');
        $this->addType('senderAttention', 'string');
        $this->addType('recipient', 'string');
        $this->addType('recipientAttention', 'string');
        $this->addType('messageIdAs4', 'string');
        $this->addType('messageId', 'string');
        $this->addType('conversationId', 'string');
        $this->addType('addressBookCopy', 'string');
        $this->addType('logData', 'json');
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize() {
        return [
            'id' => $this->getId(),
            'message_type' => $this->getMessageType(),
            'ap_id' => $this->getApId(),
            'creation_date_time' => $this->getCreationDateTime(),
            'from_client' => $this->getFromClient(),
            'to_client' => $this->getToClient(),
            'from_ap' => $this->getFromAp(),
            'to_ap' => $this->getToAp(),
            'sender' => $this->getSender(),
            'sender_attention' => $this->getSenderAttention(),
            'recipient' => $this->getRecipient(),
            'recipient_attention' => $this->getRecipientAttention(),
            'message_id_as4' => $this->getMessageIdAs4(),
            'message_id' => $this->getMessageId(),
            'conversation_id' => $this->getConversationId(),
            'address_book_copy' => $this->getAddressBookCopy(),
            'log_data' => $this->getLogData(),
        ];
    }
}
