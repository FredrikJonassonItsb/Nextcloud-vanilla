<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * NEW FILE for the sdkmc app. Target: lib/Service/SummaryService.php
 */

namespace OCA\SdkMc\Service;

use OCA\SdkMc\Db\AccountItslMailbox;
use OCA\SdkMc\Db\AccountItslMailboxMapper;
use OCA\SdkMc\Db\ItslMailbox;
use OCA\SdkMc\Db\ItslMailboxMapper;
use OCA\SdkMc\Db\ItslTagMapper;
use OCA\SdkMc\Db\MessageReceiptMapper;
use OCA\SdkMc\Db\MessageThreadMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Hubs Start — the ONE server-side aggregation behind `fetchSummary` (api.js).
 *
 * Replaces gov-portal's client-side fan-out: every widget on the Start view is
 * fed from a single, cached, incremental Summary payload assembled here. The
 * shape returned by {@see self::getSummary()} is a hard contract — it must match
 * the `Summary` typedef in `src/services/api.js` exactly, or the SPA will not
 * render. See docs/CONTRACTS.md → "SummaryService".
 *
 * Composition:
 *   - ItslMailboxMapper          → the function mailboxes (funktionsbrevlådor)
 *   - AccountItslMailboxMapper   → who has access to which mailbox + absence rows
 *   - ItslTagMapper              → assignment tags (Otilldelat vs Mina meddelanden)
 *   - MessageReceiptMapper       → outgoing receipt state (Kvittenser / problem)
 *   - MessageThreadMapper        → SDK<->email thread mapping for deep links
 *   - OutOfOffice absence rows   → Bevakningar (delegations both directions)
 *   - mail_* tables (read-only)  → real unread / unassigned counters that the mail
 *                                  frontend currently hardcodes to 0
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.ExcessiveClassLength")
 */
class SummaryService {

    /** Short TTL: the SPA polls every 30s (POLL_INTERVAL_MS), so a 20s cache
     *  smooths out repeated tab focus / multiple widgets without going stale. */
    private const CACHE_TTL = 20;
    private const CACHE_PREFIX = 'hubs_start_summary_';

    /** Hubs section ids (mirror sections.js SECTIONS). */
    private const SECTION_KRAVER_ATGARD = 'kraver_atgard';
    private const SECTION_OTILLDELAT = 'otilldelat';
    private const SECTION_NYTT = 'nytt';
    private const SECTION_BEVAKAS = 'bevakas';
    private const SECTION_KLART_IDAG = 'klart_idag';

    /** GOV.UK statuses (mirror sections.js STATUSES). */
    private const STATUS_NY = 'ny';
    private const STATUS_TILLDELAD = 'tilldelad';
    private const STATUS_VANTAR_KVITTENS = 'vantar_kvittens';
    private const STATUS_BESVARAD = 'besvarad';
    private const STATUS_PROBLEM = 'problem';
    private const STATUS_KLAR = 'klar';

    private ICache $cache;

    public function __construct(
        private ItslMailboxMapper $mailboxMapper,
        private AccountItslMailboxMapper $accountMailboxMapper,
        private ItslTagMapper $tagMapper,
        private MessageReceiptMapper $receiptMapper,
        private MessageThreadMapper $threadMapper,
        private ChannelClassificationService $classifier,
        private IDBConnection $db,
        private IUserManager $userManager,
        private IGroupManager $groupManager,
        private IL10N $l,
        private LoggerInterface $logger,
        ICacheFactory $cacheFactory,
    ) {
        $this->cache = $cacheFactory->createDistributed(self::CACHE_PREFIX);
    }

    /**
     * Aggregate everything the Start view needs in one payload.
     *
     * @param string $userId
     * @param ?string $sinceIds Incremental cursor from a previous Summary.maxSinceId.
     *                          When set, callers expect only items newer than the
     *                          cursor (the store upserts by id).
     * @return array{
     *   loa: string,
     *   counts: array{kravAtgard:int,otilldelat:int,nytt:int,bevakas:int,klartIdag:int,problem:int},
     *   items: list<array<string,mixed>>,
     *   mailboxes: list<array{id:int,name:string,unread:int,unassigned:int}>,
     *   watching: list<array{mailbox:string,owner:string,untilDate:?string,direction:string}>,
     *   channelCoverage: list<string>,
     *   maxSinceId: ?string
     * }
     */
    public function getSummary(string $userId, ?string $sinceIds): array {
        $cacheKey = $this->cacheKey($userId, $sinceIds);
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $summary = $this->buildSummary($userId, $sinceIds);
        } catch (\Throwable $e) {
            $this->logger->error('Hubs Start: failed to build summary for ' . $userId . ': ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            // Always return the full shape so the frontend renders an empty,
            // honest state rather than crashing.
            $summary = $this->emptySummary($userId);
        }

        $this->cache->set($cacheKey, $summary, self::CACHE_TTL);
        return $summary;
    }

