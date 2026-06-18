<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Service;

use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Per-ärende NC-grupp = ärenderummets ÅTKOMSTLISTA (beslut: per-ärende-isolering).
 *
 * Varför per-case-grupp och inte enhet-grupp: groupfolders ger åtkomst per GRUPP.
 * Om varje ärenderum grantar hela enhetens grupp ser hela mottagningskretsen ALLA
 * ärenden i enheten (cross-case-läcka, OSL 26 kap inre sekretess bryts). Med en
 * grupp PER hubsCaseId, grantad ENBART på det ärendets folder, ser bara gruppens
 * medlemmar just det rummet — need-to-know på ärendenivå. Gruppen ÄR åtkomstlistan,
 * speglad från {@see \OCA\HubsArende\Db\MemberMapper} (member-ledgern).
 *
 * Livscykel: skapas vid R4 (createCase), synkas vid varje medlemsändring (tilldela /
 * laggTillMedlem / taBortMedlem), och RADERAS vid gallring/rollback/purge så ingen
 * tom grupp blir kvar (antalet grupper begränsas därmed av ANTALET AKTIVA ärenden,
 * inte all-time).
 *
 * Pseudonymt: gid = 'hubs-case-{hubsCaseId}' (UUID v4, ingen PII). displayName likaså.
 *
 * GRACEFUL: ingen groupManager/userManager (positionell testharness) ⇒ varje metod
 * är en no-op; saga/ACL fortsätter degraderat precis som tidigare.
 */
class ArenderumGroupService {
    /** Prefix för per-case-gruppens id. 'hubs-case-' (10) + UUID (36) = 46 ≤ 64. */
    public const GID_PREFIX = 'hubs-case-';

    public function __construct(
        private ?IGroupManager $groupManager = null,
        private ?IUserManager $userManager = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /** The deterministic per-case group id (pseudonym; no PII). */
    public function gidFor(string $hubsCaseId): string {
        return self::GID_PREFIX . $hubsCaseId;
    }

    /**
     * Ensure the per-case group exists; return its gid, or null when group
     * management is unavailable (graceful — caller skips the per-case grant).
     */
    public function ensure(string $hubsCaseId): ?string {
        if ($this->groupManager === null) {
            return null;
        }
        $gid = $this->gidFor($hubsCaseId);
        if (!$this->groupManager->groupExists($gid)) {
            $group = $this->groupManager->createGroup($gid);
            if ($group === null) {
                $this->log('warning', 'kunde ej skapa per-case-grupp (graceful)', $hubsCaseId);
                return null;
            }
            // Pseudonym displayName — id only, no PII.
            if (method_exists($group, 'setDisplayName')) {
                $group->setDisplayName('Hubs ärende ' . $hubsCaseId);
            }
        }
        return $gid;
    }

    /**
     * Make the per-case group membership EXACTLY $uids (add missing, remove extra).
     * Only resolvable, existing users are added. The group is the case's access
     * list, so this mirrors the member-ledger into actual folder access.
     */
    public function sync(string $hubsCaseId, array $uids): void {
        if ($this->groupManager === null || $this->userManager === null) {
            return;
        }
        $gid = $this->ensure($hubsCaseId);
        if ($gid === null) {
            return;
        }
        $group = $this->groupManager->get($gid);
        if ($group === null) {
            return;
        }

        // Önskad mängd (endast riktiga användare; uid:n som demo-hl-* hoppas).
        $onskad = [];
        foreach ($uids as $uid) {
            $uid = (string)$uid;
            if ($uid !== '' && $this->userManager->userExists($uid)) {
                $onskad[$uid] = true;
            }
        }

        // Nuvarande mängd.
        $nuvarande = [];
        foreach ($group->getUsers() as $user) {
            $nuvarande[$user->getUID()] = true;
        }

        // Lägg till saknade.
        foreach (array_keys($onskad) as $uid) {
            if (!isset($nuvarande[$uid])) {
                $user = $this->userManager->get($uid);
                if ($user !== null) {
                    $group->addUser($user);
                }
            }
        }
        // Ta bort överflödiga.
        foreach (array_keys($nuvarande) as $uid) {
            if (!isset($onskad[$uid])) {
                $user = $this->userManager->get($uid);
                if ($user !== null) {
                    $group->removeUser($user);
                }
            }
        }

        $this->log('info', 'synkade per-case-grupp (' . count($onskad) . ' medlemmar)', $hubsCaseId);
    }

    /**
     * Delete the per-case group (rollback / gallring / purge), so no empty group
     * survives a torn-down ärenderum. Idempotent + graceful.
     */
    public function delete(string $hubsCaseId): void {
        if ($this->groupManager === null) {
            return;
        }
        $group = $this->groupManager->get($this->gidFor($hubsCaseId));
        if ($group !== null) {
            $group->delete();
            $this->log('info', 'raderade per-case-grupp', $hubsCaseId);
        }
    }

    private function log(string $level, string $msg, string $hubsCaseId): void {
        $this->logger?->{$level}('hubs_arende: ArenderumGroupService — ' . $msg, [
            'app' => 'hubs_arende',
            'hubsCaseId' => $hubsCaseId,
        ]);
    }
}
