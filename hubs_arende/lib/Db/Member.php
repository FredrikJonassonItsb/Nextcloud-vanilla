<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A first-class MEMBER of an ärenderum — a user (NC uid) who belongs to the case
 * room in a given role.
 *
 * Maps to table `hubs_arende_member` (NC adds the `oc_` prefix).
 *
 * Why a dedicated table (not a pekare): a pekare points at an EXTERNAL OBJECT
 * (folderId, talkToken…); a member is a PRINCIPAL (a uid) with a role, and the
 * conceptual model makes "who is in the room" a first-class, ENUMERABLE property
 * of the ärenderum — the engine must be able to list members without querying
 * Talk/groupfolder. The mottagningskrets is recorded at case birth (R4/R6); the
 * assignee (and any co-handläggare) are added at {@see \OCA\HubsArende\Service\ArendeService::tilldela()}.
 *
 * Carries ONLY a uid + a role — never PII, never verksamhetsdata.
 *
 * Roles (roll): the room's user set, distinguished by how they got there:
 *   - {@see ROLL_MOTTAGNINGSKRETS} the enhet's reception circle (group-derived at birth)
 *   - {@see ROLL_HANDLAGGARE}      the assigned handläggare (tilldela)
 *   - {@see ROLL_CO_HANDLAGGARE}   an additional concurrent handläggare (co-handläggare)
 *   - {@see ROLL_OBSERVATOR}       read-only participant (e.g. arbetsledare)
 *
 * @method int|null getId()
 * @method void setId(int $id)
 * @method string getHubsCaseId()
 * @method void setHubsCaseId(string $hubsCaseId)
 * @method string getUid()
 * @method void setUid(string $uid)
 * @method string getRoll()
 * @method void setRoll(string $roll)
 * @method \DateTime|null getSkapad()
 * @method void setSkapad(\DateTime $skapad)
 */
class Member extends Entity implements \JsonSerializable {
    /** The enhet's reception circle — group-derived at case birth (R4/R6). */
    public const ROLL_MOTTAGNINGSKRETS = 'mottagningskrets';
    /** The assigned handläggare (set by tilldela). */
    public const ROLL_HANDLAGGARE = 'handlaggare';
    /** An additional, concurrent handläggare (co-handläggare). */
    public const ROLL_CO_HANDLAGGARE = 'co_handlaggare';
    /** A read-only participant (e.g. arbetsledare / insyn). */
    public const ROLL_OBSERVATOR = 'observator';

    /** FK -> hubs_arende_case.hubs_case_id (UUID v4). */
    protected string $hubsCaseId = '';
    /** The member's NC user id. */
    protected string $uid = '';
    /** mottagningskrets | handlaggare | co_handlaggare | observator */
    protected string $roll = '';
    /** When the membership was recorded. */
    protected ?\DateTime $skapad = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('hubsCaseId', 'string');
        $this->addType('uid', 'string');
        $this->addType('roll', 'string');
        $this->addType('skapad', 'datetime');
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'hubsCaseId' => $this->hubsCaseId,
            'uid' => $this->uid,
            'roll' => $this->roll,
            'skapad' => $this->skapad?->format('c'),
        ];
    }
}
