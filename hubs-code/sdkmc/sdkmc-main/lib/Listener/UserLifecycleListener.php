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
use OCP\Log\Audit\CriticalActionPerformedEvent;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * Handles user deletion events and triggers mailbox consolidation
 * when deleted users have mailbox assignments.
 *
 * @template-implements IEventListener<Event>
 */
class UserLifecycleListener implements IEventListener {
    public function __construct(
        private AccountItslMailboxMapper $accountMailboxMapper,
        private ConsolidateMailboxesService $consolidateService,
        private IEventDispatcher $eventDispatcher,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if (!($event instanceof UserDeletedEvent)) {
            return;
        }

        $user = $event->getUser();
        $userId = $user->getUID();

        $this->logger->debug(
            "User {$userId} deleted, checking for mailbox assignments"
        );

        try {
            // Delete direct user assignments
            $directCount = $this->accountMailboxMapper->deleteByAccountId($userId);

            // Delete absence entries where user is the replacement
            $replacementCount = $this->accountMailboxMapper->deleteAbsenceByReplacementUserId($userId);

            // Delete absence entries where user is the source (absent user)
            $sourceCount = $this->accountMailboxMapper->deleteBySourceUserId($userId);

            $totalDeleted = $directCount + $replacementCount + $sourceCount;

            if ($totalDeleted === 0) {
                $this->logger->debug(
                    "No mailbox assignments found for user {$userId}"
                );
                return;
            }

            $this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent(
                'Deleted [%d] mailbox assignment(s) due to user [%s] deletion',
                [$totalDeleted, $userId]
            ));
            $this->logger->info(
                "Deleted {$totalDeleted} mailbox assignment(s) for user {$userId} "
                . "(direct: {$directCount}, replacement: {$replacementCount}, source: {$sourceCount}), "
                . 'scheduling consolidation'
            );
            $this->consolidateService->scheduleConsolidationIfNeeded();
        } catch (\Throwable $e) {
            $this->logger->error(
                "Failed to handle user deletion for user {$userId}: " . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }
}
