<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getMessageId()
 * @method void setMessageId(string $messageId)
 * @method string getConversationId()
 * @method void setConversationId(string $conversationId)
 * @method ?string getInReplyTo()
 * @method void setInReplyTo(?string $messageId)
 * @method string getSdkMessageId()
 * @method void setSdkMessageId(string $messageId)
 * @method string getSdkConversationId()
 * @method void setSdkConversationId(string $conversationId)
 * @method ?string getSdkInReplyTo()
 * @method void setSdkInReplyTo(?string $messageId)
 */
class MessageThread extends Entity implements \JsonSerializable {
    protected string $messageId = '';
    protected string $conversationId = '';
    protected ?string $inReplyTo = null;
    protected string $sdkMessageId = '';
    protected string $sdkConversationId = '';
    protected ?string $sdkInReplyTo = null;

    public function __construct() {
        $this->addType('messageId', 'string');
        $this->addType('conversationId', 'string');
        $this->addType('inReplyTo', 'string');
        $this->addType('sdkMessageId', 'string');
        $this->addType('sdkConversationId', 'string');
        $this->addType('sdkInReplyTo', 'string');
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize() {
        return [
            'email' => [
                'messageId' => $this->messageId,
                'conversationId' => $this->conversationId,
                'inReplyTo' => $this->inReplyTo,
            ], 'sdk' => [
                'messageId' => $this->sdkMessageId,
                'conversationId' => $this->sdkConversationId,
                'inReplyTo' => $this->sdkInReplyTo,
            ],
            'isNewConversation' => is_null($this->sdkInReplyTo)
        ];
    }
}
