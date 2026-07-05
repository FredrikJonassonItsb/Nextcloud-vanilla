<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Service;

use OCA\AgentEngine\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * NC bell notifications (rendered Swedish by Notification\Notifier) — the
 * "no silently ignored human" layer (INTERAKTIONSDESIGN §2.8). Native NC
 * notifications ARE the push infrastructure; we build no other.
 *
 * Subject parameters carry ONLY coordination values (card ids, agent codes,
 * timestamps) — never card content.
 */
class NotificationService {
    public const SUBJECT_REFUSED = 'takeover_refused';
    public const SUBJECT_NOT_ENROLLED = 'not_enrolled';
    public const SUBJECT_PRESENCE_STALE = 'presence_stale';
    public const SUBJECT_RECALLED = 'recalled';
    public const SUBJECT_QUESTION = 'question';
    public const SUBJECT_REVIEW_READY = 'review_ready';
    public const SUBJECT_FAILED = 'failed';
    public const SUBJECT_PRECLAIM_STALL = 'preclaim_stall';

    public function __construct(
        private INotificationManager $notificationManager,
        private ITimeFactory $timeFactory,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string,string> $params coordination values only — no content
     */
    public function notify(string $userId, string $subject, array $params, string $objectId): void {
        if ($userId === '') {
            return;
        }
        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp(Application::APP_ID)
                ->setUser($userId)
                ->setDateTime($this->timeFactory->getDateTime())
                ->setObject('agent_engine_card', $objectId !== '' ? $objectId : '0')
                ->setSubject($subject, $params);
            $this->notificationManager->notify($notification);
        } catch (\Throwable $e) {
            // Notification failure must never break the pipeline.
            $this->logger->warning('agent_engine: notification failed', [
                'app' => 'agent_engine',
                'subject' => $subject,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