    /**
     * Build the receipt rows for the Kvittenser widget. Public so the OCS
     * Summary controller's `receipts` action can reuse it without re-running the
     * full aggregation.
     *
     * @param string $userId
     * @param string $statusFilter 'all' | 'problem' | 'pending'
     * @param int $limit
     * @return list<array{messageId:string,recipient:string,channel:array<string,string>,state:string,updatedAt:?string,deepLink:array<string,mixed>}>
     */
    public function buildReceipts(string $userId, string $statusFilter = 'all', int $limit = 20): array {
        $mailboxes = $this->getAccessibleMailboxes($userId);
        $mailboxByEmail = [];
        foreach ($mailboxes as $mb) {
            $mailboxByEmail[strtolower($mb->getEmail())] = $mb;
        }

        $rows = $this->fetchReceiptRows($userId, $limit);
        $receipts = [];

        foreach ($rows as $row) {
            $state = $this->mapReceiptState((string)($row['status'] ?? ''));
            if ($statusFilter === 'problem' && $state !== self::STATUS_PROBLEM) {
                continue;
            }
            if ($statusFilter === 'pending' && in_array($state, ['besvarat', self::STATUS_PROBLEM], true)) {
                continue;
            }

            $messageId = (string)($row['message_id'] ?? $row['document_reference'] ?? '');
            $channel = $this->resolveChannelForMessage($messageId, $mailboxByEmail);
            $deepLink = $this->threadDeepLinkForMessage($messageId, $mailboxByEmail);

            $receipts[] = [
                'messageId' => $messageId,
                // Anonymised: a document reference or a channel label, never citizen PII.
                'recipient' => $this->anonymiseRecipient($row, $channel),
                'channel' => $channel,
                'state' => $state,
                'updatedAt' => $this->isoOrNull($row['updated_at'] ?? null),
                'deepLink' => $deepLink,
            ];
        }

        // Problem rows sorted first (KvittensWidget renders them with error tone).
        usort($receipts, static function (array $a, array $b): int {
            $ap = $a['state'] === self::STATUS_PROBLEM ? 0 : 1;
            $bp = $b['state'] === self::STATUS_PROBLEM ? 0 : 1;
            if ($ap !== $bp) {
                return $ap <=> $bp;
            }
            return strcmp((string)$b['updatedAt'], (string)$a['updatedAt']);
        });

        return $receipts;
    }

    // -----------------------------------------------------------------------
    // Aggregation
    // -----------------------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    private function buildSummary(string $userId, ?string $sinceIds): array {
        $accessibleMailboxes = $this->getAccessibleMailboxes($userId);

        $mailboxSummaries = [];
        $items = [];
        $channelCoverage = [];
        $counts = [
            'kravAtgard' => 0,
            'otilldelat' => 0,
            'nytt' => 0,
            'bevakas' => 0,
            'klartIdag' => 0,
            'problem' => 0,
        ];

        $maxSinceId = $sinceIds;

        foreach ($accessibleMailboxes as $mailbox) {
            $channelInfo = $this->channelForMailbox($mailbox);
            if ($channelInfo['channel'] !== ChannelClassificationService::CHANNEL_UNKNOWN
                && !in_array($channelInfo['channel'], $channelCoverage, true)) {
                $channelCoverage[] = $channelInfo['channel'];
            }

            // REAL server-side counters for the two virtual mailboxes the mail
            // frontend hardcodes to 0: "Mina meddelanden" (assigned to me) and
            // "Otilldelade" (no assignment tag at all).
            $unread = $this->countUnread($mailbox->getEmail());
            $unassigned = $this->countUnassigned($userId, $mailbox);

            $mailboxSummaries[] = [
                'id' => $mailbox->getId(),
                'name' => $mailbox->getName() !== '' ? $mailbox->getName() : $mailbox->getEmail(),
                'unread' => $unread,
                'unassigned' => $unassigned,
            ];

            // Build queue items for unassigned (otilldelat) threads in this mailbox.
            foreach ($this->buildOtilldeladeItems($userId, $mailbox, $channelInfo, $sinceIds) as $item) {
                $items[] = $item;
                $maxSinceId = $this->advanceCursor($maxSinceId, $item['since'], $item['id']);
            }
        }

