<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * HUBS-START BACKEND-ADDITION · UPSTREAM-KANDIDAT · Target: lib/Service/TeamService.php
 *
 * NEW FILE for the sdkmc app. Server-side source for the Hubs Start
 * "Enhetschatt"/team side panel (EnhetschattPanel). Resolves the signed-in
 * user's enhet/team membership from Nextcloud's own group model — there is no
 * sdkmc table for "teams"; an enhet *is* an NC group — and lists its members
 * with their display names.
 *
 * Data sources (OCP only):
 *   - \OCP\IGroupManager  → the user's groups (= enheter/team) and their members
 *   - \OCP\IUserManager   → display names (member rows also carry the IUser)
 *
 * Honesty contract: this surface reports membership truthfully and nothing it
 * cannot know. Presence/status fields are emitted as honest neutral values
 * ('unknown'/null), never fabricated "online"/"away" states, because sdkmc has
 * no presence source today. No citizen PII is ever read here — only staff
 * uid + display name from the NC user backend.
 */

namespace OCA\SdkMc\Service;

use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Hubs Start — the ONE server-side source behind `fetchTeam` (see
 * hubs_start/src/services/demo/socialsekreterare.js → `team`). The frontend's
 * EnhetschattPanel renders the returned teams and their members; the shape here
 * is the contract.
 */
class TeamService {

	/**
	 * Groups that are administrative/technical rather than an "enhet" and must
	 * never appear in the staff-facing team panel. Compared case-insensitively
	 * against the GID.
	 *
	 * @var list<string>
	 */
	private const HIDDEN_GROUPS = [
		'admin',
		'guest_app',
	];

