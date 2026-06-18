<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * NEW FILE for the sdkmc app. Target: lib/Controller/OCS/SummaryController.php
 */

namespace OCA\SdkMc\Controller\OCS;

use OCA\SdkMc\Db\MessageReceipt;
use OCA\SdkMc\Db\MessageReceiptMapper;
use OCA\SdkMc\Db\MessageThreadMapper;
use OCA\SdkMc\Service\ChannelClassificationService;
use OCA\SdkMc\Service\SummaryService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Hubs Start — aggregated summary + delivery receipts OCS endpoints.
 *
 * This is THE single server-side aggregation surface for the Hubs Start
 * dashboard (gov-portal's client-side fan-out is explicitly avoided). The
 * frontend's `api.fetchSummary()` / `api.fetchReceipts()` (see
 * hubs_start/src/services/api.js) talk only to these two actions.
 *
 * Routes (appended to sdkmc appinfo/routes.php under 'ocs'):
 *   ['name' => 'OCS\\Summary#summary',  'url' => '/api/v1/summary',  'verb' => 'GET'],
 *   ['name' => 'OCS\\Summary#receipts', 'url' => '/api/v1/receipts', 'verb' => 'GET'],
 */
class SummaryController extends OCSController {

    /**
     * Raw MW receipt status tokens (as persisted verbatim by
     * MessageReceiptController::save) mapped to the 4-step + problem pill the
     * KvittensWidget renders: skickat → levererat → läst → besvarat (+ problem).
     *
     * The MW vocabulary is not enumerated anywhere in the sdkmc codebase (the
     * receipt `status`/`statusCode` strings are stored exactly as the message
     * broker delivers them). The map below is deliberately broad and
     * case-insensitive; unknown-but-non-error tokens degrade to 'skickat'.
     *
     * @var array<string, string>
     */
    private const RECEIPT_STATE_MAP = [
        // Sent / accepted by the broker, no delivery confirmation yet.
        'sent' => 'skickat',
        'created' => 'skickat',
        'queued' => 'skickat',
        'accepted' => 'skickat',
        'pending' => 'skickat',
        'skickat' => 'skickat',
        // Delivered to the recipient endpoint / mailbox.
        'delivered' => 'levererat',
        'received' => 'levererat',
        'levererat' => 'levererat',
        // Opened / read by the recipient.
        'read' => 'last',
        'opened' => 'last',
        'displayed' => 'last',
        'last' => 'last',
        'läst' => 'last',
        // Recipient replied.
        'answered' => 'besvarat',
        'replied' => 'besvarat',
        'besvarat' => 'besvarat',
        // Hard failures.
        'failed' => 'problem',
        'rejected' => 'problem',
        'error' => 'problem',
        'bounced' => 'problem',
        'undelivered' => 'problem',
        'expired' => 'problem',
        'problem' => 'problem',
    ];

