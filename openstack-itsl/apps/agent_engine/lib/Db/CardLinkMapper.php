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
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Single point of write into agent_engine_links.
 *
 * The INSERT of an OPEN link doubles as the takeover mutex: the unique index
 * on open_key means two racing takeovers (listener vs. sweep, or two sweep
 * workers) collapse to one winner — the loser's insert throws a unique
 * constraint violation and aborts silently.
 *
 * @extends QBMapper<CardLink>
 */
class CardLinkMapper extends QBMapper {
    public function __construct(
        IDBConnection $db,
        private ITimeFactory $timeFactory,
    ) {
        parent::__construct($db, 'agent_engine_links', CardLink::class);
    }

    /**
     * Insert a fresh OPEN link (the takeover mutex — open_key set).
     *
     * @throws \OCP\DB\Exception unique violation ⇒ another takeover won
     */
    public function insertOpen(CardLink $link): CardLink {
        $now = $this->timeFactory->getTime();
        $link->setState('open');
        $link->setOpenKey(CardLink::openKeyFor($link->getOriginBoard(), $link->getOriginCard()));
        $link->setCreatedAt($now);
        $link->setUpdatedAt($now);
        return $this->insert($link);
    }

    /** Persist a state transition; leaving 'open'/'review' clears open_key. */
    public function transition(CardLink $link, string $state, ?string $phase = null): CardLink {
        $link->setState($state);
        if ($phase !== null) {
            $link->setPhase($phase);
        }
        if ($state !== 'open' && $state !== 'review') {
            $link->setOpenKey(null);
        }
        $link->setUpdatedAt($this->timeFactory->getTime());
        return $this->update($link);
    }

    /** Touch updated_at and persist whatever fields the caller set. */
    public function save(CardLink $link): CardLink {
        $link->setUpdatedAt($this->timeFactory->getTime());
        return $this->update($link);
    }

    /**
     * The open (or in-review) link for an origin card, if any.
     * open_key covers state='open' AND state='review' (review is still live).
     */
    public function findOpenByOriginCard(int $originCard): ?CardLink {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('origin_card', $qb->createNamedParameter($originCard, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->in('state', $qb->createNamedParameter(
                ['open', 'review'],
                IQueryBuilder::PARAM_STR_ARRAY,
            )))
            ->orderBy('id', 'DESC')
            ->setMaxResults(1);
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /** The live link for an engine card (open or review), if any. */
    public function findOpenByEngineCard(int $engineCard): ?CardLink {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('engine_card', $qb->createNamedParameter($engineCard, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->in('state', $qb->createNamedParameter(
                ['open', 'review'],
                IQueryBuilder::PARAM_STR_ARRAY,
            )))
            ->orderBy('id', 'DESC')
            ->setMaxResults(1);
        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }

    /**
     * All live links (open or review) — the sweep's mirror-catchup working set.
     *
     * @return CardLink[]
     */
    public function findAllLive(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in('state', $qb->createNamedParameter(
                ['open', 'review'],
                IQueryBuilder::PARAM_STR_ARRAY,
            )))
            ->orderBy('id', 'ASC');
        return $this->findEntities($qb);
    }

    /**
     * Live links on one origin board (sweep per-board pass).
     *
     * @return CardLink[]
     */
    public function findLiveByOriginBoard(int $originBoard): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('origin_board', $qb->createNamedParameter($originBoard, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->in('state', $qb->createNamedParameter(
                ['open', 'review'],
                IQueryBuilder::PARAM_STR_ARRAY,
            )))
            ->orderBy('id', 'ASC');
        return $this->findEntities($qb);
    }

    /** Delete a link row (takeover compensation when Deck ops failed). */
    public function deleteById(int $id): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }
}
