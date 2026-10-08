<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Ett AI-UTKAST — en generativ funktions (fn_draft_* / fn_avslutssyntes) råa
 * förslag som VÄNTAR på människans HITL-beslut (SPEC-BRAIN-PER-ARENDE kap 8.0.4/
 * 8.0.7). Utkastet blir ALDRIG en handling utan ett mänskligt godkännande — vid
 * godkännande skapas handlingen i akten via HandlingService, vid avvisning
 * kasseras det som mellanprodukt (TF 2:12-linjen).
 *
 * Maps to table `hubs_arende_ai_utkast` (NC lägger på `oc_`-prefixet).
 *
 * RADERINGSFÖNSTER (granskningsfynd säkerhet 9.8/8.0.4): `innehall` bär rått
 * AI-genererat ärendeinnehåll och lever i NC-databasen. Det NOLLAS (NULL):
 *   - vid AVVISNING omedelbart (mellanprodukt), och
 *   - vid GODKÄNNANDE i samma svep som handlingen skapas i akten (facksystemet är
 *     rättskällan — draftkopian ska inte dubbellagras i NC-DB-backup).
 * Kvar blir innehållsfri provenans (funktion, modellversion, prompt_version,
 * diff_pct, utfall_eko, kallrefs). Raderna gallras MED ärendet (GallringService).
 *
 * TILLSTÅND (status): utkast → godkant | avvisat | avstadd | utgangen.
 *
 * @method int|null getId()
 * @method void setId(int $id)
 * @method string getHubsCaseId()
 * @method void setHubsCaseId(string $hubsCaseId)
 * @method string getRunId()
 * @method void setRunId(string $runId)
 * @method string getFunktion()
 * @method void setFunktion(string $funktion)
 * @method string|null getMallId()
 * @method void setMallId(?string $mallId)
 * @method string|null getInnehall()
 * @method void setInnehall(?string $innehall)
 * @method string|null getKallrefs()
 * @method void setKallrefs(?string $kallrefs)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getDiffText()
 * @method void setDiffText(?string $diffText)
 * @method int|null getDiffPct()
 * @method void setDiffPct(?int $diffPct)
 * @method string|null getUtfallEko()
 * @method void setUtfallEko(?string $utfallEko)
 * @method string|null getModell()
 * @method void setModell(?string $modell)
 * @method string|null getModellversion()
 * @method void setModellversion(?string $modellversion)
 * @method string|null getPromptVersion()
 * @method void setPromptVersion(?string $promptVersion)
 * @method \DateTime|null getSkapad()
 * @method void setSkapad(\DateTime $skapad)
 * @method string|null getAvgjordAv()
 * @method void setAvgjordAv(?string $avgjordAv)
 * @method \DateTime|null getAvgjord()
 * @method void setAvgjord(?\DateTime $avgjord)
 */
class AiUtkast extends Entity implements \JsonSerializable {
    // --- Status (tillståndsmaskinen, monoton från 'utkast') ---
    public const STATUS_UTKAST = 'utkast';
    public const STATUS_GODKANT = 'godkant';
    public const STATUS_AVVISAT = 'avvisat';
    public const STATUS_AVSTADD = 'avstadd';
    public const STATUS_UTGANGEN = 'utgangen';

    /**
     * Funktionen vars generativa utkast dubbelkollas serverside mot människans
     * ställningstagande (utfall_eko-regeln, SPEC 8.8). Får ALDRIG bära beslutsUTFALL.
     */
    public const FN_BESLUTSFORMULERING = 'fn_draft_beslutsformulering';