        // Receipt-derived items: things that need attention (problem) or are
        // waiting for a citizen kvittens. These belong to "Kräver åtgärd".
        foreach ($this->buildReceiptItems($userId, $accessibleMailboxes, $sinceIds) as $item) {
            $items[] = $item;
            $maxSinceId = $this->advanceCursor($maxSinceId, $item['since'], $item['id']);
        }

        // Tally counts from the assembled items.
        foreach ($items as $item) {
            switch ($item['section']) {
                case self::SECTION_KRAVER_ATGARD:
                    $counts['kravAtgard']++;
                    break;
                case self::SECTION_OTILLDELAT:
                    $counts['otilldelat']++;
                    break;
                case self::SECTION_NYTT:
                    $counts['nytt']++;
                    break;
                case self::SECTION_BEVAKAS:
                    $counts['bevakas']++;
                    break;
                case self::SECTION_KLART_IDAG:
                    $counts['klartIdag']++;
                    break;
            }
            if (($item['status'] ?? '') === self::STATUS_PROBLEM) {
                $counts['problem']++;
            }
        }

        $watching = $this->buildWatching($userId);
        $counts['bevakas'] = max($counts['bevakas'], count($watching));

        // Order newest first (store also sorts by `since` desc).
        usort($items, static fn (array $a, array $b): int => strcmp((string)$b['since'], (string)$a['since']));

