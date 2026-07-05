<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Listener;

use OCA\AgentEngine\Db\CardLinkMapper;
use OCA\AgentEngine\Service\MirrorService;
use OCP\Comments\CommentsEvent;
use OCP\Comments\ICommentsEventHandler;
use Psr\Log\LoggerInterface;

/**
 * In-process latency path for origin→engine mirroring: Deck comments are NC
 * comments with objectType 'deckCard', and the comments manager calls every
 * registered ICommentsEventHandler synchronously on add.
 *
 * Registered in Application::boot() via ICommentsManager::registerEventHandler.
 * Only EVENT_ADD on deckCard objects with an OPEN link is acted on; all four
 * structural loop brakes live in MirrorService::onOriginComment, so double
 * delivery (this handler + the sweep's comment catchup) collapses on the
 * idempotency key.
 */
class CommentsEventHandler implements ICommentsEventHandler {
    public function __construct(
        private CardLinkMapper $linkMapper,
        private MirrorService $mirror,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(CommentsEvent $event): void {
        try {
            if ($event->getEvent() !== CommentsEvent::EVENT_ADD) {
                return;
            }
            $comment = $event->getComment();
            if ($comment->getObjectType() !== 'deckCard') {
                return;
            }
            $cardId = (int)$comment->getObjectId();
            if ($cardId <= 0) {
                return;
            }
            $link = $this->linkMapper->findOpenByOriginCard($cardId);
            if ($link === null) {
                return; // not a linked origin card (engine-card comments included)
            }
            if ($comment->getActorType() !== 'users') {
                return;
            }
            $this->mirror->onOriginComment($link, [
                'id' => (int)$comment->getId(),
                'actorId' => $comment->getActorId(),
                'message' => $comment->getMessage(),
                'timestamp' => $comment->getCreationDateTime()->getTimestamp(),
            ]);
        } catch (\Throwable $e) {
            // Never break the user's comment post; the sweep catches up.
            $this->logger->warning('agent_engine: comment handler failed (sweep will mirror)', [
                'app' => 'agent_engine',
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
