<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Db;

use OCP\AppFramework\Db\Entity;

/**
 * En BEKRÄFTAD SAKUPPGIFT i ärendet — dokumentkedjans minne.
 *
 * Mappas mot tabellen `hubs_arende_sakuppgift` (NC prefixar `oc_`).
 *
 * När handläggaren skapar en handling ur mall och aktivt bekräftar de
 * förifyllda fälten i förhandsdialogen sparas varje icke-tomt fält här —
 * strukturerat, med källa, ursprungsdokument, vem och när. Nästa mall i
 * dokumentkedjan förifylls då ur ärendets egna bekräftade uppgifter i stället
 * för att handläggaren skriver av samma sak igen
 * (ANALYS-FORIFYLLNAD-FALTKARTLAGGNING.md §4).
 *
 * ANSVARSGRÄNSEN: endast FAKTA/HÄRLEDDA sakuppgifter — aldrig framåtriktade
 * bedömningar. Ett fattat besluts utfall är ett faktum bakåt; bekräftad_av +
 * bekraftad + ursprung är spårbarheten som håller ansvaret hos människan.
 *
 * PII-regler som {@see Part}: värdet kan bära personuppgifter — aldrig i
 * loggar eller Handelse.detalj; gallras OVILLKORLIGEN med ärendet; NEVER-SoR.
 *
 * @method int|null getId()
 * @method void setId(int $id)
 * @method string getHubsCaseId()
 * @method void setHubsCaseId(string $hubsCaseId)
 * @method string getNyckel()
 * @method void setNyckel(string $nyckel)
 * @method string getVarde()
 * @method void setVarde(string $varde)
 * @method string getKalla()
 * @method void setKalla(string $kalla)
 * @method string getUrsprung()
 * @method void setUrsprung(string $ursprung)
 * @method string getBekraftadAv()
 * @method void setBekraftadAv(string $bekraftadAv)
 * @method \DateTime|null getBekraftad()
 * @method void setBekraftad(\DateTime $bekraftad)
 */
class Sakuppgift extends Entity implements \JsonSerializable {
    /** FK -> hubs_arende_case.hubs_case_id (UUID v4). */
    protected string $hubsCaseId = '';
    /** Fältnyckel ur ArendedataService-vokabulären (t.ex. 'barnNamn'). */
    protected string $nyckel = '';
    /** Det bekräftade värdet (kan bära PII — partsregister-reglerna gäller). */
    protected string $varde = '';
    /** Ursprungskälla (register|partsregister|anvandare|journal|handlaggare|akten_tidigare_handling). */
    protected string $kalla = '';
    /** Ursprungsdokument (mall-slug) där bekräftelsen gjordes. */
    protected string $ursprung = '';
    /** Handläggarens uid ('' = system-/sagakontext). */
    protected string $bekraftadAv = '';
    /** När bekräftelsen gjordes. */
    protected ?\DateTime $bekraftad = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('hubsCaseId', 'string');
        $this->addType('nyckel', 'string');
        $this->addType('varde', 'string');
        $this->addType('kalla', 'string');
        $this->addType('ursprung', 'string');
        $this->addType('bekraftadAv', 'string');
        $this->addType('bekraftad', 'datetime');
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'hubsCaseId' => $this->hubsCaseId,
            'nyckel' => $this->nyckel,
            'varde' => $this->varde,
            'kalla' => $this->kalla,
            'ursprung' => $this->ursprung,
            'bekraftadAv' => $this->bekraftadAv,
            'bekraftad' => $this->bekraftad?->format('c'),
        ];
    }
}
