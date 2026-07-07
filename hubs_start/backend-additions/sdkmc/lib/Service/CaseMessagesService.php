<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * HUBS-START BACKEND-ADDITION · UPSTREAM-KANDIDAT
 * Target: lib/Service/CaseMessagesService.php
 */

namespace OCA\SdkMc\Service;

use OCA\SdkMc\Db\AccountItslMailboxMapper;
use OCA\SdkMc\Db\ItslMailbox;
use OCA\SdkMc\Db\ItslMailboxMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * ÄRENDETS MEDDELANDEN — kortets "Meddelanden"-flik: alla meddelanden som är
 * kopplade till ett ärende via case:-taggen ({@see ItslTagService}), oavsett
 * kanal (SDK/säker e-post/intern/fax/sms) och riktning (in/ut).
 *
 * case:-taggen ÄR kopplingen: den sätts i handläggarens session vid "Ta emot"/
 * koppla/composer-&case= och bor i sdkmc:s EGNA taggtabeller
 * (oc_sdkmc_itsl_message_tag + oc_sdkmc_itsl_tag) — samma sanning som
 * inflöde-feedens triage-filter läser.
 *
 * ACL: EXAKT samma mailbox-behörighetsmodell som InflodeFeedService/
 * SummaryService (direkt + grupp-tilldelning via AccountItslMailboxMapper) —
 * ett taggat meddelande i en korg användaren INTE har åtkomst till returneras
 * ALDRIG (invarianten: läck aldrig över behörighetsgräns; PII till behöriga är
 * avsedd, se PII-principen).
 *
 * Read-only, graceful: varje fel ⇒ ärligt tom lista, aldrig 500, aldrig syntes.
 */
class CaseMessagesService {
    /** Hard cap — kortets flik är en översikt, inte ett arkiv. */
    private const MAX_ROWS = 50;

