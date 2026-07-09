<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Service;

use OCA\HubsArende\Db\Arende;
use OCA\HubsArende\Db\ArendeMapper;
use OCA\HubsArende\Db\ArendeTyp;
use OCA\HubsArende\Db\Handelse;
use OCA\HubsArende\Db\HandelseMapper;
use OCA\HubsArende\Db\Member;
use OCA\HubsArende\Db\MemberMapper;
use OCA\HubsArende\Db\PekareMapper;
use OCA\HubsArende\Exception\AvvisadException;
use OCA\HubsArende\Integration\Client\CalendarClient;
use OCA\HubsArende\Integration\Client\DeckClient;
use OCA\HubsArende\Integration\Client\GroupfolderClient;
use OCA\HubsArende\Integration\Client\SdkmcClient;
use OCA\HubsArende\Integration\Client\SpreedClient;
use OCA\HubsArende\Integration\Client\TeamClient;
use OCA\HubsArende\Integration\Port\EdiariumPort;
use OCA\HubsArende\Service\Brain\BrainProvisionRetryService;
use OCA\HubsArende\Service\Brain\BrainProvisionService;
use OCA\HubsArende\Service\Brain\BrainProvisionUnavailable;
use OCA\HubsArende\Service\Brain\HandelseTypAi;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception as DBException;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * The case engine's single writer and saga orchestrator.
 *
 * {@see createCase()} is a distributed SAGA (HUBS-INTERNALS §1.2.3): the steps
 * span different persistence layers (this app's register, sdkmc tags, a
 * Groupfolder on disk, a Deck row, a Spreed room, a CalDAV object) so NO database
 * transaction can roll them back. Instead each forward step registers a
 * COMPENSATING closure; if step n fails, the engine runs the compensations for
 * n-1..1 in reverse order, leaving no orphaned side effect.
 *
 * Step R0 is the {@see SakerhetsskyddGrind} (fail-closed) and runs BEFORE any
 * side effect — if it rejects, an {@see AvvisadException} is thrown and nothing is
 * created.
 *
 * Invariants:
 *  - commit_destination NOT NULL — enforced before the register INSERT (R2).
 *  - Idempotent on conversationId — a re-delivered inflow returns the existing case.
 *  - This app stores ONLY coordination state; verksamhetsdata lives in the facksystem.
 *
 * Steps R3–R7, R9 cross into sdkmc / Groupfolders / Deck / Spreed / CalDAV, which
 * are consumed over OCS/events. Those calls are marked TODO and their forward +
 * compensation are wired as closures so the saga structure is complete and correct;
 * only the external call bodies are stubbed.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class ArendeService {
    /** Destinations that never commit to a facksystem (still NOT NULL). */
    private const NON_FACKSYSTEM_DESTINATIONS = ['triage_forward', 'karantan'];

    /**
     * Canonical, server-side allowlist of commit_destination values (L4). A
     * client/typ-config override is validated against this set at R2 so an invalid
     * destination is fail-fast-rejected at the boundary rather than INSERT:ed and
     * stuck `aktiv`/ogallrad. Union of the facksystem-committbar destinations and
     * the declared NON_FACKSYSTEM_DESTINATIONS.
     *
     * @var array<int,string>
     */
    private const VALID_DESTINATIONS = [
        'facksystem',
        'diarium',
        'e_arkiv',
        'extern_myndighet',
        'triage_forward',
        'karantan',
    ];

    /**
     * Honest-empty SAGA-pekarblock — alla 8 koordinations-pekare null. Returneras
     * när pekareMapper saknas (positionell testharness) eller ingen pekare finns.
     * Bär ENDAST koordinationspekare (board-/kort-id, opaka tokens, conversation),
     * aldrig PII/innehåll (NEVER-SoR).
     *
     * @var array<string,null>
     */
    private const TOM_PEKARE = [
        'talkToken' => null,
        'groupfolderId' => null,
        'conversationId' => null,
        'deckBoardId' => null,
        'deckCardId' => null,
        'calendarUri' => null,
        'bevakningBoardId' => null,
        'teamId' => null,
        // ALLA ärendets chattrum (1:n) — saga-originalet först; namn = riktning
        // (null för originalet ⇒ frontendens etikett "Ärendets diskussion").
        'talkRooms' => [],
    ];

    /**
     * @param PekareMapper|null      $pekareMapper      R3–R9 pointer store (autowired at runtime; null in
     *                                                  the positional unit harness ⇒ those steps NO-OP).
     * @param SdkmcClient|null       $sdkmcClient       R3/R9 case:-tag carrier (graceful, isAvailable()-gated).
     * @param GroupfolderClient|null $groupfolderClient R4 ärenderum (graceful).
     * @param DeckClient|null        $deckClient        R5 ärendekort (graceful).
     * @param SpreedClient|null      $spreedClient      R6 case room (graceful).
     * @param CalendarClient|null    $calendarClient    R7 calendar object (graceful).
     *
     * The integration collaborators are appended as optional, autowired
     * dependencies: the NC DI container injects the real (isAvailable()-gated)
     * clients in production, while the existing positional unit harness (which
     * passes only the first seven args) leaves them null and every saga side-effect
     * step degrades to a logged NO-OP. A missing neighbour can NEVER fell createCase.
     *
     * @param IUserSession|null  $userSession  Object-level authz (H1). Autowired in
     *        production; null in the positional unit harness AND on the CLI/cron path
     *        (smoke) ⇒ SYSTEM context ⇒ authz allows. TRAILING OPTIONAL — never moved.
     * @param IGroupManager|null $groupManager Maps the caller to its authorised
     *        enheter (H1) AND resolves the enhet's mottagningskrets (the room's
     *        members) for R4/R6. Null ⇒ SYSTEM context ⇒ authz allows; krets is
     *        then empty (graceful). TRAILING OPTIONAL.
     * @param MemberMapper|null $memberMapper First-class ärenderum-member ledger.
     *        The mottagningskrets is recorded here at case birth so "who is in the
     *        room" is enumerable from the engine. Null in the positional unit
     *        harness ⇒ membership recording NO-OPs (room still created). TRAILING OPTIONAL.
     */
    public function __construct(
        private ArendeMapper $arendeMapper,
        private ArendeTypRegistry $typRegistry,
        private SakerhetsskyddGrind $sakerhetsskyddGrind,
        private FacksystemCommitService $commitService,
        private ISecureRandom $secureRandom,
        private ITimeFactory $timeFactory,
        private LoggerInterface $logger,
        private ?PekareMapper $pekareMapper = null,
        private ?SdkmcClient $sdkmcClient = null,
        private ?GroupfolderClient $groupfolderClient = null,
        private ?DeckClient $deckClient = null,
        private ?SpreedClient $spreedClient = null,
        private ?CalendarClient $calendarClient = null,
        private ?IUserSession $userSession = null,
        private ?IGroupManager $groupManager = null,
        private ?MemberMapper $memberMapper = null,
        private ?IAppConfig $appConfig = null,
        private ?ArenderumGroupService $arenderumGroupService = null,
        private ?ReferensFilService $referensFilService = null,
        private ?IRootFolder $rootFolder = null,
        // Deklarerade saga-hookar (§2.5): kat6 'diariefor_direkt' (pre-saga,
        // FGS-diarieföring led-0) och kat8 'familjeratt_yttrande' (post-commit)
        // går båda via denna port. DI-registrerad i Application::register()
        // (INTEGRATION_MODE). TRAILING OPTIONAL — null i den positionella
        // testharnessen ⇒ pre-hook fail-closed, post-hook graceful no-op.
        private ?EdiariumPort $ediariumPort = null,
        // T — ärenderummets PRESENTATIONSLAGER: ett Team (circle) per ärende med
        // per-case-gruppen som enda medlem (åtkomstspegeln — ägarmodellen ändras
        // inte). TRAILING OPTIONAL — null ⇒ T är en loggad skip, rummet fungerar
        // fullt ut utan sitt team.
        private ?TeamClient $teamClient = null,
        // Händelsejournalen ("Historik & beslut"-tidslinjen). BEST-EFFORT —
        // journal-skrivning får aldrig fälla mutationen den beskriver.
        // TRAILING OPTIONAL — null ⇒ journalen är en no-op.
        private ?HandelseMapper $handelseMapper = null,
        // Användarvalidering för tilldela/laggTillMedlem: en medlem MÅSTE vara en
        // riktig NC-/Hubs-användare (annars spök-rader i ledgern som aldrig når
        // grupp/chatt). TRAILING OPTIONAL — null ⇒ valideringen hoppar (testharness).
        private ?\OCP\IUserManager $userManager = null,
        // NC-notiser (klockan): tilldelning/medlemskap/frist-varsel. BEST-EFFORT —
        // en notis får aldrig fälla mutationen. TRAILING OPTIONAL.
        private ?INotificationManager $notificationManager = null,
        // BEVAKNINGS-LIVSCYKELN: föder standardbevakningarna vid födelse (R5→post-frist),
        // utvärderar villkor vid koppling, och äger de ref-baserade bevaknings-
        // mutationerna. TRAILING OPTIONAL — null ⇒ createCase faller tillbaka på den
        // gamla engångs-frist-beräkningen (computeFristDue) och bevaknings-API:t är dött.
        private ?BevakningService $bevakningService = null,
        // A7 — EVIDENS ur journalen som bryter den cirkulära plikt-härledningen:
        // pliktForArende() läser skyddsbedömningens FAKTISKA artefakt/kvittens i
        // stället för att gissa ur steget (som grinden själv flyttar). TRAILING
        // OPTIONAL — null ⇒ fail-open till det gamla steg-baserade beteendet så den
        // positionella testharnessen förblir grön. Autowirad i drift.
        private ?EvidensService $evidensService = null,
        // R2b — BRAIN-PROVISIONERING (SPEC-BRAIN-PER-ARENDE kap 3.3). ICKE-FÄLLANDE
        // saga-steg som provisionerar ärendets brain-tenant efter register-INSERT.
        // TRAILING OPTIONAL — null i den positionella testharnessen (R2b hoppas helt)
        // och autowiras i drift. INVARIANT (kap 1.2): ärendeskapande får ALDRIG
        // blockeras av dessa; ett provisioneringsfel köas durabelt eller sväljs.
        private ?BrainProvisionService $brainProvisionService = null,
        private ?BrainProvisionRetryService $brainProvisionRetryService = null,
    ) {
    }

    /**
     * KORT pseudonym referens för människor: de 6 första hex-tecknen av
     * hubsCaseId (utan bindestreck). Används i rums-/team-/kort-namn och
     * kort-rubriker — hela UUID:t är brus för ögat men förblir den tekniska
     * nyckeln. Fortfarande pseudonym (M2) — aldrig PII.
     */
    public static function kortRef(string $hubsCaseId): string {
        return substr(str_replace('-', '', $hubsCaseId), 0, 6);
    }

    /**
     * Validera att en uid är en RIKTIG användare. Graceful i testharness/CLI
     * utan userManager (valideringen hoppar — demo-seed använder demo-uids).
     *
     * @throws \InvalidArgumentException när användaren inte finns.
     */
    private function assertRiktigAnvandare(string $uid): void {
        if ($this->userManager === null || $uid === '') {
            return;
        }
        if (!$this->userManager->userExists($uid)) {
            throw new \InvalidArgumentException('Användaren finns inte i Hubs: ' . $uid);
        }
    }

    /**
     * Skicka en NC-notis (best-effort). Params bär ENDAST pseudonym referens +
     * koordinationsvärden — aldrig PII. Självnotiser hoppas (aktören vet redan).
     *
     * @param array<string,string> $params
     */
    private function skickaNotis(string $tillUid, string $subject, array $params, string $objektId): void {
        if ($this->notificationManager === null || $tillUid === '') {
            return;
        }
        // Ingen notis till den som själv utförde handlingen ("Ta ärendet").
        $aktor = $this->userSession?->getUser()?->getUID();
        if ($aktor !== null && $aktor === $tillUid) {
            return;
        }
        try {
            $notis = $this->notificationManager->createNotification();
            $notis->setApp('hubs_arende')
                ->setUser($tillUid)
                ->setDateTime($this->timeFactory->getDateTime())
                ->setObject('arende', substr($objektId, 0, 64))
                ->setSubject($subject, $params);
            $this->notificationManager->notify($notis);
        } catch (\Throwable $e) {
            $this->logger->warning('hubs_arende: notis misslyckades (graceful)', [
                'app' => 'hubs_arende',
                'subject' => $subject,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Append a journal row (best-effort). $detalj bär ENDAST koordinationsvärden
     * (steg-namn, dnr, roll) — ALDRIG fritext/PII. Aktören läses ur sessionen
     * ('' = system/saga-kontext). Ett journal-fel får ALDRIG fälla mutationen —
     * fel sväljs och loggas.
     *
     * @param array<string,mixed> $detalj
     */
    private function loggaHandelse(string $hubsCaseId, string $typ, array $detalj = []): void {
        if ($this->handelseMapper === null) {
            return;
        }
        try {
            $aktor = $this->userSession?->getUser()?->getUID() ?? '';
            $this->handelseMapper->record($hubsCaseId, $typ, $detalj, $aktor);
        } catch (\Throwable $e) {
            $this->logger->warning('hubs_arende: journal-skrivning misslyckades (graceful)', [
                'app' => 'hubs_arende',
                'hubsCaseId' => $hubsCaseId,
                'typ' => $typ,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Create a case for an inflow row, running the säkerhetsskydd-grind (R0) then
     * the saga R1–R10 with per-step compensation.
     *
     * @param array<string,mixed> $rad Inflow/triage row. Recognised keys:
     *        conversationId, arendeTyp|arendetyp, triageRef, objektRef|barnRef,
     *        enhet, commitDestination (override), inkomDatum.
     *
     * @return Arende The completed register row (status=otilldelat, steg=inflode).
     *
     * @throws AvvisadException If the säkerhetsskydd-grind rejects the inflow
     *         (NO side effect performed).
     * @throws \InvalidArgumentException If the ärendetyp is unknown or
     *         commit_destination would be null (the engine invariant).
     * @throws \RuntimeException If a saga step fails after compensation has run.
     */
    public function createCase(array $rad): Arende {
        // ============================================================== //
        //  R0 — SÄKERHETSSKYDD-GRIND (FAIL-CLOSED), BEFORE ANY SIDE EFFECT
        // ============================================================== //
        $grind = $this->sakerhetsskyddGrind->evaluate($rad);
        if ($grind['avvisad'] === true) {
            // If a case already exists for this anchor, quarantine it retroactively.
            if (($grind['retroaktiv'] ?? false) === true) {
                $conversationId = (string)($rad['conversationId'] ?? '');
                $existing = $conversationId !== ''
                    ? $this->arendeMapper->findByConversationId($conversationId)
                    : null;
                if ($existing !== null) {
                    $this->sakerhetsskyddGrind->evaluateRetroaktiv(
                        $existing->getHubsCaseId(),
                        (string)$grind['reason'],
                    );
                }
            }
            // No register row, no tag, no room — just the avvisningskvitto.
            throw new AvvisadException(
                (string)$grind['reason'],
                (array)($grind['kvitto'] ?? []),
                (bool)($grind['retroaktiv'] ?? false),
            );
        }

        // --- Idempotency: a re-delivered inflow returns the existing case. ----
        $conversationId = (string)($rad['conversationId'] ?? '');
        if ($conversationId !== '') {
            $existing = $this->arendeMapper->findByConversationId($conversationId);
            if ($existing !== null) {
                $this->logger->info('hubs_arende: createCase idempotent hit', [
                    'app' => 'hubs_arende',
                    'conversationId' => $conversationId,
                    'hubsCaseId' => $existing->getHubsCaseId(),
                ]);
                return $existing;
            }
        }

        // --- Resolve the ärendetyp config-row (parameterises every step). -----
        $arendeTypId = (string)($rad['arendeTyp'] ?? $rad['arendetyp'] ?? '');
        $typ = $this->typRegistry->get($arendeTypId);
        if ($typ === null) {
            throw new \InvalidArgumentException('Okänd ärendetyp: ' . $arendeTypId);
        }

        // --- INVARIANT: commit_destination NOT NULL (enforced before INSERT). -
        $commitDestination = $this->resolveCommitDestination($rad, $typ);

        // --- H1: object-level authz on the create path. The enhet is resolved the
        //     same way buildEntity() resolves it (explicit enhet wins, else the typ
        //     default). Runs AFTER the R0 grind and BEFORE the R2 INSERT so an
        //     unauthorised caller can neither create a row nor any saga side effect.
        $skapaEnhet = isset($rad['enhet']) && $rad['enhet'] !== ''
            ? (string)$rad['enhet']
            : (string)$typ->getDefaultEnhet();
        $this->assertEnhetAtkomstForEnhet($skapaEnhet);

        // --- M1/M2 — pre-flight pseudonym-validering FÖRE sagan. Körs här (inte bara
        //     i buildEntity, som ligger inuti saga-try:n) så att ogiltig PII ger ett
        //     rent \InvalidArgumentException → 400, i stället för att fångas av sagans
        //     kompensering och wrappas till \RuntimeException → 500. buildEntity()
        //     validerar samma värden igen (defense in depth, idempotent).
        $this->validateObjektRef(
            isset($rad['objektRef']) ? (string)$rad['objektRef']
                : (isset($rad['barnRef']) ? (string)$rad['barnRef'] : null)
        );
        $this->validateTriageRef(isset($rad['triageRef']) ? (string)$rad['triageRef'] : null);

        // Compensation stack — closures pushed after each successful forward step,
        // run in reverse on failure.
        /** @var array<int, array{name:string, fn:callable():void}> $compensations */
        $compensations = [];

        $hubsCaseId = '';
        try {
            // ========================================================== //
            //  R1 — MINT hubsCaseId (UUID v4). No side effect yet.
            // ========================================================== //
            $hubsCaseId = $this->mintUuidV4();

            // ========================================================== //
            //  PRE-SAGA HOOK — kat6 'diariefor_direkt' (omvänd ordning).
            // ========================================================== //
            // För en typ med preSagaHook='diariefor_direkt' (rättsligt/tvång)
            // sker formell diarieföring DIREKT som led-0 FÖRE registret/rummet
            // (allmän handling, OSL 5:1) — omvänd ordning mot kat1:s
            // förhandsbedömning-först. Kvittot seedar den FÖDS-registrerade raden
            // (provenans='registrerad' + dnr) i buildEntity nedan. Fail-closed:
            // saknas porten kastar handlern (kompenseras + wrappas av sagans catch).
            $preHookKvitto = $this->dispatchHook($typ->getPreSagaHook(), $hubsCaseId, $typ, $rad);
            if ($preHookKvitto !== null && ($preHookKvitto['ok'] ?? false) === true) {
                $compensations[] = [
                    'name' => 'PRE:diariefor_direkt',
                    'fn' => function () use ($hubsCaseId, $preHookKvitto): void {
                        // En allmän handling kan inte av-diarieföras programmatiskt
                        // (rättelse sker via en separat rättelse-handling). Logga att
                        // diarienumret kvarstår så ops kan stämma av manuellt.
                        $this->logger->critical(
                            'hubs_arende: saga-fel EFTER diariefor_direkt — diarienummer kvarstår (manuell rättelse kan krävas)',
                            [
                                'app' => 'hubs_arende',
                                'hubsCaseId' => $hubsCaseId,
                                'diarienummer' => $preHookKvitto['diarienummer'] ?? null,
                            ],
                        );
                    },
                ];
            }

            // ========================================================== //
            //  R2 — INSERT register row (commit_destination NOT NULL).
            // ========================================================== //
            // M6 — close the idempotency TOCTOU window application-side: a UNIQUE
            // index on conversation_id (new migration) turns a racing duplicate into
            // a constraint violation here. Catch it, re-read the winning row and
            // return it as the idempotent result instead of compensating a partial
            // saga. The winner already exists, so there is no orphaned side effect.
            $arende = $this->buildEntity($hubsCaseId, $rad, $typ, $commitDestination, $preHookKvitto);
            try {
                $arende = $this->arendeMapper->insert($arende);
            } catch (DBException $e) {
                if (
                    $e->getReason() === DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION
                    && $conversationId !== ''
                ) {
                    $winner = $this->arendeMapper->findByConversationId($conversationId);
                    if ($winner !== null) {
                        $this->logger->info('hubs_arende: createCase idempotent (R2 race vunnen av annan)', [
                            'app' => 'hubs_arende',
                            'conversationId' => $conversationId,
                            'hubsCaseId' => $winner->getHubsCaseId(),
                        ]);
                        return $winner;
                    }
                }
                throw $e;
            }
            $compensations[] = [
                'name' => 'R2:delete-register-row',
                'fn' => function () use ($arende): void {
                    try {
                        $this->arendeMapper->delete($arende);
                    } catch (\Throwable $e) {
                        $this->logCompensationFailure('R2', $e);
                    }
                },
            ];

            // ========================================================== //
            //  R2b — BRAIN-PROVISIONERING (ICKE-FÄLLANDE, SPEC kap 1.2/3.3).
            // ========================================================== //
            // Provisionera ärendets brain-tenant DIREKT efter registret (R2) men
            // FÖRE de externa saga-stegen. HÅRD INVARIANT (kap 1.2): ärendeskapande
            // får ALDRIG blockeras av AI-infra — därför är HELA blocket icke-fällande:
            //   - provision() kastar ENDAST BrainProvisionUnavailable (retrybart);
            //     då köas ärendet durabelt (BrainProvisionRetryService) och skapandet
            //     fortsätter UTAN brain (BrainProvisionRetryJob efterprovisionerar).
            //   - permanent_fel (409/422) ⇒ ingen brain, ingen retry (loggas).
            //   - noop (ej konfigurerad miljö) ⇒ tyst; ingen brain.
            //   - lyckat ⇒ brain_tenant-pekare (hubs_case_id → tenant_id) + TYP_AI-
            //     journal + kompensering 'R2b:drop-brain' som river tenanten om en
            //     SENARE saga-fas (R3–R10) rullar tillbaka.
            //   - belt-and-suspenders catch(\Throwable): INGET kast når saga-catch:en.
            // Trailing-optional-injektionen ⇒ null i testharnessen ⇒ R2b hoppas helt.
            if ($this->brainProvisionService !== null) {
                try {
                    $brainRes = $this->brainProvisionService->provision(
                        $hubsCaseId,
                        $typ->getArendeTypId(),
                        // Normalt false — R0 fångade karantän-inflöden före R2. En typ
                        // som EXPLICIT föds i karantän får en R0-speglad (fryst) brain.
                        $commitDestination === 'karantan',
                    );
                    $tenantId = (string)($brainRes['tenant_id'] ?? '');
                    if ($tenantId !== '') {
                        // Kompenseringen pushas FÖRST (fångar tenantId per värde), så en
                        // ev. pekar-skrivfel nedan inte tappar rollback-förmågan.
                        $compensations[] = [
                            'name' => 'R2b:drop-brain',
                            'fn' => function () use ($tenantId, $hubsCaseId): void {
                                try {
                                    $this->brainProvisionService?->rollback($tenantId);
                                    $this->pekareMapper?->deleteByCaseAndTyp($hubsCaseId, 'brain_tenant');
                                } catch (\Throwable $e) {
                                    $this->logCompensationFailure('R2b', $e);
                                }
                            },
                        ];
                        // brain_tenant-pekaren är enda vägen hubs_case_id → tenant_id
                        // (frys/gallring/karantän-spegling resolverar tenanten härifrån).
                        $this->pekareMapper?->record($hubsCaseId, 'brain_tenant', $tenantId);
                        $this->loggaHandelse($hubsCaseId, HandelseTypAi::typVarde(), [
                            'handling' => HandelseTypAi::PROVISIONERAD,
                            'idempotent' => ($brainRes['idempotent'] ?? false) === true,
                        ]);
                    } elseif (($brainRes['permanent_fel'] ?? false) === true) {
                        // 409/422 = terminalt (t.ex. schema-kollision). Ingen retry (kap 3.3).
                        $this->logger->critical('hubs_arende: brain-provision permanent fel vid R2b (driftlarm)', [
                            'app' => 'hubs_arende',
                            'hubsCaseId' => $hubsCaseId,
                            'kod' => (string)($brainRes['kod'] ?? ''),
                        ]);
                    }
                    // noop (ej konfigurerad) faller igenom tyst: ingen brain i denna miljö.
                } catch (BrainProvisionUnavailable $e) {
                    // RETRYBART (provisionern onåbar/timeout/5xx): köa durabelt och
                    // neutralisera kön om sagan senare rullar tillbaka (kap 3.3, spärr 1).
                    $this->brainProvisionRetryService?->enqueue($hubsCaseId, $typ->getArendeTypId());
                    $compensations[] = [
                        'name' => 'R2b:retry-neutralisera',
                        'fn' => function () use ($hubsCaseId): void {
                            try {
                                $this->brainProvisionRetryService?->neutralisera($hubsCaseId);
                            } catch (\Throwable $e) {
                                $this->logCompensationFailure('R2b', $e);
                            }
                        },
                    ];
                    $this->logger->warning('hubs_arende: brain-provision onåbar vid R2b — köad för durabel retry', [
                        'app' => 'hubs_arende',
                        'hubsCaseId' => $hubsCaseId,
                    ]);
                } catch (\Throwable $e) {
                    // Belt-and-suspenders (kap 3.3): INGET brain-fel får fälla skapandet.
                    $this->logger->error('hubs_arende: brain-provision ohanterat fel vid R2b (sväljs, ärendet skapas)', [
                        'app' => 'hubs_arende',
                        'hubsCaseId' => $hubsCaseId,
                        'exception' => $e->getMessage(),
                    ]);
                }
            } else {
                $this->skipStep('R2b', $hubsCaseId);
            }

            // ========================================================== //
            //  R3 — case:{hubsCaseId} tag via sdkmc (carrier of the token).
            // ========================================================== //
            // Create the case:{hubsCaseId} systemtag/imap_label via the sdkmc OCS
            // seam and record a pekare (objekt_typ='case_tag'). isAvailable()-gated:
            // if sdkmc/pekareMapper is absent this is a logged skip, not a crash.
            if ($this->sdkmcClient !== null && $this->pekareMapper !== null) {
                $caseTag = $this->sdkmcClient->createCaseTag($hubsCaseId, $this->inflowEmail($rad));
                if ($caseTag !== null) {
                    $this->pekareMapper->record($hubsCaseId, 'case_tag', $caseTag);
                }
            } else {
                $this->skipStep('R3', $hubsCaseId);
            }
            $compensations[] = [
                'name' => 'R3:delete-case-tag',
                'fn' => function () use ($hubsCaseId, $rad): void {
                    if ($this->sdkmcClient === null || $this->pekareMapper === null) {
                        $this->noopCompensation('R3', $hubsCaseId);
                        return;
                    }
                    foreach ($this->pekareMapper->findByCaseAndTyp($hubsCaseId, 'case_tag') as $p) {
                        $this->sdkmcClient->deleteCaseTag($hubsCaseId, $this->inflowEmail($rad), $p->getObjektId());
                    }
                    $this->pekareMapper->deleteByCaseAndTyp($hubsCaseId, 'case_tag');
                },
            ];

            // ========================================================== //
            //  M — Medlemmar: mottagningskretsen (enhetens grupp).
            // ========================================================== //
            // Resolve the room's reception circle ONCE and reuse it for R4
            // (groupfolder group-grant) and R6 (Spreed participants), and record it
            // as the ärenderum's FIRST-CLASS members so "who is in the room" is
            // enumerable from the engine — not only projected onto external ACLs.
            // groupManager absent / no matching enhet-group ⇒ empty krets (graceful,
            // fail-closed: the room is created with NO broad access rather than a
            // wrong one). The assignee (+ co-handläggare) are added later by tilldela().
            $kretsUids = $this->aclKretsUids($arende, $typ);
            if ($this->memberMapper !== null) {
                foreach ($kretsUids as $uid) {
                    $this->memberMapper->record($hubsCaseId, $uid, Member::ROLL_MOTTAGNINGSKRETS);
                }
            } else {
                $this->skipStep('M', $hubsCaseId);
            }
            // Per-ÄRENDE-grupp = ärenderummets ÅTKOMSTLISTA (per-ärende-isolering):
            // skapa gruppen och synka den till mottagningskretsen. R4 grantar SEDAN
            // ENBART denna grupp på foldern → bara ärendets medlemmar ser rummet (ej
            // hela enheten). Gruppen speglar member-ledgern hädanefter (tilldela /
            // laggTillMedlem / taBortMedlem synkar om).
            $perCaseGid = $this->arenderumGroupService?->ensure($hubsCaseId);
            // Synka gruppen ur ledgern (handoff-medvetet via atkomstUids). Vid födsel
            // är ärendet otilldelat ⇒ åtkomst = mottagningskretsen.
            $this->syncArenderumGrupp($hubsCaseId);
            $compensations[] = [
                'name' => 'M:teardown-members',
                'fn' => function () use ($hubsCaseId): void {
                    // Riv per-case-gruppen (åtkomstlistan) FÖRST, sedan member-raderna.
                    $this->arenderumGroupService?->delete($hubsCaseId);
                    if ($this->memberMapper === null) {
                        $this->noopCompensation('M', $hubsCaseId);
                        return;
                    }
                    // Rollback tears down the whole room → remove all member rows
                    // (only mottagningskrets exists during createCase rollback).
                    $this->memberMapper->deleteByCaseId($hubsCaseId);
                },
            ];

            // ========================================================== //
            //  T — Team (circle): ärenderummets PRESENTATIONSLAGER.
            // ========================================================== //
            // One Team per case, owned by the service account, with the per-case
            // access group as its ONLY member ⇒ the team is an automatically
            // correct mirror of the room's access list (handoff included) — the
            // ÄGARMODELLEN IS UNCHANGED, gruppen förblir åtkomstprimitiven. The
            // team ties the room together in NC:s egna UI:n: R4 grantar teamet
            // som extra applicable på groupfoldern och R6 lägger det som
            // Talk-deltagare, så akt + diskussion listas som team-resurser.
            // Pseudonymt namn (M2) — aldrig triageRef. Pekare objekt_typ='team'
            // (objekt_id = circle singleId). Graceful skip utan client/grupp.
            $teamSingleId = null;
            if ($this->teamClient !== null && $this->pekareMapper !== null && $perCaseGid !== null) {
                $teamSingleId = $this->teamClient->createTeam('Ärende ' . self::kortRef($hubsCaseId), $perCaseGid);
                if ($teamSingleId !== null) {
                    $this->pekareMapper->record($hubsCaseId, 'team', $teamSingleId);
                }
            } else {
                $this->skipStep('T', $hubsCaseId);
            }
            $compensations[] = [
                'name' => 'T:destroy-team',
                'fn' => function () use ($hubsCaseId): void {
                    if ($this->teamClient === null || $this->pekareMapper === null) {
                        $this->noopCompensation('T', $hubsCaseId);
                        return;
                    }
                    foreach ($this->pekareMapper->findByCaseAndTyp($hubsCaseId, 'team') as $p) {
                        $this->teamClient->destroyTeam($p->getObjektId());
                    }
                    $this->pekareMapper->deleteByCaseAndTyp($hubsCaseId, 'team');
                },
            ];

            // ========================================================== //
            //  R4 — Groupfolder (ärenderum) + least-permission ACL.
            // ========================================================== //
            // Create the ärenderum, grant the enhet's mottagningskrets-group(s)
            // access and apply the least-permission ACL profile from
            // $typ->getAclProfil(); store a pekare (objekt_typ='groupfolder').
            // isAvailable()-gated graceful skip when groupfolders is absent.
            if ($this->groupfolderClient !== null && $this->pekareMapper !== null) {
                // M2 — use the pseudonym hubsCaseId as the human-visible mount name,
                // NEVER triageRef (which may carry a kommunal referens nearer PII).
                // Teamet grantas som EXTRA applicable (samma publik som gruppen —
                // ingen behörighetsbreddning) så akten listas som team-resurs.
                $folderGroups = $perCaseGid !== null ? [$perCaseGid] : [];
                if ($teamSingleId !== null) {
                    $folderGroups[] = $teamSingleId;
                }
                $folderId = $this->groupfolderClient->createArenderum(
                    $hubsCaseId,
                    (string)$typ->getAclProfil(),
                    $folderGroups,
                );
                if ($folderId !== null) {
                    $this->pekareMapper->record($hubsCaseId, 'groupfolder', (string)$folderId);
                }
            } else {
                $this->skipStep('R4', $hubsCaseId);
            }
            $compensations[] = [
                'name' => 'R4:remove-groupfolder',
                'fn' => function () use ($hubsCaseId): void {
                    if ($this->groupfolderClient === null || $this->pekareMapper === null) {
                        $this->noopCompensation('R4', $hubsCaseId);
                        return;
                    }
                    foreach ($this->pekareMapper->findByCaseAndTyp($hubsCaseId, 'groupfolder') as $p) {
                        $this->groupfolderClient->removeFolder((int)$p->getObjektId());
                    }
                    $this->pekareMapper->deleteByCaseAndTyp($hubsCaseId, 'groupfolder');
                },
            ];

            // ========================================================== //
            //  R5 — Bevaknings-Deck-korten (ETT kort per bevakning).
            // ========================================================== //
            // Den gamla modellen skapade HÄR ett enda generiskt, inert kort
            // (due=null) per ärende. Nu skapas i stället ETT Deck-kort per
            // standardbevakning — men först EFTER att fristen beräknats (R8) och
            // steget satts (R10), av BevakningService::skapaStandardForFodelse
            // längre ned. Varje sådant kort registreras som en deck_card-pekare,
            // så kompensationen nedan (och gallringen) river dem uniformt.
            $this->skipStep('R5', $hubsCaseId);
            $compensations[] = [
                'name' => 'R5:delete-deck-cards+bevakningar',
                'fn' => function () use ($hubsCaseId): void {
                    // Riv bevakningsraderna (koordinationsdata) …
                    $this->bevakningService?->rensaForKompensation($hubsCaseId);
                    // … och deras Deck-kort via deck_card-pekarna.
                    if ($this->deckClient === null || $this->pekareMapper === null) {
                        $this->noopCompensation('R5', $hubsCaseId);
                        return;
                    }
                    foreach ($this->pekareMapper->findByCaseAndTyp($hubsCaseId, 'deck_card') as $p) {
                        $this->deckClient->deleteCard((int)$p->getRiktning(), (int)$p->getObjektId());
                    }
                    $this->pekareMapper->deleteByCaseAndTyp($hubsCaseId, 'deck_card');
                },
            ];

            // ========================================================== //
            //  R6 — Spreed room (participants = ACL krets). ALL chat here.
            // ========================================================== //
            // Create the case talk room (spreed-itsl room-API) with the ACL krets as
            // participants; store a pekare (objekt_typ='talk_room', objekt_id=token).
            // isAvailable()-gated graceful skip.
            if ($this->spreedClient !== null && $this->pekareMapper !== null) {
                // M2 — pseudonymt rumsnamn; "– diskussion" gör HUVUDCHATTEN
                // identifierbar i Talk-listan (rått UUID sa ingenting).
                // Participants = the mottagningskrets resolved once for step M (above).
                $talkToken = $this->spreedClient->createRoom(
                    'Ärende ' . self::kortRef($hubsCaseId) . ' – diskussion',
                    $kretsUids,
                );
                if ($talkToken !== null) {
                    $this->pekareMapper->record($hubsCaseId, 'talk_room', $talkToken);
                    // T-koppling: teamet som deltagare (markör-attendee) så rummet
                    // listas som team-resurs + @team-mention. Samma publik som
                    // kretsen — ingen behörighetsbreddning. Best-effort.
                    if ($teamSingleId !== null) {
                        $this->spreedClient->addCircleParticipant($talkToken, $teamSingleId);
                    }
                }
            } else {
                $this->skipStep('R6', $hubsCaseId);
            }
            $compensations[] = [
                'name' => 'R6:delete-spreed-room',
                'fn' => function () use ($hubsCaseId): void {
                    if ($this->spreedClient === null || $this->pekareMapper === null) {
                        $this->noopCompensation('R6', $hubsCaseId);
                        return;
                    }
                    // Rollback of a freshly-created R6 room → HARD delete (no orphan
                    // row). Evidence-preserving isolation is the säkerhetsskydd path.
                    foreach ($this->pekareMapper->findByCaseAndTyp($hubsCaseId, 'talk_room') as $p) {
                        $this->spreedClient->deleteRoom($p->getObjektId());
                    }
                    $this->pekareMapper->deleteByCaseAndTyp($hubsCaseId, 'talk_room');
                },
            ];

            // ========================================================== //
            //  R7 — Calendar (CATEGORIES=hubsCaseId) for case meetings.
            // ========================================================== //
            // Prepare the per-case calendar object (CATEGORIES={hubsCaseId}); store a
            // pekare (objekt_typ='calendar', objekt_id=objUri, riktning=ägar-uid). At
            // birth the case is otilldelat ⇒ owner is the SERVICE ACCOUNT (riktning
            // ''); tilldela() re-homes the object into the handläggarens egen kalender
            // (handläggar-ägd kalender). isAvailable()-gated graceful skip.
            if ($this->calendarClient !== null && $this->pekareMapper !== null) {
                $kalenderAgare = $arende->getAgareUid(); // null vid födsel (otilldelat)
                $objUri = $this->calendarClient->prepareCaseCalendar($hubsCaseId, $kalenderAgare);
                if ($objUri !== null) {
                    $this->pekareMapper->record($hubsCaseId, 'calendar', $objUri, $kalenderAgare ?? '');
                }
            } else {
                $this->skipStep('R7', $hubsCaseId);
            }
            $compensations[] = [
                'name' => 'R7:remove-calendar-object',
                'fn' => function () use ($hubsCaseId): void {
                    if ($this->calendarClient === null || $this->pekareMapper === null) {
                        $this->noopCompensation('R7', $hubsCaseId);
                        return;
                    }
                    foreach ($this->pekareMapper->findByCaseAndTyp($hubsCaseId, 'calendar') as $p) {
                        // riktning carries the owner uid ('' = service account).
                        $this->calendarClient->removeCalendar($p->getObjektId(), $p->getRiktning() ?: null);
                    }
                    $this->pekareMapper->deleteByCaseAndTyp($hubsCaseId, 'calendar');
                },
            ];

            // ========================================================== //
            //  R8 — Frist from inkom-datum (NOT now()), per fristPolicy.
            // ========================================================== //
            $fristDue = $this->computeFristDue($rad, $typ);
            if ($fristDue !== null) {
                $arende->setFristDue($fristDue);
                $arende = $this->arendeMapper->update($arende);
            }
            // (Compensation for R8 is folded into R2's row delete — no separate side effect.)

            // ========================================================== //
            //  R9 — Tag the triggering message(s) case:{id} via sdkmc.
            // ========================================================== //
            // Tag the inflow message(s) with case:{hubsCaseId} via sdkmc, and write
            // the conversation pekare (objekt_typ='conversation') that mirrors the
            // idempotency anchor. isAvailable()-gated graceful skip.
            if ($this->sdkmcClient !== null && $this->pekareMapper !== null) {
                $this->sdkmcClient->tagMessage($hubsCaseId, $this->inflowMessageIds($rad));
                if ($conversationId !== '') {
                    $this->pekareMapper->record($hubsCaseId, 'conversation', $conversationId);
                }
            } else {
                $this->skipStep('R9', $hubsCaseId);
            }
            // #8 — skriv en REFERENS till startmeddelandet i ärenderummet så akten inte
            // är tom vid födsel: en .url-pekare (+ groupfolder_ref-pekare) i groupfoldern,
            // ENDAST metadata (hubsCaseId + meddelande-ref), aldrig PII/innehåll (NEVER-SoR,
            // samma mönster som kopplaMeddelande). conversationId är den durabla
            // meddelande-ankaren. Best-effort/graceful: no-op om groupfoldern ännu ej är
            // resolverbar eller referensFilService/rootFolder saknas (positionell testharness).
            if ($conversationId !== '') {
                $this->referensFilService?->skrivMeddelandeReferens($hubsCaseId, $conversationId);
            }
            $compensations[] = [
                'name' => 'R9:untag-message',
                'fn' => function () use ($hubsCaseId, $rad): void {
                    if ($this->sdkmcClient === null || $this->pekareMapper === null) {
                        $this->noopCompensation('R9', $hubsCaseId);
                        return;
                    }
                    $this->sdkmcClient->untagMessage($hubsCaseId, $this->inflowMessageIds($rad));
                    $this->pekareMapper->deleteByCaseAndTyp($hubsCaseId, 'conversation');
                },
            ];

            // ========================================================== //
            //  R10 — UPDATE register with all pointers (commit point).
            // ========================================================== //
            // The row becomes "komplett". Pointers from R3–R7 live in
            // hubs_arende_pekare (written by those steps); here we finalise status.
            $arende->setStatus('otilldelat');
            // gap23 — case-skapande typer FÖDS i 'forhandsbedomning' (rätt stepper-
            // position); ENDAST de rena triage-utan-ärende-destinationerna
            // (triage_forward/karantan) föds i 'inflode'.
            $arende->setSteg($this->fodelseSteg($commitDestination));
            $arende = $this->arendeMapper->update($arende);

            // Journal: ärendet föddes (+ ev. född-registrerad via kat6-hooken).
            $this->loggaHandelse($hubsCaseId, Handelse::TYP_SKAPAD, [
                'arendeTyp' => $typ->getArendeTypId(),
                'enhet' => (string)$arende->getEnhet(),
            ]);
            if ($arende->getProvenanceState() === 'registrerad') {
                $this->loggaHandelse($hubsCaseId, Handelse::TYP_REGISTRERAD, [
                    'dnr' => (string)$arende->getDnr(),
                    'destination' => $commitDestination,
                    'vidFodsel' => true,
                ]);
            }

            // BEVAKNINGSKEDJAN föds här — EFTER att register-raden är komplett (frist
            // R8, steg R10) så att fristprojektionen inte klottras över av senare
            // lokala $arende-uppdateringar. Instansierar ärendetypens vidSteg='fodelse'-
            // mallar (t.ex. orosanmälans 14-dagars förhandsbedömning), skapar deras
            // Deck-kort (deck_card-pekare → R5-kompensation/gallring river dem) och
            // projicerar registrets fristDue = tidigaste aktiva bevakning. Best-effort;
            // ett fel fäller inte det redan födda ärendet. null-service (testharness)
            // ⇒ computeFristDue (R8) står kvar som frist.
            $this->bevakningService?->skapaStandardForFodelse($hubsCaseId, $typ, $rad);

            $this->logger->info('hubs_arende: createCase OK', [
                'app' => 'hubs_arende',
                'hubsCaseId' => $hubsCaseId,
                'arendeTyp' => $typ->getArendeTypId(),
                'commitDestination' => $commitDestination,
            ]);

            return $arende;
        } catch (AvvisadException $e) {
            // Should not occur here (R0 handled above) but never compensate-then-swallow it.
            throw $e;
        } catch (\Throwable $e) {
            // SAGA FAILURE: run compensations n-1..1 in reverse order.
            $this->compensate($compensations, $hubsCaseId, $e);
            throw new \RuntimeException(
                'hubs_arende: createCase-saga avbruten och kompenserad (' . $e->getMessage() . ')',
                0,
                $e,
            );
        }
    }

    /**
     * Open a case by hubsCaseId or dnr (O(1) register lookup).
     *
     * H1: object-level authz is enforced FIRST — an unauthorised caller gets a
     * {@see DoesNotExistException} (controller → 404) so existence is not leaked.
     *
     * @throws DoesNotExistException
     * @throws DBException
     */
    public function show(string $ref): Arende {
        $arende = $this->resolveByRef($ref);
        $this->assertEnhetAtkomst($arende);
        return $arende;
    }

    /**
     * Resolve a case by hubsCaseId or dnr WITHOUT the authz check. Internal only —
     * the public {@see show()} adds the enhet gate on top.
     *
     * @throws DoesNotExistException
     * @throws DBException
     */
    private function resolveByRef(string $ref): Arende {
        try {
            return $this->arendeMapper->findByCaseId($ref);
        } catch (DoesNotExistException) {
            $byDnr = $this->arendeMapper->findByDnr($ref);
            if ($byDnr === null) {
                throw new DoesNotExistException('Inget ärende för ref ' . $ref);
            }
            return $byDnr;
        }
    }

    /**
     * Dashboard aggregate over an enhet — counts + frist colours only, never
     * innehåll (OSL 26 kap.). When $enhet is null an empty aggregate is returned
     * (the OCS layer is expected to scope to the caller's authorised enheter).
     *
     * @return array<string,mixed>
     */
    public function summary(?string $enhet): array {
        if ($enhet === null || $enhet === '') {
            return ['enhet' => null, 'total' => 0, 'perStatus' => [], 'perSteg' => []];
        }

        // H1: scope the aggregate to the caller's authorised enheter. SYSTEM/CLI
        // (no session) sees all; a real user that asks for an enhet it is not in
        // gets an empty aggregate (counts of another enhet are not leaked).
        if (!$this->enhetTillaten($enhet)) {
            return ['enhet' => $enhet, 'total' => 0, 'perStatus' => [], 'perSteg' => []];
        }

        try {
            $cases = $this->arendeMapper->findByEnhet($enhet);
        } catch (DBException $e) {
            $this->logger->error('hubs_arende: summary DB-fel', [
                'app' => 'hubs_arende',
                'enhet' => $enhet,
                'exception' => $e,
            ]);
            $cases = [];
        }

        $perStatus = [];
        $perSteg = [];
        foreach ($cases as $c) {
            $perStatus[$c->getStatus()] = ($perStatus[$c->getStatus()] ?? 0) + 1;
            $perSteg[$c->getSteg()] = ($perSteg[$c->getSteg()] ?? 0) + 1;
        }

        return [
            'enhet' => $enhet,
            'total' => count($cases),
            'perStatus' => $perStatus,
            'perSteg' => $perSteg,
        ];
    }

    /**
     * Dashboard "Mina ärenden" summary for the hubs_start frontend. Returns the
     * caller's real cases mapped to the dashboard's collapsed card shape — ENGINE-
     * HONEST and THIN: only the coordination fields the engine actually holds
     * (pseudonym-ref, steg, frist-datum, provenans). PII/innehåll (avsändare,
     * dokument, sekretess-detaljer, möten) is NOT fabricated — it is left empty so it
     * stays unambiguous what is real engine state and what is not.
     *
     * No live inflöde-feed/möten exist in the standalone engine, so triage/moten are
     * empty. The shape mirrors ssDemo.fetchArendeSummary so the existing UI renders
     * it unchanged.
     *
     * @return array<string,mixed>
     */
    public function dashboardSummary(bool $mineOnly = false): array {
        $cards = $this->dashboardArenden($mineOnly);
        // gap25 — dagspuls: räkna ur de redan-mappade korten (frist-färg = sanning).
        $fristerBrinner = 0;
        foreach ($cards as $kort) {
            $tone = $kort['frist']['tone'] ?? null;
            if ($tone === 'error' || $tone === 'warning') {
                $fristerBrinner++;
            }
        }
        return [
            'arenden' => $cards,
            'triage' => [],   // no live inflow feed in the standalone engine
            'moten' => [],    // calendar lives outside the engine
            // gap34 — ärenden i slutläge ('avslutat') som uppdaterades idag. Motorn
            // saknar separat avslut-tidsstämpel; honest fallback = 0 (inget fabriceras).
            'klartIdag' => $this->klartIdag(),
            // gap25 — 5-nyckel-puls som hubs_start-dashboarden binder. Motorn äger bara
            // frist-färgen ärligt; möten/signera/inflöde/omnämnanden lever utanför
            // motorn ⇒ 0 (aldrig fabricerade), fristerBrinner härleds ur korts frist.
            'puls' => [
                'fristerBrinner' => $fristerBrinner,
                'motenIdag' => 0,
                'attSignera' => 0,
                'nyaInflode' => 0,
                'omnamnanden' => 0,
            ],
        ];
    }

    /**
     * gap34 — antal ärenden i slutläget 'avslutat' som senast uppdaterades idag. Motorn
     * lagrar ingen separat avslut-/uppdaterings-tidsstämpel utöver 'skapad'; ett ärende
     * som SKAPADES idag och redan är 'avslutat' räknas honest som klart idag. Övriga
     * 'avslutat' (skapade tidigare) kan inte tidsbestämmas ⇒ utelämnas (inget fabriceras).
     */
    private function klartIdag(): int {
        $idag = $this->timeFactory->getDateTime()->format('Y-m-d');
        $antal = 0;
        foreach ($this->arendeMapper->findAll(200) as $arende) {
            if (!$this->enhetTillaten($arende->getEnhet())) {
                continue;
            }
            if ($arende->getSteg() !== 'avslutat') {
                continue;
            }
            $skapad = $arende->getSkapad();
            if ($skapad instanceof \DateTime && $skapad->format('Y-m-d') === $idag) {
                $antal++;
            }
        }
        return $antal;
    }

    /**
     * The caller's authorised cases mapped to collapsed dashboard cards. Object-level
     * authz is applied per row (system/CLI + admin see all; a real user sees only
     * cases for an enhet it belongs to) — the same fail-closed enhet-predikat the
     * rest of the service uses.
     *
     * MEDLEMSBASERAT läge ($mineOnly): "Mina ärenden" = de ärenden där anroparen
     * finns i medlemsledgern (mottagningskrets/handläggare/co/observatör) — inte
     * hela enhetens lista. Enhet-authz (H1) gäller FORTFARANDE per rad ovanpå
     * medlemsfiltret. System/CLI-kontext (ingen session) ⇒ mineOnly ignoreras
     * (smoke/jobb ser allt, som tidigare).
     *
     * @return list<array<string,mixed>>
     */
    public function dashboardArenden(bool $mineOnly = false): array {
        $minaCaseIds = null;
        if ($mineOnly && $this->memberMapper !== null) {
            $uid = $this->userSession?->getUser()?->getUID();
            if ($uid !== null && $uid !== '') {
                try {
                    $minaCaseIds = array_fill_keys($this->memberMapper->findCaseIdsByUid($uid), true);
                } catch (\Throwable $e) {
                    // Graceful: fall tillbaka till enhets-scopad lista hellre än tom vy.
                    $minaCaseIds = null;
                }
            }
        }
        $out = [];
        foreach ($this->arendeMapper->findAll(200) as $arende) {
            if (!$this->enhetTillaten($arende->getEnhet())) {
                continue;
            }
            if ($minaCaseIds !== null && !isset($minaCaseIds[$arende->getHubsCaseId()])) {
                continue;
            }
            // AVSLUTADE ärenden visas ALDRIG i arbetsvyn (Fredrik 2026-07-07):
            // akten lever i SoR och Hubs-raden väntar bara på gallringssvepet —
            // den är inte handläggarens arbetsmaterial. ("Klart idag"-pulsen
            // räknar avslutade separat och påverkas inte av detta filter.)
            if ($arende->getSteg() === 'avslutat') {
                continue;
            }
            $out[] = $this->mapToCard($arende);
        }
        return $out;
    }

    /**
     * Map a register row to the dashboard's COLLAPSED card shape (heavy flik fields
     * are added only by {@see mapToFullCard()} on expand). ENGINE-HONEST + THIN:
     * real coordination fields are surfaced; PII/innehåll is left empty, never faked.
     *
     * @return array<string,mixed>
     */
    public function mapToCard(Arende $arende): array {
        return [
            // triageRef = ALLTID hubsCaseId: stabil OCH garanterat unik kort-nyckel.
            // Tidigare 'dnr ?? hubsCaseId' — men dnr är inte garanterat unikt
            // (stubbens per-request-sekvens gav dubbletter; även live kan moduler
            // återanvända serier), och en nyckelkollision blandar frontendens
            // full[]-cache/flikinnehåll mellan kort. dnr visas separat.
            'dnr' => $arende->getDnr(),
            'triageRef' => $arende->getHubsCaseId(),
            // Kort, mänsklig referens ('Ärende 353730') för rubriker — ett
            // oregistrerat ärende får ALDRIG en tom rubrik.
            'kortRef' => self::kortRef($arende->getHubsCaseId()),
            'barnRef' => $arende->getObjektRef(),
            'hubsCaseId' => $arende->getHubsCaseId(),
            'steg' => $arende->getSteg(),
            'substeg' => null,
            'status' => $arende->getStatus(),
            'agareUid' => $arende->getAgareUid(),
            'arendeTyp' => $arende->getArendeTyp(),
            // Engine holds no sekretess classification — neutral, not fabricated.
            'sekretess' => ['kod' => null, 'skyddadeUppgifter' => false],
            'loa' => null,
            'frist' => $this->fristObjekt($arende),
            'provenance' => [
                'state' => $arende->getProvenanceState(),
                'dnr' => $arende->getDnr(),
                'gallrasDatum' => $arende->getGallrasDatum()?->format('Y-m-d'),
                'bevarasDatum' => null,
            ],
            'plikt' => $this->pliktForArende($arende),
            'nastaAtgard' => $this->nastaAtgardForArende($arende),
            'vantar' => null,
            // Tilldelningsläget för kortets TilldelningBand (tidigare saknades
            // blocket helt på motor-data ⇒ bandet renderades aldrig live).
            'tilldelning' => [
                'status' => $arende->getStatus(),
                'agareUid' => $arende->getAgareUid(),
                'agareNamn' => $this->agareVisningsnamn($arende->getAgareUid()),
            ],
            // SAGA-koordinationspekare som /arende-summary kan bära utan en full
            // fetch: ärenderummets diskussions-token + bevaknings-board (= deck-board).
            // THIN + NEVER-SoR: ENDAST id/token, aldrig PII.
            'talkToken' => $this->pekareTalkToken($arende->getHubsCaseId()),
            'bevakningBoardId' => $this->pekareBevakningBoardId($arende->getHubsCaseId()),
            'teamId' => $this->pekareTeamId($arende->getHubsCaseId()),
            // The dashboard can badge this row as real engine state (deliberately thin).
            'kallaMotor' => true,
        ];
    }

    /**
     * Ägarens visningsnamn för TilldelningBand ("Tilldelad Axel Israelsson av …").
     * GRACEFUL: ingen userManager / okänd uid ⇒ null (bandet faller tillbaka på
     * uid-jämförelsen "mig"). Visningsnamn är inte PII-känsligare än uid här —
     * bandet visas bara för ärendets egna medlemmar.
     */
    private function agareVisningsnamn(?string $uid): ?string {
        if ($uid === null || $uid === '' || $this->userManager === null) {
            return null;
        }
        $user = $this->userManager->get($uid);
        return $user !== null ? $user->getDisplayName() : null;
    }

    /**
     * Diskussions-token (talk_room.objekt_id) för kortet, eller null. GRACEFUL:
     * ingen pekareMapper / ingen pekare ⇒ null (aldrig ett kast).
     */
    private function pekareTalkToken(string $hubsCaseId): ?string {
        if ($this->pekareMapper === null) {
            return null;
        }
        foreach ($this->pekareMapper->findByCaseAndTyp($hubsCaseId, 'talk_room') as $p) {
            return $p->getObjektId() !== '' ? $p->getObjektId() : null;
        }
        return null;
    }

    /**
     * Teamets singleId (team.objekt_id) för kortet, eller null — ärenderummets
     * presentationslager i Contacts team-vy. GRACEFUL: ingen pekareMapper /
     * ingen pekare ⇒ null (aldrig ett kast).
     */
    private function pekareTeamId(string $hubsCaseId): ?string {
        if ($this->pekareMapper === null) {
            return null;
        }
        foreach ($this->pekareMapper->findByCaseAndTyp($hubsCaseId, 'team') as $p) {
            return $p->getObjektId() !== '' ? $p->getObjektId() : null;
        }
        return null;
    }

    /**
     * Bevaknings-board (deck_card.riktning = boardId) för kortet, eller null —
     * '' / null ⇒ null, aldrig ett falskt 0. GRACEFUL: ingen pekareMapper /
     * ingen pekare ⇒ null.
     */
    private function pekareBevakningBoardId(string $hubsCaseId): ?int {
        if ($this->pekareMapper === null) {
            return null;
        }
        foreach ($this->pekareMapper->findByCaseAndTyp($hubsCaseId, 'deck_card') as $p) {
            return $this->pekareInt($p->getRiktning());
        }
        return null;
    }

    /**
     * Collapsed card + the (empty) heavy flik fields, for the lazy-load on expand
     * (GET /arende/{ref}). The engine stores no documents/messages/meetings, so those
     * are empty — honest, never fabricated.
     *
     * @return array<string,mixed>
     */
    public function mapToFullCard(Arende $arende): array {
        // gap19 — enumerera ärenderummets groupfolder-filer (namn + ev. fileid).
        [$groupfolderId, $dokument] = $this->arenderumDokument($arende->getHubsCaseId());
        $hubsCaseId = $arende->getHubsCaseId();
        // Ärenderummets medlemmar (ur ledgern) — kortets medlemspanel.
        $medlemmar = [];
        if ($this->memberMapper !== null) {
            try {
                $medlemmar = array_map(
                    static fn ($m): array => ['uid' => $m->getUid(), 'roll' => $m->getRoll()],
                    $this->memberMapper->findByCaseId($hubsCaseId),
                );
            } catch (\Throwable $e) {
                // Graceful — panel utan data hellre än ett fällt kort.
            }
        }
        return $this->mapToCard($arende) + [
            'rum' => ['groupfolderId' => $groupfolderId, 'olasta' => 0, 'acl' => null, 'dokument' => $dokument],
            'meddelanden' => [],
            'moten' => [],
            'bevakningar' => [],
            // A11 — BEVARANDE-panelen: härled beslut+signatur-provenans ur den PERSISTERADE
            // journalen (TYP_REGISTRERAD) så panelen renderas live vid varje fetch, inte
            // bara i commit-svaret. null när ärendet ännu inte är registrerat.
            'beslut' => $this->beslutForCard($arende),
            'medlemmar' => $medlemmar,
            // SAGA-koordinationspekare for deep-länkning (ärenderum/diskussion/
            // ärendekort/kalender). THIN + NEVER-SoR: ENDAST id/token, aldrig PII.
            'pekare' => $this->pekarBlock($hubsCaseId),
        ];
    }

    // ================================================================== //
    //  ADDITIVE READ SURFACES for the hubs_start dashboard
    //  (fördelningsvy + verifierade Treserva-kvittenser).
    //
    //  Both are PURELY ADDITIVE: they reuse the existing object-level authz
    //  predicate (enhetTillaten) and the engine-honest card/frist mappers, and
    //  NEVER touch the saga, commit, ACL or write paths. ENGINE-HONEST + THIN:
    //  only real coordination state is surfaced; PII/innehåll (avsändare,
    //  utredarnamn, dokument) is left empty/neutral, never fabricated. A missing
    //  source on dev15 degrades to a valid, empty-but-well-formed shape — never a
    //  500, never fabricated PII.
    // ================================================================== //

    /**
     * Gruppledarens fördelningsvy for the hubs_start frontend (roll-läge
     * 'fordelning'). Returns the OTILLDELADE cases the caller is authorised for,
     * mapped to the collapsed dashboard card shape, plus the (thin) handläggar-
     * belastning derivable from the register's own assignment state.
     *
     * Shape mirrors ssDemo.fetchFordelningSummary so the existing UI renders it
     * unchanged:
     *   { attFordela: Card[], utredare: [{namn,aktiva,roda,naraTak}], mottagningPagaende:int }
     *
     * ENGINE-HONEST: utredar-belastning is derived ONLY from the register's
     * agareUid + frist colour (real coordination state); no name lookup / PII is
     * fabricated. 'naraTak' is a neutral threshold over the engine's own active
     * count. On any error a valid empty shape is returned (never a 500).
     *
     * @return array<string,mixed>
     */
    public function fordelningSummary(): array {
        try {
            $attFordela = [];
            foreach ($this->arendeMapper->findOtilldelade(200) as $arende) {
                if (!$this->enhetTillaten($arende->getEnhet())) {
                    continue;
                }
                $attFordela[] = $this->mapToFordelningsKort($arende);
            }

            // Thin handläggar-belastning from the register's own assignment state:
            // count active (tilldelat) cases + red frister per agareUid. PII-fritt —
            // the key is the uid the engine already stores (no name/innehåll lookup).
            [$utredare, $mottagningPagaende] = $this->utredarBelastning();

            return [
                'attFordela' => $attFordela,
                'utredare' => $utredare,
                'mottagningPagaende' => $mottagningPagaende,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('hubs_arende: fordelningSummary fel', [
                'app' => 'hubs_arende',
                'exception' => $e,
            ]);
            return ['attFordela' => [], 'utredare' => [], 'mottagningPagaende' => 0];
        }
    }

    /**
     * Verifierade Treserva-kvittenser (kvittens-/retention-ytan) for the hubs_start
     * frontend. The committed cases (provenanceState='registrerad') ARE the
     * verified receipts: each carries a facksystem dnr + a retention deadline
     * (gallrasDatum) bound to a verified commit. Mapped to the receipt-row shape
     * ssDemo.fetchReceipts / treserva.listReceipts expect:
     *   { id, hubsCaseId, dnr, barnRef, typ, committedAt, gallrasDatum, verifierad, kalla }
     *
     * committedAt is approximated by the register's skapad (the engine stores no
     * separate commit timestamp; honest null-fallback when skapad is absent).
     * ENGINE-HONEST + THIN: no innehåll is fabricated. On any error a valid empty
     * list is returned (never a 500).
     *
     * @return list<array<string,mixed>>
     */
    public function treservaReceipts(): array {
        try {
            $out = [];
            foreach ($this->arendeMapper->findRegistrerade(200) as $arende) {
                if (!$this->enhetTillaten($arende->getEnhet())) {
                    continue;
                }
                $skapad = $arende->getSkapad();
                $out[] = [
                    'id' => 'kv-' . $arende->getHubsCaseId(),
                    'hubsCaseId' => $arende->getHubsCaseId(),
                    'dnr' => $arende->getDnr(),
                    // barnRef is the case-object pseudonym (NEVER PII).
                    'barnRef' => $arende->getObjektRef(),
                    // The engine holds no handlings-typ; surface the steg as a thin,
                    // honest stand-in rather than fabricating a document type.
                    'typ' => $arende->getSteg(),
                    // No separate commit timestamp in the register → approximate with
                    // skapad (ISO-8601), honest null when absent.
                    'committedAt' => $skapad instanceof \DateTime
                        ? $skapad->format(\DateTimeInterface::ATOM)
                        : null,
                    'gallrasDatum' => $arende->getGallrasDatum()?->format('Y-m-d'),
                    'verifierad' => true,
                    'kalla' => 'Frends → facksystem (verifierad commit)',
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            $this->logger->error('hubs_arende: treservaReceipts fel', [
                'app' => 'hubs_arende',
                'exception' => $e,
            ]);
            return [];
        }
    }

    /**
     * Map an OTILLDELAT register row to the fördelningsvy card: the collapsed
     * dashboard card + the two fördelnings-specific fields the UI binds
     * (tilldelning.status + ofordeladDagar). Reuses {@see mapToCard()} verbatim so
     * the card stays engine-honest; nothing is fabricated.
     *
     * @return array<string,mixed>
     */
    private function mapToFordelningsKort(Arende $arende): array {
        return $this->mapToCard($arende) + [
            'tilldelning' => ['status' => 'otilldelat'],
            'ofordeladDagar' => $this->ofordeladDagar($arende),
            // Engine holds no handläggar-förslag (the demo's 'forslag' is a triage
            // heuristic, not coordination state) — left neutral, never fabricated.
            'forslag' => null,
        ];
    }

    /**
     * Days a case has been waiting to be fördelad, derived from skapad. 0 when the
     * row carries no skapad timestamp (honest, never negative).
     */
    private function ofordeladDagar(Arende $arende): int {
        $skapad = $arende->getSkapad();
        if (!$skapad instanceof \DateTime) {
            return 0;
        }
        $now = $this->timeFactory->getDateTime();
        return max(0, (int)$skapad->diff($now)->format('%a'));
    }

    /**
     * Thin per-handläggare belastning + mottagningens pågående, derived ONLY from
     * the register's own coordination state (agareUid + status + frist colour). No
     * name resolution / PII — the agareUid the engine already stores is the key.
     *
     *  - utredare[]: { namn:<uid>, aktiva:int, roda:int, naraTak:bool } for every
     *    uid that owns at least one tilldelat case.
     *  - mottagningPagaende: count of cases still in the mottagnings-steg ('inflode'
     *    /'forhandsbedomning') that are NOT yet assigned (the queue the fördelare
     *    works from).
     *
     * @return array{0: list<array<string,mixed>>, 1: int}
     */
    private function utredarBelastning(): array {
        /** @var array<string,array{aktiva:int,roda:int}> $perUid */
        $perUid = [];
        $mottagningPagaende = 0;

        foreach ($this->arendeMapper->findAll(200) as $arende) {
            if (!$this->enhetTillaten($arende->getEnhet())) {
                continue;
            }

            $uid = $arende->getAgareUid();
            if ($arende->getStatus() === 'tilldelat' && $uid !== null && $uid !== '') {
                if (!isset($perUid[$uid])) {
                    $perUid[$uid] = ['aktiva' => 0, 'roda' => 0];
                }
                $perUid[$uid]['aktiva']++;
                if ($this->fristAr('error', $arende)) {
                    $perUid[$uid]['roda']++;
                }
            } elseif (
                $arende->getStatus() === 'otilldelat'
                && in_array($arende->getSteg(), ['inflode', 'forhandsbedomning'], true)
            ) {
                $mottagningPagaende++;
            }
        }

        $utredare = [];
        foreach ($perUid as $uid => $b) {
            $utredare[] = [
                'namn' => $uid,
                'aktiva' => $b['aktiva'],
                'roda' => $b['roda'],
                // Neutral belastnings-tröskel over the engine's own active count.
                'naraTak' => $b['aktiva'] >= 18,
            ];
        }
        // Stable order (busiest first) so the UI list is deterministic.
        usort($utredare, static fn (array $a, array $b): int => $b['aktiva'] <=> $a['aktiva']);

        return [$utredare, $mottagningPagaende];
    }

    /**
     * Whether a case's frist resolves to the given tone (reuses {@see fristObjekt()}
     * so there is one frist-colour source of truth).
     */
    private function fristAr(string $tone, Arende $arende): bool {
        $frist = $this->fristObjekt($arende);
        return $frist !== null && ($frist['tone'] ?? null) === $tone;
    }

    /**
     * The frist object the dashboard's FristChip/FristPanel expect, derived from the
     * engine's fristDue date. Null when the case carries no frist.
     *
     * @return array<string,mixed>|null
     */
    private function fristObjekt(Arende $arende): ?array {
        $due = $arende->getFristDue();
        if (!$due instanceof \DateTime) {
            return null;
        }
        $now = $this->timeFactory->getDateTime();
        // Signed days left: positive when the frist is in the future, negative overdue.
        $daysLeft = -1 * (int)$due->diff($now)->format('%r%a');
        $tone = $daysLeft < 0 ? 'error' : ($daysLeft <= 3 ? 'warning' : 'neutral');
        return [
            'typ' => $arende->getSteg(),
            'label' => $arende->getSteg(),
            'due' => $due->format('Y-m-d'),
            'start' => null,
            'daysLeft' => $daysLeft,
            'tone' => $tone,
            'kalla' => null,
            'agare' => null,
            'paminnelser' => [],
        ];
    }

    /**
     * Assign a case to a handläggare: set agareUid + status=tilldelat, then rewrite
     * the ACL so the assignee gains write and the mottagning krets is revoked
     * (atomic, no sekretess window — GAP-057). The frist is NOT moved.
     *
     * @throws DoesNotExistException
     * @throws DBException
     */
    public function tilldela(string $ref, string $uid): void {
        $arende = $this->show($ref);
        $hubsCaseId = $arende->getHubsCaseId();
        $typ = $this->typRegistry->get($arende->getArendeTyp());

        // En handläggare MÅSTE vara en riktig användare — annars föds spök-rader
        // i ledgern som aldrig når grupp/chatt/kalender (och notisen studsar).
        $this->assertRiktigAnvandare($uid);

        // Säkerhet: validera att assignee är behörig för enheten INNAN ACL-rewrite /
        // kalender-re-home — annars kan ärendets (pseudonyma) kalenderobjekt re-homas
        // in i en GODTYCKLIG användares egen kalender och Deck/ACL pekas om till fel
        // person. Hoppas i system-kontext (CLI/seed/cron) där demo-handläggare används.
        $this->assertAssigneeBehorig($arende, $uid);

        // Three-layer ACL coherence — each layer is isAvailable()-gated and
        // best-effort: a missing neighbour must NEVER block the assignment (the
        // register flip below is the system-of-record act and always happens).
        if ($this->pekareMapper !== null) {
            // Layer 1 — atomic ACL rewrite on the ärenderum Groupfolder
            //   (revoke mottagning, grant assignee write). Re-applies the typ's
            //   acl_profil; the per-uid grant is the GroupfolderClient seam.
            if ($this->groupfolderClient !== null) {
                $aclProfil = (string)($typ?->getAclProfil() ?? '');
                foreach ($this->pekareMapper->findByCaseAndTyp($hubsCaseId, 'groupfolder') as $p) {
                    $this->groupfolderClient->applyAcl((int)$p->getObjektId(), $aclProfil);
                }
            } else {
                $this->skipStep('tilldela:groupfolder', $hubsCaseId);
            }

            // Layer 2 — set the assignee assignment tag on the case via sdkmc.
            if ($this->sdkmcClient !== null) {
                $this->sdkmcClient->tagMessage($hubsCaseId, $this->inflowMessageIds(['assigneeUid' => $uid]));
            } else {
                $this->skipStep('tilldela:sdkmc', $hubsCaseId);
            }

            // Layer 3 — move/label the Deck card to the assignee.
            if ($this->deckClient !== null) {
                foreach ($this->pekareMapper->findByCaseAndTyp($hubsCaseId, 'deck_card') as $p) {
                    $this->deckClient->addLabel(
                        (int)$p->getRiktning(),
                        (int)$p->getObjektId(),
                        'assignee:' . $uid,
                    );
                }
            } else {
                $this->skipStep('tilldela:deck', $hubsCaseId);
            }

            // Layer 4 — re-home the case calendar object into the assignee's OWN
            // calendar (handläggar-ägd kalender). Move from the current owner
            // (pekare.riktning, '' = service account) to $uid and update the pekare's
            // recorded owner. An unknown $uid degrades to the service account inside
            // the client (deterministic), so teardown stays consistent.
            if ($this->calendarClient !== null) {
                foreach ($this->pekareMapper->findByCaseAndTyp($hubsCaseId, 'calendar') as $p) {
                    $from = $p->getRiktning() ?: null;
                    $nyttUri = $this->calendarClient->moveCaseCalendar($hubsCaseId, $from, $uid);
                    if ($nyttUri !== null) {
                        $p->setRiktning($uid);
                        $this->pekareMapper->update($p);
                    }
                }
            } else {
                $this->skipStep('tilldela:kalender', $hubsCaseId);
            }
        } else {
            $this->skipStep('tilldela', $hubsCaseId);
        }

        $arende->setAgareUid($uid);
        $arende->setStatus('tilldelat');
        $this->arendeMapper->update($arende);

        // Förstaklassigt medlemskap: registrera den tilldelade handläggaren som
        // medlem (roll=handlaggare). ADDITIVT — mottagningskretsen behåller sin
        // grupp-åtkomst till groupfolder (groupfolders ger åtkomst per GRUPP, inte
        // per användare; äkta per-handläggar-avsmalning kräver per-case-grupp eller
        // granulära ACL-regler, se Integration/README seam). idempotent via UNIQUE.
        $this->memberMapper?->record($hubsCaseId, $uid, Member::ROLL_HANDLAGGARE);
        // Spegla in handläggaren i per-case-gruppen (folder-åtkomst).
        $this->syncArenderumGrupp($hubsCaseId);

        $this->loggaHandelse($hubsCaseId, Handelse::TYP_TILLDELAD, ['uid' => $uid]);
        $this->skickaNotis($uid, \OCA\HubsArende\Notification\Notifier::SUBJECT_TILLDELAD, [
            'ref' => (string)($arende->getDnr() ?? $hubsCaseId),
        ], $hubsCaseId);

        $this->logger->info('hubs_arende: tilldela', [
            'app' => 'hubs_arende',
            'hubsCaseId' => $arende->getHubsCaseId(),
            'uid' => $uid,
        ]);
    }

    /**
     * Lägg till en MEDLEM i ett befintligt ärenderum (co-handläggare eller
     * observatör) — additivt, så rummet kan ha FLERA samtidiga användare.
     *
     * Registrerar en member-rad (idempotent) och lägger till uid:t som Spreed-
     * deltagare (chat-åtkomst). Groupfolder-åtkomst följer användarens grupp-
     * medlemskap (groupfolders är grupp-baserad). roll begränsas till de roller en
     * operatör får sätta (mottagningskrets sätts ENBART av sagan).
     *
     * @throws DoesNotExistException
     * @throws \InvalidArgumentException Vid otillåten roll.
     */
    public function laggTillMedlem(string $ref, string $uid, string $roll = Member::ROLL_CO_HANDLAGGARE): void {
        if (!in_array($roll, [Member::ROLL_HANDLAGGARE, Member::ROLL_CO_HANDLAGGARE, Member::ROLL_OBSERVATOR], true)) {
            throw new \InvalidArgumentException('Otillåten medlemsroll: ' . $roll);
        }
        // En medlem MÅSTE vara en riktig användare — annars skrivs en spök-rad i
        // ledgern som aldrig når gruppen/chatten (och UI:t ljuger "tillagd").
        $this->assertRiktigAnvandare($uid);
        $arende = $this->show($ref);
        $hubsCaseId = $arende->getHubsCaseId();

        $this->memberMapper?->record($hubsCaseId, $uid, $roll);
        // Spegla in nya medlemmen i per-case-gruppen (folder-åtkomst).
        $this->syncArenderumGrupp($hubsCaseId);

        // Chat-åtkomst: lägg till som deltagare i varje talkrum (kan vara flera).
        if ($this->spreedClient !== null && $this->pekareMapper !== null) {
            foreach ($this->pekareMapper->findByCaseAndTyp($hubsCaseId, 'talk_room') as $p) {
                $this->spreedClient->addParticipant($p->getObjektId(), $uid);
            }
        }

        $this->loggaHandelse($hubsCaseId, Handelse::TYP_MEDLEM, ['uid' => $uid, 'roll' => $roll, 'riktning' => 'in']);
        $this->skickaNotis($uid, \OCA\HubsArende\Notification\Notifier::SUBJECT_MEDLEM, [
            'ref' => (string)($arende->getDnr() ?? $hubsCaseId),
        ], $hubsCaseId);

        $this->logger->info('hubs_arende: laggTillMedlem', [
            'app' => 'hubs_arende',
            'hubsCaseId' => $hubsCaseId,
            'uid' => $uid,
            'roll' => $roll,
        ]);
    }

    /**
     * Ta bort en MEDLEM ur ett ärenderum (revoke en co-handläggare/observatör).
     * Tar bort member-raden och som best-effort Spreed-deltagaren. Avsedd för
     * roller en operatör får hantera; mottagningskrets/handlaggare-borttagning
     * görs via livscykel, inte här.
     *
     * @throws DoesNotExistException
     */
    public function taBortMedlem(string $ref, string $uid, string $roll = Member::ROLL_CO_HANDLAGGARE): void {
        // Symmetriskt med laggTillMedlem: en operatör får bara hantera co-handläggare/
        // observatör. mottagningskrets sätts/rivs av sagan, handlaggare av livscykeln
        // (tilldela) — de får INTE revokas via detta verb (annars kan vilken enhets-
        // användare som helst plocka bort sittande handläggare/krets).
        if (!in_array($roll, [Member::ROLL_CO_HANDLAGGARE, Member::ROLL_OBSERVATOR], true)) {
            throw new \InvalidArgumentException('Roll får inte tas bort via detta verb: ' . $roll);
        }
        $arende = $this->show($ref);
        $hubsCaseId = $arende->getHubsCaseId();

        if ($this->memberMapper !== null) {
            $this->memberMapper->deleteByCaseUidRoll($hubsCaseId, $uid, $roll);
        }
        // Spegla bort medlemmen ur per-case-gruppen (återkalla folder-åtkomst) —
        // om samma uid inte längre har NÅGON roll på ärendet tas den ur gruppen.
        $this->syncArenderumGrupp($hubsCaseId);

        if ($this->spreedClient !== null && $this->pekareMapper !== null) {
            foreach ($this->pekareMapper->findByCaseAndTyp($hubsCaseId, 'talk_room') as $p) {
                $this->spreedClient->removeParticipant($p->getObjektId(), $uid);
            }
        }

        $this->loggaHandelse($hubsCaseId, Handelse::TYP_MEDLEM, ['uid' => $uid, 'roll' => $roll, 'riktning' => 'ut']);

        $this->logger->info('hubs_arende: taBortMedlem', [
            'app' => 'hubs_arende',
            'hubsCaseId' => $hubsCaseId,
            'uid' => $uid,
            'roll' => $roll,
        ]);
    }

    /**
     * Ärenderummets medlemmar (förstaklassiga) — för dashboarden / status.
     *
     * @return list<array{uid:string, roll:string}>
     * @throws DoesNotExistException
     */
    public function medlemmar(string $ref): array {
        $arende = $this->show($ref);
        if ($this->memberMapper === null) {
            return [];
        }
        return array_map(
            static fn ($m): array => ['uid' => $m->getUid(), 'roll' => $m->getRoll()],
            $this->memberMapper->findByCaseId($arende->getHubsCaseId()),
        );
    }

    /**
     * Ärendets HÄNDELSEJOURNAL (äldst först) — datakällan för kortets
     * "Historik & beslut"-tidslinje. H1-authz via show(); bär ENDAST
     * koordinationsvärden (typ, detalj-JSON, aktör-uid, tid) — aldrig PII/innehåll.
     *
     * @return list<array<string,mixed>>
     * @throws DoesNotExistException
     */
    public function historik(string $ref): array {
        $arende = $this->show($ref);
        if ($this->handelseMapper === null) {
            return [];
        }
        try {
            return array_map(
                static fn (Handelse $h): array => $h->jsonSerialize(),
                $this->handelseMapper->findByCaseId($arende->getHubsCaseId()),
            );
        } catch (\Throwable $e) {
            $this->logger->warning('hubs_arende: historik-läsning misslyckades (graceful)', [
                'app' => 'hubs_arende',
                'hubsCaseId' => $arende->getHubsCaseId(),
                'exception' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Ärendets BEVAKNINGAR — läs-projektion av bevaknings-REGISTRET (typ, villkor,
     * status, frist), det förstaklassiga watch-lagret. Byggt för kortets
     * Bevakningar-flik. H1-authz via show(). Koordinationsdata utan PII.
     * Graceful: ingen BevakningService (testharness) ⇒ tom lista.
     *
     * @return list<array<string,mixed>>
     * @throws DoesNotExistException
     */
    public function bevakningar(string $ref): array {
        $arende = $this->show($ref);
        if ($this->bevakningService === null) {
            return [];
        }
        $ut = [];
        foreach ($this->bevakningService->listaForCase($arende->getHubsCaseId()) as $b) {
            $ut[] = $b->jsonSerialize();
        }
        return $ut;
    }

    /**
     * Handläggarskapad ad hoc-bevakning (den tidigare döda "skapa bevakning").
     * H1-authz via show(); aktör = inloggad handläggare.
     *
     * @param array<string,mixed> $data {titel, fristDue?, recurringDagar?, lagstadgad?}
     * @throws DoesNotExistException|\InvalidArgumentException|\RuntimeException
     */
    public function laggTillBevakning(string $ref, array $data): array {
        $arende = $this->show($ref);
        if ($this->bevakningService === null) {
            throw new \RuntimeException('Bevaknings-tjänsten är inte tillgänglig.');
        }
        $uid = $this->userSession?->getUser()?->getUID() ?? '';
        return $this->bevakningService->laggTillManuell($arende->getHubsCaseId(), $data, $uid)->jsonSerialize();
    }

    /**
     * Manuell klarmarkering (kvittering) av en bevakning. H1-authz via show().
     *
     * @throws DoesNotExistException|\InvalidArgumentException|\RuntimeException
     */
    public function kvitteraBevakning(string $ref, int $id): array {
        $arende = $this->show($ref);
        if ($this->bevakningService === null) {
            throw new \RuntimeException('Bevaknings-tjänsten är inte tillgänglig.');
        }
        $uid = $this->userSession?->getUser()?->getUID() ?? '';
        return $this->bevakningService->kvittera($arende->getHubsCaseId(), $id, $uid)->jsonSerialize();
    }

    /**
     * Avbryt en bevakning (ej längre relevant). H1-authz via show().
     *
     * @throws DoesNotExistException|\InvalidArgumentException|\RuntimeException
     */
    public function avbrytBevakning(string $ref, int $id): array {
        $arende = $this->show($ref);
        if ($this->bevakningService === null) {
            throw new \RuntimeException('Bevaknings-tjänsten är inte tillgänglig.');
        }
        $uid = $this->userSession?->getUser()?->getUID() ?? '';
        return $this->bevakningService->avbryt($arende->getHubsCaseId(), $id, $uid)->jsonSerialize();
    }

    /**
     * Sätt delgivningsdatum → föder/uppdaterar överklagandebevakningen (3 v → laga
     * kraft). H1-authz via show().
     *
     * @throws DoesNotExistException|\InvalidArgumentException|\RuntimeException
     */
    public function setDelgivningsdatum(string $ref, string $datum): array {
        $arende = $this->show($ref);
        if ($this->bevakningService === null) {
            throw new \RuntimeException('Bevaknings-tjänsten är inte tillgänglig.');
        }
        $uid = $this->userSession?->getUser()?->getUID() ?? '';
        return $this->bevakningService->setDelgivningsdatum($arende->getHubsCaseId(), $datum, $uid)->jsonSerialize();
    }

    /**
     * 1:n — lägg till YTTERLIGARE ett talkrum i samma ärenderum (samma
     * hubs_case_id). Default-sagan skapar exakt ETT rum (R6); detta är den
     * explicita vägen för fler (t.ex. ett separat samverkans-/sekretess-rum).
     * Deltagare = rummets nuvarande medlemmar. Registrerar en till talk_room-
     * pekare (schemat tillåter 1:n — ingen unik-constraint på (case, objekt_typ)).
     *
     * @return string|null Den nya talkToken, eller null (granne ej tillgänglig).
     * @throws DoesNotExistException
     */
    public function laggTillTalkrum(string $ref, ?string $namn = null): ?string {
        $arende = $this->show($ref);
        $hubsCaseId = $arende->getHubsCaseId();
        if ($this->spreedClient === null || $this->pekareMapper === null) {
            $this->skipStep('extra-talkrum', $hubsCaseId);
            return null;
        }
        // M2 — pseudonymt defaultnamn med KORT ref; eget namn prefixas med
        // ärendereferensen så alla ärendets rum hittas ihop i Talk-listan.
        $rumNamn = $namn !== null && $namn !== ''
            ? 'Ärende ' . self::kortRef($hubsCaseId) . ' – ' . $namn
            : 'Ärende ' . self::kortRef($hubsCaseId) . ' – chatt';
        $token = $this->spreedClient->createRoom($rumNamn, $this->atkomstUids($hubsCaseId));
        if ($token !== null) {
            // riktning bär rummets namn (null för saga-originalet) så kortets
            // Rum-flik kan visa läsbara etiketter utan Talk-uppslag.
            $this->pekareMapper->record($hubsCaseId, 'talk_room', $token, $rumNamn);
            // T-koppling: teamet som deltagare även i EXTRA chattar, så de listas
            // som team-resurser + framtida medlemmar följer med via gruppen.
            // Samma publik som åtkomstlistan — ingen behörighetsbreddning.
            $teamId = $this->pekareTeamId($hubsCaseId);
            if ($teamId !== null) {
                $this->spreedClient->addCircleParticipant($token, $teamId);
            }
            $this->loggaHandelse($hubsCaseId, Handelse::TYP_RUM, ['namngiven' => $namn !== null]);
        }
        $this->logger->info('hubs_arende: laggTillTalkrum', [
            'app' => 'hubs_arende',
            'hubsCaseId' => $hubsCaseId,
            'talkToken' => $token,
        ]);
        return $token;
    }

    /**
     * 1:n — lägg till YTTERLIGARE en groupfolder i samma ärenderum (samma
     * hubs_case_id). Default-sagan skapar exakt EN folder (R4); detta är den
     * explicita vägen för fler (t.ex. en separat förvarings-/utrednings-folder).
     * Registrerar en till groupfolder-pekare.
     *
     * @return int|null Den nya folderId, eller null (granne ej tillgänglig).
     * @throws DoesNotExistException
     */
    public function laggTillGroupfolder(string $ref, ?string $namn = null): ?int {
        $arende = $this->show($ref);
        $hubsCaseId = $arende->getHubsCaseId();
        if ($this->groupfolderClient === null || $this->pekareMapper === null) {
            $this->skipStep('extra-groupfolder', $hubsCaseId);
            return null;
        }
        $typ = $this->typRegistry->get($arende->getArendeTyp());
        // Grant ärendets PER-CASE-grupp (åtkomstlistan), aldrig hela enhetsgruppen —
        // annars läcker den extra foldern till hela enheten (cross-case).
        $perCaseGid = $this->arenderumGroupService?->ensure($hubsCaseId);
        $folderId = $this->groupfolderClient->createArenderum(
            $namn ?? $hubsCaseId,
            (string)($typ?->getAclProfil() ?? ''),
            $perCaseGid !== null ? [$perCaseGid] : [],
        );
        if ($folderId !== null) {
            $this->pekareMapper->record($hubsCaseId, 'groupfolder', (string)$folderId);
        }
        $this->logger->info('hubs_arende: laggTillGroupfolder', [
            'app' => 'hubs_arende',
            'hubsCaseId' => $hubsCaseId,
            'folderId' => $folderId,
        ]);
        return $folderId;
    }

    /**
     * USER-DRIVEN koppling: durably tie one or more MESSAGES to an existing
     * ärenderum by tagging them case:{hubsCaseId} in sdkmc, and record a
     * 'conversation' pekare per message — but ONLY for messages whose tag actually
     * LANDED. This closes the false-coupling gap (the register must never show
     * "kopplat" for a tag that silently 401:ed / no-op:ed).
     *
     * Graceful + honest: missing message ids, sdkmc absent, or an unauthenticated
     * server-to-server call all yield {ok:true, verifierad:false, taggade:0} — the
     * request is accepted (the case exists) but NO coupling pointer is written. The
     * caller can show "kopplat" only when verifierad is true. Idempotent — a
     * re-koppla does not duplicate the conversation pekare.
     *
     * @param string $ref        hubsCaseId or dnr (resolved + H1-authz via show()).
     * @param int[]  $messageIds sdkmc/mail message DB ids to couple.
     * @return array{ok:bool, hubsCaseId:string, taggade:int, verifierad:bool}
     *
     * @throws DoesNotExistException When the case is missing/unauthorised.
     */
    public function kopplaMeddelande(string $ref, array $messageIds): array {
        $arende = $this->show($ref); // existence + H1 enhet-gate FIRST
        $hubsCaseId = $arende->getHubsCaseId();
        $ids = array_values(array_filter(array_map('intval', $messageIds)));

        // F1 — REFERENS i akten: skriv en liten .url-pekare i ärenderummets groupfolder
        // per meddelande (endast djuplänk+id, ingen PII; NEVER-SoR). Detta är den SYNLIGA
        // kopplingen i akten och är oberoende av sdkmc-taggen nedan — den ärver
        // ärenderummets per-case-ACL (Fas E) och följer med vid handoff. Refererade
        // filer städas av ReferensFilService::taBortReferenser (gallring) + groupfolder-
        // rivning (purge/compensation).
        if ($ids !== [] && $this->referensFilService !== null) {
            foreach ($ids as $mid) {
                $this->referensFilService->skrivMeddelandeReferens($hubsCaseId, (string)$mid);
            }
            $this->loggaHandelse($hubsCaseId, Handelse::TYP_KOPPLAD, ['antal' => count($ids)]);
        }

        // BEVAKNING: en kopplad komplettering släcker en komplettering_kopplad-
        // bevakning ("inväntar komplettering"). Best-effort, koordinationsdata.
        if ($ids !== []) {
            $this->bevakningService?->utvardera($hubsCaseId, 'komplettering', ['antal' => count($ids)]);
        }

        // SÄKERHET (IDOR): tagMessage kör server-till-server som SERVICE-KONTOT, som
        // kan tagga VILKET meddelande som helst. Vi får INTE tagga klient-angivna
        // messageIds under den behörigheten utan per-meddelande-authz mot den VERKLIGA
        // slutanvändaren (sdkmc-sidans user-scopade case-tag-route, GAP-019). Tills den
        // finns är den durabla admin-taggningen AVSTÄNGD by default (config
        // 'koppla_admin_tag'=1 för att aktivera i en miljö där per-meddelande-authz
        // garanteras på annat sätt). Default ⇒ verifierad=false, ingen falsk koppling.
        $landed = false;
        if ($ids !== [] && $this->sdkmcClient !== null && $this->kopplaAdminTagAktiverad()) {
            $landed = $this->sdkmcClient->tagMessage($hubsCaseId, $ids);
        } elseif ($ids !== []) {
            $this->logger->info('hubs_arende: koppla durabel tagg AVSTÄNGD (per-meddelande-authz saknas; IDOR-skydd)', [
                'app' => 'hubs_arende',
                'hubsCaseId' => $hubsCaseId,
                'count' => count($ids),
            ]);
        }

        // Skriv 'conversation'-pekare ENBART om taggen landade — och dedupliceradt
        // mot redan kopplade meddelanden (idempotent re-koppla).
        if ($landed && $this->pekareMapper !== null) {
            $redan = [];
            foreach ($this->pekareMapper->findByCaseAndTyp($hubsCaseId, 'conversation') as $p) {
                $redan[$p->getObjektId()] = true;
            }
            foreach ($ids as $mid) {
                if (!isset($redan[(string)$mid])) {
                    $this->pekareMapper->record($hubsCaseId, 'conversation', (string)$mid);
                }
            }
        }

        $this->logger->info('hubs_arende: kopplaMeddelande', [
            'app' => 'hubs_arende',
            'hubsCaseId' => $hubsCaseId,
            'count' => count($ids),
            'verifierad' => $landed,
        ]);

        return [
            'ok' => true,
            'hubsCaseId' => $hubsCaseId,
            'taggade' => $landed ? count($ids) : 0,
            'verifierad' => $landed,
            'referenser' => ($ids !== [] && $this->referensFilService !== null) ? count($ids) : 0,
        ];
    }

    /**
     * Whether durable admin-scoped message tagging is explicitly enabled. OFF by
     * default: tagging client-supplied messageIds under the service account is an
     * IDOR until per-message authz exists (see kopplaMeddelande). Activate only in
     * an environment that guarantees per-message authorization another way:
     *   occ config:app:set hubs_arende koppla_admin_tag --value 1
     */
    private function kopplaAdminTagAktiverad(): bool {
        if ($this->appConfig === null) {
            return false;
        }
        return $this->appConfig->getAppValueString('koppla_admin_tag', '0') === '1';
    }

    /**
     * Commit a case to its destination via the verified commit path. Delegates to
     * {@see FacksystemCommitService}; enriches the payload from the register row +
     * ärendetyp so the port can route to the right facksystem-modul.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed> The verified receipt {ok, dnr, committedAt, gallrasDatum, verifierad}.
     *
     * @throws DoesNotExistException
     */
    public function commit(string $ref, array $payload): array {
        // show() does the existence lookup AND the H1 enhet gate (authz FIRST).
        $arende = $this->show($ref);
        $hubsCaseId = $arende->getHubsCaseId();
        $typ = $this->typRegistry->get($arende->getArendeTyp());

        // H3 — idempotency: if this case is already registered, do NOT call the
        // commit port again (no double facksystem-registrering). Derive a receipt
        // from the persisted dnr/retention so a re-POST is a safe no-op.
        if ($arende->getProvenanceState() === 'registrerad') {
            $this->logger->info('hubs_arende: commit idempotent hit (redan registrerad)', [
                'app' => 'hubs_arende',
                'hubsCaseId' => $hubsCaseId,
                'dnr' => $arende->getDnr(),
            ]);
            return $this->receiptFromRegistered($arende);
        }

        // Enrich the payload with routing fields from coordination state.
        $payload['commit_destination'] = $payload['commit_destination']
            ?? $arende->getCommitDestination();
        $payload['frends_modul'] = $payload['frends_modul']
            ?? ($typ?->getFrendsModul());
        $payload['arendetyp'] = $payload['arendetyp'] ?? $arende->getArendeTyp();
        // H3 — carry a STABLE correlationId (hubsCaseId-based, NOT committedAt-derived)
        // so two in-flight commits for the same case collapse onto one callback token
        // instead of minting a fresh verified receipt per request.
        $payload['correlationId'] = $payload['correlationId'] ?? ('hubs-case:' . $hubsCaseId);

        $kvitto = $this->commitService->commit($hubsCaseId, $payload);

        // On a VERIFIED receipt, flip provenance + dnr + retention (R8-equivalent,
        // bound to the verified callback — never to a checkbox, GAP-007).
        if (($kvitto['verifierad'] ?? false) === true) {
            if (!empty($kvitto['dnr'])) {
                $arende->setDnr((string)$kvitto['dnr']);
            }
            $arende->setProvenanceState('registrerad');
            $arende->setRetentionState('gallras_efter_commit');
            // L2 — persist the gallrings-deadline from the verified receipt so the
            // retention sweep knows NÄR, not just ATT, the row must be gallrad.
            if (!empty($kvitto['gallrasDatum'])) {
                try {
                    $arende->setGallrasDatum(new \DateTime((string)$kvitto['gallrasDatum']));
                } catch (\Throwable $e) {
                    $this->logger->warning('hubs_arende: commit ogiltigt gallrasDatum i kvitto', [
                        'app' => 'hubs_arende',
                        'hubsCaseId' => $hubsCaseId,
                        'gallrasDatum' => $kvitto['gallrasDatum'],
                        'exception' => $e,
                    ]);
                }
            }
            // L3 — make a register-flip FAILURE observable: signal it in the receipt
            // instead of silently swallowing the DB error. On success the receipt is
            // left untouched (a fresh verified kvitto is implicitly registerPersisted).
            // Fail-safe direction is preserved — the row is never deleted early here.
            try {
                $this->arendeMapper->update($arende);
            } catch (DBException $e) {
                $kvitto['registerPersisted'] = false;
                $this->logger->error('hubs_arende: commit register-update fel', [
                    'app' => 'hubs_arende',
                    'hubsCaseId' => $hubsCaseId,
                    'exception' => $e,
                ]);
            }

            // Journal: verifierad facksystem-registrering (dnr + destination).
            // A11 — SIGNATUR-PROVENANS: när facksystemet signerat beslutet (kraverSignering)
            // returnerar kvittot ett 'signatur'-block; bär in dess metadata i journalen så
            // bevarande-panelen (mapToFullCard.beslut) kan renderas live vid varje fetch —
            // inte bara i commit-svaret. PII-fritt: signeratAv = uid/roll-ref, aldrig namn.
            $registreradDetalj = [
                'dnr' => (string)($kvitto['dnr'] ?? ''),
                'destination' => (string)$arende->getCommitDestination(),
            ];
            $signaturDetalj = $this->signaturDetaljFromKvitto($kvitto);
            if ($signaturDetalj !== null) {
                $registreradDetalj['signatur'] = $signaturDetalj;
            }
            $this->loggaHandelse($hubsCaseId, Handelse::TYP_REGISTRERAD, $registreradDetalj);

            // GAP-044 ÄGARSKIFTE: registreringen är VERIFIERAD ⇒ facksystemet äger nu
            // fristerna. Släck commit_registrerad-bevakningar (uppnådda) och avbryt
            // Hubs lagstadgade speglingar så dubbelbevakningen upphör (kortet visar
            // "bevakas i facksystemet"). Interna/manuella bevakningar behålls.
            // Best-effort — ett fel här får aldrig fälla den verifierade commiten.
            if (!empty($kvitto['dnr'])) {
                $this->bevakningService?->bevakasIFacksystem($hubsCaseId, (string)$kvitto['dnr']);
            }

            // gap24 — steg-advancering sker INTE här. Commit = Treserva-registrering
            // (provenans/dnr/retention) och är ORTOGONAL mot livscykel-steget: en
            // förhandsbedömning kan utmynna i "inleda" (→utredning) ELLER "inte inleda"
            // (→avslutat) — backend kan inte gissa beslutet. Frontend (onCommitted)
            // driver den explicita övergången via POST /arende/{id}/steg utifrån
            // handläggarens val. (advanceStegEfterCommit behålls oanvänd som referens.)

            // POST-COMMIT HOOK — kat8 'familjeratt_yttrande'. Registrera tingsrätts-
            // yttrandet EFTER den verifierade commiten. BEST-EFFORT: får ALDRIG fälla
            // det redan verifierade kvittot (samma disciplin som steg-advanceringen).
            // Körs en gång per verifierad commit — en idempotent re-POST kortsluts
            // redan i receiptFromRegistered() ovan och når aldrig hit.
            if ($typ !== null) {
                try {
                    $this->dispatchHook($typ->getPostCommitHook(), $hubsCaseId, $typ, $payload);
                } catch (\Throwable $e) {
                    $this->logger->warning('hubs_arende: postCommitHook misslyckades (graceful)', [
                        'app' => 'hubs_arende',
                        'hubsCaseId' => $hubsCaseId,
                        'hook' => $typ->getPostCommitHook(),
                        'exception' => $e->getMessage(),
                    ]);
                }
            }

            // A12 — PERMANENT PROVENANS: journalen (hubs_handelser) gallras TILLSAMMANS
            // med ärendet, så noten om att beslutet registrerades måste bo utanför
            // Hubs-gallringen. Spegla den verifierade commiten som provenans i
            // facksystemet/e-arkivet (rättskällan vid gallring). BEST-EFFORT — får
            // ALDRIG fälla det redan verifierade kvittot (sparaProvenans returnerar
            // false i stället för att kasta, men vi omsluter ändå defensivt).
            try {
                $signaturDetalj = $this->signaturDetaljFromKvitto($kvitto);
                $provenans = [
                    'moment' => 'beslut',
                    'lagrum' => 'SoL/FL',
                    'utfall' => 'registrerad',
                    'harCommit' => true,
                    'aktorUid' => $this->userSession?->getUser()?->getUID() ?? '',
                    'dnr' => (string)($kvitto['dnr'] ?? ''),
                ];
                // Bär signaturens artefakt-referens (t.ex. PAdES-PDF-pekare) om den finns —
                // aldrig PII, bara en referens.
                if ($signaturDetalj !== null && isset($signaturDetalj['artefaktRef'])) {
                    $provenans['artefaktRef'] = (string)$signaturDetalj['artefaktRef'];
                }
                $this->commitService->sparaProvenans($hubsCaseId, $provenans);
            } catch (\Throwable $e) {
                $this->logger->warning('hubs_arende: commit-provenans misslyckades (graceful)', [
                    'app' => 'hubs_arende',
                    'hubsCaseId' => $hubsCaseId,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return $kvitto;
    }

    /**
     * gap24 — best-effort steg-advancering efter en verifierad commit. Härleder NÄSTA
     * steg ur ALLOWED_TRANSITIONS-grafen och flyttar bara när det finns EXAKT en entydig
     * framåt-kant (forhandsbedomning→utredning, utredning→beslut, beslut→uppfoljning,
     * uppfoljning→avslutat). Idempotent (avslutat/okänt steg ⇒ no-op) och graceful (alla
     * fel sväljs + loggas — får aldrig fälla det redan verifierade kvittot).
     */
    private function advanceStegEfterCommit(Arende $arende): void {
        // Grafen speglar ArendeLifecycleService::ALLOWED_TRANSITIONS (single source of
        // truth där); duplicerad minimalt här för att inte skapa en write-väg-koppling.
        $nasta = [
            'forhandsbedomning' => 'utredning',
            'utredning' => 'beslut',
            'beslut' => 'uppfoljning',
            'uppfoljning' => 'avslutat',
        ];
        $franSteg = $arende->getSteg();
        $tillSteg = $nasta[$franSteg] ?? null;
        if ($tillSteg === null || $tillSteg === $franSteg) {
            // Inget steg, redan avslutat, eller ingen entydig framåt-kant ⇒ idempotent no-op.
            return;
        }
        try {
            $arende->setSteg($tillSteg);
            $this->arendeMapper->update($arende);
            $this->logger->info('hubs_arende: commit steg-advancering', [
                'app' => 'hubs_arende',
                'hubsCaseId' => $arende->getHubsCaseId(),
                'franSteg' => $franSteg,
                'tillSteg' => $tillSteg,
            ]);
        } catch (\Throwable $e) {
            // Graceful: kvittot är redan verifierat och persistat — en misslyckad
            // steg-advancering loggas men fäller aldrig commit:en.
            $this->logger->warning('hubs_arende: commit steg-advancering misslyckades (graceful)', [
                'app' => 'hubs_arende',
                'hubsCaseId' => $arende->getHubsCaseId(),
                'franSteg' => $franSteg,
                'tillSteg' => $tillSteg,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * H3 — derive a verified receipt for an already-registered case from persisted
     * coordination state, WITHOUT re-invoking the commit port (idempotent re-POST).
     *
     * @return array<string,mixed>
     */
    private function receiptFromRegistered(Arende $arende): array {
        $gallrasDatum = $arende->getGallrasDatum();
        return [
            'ok' => true,
            'dnr' => $arende->getDnr(),
            'committedAt' => null,
            'gallrasDatum' => $gallrasDatum?->format('Y-m-d'),
            'verifierad' => true,
            'hubsCaseId' => $arende->getHubsCaseId(),
            'idempotent' => true,
            'registerPersisted' => true,
        ];
    }

    // ================================================================== //
    //  Object-level authorization (H1)
    // ================================================================== //

    /**
     * H1 — enforce enhets-tillhörighet on an existing case (read/assign/commit
     * paths). On an unauthorised caller a {@see DoesNotExistException} is thrown
     * (controller → 404) so the case's existence is not leaked (never 403).
     *
     * SYSTEM/CLI context (no userSession, or no logged-in user — smoke/cron) is
     * ALLOWED: the engine's own background paths are trusted. This is what keeps
     * `occ hubs_arende:smoke` green (it runs without a user session).
     *
     * @throws DoesNotExistException When a real user is not authorised for the enhet.
     */
    public function assertEnhetAtkomst(Arende $arende): void {
        if ($this->isSystemContext()) {
            return;
        }
        if (!$this->enhetTillaten($arende->getEnhet())) {
            // Deny indistinguishably from "does not exist".
            throw new DoesNotExistException('Inget ärende för ref ' . $arende->getHubsCaseId());
        }
    }

    /**
     * H1 — enhets-tillhörighet for the create path (no entity yet). SYSTEM/CLI is
     * allowed; a real user must belong to (or admin) the target enhet, else the
     * create is refused with the same fail-closed semantics.
     *
     * @throws DoesNotExistException When a real user is not authorised for the enhet.
     */
    public function assertEnhetAtkomstForEnhet(?string $enhet): void {
        if ($this->isSystemContext()) {
            return;
        }
        if (!$this->enhetTillaten($enhet)) {
            throw new DoesNotExistException('Ej behörig för enhet.');
        }
    }

    /**
     * True when there is no logged-in user (positional unit harness, CLI smoke,
     * cron). In that case object-level authz is bypassed (trusted system path).
     */
    private function isSystemContext(): bool {
        return $this->userSession === null || $this->userSession->getUser() === null;
    }

    /**
     * Whether the current (real) user may act on an enhet. Admins pass everything;
     * otherwise the normalised enhet must match one of the user's group ids.
     *
     * SYSTEM context returns true (callers that gate on system context separately
     * still get an allow here, used by {@see summary()}).
     *
     * enhet→grupp-mappningen är ett TODO[konfig]; DEFAULT FAIL-CLOSED — en okänd
     * enhet utan matchande grupp nekas.
     */
    private function enhetTillaten(?string $enhet): bool {
        if ($this->isSystemContext()) {
            return true;
        }
        if ($this->groupManager === null) {
            // A real user but no group resolver wired ⇒ fail-closed.
            return false;
        }
        $user = $this->userSession->getUser();
        if ($user === null) {
            return true;
        }
        if ($this->groupManager->isAdmin($user->getUID())) {
            return true;
        }
        $normEnhet = $this->normaliseEnhet($enhet);
        if ($normEnhet === '') {
            return false;
        }
        foreach ($this->groupManager->getUserGroupIds($user) as $gid) {
            if ($this->normaliseEnhet($gid) === $normEnhet) {
                return true;
            }
        }
        return false;
    }

    /**
     * Normalise an enhet/grupp identifier for comparison: strip a trailing '@'
     * (funktionsadress-suffix), trim and lower-case.
     */
    private function normaliseEnhet(?string $value): string {
        $v = trim((string)$value);
        $v = rtrim($v, '@');
        return mb_strtolower($v);
    }

    // ================================================================== //
    //  Internal helpers
    // ================================================================== //

    /**
     * Data-driven saga-hook-dispatch (spec §2.5 "~3 deklarerade hooks", §3.2/§7.1
     * Δ7). ArendeTyp-config-raden namnger en hook via id (getPreSagaHook /
     * getPostCommitHook); detta mappar id:t → en handler-closure. Det finns INGEN
     * if(kategori===N)-gren i motorn — det är hela poängen med "en motor, en saga,
     * N config-rader, ~3 hooks". Okänt/tomt id är en loggad no-op (aldrig ett kast):
     * en kommun kan referera en hook som ett visst bygge inte implementerar utan att
     * ärende-skapandet bryts.
     *
     * @param array<string,mixed> $rad inflöde-/payload-kontext (handlingsmeta).
     * @return array<string,mixed>|null hook-kvitto (pre-hook seedar den föds-
     *         registrerade raden); null när ingen/okänd hook eller inget kvitto.
     */
    private function dispatchHook(?string $hookId, string $hubsCaseId, ArendeTyp $typ, array $rad): ?array {
        if ($hookId === null || $hookId === '') {
            return null;
        }
        $handlers = $this->hookHandlers();
        if (!isset($handlers[$hookId])) {
            $this->logger->info('hubs_arende: okänd saga-hook ignorerad (no-op)', [
                'app' => 'hubs_arende',
                'hookId' => $hookId,
                'hubsCaseId' => $hubsCaseId,
            ]);
            return null;
        }
        return ($handlers[$hookId])($hubsCaseId, $typ, $rad);
    }

    /**
     * Registret av deklarerade saga-hooks: hook-id → handler. De två deklarerade
     * (§2.5): 'diariefor_direkt' (kat6 pre-saga, FGS-diarieföring som led-0) och
     * 'familjeratt_yttrande' (kat8 post-commit, tingsrätts-yttrande). Båda går via
     * samma {@see EdiariumPort} (FGS-Ärende/Diarium). Att lägga till en hook = en
     * rad här + ett config-värde, aldrig en ny gren i sagan.
     *
     * @return array<string, callable(string, ArendeTyp, array<string,mixed>): (array<string,mixed>|null)>
     */
    private function hookHandlers(): array {
        return [
            // kat6 — formell diarieföring DIREKT (allmän handling, OSL 5:1) som
            // led-0 FÖRE ärenderummet. FAIL-CLOSED: en typ vars enda särlogik är
            // direkt-diarieföringen får INTE skapas oregistrerad.
            'diariefor_direkt' => function (string $hubsCaseId, ArendeTyp $typ, array $rad): array {
                if ($this->ediariumPort === null) {
                    throw new \RuntimeException(
                        'diariefor_direkt: e-diarium-porten saknas — kan ej diarieföra ' . $hubsCaseId
                    );
                }
                return $this->ediariumPort->registrera($hubsCaseId, [
                    'handlingstyp' => (string)$typ->getDhpHandlingstyp(),
                    'titel' => $hubsCaseId, // M2 — pseudonym, aldrig PII
                    'riktning' => 'upprattad',
                    'sekretess' => $typ->getSekretessGrund(),
                    'inkomDatum' => $this->parseInkomDatum($rad)->format('Y-m-d'),
                    'arendetyp' => $typ->getArendeTypId(),
                ]);
            },
            // kat8 — yttrande till tingsrätt registreras EFTER verifierad commit.
            // Best-effort: anropas inom commit():s graceful try/catch (får aldrig
            // fälla det redan verifierade kvittot). Port saknas ⇒ loggad no-op.
            'familjeratt_yttrande' => function (string $hubsCaseId, ArendeTyp $typ, array $rad): ?array {
                unset($rad);
                if ($this->ediariumPort === null) {
                    $this->skipStep('post-hook:familjeratt_yttrande', $hubsCaseId);
                    return null;
                }
                return $this->ediariumPort->registrera($hubsCaseId, [
                    'handlingstyp' => (string)$typ->getDhpHandlingstyp(),
                    'titel' => $hubsCaseId,
                    'riktning' => 'upprattad',
                    'sekretess' => $typ->getSekretessGrund(),
                    'inkomDatum' => $this->timeFactory->getDateTime()->format('Y-m-d'),
                    'arendetyp' => $typ->getArendeTypId(),
                ]);
            },
        ];
    }

    /**
     * Enforce the commit_destination NOT NULL invariant: explicit override wins,
     * else the ärendetyp's declared destination; null/empty is rejected.
     */
    private function resolveCommitDestination(array $rad, ArendeTyp $typ): string {
        $override = isset($rad['commitDestination']) ? (string)$rad['commitDestination'] : '';
        $destination = $override !== '' ? $override : $typ->getCommitDestination();

        if ($destination === '') {
            // INVARIANT breach — refuse rather than INSERT a null destination.
            throw new \InvalidArgumentException(
                'commit_destination saknas för ärendetyp ' . $typ->getArendeTypId()
                . ' (NOT NULL-invariant).'
            );
        }

        // L4 — fail-fast at the boundary: a client/typ override must be a member of
        // the canonical allowlist, else reject (controller → 400) rather than INSERT
        // a row that can never reach a committbar rutt and gets stuck aktiv/ogallrad.
        if (!in_array($destination, self::VALID_DESTINATIONS, true)) {
            throw new \InvalidArgumentException(
                'Ogiltig commit_destination "' . $destination . '" — tillåtna: '
                . implode(', ', self::VALID_DESTINATIONS) . '.'
            );
        }
        return $destination;
    }

    /**
     * M1 — POSITIVE pseudonym validation for objektRef/barnRef. The engine's
     * contract is that callers pass pseudonyms (UUID / hash / short token), NEVER
     * PII. Accept only that shape; reject personnummer patterns, names with
     * whitespace, and oversized values with {@see \InvalidArgumentException}
     * (controller → 400). null/empty is allowed (objektRef is optional).
     *
     * @throws \InvalidArgumentException When the value is not a valid pseudonym.
     */
    private function validateObjektRef(?string $ref): void {
        if ($ref === null || $ref === '') {
            return;
        }
        // Hard reject: personnummer / samordningsnummer-mönster (6–8 + ev. -/+ + 4).
        if (preg_match('/\d{6,8}[-+]?\d{4}/', $ref) === 1) {
            throw new \InvalidArgumentException('objektRef ser ut som personnummer — pseudonym krävs.');
        }
        // Hard reject: whitespace (e.g. a name like "Anna Andersson").
        if (preg_match('/\s/u', $ref) === 1) {
            throw new \InvalidArgumentException('objektRef får inte innehålla whitespace — pseudonym krävs.');
        }
        // Length guard (a pseudonym token is short; a free-text value is not).
        if (mb_strlen($ref) > 64) {
            throw new \InvalidArgumentException('objektRef för långt för en pseudonym (max 64).');
        }
        // POSITIVE allow: UUID, hex/base32-hash, or a short alphanumeric token
        // (letters/digits with - _ . : separators). Anything else is rejected.
        $isUuid = preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $ref
        ) === 1;
        $isToken = preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{1,63}$/', $ref) === 1;
        if (!$isUuid && !$isToken) {
            throw new \InvalidArgumentException('objektRef är inte ett giltigt pseudonym-format.');
        }
    }

    /**
     * M2 — soft format validation for triageRef (a kommunal dnr/referens, e.g.
     * 'SN 2026-0142'). It stays a register-only field and is NEVER used as a
     * visible object name, but an obviously-PII value (personnummer) is still
     * rejected at the boundary. null/empty allowed.
     *
     * @throws \InvalidArgumentException When triageRef looks like a personnummer
     *         or is implausibly long for a referens.
     */
    private function validateTriageRef(?string $ref): void {
        if ($ref === null || $ref === '') {
            return;
        }
        if (preg_match('/\d{6,8}[-+]?\d{4}/', $ref) === 1) {
            throw new \InvalidArgumentException('triageRef ser ut som personnummer — referens krävs.');
        }
        if (mb_strlen($ref) > 128) {
            throw new \InvalidArgumentException('triageRef för långt för en referens (max 128).');
        }
    }

    /**
     * gap23 — födelse-steget för ett nyskapat ärende. Case-skapande typer (allt som
     * INTE är en ren triage-destination) föds i 'forhandsbedomning' så stepper-positionen
     * blir korrekt; de rena triage-utan-ärende-destinationerna (triage_forward/karantan)
     * behåller 'inflode'.
     */
    private function fodelseSteg(string $commitDestination): string {
        return in_array($commitDestination, self::NON_FACKSYSTEM_DESTINATIONS, true)
            ? 'inflode'
            : 'forhandsbedomning';
    }

    /**
     * gap21 / A7 — plikt-grinden för kortet. Om ärendetypen deklarerar pliktGrind=true
     * OCH en skyddsbedömning ännu inte är gjord ⇒ {typ:'skyddsbedomning', kvitterad:false},
     * annars null.
     *
     * A7 — CIRKULARITETEN BRUTEN (GAP-U1): tidigare härleddes "skyddsbedömning gjord"
     * ur STEGET (`!in_array(getSteg(),['inflode','forhandsbedomning'])`) — men steget
     * flyttas just genom den grind som plikten ska skydda, så beviset var cirkulärt och
     * kortets röda pliktmarkör ljög. Nu läses beviset ur journalen via EvidensService:
     * skyddsbedömningen anses gjord om det finns en ARTEFAKT ur mall (handling vars
     * mall-id matchar 'skyddsbedom') ELLER en journalförd KVITTENS/grindval för momentet.
     * Detta speglar frontendens harledStatus (arendeFlow.js) så kort och stepper aldrig
     * divergerar.
     *
     * FAIL-OPEN: saknas evidensService (positionell testharness) faller vi tillbaka på
     * det gamla steg-baserade beteendet så de befintliga testerna förblir gröna.
     * EvidensService är i sig fail-open vid läsfel (låser aldrig ute en handläggare).
     *
     * @return array{typ:string, kvitterad:bool}|null
     */
    private function pliktForArende(Arende $arende): ?array {
        $typ = $this->typRegistry->get($arende->getArendeTyp());
        if ($typ === null || $typ->getPliktGrind() !== true) {
            return null;
        }
        $hubsCaseId = $arende->getHubsCaseId();
        if ($this->evidensService !== null) {
            // A7 — honest bevis ur journalen (artefakt ur mall ELLER kvittens/grindval).
            $skyddsbedomningGjord =
                $this->evidensService->harArtefakt($hubsCaseId, 'skyddsbedomning')
                || $this->evidensService->harKvittens($hubsCaseId, 'skyddsbedomning');
        } else {
            // Fail-open: gammalt steg-baserat beteende när evidensService ej wirad
            // (behåller den positionella testharnessen grön).
            $skyddsbedomningGjord = !in_array($arende->getSteg(), ['inflode', 'forhandsbedomning'], true);
        }
        if ($skyddsbedomningGjord) {
            return null;
        }
        return ['typ' => 'skyddsbedomning', 'kvitterad' => false];
    }

    /**
     * A11 — normalisera signatur-metadatan ur ett verifierat commit-kvitto till den
     * form som journalförs i TYP_REGISTRERAD.detalj.signatur och renderas i
     * bevarande-panelen. Facksystemet returnerar blocket när beslutet krävde signering
     * (kraverSignering). Honest null när kvittot saknar signatur.
     *
     * PII-invariant: signeratAv är en uid-/roll-referens, ALDRIG ett namn. Endast
     * kända, PII-fria nycklar plockas in (format/pdfa/ltv/signeratAv/tid[/artefaktRef]).
     *
     * @param array<string,mixed> $kvitto
     * @return array<string,mixed>|null
     */
    private function signaturDetaljFromKvitto(array $kvitto): ?array {
        $sig = $kvitto['signatur'] ?? null;
        if (!is_array($sig) || $sig === []) {
            return null;
        }
        $detalj = [
            'format' => (string)($sig['format'] ?? ''),
            'pdfa' => (bool)($sig['pdfa'] ?? false),
            'ltv' => (bool)($sig['ltv'] ?? false),
            'signeratAv' => (string)($sig['signeratAv'] ?? ''),
            'tid' => (string)($sig['tid'] ?? ''),
        ];
        // Valfri artefakt-referens (t.ex. PAdES-PDF-pekare) — aldrig PII, bara en referens.
        if (isset($sig['artefaktRef']) && $sig['artefaktRef'] !== '') {
            $detalj['artefaktRef'] = (string)$sig['artefaktRef'];
        }
        return $detalj;
    }

    /**
     * A11 — bevarande-panelens beslutsblock för mapToFullCard, härlett ur den
     * PERSISTERADE journalen (senaste TYP_REGISTRERAD) + register-raden så panelen
     * renderas live vid varje fetch (inte bara i commit-svaret). Honest null när
     * ärendet ännu inte är registrerat (ingen commit ⇒ inget beslut att bevara).
     *
     * Signatur-provenansen (om beslutet signerats) plockas ur samma journalrad så
     * "signerat / PDF/A / LTV"-chippen speglar det facksystemet faktiskt kvitterade.
     * THIN + NEVER-SoR: enbart koordinations-/provenansvärden, aldrig PII/sakinnehåll.
     *
     * @return array<string,mixed>|null
     */
    private function beslutForCard(Arende $arende): ?array {
        // Ett beslut att bevara existerar först när ärendet är verifierat registrerat.
        if ($arende->getProvenanceState() !== 'registrerad') {
            return null;
        }
        $signatur = null;
        if ($this->handelseMapper !== null) {
            try {
                // findByCaseId är ASC (äldst först) ⇒ sista sedda TYP_REGISTRERAD vinner
                // (nyaste registreringen), samma "sista vinner"-mönster som pekarBlock.
                foreach ($this->handelseMapper->findByCaseId($arende->getHubsCaseId(), 500) as $h) {
                    if ($h->getTyp() !== Handelse::TYP_REGISTRERAD) {
                        continue;
                    }
                    $d = $this->handelseDetalj($h);
                    if (isset($d['signatur']) && is_array($d['signatur'])) {
                        $signatur = $d['signatur'];
                    }
                }
            } catch (\Throwable $e) {
                // Graceful — panel utan signatur-chip hellre än ett fällt kort.
                $this->logger->warning('hubs_arende: beslutForCard journal-läsfel (graceful)', [
                    'app' => 'hubs_arende',
                    'hubsCaseId' => $arende->getHubsCaseId(),
                    'exception' => $e->getMessage(),
                ]);
            }
        }
        return [
            'dnr' => $arende->getDnr(),
            'destination' => $arende->getCommitDestination(),
            'provenans' => $arende->getProvenanceState(),
            // Signatur-provenans (null när beslutet inte krävde/har signering).
            'signatur' => $signatur,
            'signerat' => $signatur !== null,
        ];
    }

    /**
     * Avkoda en journalrads detalj-JSON till en array (tomt vid saknad/ogiltig JSON).
     * Speglar EvidensService::detalj — ingen ny lagring, bara avläsning.
     *
     * @return array<string,mixed>
     */
    private function handelseDetalj(Handelse $h): array {
        $raw = $h->getDetalj();
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * gap22 — process-mall-driven "Nästa åtgärd": ärendetypens deklarerade
     * getForstaAtgard() (första åtgärden i process-mallen). Honest null när typen/
     * fältet saknas — inget fabriceras.
     */
    private function nastaAtgardForArende(Arende $arende): ?string {
        $typ = $this->typRegistry->get($arende->getArendeTyp());
        if ($typ === null) {
            return null;
        }
        $forsta = $typ->getForstaAtgard();
        return ($forsta !== null && $forsta !== '') ? $forsta : null;
    }

    /**
     * SAGA-pekarblock for the full card — surfaces the case's coordination
     * pointers (ärenderum-token, groupfolder-id, deck-board/-kort, kalender-uri,
     * conversation) so the GUI can deep-länka utan en egen pekare-fråga. THIN +
     * NEVER-SoR: ENDAST koordinationsstate (id/token), aldrig PII/innehåll.
     *
     * findByCaseId() är id-DESC (nyast först); vi behåller det SIST sedda värdet
     * per typ medan vi itererar ⇒ SAGA-ORIGINALET (äldsta) väljs, även om en pekare
     * råkat skrivas om. GRACEFUL: ingen pekareMapper ⇒ TOM_PEKARE (alla null).
     *
     * @return array<string,mixed>
     */
    private function pekarBlock(string $hubsCaseId): array {
        if ($this->pekareMapper === null) {
            return self::TOM_PEKARE;
        }
        $block = self::TOM_PEKARE;
        $deckBoardId = null;
        $talkRooms = [];
        foreach ($this->pekareMapper->findByCaseId($hubsCaseId) as $p) {
            // id-DESC ⇒ skriv varje typ varv för varv; sista (= äldsta) vinner.
            switch ($p->getObjektTyp()) {
                case 'talk_room':
                    $block['talkToken'] = $p->getObjektId() !== '' ? $p->getObjektId() : null;
                    if ($p->getObjektId() !== '') {
                        // Samla ALLA rum (id-DESC här; vänds till äldst-först nedan).
                        $talkRooms[] = [
                            'token' => $p->getObjektId(),
                            'namn' => $p->getRiktning() !== null && $p->getRiktning() !== '' ? $p->getRiktning() : null,
                        ];
                    }
                    break;
                case 'groupfolder':
                    $block['groupfolderId'] = $this->pekareInt($p->getObjektId());
                    break;
                case 'conversation':
                    $block['conversationId'] = $p->getObjektId() !== '' ? $p->getObjektId() : null;
                    break;
                case 'deck_card':
                    // riktning = boardId, objekt_id = cardId (samma som R5-skrivningen).
                    $deckBoardId = $this->pekareInt($p->getRiktning());
                    $block['deckBoardId'] = $deckBoardId;
                    $block['deckCardId'] = $this->pekareInt($p->getObjektId());
                    break;
                case 'calendar':
                    $block['calendarUri'] = $p->getObjektId() !== '' ? $p->getObjektId() : null;
                    break;
                case 'team':
                    $block['teamId'] = $p->getObjektId() !== '' ? $p->getObjektId() : null;
                    break;
            }
        }
        // bevakningBoardId speglar deck-boardet (bevakningar bor på ärendekortets board).
        $block['bevakningBoardId'] = $deckBoardId;
        // Äldst först (saga-originalet = ärendets diskussion) — Rum-flikens ordning.
        $block['talkRooms'] = array_reverse($talkRooms);
        return $block;
    }

    /**
     * Casta ett pekare-fält (text-kolumn) till int, men behåll null/'' som null —
     * en saknad pekare blir ALDRIG ett falskt 0.
     */
    private function pekareInt(?string $value): ?int {
        return ($value === null || $value === '') ? null : (int)$value;
    }

    /**
     * gap19 — enumerera ärenderummets groupfolder-filer. Resolverar foldern via
     * Pekare(objekt_typ='groupfolder') + groupfolders' jail-path '__groupfolders/{id}'
     * (samma mönster som ReferensFilService). GRACEFUL: ingen IRootFolder/pekare/folder
     * eller fel ⇒ [0, []] (tom lista, aldrig ett kast).
     *
     * @return array{0:int, 1:list<array{namn:string, fileid:int|null}>}
     */
    private function arenderumDokument(string $hubsCaseId): array {
        if ($this->rootFolder === null || $this->pekareMapper === null) {
            return [0, []];
        }
        $folderId = 0;
        foreach ($this->pekareMapper->findByCaseAndTyp($hubsCaseId, 'groupfolder') as $p) {
            $folderId = (int)$p->getObjektId();
            break;
        }
        if ($folderId === 0) {
            return [0, []];
        }
        $dokument = [];
        try {
            $node = $this->rootFolder->get('__groupfolders/' . $folderId);
            if ($node instanceof Folder) {
                foreach ($node->getDirectoryListing() as $child) {
                    $dokument[] = [
                        'namn' => $child->getName(),
                        'fileid' => $child->getId(),
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Graceful: foldern ej resolverbar / läsfel ⇒ tom dokumentlista.
            $this->logger->debug('hubs_arende: arenderumDokument — groupfolder ej läsbar (graceful)', [
                'app' => 'hubs_arende',
                'hubsCaseId' => $hubsCaseId,
                'folderId' => $folderId,
            ]);
            return [$folderId, []];
        }
        return [$folderId, $dokument];
    }

    private function buildEntity(string $hubsCaseId, array $rad, ArendeTyp $typ, string $commitDestination, ?array $preHookKvitto = null): Arende {
        $arende = new Arende();
        $arende->setHubsCaseId($hubsCaseId);
        // M2 — triageRef is a register-only field; validate softly against a
        // dnr/referens-pattern so an obviously-PII value is rejected at the boundary.
        $triageRef = isset($rad['triageRef']) ? (string)$rad['triageRef'] : null;
        $this->validateTriageRef($triageRef);
        $arende->setTriageRef($triageRef);
        // Provenance anchor / idempotency key.
        $arende->setConversationId(
            isset($rad['conversationId']) && $rad['conversationId'] !== '' ? (string)$rad['conversationId'] : null
        );
        // M1 — objektRef is a pseudonym (barnRef) — NEVER PII. Validate POSITIVELY:
        // require a pseudonym format and reject personnummer / names / oversized.
        $objektRef = isset($rad['objektRef']) ? (string)$rad['objektRef']
            : (isset($rad['barnRef']) ? (string)$rad['barnRef'] : null);
        $this->validateObjektRef($objektRef);
        $arende->setObjektRef($objektRef);
        $arende->setEnhet(
            isset($rad['enhet']) && $rad['enhet'] !== '' ? (string)$rad['enhet'] : $typ->getDefaultEnhet()
        );
        $arende->setAgareUid(null);
        $arende->setStatus('otilldelat');
        // gap23 — föd case-skapande typer i 'forhandsbedomning' (rätt stepper-position);
        // rena triage-destinationer (triage_forward/karantan) föds i 'inflode'. R10
        // sätter samma värde (idempotent) efter att pekarna skrivits.
        $arende->setSteg($this->fodelseSteg($commitDestination));
        $arende->setDnr(null);
        $arende->setProvenanceState('ej_registrerad');
        $arende->setCommitDestination($commitDestination);
        $arende->setRetentionState('aktiv');
        $arende->setArendeTyp($typ->getArendeTypId());
        $arende->setSkapad($this->timeFactory->getDateTime());
        // kat6 'diariefor_direkt': ärendet FÖDS registrerat (omvänd ordning mot
        // kat1) — provenans + dnr seedas ur diarieförings-kvittot (pre-saga-hook).
        // Idempotent compose: en senare commit() kortsluts då av
        // receiptFromRegistered() (H3) och dubbel-registrerar aldrig.
        if ($preHookKvitto !== null && ($preHookKvitto['ok'] ?? false) === true) {
            if (($preHookKvitto['provenanceState'] ?? '') === 'registrerad') {
                $arende->setProvenanceState('registrerad');
            }
            $diarienummer = (string)($preHookKvitto['diarienummer'] ?? '');
            if ($diarienummer !== '') {
                $arende->setDnr($diarienummer);
            }
        }
        return $arende;
    }

    /**
     * Compute the frist due date from the ärendetyp's fristPolicy, anchored to the
     * inkom-datum (NOT now()). Returns null when the policy carries no own clock
     * (koordinering / arver / speglasUrTreserva).
     */
    private function computeFristDue(array $rad, ArendeTyp $typ): ?\DateTime {
        $policy = $this->decodeFristPolicy($typ->getFristPolicy());
        $policyTyp = (string)($policy['typ'] ?? 'ingen');

        // Frist mirrored from the facksystem (Frends) — not counted here.
        if (($policy['speglasUrTreserva'] ?? false) === true) {
            return null;
        }
        // No own clock for these policy kinds.
        if (in_array($policyTyp, ['ingen', 'koordinering', 'arver'], true)) {
            return null;
        }

        // Anchor on inkom-datum (provenance), falling back to now() only if absent.
        $anchor = $this->parseInkomDatum($rad);

        $days = match ($policyTyp) {
            '14d_forhandsbedomning' => 14,
            'manadscykel' => 30,
            'forvaltningsratt_skyndsam' => 21,
            default => null,
        };
        if ($days === null) {
            return null;
        }

        return (clone $anchor)->add(new \DateInterval('P' . $days . 'D'));
    }

    /**
     * Decode the ärendetyp's fristPolicy JSON text into an array.
     *
     * @return array<string,mixed>
     */
    private function decodeFristPolicy(?string $json): array {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function parseInkomDatum(array $rad): \DateTime {
        $raw = $rad['inkomDatum'] ?? $rad['inkom_datum'] ?? null;
        if (is_string($raw) && $raw !== '') {
            try {
                return new \DateTime($raw);
            } catch (\Throwable) {
                // fall through to now()
            }
        }
        return $this->timeFactory->getDateTime();
    }

    /**
     * Mint a UUID v4 using NC's ISecureRandom (CSPRNG), formatted 8-4-4-4-12 with
     * the version/variant bits set per RFC 4122.
     */
    private function mintUuidV4(): string {
        $hex = bin2hex($this->secureRandom->generate(
            16,
            ISecureRandom::CHAR_DIGITS . 'abcdef',
        ));
        // Force version (4) and variant (8/9/a/b) nibbles.
        $bytes = str_split($hex, 2);
        $bytes[6] = dechex((hexdec($bytes[6]) & 0x0f) | 0x40);
        $bytes[6] = str_pad($bytes[6], 2, '0', STR_PAD_LEFT);
        $bytes[8] = dechex((hexdec($bytes[8]) & 0x3f) | 0x80);
        $bytes[8] = str_pad($bytes[8], 2, '0', STR_PAD_LEFT);
        $hex = implode('', $bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }

    /**
     * Run the compensation stack in reverse order (n-1..1). Each compensation is
     * best-effort: a failing compensation is logged but does not abort the rest.
     *
     * @param array<int, array{name:string, fn:callable():void}> $compensations
     */
    private function compensate(array $compensations, string $hubsCaseId, \Throwable $cause): void {
        $this->logger->error('hubs_arende: saga FEL — kör kompensering', [
            'app' => 'hubs_arende',
            'hubsCaseId' => $hubsCaseId,
            'steps' => count($compensations),
            'cause' => $cause->getMessage(),
        ]);
        foreach (array_reverse($compensations) as $comp) {
            try {
                ($comp['fn'])();
                $this->logger->info('hubs_arende: kompenserade ' . $comp['name'], [
                    'app' => 'hubs_arende',
                    'hubsCaseId' => $hubsCaseId,
                ]);
            } catch (\Throwable $e) {
                $this->logCompensationFailure($comp['name'], $e);
            }
        }
    }

    /**
     * Resolve the inflow funktionsadress used to address the sdkmc tag account.
     *
     * @param array<string,mixed> $rad
     */
    private function inflowEmail(array $rad): string {
        return (string)($rad['enhetEmail'] ?? $rad['email'] ?? $rad['funktionsadress'] ?? '');
    }

    /**
     * Normalise the inflow message id(s) to tag/untag (R9) to an int list.
     *
     * @param array<string,mixed> $rad
     * @return int[]
     */
    private function inflowMessageIds(array $rad): array {
        $ids = $rad['messageIds'] ?? (isset($rad['messageId']) ? [$rad['messageId']] : []);
        return array_values(array_filter(array_map('intval', (array)$ids)));
    }

    /**
     * The mottagningskrets uids — the room's members AND the Spreed participants
     * for R6. Derived from the enhet's NC group(s): each user of every group whose
     * normalised id matches the case's enhet (the same enhet↔grupp convention used
     * by {@see enhetTillaten()}). De-duplicated across groups.
     *
     * Fail-closed/graceful: no groupManager, or no matching enhet-group, ⇒ empty
     * (the room is created with no broad access rather than a wrong krets). $typ is
     * reserved for a future per-typ krets refinement.
     *
     * @return string[] distinct member uids
     */
    private function aclKretsUids(Arende $arende, ?ArendeTyp $typ = null): array {
        unset($typ);
        if ($this->groupManager === null) {
            return [];
        }
        $uids = [];
        foreach ($this->resolveEnhetGroups($arende->getEnhet()) as $gid) {
            $group = $this->groupManager->get($gid);
            if ($group === null) {
                continue;
            }
            foreach ($group->getUsers() as $user) {
                // Key by uid so a user in several enhet-groups is counted once.
                $uids[$user->getUID()] = true;
            }
        }
        // strval: a purely-numeric uid (e.g. a personnummer-baserad uid like
        // '197411040293') becomes an INT array key in PHP; cast back so every
        // returned uid is a string (MemberMapper::record requires string).
        return array_map('strval', array_keys($uids));
    }

    /**
     * Säkerhets-gate för tilldelning: assignee MÅSTE vara behörig för ärendets
     * enhet (medlem av enhetens mottagningskrets) innan vi pekar om ACL/Deck och
     * re-homar kalenderobjektet till deras kalender.
     *
     * Hoppas i SYSTEM-kontext (CLI/seed/cron — ingen session, demo-handläggare som
     * 'demo-hl-*' tillåts) och när ingen groupManager finns (kan ej resolva). På
     * den riktiga OCS-vägen (inloggad användare) krävs medlemskap, annars
     * {@see \InvalidArgumentException} (controller → 400).
     */
    private function assertAssigneeBehorig(Arende $arende, string $uid): void {
        if ($this->isSystemContext() || $this->groupManager === null) {
            return;
        }
        if (in_array($uid, $this->aclKretsUids($arende), true)) {
            return;
        }
        throw new \InvalidArgumentException('Tilldelad handläggare är inte behörig för ärendets enhet.');
    }

    /**
     * Resolve the NC group ids whose normalised id equals the case's enhet — the
     * mottagningskrets's owning group(s). Mirrors {@see enhetTillaten()}'s
     * enhet↔grupp convention via {@see normaliseEnhet()} (strip trailing '@',
     * trim, lower-case).
     *
     * Tries the direct hit (a group named exactly the normalised enhet) first, then
     * a normalised search for funktionsadress-variants (e.g. a 'barn-familj@' gid).
     * Empty when no groupManager or no match (fail-closed).
     *
     * @return string[] matching NC group ids (gids)
     */
    private function resolveEnhetGroups(?string $enhet): array {
        if ($this->groupManager === null) {
            return [];
        }
        $norm = $this->normaliseEnhet($enhet);
        if ($norm === '') {
            return [];
        }
        // Fast path: a group named exactly the normalised enhet (e.g. 'barn-familj').
        $direct = $this->groupManager->get($norm);
        if ($direct !== null) {
            return [$direct->getGID()];
        }
        // Variant path: a gid that normalises to the same value (e.g. 'Barn-Familj@').
        $gids = [];
        foreach ($this->groupManager->search($norm) as $group) {
            if ($this->normaliseEnhet($group->getGID()) === $norm) {
                $gids[$group->getGID()] = true;
            }
        }
        return array_keys($gids);
    }

    /**
     * Spegla member-ledgern in i ärenderummets per-case-grupp (åtkomstlistan), så
     * folder-åtkomsten alltid matchar exakt de aktuella medlemmarna. Anropas efter
     * VARJE member-mutation (tilldela / laggTillMedlem / taBortMedlem). Graceful no-op
     * utan grupp-service/ledger.
     */
    private function syncArenderumGrupp(string $hubsCaseId): void {
        $this->arenderumGroupService?->sync($hubsCaseId, $this->atkomstUids($hubsCaseId));
    }

    /**
     * Ärenderummets AKTIVA åtkomstlista (handoff-medveten), härledd ur member-
     * ledgern: så länge ärendet är OTILLDELAT ser mottagningskretsen det; så snart
     * en handläggare (eller co-handläggare/observatör) finns ÖVERGÅR åtkomsten till
     * dem och mottagningskretsen REVOKAS (OSL 26 kap inre sekretess; GAP-057). Detta
     * är källan för per-case-gruppen, så folder-/rums-åtkomsten följer handoff:en.
     *
     * @return string[] distinkta uid:n med aktiv åtkomst
     */
    private function atkomstUids(string $hubsCaseId): array {
        if ($this->memberMapper === null) {
            return [];
        }
        $handlaggare = [];
        $krets = [];
        foreach ($this->memberMapper->findByCaseId($hubsCaseId) as $m) {
            $uid = (string)$m->getUid();
            if (in_array($m->getRoll(), [Member::ROLL_HANDLAGGARE, Member::ROLL_CO_HANDLAGGARE, Member::ROLL_OBSERVATOR], true)) {
                $handlaggare[$uid] = true;
            } elseif ($m->getRoll() === Member::ROLL_MOTTAGNINGSKRETS) {
                $krets[$uid] = true;
            }
        }
        // Handoff: finns en handläggare ÄR de åtkomstlistan (kretsen revokeras);
        // annars (otilldelat) ser mottagningskretsen ärendet.
        $aktiva = $handlaggare !== [] ? $handlaggare : $krets;
        return array_map('strval', array_keys($aktiva));
    }

    /**
     * Log a gracefully-skipped saga side-effect step (client/pekareMapper absent or
     * the app is not enabled). The saga continues — a missing neighbour must never
     * fell createCase.
     */
    private function skipStep(string $step, string $hubsCaseId): void {
        $this->logger->info('hubs_arende: hoppar ' . $step . ' (granne ej tillgänglig, graceful)', [
            'app' => 'hubs_arende',
            'hubsCaseId' => $hubsCaseId,
        ]);
    }

    private function noopCompensation(string $step, string $hubsCaseId): void {
        // Placeholder while the external forward-call is a TODO: there is no side
        // effect yet to undo. Kept as a closure so the saga shape is complete and
        // wiring the real call only means filling both the forward + this body.
        $this->logger->debug('hubs_arende: kompensering ' . $step . ' (no-op, extern anrop ej wirad)', [
            'app' => 'hubs_arende',
            'hubsCaseId' => $hubsCaseId,
        ]);
    }

    private function logCompensationFailure(string $step, \Throwable $e): void {
        $this->logger->critical('hubs_arende: KOMPENSERING MISSLYCKADES för ' . $step
            . ' — manuell städning kan krävas', [
                'app' => 'hubs_arende',
                'exception' => $e,
            ]);
    }
}
