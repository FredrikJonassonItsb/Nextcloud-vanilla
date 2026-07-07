<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A PART (party) of an ärende — a person (barn, vårdnadshavare, anmälare…)
 * recorded in the case's partsregister.
 *
 * Maps to table `hubs_arende_part` (NC adds the `oc_` prefix).
 *
 * PII-DOKTRIN: this is the engine's ONLY sanctioned PII table (doktrinjustering,
 * beslut 2026-07-06 — see hubs_start/docs/ANALYS-HANDLING-FRAN-MALL.md par 3.4).
 * It is TRANSIENT WORK DATA, never a system of record (SoR):
 *   - primary fill-in source for document generation (handling från mall) and
 *     for {@see \OCA\HubsArende\Service\ArendeMatchService} party matching;
 *   - born at intake (manuell / anmälan / Navet), enriched via Treserva;
 *   - GALLRAS MED ÄRENDET — purged by {@see \OCA\HubsArende\Service\GallringService}
 *     together with the rest of the case;
 *   - personnummer/namn may NEVER reach logs (LoggerInterface) or
 *     Händelse.detalj — log counts/korrelationsId/roll/skydd, never identity.
 *
 * FAIL-CLOSED SKYDD: `skydd` is MANDATORY on every person record coming from
 * the Navet port (ingen | sekretessmarkering | skyddad_folkbokforing); a
 * missing/unknown value must throw, NEVER default to {@see SKYDD_INGEN}.
 * At {@see SKYDD_SKYDDAD_FOLKBOKFORING} `adress` is always null — the real
 * address does not even exist in Navet — and `sarskildPostadress` is the ONLY
 * permitted mail route (K-NAV-5.2).
 *
 * Displaying PII to an AUTHORIZED handläggare is INTENDED — the invariant is
 * the authorization boundary, not PII-hiding (jfr hubs-pii-authorization-principle);
 * hence {@see jsonSerialize()} exposes all fields.
 *
 * Roles (roll): who the person is in relation to the case:
 *   - {@see ROLL_BARN}            the child the case concerns
 *   - {@see ROLL_VARDNADSHAVARE}  a custodian (vårdnadshavare)
 *   - {@see ROLL_ANMALARE}        the reporter (anmälare)
 *   - {@see ROLL_MOTPART}         a counterparty (motpart)
 *   - {@see ROLL_SAMVERKANSPART}  a collaborating party (samverkanspart)
 *   - {@see ROLL_ANNAN}           any other party
 *
 * @method int|null getId()
 * @method void setId(int $id)
 * @method string getHubsCaseId()
 * @method void setHubsCaseId(string $hubsCaseId)
 * @method string getRoll()
 * @method void setRoll(string $roll)
 * @method string getNamn()
 * @method void setNamn(string $namn)
 * @method string|null getPersonnummer()
 * @method void setPersonnummer(?string $personnummer)
 * @method string|null getAdress()
 * @method void setAdress(?string $adress)
 * @method string|null getSarskildPostadress()
 * @method void setSarskildPostadress(?string $sarskildPostadress)
 * @method string|null getKontakt()
 * @method void setKontakt(?string $kontakt)
 * @method string getSkydd()
 * @method void setSkydd(string $skydd)
 * @method string|null getFbfStatus()
 * @method void setFbfStatus(?string $fbfStatus)
 * @method string|null getIdentitetshistorik()
 * @method void setIdentitetshistorik(?string $identitetshistorik)
 * @method string getKalla()
 * @method void setKalla(string $kalla)
 * @method \DateTime|null getVerifierad()
 * @method void setVerifierad(?\DateTime $verifierad)
 * @method \DateTime|null getSkapad()
 * @method void setSkapad(\DateTime $skapad)
 */
class Part extends Entity implements \JsonSerializable {
    /** The child the case concerns. */
    public const ROLL_BARN = 'barn';
    /** A custodian (vårdnadshavare) of the child. */
    public const ROLL_VARDNADSHAVARE = 'vardnadshavare';
    /** The reporter (anmälare) — e.g. the person behind an orosanmälan. */
    public const ROLL_ANMALARE = 'anmalare';
    /** A counterparty (motpart) in the case. */
    public const ROLL_MOTPART = 'motpart';
    /** A collaborating party (samverkanspart) — e.g. skola, BUP. */
    public const ROLL_SAMVERKANSPART = 'samverkanspart';
    /** Any other party that does not fit the roles above. */
    public const ROLL_ANNAN = 'annan';