    /** FK -> hubs_arende_case.hubs_case_id (UUID v4). */
    protected string $hubsCaseId = '';
    /** Korrelation till ork.run_log (orkestrerarens körnings-audit). */
    protected string $runId = '';
    /** fn_draft_* | fn_avslutssyntes. */
    protected string $funktion = '';
    /** Mallen som ett godkännande genererar via HandlingService (null om ren syntes). */
    protected ?string $mallId = null;
    /** Utkast-JSON. NOLLAS vid både godkännande och avvisning (raderingsfönster). */
    protected ?string $innehall = null;
    /** JSON-lista handelse-/tanke-id:n (källrefs). */
    protected ?string $kallrefs = null;
    /** utkast | godkant | avvisat | avstadd | utgangen. */
    protected string $status = self::STATUS_UTKAST;
    /** Unified diff vid redigerat godkännande (provenans, gallras med ärendet). */
    protected ?string $diffText = null;
    /** Andel ändrad text vid redigerat godkännande (0–100). */
    protected ?int $diffPct = null;
    /** fn_draft_beslutsformulering: människans utfall (serverside eko-kontroll 8.8). */
    protected ?string $utfallEko = null;
    /** Modellnamn (aldrig gissad — tas ur litellm-svaret). */
    protected ?string $modell = null;
    protected ?string $modellversion = null;
    protected ?string $promptVersion = null;
    protected ?\DateTime $skapad = null;
    /** uid som fattade HITL-beslutet (null tills avgjort). */
    protected ?string $avgjordAv = null;
    protected ?\DateTime $avgjord = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('hubsCaseId', 'string');
        $this->addType('runId', 'string');
        $this->addType('funktion', 'string');
        $this->addType('mallId', 'string');
        $this->addType('innehall', 'string');
        $this->addType('kallrefs', 'string');
        $this->addType('status', 'string');
        $this->addType('diffText', 'string');
        $this->addType('diffPct', 'integer');
        $this->addType('utfallEko', 'string');
        $this->addType('modell', 'string');
        $this->addType('modellversion', 'string');
        $this->addType('promptVersion', 'string');
        $this->addType('skapad', 'datetime');
        $this->addType('avgjordAv', 'string');
        $this->addType('avgjord', 'datetime');
    }

    /** @return bool true om utkastet fortfarande väntar på HITL-beslut. */
    public function arOavgjort(): bool {
        return $this->status === self::STATUS_UTKAST;
    }

    /**
     * Innehållsfri metadatavy (GET .../ai-utkast — listan): ALDRIG innehall/kallrefs
     * här (kortet är pekare, inte data — hos-agenten-mönstret, SPEC 8.0.7 steg 2).
     *
     * @return array<string,mixed>
     */
    public function toListItem(): array {
        return [
            'id' => $this->getId(),
            'funktion' => $this->funktion,
            'status' => $this->status,
            'mallId' => $this->mallId,
            'diffPct' => $this->diffPct,
            'skapad' => $this->skapad?->format('c'),
        ];
    }

    /**
     * Full vy (GET .../ai-utkast/{id}): innehall + kallrefs avkodas från JSON.
     * Medlems-authz prövas i AiUtkastService::hamta() via ArendeService::show() (H1)
     * INNAN denna serialisering når anroparen.
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'hubsCaseId' => $this->hubsCaseId,
            'runId' => $this->runId,
            'funktion' => $this->funktion,
            'mallId' => $this->mallId,
            'status' => $this->status,
            'innehall' => $this->avkoda($this->innehall),
            'kallrefs' => $this->avkoda($this->kallrefs),
            'diffPct' => $this->diffPct,
            'utfallEko' => $this->utfallEko,
            'modell' => $this->modell,
            'modellversion' => $this->modellversion,
            'promptVersion' => $this->promptVersion,
            'skapad' => $this->skapad?->format('c'),
            'avgjordAv' => $this->avgjordAv,
            'avgjord' => $this->avgjord?->format('c'),
        ];
    }

    /** JSON-sträng → array/scalar, eller null om tomt/ogiltigt. */
    private function avkoda(?string $json): mixed {
        if ($json === null || $json === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        return $decoded === null ? null : $decoded;
    }
}
