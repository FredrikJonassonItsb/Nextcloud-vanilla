<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Migration;

use Closure;
use Doctrine\DBAL\Types\Type;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Fix #256: Change notification dedup key from per-user DB integer to IMAP Message-ID string.
 * Add UNIQUE constraint for race-condition-safe insert-before-send pattern.
 *
 * ## Column length rationale: VARCHAR(512) for IMAP Message-ID
 *
 * RFC 5322 imposes no explicit Message-ID length limit, but the 998-char line
 * length rule + prohibition on folding inside angle brackets caps it at ~984.
 * RFC 4130 §5.3.3 is the only RFC with an explicit recommendation: max 998,
 * SHOULD be ≤255 for backward compatibility.
 *
 * Real-world data: >99% of Message-IDs are under 128 chars. The longest
 * documented legitimate ID was 291 chars (Gmail Groups; see nextcloud/mail#3244).
 * Spam can exceed 1023 chars (see nextcloud/mail#9102).
 *
 * Column lengths for IMAP Message-ID across related tables:
 *
 *   mail_messages.message_id              = 1023  (Mail app, expanded from 255→1024→1023)
 *   mail_messages.in_reply_to             = 1023  (Mail app)
 *   mail_messages.thread_root_id          = 1023  (Mail app)
 *   mail_message_tags.imap_message_id     = 1023  (Mail app)
 *   mail_local_messages.in_reply_to_...   = 1023  (Mail app)
 *   sdkmc_message_thread.message_id       = 1023  (sdkmc)
 *   sdkmc_itsl_message_tag.imap_message_id = 1023  (sdkmc)
 *   sdkmc_mbox_notif_log.message_id       = 512   (this table — see below)
 *
 * We use 512 instead of the usual 1023 because this column participates in a
 * composite UNIQUE index (recipient, message_id) for atomic dedup. InnoDB with
 * utf8mb4 (4 bytes/char) limits index keys to 3072 bytes:
 *
 *   recipient VARCHAR(255) = 255×4+2 = 1022 bytes
 *   message_id VARCHAR(N)  =   N×4+2 bytes
 *   ────────────────────────────────────────
 *   N=512  → 1022 + 2050 = 3072 bytes ✓ (exactly at limit)
 *   N=1023 → 1022 + 4094 = 5116 bytes ✗ (exceeds 3072)
 *
 * 512 covers all known legitimate Message-IDs. The Mail app's indexes on their
 * 1023-length columns use prefix lengths (64-128 chars) which cannot enforce
 * uniqueness — we need a real UNIQUE constraint for the insert-before-send
 * dedup pattern.
 *
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 */
class Version020016Date20260219000000 extends SimpleMigrationStep {
    public function __construct(
        private IDBConnection $db,
    ) {
    }

    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        // Truncate: old entries used wrong dedup key (per-user DB integer)
        $this->db->executeStatement('DELETE FROM *PREFIX*sdkmc_mbox_notif_log');
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('sdkmc_mbox_notif_log')) {
            return null;
        }

        $table = $schema->getTable('sdkmc_mbox_notif_log');

        // Drop old non-unique index
        if ($table->hasIndex('sdkmc_mbn_log_idx')) {
            $table->dropIndex('sdkmc_mbn_log_idx');
        }

        // Change message_id from BIGINT to STRING(512) for IMAP Message-ID
        // (512 is the max that fits in the composite UNIQUE index — see class docblock)
        $table->modifyColumn('message_id', [
            'type' => Type::getType(Types::STRING),
            'length' => 512,
            'notnull' => true,
        ]);

        // Add UNIQUE index for atomic dedup via insert-before-send
        if (!$table->hasIndex('sdkmc_mbn_log_uniq')) {
            $table->addUniqueIndex(['recipient', 'message_id'], 'sdkmc_mbn_log_uniq');
        }

        return $schema;
    }
}
