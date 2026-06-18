<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Tests\Unit\Service;

use OCA\HubsArende\Db\Arende;
use OCA\HubsArende\Db\ArendeMapper;
use OCA\HubsArende\Service\ArendeService;
use OCA\HubsArende\Service\ArendeTypRegistry;
use OCA\HubsArende\Service\FacksystemCommitService;
use OCA\HubsArende\Service\SakerhetsskyddGrind;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * H1 — object-level enhet authorisation over the sekretess boundary.
 *
 * CONTRACT (granskningsrapport H1, "Åtgärd"): the engine must derive the caller's
 * authorised enheter from an injected IUserSession + IGroupManager and verify that
 * `$arende->getEnhet()` is among them BEFORE any read/write. An unauthorised caller
 * must be told the case "does not exist" (404 / DoesNotExistException) so existence
 * is not leaked. A null session (CLI / smoke / occ — no logged-in user) must be
 * ALLOWED, because the smoke command runs without a user-session and MUST stay green.
 *
 * ── RECONCILIATION NOTES (names that may still change in the parallel build) ──
 *   * The authz check is asserted here as a public method `assertEnhetAtkomst(Arende
 *     $arende): void` that throws DoesNotExistException when the caller is not
 *     authorised for the case's enhet. If the implementer names it differently
 *     (e.g. `assertAtkomst`, `assertEnhetBehorighet`, `kontrolleraEnhetsAtkomst`)
 *     or scopes it per-route inside show()/tilldela()/commit(), rename the calls
 *     in {@see invokeAuthz()} below — the BEHAVIOUR asserted is the stable part.
 *   * IUserSession + IGroupManager are expected as TRAILING OPTIONAL constructor
 *     params (?Type $x = null) appended LAST, after the existing 13-arg signature,
 *     so the positional unit harness and the smoke path are untouched. This test
 *     builds the service with named args to be robust to their exact position; if
 *     the build instead injects a dedicated authz collaborator, adapt setUp().
 *   * Group-membership model assumed: a user is authorised for an enhet when they
 *     are a member of a group whose gid equals the enhet (the simplest mapping the
 *     report's "härled användarens auktoriserade enheter" implies). If the build
 *     maps enhet→group via a prefix or a config table, only {@see authoriseFor()}
 *     needs adjusting.
 */
final class ArendeServiceAuthzTest extends TestCase {
    private ArendeMapper&MockObject $arendeMapper;
    private ArendeTypRegistry&MockObject $typRegistry;
    private SakerhetsskyddGrind&MockObject $grind;
    private FacksystemCommitService&MockObject $commitService;
    private ISecureRandom&MockObject $secureRandom;
    private ITimeFactory&MockObject $timeFactory;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void {
        parent::setUp();

        $this->arendeMapper = $this->createMock(ArendeMapper::class);
        $this->typRegistry = $this->createMock(ArendeTypRegistry::class);
        $this->grind = $this->createMock(SakerhetsskyddGrind::class);
        $this->commitService = $this->createMock(FacksystemCommitService::class);
        $this->secureRandom = $this->createMock(ISecureRandom::class);
        $this->timeFactory = $this->createMock(ITimeFactory::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    // ================================================================== //
    //  Null session (CLI / smoke / occ) MUST be allowed — invariant.
    // ================================================================== //

    public function testNullSessionIsAllowed(): void {
        // A null IUserSession (or a session with no logged-in user) models the
        // occ/CLI/smoke path. The gate MUST NOT throw — smoke runs without a user.
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn(null);
        $groupManager = $this->createMock(IGroupManager::class);

        $service = $this->makeService($session, $groupManager);

        $arende = $this->makeArende('barn-familj@');

        // No exception ⇒ allowed.
        $this->invokeAuthz($service, $arende);
        $this->addToAssertionCount(1);
    }

    public function testNullSessionCollaboratorEntirelyAbsentIsAllowed(): void {
        // The positional unit harness leaves IUserSession/IGroupManager null.
        // With no session collaborator at all the gate must degrade to "allow"
        // (the existing harness + smoke path must keep working unchanged).
        $service = $this->makeService(null, null);

        $arende = $this->makeArende('barn-familj@');

        $this->invokeAuthz($service, $arende);
        $this->addToAssertionCount(1);
    }

    // ================================================================== //
    //  User in the WRONG group → DoesNotExistException (404, not 403).
    // ================================================================== //

    public function testUserInWrongGroupIsDeniedAsNotFound(): void {
        // Caller is authorised for 'aldreomsorg@' but the case belongs to
        // 'barn-familj@' → access denied, surfaced as "does not exist" (no leak).
        $session = $this->createMock(IUserSession::class);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('handlaggare-x');
        $session->method('getUser')->willReturn($user);

        $groupManager = $this->authoriseFor('handlaggare-x', ['aldreomsorg@']);

        $service = $this->makeService($session, $groupManager);

        $arende = $this->makeArende('barn-familj@');

        $this->expectException(DoesNotExistException::class);
        $this->invokeAuthz($service, $arende);
    }

    public function testWrongGroupDenyDoesNotLeakEnhetOr403(): void {
        // AUDIT-GAP (smoke covered only the CLI ALLOW path, never DENY): a real user
        // in the WRONG group must be refused via the 404-shaped DoesNotExistException,
        // and that refusal must NOT disclose the case's enhet nor read as a 403/"behörig"
        // leak — existence and tillhörighet stay indistinguishable from "finns inte".
        $session = $this->createMock(IUserSession::class);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('handlaggare-z');
        $session->method('getUser')->willReturn($user);

        // Authorised for 'aldreomsorg@'; the case lives in 'barn-familj@' (sekretess-gräns).
        $groupManager = $this->authoriseFor('handlaggare-z', ['aldreomsorg@']);

        $service = $this->makeService($session, $groupManager);
        $arende = $this->makeArende('barn-familj@');

        try {
            $this->invokeAuthz($service, $arende);
            $this->fail('Förväntade DoesNotExistException för user i fel grupp (deny-vägen).');
        } catch (DoesNotExistException $e) {
            $message = $e->getMessage();
            // Must not leak the case's enhet.
            $this->assertStringNotContainsStringIgnoringCase('barn-familj', $message);
            // Must not surface as a 403/authorization leak (deny is shaped as "not found").
            $this->assertStringNotContainsStringIgnoringCase('behörig', $message);
            $this->assertStringNotContainsStringIgnoringCase('403', $message);
        }
    }

    public function testUserInCorrectGroupIsAllowed(): void {
        // Positive control: the same user, authorised for the case's enhet, passes.
        $session = $this->createMock(IUserSession::class);
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('handlaggare-y');
        $session->method('getUser')->willReturn($user);

        $groupManager = $this->authoriseFor('handlaggare-y', ['barn-familj@', 'aldreomsorg@']);

        $service = $this->makeService($session, $groupManager);

        $arende = $this->makeArende('barn-familj@');

        $this->invokeAuthz($service, $arende);
        $this->addToAssertionCount(1);
    }

    // ================================================================== //
    //  Helpers
    // ================================================================== //

    /**
     * Build the service with the (assumed trailing-optional) session collaborators.
     * Named-arg construction keeps this robust to the exact parameter POSITION, but
     * NOT to the parameter NAMES: this call assumes the H1 fix appends two trailing
     * optionals named exactly `?IUserSession $userSession = null` and
     * `?IGroupManager $groupManager = null` AFTER the existing 6 optional clients
     * (pekareMapper … calendarClient), per the trailing-optional invariant.
     *
     * RECONCILE: if the build names the params differently (or injects a single
     * dedicated authz collaborator instead), adjust the named keys here. Until those
     * params exist this test will (correctly) fail to construct — that failure is
     * the signal that the H1 collaborators are not yet wired.
     */
    private function makeService(?IUserSession $session, ?IGroupManager $groupManager): ArendeService {
        return new ArendeService(
            arendeMapper: $this->arendeMapper,
            typRegistry: $this->typRegistry,
            sakerhetsskyddGrind: $this->grind,
            commitService: $this->commitService,
            secureRandom: $this->secureRandom,
            timeFactory: $this->timeFactory,
            logger: $this->logger,
            userSession: $session,
            groupManager: $groupManager,
        );
    }

    /**
     * Invoke the authorisation gate. The asserted behaviour is: throws
     * DoesNotExistException when unauthorised, returns void otherwise.
     *
     * RECONCILE: rename `assertEnhetAtkomst` if the implementer chose another name.
     */
    private function invokeAuthz(ArendeService $service, Arende $arende): void {
        $service->assertEnhetAtkomst($arende);
    }

    /**
     * A group manager that reports $uid as a member of exactly $enheter (gids).
     */
    private function authoriseFor(string $uid, array $enheter): IGroupManager&MockObject {
        $groups = [];
        foreach ($enheter as $gid) {
            $g = $this->createMock(IGroup::class);
            $g->method('getGID')->willReturn($gid);
            $groups[] = $g;
        }

        $gm = $this->createMock(IGroupManager::class);
        // Two common shapes are supported so the test is robust to whichever the
        // implementer uses to derive the authorised enheter.
        $gm->method('getUserGroupIds')->willReturnCallback(
            static fn (IUser $u): array => $u->getUID() === $uid ? $enheter : [],
        );
        $gm->method('getUserGroups')->willReturnCallback(
            static fn (IUser $u): array => $u->getUID() === $uid ? $groups : [],
        );
        $gm->method('isInGroup')->willReturnCallback(
            static fn (string $u, string $gid): bool => $u === $uid && in_array($gid, $enheter, true),
        );
        return $gm;
    }

    private function makeArende(string $enhet): Arende {
        $arende = new Arende();
        $arende->setHubsCaseId('11111111-2222-4333-8444-555555555555');
        $arende->setEnhet($enhet);
        $arende->setArendeTyp('orosanmalan');
        $arende->setCommitDestination('facksystem');
        return $arende;
    }
}
