<?php

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Activity;

use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\Activity\IManager;
use OCP\Activity\IProvider;
use OCP\Comments\ICommentsManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;
use Exception;

class Provider implements IProvider {
    protected ?IL10N $l = null;

    public function __construct(
        protected IFactory $languageFactory,
        protected IURLGenerator $url,
        protected ICommentsManager $commentsManager,
        protected IUserManager $userManager,
        protected IManager $activityManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param string $language
     * @param IEvent $event
     * @param IEvent|null $previousEvent
     * @return IEvent
     * @throws UnknownActivityException
     * @since 11.0.0
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function parse($language, IEvent $event, ?IEvent $previousEvent = null): IEvent {
        if ($event->getApp() !== 'mail') {
            throw new UnknownActivityException();
        }

        $l = $this->languageFactory->get('sdkmc', $language);
        $this->l = $l;

        $event->setIcon($this->url->getAbsoluteURL(
            $this->activityManager->getRequirePNG()
                ? $this->url->imagePath('core', 'actions/mail.svg')
                : $this->url->imagePath('core', 'actions/mail.svg')
        ));

        // Combine tag_assignment events for the same thread into one notification
        if ($previousEvent !== null && $this->shouldCombineEvents($event, $previousEvent)) {
            // Pick the best message to link to and update the event
            $this->pickBestMessageForCombinedEvent($event, $previousEvent);
            $event->setChildEvent($previousEvent);

            // Create clean announcement with only required fields for Activity system
            $cleanAnnouncement = [
                'type' => 'announcement',
                'id' => '0',
                'name' => $l->t('You were assigned to a message'),
                'link' => $event->getLink(),
            ];
            $event->setParsedSubject($l->t('You were assigned to a message'));
            $event->setRichSubject('{announcement}', ['announcement' => $cleanAnnouncement]);
            return $event;
        }

        try {
            $params = $event->getSubjectParameters();

            if (
                !array_key_exists('announcement', $params)
                || !is_array($params['announcement'])
                || !array_key_exists('name', $params['announcement'])
                || !in_array($params['announcement']['name'], ['messages_sdk', 'messages_personlig', 'messages_gruppbox', 'messages_fax', 'messages_sms', 'tag_assignment'], true)
            ) {
                $event->setParsedSubject($l->t('New secure message'));
                $event->setRichSubject($l->t('New secure message'));
                return $event;
            }

            /** @var array{type?: string, id?: string|int, name: string, link?: string, mailbox_id?: int, message_id?: int, thread_root_id?: string, flag_seen?: bool} $announcement */
            $announcement = $params['announcement'];
            $messageType = $announcement['name'];
            $event->setParsedSubject($l->t(match($messageType) {
                'messages_sdk' => $l->t('New SDK message'),
                'messages_personlig' => $l->t('New Personal message'),
                'messages_gruppbox' => $l->t('New Group message'),
                'messages_fax' => $l->t('New FAX message'),
                'messages_sms' => $l->t('New SMS message'),
                'tag_assignment' => $l->t('You were assigned to a message'),
                default => $l->t('New secure message'),
            }));

            // Create clean announcement with only required fields (Activity validator rejects extra fields)
            $cleanAnnouncement = [
                'type' => 'announcement',
                'id' => (string)($announcement['id'] ?? '0'),
                'name' => $event->getParsedSubject(),
                'link' => $announcement['link'] ?? $event->getLink(),
            ];
            $event->setRichSubject('{announcement}', ['announcement' => $cleanAnnouncement]);
            return $event;
        } catch (Exception $e) {
            $this->logger->error('Error while parsing new message event', [$e->getMessage()]);
            throw new UnknownActivityException();
        }
    }

    /**
     * Check if two events should be combined (same thread, same type).
     */
    private function shouldCombineEvents(IEvent $event, IEvent $previousEvent): bool {
        // Must both be tag_assignment type
        if ($event->getType() !== 'tag_assignment' || $previousEvent->getType() !== 'tag_assignment') {
            return false;
        }

        // Must be same affected user (tagee)
        if ($event->getAffectedUser() !== $previousEvent->getAffectedUser()) {
            return false;
        }

        // Compare by thread_root_id from event parameters
        $currentThreadRootId = $this->getThreadRootId($event);
        $previousThreadRootId = $this->getThreadRootId($previousEvent);

        return $currentThreadRootId !== null
            && $previousThreadRootId !== null
            && $currentThreadRootId === $previousThreadRootId;
    }

    /**
     * Get thread_root_id from event parameters.
     */
    private function getThreadRootId(IEvent $event): ?string {
        $params = $event->getSubjectParameters();
        if (
            isset($params['announcement'])
            && is_array($params['announcement'])
            && isset($params['announcement']['thread_root_id'])
        ) {
            /** @var array{thread_root_id: string|int} $announcement */
            $announcement = $params['announcement'];
            return (string)$announcement['thread_root_id'];
        }
        return null;
    }

    /**
     * Pick the best message to link to when combining events.
     * Priority: lowest unread message ID, or highest ID if all are read.
     */
    private function pickBestMessageForCombinedEvent(IEvent $event, IEvent $previousEvent): void {
        $currentParams = $event->getSubjectParameters();
        $previousParams = $previousEvent->getSubjectParameters();

        if (
            !isset($currentParams['announcement'])
            || !is_array($currentParams['announcement'])
            || !isset($currentParams['announcement']['message_id'])
            || !isset($previousParams['announcement'])
            || !is_array($previousParams['announcement'])
            || !isset($previousParams['announcement']['message_id'])
        ) {
            return;
        }

        /** @var array{message_id: int, flag_seen?: bool, mailbox_id?: int, link?: string} $currentAnnouncement */
        $currentAnnouncement = $currentParams['announcement'];
        /** @var array{message_id: int, flag_seen?: bool} $previousAnnouncement */
        $previousAnnouncement = $previousParams['announcement'];

        $currentId = $currentAnnouncement['message_id'];
        $previousId = $previousAnnouncement['message_id'];
        $currentSeen = $currentAnnouncement['flag_seen'] ?? true;
        $previousSeen = $previousAnnouncement['flag_seen'] ?? true;
        $mailboxId = $currentAnnouncement['mailbox_id'] ?? 0;

        // Determine the best message ID to use
        $bestId = $this->selectBestMessageId($currentId, $currentSeen, $previousId, $previousSeen);

        // If best is different from current, update the event link
        if ($bestId !== $currentId && $mailboxId > 0) {
            $newLink = $this->url->linkToRouteAbsolute('mail.page.thread', [
                'mailboxId' => $mailboxId,
                'id' => $bestId,
            ]);
            $event->setLink($newLink);

            // Also update the link in parameters
            $currentAnnouncement['link'] = $newLink;
            $currentAnnouncement['message_id'] = $bestId;
            $currentParams['announcement'] = $currentAnnouncement;
            $event->setSubject($event->getSubject(), $currentParams);
        }
    }

    /**
     * Select the best message ID from two candidates.
     * Priority: lowest unread ID, or highest ID if all are read.
     */
    private function selectBestMessageId(int $id1, bool $seen1, int $id2, bool $seen2): int {
        // If one is unread and the other is read, prefer unread
        if (!$seen1 && $seen2) {
            return $id1;
        }
        if (!$seen2 && $seen1) {
            return $id2;
        }

        // Both same state: unread = oldest (min), read = newest (max)
        return $seen1 ? max($id1, $id2) : min($id1, $id2);
    }
}
