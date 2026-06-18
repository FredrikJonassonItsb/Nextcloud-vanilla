<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method string getConversationId()
 * @method void setConversationId(string $conversationId)
 * @method string getEmail()
 * @method void setEmail(string $email)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string|null getRequiredSsn()
 * @method void setRequiredSsn(?string $ssn)
 * @method string|null getLastUsedSsn()
 * @method void setLastUsedSsn(?string $ssn)
 * @method bool getShowFirstName()
 * @method void setShowFirstName(bool $showFirstName)
 * @method bool getShowLastName()
 * @method void setShowLastName(bool $showLastName)
 * @method bool getShowSsn()
 * @method void setShowSsn(bool $showSsn)
 * @method string|null getFirstName()
 * @method void setFirstName(?string $firstName)
 * @method string|null getLastName()
 * @method void setLastName(?string $lastName)
 * @method string|null getActorId()
 * @method void setActorId(?string $lastName)
 * @method int|null getAccountId()
 * @method void setAccountId(?int $accountId)
 */
class ConversationBankIDAuth extends Entity implements JsonSerializable {
    protected string $conversationId = '';
    protected string $email = '';
    protected string $createdAt = '';
    protected ?string $requiredSsn = null;
    protected ?string $lastUsedSsn = null;
    protected bool $showFirstName = true;
    protected bool $showLastName = true;
    protected bool $showSsn = true;
    protected ?string $firstName = null;
    protected ?string $lastName = null;
    protected ?string $actorId = null;
    protected ?int $accountId = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('conversationId', 'string');
        $this->addType('email', 'string');
        $this->addType('createdAt', 'string');
        $this->addType('requiredSsn', 'string');
        $this->addType('lastUsedSsn', 'string');
        $this->addType('showFirstName', 'boolean');
        $this->addType('showLastName', 'boolean');
        $this->addType('showSsn', 'boolean');
        $this->addType('firstName', 'string');
        $this->addType('lastName', 'string');
        $this->addType('actorId', 'string');
        $this->addType('accountId', 'integer');
    }

    /**
     * Verify if provided SSN matches stored ssn
     *
     * @param string $ssn Raw SSN to verify
     * @return bool True if SSN matches
     */
    public function verifySsn(string $ssn): bool {
        if ($this->requiredSsn === null || $this->requiredSsn === '') {
            return false;
        }
        return hash_equals($this->requiredSsn, trim($ssn));
    }

    /**
     * Check if SSN requirement is set
     *
     * @return bool True if SSN is required for this auth
     */
    public function hasSsnRequirement(): bool {
        return $this->requiredSsn !== null && $this->requiredSsn !== '';
    }

    /**
     * Get Display Name based on configuration
     *
     * @return string Display name based on show* configuration flags
     */
    public function getDisplayName(): string {
        $parts = [];

        if ($this->showFirstName && $this->firstName !== null && $this->firstName !== '') {
            $parts[] = trim($this->firstName);
        }

        if ($this->showLastName && $this->lastName !== null && $this->lastName !== '') {
            $parts[] = trim($this->lastName);
        }

        // Build the main name part
        $displayName = implode(' ', $parts);

        // Add SSN if configured to show and exists
        if ($this->showSsn && $this->lastUsedSsn !== null && $this->lastUsedSsn !== '') {
            if ($displayName !== '') {
                $displayName .= ' (' . $this->lastUsedSsn . ')';
                return $displayName;
            }
            $displayName = $this->lastUsedSsn;
        }

        return $displayName;
    }

    /**
     * Get Full Name, should be accessible by moderators only
     *
     * @return string Returns First name + Last name + ( SSN )
     */
    public function getFullName(): string {
        $parts = [];

        if ($this->firstName !== null && $this->firstName !== '') {
            $parts[] = trim($this->firstName);
        }

        if ($this->lastName !== null && $this->lastName !== '') {
            $parts[] = trim($this->lastName);
        }

        $fullName = implode(' ', $parts);

        if ($this->lastUsedSsn !== null && $this->lastUsedSsn !== '') {
            if ($fullName !== '') {
                $fullName .= ' (' . $this->lastUsedSsn . ')';
                return $fullName;
            }
            $fullName = $this->lastUsedSsn;
        }

        // Return email if no other information is available
        if ($fullName === '') {
            return $this->email;
        }

        return $fullName;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'conversationId' => $this->getConversationId(),
            'email' => $this->getEmail(),
            'createdAt' => $this->getCreatedAt(),
            'hasSsnRequirement' => $this->hasSsnRequirement(),
            'showFirstName' => $this->getShowFirstName(),
            'showLastName' => $this->getShowLastName(),
            'showSsn' => $this->getShowSsn(),
            'displayName' => $this->getDisplayName(),
        ];
    }
}
