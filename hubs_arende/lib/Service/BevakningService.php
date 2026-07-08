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
use OCA\HubsArende\Db\Bevakning;
use OCA\HubsArende\Db\BevakningMapper;
use OCA\HubsArende\Db\Handelse;
use OCA\HubsArende\Db\HandelseMapper;
use OCA\HubsArende\Db\PekareMapper;
use OCA\HubsArende\Integration\Client\DeckClient;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * BEVAKNINGS-LIVSCYKELN — händelsedriven villkorsmotor.
 *
 * En bevakning slocknar när det bevakade UPPNÅS. Det sker inte av sig självt:
 * ärendets händelser (steg-övergång, komplettering, verifierad commit, signering,
 * avslut, datum som passeras, manuell kvittering) matchas mot varje aktiv
 * bevaknings `villkor` — träff ⇒ status aktiv → uppnadd. Se
 * hubs_start/docs/KRAVSTALLNING-BEVAKNINGAR.md.
 *
 * KONTRAKT (best-effort, aldrig kastar mot anroparen):
 *  - {@see skapaStandardForFodelse()} R5 i sagan: instansierar ärendetypens
 *    mallar med vidSteg='fodelse'.
 *  - {@see skapaStandardForSteg()} steg-övergången: släcker det gamla stegets
 *    steg_uppnatt-mål (14d), avbryter dess recurring, föder nästa stegs mallar
 *    (4 mån) — själva "nollställningen".
 *  - {@see utvardera()} den generella motorn — anropas efter komplettering/
 *    commit/signering/avslut.
 *  - {@see bevakasIFacksystem()} GAP-044: verifierad registrering ⇒ facksystemet
 *    äger fristen ⇒ Hubs avbryter sina lagstadgade speglingar (dubbelbevakning
 *    upphör).
 *  - {@see setDelgivningsdatum()} sätter ankaret och föder överklagandebevakningen.
 *
 * fristDue på registret ({@see Arende}) är en PROJEKTION av den mest brådskande
 * aktiva bevakningen ({@see projicieraFrist()}) — FristChip m.fl. konsumerar
 * oförändrat. Deck = projektion per bevakning (graceful om Deck saknas).
 *
 * PII: titlar/journaldetaljer bär bara koordinationsdata (typ/villkor/datum).
 */
class BevakningService {
    public function __construct(
        private readonly BevakningMapper $bevakningMapper,
        private readonly ArendeMapper $arendeMapper,
        private readonly ArendeTypRegistry $typRegistry,
        private readonly ITimeFactory $timeFactory,
        private readonly LoggerInterface $logger,
        private readonly ?DeckClient $deckClient = null,
        private readonly ?PekareMapper $pekareMapper = null,
        private readonly ?HandelseMapper $handelseMapper = null,
        // A8 — trailing-optional (positionell testharness ⇒ null ⇒ auto-omprövning AV).
        private readonly ?GrindConfig $grindConfig = null,
    ) {
    }

    // ==================================================================== //
    //  LÄSNING
    // ==================================================================== //

    /**
     * Registrets bevakningar för ett ärende (nyast först) — läsprojektionen som
     * ArendeService::bevakningar() och Bevakningar-fliken konsumerar.
     *
     * @return Bevakning[]
     */
    public function listaForCase(string $hubsCaseId): array {
        try {
            return $this->bevakningMapper->findByCaseId($hubsCaseId);
        } catch (\Throwable $e) {
            $this->logger->warning('hubs_arende: bevaknings-läsning misslyckades (graceful)', [
                'app' => 'hubs_arende', 'hubsCaseId' => $hubsCaseId, 'exception' => $e->getMessage(),
            ]);
            return [];
        }
    }

    // ==================================================================== //
    //  SKAPANDE — datadrivet ur ärendetypens mallar
    // ==================================================================== //

