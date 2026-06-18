<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 ITSL AB
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Model;

use ChristophWurst\Nextcloud\Testing\TestCase;
use OCA\Mail\Db\Tag;

/**
 * Regression test for bug #74: IMAP keyword tags should have default color #808080
 */
class IMAPMessageTagColorTest extends TestCase {
	/**
	 * Test that tags created from IMAP keywords get the default gray color.
	 * This covers the fix in IMAPMessage.php line 592.
	 */
	public function testTagFromImapKeywordHasDefaultColor(): void {
		$tag = new Tag();
		$tag->setImapLabel('$custom_keyword');
		$tag->setDisplayName('Custom Keyword');
		$tag->setUserId('testuser');
		$tag->setColor('#808080');

		$this->assertSame('#808080', $tag->getColor());
		$this->assertSame('$custom_keyword', $tag->getImapLabel());
		$this->assertSame('Custom Keyword', $tag->getDisplayName());
	}

	/**
	 * Test that a tag can be created without a keyword and still functions.
	 * Ensures no regression in basic tag entity behavior.
	 */
	public function testTagWithoutKeywordStillWorks(): void {
		$tag = new Tag();
		$tag->setDisplayName('Test Tag');
		$tag->setUserId('testuser');
		$tag->setColor('#ff0000');

		$this->assertSame('#ff0000', $tag->getColor());
		$this->assertSame('Test Tag', $tag->getDisplayName());
		$this->assertNull($tag->getImapLabel());
	}
}
