<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Db;

use OCP\AppFramework\Db\Entity;

/**
 * En BEVAKNING — en förstaklassig, självständig watch på ett ärende: "systemet
 * håller ögonen på att X sker (senast T), och släcker sig själv när X sker".
 *
 * Maps to table `hubs_arende_bevakning` (NC lägger på `oc_`-prefixet).
 *
 * Detta ersätter den tidigare modellen (ETT generiskt Deck-kort per ärende +
 * ETT engångs-frist-tal), som inte kunde uttrycka "nollställ när det bevakade
 * uppnås" (se analysis-output/BEVAKNING-LIVSCYKEL-ANALYS-2026-07-07.md och
 * hubs_start/docs/KRAVSTALLNING-BEVAKNINGAR.md). Ett ärende kan ha FLERA aktiva
 * bevakningar med olika villkor; var och en har egen livscykel.
 *
 * KOORDINATIONSDATA UTAN PII (K-BEV-2.1): titel/typ/villkor/datum — ALDRIG namn,
 * personnummer eller sakinnehåll. Vad bevakningen handlar om i sak bor i akten/
 * facksystemet. Raderna gallras med ärendet (K-BEV-2.2).
 *
 * TILLSTÅNDSMASKIN (monoton, K-BEV-1.2): aktiv → uppnadd | passerad | avbruten.
 *  - `passerad` är ett LARMLÄGE, inte ett slutläge (K-BEV-1.3): en missad
 *    lagfrist är brådskande, inte klar; en sen villkorsträff flyttar den till
 *    `uppnadd` med `forsenad=true`.
 *  - recurring: en uppnådd/kvitterad post med `recurringDagar` föder en NY aktiv
 *    post (historiken bevaras — varje övervägande är ett eget ställningstagande).
 *
 * @method int|null getId()
 * @method void setId(int $id)
 * @method string getHubsCaseId()
 * @method void setHubsCaseId(string $hubsCaseId)
 * @method string getTyp()
 * @method void setTyp(string $typ)
 * @method string getTitel()
 * @method void setTitel(string $titel)
 * @method string getVillkorTyp()
 * @method void setVillkorTyp(string $villkorTyp)
 * @method string|null getVillkorArg()
 * @method void setVillkorArg(?string $villkorArg)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method \DateTime|null getFristDue()
 * @method void setFristDue(?\DateTime $fristDue)
 * @method string getAnkare()
 * @method void setAnkare(string $ankare)
 * @method int|null getRecurringDagar()
 * @method void setRecurringDagar(?int $recurringDagar)
 * @method bool getLagstadgad()
 * @method void setLagstadgad(bool $lagstadgad)
 * @method string getSkapadAv()
 * @method void setSkapadAv(string $skapadAv)
 * @method \DateTime|null getUppnaddDatum()
 * @method void setUppnaddDatum(?\DateTime $uppnaddDatum)
 * @method string|null getUppnaddAv()
 * @method void setUppnaddAv(?string $uppnaddAv)
 * @method bool getForsenad()
 * @method void setForsenad(bool $forsenad)
 * @method string|null getDeckCardId()
 * @method void setDeckCardId(?string $deckCardId)
 * @method string|null getDeckBoardId()
 * @method void setDeckBoardId(?string $deckBoardId)
 * @method \DateTime|null getSkapad()
 * @method void setSkapad(\DateTime $skapad)
 */
class Bevakning extends Entity implements \JsonSerializable {
    // --- Status (tillståndsmaskinen) ---
    public const STATUS_AKTIV = 'aktiv';
    public const STATUS_UPPNADD = 'uppnadd';
    public const STATUS_PASSERAD = 'passerad';
    public const STATUS_AVBRUTEN = 'avbruten';

    // --- Villkorstyper (sluten enum, K-BEV-3.2) — vad som SLÄCKER bevakningen ---
    /** Ärendet når ett målsteg. villkorArg = målsteget (t.ex. 'utredning'). */
    public const VILLKOR_STEG_UPPNATT = 'steg_uppnatt';
    /** En komplettering (kat 4) kopplas till ärendet. */
    public const VILLKOR_KOMPLETTERING_KOPPLAD = 'komplettering_kopplad';
    /** Verifierad facksystem-registrering (dnr). */
    public const VILLKOR_COMMIT_REGISTRERAD = 'commit_registrerad';
    /** Signeringskvittens mottagen (när SigneringPort wiras). */
    public const VILLKOR_SIGNERING_KVITTERAD = 'signering_kvitterad';
    /** Ren datumbevakning: fristdagen nås = UPPNÅDD (t.ex. överklagande → laga kraft). */
    public const VILLKOR_DATUM_PASSERAT = 'datum_passerat';
    /** Handläggaren klarmarkerar manuellt. */
    public const VILLKOR_MANUELL_KVITTERING = 'manuell_kvittering';

