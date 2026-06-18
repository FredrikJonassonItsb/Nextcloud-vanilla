<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ITSL AB
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Service;

use ChristophWurst\Nextcloud\Testing\TestCase;
use OCA\Mail\Db\MessageSnooze;

/**
 * Regression test for bug #79: Snooze should store per-message srcMailboxId
 * so unsnooze restores each message to its correct source mailbox.
 */
class SnoozeServiceSourceMailboxTest extends TestCase {
	/**
	 * Test that MessageSnooze entity can store and retrieve srcMailboxId.
	 */
	public function testSnoozeStoresSourceMailboxId(): void {
		$snooze = new MessageSnooze();
		$snooze->setMailboxId(100);
		$snooze->setUid(42);
		$snooze->setSnoozedUntil(1700000000);
		$snooze->setSrcMailboxId(200);

		$this->assertSame(200, $snooze->getSrcMailboxId());
		$this->assertSame(100, $snooze->getMailboxId());
		$this->assertSame(42, $snooze->getUid());
	}

	/**
	 * Test that srcMailboxId defaults to null when not explicitly set.
	 * This is the graceful fallback scenario - unsnooze should fall back to INBOX.
	 */
	public function testSnoozeWithNullSrcMailboxIdFallsBackGracefully(): void {
		$snooze = new MessageSnooze();
		$snooze->setMailboxId(100);
		$snooze->setUid(42);
		$snooze->setSnoozedUntil(1700000000);

		$this->assertNull($snooze->getSrcMailboxId());
	}

	/**
	 * Test that different messages in a thread can have different srcMailboxIds.
	 * This is the core fix for #79 - per-message source tracking.
	 */
	public function testDifferentMessagesCanHaveDifferentSourceMailboxes(): void {
		$snooze1 = new MessageSnooze();
		$snooze1->setMailboxId(300); // snooze mailbox
		$snooze1->setUid(1);
		$snooze1->setSnoozedUntil(1700000000);
		$snooze1->setSrcMailboxId(100); // came from mailbox 100

		$snooze2 = new MessageSnooze();
		$snooze2->setMailboxId(300); // same snooze mailbox
		$snooze2->setUid(2);
		$snooze2->setSnoozedUntil(1700000000);
		$snooze2->setSrcMailboxId(200); // came from different mailbox 200

		$this->assertSame(100, $snooze1->getSrcMailboxId());
		$this->assertSame(200, $snooze2->getSrcMailboxId());
		$this->assertNotSame($snooze1->getSrcMailboxId(), $snooze2->getSrcMailboxId());
	}
}
