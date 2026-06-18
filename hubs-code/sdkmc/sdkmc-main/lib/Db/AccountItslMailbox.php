<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method int getItslMailboxId()
 * @method void setItslMailboxId(int $itslMailboxId)
 * @method string getAccessType()
 * @method void setAccessType(string $accessType)
 * @method ?string getAccountId()
 * @method void setAccountId(?string $accountId)
 * @method ?string getGroupId()
 * @method void setGroupId(?string $groupId)
 * @method ?string getSourceUserId()
 * @method void setSourceUserId(?string $sourceUserId)
 * @method ?int getEndTime()
 * @method void setEndTime(?int $endTime)
 */
class AccountItslMailbox extends Entity implements \JsonSerializable {
    protected int $itslMailboxId = 0;
    protected string $accessType = '';
    protected ?string $accountId = null;
    protected ?string $groupId = null;
    protected ?string $sourceUserId = null;
    protected ?int $endTime = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('itslMailboxId', 'integer');
        $this->addType('accessType', 'string');
        $this->addType('accountId', 'string');
        $this->addType('groupId', 'string');
        $this->addType('sourceUserId', 'string');
        $this->addType('endTime', 'integer');
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize() {
        return [
            'id' => $this->id,
            'itslMailboxId' => $this->itslMailboxId,
            'accessType' => $this->accessType,
            'accountId' => $this->accountId,
            'groupId' => $this->groupId,
            'sourceUserId' => $this->sourceUserId,
            'endTime' => $this->endTime,
        ];
    }
}
