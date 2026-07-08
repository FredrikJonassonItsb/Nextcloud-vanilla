<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\BackgroundJob;

use OCA\HubsArende\Db\Arende;
use OCA\HubsArende\Db\ArendeMapper;
use OCA\HubsArende\Db\Bevakning;
use OCA\HubsArende\Db\BevakningMapper;
use OCA\HubsArende\Db\Member;
use OCA\HubsArende\Db\MemberMapper;
use OCA\HubsArende\Notification\Notifier;
use OCA\HubsArende\Service\BevakningService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Dagligt BEVAKNINGS-VARSEL: driver bevakningsregistrets tidsstyrda livscykel och
 * varslar ärendets aktiva medlemmar när en bevakning närmar sig sin frist eller
 * har passerats — varsel-lagret som gör "Kräver åtgärd nu" proaktivt (samma roll
 * som {@see FristVarselJob}, men per BEVAKNING i stället för ärendets legacy-frist).
 *
 * TVÅ ANSVAR (1×/dygn):
 *  1. Tidsstyrda tillståndsövergångar (best-effort, får aldrig kasta):
 *     {@see BevakningService::bearbetaForfallnaDatum()} flippar rena datum-
 *     bevakningar till uppnadd, {@see BevakningService::flaggaPasserade()} sätter
 *     larmläget PASSERAD på överskridna aktiva bevakningar.
 *  2. Varsel på deterministiska trösklar T-7, T-3 och T-0 (varje tröskel fyras
 *     högst en gång per bevakning eftersom jobbet kör 1×/dygn — ingen extra state).
 *
 * MOTTAGARE: den tilldelade handläggaren när ärendet är tilldelat; annars hela
 * mottagningskretsen (samma handoff-avsmalning som åtkomstlistan och FristVarselJob).
 *
 * ESKALERING (ratificerad design: plattformsnotiser till handläggare + arbetsledare
 * + dashboardens röda lista, INGEN e-post): en PASSERAD och LAGSTADGAD bevakning
 * (missad rättslig frist) varslar DESSUTOM ärendets arbetsledare. Rollmodellen har
 * ingen egen arbetsledar-/fördelar-roll — närmast är {@see Member::ROLL_OBSERVATOR}
 * ("read-only participant (e.g. arbetsledare / insyn)"), så eskaleringen går till
 * observatörerna; saknas observatörer faller den tillbaka på mottagningskretsen så
 * att larmet aldrig blir tyst.
 *
 * PII-invariant: notisen bär ENDAST koordinationsdata (pseudonym titel, dagarKvar,
 * status, lagstadgad, hubsCaseId, typ) — aldrig namn/personnummer/sakinnehåll. Vi
 * loggar aldrig titeln (även om den är pseudonym) — endast bevakningId/status/
 * hubsCaseId/dagarKvar.
 */
class BevakningVarselJob extends TimedJob {
    /** Varsel-horisont i dagar (T-7 är den första tröskeln). */
    private const HORISONT_DAGAR = 7;
    /** Deterministiska varsel-trösklar (dagar kvar till frist). */
    private const TROSKLAR = [7, 3, 0];

