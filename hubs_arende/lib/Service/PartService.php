<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Service;

use OCA\HubsArende\Db\Arende;
use OCA\HubsArende\Db\Handelse;
use OCA\HubsArende\Db\HandelseMapper;
use OCA\HubsArende\Db\Part;
use OCA\HubsArende\Db\PartMapper;
use OCA\HubsArende\Integration\Port\FolkbokforingPort;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * PARTSREGISTRET — authz-grindad CRUD över ärendets parter plus Navet-uppslaget
 * (folkbokföringsverifiering) in i registret (K-NAV-4.x).
 *
 * PII-DOKTRIN: oc_hubs_arende_part är motorns ENDA sanktionerade PII-tabell
 * (beslut Fredrik 2026-07-06, se hubs_start/docs/ANALYS-HANDLING-FRAN-MALL.md
 * §3.4): TRANSIENT arbetsdata som gallras med ärendet ({@see GallringService}),
 * ALDRIG system-of-record. Personnummer/namn/adress får ALDRIG skrivas till
 * loggar (LoggerInterface) eller till Händelse.detalj — journalen och loggarna
 * bär enbart antal / korrelationsId / roll / källa / skydd, aldrig identitet.
 * Att VISA PII för en BEHÖRIG handläggare är däremot avsett — invarianten är
 * behörighetsgränsen, inte PII-gömning (jfr hubs-pii-authorization-principle);
 * därför returnerar metoderna hela partsposter till den authz-grindade anroparen.
 *
 * H1-AUTHZ: varje publik metod går genom {@see ArendeService::show()} FÖRST —
 * den kör assertEnhetAtkomst och kastar {@see DoesNotExistException} vid
 * saknat/obehörigt ärende (controller → 404, existens läcker inte). Metoder
 * som tar ett rad-id verifierar dessutom att raden tillhör DET ärendet
 * (IDOR-guard) — ett id ur ett annat ärende ger samma DoesNotExistException.
 *
 * FAIL-CLOSED SKYDD: fältet `skydd` (ingen | sekretessmarkering |
 * skyddad_folkbokforing) är OBLIGATORISKT i varje personpost — saknat/okänt
 * värde kastar, ALDRIG default {@see Part::SKYDD_INGEN}. Porten garanterar
 * redan detta ({@see FolkbokforingPort}); denna service kör samma kontroll en
 * gång till som en andra, oberoende vakt (defense in depth). Vid
 * {@see Part::SKYDD_SKYDDAD_FOLKBOKFORING} lagras den verkliga adressen ALDRIG
 * — `adress` sätts OVILLKORLIGEN till null (även om klienten skulle leverera
 * något) och endast `sarskildPostadress` (Skatteverkets förmedlingsadress) får
 * lagras (K-NAV-5.2).
 *
 * AUDIT (K-NAV-4.2): Skatteverket loggar inte på användarnivå — hela
 * audit-ansvaret är vårt. Varje uppslag myntar därför ett korrelationsId
 * (UUID v4) som bärs till porten (skv_client_correlation_id) OCH in i
 * händelsejournalen, så uppslaget kan bindas till vem/vilket ärende/vilket
 * ändamål utan att identiteten själv hamnar i journalen.
 *
 * RÄTTELSE-GARANTIN (K-NAV-4.4): uppslaget är en UPSERT på
 * (ärende, personnummer, roll) — en omkörning uppdaterar den befintliga raden
 * (namn/adress/skydd/status + verifierad-stämpel) i stället för att duplicera,
 * så en rättelse i folkbokföringen alltid slår igenom i registret.
 */
class PartService {
    public function __construct(
        private ArendeService $arendeService,
        private PartMapper $partMapper,
        private FolkbokforingPort $folkbokforing,
        private LoggerInterface $logger,
        // TRAILING OPTIONAL (autowired): händelsejournalen. Best-effort — ett
        // journal-fel får ALDRIG fälla den mutation det beskriver. Null enbart
        // i en positionell test-harness ⇒ journalen är en graceful skip.
        private ?HandelseMapper $handelseMapper = null,
        // TRAILING OPTIONAL (autowired): aktören bakom mutationen ('' =
        // system/CLI-kontext utan session).
        private ?IUserSession $userSession = null,
        // TRAILING OPTIONAL (autowired): per-part-delgivningen fostrar ärendets
        // överklagandebevakning (laga kraft = senaste partens frist). Null i en
        // positionell testharness ⇒ delgivningen sätts men bevakningen synkas ej.
        private ?BevakningService $bevakningService = null,
    ) {
    }

