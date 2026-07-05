<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One enrolled human board (table `agent_engine_boards`).
 *
 * Enrollment is the human authorization boundary control (INTERAKTIONSDESIGN
 * §2.11): only boards without client/case PII get a row here, recorded with
 * who reviewed (`pii_reviewed_by`) and who enrolled. Un-enroll = disable the
 * row (+ ACL removal, done out-of-band by enroll-board.mjs).
 *
 * @method int|null getId()
 * @method void setId(int $id)
 * @method int getBoardId()
 * @method void setBoardId(int $v)
 * @method int getEnabled()
 * @method void setEnabled(int $v)
 * @method string getOnDone()
 * @method void setOnDone(string $v)
 * @method int getConservative()
 * @method void setConservative(int $v)
 * @method string getPiiReviewedBy()
 * @method void setPiiReviewedBy(string $v)
 * @method string getEnrolledBy()
 * @method void setEnrolledBy(string $v)
 * @method string getEtag()
 * @method void setEtag(string $v)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $v)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $v)
 */
class EnrolledBoard extends Entity implements \JsonSerializable {
    protected int $boardId = 0;
    protected int $enabled = 1;
    protected string $onDone = 'comment_only';
    protected int $conservative = 0;
    protected string $piiReviewedBy = '';
    protected string $enrolledBy = '';
    protected string $etag = '';
    protected int $createdAt = 0;
    protected int $updatedAt = 0;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('boardId', 'integer');
        $this->addType('enabled', 'integer');
        $this->addType('onDone', 'string');
        $this->addType('conservative', 'integer');
        $this->addType('piiReviewedBy', 'string');
        $this->addType('enrolledBy', 'string');
        $this->addType('etag', 'string');
        $this->addType('createdAt', 'integer');
        $this->addType('updatedAt', 'integer');
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'boardId' => $this->boardId,
            'enabled' => $this->enabled === 1,
            'onDone' => $this->onDone,
            'conservative' => $this->conservative === 1,
            'piiReviewedBy' => $this->piiReviewedBy,
            'enrolledBy' => $this->enrolledBy,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }
}