    /** No protection in the folkbokföring. */
    public const SKYDD_INGEN = 'ingen';
    /** Sekretessmarkering (SkV secrecy flag) — handle with heightened care. */
    public const SKYDD_SEKRETESSMARKERING = 'sekretessmarkering';
    /**
     * Skyddad folkbokföring — the real address may NEVER be stored
     * (`adress` = null; only `sarskildPostadress` may be stored, K-NAV-5.2).
     */
    public const SKYDD_SKYDDAD_FOLKBOKFORING = 'skyddad_folkbokforing';

    /** Entered by hand by a handläggare. */
    public const KALLA_MANUELL = 'manuell';
    /** Extracted from an incoming anmälan (orosanmälan intake). */
    public const KALLA_ANMALAN = 'anmalan';
    /** Fetched/verified from Navet (Skatteverkets folkbokföring). */
    public const KALLA_NAVET = 'navet';
    /** Enriched from Treserva (facksystem). */
    public const KALLA_TRESERVA = 'treserva';

    /** FK -> hubs_arende_case.hubs_case_id (UUID v4). */
    protected string $hubsCaseId = '';
    /** barn | vardnadshavare | anmalare | motpart | samverkanspart | annan */
    protected string $roll = '';
    /** Full name as known (may be empty until verified). */
    protected string $namn = '';
    /** Personnummer, 12 digits AAAAMMDDNNNN — NEVER log, NEVER put in Händelse.detalj. */
    protected ?string $personnummer = null;
    /** Formatted postadress (kontaktadress) — MUST be null at skyddad folkbokföring. */
    protected ?string $adress = null;
    /** Särskild postadress — the only permitted mail route at skyddad folkbokföring. */
    protected ?string $sarskildPostadress = null;
    /** Free-form contact info (phone/e-mail) — manual/anmälan-sourced. */
    protected ?string $kontakt = null;
    /** ingen | sekretessmarkering | skyddad_folkbokforing — mandatory, fail-closed. */
    protected string $skydd = '';
    /** Folkbokföringsstatus: null (aktiv) | avliden | utvandrad. */
    protected ?string $fbfStatus = null;
    /** JSON array of earlier personnummer (tidigare beteckningar from Navet). */
    protected ?string $identitetshistorik = null;
    /** manuell | anmalan | navet | treserva — where the record was born/last sourced. */
    protected string $kalla = '';
    /** When the record was last verified against Navet (null = never). */
    protected ?\DateTime $verifierad = null;
    /** When the party record was created. */
    protected ?\DateTime $skapad = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('hubsCaseId', 'string');
        $this->addType('roll', 'string');
        $this->addType('namn', 'string');
        $this->addType('personnummer', 'string');
        $this->addType('adress', 'string');
        $this->addType('sarskildPostadress', 'string');
        $this->addType('kontakt', 'string');
        $this->addType('skydd', 'string');
        $this->addType('fbfStatus', 'string');
        $this->addType('identitetshistorik', 'string');
        $this->addType('kalla', 'string');
        $this->addType('verifierad', 'datetime');
        $this->addType('skapad', 'datetime');
    }

    /**
     * All roles a part may hold — the validation whitelist for `roll`.
     *
     * @return string[]
     */
    public static function tillatnaRoller(): array {
        return [
            self::ROLL_BARN,
            self::ROLL_VARDNADSHAVARE,
            self::ROLL_ANMALARE,
            self::ROLL_MOTPART,
            self::ROLL_SAMVERKANSPART,
            self::ROLL_ANNAN,
        ];
    }

    /**
     * All valid skydd values — the validation whitelist for `skydd`.
     * A value outside this list must throw (fail-closed), NEVER be
     * defaulted to {@see SKYDD_INGEN}.
     *
     * @return string[]
     */
    public static function tillatnaSkydd(): array {
        return [
            self::SKYDD_INGEN,
            self::SKYDD_SEKRETESSMARKERING,
            self::SKYDD_SKYDDAD_FOLKBOKFORING,
        ];
    }

    /**
     * Full serialization INCLUDING PII — intended for display to the
     * authorized handläggare inside the ärenderum (the invariant is the
     * authorization boundary, not PII-hiding). Must never be routed to
     * logs or Händelse.detalj.
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'hubsCaseId' => $this->hubsCaseId,
            'roll' => $this->roll,
            'namn' => $this->namn,
            'personnummer' => $this->personnummer,
            'adress' => $this->adress,
            'sarskildPostadress' => $this->sarskildPostadress,
            'kontakt' => $this->kontakt,
            'skydd' => $this->skydd,
            'fbfStatus' => $this->fbfStatus,
            'identitetshistorik' => $this->identitetshistorik !== null
                ? json_decode($this->identitetshistorik, true)
                : null,
            'kalla' => $this->kalla,
            'verifierad' => $this->verifierad?->format('c'),
            'skapad' => $this->skapad?->format('c'),
        ];
    }
}
