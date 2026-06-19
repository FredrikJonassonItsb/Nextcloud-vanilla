<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * HUBS-START BACKEND-ADDITION · UPSTREAM-KANDIDAT · Target: lib/Service/ArendeEnrichmentService.php
 *
 * NEW FILE for the sdkmc app. Server-side source behind the ärende-kort
 * "diskussion"-enrichment (#3/#15/#16): given an ärenderum's diskussions-token,
 * surface a PII-free *summary* of the room's activity for the card — how many
 * messages, whether the signed-in handläggare is @-omnämnd, and the two most
 * recent distinct deltagare (as opaque actor ids only). The card never renders
 * message bodies, so `meddelanden` stays an honest-empty array here.
 *
 * Data source (in-process spreed, guarded): the diskussions-room is read
 * directly via spreed's Manager/ChatManager when those classes are loadable in
 * this process (class_exists guard + \OCP\Server::get). When spreed is not
 * available — or anything at all goes wrong — this surface degrades to a fully
 * honest-empty shape and NEVER throws to its OCS caller (read path).
 *
 * NEVER-SoR: only PII-free coordination pointers leave this service — the
 * diskussions-token in, and opaque actor-id strings + counts out. No
 * personnummer, no message content, no display-name lookup is performed.
 */

namespace OCA\SdkMc\Service;

use OCP\Comments\IComment;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Hubs Start — the ONE server-side source behind the ärende-kort discussion
 * enrichment. The frontend calls GET /api/v1/arende-enrichment?talkToken=… and
 * renders the returned `diskussion` summary on the card; `meddelanden`/`moten`
 * are owned by the ärende-engine, not by this surface, so they are emitted as
 * honest-empty here.
 */
class ArendeEnrichmentService {

	public function __construct(
		private LoggerInterface $logger,
		private IUserSession $userSession,
	) {
	}

	/**
	 * Enrichment for one ärenderum, keyed by its diskussions-token.
	 *
	 * Shape (the #16 contract — summary only, no bodies):
	 *   {
	 *     diskussion: {
	 *       olasta: int,                 // honest 0 — sdkmc has no cheap unread source here
	 *       omnamnandeTillMig: bool,     // best-effort: any message text contains '@'+currentUid
	 *       deltagare: list<string>,     // up to the 2 most-recent distinct actorIds (opaque)
	 *       meddelanden: list            // honest-empty: the card shows a summary, not bodies
	 *     },
	 *     meddelanden: list,             // honest-empty: engine owns these
	 *     moten: list                    // honest-empty: engine owns these
	 *   }
	 *
	 * Graceful: spreed unavailable, empty token, or any Throwable → the
	 * honest-empty shape. This method never throws.
	 *
	 * @return array{
	 *   diskussion: array{olasta: int, omnamnandeTillMig: bool, deltagare: list<string>, meddelanden: list<mixed>},
	 *   meddelanden: list<mixed>,
	 *   moten: list<mixed>
	 * }
	 */
	public function diskussionForToken(string $talkToken): array {
		if (!$this->spreedAvailable() || $talkToken === '') {
			return $this->emptyEnrichment();
		}

		try {
			/** @var \OCA\Talk\Manager $mgr */
			$mgr = \OCP\Server::get(\OCA\Talk\Manager::class);
			$room = $mgr->getRoomByToken($talkToken);

			/** @var \OCA\Talk\Chat\ChatManager $chatManager */
			$chatManager = \OCP\Server::get(\OCA\Talk\Chat\ChatManager::class);
			// getHistory returns IComment[] in DESC order (newest first).
			$history = $chatManager->getHistory($room, 0, 30, false);

			$currentUid = $this->userSession->getUser()?->getUID() ?? '';
			$mention = $currentUid !== '' ? '@' . $currentUid : null;

			$omnamnande = false;
			$deltagare = [];
			foreach ($history as $comment) {
				if (!$comment instanceof IComment) {
					continue;
				}
				// Only real chat messages count (VERB_MESSAGE === 'comment');
				// system messages, reactions, etc. are not "diskussion" activity.
				if ($comment->getVerb() !== 'comment') {
					continue;
				}

				// Best-effort @-mention of the signed-in handläggare. We never
				// surface the message body — only this boolean leaves the service.
				if (!$omnamnande && $mention !== null && str_contains($comment->getMessage(), $mention)) {
					$omnamnande = true;
				}

				// Up to the 2 most-recent distinct actor ids (history is DESC, so
				// we encounter newest first). Opaque actor-id strings only — NO
				// PII / display-name lookup.
				$actorId = $comment->getActorId();
				if ($actorId !== '' && !in_array($actorId, $deltagare, true) && count($deltagare) < 2) {
					$deltagare[] = $actorId;
				}
			}

			return [
				'diskussion' => [
					// Honest 0: sdkmc can't cheaply compute unread for this room
					// here (no per-user read marker in scope). Keep the key so the
					// card renders; never fabricate a count.
					'olasta' => 0,
					'omnamnandeTillMig' => $omnamnande,
					'deltagare' => $deltagare,
					// Summary-only contract (#16): the card does not show bodies.
					'meddelanden' => [],
				],
				// Engine owns these; honest-empty from the enrichment surface.
				'meddelanden' => [],
				'moten' => [],
			];
		} catch (\Throwable $e) {
			$this->logger->debug('[hubs-start] arende-enrichment: could not read diskussion for token: ' . $e->getMessage(), [
				'exception' => $e,
			]);
			return $this->emptyEnrichment();
		}
	}

	/**
	 * Whether spreed's in-process classes are loadable here. When they are not
	 * (spreed disabled, or not in this process), enrichment degrades to empty.
	 */
	private function spreedAvailable(): bool {
		return class_exists(\OCA\Talk\Manager::class);
	}

	/**
	 * The honest-empty enrichment shape — emitted whenever the diskussion cannot
	 * be read (spreed unavailable, empty token, or any failure).
	 *
	 * @return array{
	 *   diskussion: array{olasta: int, omnamnandeTillMig: bool, deltagare: list<string>, meddelanden: list<mixed>},
	 *   meddelanden: list<mixed>,
	 *   moten: list<mixed>
	 * }
	 */
	private function emptyEnrichment(): array {
		return [
			'diskussion' => $this->emptyDiskussion(),
			'meddelanden' => [],
			'moten' => [],
		];
	}

	/**
	 * The honest-empty `diskussion` block.
	 *
	 * @return array{olasta: int, omnamnandeTillMig: bool, deltagare: list<string>, meddelanden: list<mixed>}
	 */
	private function emptyDiskussion(): array {
		return [
			'olasta' => 0,
			'omnamnandeTillMig' => false,
			'deltagare' => [],
			'meddelanden' => [],
		];
	}
}
