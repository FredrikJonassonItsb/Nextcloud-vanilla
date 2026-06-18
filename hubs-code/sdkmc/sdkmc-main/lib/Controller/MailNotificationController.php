<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Controller;

use OCA\SdkMc\Db\ItslMailboxMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class MailNotificationController extends Controller {
    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $userSession,
        private ItslMailboxMapper $itslMailboxMapper,
        private IURLGenerator $urlGenerator,
        private IDBConnection $db,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * @NoCSRFRequired
     * @NoAdminRequired
     * @SuppressWarnings("PHPMD.CyclomaticComplexity")
     * @return RedirectResponse<303, array{}>|JSONResponse<401, array{error: string}, array{}>|JSONResponse<404, array{error: string}, array{}>|JSONResponse<500, array{error: string}, array{}>
     */
    public function redirect(int $itslMailboxId, ?int $mid = null): RedirectResponse|JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }
        $userId = $user->getUID();

        // Look up ITSL mailbox to get email
        try {
            $itslMailbox = $this->itslMailboxMapper->findById($itslMailboxId);
        } catch (DoesNotExistException $e) {
            return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        } catch (MultipleObjectsReturnedException $e) {
            $this->logger->error('Multiple ITSL mailboxes found for ID', ['id' => $itslMailboxId]);
            return new JSONResponse(['error' => 'Internal error'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
        $email = $itslMailbox->getEmail();

        // Find user's mail account for that email
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('mail_accounts')
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('email', $qb->createNamedParameter($email)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        if (!is_array($row) || !isset($row['id'])) {
            // Same 404 as "not found" to prevent ITSL mailbox ID enumeration
            return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        }
        /** @var array{id: int|string} $row */
        $accountId = (int)$row['id'];

        // Fetch ALL mailboxes for this account (INBOX, Sent, Archive, etc.)
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'name')
            ->from('mail_mailboxes')
            ->where($qb->expr()->eq('account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        /** @var list<array{id: int|string, name: string}> $mailboxRows */
        $mailboxRows = $result->fetchAll();
        $result->closeCursor();

        if (count($mailboxRows) === 0) {
            return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
        }

        // Identify INBOX for fallback redirect
        $inboxId = null;
        $allMailboxIds = [];
        foreach ($mailboxRows as $mbRow) {
            $mbId = (int)$mbRow['id'];
            $allMailboxIds[] = $mbId;
            if (strtolower($mbRow['name']) === 'inbox') {
                $inboxId = $mbId;
            }
        }
        // If no INBOX exists (unusual), use first mailbox as fallback
        $fallbackMailboxId = $inboxId ?? $allMailboxIds[0];

        // Optionally deep-link to specific message via numeric mail_messages.id
        $messageId = null;
        $targetMailboxId = $fallbackMailboxId;

        if ($mid !== null) {
            // Step A: resolve numeric ID to RFC Message-ID
            $qb = $this->db->getQueryBuilder();
            $qb->select('message_id')
                ->from('mail_messages')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($mid, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->in('mailbox_id', $qb->createNamedParameter($allMailboxIds, IQueryBuilder::PARAM_INT_ARRAY)))
                ->setMaxResults(1);

            $result = $qb->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();

            if (is_array($row) && isset($row['message_id']) && is_string($row['message_id'])) {
                $rfcMessageId = $row['message_id'];

                // Step B: search across ALL user's mailboxes for this RFC Message-ID
                $qb = $this->db->getQueryBuilder();
                $qb->select('id', 'mailbox_id')
                    ->from('mail_messages')
                    ->where($qb->expr()->in('mailbox_id', $qb->createNamedParameter($allMailboxIds, IQueryBuilder::PARAM_INT_ARRAY)))
                    ->andWhere($qb->expr()->eq('message_id', $qb->createNamedParameter($rfcMessageId)));

                $result = $qb->executeQuery();
                /** @var list<array{id: int|string, mailbox_id: int|string}> $matches */
                $matches = $result->fetchAll();
                $result->closeCursor();

                // Heuristic: prefer INBOX match, then first match in any other mailbox
                foreach ($matches as $match) {
                    if ((int)$match['mailbox_id'] === $inboxId) {
                        $messageId = (int)$match['id'];
                        $targetMailboxId = $inboxId;
                        break;
                    }
                }
                if ($messageId === null && count($matches) > 0) {
                    $messageId = (int)$matches[0]['id'];
                    $targetMailboxId = (int)$matches[0]['mailbox_id'];
                }
            }
        }

        // Build redirect URL
        $path = '/apps/mail/box/' . $targetMailboxId;
        if ($messageId !== null) {
            $path .= '/thread/' . $messageId;
        }

        return new RedirectResponse($this->urlGenerator->getAbsoluteURL($path));
    }
}
