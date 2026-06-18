<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Service;

use OCA\SdkMc\Db\MailboxRetentionMapper;

/**
 * Service to manage per-mailbox folder retention overrides.
 */
class MailboxRetentionService {
    /** @var array<string> Valid folder names */
    public const FOLDERS = ['*', 'INBOX', 'Sent', 'Archive', 'Trash', 'Drafts'];

    public function __construct(
        private MailboxRetentionMapper $mapper,
    ) {
    }

    /**
     * Get all retention overrides for a mailbox as folder => days map.
     *
     * @param string $email
     * @return array<string, int|null> folder => retention_days
     */
    public function getOverrides(string $email): array {
        $overrides = [];
        foreach ($this->mapper->findByEmail($email) as $entity) {
            $overrides[$entity->getFolder()] = $entity->getRetentionDays();
        }
        return $overrides;
    }

    /**
     * Save retention overrides for a mailbox.
     *
     * @param string $email
     * @param array<string, int|null> $overrides folder => days (null = remove override)
     */
    public function saveOverrides(string $email, array $overrides): void {
        foreach ($overrides as $folder => $days) {
            if (!in_array($folder, self::FOLDERS, true)) {
                continue; // Skip invalid folders
            }
            if ($days === null) {
                // Remove override (inherit from global)
                $this->mapper->removeRetention($email, $folder);
                continue;
            }
            // Set explicit override (0 = keep forever, >0 = days)
            $this->mapper->setRetention($email, $folder, $days);
        }
    }

    /**
     * Delete all overrides when mailbox is deleted.
     *
     * @param string $email
     */
    public function deleteOverrides(string $email): void {
        $this->mapper->deleteByEmail($email);
    }
}
