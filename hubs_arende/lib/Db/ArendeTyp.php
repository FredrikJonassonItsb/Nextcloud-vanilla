<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Datadriven ärendetyp config-row (table hubs_arende_typ).
 *
 * One row per ärendetyp parameterises the single generic saga: routing, first
 * action, frist-policy, ACL, sekretess, facksystem-modul and the declared
 * pre/post hooks. Adding a new ärendetyp = adding a row, not new PHP.
 *
 * The primary key is the string `arendeTypId` (not an autoincrement int).
 *
 * @method string getArendeTypId()
 * @method void setArendeTypId(string $arendeTypId)
 * @method string getDisplayName()
 * @method void setDisplayName(string $displayName)
 * @method string|null getDefaultEnhet()
 * @method void setDefaultEnhet(?string $defaultEnhet)
 * @method string|null getForstaAtgard()
 * @method void setForstaAtgard(?string $forstaAtgard)
 * @method bool|null getPliktGrind()
 * @method void setPliktGrind(?bool $pliktGrind)
 * @method string|null getKopplingDefault()
 * @method void setKopplingDefault(?string $kopplingDefault)
 * @method string|null getFristPolicy()
 * @method void setFristPolicy(?string $fristPolicy)
 * @method string|null getAclProfil()
 * @method void setAclProfil(?string $aclProfil)
 * @method string|null getSekretessGrund()
 * @method void setSekretessGrund(?string $sekretessGrund)
 * @method bool|null getDiariePlikt()
 * @method void setDiariePlikt(?bool $diariePlikt)
 * @method string|null getDhpHandlingstyp()
 * @method void setDhpHandlingstyp(?string $dhpHandlingstyp)
 * @method string getCommitDestination()
 * @method void setCommitDestination(string $commitDestination)
 * @method string|null getFrendsModul()
 * @method void setFrendsModul(?string $frendsModul)
 * @method string|null getPreSagaHook()
 * @method void setPreSagaHook(?string $preSagaHook)
 * @method string|null getPostCommitHook()
 * @method void setPostCommitHook(?string $postCommitHook)
 * @method string|null getPartsModell()
 * @method void setPartsModell(?string $partsModell)
 * @method string|null getBevakningsmallar()
 * @method void setBevakningsmallar(?string $bevakningsmallar)
 */
class ArendeTyp extends Entity implements \JsonSerializable {
    protected string $arendeTypId = '';
    protected string $displayName = '';
    protected ?string $defaultEnhet = null;
    protected ?string $forstaAtgard = null;
    protected ?bool $pliktGrind = false;
    protected ?string $kopplingDefault = null;
    /** frist-policy serialised as text/json. */
    protected ?string $fristPolicy = null;
    protected ?string $aclProfil = null;
    protected ?string $sekretessGrund = null;
    protected ?bool $diariePlikt = false;
    protected ?string $dhpHandlingstyp = null;
    /** INVARIANT carrier — every typ declares its commit_destination. */
    protected string $commitDestination = '';
    protected ?string $frendsModul = null;
    /** e.g. 'diariefor_direkt' for kat 6 (LVU/LVM). */
    protected ?string $preSagaHook = null;
    /** e.g. 'familjeratt_yttrande' for kat 8. */
    protected ?string $postCommitHook = null;
    /** e.g. 'flerpartsärende' for familjerätt. */
    protected ?string $partsModell = null;
    /**
     * Datadrivna standardbevakningar (JSON-array) — ersätter det oanvända
     * perStegFrist. Varje post: {typ,titel,villkorTyp,villkorArg,ankare,
     * ankareDagar,recurringDagar,lagstadgad,vidSteg}. BevakningService
     * instansierar dem vid födelse (vidSteg='fodelse') och steg-övergång
     * (vidSteg=stegnamn). Null = inga standardbevakningar.
     */
    protected ?string $bevakningsmallar = null;

    public function __construct() {
        // arende_typ_id is the string PK; tell the framework it is the id field.
        $this->addType('arendeTypId', 'string');
        $this->addType('displayName', 'string');
        $this->addType('defaultEnhet', 'string');
        $this->addType('forstaAtgard', 'string');
        $this->addType('pliktGrind', 'boolean');
        $this->addType('kopplingDefault', 'string');
        $this->addType('fristPolicy', 'string');
        $this->addType('aclProfil', 'string');
        $this->addType('sekretessGrund', 'string');
        $this->addType('diariePlikt', 'boolean');
        $this->addType('dhpHandlingstyp', 'string');
        $this->addType('commitDestination', 'string');
        $this->addType('frendsModul', 'string');
        $this->addType('preSagaHook', 'string');
        $this->addType('postCommitHook', 'string');
        $this->addType('partsModell', 'string');
        $this->addType('bevakningsmallar', 'string');
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize(): array {
        return [
            'arendeTypId' => $this->arendeTypId,
            'displayName' => $this->displayName,
            'defaultEnhet' => $this->defaultEnhet,
            'forstaAtgard' => $this->forstaAtgard,
            'pliktGrind' => $this->pliktGrind,
            'kopplingDefault' => $this->kopplingDefault,
            'fristPolicy' => $this->fristPolicy,
            'aclProfil' => $this->aclProfil,
            'sekretessGrund' => $this->sekretessGrund,
            'diariePlikt' => $this->diariePlikt,
            'dhpHandlingstyp' => $this->dhpHandlingstyp,
            'commitDestination' => $this->commitDestination,
            'frendsModul' => $this->frendsModul,
            'preSagaHook' => $this->preSagaHook,
            'postCommitHook' => $this->postCommitHook,
            'partsModell' => $this->partsModell,
            'bevakningsmallar' => $this->bevakningsmallar,
        ];
    }
}