    public function __construct(
        private ItslMailboxMapper $mailboxMapper,
        private AccountItslMailboxMapper $accountMailboxMapper,
        private ChannelClassificationService $classifier,
        private IDBConnection $db,
        private IUserManager $userManager,
        private IGroupManager $groupManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Meddelanden kopplade till ett ärende (nyast först), ACL-filtrerade till
     * användarens korgar.
     *
     * @param string $userId     Den INLOGGADE användaren (ACL-subjektet).
     * @param string $hubsCaseId Ärendets pseudonyma id (case:-taggens suffix).
     * @return list<array<string,mixed>> rader {id, amne, inkom, olast, kanal, deepLink}
     */
    public function getCaseMessages(string $userId, string $hubsCaseId): array {
        if ($userId === '' || $hubsCaseId === '') {
            return [];
        }
        try {
            $taggedIds = $this->taggedMessageIds($hubsCaseId);
            if ($taggedIds === []) {
                return [];
            }

            $mailboxes = $this->getAccessibleMailboxes($userId);
            if ($mailboxes === []) {
                return [];
            }
            $byEmail = [];
            foreach ($mailboxes as $mailbox) {
                $byEmail[strtolower($mailbox->getEmail())] = $mailbox;
            }

            $rows = $this->fetchMessageRows($taggedIds, array_keys($byEmail));

            $out = [];
            $sedda = [];
            foreach ($rows as $row) {
                // Dedup: en funktionskorg med två mail_accounts speglar samma
                // logiska meddelande — behåll första (nyast, ORDER BY sent_at DESC).
                $mid = (string)($row['message_id'] ?? '');
                if ($mid === '' || isset($sedda[$mid])) {
                    continue;
                }
                $sedda[$mid] = true;

                $mailbox = $byEmail[strtolower((string)($row['email'] ?? ''))] ?? null;
                $out[] = [
                    'id' => (int)($row['db_id'] ?? 0),
                    // PII-principen: ämnesraden visas OSKRUBBAD för behöriga —
                    // ACL:en ovan är gränsen, inte maskning.
                    'amne' => (string)($row['subject'] ?? ''),
                    'inkom' => $this->isoOrNull($row['sent_at'] ?? null),
                    'olast' => !((bool)($row['flag_seen'] ?? true)),
                    'kanal' => $mailbox !== null ? $this->channelForMailbox($mailbox) : null,
                    'deepLink' => [
                        'app' => 'thread',
                        'params' => [
                            'itslMailboxId' => $mailbox?->getId(),
                            'mid' => $row['db_id'] ?? '',
                        ],
                    ],
                ];
                if (count($out) >= self::MAX_ROWS) {
                    break;
                }
            }
            return $out;
        } catch (\Throwable $e) {
            $this->logger->debug('[hubs-start] case-messages failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * RFC Message-ID:n för alla meddelanden som bär ärendets case:-tagg
     * (soft-deleted taggar ignoreras).
     *
     * @return list<string>
     */
    private function taggedMessageIds(string $hubsCaseId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('mmt.imap_message_id')
            ->from('sdkmc_itsl_message_tag', 'mmt')
            ->join('mmt', 'sdkmc_itsl_tag', 'mt', $qb->expr()->eq('mmt.tag_id', 'mt.id'))
            ->where($qb->expr()->eq('mt.imap_label', $qb->createNamedParameter('case:' . $hubsCaseId, IQueryBuilder::PARAM_STR)))
            ->andWhere($qb->expr()->isNull('mt.deleted_at'));

        $ids = [];
        $r = $qb->executeQuery();
        while (($row = $r->fetch()) !== false) {
            if (is_array($row) && isset($row['imap_message_id']) && (string)$row['imap_message_id'] !== '') {
                $ids[] = (string)$row['imap_message_id'];
            }
        }
        $r->closeCursor();
        return $ids;
    }

    /**
     * Meddelanderader (alla korgar/riktningar utom papperskorgen) för de taggade
     * message-id:na, begränsat till användarens korgar (ACL via e-postlistan).
     *
     * @param list<string> $messageIds
     * @param list<string> $emails lowercase-adresser för användarens korgar
     * @return list<array<string,mixed>>
     */
    private function fetchMessageRows(array $messageIds, array $emails): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('m.id AS db_id', 'm.message_id', 'm.subject', 'm.sent_at', 'm.flag_seen', 'a.email AS email', 'mb.name AS mb_name')
            ->from('mail_messages', 'm')
            ->join('m', 'mail_mailboxes', 'mb', $qb->expr()->eq('m.mailbox_id', 'mb.id'))
            ->join('mb', 'mail_accounts', 'a', $qb->expr()->eq('mb.account_id', 'a.id'))
            ->where($qb->expr()->in('m.message_id', $qb->createNamedParameter($messageIds, IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($qb->expr()->in($qb->func()->lower('a.email'), $qb->createNamedParameter($emails, IQueryBuilder::PARAM_STR_ARRAY)))
            // Papperskorgen är inte en ärendevy.
            ->andWhere($qb->expr()->neq($qb->func()->lower('mb.name'), $qb->createNamedParameter('trash', IQueryBuilder::PARAM_STR)))
            ->orderBy('m.sent_at', 'DESC')
            ->setMaxResults(self::MAX_ROWS * 4);

        $rows = [];
        $r = $qb->executeQuery();
        while (($row = $r->fetch()) !== false) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        $r->closeCursor();
        return $rows;
    }

    /**
     * Funktionskorgar användaren får agera i — identisk modell som
     * InflodeFeedService/SummaryService (direkt + grupp-tilldelning).
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
            } catch (DoesNotExistException | MultipleObjectsReturnedException) {
                continue;
            }
        }
        return $mailboxes;
    }

    /** @return array<string,mixed> kanal-info {channel, label, …} */
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
     * @param int|string|null $value unix timestamp (seconds) or ISO string
     */
    private function isoOrNull(int|string|null $value): ?string {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (new \DateTimeImmutable('@' . (int)$value))->format('c');
        }
        try {
            return (new \DateTimeImmutable((string)$value))->format('c');
        } catch (\Throwable) {
            return null;
        }
    }
}
