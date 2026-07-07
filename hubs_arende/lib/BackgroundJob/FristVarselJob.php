<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\BackgroundJob;

use OCA\HubsArende\Db\Arende;
use OCA\HubsArende\Db\ArendeMapper;
use OCA\HubsArende\Db\Member;
use OCA\HubsArende\Db\MemberMapper;
use OCA\HubsArende\Notification\Notifier;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Dagligt FRIST-VARSEL: notifiera ärendets aktiva medlemmar när fristen närmar
 * sig eller passeras — varsel-lagret som gör "Kräver åtgärd nu" proaktivt
 * (handläggaren ska inte behöva polla dashboarden med ögonen).
 *
 * VARSEL-PUNKTER (deterministiska, ingen extra state): jobbet kör 1×/dygn och
 * varslar när det är EXAKT 3 dagar kvar respektive på förfallodagen (0 dagar) —
 * varje tröskel fyras därmed högst en gång per ärende. Redan-förfallna rader
 * varslas inte om varje dag (spam) — förfallodags-varslet är sista pushen;
 * därefter bär dashboardens röda varsel-lista ansvaret.
 *
 * MOTTAGARE: den tilldelade handläggaren när ärendet är tilldelat; annars hela
 * mottagningskretsen (samma handoff-logik som åtkomstlistan). PII-invariant:
 * notisen bär ENDAST pseudonym referens + datum.
 */
class FristVarselJob extends TimedJob {
    public function __construct(
        ITimeFactory $time,
        private ArendeMapper $arendeMapper,
        private MemberMapper $memberMapper,
        private INotificationManager $notificationManager,
        private LoggerInterface $logger,
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
            $nu = $this->time->getDateTime();
            $idag = new \DateTime($nu->format('Y-m-d'));
            // Hämta allt som förfaller inom varsel-horisonten (3 dagar).
            $horisont = (clone $idag)->modify('+3 days')->setTime(23, 59, 59);
            $varslade = 0;

            foreach ($this->arendeMapper->findMedFristSenast($horisont) as $arende) {
                $frist = $arende->getFristDue();
                if ($frist === null) {
                    continue;
                }
                $fristDag = new \DateTime($frist->format('Y-m-d'));
                $dagarKvar = (int)$idag->diff($fristDag)->format('%r%a');
                // Deterministiska trösklar: T-3 och förfallodagen. (Redan förfallet
                // varslas inte dagligen — dashboarden bär det röda läget.)
                if ($dagarKvar !== 3 && $dagarKvar !== 0) {
                    continue;
                }
                foreach ($this->mottagare($arende) as $uid) {
                    $this->notifiera($arende, $uid, $frist);
                    $varslade++;
                }
            }

            $this->logger->info('hubs_arende: FristVarselJob klar', [
                'app' => 'hubs_arende',
                'varslade' => $varslade,
            ]);
        } catch (\Throwable $e) {
            // Ett fel i svepet får aldrig krascha cron-runnern.
            $this->logger->error('hubs_arende: FristVarselJob fel', [
                'app' => 'hubs_arende',
                'exception' => $e,
            ]);
        }
    }

    /**
     * Varsel-mottagare: tilldelad handläggare, annars mottagningskretsen
     * (samma handoff-avsmalning som åtkomstlistan).
     *
     * @return string[]
     */
    private function mottagare(Arende $arende): array {
        $agare = $arende->getAgareUid();
        if ($agare !== null && $agare !== '') {
            return [$agare];
        }
        $uids = [];
        foreach ($this->memberMapper->findByCaseId($arende->getHubsCaseId()) as $m) {
            if ($m->getRoll() === Member::ROLL_MOTTAGNINGSKRETS) {
                $uids[$m->getUid()] = true;
            }
        }
        return array_map('strval', array_keys($uids));
    }

    private function notifiera(Arende $arende, string $uid, \DateTime $frist): void {
        try {
            $notis = $this->notificationManager->createNotification();
            $notis->setApp(Notifier::APP_ID)
                ->setUser($uid)
                ->setDateTime($this->time->getDateTime())
                ->setObject('arende-frist', substr($arende->getHubsCaseId(), 0, 64))
                ->setSubject(Notifier::SUBJECT_FRIST, [
                    'ref' => (string)($arende->getDnr() ?? $arende->getHubsCaseId()),
                    'datum' => $frist->format('Y-m-d'),
                ]);
            $this->notificationManager->notify($notis);
        } catch (\Throwable $e) {
            $this->logger->warning('hubs_arende: frist-notis misslyckades (graceful)', [
                'app' => 'hubs_arende',
                'hubsCaseId' => $arende->getHubsCaseId(),
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
