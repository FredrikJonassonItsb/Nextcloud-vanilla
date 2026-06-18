<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Entity for per-mailbox folder retention overrides.
 *
 * @method string getEmail()
 * @method void setEmail(string $email)
 * @method string getFolder()
 * @method void setFolder(string $folder)
 * @method int|null getRetentionDays()
 * @method void setRetentionDays(int $days)
 */
class MailboxRetention extends Entity implements \JsonSerializable {
    protected string $email = '';
    protected string $folder = '';
    protected ?int $retentionDays = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('email', 'string');
        $this->addType('folder', 'string');
        $this->addType('retentionDays', 'integer');
    }

    /**
     * @return array{id: int|null, email: string, folder: string, retentionDays: int|null}
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'folder' => $this->folder,
            'retentionDays' => $this->retentionDays,
        ];
    }
}
