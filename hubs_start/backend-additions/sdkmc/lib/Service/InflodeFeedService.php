<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * HUBS-START BACKEND-ADDITION · UPSTREAM-KANDIDAT · Target: lib/Service/InflodeFeedService.php
 *
 * NEW FILE for the sdkmc app. The raw incoming-message feed behind the Hubs
 * Start "meddelande-inflöde" view (the three bands KorgValjare + inflöde-list).
 *
 * SCOPE — read-only, sdkmc domain only:
 *   This service produces the RAW feed rows that the dashboard's
 *   `fetchInflodeSummary()` ({ korgar, inflode }) expects. It reads ONLY sdkmc's
 *   own surfaces (the function mailboxes from ItslMailboxMapper + the SDK<->mail
 *   thread mapping from MessageThreadMapper) joined against the read-only mail_*
 *   tables, exactly like SummaryService does. It NEVER touches a hubs_arende
 *   surface.
 *
 *   Classification (oklart/skräp/fel mottagare), ärende-matchning (koppling,
 *   konfidens, barnRef, dnr) and the provenance/retention chain are OWNED BY
 *   hubs_arende and are explicitly NOT produced here. The rows emitted here carry
 *   only what sdkmc can know on its own: which korg the message landed in, the
 *   channel (via the ONE authoritative classifier), an anonymised sender label,
 *   the inkom-timestamp and a deep link back to the thread. `arendekoppling`,
 *   `koppling`, `klassning`, `provenance` and `frist` are deliberately omitted —
 *   the ärende-motorn enriches the feed with those downstream.
 *
 * GRACEFUL: a missing data source on dev15 (no mail app, no SDK inflow, schema
 * mismatch) degrades to an honest, well-formed empty payload ({ korgar:[],
 * inflode:[] } or partial). It NEVER throws to the controller, NEVER returns a
 * 500, and NEVER fabricates a synthetic message or any citizen PII.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */

namespace OCA\SdkMc\Service;