    /**
     * Instansiera ärendetypens standardmallar med vidSteg='fodelse'. Anropas i
     * sagans R5. Best-effort: fel loggas, ärendeskapandet fortsätter.
     *
     * @param array<string,mixed> $rad Registreringens rådata (för inkom_datum-ankaret).
     */
    public function skapaStandardForFodelse(string $hubsCaseId, ArendeTyp $typ, array $rad, string $aktor = Bevakning::AKTOR_SYSTEM): void {
        try {
            $arende = $this->arendeMapper->findByCaseId($hubsCaseId);
            foreach ($this->mallarForSteg($typ, 'fodelse') as $mall) {
                $this->instansiera($arende, $mall, $aktor, $rad);
            }
            $this->projicieraFrist($hubsCaseId);
        } catch (\Throwable $e) {
            $this->logger->warning('hubs_arende: skapaStandardForFodelse misslyckades (graceful)', [
                'app' => 'hubs_arende', 'hubsCaseId' => $hubsCaseId, 'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Steg-övergången: (1) utvärdera steg-villkoret så förra stegets mål släcks,
     * (2) avbryt förra stegets recurring-bevakningar (recurring "upphör när steget
     * lämnas"), (3) instansiera det nya stegets mallar. Detta är nollställningen:
     * 14 d förhandsbedömning slocknar och 4 mån utredning föds i samma andetag.
     */
    public function skapaStandardForSteg(string $hubsCaseId, string $nyttSteg, string $aktor = Bevakning::AKTOR_SYSTEM): void {
        try {
            // (1) släck det gamla stegets steg_uppnatt-mål som pekar på detta steg.
            $this->utvardera($hubsCaseId, 'steg', ['nyttSteg' => $nyttSteg], $aktor);

            $arende = $this->arendeMapper->findByCaseId($hubsCaseId);
            $typ = $this->typRegistry->get($arende->getArendeTyp());

            // (2) recurring som hör till ett ANNAT (nu lämnat) steg avbryts.
            foreach ($this->bevakningMapper->findAktivaByCaseId($hubsCaseId) as $b) {
                if ($b->getRecurringDagar() !== null
                    && $b->getVillkorArg() !== null
                    && $b->getVillkorArg() !== $nyttSteg) {
                    $this->avslut($b, Bevakning::STATUS_AVBRUTEN, $aktor, 'steg_lamnat');
                }
            }

            // (3) föd det nya stegets mallar.
            if ($typ !== null) {
                foreach ($this->mallarForSteg($typ, $nyttSteg) as $mall) {
                    $this->instansiera($arende, $mall, $aktor);
                }
            }

            // (4) A8 — lagstadgad omprövning/övervägande: vid inträde i uppföljning
            // för en ärendetyp med omprovningskrav SÄKERSTÄLLS en aktiv
            // omprövningsbevakning. Den ska ALDRIG vila på att handläggaren råkar
            // skapa den, eller på att typens mall råkar vara korrekt seedad.
            // Idempotent: körs bara om ingen aktiv overvägande/omprövning finns (mall
            // i steg (3) ovan täcker normalfallet — detta är säkerhetsnätet).
            $this->sakerstallOmprovningsbevakning($hubsCaseId, $nyttSteg, $typ, $arende, $aktor);

            $this->projicieraFrist($hubsCaseId);
        } catch (\Throwable $e) {
            $this->logger->warning('hubs_arende: skapaStandardForSteg misslyckades (graceful)', [
                'app' => 'hubs_arende', 'hubsCaseId' => $hubsCaseId, 'nyttSteg' => $nyttSteg,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handläggarskapad ad hoc-bevakning (den tidigare DÖDA "skapa bevakning").
     *
     * @param array{titel?:string,fristDue?:string,recurringDagar?:int,lagstadgad?:bool} $data
     * @throws \InvalidArgumentException Vid tom/PII-misstänkt titel eller ogiltigt datum.
     */
    public function laggTillManuell(string $hubsCaseId, array $data, string $aktor): Bevakning {
        $arende = $this->arendeMapper->findByCaseId($hubsCaseId);
        $titel = trim((string)($data['titel'] ?? ''));
        if ($titel === '') {
            throw new \InvalidArgumentException('Bevakningen måste ha en rubrik.');
        }
        $frist = null;
        if (!empty($data['fristDue'])) {
            $frist = $this->parseDate((string)$data['fristDue']);
            if ($frist === null) {
                throw new \InvalidArgumentException('Ogiltigt fristdatum.');
            }
        }
        $recurring = isset($data['recurringDagar']) && (int)$data['recurringDagar'] > 0
            ? (int)$data['recurringDagar'] : null;

        $b = new Bevakning();
        $b->setHubsCaseId($hubsCaseId);
        $b->setTyp('manuell');
        $b->setTitel(mb_substr($titel, 0, 255));
        $b->setVillkorTyp(Bevakning::VILLKOR_MANUELL_KVITTERING);
        $b->setVillkorArg($arende->getSteg());
        $b->setStatus(Bevakning::STATUS_AKTIV);
        $b->setFristDue($frist);
        $b->setAnkare($frist !== null ? Bevakning::ANKARE_MANUELL : Bevakning::ANKARE_MANUELL);
        $b->setRecurringDagar($recurring);
        $b->setLagstadgad((bool)($data['lagstadgad'] ?? false));
        $b->setSkapadAv($aktor);
        $b->setForsenad(false);
        $b->setSkapad($this->timeFactory->getDateTime());
        $b = $this->bevakningMapper->insert($b);

        $this->skapaDeckKort($arende, $b);
        $this->loggaBev($hubsCaseId, 'skapad', $b, $aktor);
        $this->projicieraFrist($hubsCaseId);
        return $b;
    }

    // ==================================================================== //
    //  VILLKORSMOTORN
    // ==================================================================== //

    /**
     * Matcha en ärendehändelse mot varje aktiv bevakning; träff ⇒ uppnadd.
     *
     * @param string              $handelseTyp steg|komplettering|commit|signering|avslut
     * @param array<string,mixed> $kontext     t.ex. ['nyttSteg'=>'utredning'] för steg
     */
    public function utvardera(string $hubsCaseId, string $handelseTyp, array $kontext = [], string $aktor = Bevakning::AKTOR_SYSTEM): void {
        try {
            if ($handelseTyp === 'avslut') {
                $this->avbrytAllaForAvslut($hubsCaseId, $aktor);
                return;
            }
            $traffat = false;
            foreach ($this->bevakningMapper->findAktivaByCaseId($hubsCaseId) as $b) {
                if ($this->villkorUppfyllt($b, $handelseTyp, $kontext)) {
                    $this->markUppnadd($b, $aktor);
                    $traffat = true;
                }
            }
            if ($traffat) {
                $this->projicieraFrist($hubsCaseId);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('hubs_arende: utvardera misslyckades (graceful)', [
                'app' => 'hubs_arende', 'hubsCaseId' => $hubsCaseId, 'handelseTyp' => $handelseTyp,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /** Sant om händelsen släcker just denna bevakning. */
    private function villkorUppfyllt(Bevakning $b, string $handelseTyp, array $kontext): bool {
        return match ($b->getVillkorTyp()) {
            Bevakning::VILLKOR_STEG_UPPNATT => $handelseTyp === 'steg'
                && $b->getVillkorArg() !== null
                && $b->getVillkorArg() === (string)($kontext['nyttSteg'] ?? ''),
            Bevakning::VILLKOR_KOMPLETTERING_KOPPLAD => $handelseTyp === 'komplettering',
            Bevakning::VILLKOR_COMMIT_REGISTRERAD => $handelseTyp === 'commit',
            Bevakning::VILLKOR_SIGNERING_KVITTERAD => $handelseTyp === 'signering',
            default => false,
        };
    }

    /**
     * Manuell klarmarkering av en manuell_kvittering-bevakning.
     *
     * @throws DoesNotExistException|\InvalidArgumentException
     */
    public function kvittera(string $hubsCaseId, int $id, string $aktor): Bevakning {
        $b = $this->bevakningMapper->findById($id);
        if ($b->getHubsCaseId() !== $hubsCaseId) {
            throw new \InvalidArgumentException('Bevakningen tillhör inte ärendet.');
        }
        if ($b->getStatus() !== Bevakning::STATUS_AKTIV) {
            return $b; // redan avslutad — idempotent
        }
        $this->markUppnadd($b, $aktor);
        $this->projicieraFrist($hubsCaseId);
        return $b;
    }

    /**
     * Handläggaren avbryter en bevakning (inte längre relevant).
     *
     * @throws DoesNotExistException|\InvalidArgumentException
     */
    public function avbryt(string $hubsCaseId, int $id, string $aktor): Bevakning {
        $b = $this->bevakningMapper->findById($id);
        if ($b->getHubsCaseId() !== $hubsCaseId) {
            throw new \InvalidArgumentException('Bevakningen tillhör inte ärendet.');
        }
        if ($b->getStatus() === Bevakning::STATUS_AKTIV) {
            $this->avslut($b, Bevakning::STATUS_AVBRUTEN, $aktor, 'manuell');
            $this->projicieraFrist($hubsCaseId);
        }
        return $b;
    }

    /** Avslut på ärendet ⇒ alla aktiva bevakningar avbryts (ingenting kvar att bevaka). */
    public function avbrytAllaForAvslut(string $hubsCaseId, string $aktor = Bevakning::AKTOR_SYSTEM): void {
        foreach ($this->bevakningMapper->findAktivaByCaseId($hubsCaseId) as $b) {
            $this->avslut($b, Bevakning::STATUS_AVBRUTEN, $aktor, 'avslut');
        }
        $this->projicieraFrist($hubsCaseId);
    }

    /**
     * GAP-044 — verifierad facksystem-registrering: (1) commit-villkoret uppnås,
     * (2) återstående LAGSTADGADE speglingar avbryts eftersom facksystemet nu äger
     * fristen (dubbelbevakning upphör). Interna/manuella bevakningar behålls.
     */
    public function bevakasIFacksystem(string $hubsCaseId, string $dnr): void {
        try {
            // commit_registrerad-bevakningar → uppnadd
            $this->utvardera($hubsCaseId, 'commit', ['dnr' => $dnr], Bevakning::AKTOR_AGARSKIFTE);
            // återstående lagstadgade frist-speglingar → avbrutna (facksystemet äger dem)
            foreach ($this->bevakningMapper->findAktivaByCaseId($hubsCaseId) as $b) {
                if ($b->getLagstadgad()) {
                    $this->avslut($b, Bevakning::STATUS_AVBRUTEN, Bevakning::AKTOR_AGARSKIFTE, 'agarskifte');
                }
            }
            $this->projicieraFrist($hubsCaseId);
        } catch (\Throwable $e) {
            $this->logger->warning('hubs_arende: bevakasIFacksystem misslyckades (graceful)', [
                'app' => 'hubs_arende', 'hubsCaseId' => $hubsCaseId, 'exception' => $e->getMessage(),
            ]);
        }
    }

    // ==================================================================== //
    //  DELGIVNINGS-ANKARET (överklagandefristen)
    // ==================================================================== //

    /**
     * Sätt delgivningsdatum på registret och föd/uppdatera överklagandebevakningen
     * (3 veckor → laga kraft). Ren datumbevakning: dagen som passerar = UPPNÅDD.
     *
     * @throws DoesNotExistException|\InvalidArgumentException
     */
    public function setDelgivningsdatum(string $hubsCaseId, string $datum, string $aktor): Bevakning {
        $d = $this->parseDate($datum);
        if ($d === null) {
            throw new \InvalidArgumentException('Ogiltigt delgivningsdatum.');
        }
        $arende = $this->arendeMapper->findByCaseId($hubsCaseId);
        $arende->setDelgivningsdatum($d);
        $this->arendeMapper->update($arende);

        $frist = (clone $d)->add(new \DateInterval('P21D')); // 3 veckor, FL 44 §

        // Återanvänd en aktiv överklagandebevakning om den finns (idempotent).
        $befintlig = null;
        foreach ($this->bevakningMapper->findAktivaByCaseId($hubsCaseId) as $b) {
            if ($b->getTyp() === 'overklagande') {
                $befintlig = $b;
                break;
            }
        }
        if ($befintlig !== null) {
            $befintlig->setFristDue($frist);
            $befintlig->setAnkare(Bevakning::ANKARE_DELGIVNING);
            $this->bevakningMapper->update($befintlig);
            $this->uppdateraDeckKortFrist($arende, $befintlig);
            $this->loggaBev($hubsCaseId, 'delgivning_uppdaterad', $befintlig, $aktor);
            $this->projicieraFrist($hubsCaseId);
            return $befintlig;
        }

        $b = new Bevakning();
        $b->setHubsCaseId($hubsCaseId);
        $b->setTyp('overklagande');
        $b->setTitel('Överklagandefrist löper (laga kraft ' . $frist->format('Y-m-d') . ')');
        $b->setVillkorTyp(Bevakning::VILLKOR_DATUM_PASSERAT);
        $b->setVillkorArg(null);
        $b->setStatus(Bevakning::STATUS_AKTIV);
        $b->setFristDue($frist);
        $b->setAnkare(Bevakning::ANKARE_DELGIVNING);
        $b->setRecurringDagar(null);
        $b->setLagstadgad(true);
        $b->setSkapadAv($aktor);
        $b->setForsenad(false);
        $b->setSkapad($this->timeFactory->getDateTime());
        $b = $this->bevakningMapper->insert($b);

        $this->skapaDeckKort($arende, $b);
        $this->loggaBev($hubsCaseId, 'delgivning_satt', $b, $aktor);
        $this->projicieraFrist($hubsCaseId);
        return $b;
    }

    // ==================================================================== //
    //  BAKGRUNDSJOBB-STÖD
    // ==================================================================== //

    /**
     * Datumbevakningar vars dag passerat ⇒ UPPNÅDD (t.ex. överklagande → laga
     * kraft). Anropas dagligen av BevakningVarselJob. Returnerar antal flippade.
     */
    public function bearbetaForfallnaDatum(): int {
        $nu = $this->timeFactory->getDateTime();
        $antal = 0;
        $rorda = [];
        foreach ($this->bevakningMapper->findForfallnaDatumbevakningar($nu) as $b) {
            $this->markUppnadd($b, Bevakning::AKTOR_SYSTEM);
            $rorda[$b->getHubsCaseId()] = true;
            $antal++;
        }
        foreach (array_keys($rorda) as $caseId) {
            $this->projicieraFrist($caseId);
        }
        return $antal;
    }

    /**
     * Flagga aktiva bevakningar vars frist passerat som PASSERAD (larmläge). Så att
     * dashboardens röda lista och eskaleringen har ett stabilt tillstånd att läsa.
     * Datumbevakningar hoppas över (de blir uppnadd, ej passerad). Returnerar antal.
     */
    public function flaggaPasserade(): int {
        $nu = $this->timeFactory->getDateTime();
        $antal = 0;
        foreach ($this->bevakningMapper->findMedFristSenast($nu) as $b) {
            if ($b->getStatus() !== Bevakning::STATUS_AKTIV) {
                continue;
            }
            if ($b->getVillkorTyp() === Bevakning::VILLKOR_DATUM_PASSERAT) {
                continue; // hanteras av bearbetaForfallnaDatum → uppnadd
            }
            $frist = $b->getFristDue();
            if ($frist !== null && $frist->format('Y-m-d') < $nu->format('Y-m-d')) {
                $b->setStatus(Bevakning::STATUS_PASSERAD);
                $this->bevakningMapper->update($b);
                $this->loggaBev($b->getHubsCaseId(), 'passerad', $b, Bevakning::AKTOR_SYSTEM);
                $antal++;
            }
        }
        return $antal;
    }

    // ==================================================================== //
    //  fristDue-PROJEKTIONEN
    // ==================================================================== //

    /**
     * Registrets fristDue = tidigaste aktiva bevaknings frist (null om ingen).
     * Rör INTE legacy-ärenden utan bevakningsregister (behåller sin gamla frist).
     */
    public function projicieraFrist(string $hubsCaseId): void {
        try {
            $alla = $this->bevakningMapper->findByCaseId($hubsCaseId);
            if (count($alla) === 0) {
                return; // legacy-ärende utan bevakningar — behåll computeFristDue-värdet
            }
            $min = null;
            foreach ($alla as $b) {
                if ($b->getStatus() !== Bevakning::STATUS_AKTIV) {
                    continue;
                }
                $frist = $b->getFristDue();
                if ($frist === null) {
                    continue;
                }
                if ($min === null || $frist < $min) {
                    $min = $frist;
                }
            }
            $arende = $this->arendeMapper->findByCaseId($hubsCaseId);
            $arende->setFristDue($min);
            $this->arendeMapper->update($arende);
        } catch (DoesNotExistException) {
            // ärendet finns inte (t.ex. saga-kompensation) — inget att projicera.
        } catch (\Throwable $e) {
            $this->logger->warning('hubs_arende: projicieraFrist misslyckades (graceful)', [
                'app' => 'hubs_arende', 'hubsCaseId' => $hubsCaseId, 'exception' => $e->getMessage(),
            ]);
        }
    }

    // ==================================================================== //
    //  SAGA-KOMPENSATION
    // ==================================================================== //

    /**
     * R5-kompensation: riv bevakningsraderna (Deck-korten rivs av den befintliga
     * deck_card-pekar-kompensationen). Idempotent, best-effort.
     */
    public function rensaForKompensation(string $hubsCaseId): void {
        try {
            $this->bevakningMapper->deleteByCaseId($hubsCaseId);
        } catch (\Throwable $e) {
            $this->logger->warning('hubs_arende: rensaForKompensation misslyckades (graceful)', [
                'app' => 'hubs_arende', 'hubsCaseId' => $hubsCaseId, 'exception' => $e->getMessage(),
            ]);
        }
    }

    // ==================================================================== //
    //  BACKFILL (befintliga ärenden → bevakningsregistret)
    // ==================================================================== //

    /**
     * Migrera ett BEFINTLIGT ärende in i bevakningsregistret: om ärendet saknar
     * bevakningar men har en fristDue, skapa EN bevakning som speglar den (så den
     * gamla engångs-fristen blir en förstaklassig, kvitterbar watch). LEAN — inget
     * Deck-kort skapas för backfill (undviker att hamra Deck för alla gamla
     * ärenden; kortprojektionen är best-effort och kan byggas vid nästa mutation).
     * Idempotent: en re-körning hittar redan-befintliga bevakningar och hoppar.
     *
     * @return bool true om en bevakning skapades.
     */
    public function backfillForCase(Arende $arende): bool {
        try {
            $hubsCaseId = $arende->getHubsCaseId();
            if ($this->bevakningMapper->findByCaseId($hubsCaseId) !== []) {
                return false; // redan migrerad / nytt ärende
            }
            $frist = $arende->getFristDue();
            if ($frist === null) {
                return false; // ingen frist att spegla
            }
            $b = new Bevakning();
            $b->setHubsCaseId($hubsCaseId);
            $b->setTyp('backfill');
            $b->setTitel('Frist (migrerad från tidigare modell)');
            $b->setVillkorTyp(Bevakning::VILLKOR_MANUELL_KVITTERING);
            $b->setVillkorArg($arende->getSteg());
            $b->setStatus(Bevakning::STATUS_AKTIV);
            $b->setFristDue($frist);
            $b->setAnkare(Bevakning::ANKARE_MANUELL);
            $b->setRecurringDagar(null);
            $b->setLagstadgad(false);
            $b->setSkapadAv(Bevakning::AKTOR_SYSTEM);
            $b->setForsenad(false);
            $b->setSkapad($this->timeFactory->getDateTime());
            $this->bevakningMapper->insert($b);
            $this->loggaBev($hubsCaseId, 'backfill', $b, Bevakning::AKTOR_SYSTEM);
            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('hubs_arende: backfillForCase misslyckades (graceful)', [
                'app' => 'hubs_arende', 'hubsCaseId' => $arende->getHubsCaseId(), 'exception' => $e->getMessage(),
            ]);
            return false;
        }
    }

    // ==================================================================== //
    //  PRIVATA HJÄLPARE
    // ==================================================================== //

    /**
     * Skapa + persista en bevakning ur en mall, och dess Deck-kort.
     *
     * @param array<string,mixed> $mall
     * @param array<string,mixed> $rad  Registreringsdata (för inkom_datum-ankaret) — endast vid födelse.
     */
    private function instansiera(Arende $arende, array $mall, string $aktor, array $rad = []): void {
        $ankare = (string)($mall['ankare'] ?? Bevakning::ANKARE_STEG);
        $dagar = isset($mall['ankareDagar']) && $mall['ankareDagar'] !== null ? (int)$mall['ankareDagar'] : null;
        $frist = $this->berakningFrist($ankare, $dagar, $arende, $rad);

        $b = new Bevakning();
        $b->setHubsCaseId($arende->getHubsCaseId());
        $b->setTyp((string)($mall['typ'] ?? 'standard'));
        $b->setTitel(mb_substr((string)($mall['titel'] ?? 'Bevakning'), 0, 255));
        $b->setVillkorTyp((string)($mall['villkorTyp'] ?? Bevakning::VILLKOR_MANUELL_KVITTERING));
        $b->setVillkorArg(isset($mall['villkorArg']) ? (string)$mall['villkorArg'] : null);
        $b->setStatus(Bevakning::STATUS_AKTIV);
        $b->setFristDue($frist);
        $b->setAnkare($ankare);
        $b->setRecurringDagar(isset($mall['recurringDagar']) && $mall['recurringDagar'] !== null ? (int)$mall['recurringDagar'] : null);
        $b->setLagstadgad((bool)($mall['lagstadgad'] ?? false));
        $b->setSkapadAv($aktor);
        $b->setForsenad(false);
        $b->setSkapad($this->timeFactory->getDateTime());
        $b = $this->bevakningMapper->insert($b);

        $this->skapaDeckKort($arende, $b);
        $this->loggaBev($arende->getHubsCaseId(), 'skapad', $b, $aktor);
    }

    /** Beräkna frist_due ur ankare + antal dagar. Null = ingen deadline. */
    private function berakningFrist(string $ankare, ?int $dagar, Arende $arende, array $rad): ?\DateTime {
        if ($dagar === null) {
            return null;
        }
        $bas = match ($ankare) {
            Bevakning::ANKARE_INKOM => $this->inkomDatum($rad, $arende),
            Bevakning::ANKARE_DELGIVNING => $arende->getDelgivningsdatum(),
            Bevakning::ANKARE_CYKEL, Bevakning::ANKARE_STEG => $this->timeFactory->getDateTime(),
            default => $this->timeFactory->getDateTime(),
        };
        if ($bas === null) {
            return null; // ankaret saknas ännu (t.ex. delgivning ej satt)
        }
        return (clone $bas)->add(new \DateInterval('P' . $dagar . 'D'));
    }

    private function inkomDatum(array $rad, Arende $arende): \DateTime {
        $raw = $rad['inkomDatum'] ?? $rad['inkom_datum'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $d = $this->parseDate($raw);
            if ($d !== null) {
                return $d;
            }
        }
        return $arende->getSkapad() ?? $this->timeFactory->getDateTime();
    }

    /**
     * Gemensam avslutsväg: sätt status + uppnadd-metadata, projicera Deck, journalföra.
     * Uppfyllt villkor efter passerad frist markeras `forsenad`. Recurring föder ny post.
     */
    private function markUppnadd(Bevakning $b, string $aktor): void {
        $nu = $this->timeFactory->getDateTime();
        $frist = $b->getFristDue();
        $b->setForsenad($frist !== null && $frist->format('Y-m-d') < $nu->format('Y-m-d'));
        $b->setStatus(Bevakning::STATUS_UPPNADD);
        $b->setUppnaddDatum($nu);
        $b->setUppnaddAv($aktor);
        $this->bevakningMapper->update($b);
        $this->avslutDeckKort($b, true);
        $this->loggaBev($b->getHubsCaseId(), $b->getForsenad() ? 'uppnadd_forsenad' : 'uppnadd', $b, $aktor);
        $this->aterarmera($b, $aktor);
    }

    /** Sätt slutstatus (avbruten/passerad) + arkivera Deck-kortet. */
    private function avslut(Bevakning $b, string $status, string $aktor, string $orsak): void {
        $b->setStatus($status);
        if ($status === Bevakning::STATUS_AVBRUTEN) {
            $b->setUppnaddDatum($this->timeFactory->getDateTime());
            $b->setUppnaddAv($aktor);
        }
        $this->bevakningMapper->update($b);
        $this->avslutDeckKort($b, false);
        $this->loggaBev($b->getHubsCaseId(), 'avbruten:' . $orsak, $b, $aktor);
    }

    /** Recurring: en uppnådd/kvitterad cykel föder nästa (historiken bevaras). */
    private function aterarmera(Bevakning $klar, string $aktor): void {
        $cykel = $klar->getRecurringDagar();
        if ($cykel === null) {
            return;
        }
        try {
            $arende = $this->arendeMapper->findByCaseId($klar->getHubsCaseId());
        } catch (\Throwable) {
            return;
        }
        $ny = new Bevakning();
        $ny->setHubsCaseId($klar->getHubsCaseId());
        $ny->setTyp($klar->getTyp());
        $ny->setTitel($klar->getTitel());
        $ny->setVillkorTyp($klar->getVillkorTyp());
        $ny->setVillkorArg($klar->getVillkorArg());
        $ny->setStatus(Bevakning::STATUS_AKTIV);
        $ny->setFristDue((clone $this->timeFactory->getDateTime())->add(new \DateInterval('P' . $cykel . 'D')));
        $ny->setAnkare(Bevakning::ANKARE_CYKEL);
        $ny->setRecurringDagar($cykel);
        $ny->setLagstadgad($klar->getLagstadgad());
        $ny->setSkapadAv(Bevakning::AKTOR_SYSTEM);
        $ny->setForsenad(false);
        $ny->setSkapad($this->timeFactory->getDateTime());
        $ny = $this->bevakningMapper->insert($ny);
        $this->skapaDeckKort($arende, $ny);
        $this->loggaBev($ny->getHubsCaseId(), 'recurring_ny', $ny, $aktor);
    }

    /**
     * A8 — säkerställ att en aktiv lagstadgad omprövnings-/övervägandebevakning
     * finns när ett omprövningspliktigt ärende går in i uppföljning.
     *
     * Gate: bara vid nyttSteg='uppfoljning', bara om typen har omprovningskrav OCH
     * GrindConfig.autoOmprovning() är på (kod-default AV ⇒ testharness/prod orörda).
     * Idempotent: om steg (3):s mall redan skapat en aktiv overvägande/omprövning
     * (typ innehåller 'overvagande'/'omprovning') görs ingenting. Annars instansieras
     * en bevakning med SAMMA form som de seedade mallarna (manuell_kvittering,
     * recurring 180 d, lagstadgad, villkorArg='uppfoljning').
     */
    private function sakerstallOmprovningsbevakning(
        string $hubsCaseId,
        string $nyttSteg,
        ?ArendeTyp $typ,
        Arende $arende,
        string $aktor,
    ): void {
        if ($nyttSteg !== 'uppfoljning') {
            return;
        }
        if ($typ === null || $typ->getOmprovningskrav() !== true) {
            return;
        }
        if ($this->grindConfig === null || !$this->grindConfig->autoOmprovning()) {
            return; // flaggan AV (kod-default) ⇒ gammalt beteende, ingen autoskapning
        }
        // Idempotens: finns redan en aktiv overvägande/omprövning? (t.ex. skapad av
        // typens mall i steg (3), eller av en tidigare uppföljningsövergång).
        foreach ($this->bevakningMapper->findAktivaByCaseId($hubsCaseId) as $b) {
            $t = $b->getTyp();
            if (str_contains($t, 'overvagande') || str_contains($t, 'omprovning')) {
                return;
            }
        }
        // Ingen fanns → skapa säkerhetsnätet med samma form som seedmallarna.
        $mall = [
            'typ' => 'omprovning_6man',
            'titel' => 'Övervägande/omprövning av vården (var 6:e månad)',
            'villkorTyp' => Bevakning::VILLKOR_MANUELL_KVITTERING,
            'villkorArg' => 'uppfoljning',
            'ankare' => Bevakning::ANKARE_STEG,
            'ankareDagar' => 180,
            'recurringDagar' => 180,
            'lagstadgad' => true,
            'vidSteg' => 'uppfoljning',
        ];
        $this->instansiera($arende, $mall, $aktor);
    }

    /**
     * @return array<int,array<string,mixed>> Mallar för ett givet vidSteg ('fodelse' eller stegnamn).
     */
    private function mallarForSteg(ArendeTyp $typ, string $steg): array {
        $raw = $typ->getBevakningsmallar();
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $ut = [];
        foreach ($decoded as $m) {
            if (is_array($m) && (string)($m['vidSteg'] ?? 'fodelse') === $steg) {
                $ut[] = $m;
            }
        }
        return $ut;
    }

    // --- Deck-projektionen (graceful) ---

    private function skapaDeckKort(Arende $arende, Bevakning $b): void {
        if ($this->deckClient === null) {
            return;
        }
        try {
            $card = $this->deckClient->createCard(
                (string)$arende->getEnhet(),
                $this->kortRef($arende->getHubsCaseId()) . ': ' . $b->getTitel(),
                $b->getFristDue()?->format('c'),
                'Bevakning (' . $b->getTyp() . ') för ärende ' . $this->kortRef($arende->getHubsCaseId())
                    . '. Villkor: ' . $b->getVillkorTyp() . '.',
            );
            if ($card === null) {
                return;
            }
            $this->deckClient->addLabel($card['boardId'], $card['cardId'], 'case:' . $arende->getHubsCaseId());
            $b->setDeckBoardId((string)$card['boardId']);
            $b->setDeckCardId((string)$card['cardId']);
            $this->bevakningMapper->update($b);
            // Registrera som deck_card-pekare så gallring + saga-kompensation river
            // kortet uniformt (samma modell som R5).
            $this->pekareMapper?->record(
                $arende->getHubsCaseId(),
                'deck_card',
                (string)$card['cardId'],
                (string)$card['boardId'],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('hubs_arende: skapaDeckKort misslyckades (graceful)', [
                'app' => 'hubs_arende', 'hubsCaseId' => $arende->getHubsCaseId(), 'exception' => $e->getMessage(),
            ]);
        }
    }

    private function uppdateraDeckKortFrist(Arende $arende, Bevakning $b): void {
        if ($this->deckClient === null || $b->getDeckBoardId() === null || $b->getDeckCardId() === null) {
            return;
        }
        try {
            $this->deckClient->updateCard(
                (int)$b->getDeckBoardId(),
                (int)$b->getDeckCardId(),
                ['duedate' => $b->getFristDue()?->format('c')],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('hubs_arende: uppdateraDeckKortFrist misslyckades (graceful)', [
                'app' => 'hubs_arende', 'hubsCaseId' => $arende->getHubsCaseId(), 'exception' => $e->getMessage(),
            ]);
        }
    }

    /** Uppnadd ⇒ markDone; avbruten/passerad ⇒ archive. Graceful. */
    private function avslutDeckKort(Bevakning $b, bool $uppnadd): void {
        if ($this->deckClient === null || $b->getDeckBoardId() === null || $b->getDeckCardId() === null) {
            return;
        }
        try {
            $board = (int)$b->getDeckBoardId();
            $card = (int)$b->getDeckCardId();
            if ($uppnadd) {
                $this->deckClient->markDone($board, $card);
            } else {
                $this->deckClient->archiveCard($board, $card);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('hubs_arende: avslutDeckKort misslyckades (graceful)', [
                'app' => 'hubs_arende', 'hubsCaseId' => $b->getHubsCaseId(), 'exception' => $e->getMessage(),
            ]);
        }
    }

    private function loggaBev(string $hubsCaseId, string $handling, Bevakning $b, string $aktor): void {
        // KOORDINATIONSDATA UTAN PII: aldrig titel-fritext i journaldetalj.
        $this->handelseMapper?->record($hubsCaseId, Handelse::TYP_BEVAKNING, [
            'handling' => $handling,
            'typ' => $b->getTyp(),
            'villkor' => $b->getVillkorTyp(),
            'status' => $b->getStatus(),
            'bevakningId' => $b->getId(),
            'lagstadgad' => $b->getLagstadgad(),
        ], $aktor);
    }

    private function kortRef(string $hubsCaseId): string {
        return 'Ärende ' . strtoupper(substr($hubsCaseId, 0, 8));
    }

    private function parseDate(string $raw): ?\DateTime {
        try {
            return new \DateTime($raw);
        } catch (\Throwable) {
            return null;
        }
    }
}
