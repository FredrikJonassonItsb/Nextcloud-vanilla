<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * NEW FILE for the sdkmc app. Target: lib/Controller/OCS/RecipientController.php
 */

namespace OCA\SdkMc\Controller\OCS;

use OCA\SdkMc\Service\ChannelClassificationService;
use OCA\SdkMc\Service\ItslAccountService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * "Smart mottagare" — recipient search + server-side channel classification.
 *
 * This is the ONE place the SPA goes to resolve who a message can be sent to and
 * over which channel. The channel suffix logic itself is never duplicated here:
 * every candidate is classified through {@see ChannelClassificationService} so the
 * client receives an already-resolved channel (see CONTRACTS.md, hard rule 5).
 *
 * Data sources (mirrors what the existing controllers already expose):
 *   - SDK address book   → the cached digg JSON:API address book in app config
 *                          (the same `addressBookAddresses` / `addressBookOrganizations`
 *                          values served by AddressBookController::show()).
 *   - Internal mailboxes → personlig + gruppbox mailboxes from ItslAccountService
 *                          (the same data source as MailBoxController::internalMailboxesAB()).
 *   - Free value         → the raw query, classified via citizen heuristics, so the
 *                          user can always send to a manually-entered address.
 *
 * @psalm-type Recipient = array{
 *     id: string,
 *     displayName: string,
 *     address: string,
 *     classification: array{channel: string, channelLabel: string, messageType: string},
 *     ssn?: string,
 *     sms?: string
 * }
 */
class RecipientController extends OCSController {

    /** Hard cap so a broad query cannot return the whole address book. */
    private const MAX_RESULTS = 50;