	public function __construct(
		private IGroupManager $groupManager,
		private IUserManager $userManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * The signed-in user's enheter/team and their members.
	 *
	 * Shape (one element per group the user belongs to):
	 *   {
	 *     id: string,            // NC group id (GID)
	 *     label: string,         // group display name (falls back to GID)
	 *     memberCount: int,
	 *     olasta: int,           // honest 0 — no chat-unread source server-side yet
	 *     omnamnanden: int,      // honest 0 — no mention source server-side yet
	 *     token: ?string,        // best-effort enhetschatt-room token; honest null when none
	 *     members: list<{
	 *       uid: string,
	 *       namn: string,        // display name, falls back to uid
	 *       roll: string,        // 'medlem' | 'jag' (self), the only roles we can assert
	 *       narvaro: 'unknown',  // honest-neutral: no presence backend
	 *       status: null         // honest-neutral: no status backend
	 *     }>
	 *   }
	 *
	 * Graceful: a user with no groups (or whose groups have no members) yields an
	 * empty list. Any backend failure also degrades to an empty list — this
	 * surface never throws to its OCS caller.
	 *
	 * @return list<array{
	 *   id: string,
	 *   label: string,
	 *   memberCount: int,
	 *   olasta: int,
	 *   omnamnanden: int,
	 *   token: ?string,
	 *   members: list<array{uid: string, namn: string, roll: string, narvaro: string, status: ?string}>
	 * }>
	 */
	public function getTeamsForUser(string $userId): array {
		$user = $this->userManager->get($userId);
		if (!$user instanceof IUser) {
			return [];
		}

		try {
			$groups = $this->groupManager->getUserGroups($user);
		} catch (\Throwable $e) {
			$this->logger->warning('[hubs-start] team: could not resolve groups for ' . $userId . ': ' . $e->getMessage(), [
				'exception' => $e,
			]);
			return [];
		}

		$teams = [];
		foreach ($groups as $group) {
			if (!$group instanceof IGroup) {
				continue;
			}
			if ($this->isHidden($group)) {
				continue;
			}

			$team = $this->buildTeam($group, $userId);
			if ($team !== null) {
				$teams[] = $team;
			}
		}

		// Stable, human-friendly ordering: by label so the panel doesn't reorder
		// between polls.
		usort($teams, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

		return $teams;
	}

	/**
	 * Build the team entry for one group, including its member list.
	 *
	 * @return array{
	 *   id: string,
	 *   label: string,
	 *   memberCount: int,
	 *   olasta: int,
	 *   omnamnanden: int,
	 *   members: list<array{uid: string, namn: string, roll: string, narvaro: string, status: ?string}>
	 * }|null
	 */
	private function buildTeam(IGroup $group, string $selfUserId): ?array {
		$gid = $group->getGID();

		try {
			$users = $group->getUsers();
		} catch (\Throwable $e) {
			$this->logger->debug('[hubs-start] team: could not list members of ' . $gid . ': ' . $e->getMessage());
			$users = [];
		}

		$members = [];
		foreach ($users as $member) {
			if (!$member instanceof IUser) {
				continue;
			}
			$uid = $member->getUID();
			$namn = $member->getDisplayName();
			$members[] = [
				'uid' => $uid,
				'namn' => $namn !== '' ? $namn : $uid,
				// The only role we can assert truthfully from the group model is
				// "this is me" vs "a fellow member"; finer roles live in sdkmc
				// tag/ACL data, not here.
				'roll' => $uid === $selfUserId ? 'jag' : 'medlem',
				// Honest-neutral: sdkmc has no presence backend to read from.
				'narvaro' => 'unknown',
				'status' => null,
			];
		}

		// Stable member ordering by display name.
		usort($members, static fn (array $a, array $b): int => strcasecmp($a['namn'], $b['namn']));

		$label = $group->getDisplayName();

		return [
			'id' => $gid,
			'label' => $label !== '' ? $label : $gid,
			'memberCount' => count($members),
			// Honest zeros: there is no server-side chat-unread / mention source
			// for groups yet. The demo's `team` carried olasta/omnamnanden; we keep
			// the keys (so the panel renders) but never fabricate counts.
			// TODO(hubs-start): wire to Talk room unread once a group→room mapping
			// is available, then these become real.
			'olasta' => 0,
			'omnamnanden' => 0,
			// Best-effort enhetschatt-room token for this enhet (group). NULL is
			// the expected, honest value on instances where no group-bound room is
			// trivially discoverable — the panel just falls back to no deep link.
			'token' => $this->roomTokenForGroup($gid),
			'members' => $members,
		];
	}

	/**
	 * Best-effort resolution of an enhetschatt-room token for an enhet (NC group).
	 *
	 * Null-safe and conservative by design: if spreed's Manager is not loadable in
	 * this process we return null immediately, and even when it is, we only return
	 * a token for a room that is *trivially and unambiguously* bound to the group
	 * (object_type 'room' + object_id === $gid). When nothing matches we return
	 * null — we never invent or guess a room.
	 *
	 * It is ACCEPTABLE and expected that this returns null on dev15 (no group-bound
	 * enhetschatt-rooms provisioned yet). This method NEVER throws.
	 *
	 * NEVER-SoR: only the opaque room token (a PII-free coordination pointer) ever
	 * leaves this method.
	 */
	private function roomTokenForGroup(string $gid): ?string {
		// Guard: in-process spreed must be loadable. On dev15 / minimal instances
		// it may not be — return the honest null rather than risk a fatal.
		if (!class_exists(\OCA\Talk\Manager::class)) {
			return null;
		}

		try {
			/** @var \OCA\Talk\Manager $mgr */
			$mgr = \OCP\Server::get(\OCA\Talk\Manager::class);

			// A group-bound room is one spreed created "for" the group: its
			// object_type is 'room' and object_id is the GID. We only trust this
			// trivial, unambiguous binding; anything fancier (membership scans,
			// fuzzy name matching) would risk surfacing the wrong room, so we
			// decline and return null instead.
			if (!method_exists($mgr, 'getRoomByObject')) {
				return null;
			}

			/** @var object $room */
			$room = $mgr->getRoomByObject('room', $gid);
			$token = $room->getToken();

			return is_string($token) && $token !== '' ? $token : null;
		} catch (\Throwable $e) {
			// No matching room (the common case) or spreed not ready — honest null.
			$this->logger->debug('[hubs-start] team: no group-bound room for ' . $gid . ': ' . $e->getMessage());
			return null;
		}
	}

	/**
	 * Whether a group is an administrative/technical group that should not be
	 * shown as an "enhet" in the staff-facing panel.
	 */
	private function isHidden(IGroup $group): bool {
		$gid = strtolower($group->getGID());
		foreach (self::HIDDEN_GROUPS as $hidden) {
			if ($gid === $hidden) {
				return true;
			}
		}
		return false;
	}
}
