<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Command;

use OCA\HubsArende\Service\ArendeService;
use OCA\HubsArende\Service\StatusService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ hubs_arende:status` — print the PII-free engine status (same data the admin
 * status page renders). Useful for ops without the web UI. Read-only.
 */
class Status extends Command {
    public function __construct(
        private StatusService $statusService,
        private ArendeService $arendeService,
    ) {
        parent::__construct();
    }

    protected function configure(): void {
        $this->setName('hubs_arende:status')
            ->setDescription('Visa aggregerad, PII-fri motorstatus (räknare + config + ärendetyp-register).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        $s = $this->statusService->status();

        $output->writeln('<info>=== Hubs Ärende — motorstatus ===</info>');
        $output->writeln('  version                 = ' . ($s['version'] ?? '?'));
        $output->writeln('  antal ärenden           = ' . ($s['totalCases'] ?? 0));
        $output->writeln('  väntar på gallring      = ' . ($s['gallringsbaraPending'] ?? 0));

        $output->writeln('<info>  integrationsläge (per port)</info>');
        foreach (($s['integrationMode'] ?? []) as $port => $mode) {
            $output->writeln('    ' . str_pad((string)$port, 14) . '= ' . $mode);
        }

        $this->printMap($output, 'per steg', $s['perSteg'] ?? []);
        $this->printMap($output, 'per status', $s['perStatus'] ?? []);
        $this->printMap($output, 'per provenans', $s['perProvenance'] ?? []);
        $this->printMap($output, 'per commit-destination', $s['perCommitDestination'] ?? []);

        $typer = $s['arendetyper'] ?? [];
        $output->writeln('<info>  ärendetyp-register (' . count($typer) . ' typer)</info>');
        foreach ($typer as $t) {
            $hooks = [];
            if (!empty($t['preSagaHook'])) {
                $hooks[] = 'pre:' . $t['preSagaHook'];
            }
            if (!empty($t['postCommitHook'])) {
                $hooks[] = 'post:' . $t['postCommitHook'];
            }
            $output->writeln(sprintf(
                '    %-22s -> %-16s %s',
                (string)($t['id'] ?? ''),
                (string)($t['commitDestination'] ?? ''),
                $hooks === [] ? '' : '(' . implode(', ', $hooks) . ')',
            ));
        }

        // Dashboard-kort (CLI = system-kontext → ser alla; exakt formen frontend får).
        $cards = $this->arendeService->dashboardArenden();
        $output->writeln('<info>  dashboard-kort (Mina ärenden)</info> = ' . count($cards));
        foreach (array_slice($cards, 0, 5) as $c) {
            $output->writeln(sprintf(
                '    %-26s typ=%-16s steg=%-16s status=%-11s frist=%s',
                (string)($c['triageRef'] ?? ''),
                (string)($c['arendeTyp'] ?? ''),
                (string)($c['steg'] ?? ''),
                (string)($c['status'] ?? ''),
                isset($c['frist']['due']) ? (string)$c['frist']['due'] : '-',
            ));
        }

        return 0;
    }

    /** @param array<string,int> $map */
    private function printMap(OutputInterface $output, string $label, array $map): void {
        $output->writeln('<info>  ' . $label . '</info>');
        if ($map === []) {
            $output->writeln('    (inga)');
            return;
        }
        foreach ($map as $k => $v) {
            $output->writeln('    ' . str_pad((string)$k, 22) . '= ' . $v);
        }
    }
}