    public function __construct(
        string $appName,
        IRequest $request,
        private IAppConfig $appConfig,
        private ItslAccountService $accountService,
        private ChannelClassificationService $classifier,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Search recipients across the SDK address book, internal mailboxes and a
     * free-value candidate, each returned ALREADY classified by the server.
     *
     * @param string $query free text (name, org, ssn, email, phone, address)
     * @return DataResponse<int, list<Recipient>, array{}>
     *
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function search(string $query = ''): DataResponse {
        $needle = mb_strtolower(trim($query));

        $results = [];
        $this->collectAddressBook($needle, $results);
        $this->collectInternalMailboxes($needle, $results);
        $this->appendFreeValue($query, $needle, $results);

        // De-duplicate on address (an internal mailbox could also appear free-typed)
        // while preserving the source ordering (address book first, then internal,
        // then the free candidate).
        $seen = [];
        $deduped = [];
        foreach ($results as $recipient) {
            $key = mb_strtolower($recipient['address']);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $recipient;
            if (count($deduped) >= self::MAX_RESULTS) {
                break;
            }
        }

        return new DataResponse($deduped);
    }

    /**
     * Classify an explicit, manually-entered recipient value so the composer can
     * be opened with the correct channel preselected.
     *
     * @param string $value
     * @return DataResponse<int, array{channel: string, channelLabel: string, messageType: string}, array{}>
     *
     * @NoAdminRequired
     */
    #[NoAdminRequired]
    public function classify(string $value = ''): DataResponse {
        return new DataResponse($this->classifier->classifyRecipientValue($value));
    }

    /**
     * Read the cached SDK address book (digg JSON:API) and emit one Recipient per
     * function address whose org name, function name or routable address matches
     * the query.
     *
     * Shape of the cached value (see UpdateAddressBookService): each app value is
     * `{ meta, links, data: [ { id, attributes, relationships } ] }`.
     *   - organizations: attributes.name + attributes.participantIdentifier (org addr)
     *   - addresses:     attributes.name + attributes.identifier (routable addr,
     *                    typically *@sdk) + relationships.parent.data.id → org id
     *
     * @param list<Recipient> $results
     * @param-out list<Recipient> $results
     */
    private function collectAddressBook(string $needle, array &$results): void {
        try {
            $orgs = $this->readAddressBookData('addressBookOrganizations');
            $addresses = $this->readAddressBookData('addressBookAddresses');
        } catch (Throwable $e) {
            $this->logger->warning('[hubs-start] Failed to read SDK address book for recipient search', ['exception' => $e]);
            return;
        }

        // Index organizations by id for parent lookup + name resolution.
        $orgById = [];
        foreach ($orgs as $org) {
            $id = $this->str($org['id'] ?? null);
            if ($id === '') {
                continue;
            }
            $attr = is_array($org['attributes'] ?? null) ? $org['attributes'] : [];
            $orgById[$id] = [
                'name' => $this->str($attr['name'] ?? ''),
                'address' => $this->str($attr['participantIdentifier'] ?? ''),
            ];
        }

        foreach ($addresses as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $attr = is_array($entry['attributes'] ?? null) ? $entry['attributes'] : [];
            $address = $this->str($attr['identifier'] ?? '');
            if ($address === '') {
                continue;
            }

            $funcName = $this->str($attr['name'] ?? '');
            $parentId = $this->str($entry['relationships']['parent']['data']['id'] ?? null);
            $org = $orgById[$parentId] ?? ['name' => '', 'address' => ''];

            // "Funktionsnamn, Organisation" — fall back gracefully if one is missing.
            $displayName = trim(implode(', ', array_filter([$funcName, $org['name']])));
            if ($displayName === '') {
                $displayName = $address;
            }

            $haystack = mb_strtolower($funcName . ' ' . $org['name'] . ' ' . $org['address'] . ' ' . $address);
            if ($needle !== '' && !str_contains($haystack, $needle)) {
                continue;
            }

            $results[] = [
                'id' => $this->str($entry['id'] ?? '') !== '' ? 'ab:' . $this->str($entry['id']) : 'ab:' . $address,
                'displayName' => $displayName,
                'address' => $address,
                'classification' => $this->classifier->classifyAddress($address),
            ];
        }
    }

    /**
     * Emit one Recipient per internal mailbox (personlig + gruppbox) whose name,
     * description or e-mail matches the query. Same data source as
     * MailBoxController::internalMailboxesAB() / getInternalMailboxes(false).
     *
     * @param list<Recipient> $results
     * @param-out list<Recipient> $results
     */
    private function collectInternalMailboxes(string $needle, array &$results): void {
        try {
            $mailboxes = array_merge(
                $this->accountService->getMailBoxes('gruppbox'),
                $this->accountService->getMailBoxes('personlig'),
            );
        } catch (Throwable $e) {
            $this->logger->warning('[hubs-start] Failed to read internal mailboxes for recipient search', ['exception' => $e]);
            return;
        }

        foreach ($mailboxes as $entry) {
            if (!is_array($entry) || !isset($entry['email'])) {
                continue;
            }
            $address = $this->str($entry['email']);
            if ($address === '') {
                continue;
            }

            $name = $this->str($entry['name'] ?? '');
            $description = $this->str($entry['description'] ?? '');
            $displayName = $name !== '' ? $name : $address;

            $haystack = mb_strtolower($name . ' ' . $description . ' ' . $address);
            if ($needle !== '' && !str_contains($haystack, $needle)) {
                continue;
            }

            $results[] = [
                'id' => 'mailbox:' . $address,
                'displayName' => $displayName,
                'address' => $address,
                'classification' => $this->classifier->classifyAddress($address),
            ];
        }
    }

    /**
     * Always offer the raw query as a free-value candidate (citizen email / ssn /
     * fax / mobile) so the user can send to anyone, classified by the same
     * heuristics the composer would use. Skipped only when the query is empty or
     * is already a Hubs pseudo-address that matched an exact internal mailbox /
     * address-book entry (the de-dup pass in search() drops the duplicate).
     *
     * For a personnummer-like value we surface it as `ssn`; for a pure digit
     * string (mobile/fax) we surface it as `sms` so the composer can prefill.
     *
     * @param list<Recipient> $results
     * @param-out list<Recipient> $results
     */
    private function appendFreeValue(string $rawQuery, string $needle, array &$results): void {
        $value = trim($rawQuery);
        if ($value === '') {
            return;
        }

        $classification = $this->classifier->classifyRecipientValue($value);

        $recipient = [
            'id' => 'free:' . $value,
            'displayName' => $value,
            'address' => $value,
            'classification' => $classification,
        ];

        $digits = preg_replace('/\D/', '', $value) ?? '';
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false
            && $digits !== '' && strlen($digits) >= 10 && strlen($digits) <= 13) {
            // Personnummer-like → identify the citizen by ssn.
            $recipient['ssn'] = $digits;
        } elseif (filter_var($value, FILTER_VALIDATE_EMAIL) === false
            && $digits !== '' && $value === $digits) {
            // Pure digit string with no separators → treat as an SMS/fax number.
            $recipient['sms'] = $digits;
        }

        $results[] = $recipient;
    }

    /**
     * Read and unwrap the `data` array of a cached digg address-book app value.
     *
     * @return list<array<string, mixed>>
     */
    private function readAddressBookData(string $appValueKey): array {
        $stored = $this->appConfig->getAppValueArray($appValueKey, [], true);
        $data = $stored['data'] ?? null;
        if (!is_array($data)) {
            return [];
        }
        return array_values(array_filter($data, 'is_array'));
    }

    /**
     * Coerce a loosely-typed config value to a trimmed string.
     */
    private function str(mixed $value): string {
        if (is_string($value)) {
            return trim($value);
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        return '';
    }
}
