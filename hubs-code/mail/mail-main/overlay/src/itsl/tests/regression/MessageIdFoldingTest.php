<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ITSL AB
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Regression test for mail#58 / new-mail#2: IMAP header folding whitespace
 * artifact in message-IDs with long Swedish municipal domain names.
 *
 * When a message-id header exceeds ~80 chars, the IMAP server folds it by
 * inserting CRLF + space. Horde unfolds the CRLF but leaves the space,
 * producing "< uuid@domain>" instead of "<uuid@domain>". This breaks
 * threading, tag lookups, and message-ID comparisons.
 *
 * This test ensures the fix in ImapMessageFetcher.php survives future
 * upstream upgrades by verifying the Horde parser output is properly cleaned.
 */

namespace OCA\Mail\Tests\Regression;

use Horde_Mail_Rfc822_Identification;
use PHPUnit\Framework\TestCase;

class MessageIdFoldingTest extends TestCase {

	/**
	 * Simulate the fix from ImapMessageFetcher.php — must match the deployed code.
	 */
	private static function cleanMessageId(string $raw): string {
		$mid = new Horde_Mail_Rfc822_Identification($raw);
		$id = $mid->ids[0] ?? '';
		return str_starts_with($id, '<') ? '<' . ltrim(substr($id, 1)) : ltrim($id);
	}

	public static function messageIdProvider(): array {
		return [
			'normal short ID' => [
				'<abc@dev11.hubs.se>',
				'<abc@dev11.hubs.se>',
			],
			'folded header — space after <' => [
				'< uuid-1234567890@municipality.kommun.region.county.government.se>',
				'<uuid-1234567890@municipality.kommun.region.county.government.se>',
			],
			'folded header — multiple spaces' => [
				'<  uuid@very.long.swedish.domain.name.se>',
				'<uuid@very.long.swedish.domain.name.se>',
			],
			'folded header — tab artifact' => [
				"<\tuuid@domain.se>",
				'<uuid@domain.se>',
			],
			'Outlook multi-@ ID' => [
				'<abc@@outlook.com>',
				'<abc@@outlook.com>',
			],
			'empty string' => [
				'',
				'',
			],
			'Horde-generated ID' => [
				'<20260327131245.Horde.kSytGQuF9G6Kvryy1i42jCN@dev11.hubs.se>',
				'<20260327131245.Horde.kSytGQuF9G6Kvryy1i42jCN@dev11.hubs.se>',
			],
			'SDK message ID (long)' => [
				'<msg.1774442282706.vertxmail.1@middleware.javamw.itsl.internal>',
				'<msg.1774442282706.vertxmail.1@middleware.javamw.itsl.internal>',
			],
		];
	}

	/**
	 * @dataProvider messageIdProvider
	 */
	public function testMessageIdWhitespaceStripping(string $input, string $expected): void {
		$result = self::cleanMessageId($input);
		$this->assertSame($expected, $result, "Message-ID folding whitespace not properly stripped for: $input");
		// Also verify no leading whitespace inside angle brackets
		if (str_starts_with($result, '<')) {
			$this->assertDoesNotMatchRegularExpression(
				'/^<\s/',
				$result,
				"Message-ID still contains whitespace after '<': $result"
			);
		}
	}

	/**
	 * Verify that the actual ImapMessageFetcher.php contains the fix.
	 * This catches cases where an upgrade accidentally drops the ITSL hunk.
	 */
	public function testImapMessageFetcherContainsFix(): void {
		$fetcherPath = (new \ReflectionClass(\OCA\Mail\IMAP\ImapMessageFetcher::class))->getFileName();
		if ($fetcherPath === false || !file_exists($fetcherPath)) {
			$this->markTestSkipped('ImapMessageFetcher.php not found via composer autoload');
		}
		$source = file_get_contents($fetcherPath);
		$this->assertStringContainsString(
			'ltrim',
			$source,
			'ImapMessageFetcher.php is missing the ltrim() fix for message-ID folding whitespace (mail#58 / new-mail#2)'
		);
		$this->assertStringContainsString(
			'str_starts_with',
			$source,
			'ImapMessageFetcher.php is missing the str_starts_with check for angle brackets (new-mail#2 corrected fix)'
		);
	}
}
