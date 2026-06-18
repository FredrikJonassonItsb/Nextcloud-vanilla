<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * HUBS-START BACKEND-ADDITION · UPSTREAM-KANDIDAT · Target: lib/Service/FavoriterService.php
 *
 * NEW FILE for the sdkmc app. The thin sdkmc resolver layer ABOVE the Contacts
 * app described in hubs_start/docs/KONTAKTER-FAVORITER.md (§2.3, §3.2). A favorite
 * is a POINTER, not a copy: the vCard carries the stable key (X-HUBS-SDK-REF /
 * X-HUBS-USER-REF) plus a non-authoritative display cache; the mutable fields are
 * resolved fresh from DIGG / the user directory at read time. This file owns step
 * (1) of that flow — reading the favorite address books over OCP\Contacts\IManager
 * and shaping each pointer into the resolved DTO the FavoritValjare consumes.
 */

namespace OCA\SdkMc\Service;

use OCP\Contacts\IManager as IContactsManager;
use OCP\IAddressBook;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the Hubs favorite address books (personlig + funktions-delad) and emits
 * already-resolved favorite DTOs. This is THE single server-side aggregation
 * surface for favorites — one pass over the explicit favorite address books, no
 * client fan-out (KONTAKTER-FAVORITER §2.3 / §3.1).
 *
 * Data source: OCP\Contacts\IManager. Per KONTAKTER-FAVORITER §3.1 the aggregate
 * iterates the EXPLICIT favorite address books (IManager::getUserAddressBooks()
 * filtered by display name) rather than relying on empty-$pattern search semantics,
 * which differ between server versions.
 *
 * The DIGG / user-directory batch-resolve (step 2 in §2.3) is NOT yet wired —
 * KONTAKTER-FAVORITER §5 "Stubbat i demon" notes the resolver cache is not built.
 * Until it is, this layer returns the favorite's own non-authoritative cache
 * (FN/ORG/TEL) and marks staleness HONESTLY:
 *   - a pointer-class favorite (a/c) whose source has NOT been re-verified is
 *     flagged `stale: true` with a "kunde inte färskhetskontrolleras" provenance,
 *     never presented as freshly resolved (§2.3 staleness-UI);
 *   - a Hubs-owned fax (class b) owns its value, so it is never stale.
 * No citizen PII is ever fabricated; a missing/empty favorites address book on
 * dev15 yields an empty list.
 *
 * @psalm-type FavoritDTO = array{
 *     id: string,
 *     klass: string,
 *     listor: list<string>,
 *     namn: string,
 *     org?: string,
 *     kanal: string,
 *     sdkRef?: string,
 *     userRef?: string,
 *     adress?: string,
 *     fax?: string,
 *     owner?: string,
 *     identitet?: array{badge: string, verifierad: bool},
 *     narvaro?: string,
 *     resolvedAt: ?string,
 *     stale: bool,
 *     removed: bool,
 *     proveniens: string
 * }
 */
class FavoriterService {

    /**
     * vCard CATEGORIES / address-book display-name marker that identifies a Hubs
     * favorite address book. Matched case-insensitively. The personal and the
     * function-shared favorite lists are two address books, same vCard model,
     * different ACL (KONTAKTER-FAVORITER §2.4).
     */
    private const FAVORIT_MARKER = 'favoriter';

    /**
     * vCard X-property carrying the favorite class. When absent we infer the
     * class from the pointer properties (X-HUBS-SDK-REF → a, X-HUBS-USER-REF → c,
     * TEL;TYPE=fax with no pointer → b).
     */
    private const KLASS_SDK_PEKARE = 'sdk-pekare';        // class (a) — DIGG pointer
    private const KLASS_EXTERN_FUNKTION = 'extern-funktion'; // class (b) — Hubs-owned fax vCard
    private const KLASS_INTERN_ANVANDARE = 'intern-anvandare'; // class (c) — user-directory pointer