    /**
     * List the case's parter (newest first) — the partsregister panel.
     *
     * H1: authz via {@see ArendeService::show()} FÖRST; en obehörig anropare
     * får DoesNotExistException (404) och lär sig inte ens att ärendet finns.
     * Full PII i svaret är AVSEDD — anroparen är per definition behörig.
     *
     * @param string $ref hubsCaseId eller dnr.
     *
     * @return array<int,array<string,mixed>> jsonSerialize-ade partsrader.
     *
     * @throws DoesNotExistException Vid saknat/obehörigt ärende.
     */
    public function parter(string $ref): array {
        $arende = $this->arendeService->show($ref);

        return array_map(
            static fn (Part $part): array => $part->jsonSerialize(),
            $this->partMapper->findByCaseId($arende->getHubsCaseId()),
        );
    }

    /**
     * Add a MANUAL part (kalla=manuell) — the fallback when Navet is
     * unavailable (K-NAV-4.1 graceful degradation) or when the person has no
     * personnummer (e.g. an anonymous anmälare or a samverkanspart-kontakt).
     *
     * FAIL-CLOSED: `skydd` är OBLIGATORISKT även här — ett saknat/okänt värde
     * kastar InvalidArgumentException, defaultar ALDRIG till 'ingen'. Vid
     * skyddad_folkbokforing tvingas `adress` till null oavsett indata.
     *
     * @param string $ref hubsCaseId eller dnr.
     * @param array<string,mixed> $data Partsdata: roll (obligatorisk, se
     *        {@see Part::tillatnaRoller()}), skydd (obligatorisk, fail-closed,
     *        se {@see Part::tillatnaSkydd()}), namn (obligatorisk),
     *        personnummer? (normaliseras till 12 siffror), adress?,
     *        sarskildPostadress?, kontakt?.
     *
     * @throws DoesNotExistException Vid saknat/obehörigt ärende.
     * @throws \InvalidArgumentException Vid ogiltig roll, saknat/okänt skydd,
     *         tomt namn eller ogiltigt personnummer.
     */
    public function laggTill(string $ref, array $data): Part {
        $arende = $this->arendeService->show($ref);

        $roll = (string)($data['roll'] ?? '');
        if (!in_array($roll, Part::tillatnaRoller(), true)) {
            throw new \InvalidArgumentException('Ogiltig roll: ' . $roll);
        }

        // FAIL-CLOSED: skydd måste finnas OCH vara känt — aldrig default 'ingen'.
        $skydd = $data['skydd'] ?? null;
        if (!is_string($skydd) || !in_array($skydd, Part::tillatnaSkydd(), true)) {
            throw new \InvalidArgumentException(
                'Fältet skydd är obligatoriskt (ingen|sekretessmarkering|skyddad_folkbokforing) — fail-closed, ingen default.',
            );
        }

        $namn = trim((string)($data['namn'] ?? ''));
        if ($namn === '') {
            throw new \InvalidArgumentException('Fältet namn är obligatoriskt för en manuell part.');
        }

        $personnummer = null;
        if (isset($data['personnummer']) && trim((string)$data['personnummer']) !== '') {
            $personnummer = $this->normaliseraPnr((string)$data['personnummer']);
        }

        $part = new Part();
        $part->setHubsCaseId($arende->getHubsCaseId());
        $part->setRoll($roll);
        $part->setNamn($namn);
        $part->setPersonnummer($personnummer);
        // K-NAV-5.2: vid skyddad folkbokföring lagras verklig adress ALDRIG —
        // null OVILLKORLIGEN, även om klienten skulle leverera något.
        $part->setAdress(
            $skydd === Part::SKYDD_SKYDDAD_FOLKBOKFORING
                ? null
                : $this->trimEllerNull($data['adress'] ?? null),
        );
        $part->setSarskildPostadress($this->trimEllerNull($data['sarskildPostadress'] ?? null));
        $part->setKontakt($this->trimEllerNull($data['kontakt'] ?? null));
        $part->setSkydd($skydd);
        $part->setKalla(Part::KALLA_MANUELL);
        $part->setSkapad(new \DateTime());

        $part = $this->partMapper->insert($part);

        // Journal: ALDRIG pnr/namn/adress — enbart koordinationsvärden.
        $this->loggaHandelse($arende->getHubsCaseId(), [
            'handling' => 'tillagd',
            'roll' => $roll,
            'kalla' => Part::KALLA_MANUELL,
            'skydd' => $skydd,
        ]);

        // ★ LEGAL-FRIST ★ En ny delgivningsbar part (ej delgiven) gör laga kraft
        // obestämbar igen — synka så en redan uppnådd frist återöppnas ("väntar").
        // No-op om ärendet saknar delgivningsbara roller / ingen delgivning skett.
        $this->synkaDelgivning($arende->getHubsCaseId());

        return $part;
    }

