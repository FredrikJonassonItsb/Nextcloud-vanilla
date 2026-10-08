<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Command;

use OCA\HubsArende\Service\ArendeService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ hubs_arende:brain-halsa <token> <arenderum|dokumentchatt> [fil]` — posta
 * brain-botens inledande hälsning i ett rum. Idempotent (en gång per rum via
 * brain_greeting-pekare) och admin-gated (config brain_greeting). Anropas av
 * bot-reconcile för dokument-rum som upptäcks efter att en användare öppnat
 * dela→chatt (SAGA/registreraDokumentchatt hälsar redan för genererade handlingar).
 */
class BrainHalsa extends Command {
    public function __construct(
        private ArendeService $arendeService,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('hubs_arende:brain-halsa')
            ->setDescription('Posta brain-botens inledande hälsning i ett rum (idempotent, admin-gated).')
            ->addArgument('token', InputArgument::REQUIRED, 'Talk-rummets token')
            ->addArgument('typ', InputArgument::REQUIRED, 'arenderum | dokumentchatt')
            ->addArgument('fil', InputArgument::OPTIONAL, 'Filnamn (endast för dokumentchatt)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $token = (string)$input->getArgument('token');
        $typ = (string)$input->getArgument('typ');
        $fil = $input->getArgument('fil');
        if ($token === '' || !in_array($typ, ['arenderum', 'dokumentchatt'], true)) {
            $output->writeln('<error>Användning: hubs_arende:brain-halsa <token> <arenderum|dokumentchatt> [fil]</error>');
            return 1;
        }
        $this->arendeService->postaBrainHalsning($token, $typ, is_string($fil) ? $fil : null);
        return 0;
    }
}
