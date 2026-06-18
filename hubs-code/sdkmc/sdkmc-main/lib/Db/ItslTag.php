<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Db;

use OCA\Mail\Db\Tag;

/**
 * ITSL extension of Mail's Tag entity with email-based scoping.
 *
 * Extends Tag to ensure type compatibility where mail app expects Tag objects.
 * The key difference is tags are scoped by emailAddress (for shared mailboxes)
 * instead of userId (per-user).
 *
 * @method string getEmailAddress()
 * @method void setEmailAddress(string $emailAddress)
 * @method bool|null getIsAssignmentTag()
 * @method void setIsAssignmentTag(bool $flag)
 * @method string|null getUsername()
 * @method void setUsername(?string $username)
 * @method \DateTime|null getDeletedAt()
 * @method void setDeletedAt(?\DateTime $deletedAt)
 */
class ItslTag extends Tag {
    protected string $emailAddress = '';
    /** @var bool|null */
    protected $isAssignmentTag;
    /** @var string|null */
    protected $username;
    /** @var \DateTime|null */
    protected $deletedAt;

    public function __construct() {
        parent::__construct();
        $this->addType('emailAddress', 'string');
        $this->addType('isAssignmentTag', 'boolean');
        $this->addType('username', 'string');
        $this->addType('deletedAt', 'datetime');
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize() {
        return [
            'id' => $this->getId(),
            'emailAddress' => $this->emailAddress,
            'imapLabel' => $this->getImapLabel(),
            'displayName' => $this->getDisplayName(),
            'color' => $this->getColor(),
            'isDefaultTag' => $this->getIsDefaultTag() === true,
            'isAssignmentTag' => $this->getIsAssignmentTag() === true,
            'username' => $this->username,
        ];
    }
}
