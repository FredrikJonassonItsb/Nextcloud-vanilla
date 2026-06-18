<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Listener;

use OCA\SdkMc\Db\AccountItslMailboxMapper;
use OCA\SdkMc\Service\ConsolidateMailboxesService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;
use Psr\Log\LoggerInterface;

/**
 * Handles group membership changes (user added/removed from groups)
 * and triggers mailbox consolidation when affected groups have mailbox assignments.
 *
 * @template-implements IEventListener<Event>
 */
class GroupMembershipListener implements IEventListener {
    public function __construct(
        private AccountItslMailboxMapper $accountMailboxMapper,
        private ConsolidateMailboxesService $consolidateService,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        // Handle both UserAddedEvent and UserRemovedEvent with the same logic
        if (!($event instanceof UserAddedEvent) && !($event instanceof UserRemovedEvent)) {
            return;
        }

        $group = $event->getGroup();
        $user = $event->getUser();
        $groupId = $group->getGID();
        $userId = $user->getUID();

        $eventType = $event instanceof UserAddedEvent ? 'added to' : 'removed from';
        $this->logger->debug(
            "User {$userId} {$eventType} group {$groupId}, checking for mailbox assignments"
        );

        try {
            // Check if this group has any mailbox assignments
            $assignments = $this->accountMailboxMapper->findByGroupId($groupId);

            if (count($assignments) === 0) {
                $this->logger->debug(
                    "Group {$groupId} has no mailbox assignments, skipping consolidation"
                );
                return;
            }

            $this->logger->info(
                "Group {$groupId} has " . count($assignments) . ' mailbox assignment(s), scheduling consolidation'
            );
            $this->consolidateService->scheduleConsolidationIfNeeded();
        } catch (\Throwable $e) {
            $this->logger->error(
                "Failed to handle group membership change for user {$userId} in group {$groupId}: " . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
