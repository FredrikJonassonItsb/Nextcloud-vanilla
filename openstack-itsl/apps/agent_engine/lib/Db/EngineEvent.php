<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Idempotency/audit row (table `agent_engine_events`).
 *
 * The UNIQUE index on event_key is load-bearing twice over:
 *  - mirror/takeover idempotency (listener-vs-sweep double delivery collapses
 *    to one write), and
 *  - the CLAIM MUTEX: event_key 'claim:<engineCardId>' — the winner's insert
 *    succeeds inside its transaction, the loser gets a unique constraint
 *    violation and a 409 {claimedBy}. This is Nextcloud's documented
 *    replacement for SELECT … FOR UPDATE (see OCP\IDBConnection deprecations).
 *
 * @method int|null getId()
 * @method void setId(int $id)
 * @method string getEventKey()
 * @method void setEventKey(string $v)
 * @method int getLinkId()
 * @method void setLinkId(int $v)
 * @method string getCategory()
 * @method void setCategory(string $v)
 * @method string|null getPayload()
 * @method void setPayload(?string $v)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $v)
 */
class EngineEvent extends Entity implements \JsonSerializable {
    protected string $eventKey = '';
    protected int $linkId = 0;
    protected string $category = 'audit';
    protected ?string $payload = null;
    protected int $createdAt = 0;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('eventKey', 'string');
        $this->addType('linkId', 'integer');
        $this->addType('category', 'string');
        $this->addType('payload', 'string');
        $this->addType('createdAt', 'integer');
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'eventKey' => $this->eventKey,
            'linkId' => $this->linkId,
            'category' => $this->category,
            'payload' => $this->payload,
            'createdAt' => $this->createdAt,
        ];
    }
}
