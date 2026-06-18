<?php

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Listener;

use OCA\SdkMc\Db\MessageMetadata;
use OCA\SdkMc\Db\MessageMetadataMapper;
use OCA\SdkMc\Event\SaveOrUpdateDraftEvent;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IRequest;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use OCA\Mail\Db\MailAccountMapper;
use Exception;

/**
 * @implements IEventListener<Event>
 */
class SaveOrUpdateDraftListener implements IEventListener {
    public function __construct(
        private IRequest $request,
        private MessageMetadataMapper $mapper,
        private IEventDispatcher $eventDispatcher,
        private MailAccountMapper $mailAccountMapper,
        private string $userId,
    ) {
    }

    public function handle(Event $event): void {
        if (!($event instanceof SaveOrUpdateDraftEvent)) {
            return;
        }

        $params = $this->request->getParams();
        if (!array_key_exists('itsl', $params) || !is_array($params['itsl'])) {
            return;
        }

        $itsl = $params['itsl'];
        if (!array_key_exists('messageType', $itsl) || !is_string($itsl['messageType'])) {
            throw new Exception('Missing required parameter messageType');
        }

        $noReply = array_key_exists('noReply', $itsl) ? $itsl['noReply'] : '0';
        $noReply = (is_string($noReply) || is_int($noReply)) ? (string)$noReply : '0';
        $noReply = $noReply === '0' ? 0 : 1;
        $itsl['noReply'] = $noReply;

        $message = &$event->getMessage();
        try {
            $mm = $this->mapper->getByMessage($message->getId());
        } catch (DoesNotExistException $e) {
            $mm = new MessageMetadata();
            $mm->setMessageId($message->getId());
            $mm->setMessageType($itsl['messageType']);
            $mm->setNoReply($itsl['noReply']);
        }

        if (array_key_exists('sdk', $itsl) && is_array($itsl['sdk'])) {
            $mm->setSdkData($itsl['sdk']);
        }
        if (array_key_exists('loaLevel', $itsl) && is_numeric($itsl['loaLevel'])) {
            $level = (int)$itsl['loaLevel'];
            if ($level >= 1 && $level <= 3) {
                $mm->setLoaLevel($level);
            }
        }
        if (array_key_exists('smsNumber', $itsl) && is_string($itsl['smsNumber'])) {
            $smsNumber = trim($itsl['smsNumber']);
            if ($smsNumber === '' || $mm->getLoaLevel() !== 2) {
                $mm->setSmsNumber(null);
            }
            if ($smsNumber !== '' && $mm->getLoaLevel() === 2) {
                $cleaned = preg_replace('/[\s\-\(\)]+/', '', $smsNumber);
                if (is_string($cleaned) && preg_match('/^\+[1-9][0-9]{6,14}$/', $cleaned) === 1) {
                    $mm->setSmsNumber($cleaned);
                }
            }
        }
        // Always clear stale smsNumber when loaLevel is not 2 (even if smsNumber not in payload)
        if ($mm->getLoaLevel() !== 2) {
            $mm->setSmsNumber(null);
        }
        $this->mapper->insertOrUpdate($mm);
        $account = $this->mailAccountMapper->findById($message->getAccountId());

        $oldAccountId = $event->getOldAccountId();
        if ($oldAccountId !== null && $oldAccountId !== $message->getAccountId()) {
            $oldAccount = $this->mailAccountMapper->findById($oldAccountId);
            $logLine = 'User %s changed draft id %s from account %s to account %s';
            $logParams = [$this->userId, (string)($message->id), $oldAccount->getEmail(), $account->getEmail()];
            $this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent($logLine, $logParams));
        }

        $draftId = $event->getDraftId();
        if (is_null($draftId)) {
            $verb = str_contains($this->request->getRequestUri(), '/drafts/') ? 'updated' : 'saved';
            $logLine = 'User %s ' . $verb . ' draft id %s of type %s in edit cache for %s';
            $logParams = [$this->userId, (string)($message->id), $itsl['messageType'], $account->getEmail(), $this->request->getRequestUri()];
            $this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent($logLine, $logParams));
            return;
        }

        $logLine = 'User %s edited draft of type %s with database id %s (for %s) and it is now stored in edit cache with draft id %s';
        $logParams = [$this->userId, $itsl['messageType'], $event->getDraftId(), $account->getEmail(), (string)($message->id)];
        $this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent($logLine, $logParams));
    }
}
