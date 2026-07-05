<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * The three engine tables (CONTRACTS §3), NC prefixes `oc_`:
 *
 *  - agent_engine_links   — card_links: origin↔engine pairing, actors, state
 *                           machine, per-direction cursors, timestamps.
 *                           UNIQUE OPEN LINK per origin card is enforced with
 *                           the `open_key` column: it equals
 *                           "<origin_board>:<origin_card>" while state='open'
 *                           and NULL otherwise; the unique index on it is the
 *                           portable (MySQL/PG/SQLite) form of a partial
 *                           unique index — NULLs never collide.
 *  - agent_engine_boards  — enrolled boards + per-board flags.
 *  - agent_engine_events  — idempotency + audit for listeners and sweeps.
 *                           ALSO carries the claim mutex rows
 *                           (event_key = 'claim:<engineCardId>'): the unique
 *                           index makes the claim insert the one-winner lock.
 *
 * Idempotent (hasTable-guarded), mirrors hubs_arende migration conventions.
 *
 * @SuppressWarnings("PHPMD.UnusedFormalParameter")
 */
class Version000100Date20260705000000 extends SimpleMigrationStep {
    /**
     * @param IOutput $output
     * @param Closure(): ISchemaWrapper $schemaClosure
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $changed = false;

        if (!$schema->hasTable('agent_engine_links')) {
            $table = $schema->createTable('agent_engine_links');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('origin_board', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('origin_stack', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $table->addColumn('origin_card', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('engine_board', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            // 0 until the engine card is created (the link row is inserted FIRST
            // as the takeover mutex; see TakeoverService).
            $table->addColumn('engine_card', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $table->addColumn('agent_code', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('bot_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('owner_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('requester_uid', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
            $table->addColumn('reviewer_uid', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
            // open | review | done | recalled | refused
            // NB: DB default MUST match the entity default ('open'). NC's
            // Entity::setter() skips marking a field dirty when the new value
            // equals the current one, so setState('open') on a fresh entity
            // (whose $state already defaults to 'open') is omitted from the
            // INSERT — without a DB default that trips the NOT NULL constraint.
            $table->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'open']);
            // Engine-card sub-state while open/review: todo|working|blocked|hold|review
            $table->addColumn('phase', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'todo']);
            // "<origin_board>:<origin_card>" while open, NULL otherwise → the
            // UNIQUE-open-link constraint (portable partial unique index).
            $table->addColumn('open_key', Types::STRING, ['notnull' => false, 'length' => 64]);
            // Cooperative recall flag (INTERAKTIONSDESIGN §2.7) — completion wins.
            $table->addColumn('recall_requested', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
            $table->addColumn('rework_cycles', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
            // Per-direction mirror cursors: highest NC comment id already mirrored.
            $table->addColumn('origin_cursor', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $table->addColumn('engine_cursor', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            // The living ⇄ status comment on the origin card (edited in place).
            $table->addColumn('status_comment_id', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $table->addColumn('claimed_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['open_key'], 'ae_links_open_key');
            $table->addIndex(['origin_card'], 'ae_links_origin_card');
            $table->addIndex(['engine_card'], 'ae_links_engine_card');
            $table->addIndex(['state'], 'ae_links_state');
            $changed = true;
        }

        if (!$schema->hasTable('agent_engine_boards')) {
            $table = $schema->createTable('agent_engine_boards');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('board_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('enabled', Types::SMALLINT, ['notnull' => true, 'default' => 1]);
            // comment_only | move_to_stack:<id>
            $table->addColumn('on_done', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => 'comment_only']);
            // Per-board conservative mode (land in Inbox instead of Agent Todo) — default OFF.
            $table->addColumn('conservative', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
            // Human enrollment PII review (INTERAKTIONSDESIGN §2.11) — who + when.
            $table->addColumn('pii_reviewed_by', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
            $table->addColumn('enrolled_by', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
            // ETag cache for the 2-min sweep's If-None-Match polling.
            $table->addColumn('etag', Types::STRING, ['notnull' => true, 'length' => 128, 'default' => '']);
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['board_id'], 'ae_boards_board');
            $changed = true;
        }

        if (!$schema->hasTable('agent_engine_events')) {
            $table = $schema->createTable('agent_engine_events');
            $table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
            // Idempotency key, e.g. 'claim:<cardId>', 'm:o2e:<linkId>:<commentId>',
            // 'takeover:<board>:<card>', 'stall:<linkId>'. UNIQUE = the mutex.
            $table->addColumn('event_key', Types::STRING, ['notnull' => true, 'length' => 190]);
            $table->addColumn('link_id', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            // claim | mirror | takeover | recall | refusal | sweep | notify | audit
            $table->addColumn('category', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'audit']);
            // Small coordination payload (JSON) — e.g. {"agentCode":"reb-claude"}.
            // NEVER free text / PII.
            $table->addColumn('payload', Types::TEXT, ['notnull' => false]);
            $table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'default' => 0]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['event_key'], 'ae_events_key');
            $table->addIndex(['link_id'], 'ae_events_link');
            $changed = true;
        }

        return $changed ? $schema : null;
    }
}