    // --- Ankare (vad fristen räknades från) ---
    public const ANKARE_INKOM = 'inkom_datum';
    public const ANKARE_STEG = 'steg_datum';
    public const ANKARE_DELGIVNING = 'delgivning_datum';
    public const ANKARE_CYKEL = 'cykel';
    public const ANKARE_MANUELL = 'manuell';

    /** Systemets aktör-markör i skapadAv/uppnaddAv (ej en uid). */
    public const AKTOR_SYSTEM = '';
    public const AKTOR_AGARSKIFTE = 'agarskifte_facksystem';

    /** FK -> hubs_arende_case.hubs_case_id (UUID v4). */
    protected string $hubsCaseId = '';
    /** Mall-/typ-id (t.ex. 'forhandsbedomning_14d', 'manuell'). */
    protected string $typ = '';
    /** Pseudonym rubrik — ALDRIG PII. */
    protected string $titel = '';
    /** Maskinläsbart villkor (VILLKOR_*). */
    protected string $villkorTyp = '';
    /** Villkorets argument (t.ex. målsteg för steg_uppnatt). */
    protected ?string $villkorArg = null;
    /** aktiv | uppnadd | passerad | avbruten. */
    protected string $status = self::STATUS_AKTIV;
    /** Deadline (null = ren villkorsbevakning utan datum). */
    protected ?\DateTime $fristDue = null;
    protected string $ankare = self::ANKARE_MANUELL;
    /** Cykellängd i dagar; ≠null ⇒ uppnådd föder ny post. */
    protected ?int $recurringDagar = null;
    /** Styr eskalerings-/UI-ton (röd rättslig chip vs SLA). */
    protected bool $lagstadgad = false;
    protected string $skapadAv = self::AKTOR_SYSTEM;
    protected ?\DateTime $uppnaddDatum = null;
    protected ?string $uppnaddAv = null;
    /** true = villkoret uppnåddes EFTER att fristen passerat (K-BEV-3.4). */
    protected bool $forsenad = false;
    /** Deck-kortets id (projektion) + board — best-effort, kan vara null. */
    protected ?string $deckCardId = null;
    protected ?string $deckBoardId = null;
    protected ?\DateTime $skapad = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('hubsCaseId', 'string');
        $this->addType('typ', 'string');
        $this->addType('titel', 'string');
        $this->addType('villkorTyp', 'string');
        $this->addType('villkorArg', 'string');
        $this->addType('status', 'string');
        $this->addType('fristDue', 'datetime');
        $this->addType('ankare', 'string');
        $this->addType('recurringDagar', 'integer');
        $this->addType('lagstadgad', 'boolean');
        $this->addType('skapadAv', 'string');
        $this->addType('uppnaddDatum', 'datetime');
        $this->addType('uppnaddAv', 'string');
        $this->addType('forsenad', 'boolean');
        $this->addType('deckCardId', 'string');
        $this->addType('deckBoardId', 'string');
        $this->addType('skapad', 'datetime');
    }

    /** @return string[] Tillåtna statusvärden. */
    public static function tillatnaStatus(): array {
        return [self::STATUS_AKTIV, self::STATUS_UPPNADD, self::STATUS_PASSERAD, self::STATUS_AVBRUTEN];
    }

    /** @return string[] Tillåtna villkorstyper. */
    public static function tillatnaVillkor(): array {
        return [
            self::VILLKOR_STEG_UPPNATT, self::VILLKOR_KOMPLETTERING_KOPPLAD,
            self::VILLKOR_COMMIT_REGISTRERAD, self::VILLKOR_SIGNERING_KVITTERAD,
            self::VILLKOR_DATUM_PASSERAT, self::VILLKOR_MANUELL_KVITTERING,
        ];
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'hubsCaseId' => $this->hubsCaseId,
            'typ' => $this->typ,
            'titel' => $this->titel,
            'villkorTyp' => $this->villkorTyp,
            'villkorArg' => $this->villkorArg,
            'status' => $this->status,
            'fristDue' => $this->fristDue?->format('Y-m-d'),
            'ankare' => $this->ankare,
            'recurringDagar' => $this->recurringDagar,
            'lagstadgad' => $this->lagstadgad,
            'skapadAv' => $this->skapadAv,
            'uppnaddDatum' => $this->uppnaddDatum?->format('c'),
            'uppnaddAv' => $this->uppnaddAv,
            'forsenad' => $this->forsenad,
            'kanKvittera' => $this->status === self::STATUS_AKTIV
                && $this->villkorTyp === self::VILLKOR_MANUELL_KVITTERING,
            'skapad' => $this->skapad?->format('c'),
        ];
    }
}