    public function __construct(
        string $appName,
        IRequest $request,
        private SummaryService $summaryService,
        private MessageReceiptMapper $receiptMapper,
        private MessageThreadMapper $threadMapper,
        private ChannelClassificationService $classifier,
        private IDBConnection $db,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Aggregated triage summary for the signed-in user (the ONE aggregation
     * endpoint). Incremental via `sinceIds`.
     *
     * Returns the Summary shape declared in api.js:
     *   { loa, counts{kravAtgard,otilldelat,nytt,bevakas,klartIdag,problem},
     *     items: QueueItem[], mailboxes:[{id,name,unread,unassigned}],
     *     watching:[…], channelCoverage:[…], maxSinceId }
     *
     * @param ?string $sinceIds Highest item id seen on a previous poll.
     * @return DataResponse<Http::STATUS_OK|Http::STATUS_UNAUTHORIZED, array<string, mixed>, array{}>
     */
    #[NoAdminRequired]
    public function summary(?string $sinceIds = null): DataResponse {
        $userId = $this->userSession->getUser()?->getUID();
        if ($userId === null) {
            return new DataResponse([], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $summary = $this->summaryService->getSummary($userId, $sinceIds);
        } catch (\Throwable $e) {
            $this->logger->error('[hubs-start] summary aggregation failed: ' . $e->getMessage(), [
                'exception' => $e,
                'userId' => $userId,
            ]);
            // Never break the dashboard first paint: return an empty-but-valid
            // Summary so the frontend renders its empty states instead of erroring.
            $summary = $this->emptySummary();
        }

        return new DataResponse($summary);
    }

    /**
     * Delivery receipts for outgoing messages, replacing the legacy 10-minute
     * PENDING→REJECTED frontend heuristic with the REAL message-broker (MW)
     * state stored on each MessageReceipt.
     *
     * Returns a list of:
     *   { messageId, recipient, channel, state, updatedAt, deepLink }
     * where state ∈ skickat|levererat|last|besvarat|problem.
     *
     * @param ?string $status Filter: 'problem' | 'pending' | 'all' (default all).
     *                        'pending' = anything not yet 'besvarat'/'problem'.
     * @param ?int $limit Max rows (default 20, capped at 100).
     * @return DataResponse<Http::STATUS_OK|Http::STATUS_UNAUTHORIZED, list<array<string, mixed>>, array{}>
     */
    #[NoAdminRequired]
    public function receipts(?string $status = null, ?int $limit = 20): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse([], Http::STATUS_UNAUTHORIZED);
        }

        $limit = max(1, min($limit ?? 20, 100));

        // MessageReceiptMapper exposes only single-row getters; the list query
        // for the dashboard widget is done here over the same table/entity
        // (sdkmc_message_receipt) the mapper owns. Ordered by id DESC because the
        // table carries no updated_at column (see updatedAt note below).
        $receipts = $this->listRecentReceipts($limit);

        $out = [];
        foreach ($receipts as $receipt) {
            $state = $this->mapReceiptState($receipt);

            if ($status === 'problem' && $state !== 'problem') {
                continue;
            }
            if ($status === 'pending' && in_array($state, ['besvarat', 'problem'], true)) {
                continue;
            }

            $out[] = [
                'messageId' => $receipt->getMessageId(),
                'recipient' => $this->recipientOf($receipt),
                'channel' => $this->channelOf($receipt),
                'state' => $state,
                'updatedAt' => $this->updatedAtOf($receipt),
                'deepLink' => $this->deepLinkOf($receipt),
            ];
        }

        // Surface problems first (the widget sorts client-side too, but ordering
        // here keeps the capped list useful when there are many receipts).
        usort($out, static function (array $a, array $b): int {
            $ap = $a['state'] === 'problem' ? 0 : 1;
            $bp = $b['state'] === 'problem' ? 0 : 1;
            if ($ap !== $bp) {
                return $ap <=> $bp;
            }
            return strcmp((string)($b['updatedAt'] ?? ''), (string)($a['updatedAt'] ?? ''));
        });

        return new DataResponse($out);
    }

    /**
     * List the most recent receipts. Kept private and scoped to this controller
     * because MessageReceiptMapper deliberately exposes only keyed getters; the
     * Hubs Start widget needs a bounded "latest N" listing over the same table.
     *
     * @return list<MessageReceipt>
     */
    private function listRecentReceipts(int $limit): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from('sdkmc_message_receipt')
            ->orderBy('id', 'DESC')
            ->setMaxResults($limit);

        $rows = [];
        $result = $qb->executeQuery();
        while ($row = $result->fetch()) {
            $rows[] = MessageReceipt::fromRow($row);
        }
        $result->closeCursor();

