<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * DEV/DEMO — SYNTHETIC FAVORITES SEEDER.
 *
 * Server-side seeder that plants the 11 curated SYNTHETIC favorite vCards into a
 * per-user CardDAV address book so the Hubs Start "Favoriter" surface has data on
 * a fresh instance. There are NO credentials, NO HTTP/curl, NO tokens here — this
 * is a purely in-process call into the DAV app's internal CardDavBackend.
 *
 * The address book is created with display name "Favoriter" ON PURPOSE: the sdkmc
 * reader (OCA\SdkMc\Service\FavoriterService) discovers favorite books by matching
 * the case-insensitive marker "favoriter" in the address-book display name. Keeping
 * the names aligned is the contract between seeder (writer) and resolver (reader).
 *
 * Every card is SYNTHETIC demo data — function addresses, no citizen PII.
 */

namespace OCA\HubsStart\Service;

use OCA\DAV\CardDAV\CardDavBackend;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Idempotent seeder for the per-user "Favoriter" address book (DEV/DEMO).
 *
 * Flow (all in-process, OCP/DAV-internal API only):
 *   1. principalUri = 'principals/users/' . $userId
 *   2. find-or-create an address book uri 'favoriter' with {DAV:}displayname
 *      'Favoriter' (idempotent — reuses an existing book of that uri).
 *   3. read *.vcf from the deployed demo-data dir and createCard() each one with
 *      cardUri '<UID>.vcf', skipping any card already present.
 *
 * Graceful by construction: a failure on one card is logged and skipped, never
 * aborting the run. Logging is PII-free (only synthetic function names appear).
 */
class FavoriterSeedService {

	/**
	 * URI (slug) of the seeded address book. Stable so re-runs are idempotent.
	 */
	private const ADDRESSBOOK_URI = 'favoriter';

	/**
	 * Display name of the seeded address book. MUST contain "favoriter" so the
	 * sdkmc resolver ({@see \OCA\SdkMc\Service\FavoriterService}) picks the book
	 * up when it filters address books by display name.
	 */
	private const ADDRESSBOOK_DISPLAYNAME = 'Favoriter';

	public function __construct(
		private readonly CardDavBackend $cardDavBackend,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Seed the SYNTHETIC favorites for one user (idempotent, graceful).
	 *
	 * @param string $userId The target user (its CardDAV principal is derived).
	 * @return array{created: int, skipped: int} Per-card outcome tally.
	 */
	public function seed(string $userId): array {
		$created = 0;
		$skipped = 0;

		$principalUri = 'principals/users/' . $userId;

		$addressBookId = $this->ensureAddressBook($principalUri);
		if ($addressBookId === null) {
			// Could not obtain/create the book — nothing else is safe to do.
			return ['created' => 0, 'skipped' => 0];
		}

		foreach ($this->vcardFiles() as $cardUri => $cardData) {
			try {
				if ($this->cardDavBackend->getCard($addressBookId, $cardUri) !== false) {
					// Already present (re-run) — idempotent skip.
					$skipped++;
					continue;
				}

				$this->cardDavBackend->createCard($addressBookId, $cardUri, $cardData);
				$created++;
			} catch (Throwable $e) {
				// One bad card must never fail the whole seed. Log without PII —
				// only the synthetic card uri / function name appears.
				$skipped++;
				$this->logger->warning(
					'[hubs_start] favoriter-seed: kort "' . $cardUri . '" hoppades över: ' . $e->getMessage(),
					['exception' => $e],
				);
			}
		}

		$this->logger->info('[hubs_start] favoriter-seed klar (DEV/DEMO)', [
			'created' => $created,
			'skipped' => $skipped,
		]);

		return ['created' => $created, 'skipped' => $skipped];
	}

	/**
	 * Find the existing 'favoriter' address book for the principal, or create one
	 * with display name 'Favoriter'. Idempotent: an existing book of that uri is
	 * reused rather than duplicated.
	 *
	 * @return int|null The address-book id, or null if it could not be obtained.
	 */
	private function ensureAddressBook(string $principalUri): ?int {
		try {
			$books = $this->cardDavBackend->getAddressBooksForUser($principalUri);
			foreach ($books as $book) {
				if (($book['uri'] ?? null) === self::ADDRESSBOOK_URI) {
					return (int)$book['id'];
				}
			}

			// Not found → create. createAddressBook returns the new id.
			return (int)$this->cardDavBackend->createAddressBook(
				$principalUri,
				self::ADDRESSBOOK_URI,
				['{DAV:}displayname' => self::ADDRESSBOOK_DISPLAYNAME],
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'[hubs_start] favoriter-seed: kunde inte hitta/skapa adressboken "favoriter": ' . $e->getMessage(),
				['exception' => $e],
			);
			return null;
		}
	}

	/**
	 * Load the deployed SYNTHETIC vCard files, keyed by their target cardUri.
	 *
	 * Source dir is bundled with the app at hubs_start/demo-data/favoriter/
	 * (resolved relative to this file). cardUri = '<UID>.vcf' so re-runs match
	 * existing cards by uri and skip them.
	 *
	 * @return array<string, string> Map of cardUri => raw vCard body.
	 */
	private function vcardFiles(): array {
		$dir = __DIR__ . '/../../demo-data/favoriter';

		$files = @glob($dir . '/*.vcf');
		if ($files === false || $files === []) {
			$this->logger->warning('[hubs_start] favoriter-seed: inga .vcf-filer i ' . $dir . ' (DEV/DEMO)');
			return [];
		}

		$out = [];
		foreach ($files as $path) {
			$cardData = @file_get_contents($path);
			if ($cardData === false || trim($cardData) === '') {
				$this->logger->warning('[hubs_start] favoriter-seed: kunde inte läsa ' . basename($path) . ' (hoppas över)');
				continue;
			}

			$uid = $this->uidOf($cardData);
			$cardUri = ($uid !== '' ? $uid : pathinfo($path, PATHINFO_FILENAME)) . '.vcf';
			$out[$cardUri] = $cardData;
		}

		return $out;
	}

	/**
	 * Extract the vCard UID (used to build a stable cardUri). Returns '' when no
	 * UID line is present, letting the caller fall back to the filename.
	 */
	private function uidOf(string $cardData): string {
		if (preg_match('/^UID:(.+)$/mi', $cardData, $m) === 1) {
			return trim($m[1]);
		}
		return '';
	}
}
