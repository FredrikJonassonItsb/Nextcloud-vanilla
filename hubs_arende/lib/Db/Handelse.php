<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Db;

use OCP\AppFramework\Db\Entity;

/**
 * En HÄNDELSE i ärendets journal — motorns eget audit-spår per ärende och
 * datakällan för kortets "Historik & beslut"-tidslinje (första bricka av den
 * beslutade journalen, BESLUT-19).
 *
 * Maps to table `hubs_arende_handelse` (NC adds the `oc_` prefix).
 *
 * NEVER-SoR: journalen beskriver vad MOTORN gjorde (koordination + kvitton) —
 * aldrig verksamhetsinnehåll. `detalj` är ett litet JSON-objekt med
 * koordinationsvärden (steg-namn, dnr, roll) — ALDRIG fritext/PII. Raderna
 * gallras MED ärendet (aktorUid är personuppgift; facksystemet äger den
 * permanenta akten).
 *
 * @method int|null getId()
 * @method void setId(int $id)
 * @method string getHubsCaseId()
 * @method void setHubsCaseId(string $hubsCaseId)
 * @method string getTyp()
 * @method void setTyp(string $typ)
 * @method string|null getDetalj()
 * @method void setDetalj(?string $detalj)
 * @method string getAktorUid()
 * @method void setAktorUid(string $aktorUid)
 * @method \DateTime|null getTid()
 * @method void setTid(\DateTime $tid)
 */
class Handelse extends Entity implements \JsonSerializable {
    /** Ärendet föddes (saga R0–R10 klar). detalj: {typ, enhet}. */
    public const TYP_SKAPAD = 'skapad';
    /** Livscykel-övergång. detalj: {fran, till}. */
    public const TYP_STEG = 'steg';
    /** Handläggare tilldelad. detalj: {uid}. */
    public const TYP_TILLDELAD = 'tilldelad';
    /** Medlem tillagd/borttagen. detalj: {uid, roll, riktning: in|ut}. */
    public const TYP_MEDLEM = 'medlem';
    /** Verifierad facksystem-commit. detalj: {dnr, destination}. */
    public const TYP_REGISTRERAD = 'registrerad';
    /** Extra rum/chatt skapad. detalj: {namnRef}. */
    public const TYP_RUM = 'rum';
    /** Meddelande kopplat till ärendet. detalj: {}. */
    public const TYP_KOPPLAD = 'kopplad';
    /**
     * Partsregister-mutation (tillagd/uppslag/uppdaterad/borttagen).
     * detalj: {handling, roll, kalla, korrelationsId?, andamal?, skydd?} —
     * ALDRIG personnummer/namn/adress (PII bor ENBART i hubs_arende_part).
     */
    public const TYP_PART = 'part';
    /**
     * Handling genererad ur mall (handling-från-mall).
     * detalj: {handling:'skapad', mall, antalErsatta, skyddOverride?} —
     * ALDRIG fältvärden (namn/pnr/adress bor ENBART i dokumentet/partsregistret).
     */
    public const TYP_HANDLING = 'handling';
    /**
     * Bevaknings-livscykelhändelse. detalj.handling ∈ skapad | uppnadd |
     * uppnadd_forsenad | passerad | avbruten:{orsak} | recurring_ny |
     * delgivning_satt. detalj: {handling, typ, villkor, status, bevakningId,
     * lagstadgad} — ALDRIG bevakningens titel-fritext (koordinationsdata utan PII).
     */
    public const TYP_BEVAKNING = 'bevakning';
    /**
     * Grind-beslut i utredningskedjan (A9): ett medvetet, journalfört val vid en
     * grind — legitimt utfall eller override av en hård grind. detalj:
     * {grind, val, skal?, beslutsfattare?, utfall?} där grind ∈ skyddsbedomning |
     * inte_inleda | kommunicering | avslut, val ∈ godkand | override | vald.
     * ALDRIG fritext-motivering/PII — skal är en ENUM-kod, aldrig fri prosa.
     */
    public const TYP_GRINDVAL = 'grindval';
    /**
     * Kvittens av ett lagstadgat moment (A7): handläggaren intygar att momentet
     * (t.ex. skyddsbedömningen) utförts. detalj: {moment, artefaktRef?} —
     * koordinationsdata, aldrig sakinnehåll.
     */
    public const TYP_KVITTENS = 'kvittens';

    /** FK -> hubs_arende_case.hubs_case_id (UUID v4). */
    protected string $hubsCaseId = '';
    /** skapad | steg | tilldelad | medlem | registrerad | rum | kopplad | part | handling */
    protected string $typ = '';
    /** Litet JSON-objekt med koordinationsvärden — aldrig fritext/PII. */
    protected ?string $detalj = null;
    /** Vem som utförde händelsen; '' = system/saga (CLI, jobb). */
    protected string $aktorUid = '';
    /** När händelsen inträffade. */
    protected ?\DateTime $tid = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('hubsCaseId', 'string');
        $this->addType('typ', 'string');
        $this->addType('detalj', 'string');
        $this->addType('aktorUid', 'string');
        $this->addType('tid', 'datetime');
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize(): array {
        $detalj = null;
        if ($this->detalj !== null && $this->detalj !== '') {
            $decoded = json_decode($this->detalj, true);
            $detalj = is_array($decoded) ? $decoded : null;
        }
        return [
            'id' => $this->getId(),
            'typ' => $this->typ,
            'detalj' => $detalj,
            'aktorUid' => $this->aktorUid,
            'tid' => $this->tid?->format('c'),
        ];
    }
}
