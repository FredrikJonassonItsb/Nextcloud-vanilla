<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * NEW FILE for the sdkmc app. Target: lib/Service/NoteToSelfWrapperService.php
 */

namespace OCA\SdkMc\Service;

use OCP\Comments\IComment;
use Psr\Log\LoggerInterface;

/**
 * Thin server-side wrapper around spreed's per-user "Note to self" conversation
 * so the Hubs Start dashboard can read and append the caseworker's own private
 * notes ("Egna anteckningar") without the frontend ever talking to spreed
 * directly.
 *
 * The whole point of this wrapper is GRACEFUL DEGRADATION: sdkmc must boot and
 * serve every other surface even when spreed is not installed. Therefore the
 * constructor type-hints NOTHING from OCA\Talk\* — every spreed class is only
 * referenced lazily, after spreadAvailable() has confirmed it can be loaded, via
 * \OCP\Server::get(). This mirrors the in-process Talk resolution pattern in
 * SecureMeetingService (class_exists guard + \OCP\Server::get), minus the
 * loopback-OCS fallback (a note-to-self room is the user's own and resolves
 * in-process; there is no anonymous OCS path to fall back to).
 *
 * NEVER-SoR: only the opaque comment id, the user's own free text and the
 * timestamp leave this service. No personnummer / case content is read into any
 * register here — these are the user's private notes returned verbatim to that
 * same user.
 */
class NoteToSelfWrapperService {

    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Is spreed (and its NoteToSelfService) loadable in this process? When false
     * the wrapper degrades to honest-empty ([]) on reads and refuses writes.
     */
    private function spreedAvailable(): bool {
        return class_exists(\OCA\Talk\Service\NoteToSelfService::class);
    }

    /**
     * Resolve (creating on first use) the signed-in user's note-to-self room.
     * Only ever called behind a spreedAvailable() guard.
     */
    private function room(string $userId): \OCA\Talk\Room {
        /** @var \OCA\Talk\Service\NoteToSelfService $service */
        $service = \OCP\Server::get(\OCA\Talk\Service\NoteToSelfService::class);
        return $service->ensureNoteToSelfExistsForUser($userId);
    }

    /**
     * List the user's own notes, newest-first.
     *
     * Honest-empty when spreed is absent — never throws out of this read path.
     *
     * @return list<array{id: string, text: string, createdAt: string}>
     */
    public function list(string $userId, int $limit = 50): array {
        if (!$this->spreedAvailable()) {
            return [];
        }

        $room = $this->room($userId);

        /** @var \OCA\Talk\Chat\ChatManager $chatManager */
        $chatManager = \OCP\Server::get(\OCA\Talk\Chat\ChatManager::class);
        // getHistory returns IComment[] newest-first (DESC); offset 0 = latest.
        $comments = $chatManager->getHistory($room, 0, $limit, false);

        $notes = [];
        foreach ($comments as $comment) {
            if (!$comment instanceof IComment) {
                continue;
            }
            // Skip system messages / reactions etc. — only real chat messages
            // (VERB_MESSAGE = 'comment') are caseworker notes.
            if ($comment->getVerb() !== 'comment') {
                continue;
            }
            $notes[] = [
                'id' => $comment->getId(),
                'text' => $comment->getMessage(),
                'createdAt' => $comment->getCreationDateTime()->format(\DateTimeInterface::ATOM),
            ];
        }

        return $notes;
    }

    /**
     * Append a note to the user's own note-to-self room and return it in the
     * same {id,text,createdAt} shape list() emits.
     *
     * Unlike the read path this DOES throw when spreed is unavailable so the
     * controller can answer 503 (a write that silently no-ops would lose the
     * caseworker's note).
     *
     * @return array{id: string, text: string, createdAt: string}
     */
    public function add(string $userId, string $text): array {
        if (!$this->spreedAvailable()) {
            throw new \RuntimeException('Note-to-self is unavailable');
        }

        $room = $this->room($userId);

        /** @var \OCA\Talk\Service\ParticipantService $participantService */
        $participantService = \OCP\Server::get(\OCA\Talk\Service\ParticipantService::class);
        $participant = $participantService->getParticipant($room, $userId, false);

        /** @var \OCA\Talk\Chat\ChatManager $chatManager */
        $chatManager = \OCP\Server::get(\OCA\Talk\Chat\ChatManager::class);
        $comment = $chatManager->sendMessage(
            $room,
            $participant,
            \OCA\Talk\Model\Attendee::ACTOR_USERS,
            $userId,
            $text,
            new \DateTime('now'),
        );

        return [
            'id' => $comment->getId(),
            'text' => $comment->getMessage(),
            'createdAt' => $comment->getCreationDateTime()->format(\DateTimeInterface::ATOM),
        ];
    }
}
