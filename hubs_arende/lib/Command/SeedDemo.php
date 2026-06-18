<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Command;

use OCA\HubsArende\Service\DemoSeedService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ hubs_arende:seed-demo [--purge]` — DEV/DEMO TOOL.
 *
 * Tunn occ-yta över {@see DemoSeedService}: seedar en kurerad uppsättning
 * SYNTETISKA demo-ärenden genom den riktiga motorn (createCase → lifecycle-
 * transitions → tilldelning → commit) så att admin-statussidan och dashboarden
 * visar en livfull spridning över ärendetyp / steg / status / provenans.
 *
 * Allt är pseudonymt (objektRef/conversationId 'demo-…') — INGEN PII. Varje rad är
 * taggad via sin conversationId-prefix 'demo-' så att `--purge` kan ta bort exakt
 * de seedade raderna (och deras pekare) igen. Idempotent: en omkörning återanvänder
 * befintliga rader (createCase är idempotent på conversationId).
 *
 * All seed-/purge-logik (+ de 10 CASES) bor i {@see DemoSeedService} och delas med
 * admin-OCS-endpointen (AdminController#seedDemo). Detta kommando delegerar bara.
 */
class SeedDemo extends Command {
    public function __construct(
        private DemoSeedService $demoSeedService,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('hubs_arende:seed-demo')
            ->setDescription('DEV/DEMO: seeda syntetiska demo-ärenden (pseudonymt, ingen PII). --purge rensar dem.')
            ->addOption('purge', null, InputOption::VALUE_NONE, 'Ta bort alla demo-rader (conversationId demo-%) i stället för att seeda.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        if ($input->getOption('purge')) {
            return $this->purge($output);
        }
        return $this->seed($output);
    }

    private function seed(OutputInterface $output): int {
        $output->writeln('<info>Seedar demo-ärenden (syntetiskt, ingen PII)…</info>');
        $resultat = $this->demoSeedService->seed();
        $skapade = $resultat['skapade'];
        $fel = $resultat['fel'];
        $output->writeln('');
        $output->writeln(sprintf('<info>Klart: %d demo-ärenden seedade, %d fel.</info>', $skapade, $fel));
        $output->writeln('Kör <info>occ hubs_arende:status</info> eller öppna Admin → Hubs Ärende. Rensa med <info>--purge</info>.');
        return $fel === 0 ? 0 : 1;
    }

    private function purge(OutputInterface $output): int {
        $output->writeln('<info>Rensar demo-rader (conversationId demo-%)…</info>');
        $antal = $this->demoSeedService->purge();
        $output->writeln(sprintf('<info>Klart: %d demo-rader (+ pekare) borttagna.</info>', $antal));
        return 0;
    }
}