    /**
     * HUVUDFLÖDET (K-NAV-4.2/4.3): slå upp en person i folkbokföringen och
     * skriv/uppdatera partsregistret — med audit-korrelation och (valfritt)
     * automatisk hämtning av vårdnadshavarna.
     *
     * Flöde: (1) H1-authz via show(); (2) pnr normaliseras (kräver 12 siffror);
     * (3) ändamål obligatoriskt (laglig grund i audit-kedjan); (4) korrelationsId
     * (UUID v4) myntas och bärs både till porten och journalen; (5) porten
     * anropas; (6) null-svar ⇒ personen finns inte i folkbokföringen;
     * (7) fail-closed skydd-vakt (defense in depth ovanpå portens);
     * (8) posten mappas till en Part; (9) UPSERT på (ärende, pnr, roll) —
     * rättelse-garantin K-NAV-4.4; (10) vid $inkluderaVardnadshavare hämtas
     * varje V-relation med pnr som egen part (roll=vardnadshavare, SAMMA
     * korrelationsId, ALDRIG vidare rekursion); (11) journal + retur.
     *
     * Relationerna returneras som de är — PII till behörig handläggare är
     * avsedd (invarianten är behörighetsgränsen, inte PII-gömning).
     *
     * @param string $ref hubsCaseId eller dnr.
     * @param string $personnummer 10/12-siffrigt, med eller utan -/+ —
     *        normaliseras; exakt 12 siffror krävs efter normalisering.
     * @param string $roll Partens roll, se {@see Part::tillatnaRoller()}.
     * @param string $andamal Ändamål med uppslaget (obligatorisk; audit/laglig grund).
     * @param bool $inkluderaVardnadshavare Hämta även V-relationerna som parter.
     *
     * @return array{part: Part, vardnadshavare: Part[], relationer: array}
     *
     * @throws DoesNotExistException Vid saknat/obehörigt ärende.
     * @throws \InvalidArgumentException Vid ogiltigt pnr/roll, tomt ändamål,
     *         eller person som inte finns i folkbokföringen.
     * @throws \RuntimeException Vid otillgänglig port eller post som bryter
     *         fail-closed-regeln för skydd.
     * @throws \OCA\HubsArende\Integration\Port\Exception\FolkbokforingException
     *         Vid transport-/kontraktsfel i porten.
     */
    public function uppslag(
        string $ref,
        string $personnummer,
        string $roll,
        string $andamal,
        bool $inkluderaVardnadshavare = false,
    ): array {
        $arende = $this->arendeService->show($ref);

        if (!in_array($roll, Part::tillatnaRoller(), true)) {
            throw new \InvalidArgumentException('Ogiltig roll: ' . $roll);
        }
        $pnr = $this->normaliseraPnr($personnummer);
        $andamal = $this->kravAndamal($andamal);

        if (!$this->folkbokforing->isAvailable()) {
            // Graceful degradation (K-NAV-4.1): anroparen faller tillbaka på
            // manuell inmatning via laggTill().
            throw new \RuntimeException('Folkbokföringsuppslag är inte tillgängligt just nu.');
        }

        // AUDIT-NYCKELN: binder porten (skv_client_correlation_id), journalen
        // och loggen till varandra — utan att identiteten själv förekommer.
        $korrelationsId = $this->mintUuidV4();

        $post = $this->hamtaPost($arende, $pnr, $andamal, $korrelationsId);
        $part = $this->upsert($arende->getHubsCaseId(), $roll, $post);

        /** @var Part[] $vardnadshavare */
        $vardnadshavare = [];
        /** @var array<int,array<string,mixed>> $relationer */
        $relationer = is_array($post['relationer'] ?? null) ? $post['relationer'] : [];

        if ($inkluderaVardnadshavare) {
            foreach ($relationer as $relation) {
                // Endast vårdnadshavare (typ V) med känt pnr kan slås upp; en
                // relation utan pnr (skyddad/utländsk) lämnas åt manuell hantering.
                if (($relation['typ'] ?? null) !== 'V') {
                    continue;
                }
                $relPnr = $relation['personnummer'] ?? null;
                if (!is_string($relPnr) || trim($relPnr) === '' || $relPnr === $pnr) {
                    continue;
                }
                try {
                    // SAMMA korrelationsId (en audit-kedja per uppslag) och
                    // ALDRIG vidare rekursion (en VH:s relationer hämtas inte).
                    $relPost = $this->hamtaPost($arende, $this->normaliseraPnr($relPnr), $andamal, $korrelationsId);
                    $vardnadshavare[] = $this->upsert(
                        $arende->getHubsCaseId(),
                        Part::ROLL_VARDNADSHAVARE,
                        $relPost,
                    );
                } catch (\Throwable $e) {
                    // Best-effort: en VH som inte kan slås upp får inte fälla
                    // huvuduppslaget. ALDRIG pnr i loggen — korrelationsId räcker.
                    $this->logger->warning('hubs_arende: vårdnadshavar-uppslag misslyckades (graceful)', [
                        'app' => 'hubs_arende',
                        'korrelationsId' => $korrelationsId,
                        'exception' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->loggaHandelse($arende->getHubsCaseId(), [
            'handling' => 'uppslag',
            'roll' => $roll,
            'kalla' => Part::KALLA_NAVET,
            'korrelationsId' => $korrelationsId,
            'andamal' => $andamal,
            'skydd' => $part->getSkydd(),
            'antalVardnadshavare' => count($vardnadshavare),
        ]);

        $this->logger->info('hubs_arende: folkbokföringsuppslag slutfört', [
            'app' => 'hubs_arende',
            // ENBART korrelationsId + antal — ALDRIG pnr/namn/adress.
            'korrelationsId' => $korrelationsId,
            'antalVardnadshavare' => count($vardnadshavare),
        ]);

        // ★ LEGAL-FRIST ★ Uppslaget kan ha lagt till barn/vårdnadshavare
        // (delgivningsbara) — synka så laga kraft omprövas (t.ex. återöppnas).
        $this->synkaDelgivning($arende->getHubsCaseId());

        return [
            'part' => $part,
            'vardnadshavare' => $vardnadshavare,
            'relationer' => $relationer,
        ];
    }

    /**
     * Re-verify an existing part against Navet (K-NAV-4.4 rättelse-garantin):
     * kör om uppslags-mappningen på raden med samma roll och stämplar
     * `verifierad`.
     *
     * IDOR-GUARD: raden laddas via id och MÅSTE tillhöra det authz-grindade
     * ärendet — annars DoesNotExistException (existens läcker inte).
     *
     * @param string $ref hubsCaseId eller dnr.
     * @param int $id Partsradens id.
     * @param string $andamal Ändamål med uppslaget (obligatorisk; audit).
     *
     * @throws DoesNotExistException Vid saknat/obehörigt ärende eller rad ur
     *         ett annat ärende.
     * @throws \InvalidArgumentException Vid rad utan personnummer, tomt
     *         ändamål, eller person som inte längre finns i folkbokföringen.
     * @throws \RuntimeException Vid otillgänglig port eller skydd-brott.
     */
    public function uppdateraFranNavet(string $ref, int $id, string $andamal): Part {
        $arende = $this->arendeService->show($ref);
        $part = $this->kravPartICase($arende, $id);
        $andamal = $this->kravAndamal($andamal);

        $pnr = $part->getPersonnummer();
        if ($pnr === null || trim($pnr) === '') {
            throw new \InvalidArgumentException(
                'Parten saknar personnummer och kan inte verifieras mot folkbokföringen.',
            );
        }

        if (!$this->folkbokforing->isAvailable()) {
            throw new \RuntimeException('Folkbokföringsuppslag är inte tillgängligt just nu.');
        }

        $korrelationsId = $this->mintUuidV4();
        $post = $this->hamtaPost($arende, $this->normaliseraPnr($pnr), $andamal, $korrelationsId);

        // Samma mappning som uppslaget, applicerad på just DENNA rad (rollen
        // behålls) — rättelser i folkbokföringen slår igenom, dubblett skapas ej.
        $this->mappaNavetPost($part, $post);
        $part = $this->partMapper->update($part);

        $this->loggaHandelse($arende->getHubsCaseId(), [
            'handling' => 'uppdaterad',
            'roll' => $part->getRoll(),
            'kalla' => Part::KALLA_NAVET,
            'korrelationsId' => $korrelationsId,
            'andamal' => $andamal,
            'skydd' => $part->getSkydd(),
        ]);

        return $part;
    }

    /**
     * Remove a part from the register (t.ex. felaktigt tillagd person —
     * lagringsminimering, GDPR art. 5.1e).
     *
     * IDOR-GUARD: samma case-match-vakt som {@see uppdateraFranNavet()}.
     *
     * @param string $ref hubsCaseId eller dnr.
     * @param int $id Partsradens id.
     *
     * @throws DoesNotExistException Vid saknat/obehörigt ärende eller rad ur
     *         ett annat ärende.
     */
    public function taBort(string $ref, int $id): void {
        $arende = $this->arendeService->show($ref);
        $part = $this->kravPartICase($arende, $id);

        $roll = $part->getRoll();
        $kalla = $part->getKalla();
        $this->partMapper->delete($part);

        $this->loggaHandelse($arende->getHubsCaseId(), [
            'handling' => 'borttagen',
            'roll' => $roll,
            'kalla' => $kalla,
        ]);

        // ★ LEGAL-FRIST ★ Att ta bort en delgiven/frist-hållande part ändrar laga
        // kraft (senaste kvarvarande partens frist), och att ta bort den SISTA
        // delgivningsbara parten ska nolla fristen — synka i båda fallen.
        $this->synkaDelgivning($arende->getHubsCaseId());
    }

    // ================================================================== //
    //  ★ PER-PART-DELGIVNING (FL 44 §) ★
    // ================================================================== //

    /**
     * ★ LEGAL-FRIST ★ Registrera att EN part delgivits ett beslut (FL 33 §) och
     * synka ärendets överklagandebevakning (laga kraft = senaste partens frist,
     * {@see BevakningService::synkaOverklagandeFranParter()}).
     *
     * Att delge en part NOLLAR ett ev. tidigare undantag (parten nåddes ju) —
     * "delge per part" och "nå inte denna part" är ömsesidigt uteslutande på
     * partsnivå. Endast en part med överklaganderätt
     * ({@see Part::delgivningsbaraRoller()}) kan delges ett beslut.
     *
     * IDOR-guard: raden måste tillhöra det authz-grindade ärendet.
     * Journalförd (roll/metod/datum — aldrig identitet).
     *
     * @param string $ref hubsCaseId eller dnr.
     * @param int $id Partsradens id.
     * @param string $datum Delgivningsdatum (YYYY-MM-DD).
     * @param string $metod Delgivningssätt, se {@see Part::tillatnaDelgivningsmetoder()}.
     *
     * @throws DoesNotExistException Vid saknat/obehörigt ärende eller rad ur annat ärende.
     * @throws \InvalidArgumentException Vid ogiltigt datum/metod eller roll utan
     *         överklaganderätt.
     */
    public function setDelgivning(string $ref, int $id, string $datum, string $metod): Part {
        $arende = $this->arendeService->show($ref);
        $part = $this->kravPartICase($arende, $id);

        if (!in_array($part->getRoll(), Part::delgivningsbaraRoller(), true)) {
            throw new \InvalidArgumentException(
                'Parten har inte överklaganderätt (roll ' . $part->getRoll() . ') och kan inte delges ett beslut.',
            );
        }
        if (!in_array($metod, Part::tillatnaDelgivningsmetoder(), true)) {
            throw new \InvalidArgumentException(
                'Ogiltig delgivningsmetod (ordinar|forenklad|muntlig|kungorelse|stamning).',
            );
        }
        $d = $this->parseDatum($datum);

        $part->setDelgivningsdatum($d);
        $part->setDelgivningMetod($metod);
        // Att delge nollar ett ev. tidigare undantag (parten nåddes).
        $part->setDelgivningUndantagen(false);
        $part->setDelgivningUndantagGrund(null);
        $part = $this->partMapper->update($part);

        $this->loggaHandelse($arende->getHubsCaseId(), [
            'handling' => 'delgiven',
            'roll' => $part->getRoll(),
            'metod' => $metod,
            'datum' => $d->format('Y-m-d'),
        ]);

        // Synka laga kraft ur samtliga parters delgivning (best-effort).
        $this->bevakningService?->synkaOverklagandeFranParter(
            $arende->getHubsCaseId(),
            $this->aktor(),
        );

        return $part;
    }

    /**
     * ★ LEGAL-FRIST ★ Undanta en part från delgivning (OSL 10:3 / skyddad adress /
     * våldsscenario) — parten ska MEDVETET inte nås. Den räknas då inte in i laga
     * kraft ({@see Part::arDelgivningsbar()}) men bärs explicit + journalfört i
     * modellen ("nå inte denna part" är aldrig en tyst lucka).
     *
     * Nollar ett ev. tidigare satt delgivningsdatum (en undantagen part är inte
     * delgiven). Synkar överklagandebevakningen (undantaget kan göra laga kraft
     * bestämbar när den var den enda part som fattades).
     *
     * @param string $ref hubsCaseId eller dnr.
     * @param int $id Partsradens id.
     * @param string $grund Undantagsgrund, se {@see Part::tillatnaUndantagsgrunder()}.
     *
     * @throws DoesNotExistException Vid saknat/obehörigt ärende eller rad ur annat ärende.
     * @throws \InvalidArgumentException Vid ogiltig grund eller roll utan överklaganderätt.
     */
    public function undantaDelgivning(string $ref, int $id, string $grund): Part {
        $arende = $this->arendeService->show($ref);
        $part = $this->kravPartICase($arende, $id);

        if (!in_array($part->getRoll(), Part::delgivningsbaraRoller(), true)) {
            throw new \InvalidArgumentException(
                'Endast en part med överklaganderätt kan undantas från delgivning.',
            );
        }
        if (!in_array($grund, Part::tillatnaUndantagsgrunder(), true)) {
            throw new \InvalidArgumentException(
                'Ogiltig undantagsgrund (osl_10_3|skyddad_adress|vald|annan).',
            );
        }

        $part->setDelgivningUndantagen(true);
        $part->setDelgivningUndantagGrund($grund);
        // En undantagen part är inte delgiven — nolla ev. datum/metod.
        $part->setDelgivningsdatum(null);
        $part->setDelgivningMetod(null);
        $part = $this->partMapper->update($part);

        $this->loggaHandelse($arende->getHubsCaseId(), [
            'handling' => 'delgivning_undantagen',
            'roll' => $part->getRoll(),
            'grund' => $grund,
        ]);

        $this->bevakningService?->synkaOverklagandeFranParter(
            $arende->getHubsCaseId(),
            $this->aktor(),
        );

        return $part;
    }

    // ================================================================== //
    //  PRIVATA HJÄLPARE
    // ================================================================== //

    /**
     * Parse ett delgivningsdatum (YYYY-MM-DD, strikt). Kastar vid ogiltigt datum.
     *
     * @throws \InvalidArgumentException
     */
    private function parseDatum(string $datum): \DateTime {
        $d = \DateTime::createFromFormat('!Y-m-d', trim($datum));
        $fel = \DateTime::getLastErrors();
        if ($d === false || ($fel && ($fel['warning_count'] > 0 || $fel['error_count'] > 0))) {
            throw new \InvalidArgumentException('Ogiltigt delgivningsdatum — ange YYYY-MM-DD.');
        }
        return $d;
    }

    /** Aktörens uid ('' = system/CLI-kontext utan session). */
    private function aktor(): string {
        return $this->userSession?->getUser()?->getUID() ?? '';
    }

    /**
     * ★ LEGAL-FRIST ★ Be BevakningService omberäkna ärendets överklagandebevakning
     * ur partsregistrets delgivning. Best-effort (?-> + idempotent): den interna
     * grinden gör det till en no-op när ärendet saknar delgivningsbara roller, så
     * detta får anropas efter VARJE partsmutation (tillägg/uppslag/borttag/delgivning).
     */
    private function synkaDelgivning(string $hubsCaseId): void {
        $this->bevakningService?->synkaOverklagandeFranParter($hubsCaseId, $this->aktor());
    }

    /**
     * Load a part row by id and REQUIRE that it belongs to the given (already
     * authz-gated) case. En rad ur ett annat ärende ger DoesNotExistException
     * — samma 404 som en saknad rad, så existens läcker inte (IDOR-guard).
     *
     * @throws DoesNotExistException
     */
    private function kravPartICase(Arende $arende, int $id): Part {
        $part = $this->partMapper->findById($id);
        if ($part === null || $part->getHubsCaseId() !== $arende->getHubsCaseId()) {
            throw new DoesNotExistException('Ingen part med id ' . $id . ' i ärendet.');
        }
        return $part;
    }

    /**
     * Call the port for ONE person and gate the answer.
     *
     * Null-svar ⇒ personen finns inte i folkbokföringen (InvalidArgumentException).
     * FAIL-CLOSED (defense in depth): posten valideras mot
     * {@see Part::tillatnaSkydd()} en gång till HÄR — porten garanterar redan
     * regeln, men en framtida port-regression får aldrig smyga in en post utan
     * giltigt skydd (då hellre \RuntimeException än default 'ingen').
     *
     * @return array<string,mixed> Den normaliserade personposten.
     *
     * @throws \InvalidArgumentException Om personen inte finns i folkbokföringen.
     * @throws \RuntimeException Om posten saknar/har okänt skydd (fail-closed).
     */
    private function hamtaPost(Arende $arende, string $pnr, string $andamal, string $korrelationsId): array {
        $svar = $this->folkbokforing->hamtaPerson([$pnr], [
            'korrelationsId' => $korrelationsId,
            'arendeRef' => $arende->getHubsCaseId(),
            'andamal' => $andamal,
        ]);

        $post = $svar[$pnr] ?? null;
        if ($post === null) {
            // Meddelandet bär ALDRIG pnr — anroparen vet redan vem som söktes.
            throw new \InvalidArgumentException('Personen finns inte i folkbokföringen.');
        }

        $skydd = $post['skydd'] ?? null;
        if (!is_string($skydd) || !in_array($skydd, Part::tillatnaSkydd(), true)) {
            throw new \RuntimeException(
                'Personpost utan giltigt skydd-värde från folkbokföringsporten (fail-closed) — korrelationsId ' . $korrelationsId,
            );
        }

        return $post;
    }

    /**
     * UPSERT på (ärende, pnr, roll) — rättelse-garantin K-NAV-4.4. Finns raden
     * uppdateras fälten (+ verifierad-stämpeln); annars föds en ny rad med
     * kalla=navet. Manuellt ifyllt `kontakt` överlevs — Navet levererar inga
     * kontaktuppgifter och får inte radera handläggarens.
     */
    private function upsert(string $hubsCaseId, string $roll, array $post): Part {
        $pnr = (string)$post['personnummer'];

        $part = $this->partMapper->findByCasePnrRoll($hubsCaseId, $pnr, $roll);
        if ($part !== null) {
            $this->mappaNavetPost($part, $post);
            return $this->partMapper->update($part);
        }

        $part = new Part();
        $part->setHubsCaseId($hubsCaseId);
        $part->setRoll($roll);
        $part->setSkapad(new \DateTime());
        $this->mappaNavetPost($part, $post);
        return $this->partMapper->insert($part);
    }

    /**
     * Map a normalized Navet personpost onto a Part (K-NAV-4.3/5.2).
     *
     * SKYDDAD FOLKBOKFÖRING: `adress` sätts OVILLKORLIGEN till null (den
     * verkliga adressen får aldrig lagras — även om porten mot sitt kontrakt
     * skulle leverera en kontaktadress ignoreras den) och endast
     * `sarskildPostadress` (förmedlingsadressen) formateras in.
     *
     * fbfStatus ur avregistrering: AV ⇒ avliden, UV ⇒ utvandrad, annars null
     * (aktiv). Identitetshistoriken (tidigare beteckningar) JSON-lagras för
     * matchning mot äldre handlingar.
     */
    private function mappaNavetPost(Part $part, array $post): void {
        $skydd = (string)$post['skydd'];
        $namn = $post['namn'] ?? [];

        $part->setPersonnummer((string)$post['personnummer']);
        $part->setNamn(trim((string)($namn['fornamn'] ?? '') . ' ' . (string)($namn['efternamn'] ?? '')));
        $part->setSkydd($skydd);

        if ($skydd === Part::SKYDD_SKYDDAD_FOLKBOKFORING) {
            // K-NAV-5.2: verklig adress lagras ALDRIG — ovillkorligen null.
            $part->setAdress(null);
            $part->setSarskildPostadress($this->formateraAdress($post['sarskildPostadress'] ?? null));
        } else {
            $part->setAdress($this->formateraAdress($post['kontaktadress'] ?? null));
            $part->setSarskildPostadress($this->formateraAdress($post['sarskildPostadress'] ?? null));
        }

        $avregistrering = $post['avregistrering'] ?? null;
        $kod = is_array($avregistrering) ? ($avregistrering['kod'] ?? null) : null;
        $part->setFbfStatus(match ($kod) {
            'AV' => 'avliden',
            'UV' => 'utvandrad',
            default => null,
        });

        $tidigare = $post['tidigareBeteckningar'] ?? [];
        $part->setIdentitetshistorik(
            is_array($tidigare) && $tidigare !== [] ? json_encode(array_values($tidigare)) : null,
        );

        $part->setKalla(Part::KALLA_NAVET);
        $part->setVerifierad(new \DateTime());
    }

    /**
     * Format a normalized adress-shape ({rader: string[], postnummer, postort})
     * to the entity's one-string postadress: raderna, sedan "postnummer postort",
     * en per rad. Null in ⇒ null ut.
     */
    private function formateraAdress(?array $adress): ?string {
        if ($adress === null) {
            return null;
        }

        $rader = [];
        foreach (($adress['rader'] ?? []) as $rad) {
            $rad = trim((string)$rad);
            if ($rad !== '') {
                $rader[] = $rad;
            }
        }
        $ort = trim(trim((string)($adress['postnummer'] ?? '')) . ' ' . trim((string)($adress['postort'] ?? '')));
        if ($ort !== '') {
            $rader[] = $ort;
        }

        return $rader === [] ? null : implode("\n", $rader);
    }

    /**
     * Normalize a personnummer: skiljetecken (-/+) och whitespace bort, exakt
     * 12 siffror (AAAAMMDDNNNN) krävs — allt annat kastar. Meddelandet bär
     * ALDRIG det inskickade värdet (kan vara PII).
     *
     * @throws \InvalidArgumentException
     */
    private function normaliseraPnr(string $personnummer): string {
        $pnr = str_replace(['-', '+', ' '], '', trim($personnummer));
        if (!preg_match('/^\d{12}$/', $pnr)) {
            throw new \InvalidArgumentException(
                'Ogiltigt personnummer — ange 12 siffror (AAAAMMDDNNNN).',
            );
        }
        return $pnr;
    }

    /**
     * Require a non-empty ändamål (laglig grund; K-NAV-4.2 — varje uppslag
     * måste bära sitt syfte in i audit-kedjan).
     *
     * @throws \InvalidArgumentException
     */
    private function kravAndamal(string $andamal): string {
        $andamal = trim($andamal);
        if ($andamal === '') {
            throw new \InvalidArgumentException('Ändamål är obligatoriskt för ett folkbokföringsuppslag.');
        }
        return $andamal;
    }

    /**
     * Trim to string-or-null (tomt ⇒ null) — for the optional manual fields.
     */
    private function trimEllerNull(mixed $varde): ?string {
        if ($varde === null) {
            return null;
        }
        $str = trim((string)$varde);
        return $str === '' ? null : $str;
    }

    /**
     * Append a journal row (best-effort, mönstret från
     * {@see ArendeService::loggaHandelse()}). $detalj bär ENDAST
     * koordinationsvärden — handling, roll, kalla, korrelationsId, andamal,
     * skydd, antalVardnadshavare — ALDRIG pnr/namn/adress. Ett journal-fel får
     * ALDRIG fälla mutationen — fel sväljs och loggas (utan identitet).
     *
     * @param array<string,mixed> $detalj
     */
    private function loggaHandelse(string $hubsCaseId, array $detalj): void {
        if ($this->handelseMapper === null) {
            return;
        }
        try {
            $aktor = $this->userSession?->getUser()?->getUID() ?? '';
            $this->handelseMapper->record($hubsCaseId, Handelse::TYP_PART, $detalj, $aktor);
        } catch (\Throwable $e) {
            $this->logger->warning('hubs_arende: journal-skrivning misslyckades (graceful)', [
                'app' => 'hubs_arende',
                'hubsCaseId' => $hubsCaseId,
                'typ' => Handelse::TYP_PART,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Mint a UUID v4 (audit-korrelationsId) från random_bytes (CSPRNG), med
     * versions-/variantbitarna satta per RFC 4122. Egen privat hjälpare —
     * {@see ArendeService} har sin egen ISecureRandom-baserade variant, men
     * den är privat och kräver en beroende-injektion vi inte behöver här.
     */
    private function mintUuidV4(): string {
        $bytes = random_bytes(16);
        // Force version (4) and variant (8/9/a/b) bits.
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