use OCA\SdkMc\Db\AccountItslMailboxMapper;
use OCA\SdkMc\Db\ItslMailbox;
use OCA\SdkMc\Db\ItslMailboxMapper;
use OCA\SdkMc\Db\MessageThreadMapper;
// >>> HUBS-START-ADD (upstream-kandidat) ─ demo-gate imports ─────────────────
use OCA\SdkMc\Service\DemoData\InflodeDemoData;
use OCP\IAppConfig;
// <<< HUBS-START-ADD ─────────────────────────────────────────────────────────
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class InflodeFeedService {

    /** Hard cap on feed rows returned in a single summary (per korg fan-out). */
    private const MAX_ROWS_PER_KORG = 50;
    private const MAX_ROWS_TOTAL = 200;

    // >>> HUBS-START-ADD (upstream-kandidat) ─ demo-gate config ───────────────
    /**
     * sdkmc app-config that switches the inflöde feed to the SYNTHETIC demo
     * dataset. '1' → InflodeDemoData (dev15 demo); anything else (default '0')
     * → the real, source-backed feed below, UNCHANGED. Demo data is fully
     * fictional and never reaches a registered ärende.
     */
    private const DEMO_GATE_APP = 'sdkmc';
    private const DEMO_GATE_KEY = 'hubs_start_inflode_demo';
    private const DEMO_GATE_DEFAULT = '0';
    // <<< HUBS-START-ADD ─────────────────────────────────────────────────────

    public function __construct(
        private ItslMailboxMapper $mailboxMapper,
        private AccountItslMailboxMapper $accountMailboxMapper,
        private MessageThreadMapper $threadMapper,
        private ChannelClassificationService $classifier,
        private IDBConnection $db,
        private IUserManager $userManager,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
        // >>> HUBS-START-ADD (upstream-kandidat) ─ demo-gate dependency ───────
        // Optional/nullable so existing DI wiring that predates the gate keeps
        // resolving; a null config simply means "demo off" (real feed).
        private ?IAppConfig $appConfig = null,
        // <<< HUBS-START-ADD ─────────────────────────────────────────────────
    ) {
    }

    /**
     * Build the inflöde summary for the signed-in user.
     *
     * Mirrors the demo's `fetchInflodeSummary()` shape exactly:
     *   {
     *     korgar:  list<{ addr:string, label:string, scope:string, otriagerat:int }>,
     *     inflode: list<InflodeRad>
     *   }
     *
     * Where each InflodeRad carries ONLY the sdkmc-knowable fields (see class
     * docblock — no koppling/klassning/provenance, those are the ärende-motorn's):
     *   {
     *     id:string, kind:'inflode',
     *     korg:{ addr:string, label:string, scope:string },
     *     channel:{ channel:string, channelLabel:string, messageType:string },
     *     messageType:string,
     *     avsandare:string,                 // anonymised, never clear-text PII
     *     identitet:{ badge:string, verifierad:bool },
     *     titel:string,                     // anonymised channel/dnr title
     *     inkomDatum:?string,               // ISO-8601
     *     messageId:string,
     *     deepLink:{ app:string, params:array<string,mixed> }
     *   }
     *
     * @param string $userId
     * @return array{korgar: list<array<string,mixed>>, inflode: list<array<string,mixed>>}
     */
    public function getInflodeSummary(string $userId): array {
        try {
            // >>> HUBS-START-ADD (upstream-kandidat) ─ demo-gate ──────────────
            // When the sdkmc app-config 'hubs_start_inflode_demo' is '1', return
            // the SYNTHETIC demo dataset instead of the real (dev15-empty) feed.
            // Gate read + demo build are themselves graceful: any failure falls
            // through to the real feed, never a 500.
            if ($this->isDemoEnabled()) {
                return InflodeDemoData::summary();
            }
            // <<< HUBS-START-ADD ─────────────────────────────────────────────
            return $this->buildSummary($userId);
        } catch (\Throwable $e) {
            // Never break the dashboard: honest empty-but-valid shape on any
            // failure (mail app absent, schema drift, no SDK inflow on dev15).
            $this->logger->error('[hubs-start] inflöde summary failed: ' . $e->getMessage(), [
                'exception' => $e,
                'userId' => $userId,
            ]);
            return $this->emptySummary();
        }
    }

    // >>> HUBS-START-ADD (upstream-kandidat) ─ demo-gate helper ───────────────
    /**
     * Whether the SYNTHETIC inflöde demo dataset is switched on via sdkmc
     * app-config ('hubs_start_inflode_demo' === '1'). Default OFF. A missing
     * IAppConfig (older DI wiring) or any read failure resolves to OFF, so the
     * real feed is the safe fallback and this can never throw.
     */
    private function isDemoEnabled(): bool {
        if ($this->appConfig === null) {
            return false;
        }
        try {
            $value = $this->appConfig->getValueString(
                self::DEMO_GATE_APP,
                self::DEMO_GATE_KEY,
                self::DEMO_GATE_DEFAULT,
            );
            return trim($value) === '1';
        } catch (\Throwable $e) {
            $this->logger->debug('[hubs-start] inflöde: demo-gate read failed, defaulting to real feed: ' . $e->getMessage());
            return false;
        }
    }
    // <<< HUBS-START-ADD ─────────────────────────────────────────────────────

    /**
     * @param string $userId
     * @return array{korgar: list<array<string,mixed>>, inflode: list<array<string,mixed>>}
     */
    private function buildSummary(string $userId): array {
        $mailboxes = $this->getAccessibleMailboxes($userId);

        $korgar = [];
        $inflode = [];

        foreach ($mailboxes as $mailbox) {
            $korg = $this->korgForMailbox($mailbox);
            $channelInfo = $this->channelForMailbox($mailbox);

            $rows = $this->fetchIncomingRows($mailbox->getEmail());

            foreach ($rows as $row) {
                if (count($inflode) >= self::MAX_ROWS_TOTAL) {
                    break 2;
                }
                $inflode[] = $this->toFeedRow($row, $mailbox, $korg, $channelInfo);
            }

            $korgar[] = [
                'addr' => $korg['addr'],
                'label' => $korg['label'],
                'scope' => $korg['scope'],
                // sdkmc can only report a RAW unread/incoming count; "otriagerat"
                // (triaged-or-not) is the ärende-motorn's classification, so we
                // surface the honest raw incoming count under the same key the
                // frontend reads. Empty source → 0.
                'otriagerat' => count($rows),
            ];
        }

        // Newest first (the frontend also sorts, but a stable server order keeps
        // the capped list useful).
        usort($inflode, static function (array $a, array $b): int {
            return strcmp((string)($b['inkomDatum'] ?? ''), (string)($a['inkomDatum'] ?? ''));
        });

        return [
            'korgar' => $korgar,
            'inflode' => $inflode,
        ];
    }

    /**
     * Function mailboxes the given user may act on (direct user assignment +
     * group assignment). Identical access model to SummaryService, resolved
     * through the verified AccountItslMailboxMapper API (no hand-rolled
     * access-control SQL).
     *
     * @return list<ItslMailbox>
     */
    private function getAccessibleMailboxes(string $userId): array {
        $mailboxIds = [];

        try {
            foreach ($this->accountMailboxMapper->findByAccountId($userId) as $assignment) {
                $mailboxIds[$assignment->getItslMailboxId()] = true;
            }

            $user = $this->userManager->get($userId);
            if ($user instanceof IUser) {
                foreach ($this->groupManager->getUserGroups($user) as $group) {
                    foreach ($this->accountMailboxMapper->findByGroupId($group->getGID()) as $assignment) {
                        $mailboxIds[$assignment->getItslMailboxId()] = true;
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug('[hubs-start] inflöde: assignment lookup failed: ' . $e->getMessage());
            return [];
        }

        $mailboxes = [];
        foreach (array_keys($mailboxIds) as $mailboxId) {
            try {
                $mailboxes[] = $this->mailboxMapper->findById((int)$mailboxId);
            } catch (DoesNotExistException | MultipleObjectsReturnedException) {
                // Stale assignment row — skip.
                continue;
            } catch (\Throwable $e) {
                $this->logger->warning('[hubs-start] inflöde: could not load mailbox ' . $mailboxId . ': ' . $e->getMessage());
            }
        }

        return $mailboxes;
    }

    /**
     * Fetch incoming (INBOX) message rows for a function mailbox — the raw feed.
     * Read-only against mail_* (mirrors SummaryService::fetchUnassignedThreads),
     * restricted to the INBOX because the inflöde feed is about incoming traffic,
     * never Sent/Trash. Returns minimal, non-PII-bearing columns only.
     *
     * @return list<array<string,mixed>>
     */
    private function fetchIncomingRows(string $email): array {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('m.id AS db_id', 'm.message_id', 'm.thread_root_id', 'm.subject', 'm.sent_at', 'm.flag_seen', 'a.id AS account_id')
                ->from('mail_messages', 'm')
                ->join('m', 'mail_mailboxes', 'mb', $qb->expr()->eq('m.mailbox_id', 'mb.id'))
                ->join('mb', 'mail_accounts', 'a', $qb->expr()->eq('mb.account_id', 'a.id'))
                ->where($qb->expr()->eq('a.email', $qb->createNamedParameter($email, IQueryBuilder::PARAM_STR)))
                // INBOX only — incoming traffic, not Sent/Trash.
                ->andWhere($qb->expr()->eq($qb->func()->lower('mb.name'), $qb->createNamedParameter('inbox', IQueryBuilder::PARAM_STR)))
                ->orderBy('m.sent_at', 'DESC')
                ->setMaxResults(self::MAX_ROWS_PER_KORG);

            $rows = [];
            $r = $qb->executeQuery();
            while (($row = $r->fetch()) !== false) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
            $r->closeCursor();
            return $rows;
        } catch (\Throwable $e) {
            // mail app absent / schema mismatch / no inflow on dev15 → honestly
            // empty for this korg, never crash, never synthesise.
            $this->logger->debug('[hubs-start] inflöde: incoming fetch failed for ' . $email . ': ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Map a raw incoming mail row to a feed row carrying ONLY sdkmc-knowable
     * fields. No koppling / klassning / provenance — those are enriched by the
     * ärende-motorn downstream.
     *
     * @param array<string,mixed> $row
     * @param array{addr:string,label:string,scope:string} $korg
     * @param array{channel:string,channelLabel:string,messageType:string} $channelInfo
     * @return array<string,mixed>
     */
    private function toFeedRow(array $row, ItslMailbox $mailbox, array $korg, array $channelInfo): array {
        $messageId = (string)($row['message_id'] ?? '');
        $dbId = $row['db_id'] ?? $messageId;
        $inkomDatum = $this->isoOrNull($row['sent_at'] ?? null);

        return [
            'id' => 'inf:' . $dbId,
            'kind' => 'inflode',
            'korg' => [
                'addr' => $korg['addr'],
                'label' => $korg['label'],
                'scope' => $korg['scope'],
            ],
            'channel' => $channelInfo,
            // >>> HUBS-START-ADD (upstream-kandidat) ─ gap27 innehållstyp ──────
            // The `channel` object above keeps the authoritative KANAL/transport
            // type (sdk_message, fax_message, …) from ChannelClassificationService
            // — that is intentionally UNCHANGED. The top-level `messageType` the
            // inflöde-list reads is, by contract, an INNEHÅLLS-typ (orosanmalan,
            // komplettering, …). sdkmc can derive that content type only when the
            // korg localpart is itself a content corridor (e.g. 'orosanmalan@' →
            // 'orosanmalan'); otherwise it stays the channel messageType and the
            // ärende-motorn (hubs_arende) owns the real content classification.
            'messageType' => $this->contentTypeFromKorg($korg) ?? $channelInfo['messageType'],
            // <<< HUBS-START-ADD ─────────────────────────────────────────────
            // Anonymised — only the channel label, never the citizen/sender PII.
            'avsandare' => $channelInfo['channelLabel'],
            // sdkmc cannot assert LOA per-message here; emit a neutral,
            // honest "unknown verification" badge. The ärende-motorn /
            // identity layer fills the real LOA badge downstream.
            'identitet' => [
                'badge' => '',
                'verifierad' => false,
            ],
            'titel' => $this->anonymiseTitle($channelInfo, $row),
            'inkomDatum' => $inkomDatum,
            'messageId' => $messageId,
            'deepLink' => $this->deepLinkForRow($row, $mailbox),
        ];
    }

    // >>> HUBS-START-ADD (upstream-kandidat) ─ gap27 innehållstyp ─────────────
    /**
     * Derive the INNEHÅLLS-typ (content type) of an inflöde row from the korg the
     * message landed in, when — and ONLY when — the korg localpart is itself a
     * content corridor that names the content (e.g. an 'orosanmalan@' function
     * mailbox by definition carries orosanmälningar). The vocabulary is the same
     * one the inflöde-list's messageType label map renders (orosanmalan,
     * komplettering, fraga, remiss). This is a deliberately conservative,
     * suffix-/prefix-free localpart match — anything sdkmc cannot positively name
     * from its own korg returns null, and the caller keeps the channel
     * (transport) messageType so the ärende-motorn can own the real content
     * classification downstream. Never throws.
     *
     * @param array{addr:string,label:string,scope:string} $korg
     */
    private function contentTypeFromKorg(array $korg): ?string {
        // The routable localpart is the durable signal (label is human-editable).
        $addr = strtolower(trim((string)($korg['addr'] ?? '')));
        if ($addr === '') {
            return null;
        }
        // Reduce a function address to its bare localpart: drop a Hubs pseudo
        // suffix ('@sdk', '@gruppbox', …) or a real domain, and the trailing '@'
        // the korg vocabulary uses ('orosanmalan@').
        $localpart = $addr;
        $at = strpos($localpart, '@');
        if ($at !== false) {
            $localpart = substr($localpart, 0, $at);
        }
        $localpart = trim($localpart);
        if ($localpart === '') {
            return null;
        }

        // Only positively-named content corridors map; everything else is the
        // ärende-motorn's to classify (null → keep channel messageType).
        return match ($localpart) {
            'orosanmalan', 'orosanmalningar', 'oros' => 'orosanmalan',
            'komplettering', 'kompletteringar' => 'komplettering',
            'fraga', 'fragor' => 'fraga',
            'remiss', 'remisser' => 'remiss',
            default => null,
        };
    }
    // <<< HUBS-START-ADD ─────────────────────────────────────────────────────

    /**
     * The korg descriptor for a function mailbox: routable address + label +
     * scope. Scope mirrors the demo's korg scopes
     * (personlig | grupp | fax | sdk), derived from the mailbox message type.
     *
     * @return array{addr:string,label:string,scope:string}
     */
    private function korgForMailbox(ItslMailbox $mailbox): array {
        $sdkAddress = $mailbox->getSdkAddress();
        $addr = ($sdkAddress !== null && $sdkAddress !== '') ? $sdkAddress : $mailbox->getEmail();
        $label = $mailbox->getName() !== '' ? $mailbox->getName() : $addr;

        return [
            'addr' => $addr,
            'label' => $label,
            'scope' => $this->scopeForMessageType($mailbox->getMessageType()),
        ];
    }

    /**
     * Map an sdkmc mailbox message_type to the demo's korg scope vocabulary.
     */
    private function scopeForMessageType(string $messageType): string {
        return match ($messageType) {
            'personlig' => 'personlig',
            'gruppbox' => 'grupp',
            'fax' => 'fax',
            'sdk' => 'sdk',
            'sms' => 'sms',
            default => 'grupp',
        };
    }

    /**
     * Server-resolved channel for the mailbox, via the ONE authoritative
     * ChannelClassificationService (never re-derive suffixes here). Prefers the
     * SDK address suffix when present, else the routable email.
     *
     * @return array{channel:string,channelLabel:string,messageType:string}
     */
    private function channelForMailbox(ItslMailbox $mailbox): array {
        $sdkAddress = $mailbox->getSdkAddress();
        if ($sdkAddress !== null && $sdkAddress !== '') {
            $info = $this->classifier->classifyAddress($sdkAddress);
            if ($info['channel'] !== ChannelClassificationService::CHANNEL_UNKNOWN) {
                return $info;
            }
        }
        return $this->classifier->classifyAddress($mailbox->getEmail());
    }

    /**
     * Anonymised, verb-free feed title: channel label plus an optional non-PII
     * diarienummer pulled from the subject. The subject itself is NEVER
     * surfaced (it may contain citizen PII).
     *
     * @param array{channel:string,channelLabel:string,messageType:string} $channelInfo
     * @param array<string,mixed> $row
     */
    private function anonymiseTitle(array $channelInfo, array $row): string {
        $label = $channelInfo['channelLabel'];
        $dnr = $this->extractDnr($row);
        return $dnr !== null ? ($label . ' (' . $dnr . ')') : $label;
    }

    /**
     * Pull a non-PII case number (diarienummer) out of a subject if it matches a
     * dnr-like pattern, else null. Mirrors SummaryService::extractDnr so the two
     * surfaces agree on what counts as a safe-to-surface reference.
     *
     * @param array<string,mixed> $row
     */
    private function extractDnr(array $row): ?string {
        $subject = (string)($row['subject'] ?? '');
        if ($subject === '') {
            return null;
        }
        if (preg_match('/\b([A-ZÅÄÖ]{0,4}[-\s]?\d{2,4}[-\/]\d{1,6})\b/u', $subject, $m) === 1) {
            return trim($m[1]);
        }
        return null;
    }

    /**
     * Build the deep link for a feed row so a row click lands on the exact
     * thread. Resolves the itsl mailbox id from the mailbox; the SPA's
     * deepLinks.resolve turns this into sdkmc's /mailbox-link redirect.
     *
     * @param array<string,mixed> $row
     * @return array{app:string,params:array<string,mixed>}
     */
    private function deepLinkForRow(array $row, ItslMailbox $mailbox): array {
        return [
            'app' => 'thread',
            'params' => [
                'itslMailboxId' => $mailbox->getId(),
                'mid' => $row['db_id'] ?? ($row['message_id'] ?? ''),
            ],
        ];
    }

    /**
     * Resolve the mail db id for a single SDK message via the thread mapping,
     * used by the message-action surface so 'besvara'/'vidarebefordra' can land
     * on the right outgoing/incoming thread.
     *
     * @return array{app:string,params:array<string,mixed>}
     */
    public function deepLinkForMessage(string $messageId): array {
        try {
            $thread = $this->threadMapper->getByMessage($messageId);
            $mid = $thread->getMessageId();
            if ($mid !== '') {
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
            $this->logger->debug('[hubs-start] inflöde: message deepLink resolve failed: ' . $e->getMessage());
        }

        return [
            'app' => 'mailbox',
            'params' => [
                'mailboxId' => 'inbox',
            ],
        ];
    }

    /**
     * @param int|string|null $value unix timestamp (seconds) or ISO string
     */
    private function isoOrNull(int|string|null $value): ?string {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            if (is_int($value) || ctype_digit((string)$value)) {
                return (new \DateTimeImmutable('@' . (int)$value))->format(\DateTimeInterface::ATOM);
            }
            return (new \DateTimeImmutable((string)$value))->format(\DateTimeInterface::ATOM);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * A valid, empty inflöde payload — the honest dev15 fallback. The frontend
     * renders its empty state instead of erroring.
     *
     * @return array{korgar: list<array<string,mixed>>, inflode: list<array<string,mixed>>}
     */
    private function emptySummary(): array {
        return [
            'korgar' => [],
            'inflode' => [],
        ];
    }
}
