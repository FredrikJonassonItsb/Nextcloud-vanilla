<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsStart\Command;

use OCA\HubsStart\Service\FavoriterSeedService;
use OCP\IGroupManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ hubs_start:seed-favoriter [--user=<uid>]` — DEV/DEMO TOOL.
 *
 * Thin occ surface over {@see FavoriterSeedService}: plants the 11 curated
 * SYNTHETIC favorite vCards into the target user's "Favoriter" CardDAV address
 * book so the dashboard has favorites data on a fresh instance.
 *
 * Target selection:
 *   --user=<uid>  seed exactly that user.
 *   (omitted)     seed every member of the 'admin' group (the demo operators).
 *
 * NO credentials, NO curl, NO tokens — the service calls the DAV app's internal
 * CardDavBackend in-process. Idempotent: a re-run reuses the existing book and
 * skips cards already present. All data is synthetic (function addresses, no PII).
 */
class SeedFavoriter extends Command {

	public function __construct(
		private readonly FavoriterSeedService $seedService,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('hubs_start:seed-favoriter')
			->setDescription('DEV/DEMO: seeda syntetiska favoriter (funktionsadresser, ingen PII) i användarens "Favoriter"-adressbok.')
			->addOption(
				'user',
				null,
				InputOption::VALUE_REQUIRED,
				'Användar-id att seeda. Utelämnas → alla medlemmar i admin-gruppen.',
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$user = $input->getOption('user');

		$userIds = is_string($user) && $user !== ''
			? [$user]
			: $this->adminUserIds();

		if ($userIds === []) {
			$output->writeln('<error>Inga användare att seeda. Ange --user=<uid> eller skapa en admin-användare.</error>');
			return 1;
		}

		$totalCreated = 0;
		$totalSkipped = 0;
		foreach ($userIds as $userId) {
			$output->writeln(sprintf('<info>Seedar favoriter för "%s" (syntetiskt, ingen PII)…</info>', $userId));
			$result = $this->seedService->seed($userId);
			$totalCreated += $result['created'];
			$totalSkipped += $result['skipped'];
			$output->writeln(sprintf('  → %d skapade, %d hoppades över.', $result['created'], $result['skipped']));
		}

		$output->writeln('');
		$output->writeln(sprintf(
			'<info>Klart: %d favoriter skapade, %d hoppades över (redan fanns) över %d användare.</info>',
			$totalCreated,
			$totalSkipped,
			count($userIds),
		));
		return 0;
	}

	/**
	 * The user ids of the 'admin' group — the default seed targets.
	 *
	 * @return list<string>
	 */
	private function adminUserIds(): array {
		$adminGroup = $this->groupManager->get('admin');
		if ($adminGroup === null) {
			return [];
		}

		$ids = [];
		foreach ($adminGroup->getUsers() as $user) {
			$ids[] = $user->getUID();
		}
		return $ids;
	}
}
