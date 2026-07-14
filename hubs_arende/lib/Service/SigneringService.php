<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Service;

use OCA\HubsArende\Db\HandelseMapper;
use OCA\HubsArende\Db\MemberMapper;
use OCA\HubsArende\Db\Signering;
use OCA\HubsArende\Db\SigneringMapper;
use OCA\HubsArende\Integration\Port\Exception\SigningNotReadyException;
use OCA\HubsArende\Integration\Port\Exception\SigningRequestException;
use OCA\HubsArende\Integration\Port\SigneringPort;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * SIGNERINGSLIVSCYKELN (KRAV-SIGNERING-2026-07, fas 1) — den ENDA konsumenten av
 * {@see SigneringPort} (M2: UI:t pratar aldrig direkt med porten, bara med
 * motorns OCS-yta via {@see \OCA\HubsArende\Controller\SigneringController}).
 *
 * TVÅNIVÅMODELLEN (M1/K-SIGN-1): en konfigurerbar dokumenttypsmatris
 * (app-config `signering_niva_matris`, JSON) avgör per handlingstyp om det
 * räcker med "Godkänn" (digitalt godkännande — journalförs, renderas ALDRIG
 * som underskrift, K-SIGN-2) eller krävs "Signera" (AdES via porten, K-SIGN-3).
 * Kod-default: beslut ⇒ ades, övrigt ⇒ godkann.
 *
 * LIVSCYKELN (K-SIGN-5/7/9/22): begäran persisteras i hubs_arende_signering
 * (egen tabell — poll-state överlever motor-omstart), refresh() pollar porten
 * IDEMPOTENT (terminala lägen pollas aldrig om och journalförs aldrig dubbelt),
 * per-part-status hålls i signers_json (U4), rejected/expired får åtgärdbara
 * vägar (fornya/avbryt), och vid signed SLÄCKS bevakningsvillkoret
 * `signering_kvitterad` via {@see BevakningService::utvardera()} (K-SIGN-8).
 *
 * PII-INVARIANT (K-SIGN-15): journal-detalj och loggar bär ENBART
 * koordinationsvärden — signRequestId, hash-PREFIX, dokumenttyp, uid/roll,
 * antal — ALDRIG filnamn i klartext, ALDRIG SignMessage-innehåll, ALDRIG
 * namn/personnummer. SignMessage byggs NEUTRALISERAT (K-SIGN-4: kortref +
 * dokumenttyp + hash-prefix) — handläggar-fritext injiceras aldrig. Extern
 * metadata till porten neutraliseras (U6: kortref-baserat filnamn).
 *
 * JOURNALTYPERNA (TYP_SIGNERING_*) bor HÄR — signeringens typkonstanter är
 * spårets egna, inte ArendeServices (Handelse::typ är en fri sträng).
 *
 * H1-AUTHZ: varje publik metod går genom {@see ArendeService::show()} FÖRST
 * (samma mönster som {@see PartService}) — saknat/obehörigt ärende ger
 * DoesNotExistException (controller → 404, existens läcker inte). Metoder som
 * tar ett signRequestId kör dessutom IDOR-guard (raden måste tillhöra DET
 * ärendet).
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class SigneringService {
    // --- Journaltyper (Handelse::typ, fri sträng — ingen migration krävs) ---
    /** AdES-begäran skickad till porten. detalj: {signRequestId, hashPrefix, dokumenttyp, antalSigners, signers, kedjaFran?}. */
    public const TYP_SIGNERING_BEGARD = 'signering_begard';
    /** Alla parter har signerat; dokumentet hämtat + kvitterat. detalj: {signRequestId, padesLevel, hashPrefix, antalSigners}. */
    public const TYP_SIGNERING_KLAR = 'signering_klar';
    /** Begäran avvisad (av porten eller undertecknare) ELLER lokalt avbruten. detalj: {signRequestId?, handling, skalRef?}. */
    public const TYP_SIGNERING_AVVISAD = 'signering_avvisad';
    /** Begäran gick ut (expiry passerad). detalj: {signRequestId, hashPrefix}. */
    public const TYP_SIGNERING_UTGANGEN = 'signering_utgangen';
    /** Digitalt godkännande (K-SIGN-2) — ALDRIG en underskrift. detalj: {hashPrefix, roll, loa, dokumenttyp}. */
    public const TYP_SIGNERING_GODKAND = 'signering_godkand';
    /** Manuell påminnelse journalförd (v1 — ingen Talk-utskickning ännu). detalj: {signRequestId}. */
    public const TYP_SIGNERING_PAMINNELSE = 'signering_paminnelse';

    /** App-config-nyckel: nivåmatrisen (JSON: {handlingstyp: "godkann"|"ades"}). */
    public const CONFIG_NIVA_MATRIS = 'signering_niva_matris';
    /**
     * App-config-nyckel: sessionens LoA-etikett för godkännande-journalen
     * (K-SIGN-2). Motorn saknar i fas 1 en riktig LoA-källa (GrandID-claimen är
     * inte trådad in i NC-sessionen) — värdet seedas per miljö (dev15: 'LOA3').
     * TODO[signering-fas2]: läs verklig LoA ur inloggningssessionen i stället.
     */
    public const CONFIG_SESSION_LOA = 'signering_session_loa';

    /** Wildcard-nyckeln i nivåmatrisen ("alla övriga handlingstyper"). */
    private const MATRIS_OVRIGT = '*';

    public function __construct(
        private readonly ArendeService $arendeService,
        private readonly SigneringMapper $signeringMapper,
        private readonly SigneringPort $signeringPort,
        private readonly LoggerInterface $logger,
        private readonly ITimeFactory $timeFactory,
        private readonly IAppConfig $appConfig,
        private readonly DokumenttypRegistry $dokumenttypRegistry,
        // TRAILING OPTIONAL (autowired): händelsejournalen. Best-effort — ett
        // journal-fel får ALDRIG fälla den mutation det beskriver (mönstret
        // från PartService). Null enbart i en positionell testharness.
        private readonly ?HandelseMapper $handelseMapper = null,
        // TRAILING OPTIONAL (autowired): aktören bakom mutationen ('' = system).
        private readonly ?IUserSession $userSession = null,
        // TRAILING OPTIONAL (autowired): bevakningsmotorn — signed släcker
        // villkoret signering_kvitterad (K-SIGN-8). Null ⇒ graceful skip.
        private readonly ?BevakningService $bevakningService = null,
        // TRAILING OPTIONAL (autowired): medlemsregistret — aktörens ROLL i
        // ärendet till godkännande-journalen (K-SIGN-2). Null ⇒ roll 'okand'.
        private readonly ?MemberMapper $memberMapper = null,
        // TRAILING OPTIONAL (autowired): filsystemet — den kanoniska SHA-256:an
        // beräknas SERVER-SIDE ur handlingRef (fileid) när klienten inte skickar
        // någon hash (U2; klient-hash är ändå otrodd). Null ⇒ hash krävs i indata.
        private readonly ?IRootFolder $rootFolder = null,
    ) {
    }

    // ================================================================== //
    //  NIVÅMODELLEN (K-SIGN-1)
    // ================================================================== //

    /**
     * Den EFFEKTIVA nivåmatrisen: kod-default (beslut ⇒ ades, '*' ⇒ godkann)
     * överlagrad med app-config `signering_niva_matris` (JSON). Ogiltiga
     * config-poster (okänd nivå) ignoreras — hellre kod-defaulten än en tyst
     * nedgradering av ett beslut till "godkänn".
     *
     * @return array<string,string> handlingstyp ⇒ 'godkann'|'ades' (inkl. '*').
     */
    public function nivaMatris(): array {
        // Kod-default (K-SIGN-1): beslut som expedieras/tvångsvårds-beslut kräver
        // AdES; övriga delegationsbeslut räcker med journalfört godkännande.
        $matris = [
            'beslut' => Signering::NIVA_ADES,
            self::MATRIS_OVRIGT => Signering::NIVA_GODKANN,
        ];

        $raw = $this->appConfig->getAppValueString(self::CONFIG_NIVA_MATRIS, '');
        if ($raw === '') {
            return $matris;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->logger->warning('hubs_arende: signering_niva_matris är inte giltig JSON — kod-default används', [
                'app' => 'hubs_arende',
            ]);
            return $matris;
        }
        foreach ($decoded as $typ => $niva) {
            if (!is_string($typ) || $typ === '' || !is_string($niva)) {
                continue;
            }
            if (!in_array($niva, [Signering::NIVA_GODKANN, Signering::NIVA_ADES], true)) {
                continue; // okänd nivå ⇒ ignorera posten (fail mot kod-default)
            }
            $matris[$typ] = $niva;
        }
        return $matris;
    }

    /** Kravnivån för en handlingstyp ('godkann'|'ades') ur den effektiva matrisen. */
    public function nivaFor(string $handlingstyp): string {
        $matris = $this->nivaMatris();
        return $matris[$handlingstyp] ?? $matris[self::MATRIS_OVRIGT] ?? Signering::NIVA_GODKANN;
    }

    // ================================================================== //
    //  LÄSNING (K-SIGN-5)
    // ================================================================== //

    /**
     * Signeringsöversikten för GET …/arende/{ref}/signering: den effektiva
     * nivåmatrisen + ärendets AdES-poster som SigneringDTO:er (nyast först).
     * Godkännande-kvitton (niva='godkann') persisteras men exponeras INTE i
     * `poster` — DTO-kontraktet är niva='ades'; godkännanden läses ur journalen.
     *
     * @param string $ref hubsCaseId eller dnr.
     *
     * @return array{niva_matris: array<string,string>, poster: array<int,array<string,mixed>>}
     *
     * @throws DoesNotExistException Vid saknat/obehörigt ärende.
     */
    public function listForCase(string $ref): array {
        $arende = $this->arendeService->show($ref);

        $poster = [];
        foreach ($this->signeringMapper->findByCaseId($arende->getHubsCaseId()) as $post) {
            if ($post->getNiva() !== Signering::NIVA_ADES) {
                continue;
            }
            $poster[] = $this->dto($post);
        }

        return [
            'niva_matris' => $this->nivaMatris(),
            'poster' => $poster,
        ];
    }

    // ================================================================== //
    //  GODKÄNN-NIVÅN (K-SIGN-2)
    // ================================================================== //

    /**
     * Digitalt godkännande: journalför {aktör-uid, roll, tidpunkt, dokument-
     * hash, sessionens LoA} och persistera kvittot. Renderas ALDRIG som
     * underskrift/signatur — UI-texten är "Godkänt av <roll>" (ärlig
     * etikettering, K-SIGN-2/K-7.15).
     *
     * @param string $ref hubsCaseId eller dnr.
     * @param string $handlingRef Motorns dokumentreferens.
     * @param string $filename Visningsfilnamn (bara persisterat — journalförs ej).
     * @param string $dokumentHash Kanonisk SHA-256 (hex, 64 tecken).
     *
     * @return array{journalfort: bool, niva: string, tidpunkt: string}
     *
     * @throws DoesNotExistException Vid saknat/obehörigt ärende.
     * @throws \InvalidArgumentException Vid ogiltig indata.
     */
    public function godkann(string $ref, string $handlingRef, string $filename, string $dokumentHash): array {
        $arende = $this->arendeService->show($ref);
        $this->kravDokument($handlingRef, $filename);
        $dokumentHash = $this->resolveraDokumentHash($handlingRef, $dokumentHash);

        $nu = $this->timeFactory->getDateTime();
        $aktor = $this->aktor();
        $roll = $this->aktorRoll($arende->getHubsCaseId(), $aktor);
        $dokumenttyp = $this->dokumenttyp($filename);

        $post = new Signering();
        $post->setHubsCaseId($arende->getHubsCaseId());
        $post->setHandlingRef($handlingRef);
        $post->setFilename($filename);
        $post->setDokumentHash(strtolower($dokumentHash));
        $post->setSignRequestId(null);
        $post->setStatus(Signering::STATUS_GODKAND);
        $post->setNiva(Signering::NIVA_GODKANN);
        $post->setSignersJson(json_encode([[
            'uid' => $aktor,
            'role' => $roll,
            'status' => Signering::SIGNER_SIGNERAD,
            'tidpunkt' => $nu->format('c'),
        ]]));
        $post->setCreatedAt($nu);
        $post->setUpdatedAt($nu);
        $this->signeringMapper->insert($post);

        // K-SIGN-2: aktör (journalradens aktorUid) + roll + tidpunkt + hash +
        // sessionens LoA. ALDRIG filnamn/fritext i detalj.
        $this->loggaHandelse($arende->getHubsCaseId(), self::TYP_SIGNERING_GODKAND, [
            'niva' => Signering::NIVA_GODKANN,
            'hashPrefix' => $this->hashPrefix($dokumentHash),
            'roll' => $roll,
            'loa' => $this->sessionLoa(),
            'dokumenttyp' => $dokumenttyp,
        ]);

        return [
            'journalfort' => true,
            'niva' => Signering::NIVA_GODKANN,
            'tidpunkt' => $nu->format('c'),
        ];
    }

    // ================================================================== //
    //  SIGNERA-NIVÅN — BEGÄRAN (K-SIGN-3/4)
    // ================================================================== //

    /**
     * Begär en AdES-underskrift via porten: NEUTRALISERAD SignMessage byggs
     * (K-SIGN-4: kortref + dokumenttyp + hash-prefix — ALDRIG handläggar-
     * fritext), extern metadata neutraliseras (U6), begäran persisteras och
     * journalförs (K-SIGN-3). I instant-läge (stub-demo) fullföljs posten
     * direkt till signed via samma väg som refresh().
     *
     * @param string $ref hubsCaseId eller dnr.
     * @param string $handlingRef Motorns dokumentreferens.
     * @param string $filename Visningsfilnamn (skickas ALDRIG externt).
     * @param string $dokumentHash Kanonisk SHA-256 (hex, 64 tecken).
     * @param array<int,array<string,mixed>> $signers [{uid, role}] — loa tvingas LOA3.
     *
     * @return array<string,mixed> SigneringDTO (status pending, eller signed vid instant).
     *
     * @throws DoesNotExistException Vid saknat/obehörigt ärende.
     * @throws \InvalidArgumentException Vid ogiltig indata.
     * @throws SigningRequestException Vid avvisad begäran (journalförd innan rethrow).
     */
    public function begar(string $ref, string $handlingRef, string $filename, string $dokumentHash, array $signers): array {
        $arende = $this->arendeService->show($ref);
        $this->kravDokument($handlingRef, $filename);
        $dokumentHash = $this->resolveraDokumentHash($handlingRef, $dokumentHash);
        $portSigners = $this->kravSigners($signers);

        return $this->skapaBegaran(
            $arende->getHubsCaseId(),
            $handlingRef,
            $filename,
            $dokumentHash,
            $portSigners,
            null,
        );
    }

    // ================================================================== //
    //  STATUSLIVSCYKELN — REFRESH (K-SIGN-5/8/9/22)
    // ================================================================== //

    /**
     * Polla porten och uppdatera det persisterade statet. IDEMPOTENT
     * (K-SIGN-22): en post i terminalt läge (signed/rejected/expired/avbruten)
     * pollas ALDRIG om och journalförs ALDRIG dubbelt — DTO:n returneras som
     * den är. För aktiva poster mappas portens status:
     *
     *  - partially_signed ⇒ per-part-markering i ordning (U4/K-SIGN-9);
     *  - signed ⇒ alla parter signerade + padesLevel + hämtning/kvittens av
     *    dokumentet + journal TYP_SIGNERING_KLAR + bevakningsvillkoret
     *    `signering_kvitterad` släcks (K-SIGN-8);
     *  - rejected/expired ⇒ journalförd terminal (K-SIGN-7);
     *  - pending efter expiresAt ⇒ lokalt expired (porten hann aldrig).
     *
     * @param string $ref hubsCaseId eller dnr.
     * @param string $signRequestId Portens begäran-id.
     *
     * @return array<string,mixed> SigneringDTO.
     *
     * @throws DoesNotExistException Vid saknat/obehörigt ärende eller främmande rad.
     * @throws \RuntimeException Vid portfel som inte kan mappas (fail-safe, aldrig tyst).
     */
    public function refresh(string $ref, string $signRequestId): array {
        $arende = $this->arendeService->show($ref);
        $post = $this->kravPostICase($arende->getHubsCaseId(), $signRequestId);

        // IDEMPOTENS (K-SIGN-22): terminala lägen är slutgiltiga — ingen
        // om-poll, ingen dubbel-journal.
        if (!$post->arAktiv()) {
            return $this->dto($post);
        }

        try {
            $status = $this->signeringPort->pollStatus($signRequestId);
        } catch (SigningNotReadyException $e) {
            // Porten känner inte igen begäran (t.ex. adapterbortfall) — fail-safe
            // till läsbart fel, aldrig tyst (testkrav §8). PII-fritt (endast id).
            $this->logger->error('hubs_arende: signering pollStatus okänd begäran', [
                'app' => 'hubs_arende',
                'signRequestId' => $signRequestId,
                'exception' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Signeringsstatus kunde inte hämtas för begäran.');
        }

        $this->tillampaPollStatus($post, $status);
        return $this->dto($post);
    }

    // ================================================================== //
    //  ÅTGÄRDBARA VÄGAR — FÖRNYA / AVBRYT / PÅMINN (K-SIGN-7)
    // ================================================================== //

    /**
     * Förnya en avvisad/utgången/avbruten begäran: en NY begäran (nytt
     * signRequestId) skapas hos porten med samma dokument + signers, med
     * journalförd kedja till den gamla (kedja_fran, K-SIGN-7).
     *
     * @return array<string,mixed> SigneringDTO för den NYA begäran.
     *
     * @throws DoesNotExistException Vid saknat/obehörigt ärende eller främmande rad.
     * @throws \InvalidArgumentException Om posten är aktiv (avbryt först) eller redan signed.
     * @throws SigningRequestException Vid avvisad ny begäran.
     */
    public function fornya(string $ref, string $signRequestId): array {
        $arende = $this->arendeService->show($ref);
        $gammal = $this->kravPostICase($arende->getHubsCaseId(), $signRequestId);

        if ($gammal->getStatus() === Signering::STATUS_SIGNED) {
            throw new \InvalidArgumentException('Begäran är redan signerad — inget att förnya.');
        }
        if ($gammal->arAktiv()) {
            throw new \InvalidArgumentException('Begäran är fortfarande aktiv — avbryt den innan du förnyar.');
        }

        // Samma dokument + samma parter (per-part-status nollas i den nya posten).
        $signers = array_map(
            static fn (array $s): array => ['uid' => (string)($s['uid'] ?? ''), 'role' => (string)($s['role'] ?? ''), 'loa' => 'LOA3'],
            $gammal->signers(),
        );

        return $this->skapaBegaran(
            $arende->getHubsCaseId(),
            $gammal->getHandlingRef(),
            $gammal->getFilename(),
            $gammal->getDokumentHash(),
            $signers,
            $signRequestId,
        );
    }

    /**
     * Avbryt en begäran LOKALT (status 'avbruten') med journalfört skäl.
     * Idempotent på redan-avbruten. Skälet (fritext) bor i KOLUMNEN
     * avvisad_skal — journalen bär endast en icke-reversibel skalRef
     * (PII-invariant K-SIGN-15).
     *
     * TODO[signering-fas2]: extern cancel hos adaptern (K-SIGN-19) — i fas 1
     * avbryts endast motorns state; stubben har inga dinglande uppdrag.
     *
     * @throws DoesNotExistException Vid saknat/obehörigt ärende eller främmande rad.
     * @throws \InvalidArgumentException Vid tomt skäl eller redan signerad/godkänd post.
     */
    public function avbryt(string $ref, string $signRequestId, string $skal): array {
        $arende = $this->arendeService->show($ref);
        $post = $this->kravPostICase($arende->getHubsCaseId(), $signRequestId);

        if ($post->getStatus() === Signering::STATUS_AVBRUTEN) {
            return $this->dto($post); // idempotent
        }
        if ($post->getStatus() === Signering::STATUS_SIGNED) {
            throw new \InvalidArgumentException('En signerad begäran kan inte avbrytas.');
        }
        $skal = trim($skal);
        if ($skal === '') {
            throw new \InvalidArgumentException('Skäl är obligatoriskt för att avbryta en signeringsbegäran.');
        }

        $post->setStatus(Signering::STATUS_AVBRUTEN);
        $post->setAvvisadSkal(mb_substr($skal, 0, 255));
        $post->setUpdatedAt($this->timeFactory->getDateTime());
        $this->signeringMapper->update($post);

        $this->loggaHandelse($post->getHubsCaseId(), self::TYP_SIGNERING_AVVISAD, [
            'handling' => 'avbruten',
            'signRequestId' => $signRequestId,
            // Fritext-skälet journalförs ALDRIG — endast en icke-reversibel referens.
            'skalRef' => $this->safeRef($skal),
        ]);

        return $this->dto($post);
    }

    /**
     * Manuell påminnelse (K-SIGN-7). v1: journalförs endast — Talk-/notifierings-
     * utskicket är fas 2/3. TODO[signering-fas2]: skicka påminnelsen via Talk.
     *
     * @return array{paminnelse: bool}
     *
     * @throws DoesNotExistException Vid saknat/obehörigt ärende eller främmande rad.
     * @throws \InvalidArgumentException Om begäran inte är aktiv.
     */
    public function paminn(string $ref, string $signRequestId): array {
        $arende = $this->arendeService->show($ref);
        $post = $this->kravPostICase($arende->getHubsCaseId(), $signRequestId);

        if (!$post->arAktiv()) {
            throw new \InvalidArgumentException('Endast en aktiv begäran kan påminnas.');
        }

        $this->loggaHandelse($post->getHubsCaseId(), self::TYP_SIGNERING_PAMINNELSE, [
            'signRequestId' => $signRequestId,
            'antalVantande' => count(array_filter(
                $post->signers(),
                static fn (array $s): bool => ($s['status'] ?? '') !== Signering::SIGNER_SIGNERAD,
            )),
        ]);

        return ['paminnelse' => true];
    }

    // ================================================================== //
    //  GALLRING (K-SIGN-19, lokal del)
    // ================================================================== //

    /**
     * Riv ärendets signeringsspår (destruktionsspegelns lokala del, K-SIGN-19).
     * Idempotent. Anropas av {@see GallringService} — INGEN authz (systemsvep).
     *
     * TODO[signering-fas2]: extern cancel av ÖPPNA begäranden hos adaptern
     * innan raderna rivs (annars dinglande uppdrag hos extern part).
     */
    public function deleteForCase(string $hubsCaseId): int {
        return $this->signeringMapper->deleteByCaseId($hubsCaseId);
    }

    // ================================================================== //
    //  PRIVATA HJÄLPARE
    // ================================================================== //

    /**
     * Gemensam begäran-väg för begar() och fornya(): neutraliserad SignMessage
     * + neutraliserad extern metadata (U6) → porten → persist → journal →
     * ev. instant-fullföljning.
     *
     * @param array<int,array<string,mixed>> $portSigners [{uid, role, loa}].
     *
     * @return array<string,mixed> SigneringDTO.
     */
    private function skapaBegaran(
        string $hubsCaseId,
        string $handlingRef,
        string $filename,
        string $dokumentHash,
        array $portSigners,
        ?string $kedjaFran,
    ): array {
        $dokumenttyp = $this->dokumenttyp($filename);
        $kortRef = ArendeService::kortRef($hubsCaseId);
        $hashPrefix = $this->hashPrefix($dokumentHash);

        // K-SIGN-4: NEUTRALISERAD SignMessage — kortref + dokumenttyp + hash-
        // prefix. Ingen handläggar-fritext, inget röjande filnamn (OSL 26:1).
        $signMessage = mb_substr(
            'Ärende ' . $kortRef . ' — ' . $dokumenttyp . ' — dokument ' . $hashPrefix,
            0,
            255,
        );

        try {
            $kvitto = $this->signeringPort->requestSignature($hubsCaseId, [
                'ref' => $handlingRef,
                // U6/K-SIGN-15: extern metadata neutraliseras — aldrig det
                // verkliga (potentiellt röjande) filnamnet till porten.
                'filename' => $kortRef . '-' . $dokumenttyp . '.pdf',
                'mimeType' => 'application/pdf',
                'hash' => $dokumentHash,
                'handlingstyp' => $dokumenttyp,
                // U1: SignMessage per dokumenttyp (bakåtkompatibelt extrafält —
                // stubben ignorerar det, live-adaptern kräver det).
                'signMessage' => $signMessage,
            ], $portSigners);
        } catch (SigningRequestException $e) {
            // Avvisad redan vid begäran — journalför (K-SIGN-7-spårbarhet)
            // och släpp vidare till anroparen (fail-safe, aldrig tyst).
            $this->loggaHandelse($hubsCaseId, self::TYP_SIGNERING_AVVISAD, [
                'handling' => 'begaran_avvisad',
                'hashPrefix' => $hashPrefix,
                'dokumenttyp' => $dokumenttyp,
            ]);
            throw $e;
        }

        $nu = $this->timeFactory->getDateTime();
        $signRequestId = (string)($kvitto['signRequestId'] ?? '');
        $status = (string)($kvitto['status'] ?? Signering::STATUS_PENDING);

        $post = new Signering();
        $post->setHubsCaseId($hubsCaseId);
        $post->setHandlingRef($handlingRef);
        $post->setFilename($filename);
        $post->setDokumentHash($dokumentHash);
        $post->setSignRequestId($signRequestId);
        $post->setStatus(Signering::STATUS_PENDING);
        $post->setNiva(Signering::NIVA_ADES);
        $post->setSignersJson(json_encode(array_map(
            static fn (array $s): array => [
                'uid' => (string)($s['uid'] ?? ''),
                'role' => (string)($s['role'] ?? ''),
                'status' => Signering::SIGNER_VANTAR,
                'tidpunkt' => null,
            ],
            $portSigners,
        )));
        $post->setSignMessage($signMessage);
        $post->setKedjaFran($kedjaFran);
        $post->setCreatedAt($this->parseIso((string)($kvitto['createdAt'] ?? '')) ?? $nu);
        $post->setUpdatedAt($nu);
        $post->setExpiresAt($this->parseIso((string)($kvitto['expiresAt'] ?? '')));
        $post = $this->signeringMapper->insert($post);

        // K-SIGN-3: journalförd med signRequestId + dokumenthash(-prefix),
        // PII-fritt — uid/roll-referenser, aldrig namn/pnr, aldrig filnamn.
        $detalj = [
            'signRequestId' => $signRequestId,
            'hashPrefix' => $hashPrefix,
            'dokumenttyp' => $dokumenttyp,
            'antalSigners' => count($portSigners),
            'signers' => array_map(
                static fn (array $s): array => ['uid' => (string)($s['uid'] ?? ''), 'roll' => (string)($s['role'] ?? '')],
                $portSigners,
            ),
        ];
        if ($kedjaFran !== null) {
            $detalj['kedjaFran'] = $kedjaFran; // K-SIGN-7: journalförd kedja
        }
        $this->loggaHandelse($hubsCaseId, self::TYP_SIGNERING_BEGARD, $detalj);

        // Instant-läge (stub-demo/K-SIGN-21): porten svarar 'signed' direkt —
        // fullfölj via samma idempotenta väg som refresh() (poll → signed).
        if ($status === Signering::STATUS_SIGNED) {
            try {
                $this->tillampaPollStatus($post, $this->signeringPort->pollStatus($signRequestId));
            } catch (\Throwable $e) {
                // Fullföljningen är best-effort här — nästa refresh tar den.
                $this->logger->warning('hubs_arende: instant-fullföljning av signering misslyckades (tas av nästa refresh)', [
                    'app' => 'hubs_arende',
                    'signRequestId' => $signRequestId,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return $this->dto($post);
    }

    /**
     * Mappa ETT pollStatus-svar in i den persisterade posten (endast för en
     * AKTIV post — anroparen gatar). Journalför ENBART vid faktisk transition
     * till terminal (idempotens, K-SIGN-22).
     *
     * @param array<string,mixed> $status Portens pollStatus-svar.
     */
    private function tillampaPollStatus(Signering $post, array $status): void {
        $nu = $this->timeFactory->getDateTime();
        $portStatus = (string)($status['status'] ?? '');
        $signedBy = is_array($status['signedBy'] ?? null) ? $status['signedBy'] : [];

        // LOKAL EXPIRY-VAKT (K-SIGN-7): porten är inte klar och tiden är ute ⇒
        // utgången. Stubben har ingen egen expiry-motor; en live-adapter som
        // rapporterar 'expired' själv tar terminal-grenen nedan i stället.
        if (in_array($portStatus, [Signering::STATUS_PENDING, Signering::STATUS_PARTIALLY_SIGNED], true)) {
            $expiresAt = $post->getExpiresAt();
            if ($expiresAt !== null && $expiresAt < $nu) {
                $this->markeraUtgangen($post, $nu);
                return;
            }
        }

        switch ($portStatus) {
            case Signering::STATUS_SIGNED:
                $this->markeraSignerade($post, $signedBy, true, $nu);
                $post->setPadesLevel($this->strEllerNull($status['padesLevel'] ?? null));
                $post->setStatus(Signering::STATUS_SIGNED);
                $post->setUpdatedAt($nu);
                $this->kvitteraSigneratDokument($post);
                $this->signeringMapper->update($post);
                $this->loggaHandelse($post->getHubsCaseId(), self::TYP_SIGNERING_KLAR, [
                    'signRequestId' => (string)$post->getSignRequestId(),
                    'padesLevel' => $post->getPadesLevel(),
                    'hashPrefix' => $this->hashPrefix($post->getDokumentHash()),
                    'antalSigners' => count($post->signers()),
                ]);
                // K-SIGN-8: släck bevakningsvillkoret signering_kvitterad via
                // den befintliga villkorsmotorn (händelsetyp 'signering').
                $this->bevakningService?->utvardera(
                    $post->getHubsCaseId(),
                    'signering',
                    ['signRequestId' => (string)$post->getSignRequestId()],
                    $this->aktor(),
                );
                break;

            case Signering::STATUS_PARTIALLY_SIGNED:
                $this->markeraSignerade($post, $signedBy, false, $nu);
                $post->setStatus(Signering::STATUS_PARTIALLY_SIGNED);
                $post->setUpdatedAt($nu);
                $this->signeringMapper->update($post);
                break;

            case Signering::STATUS_REJECTED:
                $post->setStatus(Signering::STATUS_REJECTED);
                $post->setAvvisadSkal($this->strEllerNull($status['skal'] ?? $status['reason'] ?? null, 255));
                $post->setUpdatedAt($nu);
                $this->signeringMapper->update($post);
                $this->loggaHandelse($post->getHubsCaseId(), self::TYP_SIGNERING_AVVISAD, [
                    'handling' => 'avvisad',
                    'signRequestId' => (string)$post->getSignRequestId(),
                    'hashPrefix' => $this->hashPrefix($post->getDokumentHash()),
                ]);
                break;

            case Signering::STATUS_EXPIRED:
                $this->markeraUtgangen($post, $nu);
                break;

            case Signering::STATUS_PENDING:
            default:
                // Fortfarande väntande hos porten (expiry redan vaktad ovan).
                $post->setUpdatedAt($nu);
                $this->signeringMapper->update($post);
                break;
        }
    }

    /** Terminal 'expired' + journal (K-SIGN-7). */
    private function markeraUtgangen(Signering $post, \DateTime $nu): void {
        $post->setStatus(Signering::STATUS_EXPIRED);
        $post->setUpdatedAt($nu);
        $this->signeringMapper->update($post);
        $this->loggaHandelse($post->getHubsCaseId(), self::TYP_SIGNERING_UTGANGEN, [
            'signRequestId' => (string)$post->getSignRequestId(),
            'hashPrefix' => $this->hashPrefix($post->getDokumentHash()),
        ]);
    }

    /**
     * Per-part-markering (U4/K-SIGN-9). Portens `signedBy` godtas i två former:
     * uid-strängar (dagens stub) eller per-part-objekt {uid, status, tidpunkt}
     * (U4-utökade adapters). Vid partially_signed UTAN portdata markeras
     * signers I ORDNING (sekventiellt flöde: föredragande → beslutsfattare) —
     * en per poll, alltid minst en kvar som väntar.
     *
     * @param array<int,mixed> $signedBy
     */
    private function markeraSignerade(Signering $post, array $signedBy, bool $alla, \DateTime $nu): void {
        $signers = $post->signers();
        if ($signers === []) {
            return;
        }

        if ($alla) {
            foreach ($signers as $i => $s) {
                if (($s['status'] ?? '') !== Signering::SIGNER_SIGNERAD) {
                    $signers[$i]['status'] = Signering::SIGNER_SIGNERAD;
                    $signers[$i]['tidpunkt'] = $nu->format('c');
                }
            }
            $post->setSignersJson(json_encode(array_values($signers)));
            return;
        }

        // Normalisera portens signedBy till en uid-lista (U4-objekt eller strängar).
        $signeradeUids = [];
        foreach ($signedBy as $entry) {
            if (is_string($entry) && $entry !== '') {
                $signeradeUids[] = $entry;
            } elseif (is_array($entry) && is_string($entry['uid'] ?? null)) {
                if (($entry['status'] ?? Signering::SIGNER_SIGNERAD) !== Signering::SIGNER_VANTAR) {
                    $signeradeUids[] = (string)$entry['uid'];
                }
            }
        }

        if ($signeradeUids !== []) {
            foreach ($signers as $i => $s) {
                if (in_array((string)($s['uid'] ?? ''), $signeradeUids, true)
                    && ($s['status'] ?? '') !== Signering::SIGNER_SIGNERAD) {
                    $signers[$i]['status'] = Signering::SIGNER_SIGNERAD;
                    $signers[$i]['tidpunkt'] = $nu->format('c');
                }
            }
        } else {
            // Ingen per-part-data från porten: avancera I ORDNING — nästa
            // väntande signer markeras, men den SISTA lämnas alltid väntande
            // (annars vore posten 'signed', inte 'partially_signed').
            $antal = count($signers);
            for ($i = 0; $i < $antal - 1; $i++) {
                if (($signers[$i]['status'] ?? '') !== Signering::SIGNER_SIGNERAD) {
                    $signers[$i]['status'] = Signering::SIGNER_SIGNERAD;
                    $signers[$i]['tidpunkt'] = $nu->format('c');
                    break;
                }
            }
        }
        $post->setSignersJson(json_encode(array_values($signers)));
    }

    /**
     * Hämta + kvittera det signerade dokumentet (K-SIGN-5-kedjans slutsteg).
     * Verifierar BEGÄRAN-KOPPLINGEN: portens dokument måste svara mot samma
     * signRequestId och bära adapterns verifierad-flagga; journalens KLAR-rad
     * binder dokument_hash-prefixet till kedjan.
     *
     * TODO[signering-fas2]: kryptografisk validering av att PAdES-signaturen
     * omsluter originalets SHA-256 (EU DSS/valideringsintyg, K-SIGN-11/12) —
     * stubbens syntetiska PDF kan inte valideras kryptografiskt.
     *
     * @throws \RuntimeException Vid hämtnings-/verifikationsfel (fail-safe, aldrig tyst).
     */
    private function kvitteraSigneratDokument(Signering $post): void {
        $signRequestId = (string)$post->getSignRequestId();
        try {
            $dokument = $this->signeringPort->fetchSignedDocument($signRequestId);
        } catch (\Throwable $e) {
            $this->logger->error('hubs_arende: signerat dokument kunde inte hämtas', [
                'app' => 'hubs_arende',
                'signRequestId' => $signRequestId,
                'exception' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Det signerade dokumentet kunde inte hämtas från underskriftstjänsten.');
        }

        $verifierad = ($dokument['verified'] ?? false) === true
            && (string)($dokument['signRequestId'] ?? '') === $signRequestId
            && (string)($dokument['content'] ?? '') !== '';
        if (!$verifierad) {
            // Hash-/kopplingsmismatch = kontraktsbrott — läsbart fel, aldrig tyst.
            $this->logger->error('hubs_arende: signerat dokument underkändes i kvittensen (kopplingsmismatch)', [
                'app' => 'hubs_arende',
                'signRequestId' => $signRequestId,
                'hashPrefix' => $this->hashPrefix($post->getDokumentHash()),
            ]);
            throw new \RuntimeException('Det signerade dokumentet kunde inte verifieras mot begäran.');
        }
    }

    /**
     * Load + IDOR-guard: raden för signRequestId MÅSTE tillhöra det (redan
     * authz-grindade) ärendet — främmande/saknad rad ger samma 404, existens
     * läcker inte (mönstret från {@see PartService::kravPartICase()}).
     *
     * @throws DoesNotExistException
     */
    private function kravPostICase(string $hubsCaseId, string $signRequestId): Signering {
        $post = $signRequestId === '' ? null : $this->signeringMapper->findBySignRequestId($signRequestId);
        if ($post === null || $post->getHubsCaseId() !== $hubsCaseId) {
            throw new DoesNotExistException('Ingen signeringsbegäran ' . $signRequestId . ' i ärendet.');
        }
        return $post;
    }

    /**
     * Indata-vakt för dokumentreferensen.
     *
     * @throws \InvalidArgumentException
     */
    private function kravDokument(string $handlingRef, string $filename): void {
        if (trim($handlingRef) === '' || trim($filename) === '') {
            throw new \InvalidArgumentException('handlingRef och filename är obligatoriska.');
        }
    }

    /**
     * Kanonisk dokumenthash (U2): given klient-hash valideras (64 hex); TOM hash
     * beräknas SERVER-SIDE ur handlingRef (fileid) via aktörens filträd — aktens
     * dokumentlista bär ingen hash, och en server-beräknad hash är dessutom det
     * enda bevisvärdesbärande alternativet (klienten är otrodd).
     *
     * @return string SHA-256, gemener.
     * @throws \InvalidArgumentException när hash varken gavs eller kan beräknas.
     */
    private function resolveraDokumentHash(string $handlingRef, string $dokumentHash): string {
        if ($dokumentHash !== '') {
            if (!preg_match('/^[0-9a-fA-F]{64}$/', $dokumentHash)) {
                throw new \InvalidArgumentException('dokumentHash måste vara en kanonisk SHA-256 (64 hex-tecken).');
            }
            return strtolower($dokumentHash);
        }

        $aktor = $this->aktor();
        if ($this->rootFolder === null || $aktor === '' || !ctype_digit($handlingRef)) {
            throw new \InvalidArgumentException('dokumentHash saknas och kunde inte beräknas ur handlingRef.');
        }
        try {
            $noder = $this->rootFolder->getUserFolder($aktor)->getById((int)$handlingRef);
        } catch (\Throwable $e) {
            $this->logger->warning('signering: hashberäkning misslyckades för handlingRef ' . $handlingRef, ['exception' => $e]);
            $noder = [];
        }
        foreach ($noder as $nod) {
            if ($nod instanceof File) {
                $strom = $nod->fopen('rb');
                if (is_resource($strom)) {
                    $ctx = hash_init('sha256');
                    hash_update_stream($ctx, $strom);
                    fclose($strom);
                    return hash_final($ctx);
                }
            }
        }
        throw new \InvalidArgumentException('dokumentHash saknas och kunde inte beräknas ur handlingRef.');
    }

    /**
     * Validera + normalisera signers till portens form: [{uid, role, loa:'LOA3'}].
     * Minst en signer, varje med uid + role (K-SIGN-3).
     *
     * @param array<int,array<string,mixed>> $signers
     * @return array<int,array<string,string>>
     *
     * @throws \InvalidArgumentException
     */
    private function kravSigners(array $signers): array {
        $ut = [];
        foreach ($signers as $s) {
            if (!is_array($s)) {
                continue;
            }
            $uid = trim((string)($s['uid'] ?? ''));
            $role = trim((string)($s['role'] ?? ''));
            if ($uid === '' || $role === '') {
                throw new \InvalidArgumentException('Varje signer måste ha uid och role.');
            }
            $ut[] = ['uid' => $uid, 'role' => $role, 'loa' => 'LOA3'];
        }
        if ($ut === []) {
            throw new \InvalidArgumentException('Minst en signer krävs för en signeringsbegäran.');
        }
        return $ut;
    }

    /** SigneringDTO (delade OCS-kontraktet). Additivt fält: kedjaFran. */
    private function dto(Signering $post): array {
        return [
            'signRequestId' => $post->getSignRequestId(),
            'handlingRef' => $post->getHandlingRef(),
            'filename' => $post->getFilename(),
            'niva' => $post->getNiva(),
            'status' => $post->getStatus(),
            'signers' => $post->signers(),
            'padesLevel' => $post->getPadesLevel(),
            'createdAt' => $post->getCreatedAt()?->format('c'),
            'updatedAt' => $post->getUpdatedAt()?->format('c'),
            'expiresAt' => $post->getExpiresAt()?->format('c'),
            'avvisadSkal' => $post->getAvvisadSkal(),
            'kedjaFran' => $post->getKedjaFran(),
        ];
    }

    /** Kanonisk dokumenttyp ur mall-/filnamnet ({@see DokumenttypRegistry}); 'handling' när okänd. */
    private function dokumenttyp(string $filename): string {
        return $this->dokumenttypRegistry->klassForMall($filename) ?? 'handling';
    }

    /** Journal-/SignMessage-säkert hash-prefix (12 hex — aldrig hela hashen behövs). */
    private function hashPrefix(string $dokumentHash): string {
        return substr(strtolower($dokumentHash), 0, 12);
    }

    /** Aktörens uid ('' = system/CLI-kontext utan session). */
    private function aktor(): string {
        return $this->userSession?->getUser()?->getUID() ?? '';
    }

    /** Aktörens roll i ärendet (medlemsregistret); 'okand' utan träff/integration. */
    private function aktorRoll(string $hubsCaseId, string $uid): string {
        if ($this->memberMapper === null || $uid === '') {
            return 'okand';
        }
        try {
            foreach ($this->memberMapper->findByCaseId($hubsCaseId) as $medlem) {
                if ($medlem->getUid() === $uid) {
                    return $medlem->getRoll();
                }
            }
        } catch (\Throwable) {
            // Roll-uppslaget är berikning — får aldrig fälla godkännandet.
        }
        return 'okand';
    }

    /** Sessionens LoA-etikett (se {@see self::CONFIG_SESSION_LOA}). */
    private function sessionLoa(): string {
        return $this->appConfig->getAppValueString(self::CONFIG_SESSION_LOA, 'okand');
    }

    /** Icke-reversibel referens för loggar/journal (safeRef-mönstret, K-SIGN-15). */
    private function safeRef(string $value): string {
        if ($value === '') {
            return 'len:0';
        }
        return 'len:' . strlen($value) . ':' . substr(hash('sha256', $value), 0, 12);
    }

    /** Trimma till string-eller-null (valfri maxlängd). */
    private function strEllerNull(mixed $varde, int $max = 0): ?string {
        if (!is_string($varde)) {
            return null;
        }
        $str = trim($varde);
        if ($str === '') {
            return null;
        }
        return $max > 0 ? mb_substr($str, 0, $max) : $str;
    }

    /** Parse en ISO-tidsträng till \DateTime (null vid tom/trasig). */
    private function parseIso(string $iso): ?\DateTime {
        if (trim($iso) === '') {
            return null;
        }
        try {
            return new \DateTime($iso);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Append a journal row (best-effort — mönstret från
     * {@see PartService::loggaHandelse()}). $detalj bär ENDAST koordinations-
     * värden (signRequestId, hashPrefix, dokumenttyp, uid/roll, antal) —
     * ALDRIG filnamn, SignMessage-innehåll eller namn/personnummer (K-SIGN-15).
     *
     * @param array<string,mixed> $detalj
     */
    private function loggaHandelse(string $hubsCaseId, string $typ, array $detalj): void {
        if ($this->handelseMapper === null) {
            return;
        }
        try {
            $this->handelseMapper->record($hubsCaseId, $typ, $detalj, $this->aktor());
        } catch (\Throwable $e) {
            $this->logger->warning('hubs_arende: signering-journal misslyckades (graceful)', [
                'app' => 'hubs_arende',
                'hubsCaseId' => $hubsCaseId,
                'typ' => $typ,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
