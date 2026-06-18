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
use OCP\EventDispatcher\IEventDispatcher;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\GroupDeletedEvent;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use Psr\Log\LoggerInterface;

/**
 * Handles group deletion events and triggers mailbox consolidation
 * when deleted groups have mailbox assignments.
 *
 * @template-implements IEventListener<Event>
 */
class GroupLifecycleListener implements IEventListener {
    public function __construct(
        private AccountItslMailboxMapper $accountMailboxMapper,
        private ConsolidateMailboxesService $consolidateService,
        private IEventDispatcher $eventDispatcher,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if (!($event instanceof GroupDeletedEvent)) {
            return;
        }

        $group = $event->getGroup();
        $groupId = $group->getGID();

        $this->logger->debug(
            "Group {$groupId} deleted, checking for mailbox assignments"
        );

        try {
            $deletedCount = $this->accountMailboxMapper->deleteByGroupId($groupId);

            if ($deletedCount === 0) {
                $this->logger->debug(
                    "No mailbox assignments found for group {$groupId}"
                );
                return;
            }

            $this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent(
                'Deleted [%d] mailbox assignment(s) due to group [%s] deletion',
                [$deletedCount, $groupId]
            ));
            $this->logger->info(
                "Deleted {$deletedCount} mailbox assignment(s) for group {$groupId}, scheduling consolidation"
            );
            $this->consolidateService->scheduleConsolidationIfNeeded();
        } catch (\Throwable $e) {
            $this->logger->error(
                "Failed to handle group deletion for group {$groupId}: " . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