    public function __construct(
        ITimeFactory $time,
        private BevakningMapper $bevakningMapper,
        private ArendeMapper $arendeMapper,
        private MemberMapper $memberMapper,
        private INotificationManager $notificationManager,
        private LoggerInterface $logger,
        private BevakningService $bevakningService,
    ) {
        parent::__construct($time);
        $this->setInterval(24 * 3600);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    /**
     * @param mixed $argument Oanvänt.
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    protected function run($argument): void {
        try {
            // (1) Tidsstyrda övergångar körs FÖRE varslen så att PASSERAD-läget är
            //     satt när vi läser registret nedan. Best-effort: ett fel i motorn
            //     får inte stoppa varslen (och tvärtom).
            $this->driftaLivscykel();

            $nu = $this->time->getDateTime();
            $idag = new \DateTime($nu->format('Y-m-d'));
            $horisont = (clone $idag)->modify('+' . self::HORISONT_DAGAR . ' days')->setTime(23, 59, 59);
            $varslade = 0;

            foreach ($this->bevakningMapper->findMedFristSenast($horisont) as $bevakning) {
                $frist = $bevakning->getFristDue();
                if ($frist === null) {
                    continue;
                }
                $fristDag = new \DateTime($frist->format('Y-m-d'));
                $dagarKvar = (int)$idag->diff($fristDag)->format('%r%a');

                $arende = $this->arendeForBevakning($bevakning);
                if ($arende === null) {
                    continue;
                }

                // Deterministiska trösklar: T-7, T-3 och förfallodagen (T-0). Redan
                // förfallet varslas inte dagligen till normal-mottagarna — där bär
                // dashboardens röda lista läget; men lagstadgat larm eskaleras nedan.
                if (in_array($dagarKvar, self::TROSKLAR, true)) {
                    foreach ($this->mottagare($arende) as $uid) {
                        $this->notifiera($bevakning, $uid, $dagarKvar);
                        $varslade++;
                    }
                }

                // ESKALERING: en missad LAGSTADGAD frist (larmläge) varslar dessutom
                // arbetsledaren (observatörer) — varje dygn tills villkoret uppnås.
                if ($bevakning->getStatus() === Bevakning::STATUS_PASSERAD
                    && $bevakning->getLagstadgad()
                    && $dagarKvar < 0) {
                    foreach ($this->eskaleringsMottagare($arende) as $uid) {
                        $this->notifiera($bevakning, $uid, $dagarKvar);
                        $varslade++;
                    }
                }
            }

            $this->logger->info('hubs_arende: BevakningVarselJob klar', [
                'app' => 'hubs_arende',
                'varslade' => $varslade,
            ]);
        } catch (\Throwable $e) {
            // Ett fel i svepet får aldrig krascha cron-runnern.
            $this->logger->error('hubs_arende: BevakningVarselJob fel', [
                'app' => 'hubs_arende',
                'exception' => $e,
            ]);
        }
    }

    /**
     * Driva de tidsstyrda tillståndsövergångarna (datum-uppnadd + passerad-larm).
     * Best-effort: fel loggas som varning men stoppar aldrig varsel-svepet.
     */
    private function driftaLivscykel(): void {
        try {
            $this->bevakningService->bearbetaForfallnaDatum();
        } catch (\Throwable $e) {
            $this->logger->warning('hubs_arende: bearbetaForfallnaDatum misslyckades (graceful)', [
                'app' => 'hubs_arende',
                'exception' => $e->getMessage(),
            ]);
        }
        try {
            $this->bevakningService->flaggaPasserade();
        } catch (\Throwable $e) {
            $this->logger->warning('hubs_arende: flaggaPasserade misslyckades (graceful)', [
                'app' => 'hubs_arende',
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /** Ärendet en bevakning hör till, eller null om det inte längre finns. */
    private function arendeForBevakning(Bevakning $bevakning): ?Arende {
        try {
            return $this->arendeMapper->findByCaseId($bevakning->getHubsCaseId());
        } catch (DoesNotExistException) {
            return null;
        } catch (\Throwable $e) {
            $this->logger->warning('hubs_arende: ärende-läsning för bevakning misslyckades (graceful)', [
                'app' => 'hubs_arende',
                'hubsCaseId' => $bevakning->getHubsCaseId(),
                'bevakningId' => $bevakning->getId(),
                'exception' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Varsel-mottagare: tilldelad handläggare, annars mottagningskretsen (samma
     * handoff-avsmalning som åtkomstlistan och FristVarselJob).
     *
     * @return string[]
     */
    private function mottagare(Arende $arende): array {
        $agare = $arende->getAgareUid();
        if ($agare !== null && $agare !== '') {
            return [$agare];
        }
        return $this->uidsForRoll($arende, Member::ROLL_MOTTAGNINGSKRETS);
    }

    /**
     * Eskaleringsmottagare för en missad lagstadgad frist: ärendets arbetsledare.
     * Rollmodellen har ingen egen arbetsledar-/fördelar-roll — {@see Member::ROLL_OBSERVATOR}
     * dokumenteras som "read-only participant (e.g. arbetsledare / insyn)", så det är
     * rollen vi valde. Faller tillbaka på mottagningskretsen om inga observatörer finns,
     * så att det rättsliga larmet aldrig blir tyst.
     *
     * @return string[]
     */
    private function eskaleringsMottagare(Arende $arende): array {
        $observatorer = $this->uidsForRoll($arende, Member::ROLL_OBSERVATOR);
        if (count($observatorer) > 0) {
            return $observatorer;
        }
        return $this->uidsForRoll($arende, Member::ROLL_MOTTAGNINGSKRETS);
    }

    /**
     * Deduplicerade uids för en given roll i ärenderummet.
     *
     * @return string[]
     */
    private function uidsForRoll(Arende $arende, string $roll): array {
        $uids = [];
        foreach ($this->memberMapper->findByCaseId($arende->getHubsCaseId()) as $m) {
            if ($m->getRoll() === $roll) {
                $uids[$m->getUid()] = true;
            }
        }
        return array_map('strval', array_keys($uids));
    }

    private function notifiera(Bevakning $bevakning, string $uid, int $dagarKvar): void {
        try {
            $notis = $this->notificationManager->createNotification();
            $notis->setApp(Notifier::APP_ID)
                ->setUser($uid)
                ->setDateTime($this->time->getDateTime())
                ->setObject('bevakning', (string)$bevakning->getId())
                ->setSubject(Notifier::SUBJECT_BEVAKNING, [
                    // KOORDINATIONSDATA UTAN PII: titeln är pseudonym (aldrig namn/pnr).
                    'titel' => $bevakning->getTitel(),
                    'dagarKvar' => $dagarKvar,
                    'status' => $bevakning->getStatus(),
                    'lagstadgad' => $bevakning->getLagstadgad(),
                    'hubsCaseId' => $bevakning->getHubsCaseId(),
                    'typ' => $bevakning->getTyp(),
                ]);
            $this->notificationManager->notify($notis);
        } catch (\Throwable $e) {
            // PII-säker logg: ALDRIG titeln — bara bevakningId/status/hubsCaseId/dagarKvar.
            $this->logger->warning('hubs_arende: bevaknings-notis misslyckades (graceful)', [
                'app' => 'hubs_arende',
                'bevakningId' => $bevakning->getId(),
                'status' => $bevakning->getStatus(),
                'hubsCaseId' => $bevakning->getHubsCaseId(),
                'dagarKvar' => $dagarKvar,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
