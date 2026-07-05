<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DBException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<EngineEvent>
 */
class EngineEventMapper extends QBMapper {
    public function __construct(
        IDBConnection $db,
        private ITimeFactory $timeFactory,
    ) {
        parent::__construct($db, 'agent_engine_events', EngineEvent::class);
    }

    /**
     * Insert an idempotency row. THROWS on duplicate key — the caller decides
     * whether that means "lost the race" (claim/takeover) or "already
     * processed, skip" (mirroring).
     *
     * @throws DBException unique constraint violation on duplicate event_key
     */
    public function insertKey(string $eventKey, string $category, int $linkId = 0, ?array $payload = null): EngineEvent {
        $event = new EngineEvent();
        $event->setEventKey($eventKey);
        $event->setCategory($category);
        $event->setLinkId($linkId);
        $event->setPayload($payload === null ? null : json_encode($payload, JSON_UNESCAPED_UNICODE));
        $event->setCreatedAt($this->timeFactory->getTime());
        return $this->insert($event);
    }

    /**
     * Non-throwing variant: true when this call inserted the key (first
     * delivery), false when it already existed (duplicate — skip). Any other
     * DB error is rethrown.
     */
    public function claimKey(string $eventKey, string $category, int $linkId = 0, ?array $payload = null): bool {
        try {
            $this->insertKey($eventKey, $category, $linkId, $payload);
            return true;
        } catch (DBException $e) {
            if ($e->getReason() === DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION
                || $e->getReason() === DBException::REASON_CONSTRAINT_VIOLATION) {
                return false;
            }
            throw $e;
        }
    }

    public function findByKey(string $eventKey): ?EngineEvent {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('event_key', $qb->createNamedParameter($eventKey, IQueryBuilder::PARAM_STR)))
            ->setMaxResults(1);
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /** Delete a key (e.g. releasing a claim row when a card re-enters Agent Todo). */
    public function deleteKey(string $eventKey): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('event_key', $qb->createNamedParameter($eventKey, IQueryBuilder::PARAM_STR)));
        return $qb->executeStatement();
    }
}
