<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Listener;

use OCA\SdkMc\Db\AccountItslMailbox;
use OCA\SdkMc\Db\AccountItslMailboxMapper;
use OCA\SdkMc\Service\ConsolidateMailboxesService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\User\Events\OutOfOfficeChangedEvent;
use OCP\User\Events\OutOfOfficeScheduledEvent;
use OCP\User\Events\OutOfOfficeEndedEvent;
use OCP\User\Events\OutOfOfficeClearedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use Psr\Log\LoggerInterface;

/**
 * Handles out-of-office events to automatically grant/revoke temporary mailbox access
 * When a user sets up absence with a replacement, the replacement gets temporary access
 * to all mailboxes the absent user has access to.
 *
 * @template-implements IEventListener<Event>
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class OutOfOfficeListener implements IEventListener {
    public function __construct(
        private AccountItslMailboxMapper $accountMailboxMapper,
        private ConsolidateMailboxesService $consolidateService,
        private IGroupManager $groupManager,
        private IUserManager $userManager,
        private IEventDispatcher $eventDispatcher,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if ($event instanceof OutOfOfficeScheduledEvent || $event instanceof OutOfOfficeChangedEvent) {
            $this->handleOutOfOfficeCreatedOrUpdated($event);
        } elseif ($event instanceof OutOfOfficeEndedEvent || $event instanceof OutOfOfficeClearedEvent) {
            $this->handleOutOfOfficeDeleted($event);
        }
    }

    /**
     * Handle when an out-of-office period is created or updated
     *
     * @param OutOfOfficeScheduledEvent|OutOfOfficeChangedEvent $event
     */
    private function handleOutOfOfficeCreatedOrUpdated($event): void {
        $data = $event->getData();
        $absentUserId = $data->getUser()->getUID();

        $replacementUserId = $data->getReplacementUserId();
        if ($replacementUserId === null) {
            $this->logger->debug(
                "User {$absentUserId} has no replacement user set, skipping mailbox access grants"
            );
            $this->removeAbsenceAccessForUser($absentUserId);
            return;
        }

        $endTime = $data->getEndDate();

        $this->logger->info(
            "Processing absence for user {$absentUserId} with replacement {$replacementUserId}"
        );

        try {
            $this->removeAbsenceAccessForUser($absentUserId);

            $absentUserMailboxIds = $this->getMailboxIdsForUser($absentUserId);
            $replacementUserMailboxIds = $this->getMailboxIdsForUser($replacementUserId);

            $mailboxIdsToGrant = array_diff($absentUserMailboxIds, $replacementUserMailboxIds);

            if (count($mailboxIdsToGrant) === 0) {
                $this->logger->debug(
                    "User {$replacementUserId} already has access to all mailboxes of {$absentUserId}, no absence entries needed",
                    [
                        'absentUserIds' => $absentUserMailboxIds,
                        'replacementUserIds' => $replacementUserMailboxIds,
                    ]
                );
                return;
            }

            foreach ($mailboxIdsToGrant as $mailboxId) {
                try {
                    $absenceEntry = new AccountItslMailbox();
                    $absenceEntry->setItslMailboxId($mailboxId);
                    $absenceEntry->setAccessType('absence');
                    $absenceEntry->setAccountId($replacementUserId);
                    $absenceEntry->setSourceUserId($absentUserId);
                    $absenceEntry->setEndTime($endTime);
                    $absenceEntry->setGroupId(null);

                    $this->accountMailboxMapper->insert($absenceEntry);
                    $this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent(
                        'Granted absence mailbox access: replacement [%s] to mailbox [%s] for absent user [%s]',
                        [$replacementUserId, $mailboxId, $absentUserId]
                    ));
                    $this->logger->info(
                        "Granted absence access for replacement {$replacementUserId} to mailbox {$mailboxId} (source: {$absentUserId})"
                    );
                } catch (\Throwable $e) {
                    $this->logger->error(
                        "Failed to insert absence entry for mailbox {$mailboxId}: " . $e->getMessage(),
                        [
                            'exception' => $e,
                            'mailboxId' => $mailboxId,
                            'replacementUserId' => $replacementUserId,
                            'absentUserId' => $absentUserId,
                            'endTime' => $endTime,
                        ]
                    );
                }
            }

            $this->consolidateService->scheduleConsolidationIfNeeded();
        } catch (\Throwable $e) {
            $this->logger->error(
                "Failed to process absence for user {$absentUserId}: " . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }

    /**
     * Handle when an out-of-office period is deleted or ended
     *
     * @param OutOfOfficeEndedEvent|OutOfOfficeClearedEvent $event
     */
    private function handleOutOfOfficeDeleted($event): void {
        $data = $event->getData();
        $absentUserId = $data->getUser()->getUID();

        $this->logger->info("Removing absence access for user {$absentUserId}");

        $this->removeAbsenceAccessForUser($absentUserId);
    }

    /**
     * Remove all absence-based access entries for a specific absent user
     *
     * @param string $absentUserId
     */
    private function removeAbsenceAccessForUser(string $absentUserId): void {
        try {
            $deletedCount = $this->accountMailboxMapper->deleteBySourceUserId($absentUserId);

            if ($deletedCount === 0) {
                $this->logger->debug(
                    "No absence access entries found for user {$absentUserId}"
                );
                return;
            }

            $this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent(
                'Revoked [%d] absence mailbox access entries for user [%s]',
                [$deletedCount, $absentUserId]
            ));
            $this->logger->info(
                "Removed {$deletedCount} absence access entries for user {$absentUserId}"
            );
            $this->consolidateService->scheduleConsolidationIfNeeded();
        } catch (\Throwable $e) {
            $this->logger->error(
                "Failed to remove absence access for user {$absentUserId}: " . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }

    /**
     * Get all mailbox IDs that a user has access to (direct + group membership)
     *
     * @param string $userId
     * @return array<int>
     */
    private function getMailboxIdsForUser(string $userId): array {
        $mailboxIds = [];

        $directAssignments = $this->accountMailboxMapper->findByAccountId($userId);
        foreach ($directAssignments as $assignment) {
            $mailboxIds[$assignment->getItslMailboxId()] = true;
        }

        $user = $this->userManager->get($userId);
        if ($user !== null) {
            $userGroups = $this->groupManager->getUserGroups($user);
            foreach ($userGroups as $group) {
                $groupId = $group->getGID();
                $groupAssignments = $this->accountMailboxMapper->findByGroupId($groupId);
                foreach ($groupAssignments as $assignment) {
                    $mailboxIds[$assignment->getItslMailboxId()] = true;
                }
            }
        }

        return array_keys($mailboxIds);
    }
}
