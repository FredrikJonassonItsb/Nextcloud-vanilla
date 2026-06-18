<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Two-way pointer from a case to a non-taggable external object.
 *
 * Maps to table `hubs_arende_pekare` (NC adds the `oc_` prefix).
 *
 * The case-engine SAGA (R3–R9) creates side effects in apps that have no
 * case:-tag of their own (a Groupfolder folder id, a Deck card id, a Spreed
 * talkToken, a CalDAV object uri, a sdkmc case:-tag). Those objects cannot be
 * found from the hubsCaseId by tag, so each forward step records a pekare here;
 * the compensating step (and later lookups) resolve the external object id
 * through {@see PekareMapper::findByCaseId()}.
 *
 * Carries ONLY coordination state (object type + external id), never
 * verksamhetsdata.
 *
 * Columns: hubs_case_id, objekt_typ, objekt_id, riktning.
 *
 * @method int|null getId()
 * @method void setId(int $id)
 * @method string getHubsCaseId()
 * @method void setHubsCaseId(string $hubsCaseId)
 * @method string getObjektTyp()
 * @method void setObjektTyp(string $objektTyp)
 * @method string getObjektId()
 * @method void setObjektId(string $objektId)
 * @method string|null getRiktning()
 * @method void setRiktning(?string $riktning)
 */
class Pekare extends Entity implements \JsonSerializable {
    /** FK -> hubs_arende_case.hubs_case_id (UUID v4). */
    protected string $hubsCaseId = '';
    /** deck_card | talk_room | groupfolder | calendar | case_tag | conversation */
    protected string $objektTyp = '';
    /** The external object's native id (folderId, cardId, talkToken, objUri, tagId…). */
    protected string $objektId = '';
    /** Optional relation hint, e.g. 'forward' | 'reverse' | 'in' | 'out'. */
    protected ?string $riktning = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('hubsCaseId', 'string');
        $this->addType('objektTyp', 'string');
        $this->addType('objektId', 'string');
        $this->addType('riktning', 'string');
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'hubsCaseId' => $this->hubsCaseId,
            'objektTyp' => $this->objektTyp,
            'objektId' => $this->objektId,
            'riktning' => $this->riktning,
        ];
    }
}
