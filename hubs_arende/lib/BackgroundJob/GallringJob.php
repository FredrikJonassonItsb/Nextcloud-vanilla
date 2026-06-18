<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\BackgroundJob;

use OCA\HubsArende\Service\GallringService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Dygnsvis GDPR-gallring (art. 5.1.e — lagringsminimering) av motorns egna
 * koordinations-/routing-rader efter att facksystemet tagit över.
 *
 * NEVER-SoR: verksamhetsdatat bor i facksystemet och gallras av DET; detta job
 * driver enbart {@see GallringService::gallra()}, som purgar motorns pseudonyma
 * pekar-/routing-rad (registret + dess pekare) för rader vars verkställbara
 * gallrings-deadline från det verifierade commit-kvittot har passerat.
 *
 * Körs som TIME_INSENSITIVE (kan skjutas till lågtrafik) en gång per dygn. All
 * räkning/loggning sker i servicen (enbart antal + pseudonyma hubsCaseId:n).
 */
class GallringJob extends TimedJob {
    public function __construct(
        ITimeFactory $time,
        private GallringService $gallringService,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        // En gång per dygn. Insensitiv ⇒ får skjutas till off-hours (NC loggar
        // annars en debug-varning för ett tidskänsligt job med >=12h-intervall).
        $this->setInterval(24 * 3600);
        $this->setTimeSensitivity(self::TIME_INSENSITIVE);
    }

    /**
     * @param mixed $argument Oanvänt — sweepen är parameterlös.
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    protected function run($argument): void {
        try {
            $resultat = $this->gallringService->gallra();
            $this->logger->info('hubs_arende: GallringJob klar', [
                'app' => 'hubs_arende',
                'antal' => $resultat['antal'],
            ]);
        } catch (\Throwable $e) {
            // Ett fel i sweepen får aldrig krascha cron-runnern; logga och fortsätt.
            // Nästa dygns körning tar samma (idempotenta) batch igen.
            $this->logger->error('hubs_arende: GallringJob fel', [
                'app' => 'hubs_arende',
                'exception' => $e,
            ]);
        }
    }
}