        return [
            'loa' => $this->resolveLoa($userId),
            'counts' => $counts,
            'items' => $items,
            'mailboxes' => $mailboxSummaries,
            'watching' => $watching,
            'channelCoverage' => $channelCoverage,
            'maxSinceId' => $maxSinceId,
        ];
    }

    /**
     * Function mailboxes the given user may act on (direct + group + absence).
     *
     * @return list<ItslMailbox>
     */
    private function getAccessibleMailboxes(string $userId): array {
        $mailboxIds = [];

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

        $mailboxes = [];
        foreach (array_keys($mailboxIds) as $mailboxId) {
            try {
                $mailboxes[] = $this->mailboxMapper->findById((int)$mailboxId);
            } catch (DoesNotExistException $e) {
                // Stale assignment row — skip.
                continue;
            } catch (\Throwable $e) {
                $this->logger->warning('Hubs Start: could not load mailbox ' . $mailboxId . ': ' . $e->getMessage());
            }
        }

        return $mailboxes;
    }

    /**
     * Build the "Otilldelat" queue items for one mailbox: threads with no
     * assignment tag set. Each item carries the assignment{} block the SPA needs
     * for the "Ta ärendet" action (api.takeThread).
     *
     * @param array{channel:string,channelLabel:string,messageType:string} $channelInfo
     * @return list<array<string,mixed>>
     */
    private function buildOtilldeladeItems(
        string $userId,
        ItslMailbox $mailbox,
        array $channelInfo,
        ?string $sinceIds,
    ): array {
        $items = [];
        $email = $mailbox->getEmail();

        // The imapLabel of THIS user's assignment tag in this shared mailbox is
        // what "Ta ärendet" applies. getOrCreate is avoided here (read-only path);
        // we resolve an existing one, falling back to the conventional label.
        $assignmentTag = $this->tagMapper->getAssignmentTagByUsername($email, $userId);
        $imapLabel = $assignmentTag !== null ? $assignmentTag->getImapLabel() : '$assignee_' . $userId;

        $rows = $this->fetchUnassignedThreads($email, $sinceIds);

        foreach ($rows as $row) {
            $messageId = (string)$row['message_id'];
            $threadRootId = (string)($row['thread_root_id'] ?? $messageId);
            $since = $this->isoOrNull($row['sent_at'] ?? $row['received_at'] ?? null) ?? $this->nowIso();
            $itemId = 'msg:' . ($row['db_id'] ?? $messageId);

            $items[] = [
                'id' => $itemId,
                'title' => $this->anonymiseTitle($channelInfo, $row, self::SECTION_OTILLDELAT),
                'channel' => $channelInfo,
                'status' => self::STATUS_NY,
                'section' => self::SECTION_OTILLDELAT,
                'mailbox' => $mailbox->getName() !== '' ? $mailbox->getName() : $email,
                'dnr' => $this->extractDnr($row),
                'loa' => null,
                'since' => $since,
                'deepLink' => [
                    'app' => 'thread',
                    'params' => [
                        'itslMailboxId' => $mailbox->getId(),
                        'mid' => $row['db_id'] ?? $messageId,
                    ],
                ],
                'assignment' => [
                    'imapLabel' => $imapLabel,
                    // api.takeThread → PUT /api/thread/tags/{imapLabel} with
                    // { accountId, threadRootIds: [threadRootId] }.
                    'accountId' => $row['account_id'] ?? null,
                    'threadRootId' => $threadRootId,
                ],
                'messageId' => $messageId,
            ];
        }

        return $items;
    }

    /**
     * Receipt-derived items for "Kräver åtgärd": outgoing messages whose real MW
     * receipt state is a problem, or that are still waiting for a kvittens.
     *
     * @param list<ItslMailbox> $accessibleMailboxes
     * @return list<array<string,mixed>>
     */
    private function buildReceiptItems(string $userId, array $accessibleMailboxes, ?string $sinceIds): array {
        $mailboxByEmail = [];
        foreach ($accessibleMailboxes as $mb) {
            $mailboxByEmail[strtolower($mb->getEmail())] = $mb;
        }

        $items = [];
        foreach ($this->fetchReceiptRows($userId, 100) as $row) {
            $state = $this->mapReceiptState((string)($row['status'] ?? ''));
            if (!in_array($state, [self::STATUS_PROBLEM, 'last', 'levererat', 'skickat'], true)) {
                continue;
            }

            // Only surface problem / pending-kvittens as actionable queue items;
            // delivered/answered live in the Kvittenser widget, not the queue.
            $isProblem = $state === self::STATUS_PROBLEM;
            $waitsKvittens = in_array($state, ['skickat', 'levererat'], true);
            if (!$isProblem && !$waitsKvittens) {
                continue;
            }

            $messageId = (string)($row['message_id'] ?? $row['document_reference'] ?? '');
            $channel = $this->resolveChannelForMessage($messageId, $mailboxByEmail);
            $deepLink = $this->threadDeepLinkForMessage($messageId, $mailboxByEmail);
            $since = $this->isoOrNull($row['updated_at'] ?? null) ?? $this->nowIso();

            $items[] = [
                'id' => 'receipt:' . ($row['db_id'] ?? $messageId),
                'title' => $isProblem
                    ? $this->l->t('Åtgärda leveransproblem för %s', [$channel['channelLabel']])
                    : $this->l->t('Bevaka kvittens för %s', [$channel['channelLabel']]),
                'channel' => $channel,
                'status' => $isProblem ? self::STATUS_PROBLEM : self::STATUS_VANTAR_KVITTENS,
                'section' => $isProblem ? self::SECTION_KRAVER_ATGARD : self::SECTION_BEVAKAS,
                'mailbox' => null,
                'dnr' => $this->extractDnr($row),
                'loa' => null,
                'since' => $since,
                'deepLink' => $deepLink,
                'messageId' => $messageId,
            ];
        }

        return $items;
    }

    /**
     * Bevakningar (absence delegations) in both directions, sourced from the
     * sdkmc `sdkmc_account_itsl_mailbox` rows with access_type='absence'.
     *
     * @return list<array{mailbox:string,owner:string,untilDate:?string,direction:string}>
     */
    private function buildWatching(string $userId): array {
        $watching = [];

        // Outgoing: someone is covering MY mailbox while I'm away
        // (rows where I am the source/absent user).
        foreach ($this->accountMailboxMapper->findBySourceUserId($userId) as $row) {
            $watching[] = $this->absenceToWatch($row, 'outgoing', (string)$row->getAccountId());
        }

        // Incoming: I am covering someone else's mailbox
        // (absence rows where I am the replacement/accountId).
        foreach ($this->findAbsenceRowsForReplacement($userId) as $row) {
            $watching[] = $this->absenceToWatch($row, 'incoming', (string)$row->getSourceUserId());
        }

        return $watching;
    }

    /**
     * @param AccountItslMailbox $row
     * @param string $direction 'incoming'|'outgoing'
     * @param string $counterpartUserId
     * @return array{mailbox:string,owner:string,untilDate:?string,direction:string}
     */
    private function absenceToWatch(AccountItslMailbox $row, string $direction, string $counterpartUserId): array {
        $mailboxName = '';
        try {
            $mb = $this->mailboxMapper->findById($row->getItslMailboxId());
            $mailboxName = $mb->getName() !== '' ? $mb->getName() : $mb->getEmail();
        } catch (\Throwable $e) {
            $mailboxName = (string)$row->getItslMailboxId();
        }

        $owner = $this->displayName($counterpartUserId);
        $endTime = $row->getEndTime();

        return [
            'mailbox' => $mailboxName,
            'owner' => $owner,
            'untilDate' => $endTime !== null ? (new \DateTimeImmutable('@' . $endTime))->format(\DateTimeInterface::ATOM) : null,
            'direction' => $direction,
        ];
    }

    /**
     * Find absence rows where the given user is the replacement (accountId) and
     * access_type is 'absence'. The mapper has findByAccountId but not scoped to
     * the absence access type, so query directly.
     *
     * @return list<AccountItslMailbox>
     */
    private function findAbsenceRowsForReplacement(string $userId): array {
        $result = [];
        foreach ($this->accountMailboxMapper->findByAccountId($userId) as $row) {
            if ($row->getAccessType() === 'absence' && $row->getSourceUserId() !== null) {
                $result[] = $row;
            }
        }
        return $result;
    }

    // -----------------------------------------------------------------------
    // Real counters (mail_* read-only)
    // -----------------------------------------------------------------------

    /**
     * Unread count across all mailboxes of the mail account(s) bound to this
     * function-mailbox email. Read-only against mail_* tables (mirrors mail's
     * own unread aggregation), giving a real number where the mail frontend's
     * virtual "Mina meddelanden" mailbox shows 0.
     */
    private function countUnread(string $email): int {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('m.id', 'cnt'))
                ->from('mail_messages', 'm')
                ->join('m', 'mail_mailboxes', 'mb', $qb->expr()->eq('m.mailbox_id', 'mb.id'))
                ->join('mb', 'mail_accounts', 'a', $qb->expr()->eq('mb.account_id', 'a.id'))
                ->where($qb->expr()->eq('a.email', $qb->createNamedParameter($email, IQueryBuilder::PARAM_STR)))
                ->andWhere($qb->expr()->eq('m.flag_seen', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL), IQueryBuilder::PARAM_BOOL));

            $r = $qb->executeQuery();
            $row = $r->fetch();
            $r->closeCursor();
            return (int)($row['cnt'] ?? 0);
        } catch (\Throwable $e) {
            // mail app may be absent / schema mismatch — degrade to 0, never crash.
            // TODO(hubs-start): prefer mail's IMailManager unread API once a
            // stable cross-app contract for shared mailboxes exists.
            $this->logger->debug('Hubs Start: unread count failed for ' . $email . ': ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * REAL "Otilldelade" counter for a function mailbox: messages in the INBOX
     * that carry NO assignment tag. This is the number the mail frontend's
     * virtual "Otilldelade" mailbox currently hardcodes to 0.
     */
    private function countUnassigned(string $userId, ItslMailbox $mailbox): int {
        try {
            $email = $mailbox->getEmail();
            $assignmentTags = $this->tagMapper->getAssignmentTagsForMailbox($email);
            $assignedMessageIds = $this->collectAssignedMessageIds($assignmentTags, $email);

            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->func()->count('m.id', 'cnt'))
                ->from('mail_messages', 'm')
                ->join('m', 'mail_mailboxes', 'mb', $qb->expr()->eq('m.mailbox_id', 'mb.id'))
                ->join('mb', 'mail_accounts', 'a', $qb->expr()->eq('mb.account_id', 'a.id'))
                ->where($qb->expr()->eq('a.email', $qb->createNamedParameter($email, IQueryBuilder::PARAM_STR)))
                // Restrict to the INBOX (otilldelat is about incoming, not Sent/Trash);
                // keeps this count consistent with fetchUnassignedThreads().
                ->andWhere($qb->expr()->eq($qb->func()->lower('mb.name'), $qb->createNamedParameter('inbox', IQueryBuilder::PARAM_STR)));

            if ($assignedMessageIds !== []) {
                $qb->andWhere($qb->expr()->notIn('m.message_id', $qb->createNamedParameter($assignedMessageIds, IQueryBuilder::PARAM_STR_ARRAY)));
            }

            $r = $qb->executeQuery();
            $row = $r->fetch();
            $r->closeCursor();
            return (int)($row['cnt'] ?? 0);
        } catch (\Throwable $e) {
            $this->logger->debug('Hubs Start: unassigned count failed for ' . $mailbox->getEmail() . ': ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Resolve the IMAP message ids that already carry one of the mailbox's
     * assignment tags (i.e. are "tilldelade").
     *
     * @param array<\OCA\SdkMc\Db\ItslTag> $assignmentTags
     * @return list<string>
     */
    private function collectAssignedMessageIds(array $assignmentTags, string $email): array {
        if ($assignmentTags === []) {
            return [];
        }
        $tagIds = array_map(static fn ($t) => (int)$t->getId(), $assignmentTags);

        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('imap_message_id')
            ->from('sdkmc_itsl_message_tag')
            ->where($qb->expr()->in('tag_id', $qb->createNamedParameter($tagIds, IQueryBuilder::PARAM_INT_ARRAY)));

        $ids = [];
        $r = $qb->executeQuery();
        while (($row = $r->fetch()) !== false) {
            if (is_array($row) && isset($row['imap_message_id'])) {
                $ids[] = (string)$row['imap_message_id'];
            }
        }
        $r->closeCursor();
        return array_values(array_unique($ids));
    }

    /**
     * Fetch the unassigned threads (INBOX, no assignment tag) for a function
     * mailbox, with the minimal columns the queue items need.
     *
     * @return list<array<string,mixed>>
     */
    private function fetchUnassignedThreads(string $email, ?string $sinceIds): array {
        try {
            $assignmentTags = $this->tagMapper->getAssignmentTagsForMailbox($email);
            $assignedMessageIds = $this->collectAssignedMessageIds($assignmentTags, $email);

            $qb = $this->db->getQueryBuilder();
            $qb->select('m.id AS db_id', 'm.message_id', 'm.thread_root_id', 'm.subject', 'm.sent_at', 'a.id AS account_id')
                ->from('mail_messages', 'm')
                ->join('m', 'mail_mailboxes', 'mb', $qb->expr()->eq('m.mailbox_id', 'mb.id'))
                ->join('mb', 'mail_accounts', 'a', $qb->expr()->eq('mb.account_id', 'a.id'))
                ->where($qb->expr()->eq('a.email', $qb->createNamedParameter($email, IQueryBuilder::PARAM_STR)))
                // INBOX only — matches countUnassigned() so counts and items agree.
                ->andWhere($qb->expr()->eq($qb->func()->lower('mb.name'), $qb->createNamedParameter('inbox', IQueryBuilder::PARAM_STR)))
                ->orderBy('m.sent_at', 'DESC')
                ->setMaxResults(50);

            if ($assignedMessageIds !== []) {
                $qb->andWhere($qb->expr()->notIn('m.message_id', $qb->createNamedParameter($assignedMessageIds, IQueryBuilder::PARAM_STR_ARRAY)));
            }

            if ($sinceIds !== null && $sinceIds !== '') {
                $sinceTs = $this->cursorToTimestamp($sinceIds);
                if ($sinceTs !== null) {
                    $qb->andWhere($qb->expr()->gt('m.sent_at', $qb->createNamedParameter($sinceTs, IQueryBuilder::PARAM_INT)));
                }
            }

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
            // TODO(hubs-start): when the mail app is not installed this join is
            // unavailable; the queue degrades to receipt-only items. Wire to
            // IMailManager once a shared-mailbox listing contract exists.
            $this->logger->debug('Hubs Start: unassigned thread fetch failed for ' . $email . ': ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Fetch outgoing receipt rows. The sdkmc receipt table is not user-scoped,
     * so we return the most recent rows; the caller maps + anonymises them.
     *
     * @return list<array<string,mixed>>
     */
    private function fetchReceiptRows(string $userId, int $limit): array {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id AS db_id', 'message_id', 'document_reference', 'status', 'status_code', 'status_reason')
                ->from('sdkmc_message_receipt')
                ->orderBy('id', 'DESC')
                ->setMaxResults($limit);

            $rows = [];
            $r = $qb->executeQuery();
            while (($row = $r->fetch()) !== false) {
                if (is_array($row)) {
                    // The receipt table has no updated_at column; approximate
                    // ordering timestamp from the row id sequence.
                    // TODO(hubs-start): persist a real updated_at on receipts so
                    // the 4-step pill shows accurate progression times.
                    $row['updated_at'] = null;
                    $rows[] = $row;
                }
            }
            $r->closeCursor();
            return $rows;
        } catch (\Throwable $e) {
            $this->logger->debug('Hubs Start: receipt fetch failed: ' . $e->getMessage());
            return [];
        }
    }

    // -----------------------------------------------------------------------
    // Channel / deep-link / anonymisation helpers
    // -----------------------------------------------------------------------

    /**
     * @return array{channel:string,channelLabel:string,messageType:string}
     */
    private function channelForMailbox(ItslMailbox $mailbox): array {
        // Prefer the SDK address suffix when present, else the routable email,
        // both classified by the ONE authoritative classifier.
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
     * Resolve the channel for an outgoing message by tracing its thread back to
     * a function mailbox and classifying that mailbox's address.
     *
     * @param array<string,ItslMailbox> $mailboxByEmail
     * @return array{channel:string,channelLabel:string,messageType:string}
     */
    private function resolveChannelForMessage(string $messageId, array $mailboxByEmail): array {
        // Trace the message to its owning function mailbox via the
        // mail_messages → mail_accounts → sdkmc_itsl_mailbox join, then classify
        // that mailbox's address with the ONE authoritative classifier.
        if ($messageId !== '') {
            try {
                $info = $this->lookupMailboxIdForMessage($messageId);
                if ($info !== null) {
                    $mailbox = $this->mailboxMapper->findById($info['itsl_mailbox_id']);
                    return $this->channelForMailbox($mailbox);
                }
            } catch (\Throwable $e) {
                // Mail row gone (retention) or mail app absent — fall through.
            }
        }

        // Unknown only when the message cannot be traced to a function mailbox;
        // the widget still renders a neutral channel chip.
        return $this->classifier->classifyAddress('');
    }

    /**
     * Build a thread deep link for a message that can be resolved to a function
     * mailbox; falls back to a (best-effort) thread link with empty mailbox id.
     *
     * @param array<string,ItslMailbox> $mailboxByEmail
     * @return array{app:string,params:array<string,mixed>}
     */
    private function threadDeepLinkForMessage(string $messageId, array $mailboxByEmail): array {
        try {
            $info = $this->lookupMailboxIdForMessage($messageId);
            if ($info !== null) {
                return [
                    'app' => 'thread',
                    'params' => [
                        'itslMailboxId' => $info['itsl_mailbox_id'],
                        'mid' => $info['db_id'],
                    ],
                ];
            }
        } catch (\Throwable $e) {
            // ignore — fall through to best-effort link
        }

        // TODO(hubs-start): when the message cannot be traced to an itsl mailbox
        // (retention cleanup, missing mail row) the SPA's deepLinks.resolve still
        // produces a valid /apps/hubs_start/ fallback URL.
        return [
            'app' => 'thread',
            'params' => [
                'itslMailboxId' => 0,
                'mid' => $messageId,
            ],
        ];
    }

    /**
     * Resolve a mail message-id to its itsl mailbox id + mail db id via the
     * mail_messages → mail_accounts → sdkmc_itsl_mailbox join.
     *
     * @return array{itsl_mailbox_id:int,db_id:int}|null
     */
    private function lookupMailboxIdForMessage(string $messageId): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('m.id AS db_id', 'sim.id AS itsl_mailbox_id')
            ->from('mail_messages', 'm')
            ->join('m', 'mail_mailboxes', 'mb', $qb->expr()->eq('m.mailbox_id', 'mb.id'))
            ->join('mb', 'mail_accounts', 'a', $qb->expr()->eq('mb.account_id', 'a.id'))
            ->join('a', 'sdkmc_itsl_mailbox', 'sim', $qb->expr()->eq('a.email', 'sim.email'))
            ->where($qb->expr()->eq('m.message_id', $qb->createNamedParameter($messageId, IQueryBuilder::PARAM_STR)))
            ->setMaxResults(1);

        $r = $qb->executeQuery();
        $row = $r->fetch();
        $r->closeCursor();

        if (!is_array($row) || !isset($row['db_id'], $row['itsl_mailbox_id'])) {
            return null;
        }
        return [
            'itsl_mailbox_id' => (int)$row['itsl_mailbox_id'],
            'db_id' => (int)$row['db_id'],
        ];
    }

    /**
     * Map an SDK / MW receipt status onto the 4-step kvittens vocabulary used by
     * KvittensWidget: skickat → levererat → last → besvarat, plus problem.
     * Replaces the legacy 10-minute PENDING→REJECTED frontend heuristic.
     */
    private function mapReceiptState(string $status): string {
        $s = strtolower(trim($status));
        return match (true) {
            $s === '' => 'skickat',
            str_contains($s, 'reject'), str_contains($s, 'fail'),
            str_contains($s, 'error'), str_contains($s, 'avvis') => self::STATUS_PROBLEM,
            str_contains($s, 'answer'), str_contains($s, 'besvar'),
            str_contains($s, 'repl') => 'besvarat',
            str_contains($s, 'read'), str_contains($s, 'last'),
            str_contains($s, 'läst') => 'last',
            str_contains($s, 'deliver'), str_contains($s, 'leverer') => 'levererat',
            // TODO(hubs-start): the MW exposes a richer status enum than the
            // legacy heuristic; confirm exact PENDING semantics with the SDK team
            // before mapping ambiguous codes (see handover note).
            str_contains($s, 'pend'), str_contains($s, 'sent'),
            str_contains($s, 'skick') => 'skickat',
            default => 'skickat',
        };
    }

    /**
     * Verb-first, anonymised queue title. Never contains citizen PII — only the
     * channel label, an optional dnr, and a verb appropriate to the section.
     *
     * @param array{channel:string,channelLabel:string,messageType:string} $channelInfo
     * @param array<string,mixed> $row
     */
    private function anonymiseTitle(array $channelInfo, array $row, string $section): string {
        $label = $channelInfo['channelLabel'];
        $dnr = $this->extractDnr($row);
        $suffix = $dnr !== null ? ' (' . $dnr . ')' : '';

        return match ($section) {
            self::SECTION_OTILLDELAT => $this->l->t('Tilldela %s', [$label]) . $suffix,
            self::SECTION_KRAVER_ATGARD => $this->l->t('Besvara %s', [$label]) . $suffix,
            self::SECTION_NYTT => $this->l->t('Granska %s', [$label]) . $suffix,
            default => $this->l->t('Hantera %s', [$label]) . $suffix,
        };
    }

    /**
     * Anonymised recipient label for the Kvittenser widget — a document
     * reference (case-style id, not PII) or the channel label.
     *
     * @param array<string,mixed> $row
     * @param array{channel:string,channelLabel:string,messageType:string} $channel
     */
    private function anonymiseRecipient(array $row, array $channel): string {
        $ref = (string)($row['document_reference'] ?? '');
        if ($ref !== '' && !str_contains($ref, '@')) {
            return $ref;
        }
        return $channel['channelLabel'];
    }

    /**
     * Pull a non-PII case number (diarienummer) out of a row's subject if it
     * matches a dnr-like pattern, else null. Subjects themselves are never
     * surfaced (may contain PII).
     *
     * @param array<string,mixed> $row
     */
    private function extractDnr(array $row): ?string {
        $subject = (string)($row['subject'] ?? '');
        if ($subject === '') {
            return null;
        }
        // Common Swedish dnr patterns: "ABC-2026-1234", "2026/1234", "Dnr 1234-26".
        if (preg_match('/\b([A-ZÅÄÖ]{0,4}[-\s]?\d{2,4}[-\/]\d{1,6})\b/u', $subject, $m) === 1) {
            return trim($m[1]);
        }
        return null;
    }

    // -----------------------------------------------------------------------
    // Identity / LOA
    // -----------------------------------------------------------------------

    private function displayName(string $userId): string {
        $user = $this->userManager->get($userId);
        if ($user instanceof IUser) {
            $name = $user->getDisplayName();
            if ($name !== '') {
                return $name;
            }
        }
        return $userId;
    }

    /**
     * Resolve the user's current tillitsnivå (LOA). The authoritative source is
     * sdkmc's login-security state; we read it conservatively and default to LOA3
     * (the store also defaults to LOA3) so the header never falsely downgrades.
     */
    private function resolveLoa(string $userId): string {
        // TODO(hubs-start): read the real login-security / loa3Tag state from
        // sdkmc's settings service (getSettings.loginSecurity) once that is
        // exposed as an injectable service rather than a controller action.
        unset($userId);
        return 'LOA3';
    }

    // -----------------------------------------------------------------------
    // Cursor / cache / format utilities
    // -----------------------------------------------------------------------

    /**
     * Advance the incremental cursor. The cursor encodes the highest item
     * `since` seen so the store can request only newer rows next poll.
     */
    private function advanceCursor(?string $current, string $since, string $itemId): ?string {
        unset($itemId);
        if ($current === null || $current === '') {
            return $since;
        }
        return strcmp($since, $current) > 0 ? $since : $current;
    }

    /** Decode an ISO-8601 sinceIds cursor back to a unix timestamp. */
    private function cursorToTimestamp(string $cursor): ?int {
        try {
            return (new \DateTimeImmutable($cursor))->getTimestamp();
        } catch (\Throwable $e) {
            return null;
        }
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
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function nowIso(): string {
        return (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
    }

    private function cacheKey(string $userId, ?string $sinceIds): string {
        return md5($userId . '|' . ($sinceIds ?? ''));
    }

    /**
     * Full, empty Summary shape — returned on any aggregation failure so the SPA
     * always has a renderable contract.
     *
     * @return array<string,mixed>
     */
    private function emptySummary(string $userId): array {
        return [
            'loa' => $this->resolveLoa($userId),
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
