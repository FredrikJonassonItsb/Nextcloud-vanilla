<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Middleware;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\Server;
use Psr\Log\LoggerInterface;

class SignatureSyncMiddleware extends Middleware {
    public function __construct(
        private IRequest $request,
        private IDBConnection $db,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     * @phpstan-ignore-next-line missingType.generics (Response generic types not relevant here)
     */
    public function afterController(Controller $controller, string $methodName, Response $response): Response {
        if (get_class($controller) !== 'OCA\\Mail\\Controller\\AccountsController') {
            return $response;
        }

        if ($response->getStatus() !== Http::STATUS_OK) {
            return $response;
        }

        try {
            if ($methodName === 'updateSignature') {
                $this->syncSignature();
            } elseif ($methodName === 'patchAccount') {
                $this->syncSignatureAboveQuote();
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to sync shared mailbox signature: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }

        return $response;
    }

    private function syncSignature(): void {
        $accountId = (int)$this->request->getParam('id'); // @phpstan-ignore cast.int

        /** @var \OCA\Mail\Db\MailAccountMapper $mailAccountMapper */
        $mailAccountMapper = Server::get(\OCA\Mail\Db\MailAccountMapper::class);
        $account = $mailAccountMapper->findById($accountId);
        $email = $account->getEmail();
        $signature = $account->getSignature();

        $qb = $this->db->getQueryBuilder();
        $qb->update('mail_accounts')
            ->set('signature', $qb->createNamedParameter($signature))
            ->where($qb->expr()->eq('email', $qb->createNamedParameter($email)))
            ->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($accountId)));

        $affected = $qb->executeStatement();
        if ($affected > 0) {
            $this->logger->info('Synced signature for {email} to {affected} other account(s)', [
                'email' => $email,
                'affected' => $affected,
            ]);
        }
    }

    private function syncSignatureAboveQuote(): void {
        $signatureAboveQuote = $this->request->getParam('signatureAboveQuote');
        if ($signatureAboveQuote === null) {
            return;
        }

        $accountId = (int)$this->request->getParam('id'); // @phpstan-ignore cast.int

        /** @var \OCA\Mail\Db\MailAccountMapper $mailAccountMapper */
        $mailAccountMapper = Server::get(\OCA\Mail\Db\MailAccountMapper::class);
        $account = $mailAccountMapper->findById($accountId);
        $email = $account->getEmail();

        $qb = $this->db->getQueryBuilder();
        $qb->update('mail_accounts')
            ->set('signature_above_quote', $qb->createNamedParameter(
                $account->isSignatureAboveQuote(),
                IQueryBuilder::PARAM_BOOL
            ))
            ->where($qb->expr()->eq('email', $qb->createNamedParameter($email)))
            ->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($accountId)));

        $affected = $qb->executeStatement();
        if ($affected > 0) {
            $this->logger->info('Synced signatureAboveQuote for {email} to {affected} other account(s)', [
                'email' => $email,
                'affected' => $affected,
            ]);
        }
    }
}
