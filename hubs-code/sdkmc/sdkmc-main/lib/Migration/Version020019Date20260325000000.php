<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Migration;

use Closure;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add RFC 2822 angle brackets to email Message-IDs in the message_thread table.
 *
 * Previously javamw stripped angle brackets before storing, creating a format
 * mismatch with oc_mail_messages (which stores the canonical <message-id> form).
 * This broke the recipient disambiguation JOIN in getBySdkMessageForRecipient.
 *
 * Idempotent: rows already wrapped in <...> are skipped via NOT LIKE guard.
 *
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 */
class Version020019Date20260325000000 extends SimpleMigrationStep {
    public function __construct(
        private IDBConnection $db,
    ) {
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $this->wrapWithAngleBrackets('message_id', false, $output);
        $this->wrapWithAngleBrackets('in_reply_to', true, $output);
        $this->wrapWithAngleBrackets('conversation_id', false, $output);
    }

    private function wrapWithAngleBrackets(string $column, bool $nullable, IOutput $output): void {
        $qb = $this->db->getQueryBuilder();

        $qb->update('sdkmc_message_thread')
            ->set($column, $qb->func()->concat(
                $qb->expr()->literal('<'),
                $column,
                $qb->expr()->literal('>')
            ))
            ->where($qb->expr()->notLike($column, $qb->createNamedParameter('<%>')));

        if ($nullable) {
            $qb->andWhere($qb->expr()->isNotNull($column));
        }

        $updated = $qb->executeStatement();
        $output->info("Added angle brackets to $updated rows in sdkmc_message_thread.$column");
    }
}
