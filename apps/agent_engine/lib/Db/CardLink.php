<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One origin↔engine card pairing (table `agent_engine_links`).
 *
 * Carries ONLY coordination state (ids, uids, state machine, cursors) — the
 * content lives on the Deck cards themselves. The `open_key` column implements
 * the UNIQUE-open-link-per-origin-card constraint (CONTRACTS §3): it equals
 * "<originBoard>:<originCard>" while state='open' and is NULL otherwise.
 *
 * @method int|null getId()
 * @method void setId(int $id)
 * @method int getOriginBoard()
 * @method void setOriginBoard(int $v)
 * @method int getOriginStack()
 * @method void setOriginStack(int $v)
 * @method int getOriginCard()
 * @method void setOriginCard(int $v)
 * @method int getEngineBoard()
 * @method void setEngineBoard(int $v)
 * @method int getEngineCard()
 * @method void setEngineCard(int $v)
 * @method string getAgentCode()
 * @method void setAgentCode(string $v)
 * @method string getBotUid()
 * @method void setBotUid(string $v)
 * @method string getOwnerUid()
 * @method void setOwnerUid(string $v)
 * @method string getRequesterUid()
 * @method void setRequesterUid(string $v)
 * @method string getReviewerUid()
 * @method void setReviewerUid(string $v)
 * @method string getState()
 * @method void setState(string $v)
 * @method string getPhase()
 * @method void setPhase(string $v)
 * @method string|null getOpenKey()
 * @method void setOpenKey(?string $v)
 * @method int getRecallRequested()
 * @method void setRecallRequested(int $v)
 * @method int getReworkCycles()
 * @method void setReworkCycles(int $v)
 * @method int getOriginCursor()
 * @method void setOriginCursor(int $v)
 * @method int getEngineCursor()
 * @method void setEngineCursor(int $v)
 * @method int getStatusCommentId()
 * @method void setStatusCommentId(int $v)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $v)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $v)
 * @method int getClaimedAt()
 * @method void setClaimedAt(int $v)
 */
class CardLink extends Entity implements \JsonSerializable {
    protected int $originBoard = 0;
    protected int $originStack = 0;
    protected int $originCard = 0;
    protected int $engineBoard = 0;
    protected int $engineCard = 0;
    protected string $agentCode = '';
    protected string $botUid = '';
    protected string $ownerUid = '';
    protected string $requesterUid = '';
    protected string $reviewerUid = '';
    protected string $state = 'open';
    protected string $phase = 'todo';
    protected ?string $openKey = null;
    protected int $recallRequested = 0;
    protected int $reworkCycles = 0;
    protected int $originCursor = 0;
    protected int $engineCursor = 0;
    protected int $statusCommentId = 0;
    protected int $createdAt = 0;
    protected int $updatedAt = 0;
    protected int $claimedAt = 0;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('originBoard', 'integer');
        $this->addType('originStack', 'integer');
        $this->addType('originCard', 'integer');
        $this->addType('engineBoard', 'integer');
        $this->addType('engineCard', 'integer');
        $this->addType('agentCode', 'string');
        $this->addType('botUid', 'string');
        $this->addType('ownerUid', 'string');
        $this->addType('requesterUid', 'string');
        $this->addType('reviewerUid', 'string');
        $this->addType('state', 'string');
        $this->addType('phase', 'string');
        $this->addType('openKey', 'string');
        $this->addType('recallRequested', 'integer');
        $this->addType('reworkCycles', 'integer');
        $this->addType('originCursor', 'integer');
        $this->addType('engineCursor', 'integer');
        $this->addType('statusCommentId', 'integer');
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
        $this->addType('claimedAt', 'integer');
    }

    public static function openKeyFor(int $originBoard, int $originCard): string {
        return $originBoard . ':' . $originCard;
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'originBoard' => $this->originBoard,
            'originCard' => $this->originCard,
            'engineBoard' => $this->engineBoard,
            'engineCard' => $this->engineCard,
            'agentCode' => $this->agentCode,
            'botUid' => $this->botUid,
            'ownerUid' => $this->ownerUid,
            'requesterUid' => $this->requesterUid,
            'reviewerUid' => $this->reviewerUid,
            'state' => $this->state,
            'phase' => $this->phase,
            'recallRequested' => $this->recallRequested === 1,
            'reworkCycles' => $this->reworkCycles,
            'createdAt' => $this->createdAt,
            'claimedAt' => $this->claimedAt,
        ];
    }
}
