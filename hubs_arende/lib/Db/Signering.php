<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Db;

use OCP\AppFramework\Db\Entity;

/**
 * En SIGNERINGSPOST — persisterat begäran-state för e-underskriftslivscykeln
 * (KRAV-SIGNERING-2026-07, fas 1) i tvånivåmodellen (K-SIGN-1):
 *
 *  - niva='ades': en begäran via {@see \OCA\HubsArende\Integration\Port\SigneringPort}
 *    med hela statusmaskinen pending → partially_signed → signed | rejected |
 *    expired | avbruten och per-part-status i {@see $signersJson} (U4/K-SIGN-9).
 *  - niva='godkann': ett persisterat digitalt godkännande-kvitto (K-SIGN-2) —
 *    ingen portbegäran (signRequestId=null), renderas ALDRIG som underskrift.
 *
 * Maps to table `hubs_arende_signering` (NC adds the `oc_` prefix).
 *
 * KOORDINATIONSDATA, INTE INNEHÅLL (NEVER-SoR): referenser + hash + uid/roll —
 * aldrig dokumentinnehåll, aldrig namn/personnummer. `signMessage` är den
 * NEUTRALISERADE texten (kortref + dokumenttyp + hash-prefix, K-SIGN-4) — den
 * får aldrig bära handläggar-fritext. Raderna gallras MED ärendet
 * ({@see \OCA\HubsArende\Service\GallringService}, K-SIGN-19).
 *
 * @method int|null getId()
 * @method void setId(int $id)
 * @method string getHubsCaseId()
 * @method void setHubsCaseId(string $hubsCaseId)
 * @method string getHandlingRef()
 * @method void setHandlingRef(string $handlingRef)
 * @method string getFilename()
 * @method void setFilename(string $filename)
 * @method string getDokumentHash()
 * @method void setDokumentHash(string $dokumentHash)
 * @method string|null getSignRequestId()
 * @method void setSignRequestId(?string $signRequestId)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string getNiva()
 * @method void setNiva(string $niva)
 * @method string|null getSignersJson()
 * @method void setSignersJson(?string $signersJson)
 * @method string|null getSignMessage()
 * @method void setSignMessage(?string $signMessage)
 * @method string|null getPadesLevel()
 * @method void setPadesLevel(?string $padesLevel)
 * @method string|null getAvvisadSkal()
 * @method void setAvvisadSkal(?string $avvisadSkal)
 * @method string|null getKedjaFran()
 * @method void setKedjaFran(?string $kedjaFran)
 * @method \DateTime|null getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 * @method \DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(\DateTime $updatedAt)
 * @method \DateTime|null getExpiresAt()
 * @method void setExpiresAt(?\DateTime $expiresAt)
 */
class Signering extends Entity {
    // --- Nivåer (tvånivåmodellen, K-SIGN-1) ---
    /** Digitalt godkännande (LOA3-inloggad bekräftelse) — ALDRIG en underskrift. */
    public const NIVA_GODKANN = 'godkann';
    /** Avancerad elektronisk underskrift (AdES/PAdES via SigneringPort). */
    public const NIVA_ADES = 'ades';

    // --- Status (statusmaskinen K-SIGN-5; spegel av portens enum + lokala lägen) ---
    public const STATUS_PENDING = 'pending';
    public const STATUS_PARTIALLY_SIGNED = 'partially_signed';
    public const STATUS_SIGNED = 'signed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';
    /** Lokalt avbruten av handläggaren (extern cancel är fas 2, K-SIGN-19). */
    public const STATUS_AVBRUTEN = 'avbruten';
    /** Slutläge för niva='godkann'-rader (kvitterat godkännande, K-SIGN-2). */
    public const STATUS_GODKAND = 'godkand';

    // --- Per-part-status i signers_json (U4) ---
    public const SIGNER_VANTAR = 'vantar';
    public const SIGNER_SIGNERAD = 'signerad';

    /** FK -> hubs_arende_case.hubs_case_id (UUID v4). */
    protected string $hubsCaseId = '';
    /** Motorns dokumentreferens (fil-id/sökväg) — skickas som `ref` till porten. */
    protected string $handlingRef = '';
    /** Visningsfilnamn inom behörighetsgränsen (aldrig externt, U6). */
    protected string $filename = '';
    /** Kanonisk SHA-256 (hex) av dokumentet vid begäran (U2). */
    protected string $dokumentHash = '';
    /** Portens begäran-id; null för godkann-rader. */
    protected ?string $signRequestId = null;
    /** pending | partially_signed | signed | rejected | expired | avbruten | godkand. */
    protected string $status = '';
    /** godkann | ades. */
    protected string $niva = '';
    /** JSON: [{uid, role, status, tidpunkt|null}]. */
    protected ?string $signersJson = null;
    /** NEUTRALISERAD SignMessage — kortref+typ+hash-prefix, aldrig fritext. */
    protected ?string $signMessage = null;
    /** Uppnådd PAdES-nivå (ETSI-term); null tills signed. */
    protected ?string $padesLevel = null;
    /** Skäl vid rejected/avbruten (bor HÄR — journalen bär aldrig fritexten). */
    protected ?string $avvisadSkal = null;
    /** Föregående signRequestId vid förnyad begäran (kedjan, K-SIGN-7). */
    protected ?string $kedjaFran = null;
    protected ?\DateTime $createdAt = null;
    protected ?\DateTime $updatedAt = null;
    protected ?\DateTime $expiresAt = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('hubsCaseId', 'string');
        $this->addType('handlingRef', 'string');
        $this->addType('filename', 'string');
        $this->addType('dokumentHash', 'string');
        $this->addType('signRequestId', 'string');
        $this->addType('status', 'string');
        $this->addType('niva', 'string');
        $this->addType('signersJson', 'string');
        $this->addType('signMessage', 'string');
        $this->addType('padesLevel', 'string');
        $this->addType('avvisadSkal', 'string');
        $this->addType('kedjaFran', 'string');
        $this->addType('createdAt', 'datetime');
        $this->addType('updatedAt', 'datetime');
        $this->addType('expiresAt', 'datetime');
    }

    /**
     * Är posten i ett AKTIVT (poll-bart) läge? Terminala lägen är idempotenta
     * — en refresh på dem pollar aldrig porten igen (K-SIGN-22).
     */
    public function arAktiv(): bool {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_PARTIALLY_SIGNED], true);
    }

    /**
     * Per-part-listan ur {@see $signersJson} (tom lista vid null/trasig JSON).
     *
     * @return array<int,array<string,mixed>>
     */
    public function signers(): array {
        if ($this->signersJson === null || $this->signersJson === '') {
            return [];
        }
        $decoded = json_decode($this->signersJson, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }
}