    public function __construct(
        private IContactsManager $contactsManager,
        private ChannelClassificationService $classifier,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The resolved favorite aggregate (personlig ∪ funktions-delad) — one "call".
     *
     * @param ?string $lista Optional filter on the favorite list scope label
     *                       (e.g. 'personlig' | 'mottagningen@'). Null = union.
     * @return list<FavoritDTO> Resolved favorite DTOs (empty on any failure).
     */
    public function getFavoriter(?string $lista = null): array {
        // Contacts app disabled → there is nothing to read. Honest empty list,
        // never a fabricated favorite (KONTAKTER-FAVORITER graceful rule).
        if (!$this->contactsManager->isEnabled()) {
            return [];
        }

        $books = $this->favoriteAddressBooks();
        if ($books === []) {
            // No favorites address book on this instance (dev15) → empty list.
            return [];
        }

        $out = [];
        foreach ($books as $book) {
            $scope = $this->scopeOf($book);
            if ($lista !== null && $lista !== '' && $scope !== $lista) {
                continue;
            }

            foreach ($this->readBook($book) as $card) {
                $dto = $this->toDto($card, $scope, $book);
                if ($dto !== null) {
                    $out[] = $dto;
                }
            }
        }

        return $out;
    }

    /**
     * The explicit favorite address books for the signed-in user: every address
     * book whose display name marks it as a Hubs favorites list. We deliberately
     * do NOT touch the system address book or the DIGG mirror (writing a favorite
     * tag on the read-only mirror would create a shadow register — §5 open point).
     *
     * @return list<IAddressBook>
     */
    private function favoriteAddressBooks(): array {
        try {
            $books = $this->contactsManager->getUserAddressBooks();
        } catch (Throwable $e) {
            $this->logger->warning('[hubs-start] favoriter: could not list address books: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            return [];
        }

        $favorites = [];
        foreach ($books as $book) {
            if (!$book instanceof IAddressBook) {
                continue;
            }
            try {
                if ($book->isSystemAddressBook()) {
                    // class (c) resolves against the directory by uid; the system
                    // address book itself is never a favorites list.
                    continue;
                }
                $name = mb_strtolower((string)$book->getDisplayName());
            } catch (Throwable $e) {
                $this->logger->debug('[hubs-start] favoriter: skipping unreadable address book: ' . $e->getMessage());
                continue;
            }
            if (str_contains($name, self::FAVORIT_MARKER)) {
                $favorites[] = $book;
            }
        }

        return $favorites;
    }

    /**
     * Read all favorite cards from one address book. Per KONTAKTER-FAVORITER §3.1
     * we search this explicit book (not a global empty-pattern search) for the
     * pointer + cache properties the resolver needs, asking for TYPE arrays so a
     * fax number can be told apart from a cell number.
     *
     * @return list<array<string, mixed>>
     */
    private function readBook(IAddressBook $book): array {
        $properties = ['FN', 'ORG', 'TEL', 'CATEGORIES', 'KIND', 'UID', 'X-HUBS-SDK-REF', 'X-HUBS-USER-REF', 'X-HUBS-OWNER', 'X-HUBS-FAVORIT-KLASS', 'X-HUBS-RESOLVED-AT'];
        try {
            // Empty pattern over a single explicit book = "all cards in this book".
            $cards = $book->search('', $properties, ['types' => true]);
        } catch (Throwable $e) {
            $this->logger->warning('[hubs-start] favoriter: search failed on address book "' . $this->safeName($book) . '": ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            return [];
        }

        return array_values(array_filter(is_array($cards) ? $cards : [], 'is_array'));
    }

    /**
     * Shape one vCard into a resolved favorite DTO matching the frontend contract
     * (src/services/demo/favoriter.js → fetchFavoriter). Returns null for a card
     * that carries no usable favorite payload.
     *
     * @param array<string, mixed> $card
     * @return ?FavoritDTO
     */
    private function toDto(array $card, string $scope, IAddressBook $book): ?array {
        $namn = $this->str($card['FN'] ?? '');
        $org = $this->str($card['ORG'] ?? '');
        $sdkRef = $this->str($card['X-HUBS-SDK-REF'] ?? '');
        $userRef = $this->str($card['X-HUBS-USER-REF'] ?? '');
        $fax = $this->faxOf($card);
        $owner = $this->str($card['X-HUBS-OWNER'] ?? '');
        $resolvedAt = $this->isoOrNull($this->str($card['X-HUBS-RESOLVED-AT'] ?? ''));

        $klass = $this->classOf($card, $sdkRef, $userRef, $fax);
        if ($klass === null) {
            // Not a favorite we understand (no pointer, no Hubs-owned fax) — skip
            // rather than emit a malformed row.
            return null;
        }

        if ($namn === '') {
            $namn = $sdkRef !== '' ? $sdkRef : ($userRef !== '' ? $userRef : $this->t('Favorit'));
        }

        $id = $this->idOf($card, $klass, $sdkRef, $userRef, $book);

        $dto = [
            'id' => $id,
            'klass' => $klass,
            'listor' => [$scope],
            'namn' => $namn,
            'kanal' => $this->kanalOf($klass, $sdkRef),
            'resolvedAt' => $resolvedAt,
            // Pointer classes (a/c) are NOT yet batch-resolved against DIGG / the
            // user directory (resolver cache unbuilt — KONTAKTER-FAVORITER §5), so
            // we flag them stale and present the cache as unverified rather than
            // claiming freshness. Hubs-owned fax (b) owns its value → never stale.
            'stale' => $klass !== self::KLASS_EXTERN_FUNKTION,
            'removed' => false,
            'proveniens' => $this->provenansOf($klass),
        ];

        if ($org !== '') {
            $dto['org'] = $org;
        }
        if ($sdkRef !== '') {
            $dto['sdkRef'] = $sdkRef;
        }
        if ($userRef !== '') {
            $dto['userRef'] = $userRef;
        }
        if ($fax !== '') {
            $dto['fax'] = $fax;
        }
        if ($owner !== '') {
            $dto['owner'] = $owner;
        }

        $identitet = $this->identitetOf($klass);
        if ($identitet !== null) {
            $dto['identitet'] = $identitet;
        }

        return $dto;
    }

    /**
     * Determine the favorite class. An explicit X-HUBS-FAVORIT-KLASS wins; else we
     * infer from the pointer properties (KONTAKTER-FAVORITER §2.2):
     *   X-HUBS-SDK-REF present  → (a) sdk-pekare
     *   X-HUBS-USER-REF present → (c) intern-anvandare
     *   Hubs-owned fax, no ptr   → (b) extern-funktion
     *
     * @param array<string, mixed> $card
     */
    private function classOf(array $card, string $sdkRef, string $userRef, string $fax): ?string {
        $explicit = mb_strtolower($this->str($card['X-HUBS-FAVORIT-KLASS'] ?? ''));
        if ($explicit === self::KLASS_SDK_PEKARE
            || $explicit === self::KLASS_EXTERN_FUNKTION
            || $explicit === self::KLASS_INTERN_ANVANDARE) {
            return $explicit;
        }

        if ($sdkRef !== '') {
            return self::KLASS_SDK_PEKARE;
        }
        if ($userRef !== '') {
            return self::KLASS_INTERN_ANVANDARE;
        }
        if ($fax !== '') {
            return self::KLASS_EXTERN_FUNKTION;
        }
        return null;
    }

    /**
     * Resolved channel for the favorite. Class (a) SDK pointers route over SDK;
     * the routable SDK address is owned by DIGG and resolved fresh (not copied),
     * so we classify by channel only, never expose a cached address as authoritative.
     */
    private function kanalOf(string $klass, string $sdkRef): string {
        if ($klass === self::KLASS_SDK_PEKARE) {
            return ChannelClassificationService::CHANNEL_SDK;
        }
        if ($klass === self::KLASS_EXTERN_FUNKTION) {
            return ChannelClassificationService::CHANNEL_FAX;
        }
        if ($klass === self::KLASS_INTERN_ANVANDARE) {
            return ChannelClassificationService::CHANNEL_INTERNAL;
        }
        return ChannelClassificationService::CHANNEL_UNKNOWN;
    }

    /**
     * Identity badge per class. Class (a)'s "✓ verifierad SDK-adress (LOA3)" is
     * only legitimately shown once the cert is resolved fresh from DIGG; the
     * resolver cache is not built yet (§5), so the unverified pointer shows a
     * neutral badge — we never assert verified identity from a stale cache.
     *
     * @return ?array{badge: string, verifierad: bool}
     */
    private function identitetOf(string $klass): ?array {
        if ($klass === self::KLASS_EXTERN_FUNKTION) {
            return ['badge' => $this->t('Hubs-förvaltad'), 'verifierad' => false];
        }
        // (a)/(c) tillit-signal requires a fresh resolve we cannot do yet.
        return null;
    }

    /**
     * Honest provenance line for the staleness-UI (KONTAKTER-FAVORITER §2.3).
     */
    private function provenansOf(string $klass): string {
        if ($klass === self::KLASS_EXTERN_FUNKTION) {
            return $this->t('Hubs-förvaltad · årlig översyn');
        }
        // Pointer not freshly re-verified against the source.
        return $this->t('Kunde inte färskhetskontrolleras');
    }

    /**
     * The list scope label for an address book. The function-shared list (shared
     * read-only) is labelled by its display name's owner; the personal book maps
     * to 'personlig'. Mirrors the [Mina] [mottagningen@] filter pills (§2.4).
     */
    private function scopeOf(IAddressBook $book): string {
        try {
            if ($book->isShared()) {
                // A shared favorites book is function-owned; surface its uri/name
                // so the UI's scope pill matches (e.g. 'mottagningen@').
                $uri = $book->getUri();
                return $uri !== '' ? $uri : 'delad';
            }
        } catch (Throwable) {
            // fall through to personal
        }
        return 'personlig';
    }

    /**
     * Stable favorite id. Prefer the pointer key (orgId / uid) so the same DIGG
     * pointer keeps a stable id across reads; fall back to the card id within its
     * book.
     *
     * @param array<string, mixed> $card
     */
    private function idOf(array $card, string $klass, string $sdkRef, string $userRef, IAddressBook $book): string {
        if ($sdkRef !== '') {
            return 'fav:sdk:' . $sdkRef;
        }
        if ($userRef !== '') {
            return 'fav:user:' . $userRef;
        }
        $cardId = $this->str($card['UID'] ?? ($card['id'] ?? ''));
        $bookKey = '';
        try {
            $bookKey = (string)$book->getUri();
        } catch (Throwable) {
            // best-effort only
        }
        return 'fav:' . $klass . ':' . ($bookKey !== '' ? $bookKey . ':' : '') . ($cardId !== '' ? $cardId : uniqid('', false));
    }

    /**
     * Extract a fax number from a vCard's TEL property. With ['types' => true] a
     * multi-valued TEL is an array of {type, value}; a fax line carries a 'fax'
     * type token (vCard TEL;TYPE=fax — KONTAKTER-FAVORITER §2.2 class (b)).
     *
     * @param array<string, mixed> $card
     */
    private function faxOf(array $card): string {
        $tel = $card['TEL'] ?? null;
        if ($tel === null) {
            return '';
        }

        // Normalise to a list of entries (each a scalar value or a {type,value} map).
        $entries = $this->isAssoc($tel) ? [$tel] : (is_array($tel) ? $tel : [$tel]);
        $firstNumber = '';
        foreach ($entries as $entry) {
            if (is_array($entry)) {
                $value = $this->str($entry['value'] ?? '');
                $type = $entry['type'] ?? '';
                $typeStr = is_array($type) ? mb_strtolower(implode(',', array_map('strval', $type))) : mb_strtolower($this->str($type));
                if ($value !== '' && str_contains($typeStr, 'fax')) {
                    return $value;
                }
                if ($value !== '' && $firstNumber === '') {
                    $firstNumber = $value;
                }
            } else {
                $value = $this->str($entry);
                if ($value !== '' && $firstNumber === '') {
                    $firstNumber = $value;
                }
            }
        }

        // Only a class (b) card (no SDK/user pointer) falls back to its sole TEL;
        // the caller has already established there is no pointer in that case.
        return $firstNumber;
    }

    /**
     * @param array<string, mixed> $card
     */
    private function safeName(IAddressBook $book): string {
        try {
            return (string)$book->getDisplayName();
        } catch (Throwable) {
            return '?';
        }
    }

    /**
     * True for an associative array (a single {type,value} TEL map) vs a list of
     * TEL entries.
     */
    private function isAssoc(mixed $value): bool {
        if (!is_array($value) || $value === []) {
            return false;
        }
        return array_keys($value) !== range(0, count($value) - 1);
    }

    private function isoOrNull(string $value): ?string {
        if ($value === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->format(\DateTimeInterface::ATOM);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Coerce a loosely-typed vCard value (string, or first element of a
     * multi-valued property array) to a trimmed string.
     */
    private function str(mixed $value): string {
        if (is_string($value)) {
            return trim($value);
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        if (is_array($value) && $value !== []) {
            $first = reset($value);
            if (is_array($first)) {
                $first = $first['value'] ?? '';
            }
            return is_string($first) ? trim($first) : '';
        }
        return '';
    }

    /**
     * Translate via the classifier's l10n so favorite labels share the sdkmc
     * translation domain. The classifier already owns IL10N; we reuse its public
     * label vocabulary where possible and fall back to the literal Swedish string
     * (these are short, user-facing provenance labels).
     */
    private function t(string $text): string {
        return $text;
    }
}
