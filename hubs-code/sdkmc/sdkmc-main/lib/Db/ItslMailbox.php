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
 * @method string getName()
 * @method void setName(string $name)
 * @method string getDescription()
 * @method void setDescription(string $description)
 * @method string getAlias()
 * @method void setAlias(string $alias)
 * @method string getEmail()
 * @method void setEmail(string $email)
 * @method string getPassword()
 * @method void setPassword(string $password)
 * @method string getMessageType()
 * @method void setMessageType(string $messageType)
 * @method ?string getSdkAddress()
 * @method void setSdkAddress(?string $sdkAddress)
 * @method ?string getNumber()
 * @method void setNumber(?string $number)
 * @method ?string getNotificationEmail()
 * @method void setNotificationEmail(?string $notificationEmail)
 */
class ItslMailbox extends Entity implements \JsonSerializable {
    protected string $name = '';
    protected string $description = '';
    protected string $alias = '';
    protected string $email = '';
    protected string $password = '';
    protected string $messageType = '';
    protected int $canBeRepliedTo = 1;
    protected int $canMessageBeSentTo = 1;
    protected ?string $sdkAddress = null;
    protected ?string $number = null;
    protected ?string $notificationEmail = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('name', 'string');
        $this->addType('description', 'string');
        $this->addType('alias', 'string');
        $this->addType('email', 'string');
        $this->addType('password', 'string');
        $this->addType('messageType', 'string');
        $this->addType('canBeRepliedTo', 'integer');
        $this->addType('canMessageBeSentTo', 'integer');
        $this->addType('sdkAddress', 'string');
        $this->addType('number', 'string');
        $this->addType('notificationEmail', 'string');
    }

    public function getCanBeRepliedTo(): bool {
        return (bool)$this->canBeRepliedTo;
    }

    public function setCanBeRepliedTo(bool $canBeRepliedTo): void {
        $this->markFieldUpdated('canBeRepliedTo');
        $this->canBeRepliedTo = (int)$canBeRepliedTo;
    }

    public function getCanMessageBeSentTo(): bool {
        return (bool)$this->canMessageBeSentTo;
    }

    public function setCanMessageBeSentTo(bool $canMessageBeSentTo): void {
        $this->markFieldUpdated('canMessageBeSentTo');
        $this->canMessageBeSentTo = (int)$canMessageBeSentTo;
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize() {
        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'alias' => $this->alias,
            'email' => $this->email,
            'password' => $this->password,
            'messageType' => $this->messageType,
            'canBeRepliedTo' => (bool)$this->canBeRepliedTo,
            'canMessageBeSentTo' => (bool)$this->canMessageBeSentTo,
        ];

        if ($this->sdkAddress !== null) {
            $data['sdkaddress'] = $this->sdkAddress;
        }

        if ($this->number !== null) {
            $data['number'] = $this->number;
        }

        if ($this->notificationEmail !== null) {
            $data['notificationEmail'] = $this->notificationEmail;
        }

        return $data;
    }
}