        return $rows;
    }

    /**
     * Map a stored MW receipt to the 4-step (+problem) pill state.
     *
     * TODO(hubs-start): the canonical MW receipt status vocabulary and, in
     * particular, the exact semantics of a "PENDING" receipt (is it merely
     * "not yet delivered" → skickat, or a soft failure that the old frontend
     * promoted to REJECTED after 10 minutes?) must be confirmed with the
     * message-broker team before this surface is exposed to caseworkers. Until
     * then PENDING is treated as 'skickat' (optimistic) rather than 'problem',
     * matching the spec's intent to drop the time-based heuristic. statusReason
     * carrying an explicit failure marker still wins (see below).
     */
    private function mapReceiptState(MessageReceipt $receipt): string {
        // An explicit failure reason always wins, regardless of the status token.
        $reason = strtolower(trim((string)$receipt->getStatusReason()));
        if ($reason !== '' && (
            str_contains($reason, 'fail')
            || str_contains($reason, 'reject')
            || str_contains($reason, 'error')
            || str_contains($reason, 'bounce')
        )) {
            return 'problem';
        }

        foreach ([$receipt->getStatus(), (string)$receipt->getStatusCode()] as $token) {
            $key = strtolower(trim($token));
            if ($key !== '' && isset(self::RECEIPT_STATE_MAP[$key])) {
                return self::RECEIPT_STATE_MAP[$key];
            }
        }

        // Unknown but present status → treat as accepted-by-broker (skickat),
        // never silently a problem.
        return 'skickat';
    }

    /**
     * Resolve the recipient label for a receipt. The receipt row itself has no
     * recipient column, so we read it from the persisted receipt_data payload
     * when the broker included one.
     *
     * TODO(hubs-start): if MW receipts never carry a recipient, wire this to the
     * outgoing mail message (sdkmc_message_thread → mail_messages → recipients)
     * once the SummaryService join is finalised. Shape stays correct either way.
     */
    private function recipientOf(MessageReceipt $receipt): ?string {
        $data = $receipt->getReceiptData();
        if (is_array($data)) {
            foreach (['recipient', 'to', 'recipientAddress', 'toAddress'] as $k) {
                if (isset($data[$k]) && is_string($data[$k]) && $data[$k] !== '') {
                    return $data[$k];
                }
            }
        }
        return null;
    }

    /**
     * Server-resolved channel for the receipt, via the single authoritative
     * ChannelClassificationService (never re-derive suffixes elsewhere).
     *
     * @return array{channel: string, channelLabel: string, messageType: string}
     */
    private function channelOf(MessageReceipt $receipt): array {
        $recipient = $this->recipientOf($receipt);
        if ($recipient !== null) {
            return $this->classifier->classifyRecipientValue($recipient);
        }
        return $this->classifier->classifyAddress('');
    }

    /**
     * ISO-8601 last-update timestamp for the receipt.
     *
     * TODO(hubs-start): sdkmc_message_receipt has no updated_at column, so we
     * read a timestamp from receipt_data when present and otherwise return null.
     * A migration adding receipt updated_at is the clean fix and is tracked in
     * the backend-additions handover; the contract field is always present.
     */
    private function updatedAtOf(MessageReceipt $receipt): ?string {
        $data = $receipt->getReceiptData();
        if (is_array($data)) {
            foreach (['updatedAt', 'timestamp', 'receivedAt', 'time', 'createdAt'] as $k) {
                if (!isset($data[$k])) {
                    continue;
                }
                $value = $data[$k];
                if (is_int($value)) {
                    return (new \DateTimeImmutable('@' . $value))->format(\DateTimeInterface::ATOM);
                }
                if (is_string($value) && $value !== '') {
                    try {
                        return (new \DateTimeImmutable($value))->format(\DateTimeInterface::ATOM);
                    } catch (\Exception) {
                        // not a parseable date — fall through
                    }
                }
            }
        }
        return null;
    }

    /**
     * Build the QueueItem-style deepLink descriptor for a receipt so the widget
     * row click lands on the exact outgoing thread. Resolves the mail messageId
     * through MessageThreadMapper; falls back to opening the mailbox app.
     *
     * @return array{app: string, params: array<string, mixed>}
     */
    private function deepLinkOf(MessageReceipt $receipt): array {
        try {
            $thread = $this->threadMapper->getByMessage($receipt->getMessageId());
            $mid = $thread->getMessageId();
            if ($mid !== '') {
                // The thread deep link needs the recipient's itsl mailbox id to
                // pick the right account; SummaryService owns that join. Here we
                // pass the mail messageId so deepLinks.threadLink can still
                // resolve via sdkmc's mailbox-link redirect.
                // TODO(hubs-start): include itslMailboxId once SummaryService
                // exposes the receipt→mailbox mapping.
                return [
                    'app' => 'thread',
                    'params' => [
                        'mid' => $mid,
                    ],
                ];
            }
        } catch (DoesNotExistException | MultipleObjectsReturnedException) {
            // no thread row — fall through to a safe mailbox landing
        } catch (\Throwable $e) {
            $this->logger->debug('[hubs-start] receipt deepLink resolve failed: ' . $e->getMessage());
        }

        return [
            'app' => 'mailbox',
            'params' => [
                'mailboxId' => 'unassigned',
            ],
        ];
    }

    /**
     * A valid, empty Summary payload (used as a safe fallback so the dashboard
     * always renders).
     *
     * @return array<string, mixed>
     */
    private function emptySummary(): array {
        return [
            'loa' => 'LOA3',
            'counts' => [
                'kravAtgard' => 0,
                'otilldelat' => 0,
                'nytt' => 0,
                'bevakas' => 0,
                'klartIdag' => 0,
                'problem' => 0,
            ],
            'items' => [],
            'mailboxes' => [],
            'watching' => [],
            'channelCoverage' => [],
            'maxSinceId' => null,
        ];
    }
}
