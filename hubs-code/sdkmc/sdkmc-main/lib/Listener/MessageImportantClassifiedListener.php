<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Listener;

use OCA\SdkMc\Event\MessageImportantClassifiedEvent;
use OCA\SdkMc\Service\ItslTagService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Handles MessageImportantClassifiedEvent dispatched by NewMessagesClassifier.
 *
 * Tags the message via ItslTagService which:
 * - Stores the tag association in sdkmc tables (NOT mail tables)
 * - Sets the IMAP flag on the message
 *
 * @template-implements IEventListener<Event>
 */
class MessageImportantClassifiedListener implements IEventListener {
    public function __construct(
        private ItslTagService $tagService,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if (!($event instanceof MessageImportantClassifiedEvent)) {
            return;
        }

        try {
            $account = $event->getAccount();
            $message = $event->getMessage();
            $tag = $event->getTag();

            // Use getMessageId() - the IMAP Message-ID string, NOT getId() (database int)
            $messageId = $message->getMessageId();
            if ($messageId === null) {
                $this->logger->warning('Cannot tag message without Message-ID', [
                    'accountId' => $account->getId(),
                    'messageUid' => $message->getUid(),
                ]);
                return;
            }

            $this->tagService->tagMessage(
                $account->getUserId(),
                $account->getId(),
                $tag->getImapLabel(),
                $messageId,
            );
        } catch (\Exception $e) {
            $this->logger->error('Failed to tag important message: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }
}
