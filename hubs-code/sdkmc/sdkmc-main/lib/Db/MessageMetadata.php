<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getMessageId()
 * @method void setMessageId(int $messageId)
 * @method string getMessageType()
 * @method void setMessageType(string $messageType)
 * @method ?array<mixed> getSdkData()
 * @method void setSdkData(array<mixed> $sdkData)
 * @method int getNoReply()
 * @method void setNoReply(int $noReply)
 * @method ?string getSmsNumber()
 * @method void setSmsNumber(?string $smsNumber)
 * @method int getLoaLevel()
 * @method void setLoaLevel(int $loaLevel)
 */
class MessageMetadata extends Entity implements \JsonSerializable {
    protected int $messageId = 0;
    protected string $messageType = '';
    /** @var ?array<mixed> $sdkData */
    protected ?array $sdkData = null;
    protected int $noReply = 0;
    protected ?string $smsNumber = null;
    protected int $loaLevel = 1;

    public function __construct() {
        $this->addType('messageId', 'integer');
        $this->addType('messageType', 'string');
        $this->addType('sdkData', 'json');
        $this->addType('noReply', 'integer');
        $this->addType('smsNumber', 'string');
        $this->addType('loaLevel', 'integer');
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize() {
        return [
            'id' => $this->id,
            'message_id' => $this->messageId,
            'message_type' => $this->messageType,
            'sdk_data' => $this->sdkData,
            'no_reply' => $this->noReply,
            'sms_number' => $this->smsNumber,
            'loa_level' => $this->loaLevel,
        ];
    }
}
