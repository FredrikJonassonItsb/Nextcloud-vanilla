<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Coordination-state register row for a single case (ärende).
 *
 * Maps to table `hubs_arende_case` (NC adds the `oc_` prefix).
 *
 * IMPORTANT: this entity carries ONLY coordination/routing state — never
 * verksamhetsdata (that lives in the facksystem). `objektRef` is a pseudonym,
 * never PII.
 *
 * Invariant: `commitDestination` is NOT NULL.
 *
 * @method int|null getId()
 * @method void setId(int $id)
 * @method string getHubsCaseId()
 * @method void setHubsCaseId(string $hubsCaseId)
 * @method string|null getTriageRef()
 * @method void setTriageRef(?string $triageRef)
 * @method string|null getConversationId()
 * @method void setConversationId(?string $conversationId)
 * @method string|null getObjektRef()
 * @method void setObjektRef(?string $objektRef)
 * @method string|null getEnhet()
 * @method void setEnhet(?string $enhet)
 * @method string|null getAgareUid()
 * @method void setAgareUid(?string $agareUid)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string getSteg()
 * @method void setSteg(string $steg)
 * @method string|null getDnr()
 * @method void setDnr(?string $dnr)
 * @method string getProvenanceState()
 * @method void setProvenanceState(string $provenanceState)
 * @method string getCommitDestination()
 * @method void setCommitDestination(string $commitDestination)
 * @method string|null getRetentionState()
 * @method void setRetentionState(?string $retentionState)
 * @method \DateTime|null getFristDue()
 * @method void setFristDue(?\DateTime $fristDue)
 * @method \DateTime|null getGallrasDatum()
 * @method void setGallrasDatum(?\DateTime $gallrasDatum)
 * @method string getArendeTyp()
 * @method void setArendeTyp(string $arendeTyp)
 * @method \DateTime|null getSkapad()
 * @method void setSkapad(?\DateTime $skapad)
 */
class Arende extends Entity implements \JsonSerializable {
    /** Canonical join key (UUID v4). UNIQUE. */
    protected string $hubsCaseId = '';
    /** Kommunal triage-referens, e.g. 'SN 2026-0142'. */
    protected ?string $triageRef = null;
    /** Provenance anchor — the inflow conversationId (idempotency key). */
    protected ?string $conversationId = null;
    /** Pseudonym for the case object (e.g. barnRef) — NEVER PII. */
    protected ?string $objektRef = null;
    /** Owning unit / function-address — the ACL boundary. */
    protected ?string $enhet = null;
    /** Assigned handläggare (null = otilldelat). */
    protected ?string $agareUid = null;
    /** otilldelat | tilldelat */
    protected string $status = 'otilldelat';
    /** inflode | forhandsbedomning | utredning | beslut | uppfoljning | avslutat */
    protected string $steg = 'inflode';
    /** Facksystem dnr (null until registered via the commit port). */
    protected ?string $dnr = null;
    /** ej_registrerad | registrerad */
    protected string $provenanceState = 'ej_registrerad';
    /**
     * NOT NULL invariant.
     * facksystem | diarium | e_arkiv | extern_myndighet | triage_forward | karantan
     */
    protected string $commitDestination = '';
    /** aktiv | pausad | gallras_efter_commit */
    protected ?string $retentionState = 'aktiv';
    /** Mirrored from the facksystem — not independently counted. */
    protected ?\DateTime $fristDue = null;
    /** Verkställbar gallrings-deadline från kvittot (committedAt + 90d). */
    protected ?\DateTime $gallrasDatum = null;
    /** FK -> hubs_arende_typ.arende_typ_id */
    protected string $arendeTyp = '';
    protected ?\DateTime $skapad = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('hubsCaseId', 'string');
        $this->addType('triageRef', 'string');
        $this->addType('conversationId', 'string');
        $this->addType('objektRef', 'string');
        $this->addType('enhet', 'string');
        $this->addType('agareUid', 'string');
        $this->addType('status', 'string');
        $this->addType('steg', 'string');
        $this->addType('dnr', 'string');
        $this->addType('provenanceState', 'string');
        $this->addType('commitDestination', 'string');
        $this->addType('retentionState', 'string');
        $this->addType('fristDue', 'datetime');
        $this->addType('gallrasDatum', 'datetime');
        $this->addType('arendeTyp', 'string');
        $this->addType('skapad', 'datetime');
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'hubsCaseId' => $this->hubsCaseId,
            'triageRef' => $this->triageRef,
            'conversationId' => $this->conversationId,
            'objektRef' => $this->objektRef,
            'enhet' => $this->enhet,
            'agareUid' => $this->agareUid,
            'status' => $this->status,
            'steg' => $this->steg,
            'dnr' => $this->dnr,
            'provenanceState' => $this->provenanceState,
            'commitDestination' => $this->commitDestination,
            'retentionState' => $this->retentionState,
            'fristDue' => $this->fristDue?->format('Y-m-d'),
            'gallrasDatum' => $this->gallrasDatum?->format('Y-m-d'),
            'arendeTyp' => $this->arendeTyp,
            'skapad' => $this->skapad?->format(\DateTimeInterface::ATOM),
        ];
    }
}
