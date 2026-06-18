<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Tests\Unit\Integration;

use OCA\HubsArende\Integration\Stub\FacksystemCommitStub;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests against the stateful facksystem-stub (the in-process "Treserva").
 *
 * Two load-bearing invariants are pinned here:
 *  - commit() yields a VERIFIED receipt carrying both a dnr and a gallrasDatum
 *    (synchronous demo mode, callback run in-process).
 *  - retention starts ONLY on the verified callback (GAP-007): in async mode the
 *    commit() receipt is preliminary (gallrasDatum=null, verifierad=false) and
 *    only verifyCallback() sets gallrasDatum + verifierad=true.
 *
 * The stub takes pure config (no OCP deps) so it is exercised directly, no mocks.
 */
final class FacksystemCommitStubTest extends TestCase {
    private const HUBS_CASE_ID = '11111111-2222-4333-8444-555555555555';

    public function testSynchronousCommitReturnsVerifiedReceiptWithDnrAndGallrasDatum(): void {
        // Synchronous mode (default): the verified callback runs in-process.
        $stub = new FacksystemCommitStub(synchronousCallback: true, retentionDays: 90);

        $kvitto = $stub->commit(self::HUBS_CASE_ID, 'ifo_barn', [
            'typ' => 'anmalan',
            'arendetyp' => 'orosanmalan',
            'commit_destination' => 'facksystem',
        ]);

        self::assertTrue($kvitto['ok']);
        self::assertTrue($kvitto['verifierad'], 'Synkront kvitto måste vara verifierat.');
        self::assertNotEmpty($kvitto['dnr']);
        self::assertMatchesRegularExpression('/^2026-IFO-\d{4}$/', $kvitto['dnr']);
        self::assertNotNull($kvitto['gallrasDatum']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $kvitto['gallrasDatum']);
        self::assertSame(self::HUBS_CASE_ID, $kvitto['hubsCaseId']);
        self::assertSame('ifo_barn', $kvitto['modul']);
    }

    public function testRetentionStartsOnlyOnVerifiedCallback(): void {
        // Async mode: commit() returns a PRELIMINARY receipt; no retention yet.
        $stub = new FacksystemCommitStub(synchronousCallback: false, retentionDays: 90);

        $preliminary = $stub->commit(self::HUBS_CASE_ID, 'ifo_barn', [
            'typ' => 'anmalan',
            'arendetyp' => 'orosanmalan',
            'correlationId' => 'corr-1',
        ]);

        // Preliminary: dnr is allotted at registration, but NOT verified, NO gallrasDatum.
        self::assertFalse($preliminary['verifierad']);
        self::assertNull($preliminary['gallrasDatum']);
        self::assertArrayHasKey('callbackToken', $preliminary);
        self::assertNotEmpty($preliminary['dnr']);

        // The register entry must show retention NOT started before the callback.
        $entryBefore = $stub->getEntry(self::HUBS_CASE_ID);
        self::assertNotNull($entryBefore);
        self::assertSame('ej_startad', $entryBefore['retentionState']);
        self::assertNull($entryBefore['gallrasDatum']);
        self::assertSame('ej_registrerad', $entryBefore['provenanceState']);

        // The verified callback is the ONLY place retention starts (GAP-007).
        $verified = $stub->verifyCallback($preliminary['callbackToken'], [
            'hubsCaseId' => self::HUBS_CASE_ID,
            'dnr' => $preliminary['dnr'],
        ]);

        self::assertTrue($verified['verifierad']);
        self::assertNotNull($verified['gallrasDatum']);
        self::assertSame($preliminary['dnr'], $verified['dnr']);

        // Now the register reflects the provenance flip + retention start.
        $entryAfter = $stub->getEntry(self::HUBS_CASE_ID);
        self::assertSame('registrerad', $entryAfter['provenanceState']);
        self::assertSame('gallras_efter_commit', $entryAfter['retentionState']);
        self::assertNotNull($entryAfter['gallrasDatum']);
    }

    public function testGallrasDatumIsRetentionDaysAfterCommit(): void {
        $stub = new FacksystemCommitStub(synchronousCallback: true, retentionDays: 90);

        $kvitto = $stub->commit(self::HUBS_CASE_ID, 'ifo_barn', ['typ' => 'anmalan']);

        // The stub allots gallrasDatum as date-only (Y-m-d) while committedAt is a
        // full ISO timestamp; normalise committedAt to its date so the day-count is
        // not eroded by the time-of-day fraction.
        $committedDate = (new \DateTimeImmutable((string)$kvitto['committedAt']))
            ->setTime(0, 0, 0);
        $gallras = (new \DateTimeImmutable((string)$kvitto['gallrasDatum']))
            ->setTime(0, 0, 0);
        $diffDays = (int)$committedDate->diff($gallras)->format('%a');

        self::assertSame(90, $diffDays, 'gallrasDatum ska vara committedAt + retentionDays.');
    }

    public function testVerifyCallbackIsIdempotent(): void {
        // A re-sent callback must not double-register; it re-returns the same kvitto.
        $stub = new FacksystemCommitStub(synchronousCallback: false);

        $preliminary = $stub->commit(self::HUBS_CASE_ID, 'ifo_barn', [
            'typ' => 'anmalan',
            'correlationId' => 'corr-idem',
        ]);
        $token = $preliminary['callbackToken'];

        $first = $stub->verifyCallback($token, ['hubsCaseId' => self::HUBS_CASE_ID, 'dnr' => $preliminary['dnr']]);
        $second = $stub->verifyCallback($token, ['hubsCaseId' => self::HUBS_CASE_ID, 'dnr' => $preliminary['dnr']]);

        self::assertTrue($first['verifierad']);
        self::assertTrue($second['verifierad']);
        self::assertSame($first['dnr'], $second['dnr']);
        self::assertSame($first['gallrasDatum'], $second['gallrasDatum']);
        // Only ONE verified receipt exists despite two callbacks.
        self::assertCount(1, $stub->listReceipts());
    }
}
