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
 * @method string getImapMessageId()
 * @method void setImapMessageId(string $imapMessageId)
 * @method int getTagId()
 * @method void setTagId(int $tagId)
 * @method string getEmailAddress()
 * @method void setEmailAddress(string $emailAddress)
 */
class ItslMessageTag extends Entity implements \JsonSerializable {
    protected string $imapMessageId = '';
    protected int $tagId = 0;
    protected string $emailAddress = '';

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('imapMessageId', 'string');
        $this->addType('tagId', 'integer');
        $this->addType('emailAddress', 'string');
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize() {
        return [
            'id' => $this->id,
            'imapMessageId' => $this->imapMessageId,
            'tagId' => $this->tagId,
            'emailAddress' => $this->emailAddress,
        ];
    }
}
