<?php

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Listener;

use OCA\SdkMc\Event\FetchThreadEvent;
use OCA\SdkMc\Event\FetchEmailEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCA\Mail\Db\Message;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;

/**
 * @implements IEventListener<Event>
 */
class FetchThreadListener implements IEventListener {
    public function __construct(
        private IEventDispatcher $eventDispatcher,
        private IDBConnection $db,
    ) {
    }

    public function handle(Event $event): void {
        if (!($event instanceof FetchThreadEvent)) {
            return;
        }

        foreach ($event->getThread() as &$message) {
            // At this point, messages are still Message objects (not yet serialized)
            assert($message instanceof Message, 'Thread must contain Message objects before serialization');

            // this permanently typecasts the Message to an array.
            // Only reason this works is because the MessageController doesnt do
            // any further processing on the message than converting it to json
            $message = $message->jsonSerialize();
            assert(is_array($message) && array_key_exists('databaseId', $message) && is_int($message['databaseId']));

            $messagesController = $event->getMessagesController();
            $this->eventDispatcher->dispatchTyped(new FetchEmailEvent($messagesController, $message['databaseId'], $message, 'thread'));
        }

        // After all messages serialized, augment with thread-level snooze data
        $this->augmentThreadSnoozeData($event);
    }

    /**
     * Add snoozedUntil to all messages in thread if thread is snoozed.
     * Query once for efficiency, apply to all messages for consistency.
     *
     * Note: By the time this method is called, handle() has already converted
     * Message objects to arrays via jsonSerialize().
     */
    private function augmentThreadSnoozeData(FetchThreadEvent $event): void {
        $thread = &$event->getThread();
        if ($thread === []) {
            return;
        }

        // Try to find snooze data for any message in the thread
        // by directly querying the snooze table (no need for accountId)
        $snoozeData = null;
        foreach ($thread as $message) {
            // Messages are serialized arrays at this point (from jsonSerialize in handle())
            assert(is_array($message), 'Message must be serialized array at this point');
            $snoozeData = $this->getSnoozeData($message['mailboxId'], $message['uid']);
            if ($snoozeData !== null) {
                break;  // Found snooze data
            }
        }

        // If thread is snoozed, add snooze data to ALL messages
        if ($snoozeData !== null) {
            foreach ($thread as &$message) {
                assert(is_array($message), 'Message must be serialized array');
                if (!isset($message['itsl'])) {
                    $message['itsl'] = [];
                }
                $message['itsl']['snoozedUntil'] = $snoozeData['snoozedUntil'];
                $message['itsl']['snoozeSrcMailboxId'] = $snoozeData['srcMailboxId'];
            }
        }
    }

    /**
     * Query snooze data from mail_messages_snoozed table.
     *
     * @return array{snoozedUntil: int, srcMailboxId: int|null}|null
     */
    private function getSnoozeData(int $mailboxId, int $uid): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('snoozed_until', 'src_mailbox_id')
            ->from('mail_messages_snoozed')
            ->where($qb->expr()->eq('mailbox_id', $qb->createNamedParameter($mailboxId)))
            ->andWhere($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));

        $result = $qb->executeQuery();
        /** @var array{snoozed_until: string|int, src_mailbox_id: string|int|null}|false $row */
        $row = $result->fetch();
        $result->closeCursor();

        if ($row === false) {
            return null;
        }

        return [
            'snoozedUntil' => (int)$row['snoozed_until'],
            'srcMailboxId' => $row['src_mailbox_id'] !== null ? (int)$row['src_mailbox_id'] : null,
        ];
    }
}
