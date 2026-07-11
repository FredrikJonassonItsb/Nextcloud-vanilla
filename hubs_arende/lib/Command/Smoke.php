<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Command;

use OCA\HubsArende\Exception\AvvisadException;
use OCA\HubsArende\Integration\Port\EdiariumPort;
use OCA\HubsArende\Integration\Port\Exception\IntegrationException;
use OCA\HubsArende\Integration\Stub\EdiariumStub;
use OCA\HubsArende\Service\ArendeLifecycleService;
use OCA\HubsArende\Service\ArendeService;
use OCA\HubsArende\Service\GallringService;
use OCP\AppFramework\Db\DoesNotExistException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * End-to-end smoke test of the ärende-motor against the harness stubs.
 *
 * Proves the engine RUNS (not just compiles): createCase (orosanmälan) → R0
 * säkerhetsskydds-grind → register INSERT → frist → commit (mot stub) →
 * verifierat kvitto → provenans-flip; plus idempotens och en säkerhetsskydds-
 * avvisning. Synthetic data only (no PII). Dev/ops tool — safe & read-mostly.
 *
 *   occ hubs_arende:smoke
 */
class Smoke extends Command {
	public function __construct(
		private ArendeService $arendeService,
		private ArendeLifecycleService $lifecycleService,
		private GallringService $gallringService,
		private ?EdiariumPort $ediariumPort = null,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('hubs_arende:smoke')
			->setDescription('Smoke-test: createCase → grind → register → commit → verifierat kvitto (mot stub, syntetisk data).');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$cid = 'smoke-' . bin2hex(random_bytes(4));
		$rad = [
			'arendeTyp' => 'orosanmalan',
			'conversationId' => $cid,
			'objektRef' => 'barn-' . substr($cid, -4),
			'enhet' => 'barn-familj@',
			'inkomDatum' => date('Y-m-d'),
		];

		// 1. createCase (R0 grind → saga R1–R10).
		$arende = $this->arendeService->createCase($rad);
		$output->writeln('<info>[1] createCase OK</info>');
		$output->writeln('    hubsCaseId       = ' . $arende->getHubsCaseId());
		$output->writeln('    arendeTyp        = ' . $arende->getArendeTyp());
		$output->writeln('    commit_dest.     = ' . $arende->getCommitDestination());
		$output->writeln('    steg / status    = ' . $arende->getSteg() . ' / ' . $arende->getStatus());
		$output->writeln('    provenance       = ' . $arende->getProvenanceState());
		$frist = $arende->getFristDue();
		$output->writeln('    fristDue         = ' . ($frist instanceof \DateTime ? $frist->format('Y-m-d') : '(ingen)') . '  (förväntat: idag+14)');

		// 2. Idempotens — samma conversationId ska ge samma case.
		$again = $this->arendeService->createCase($rad);
		$idem = $again->getHubsCaseId() === $arende->getHubsCaseId();
		$output->writeln('<info>[2] idempotens</info>      = ' . ($idem ? 'JA (samma id)' : '<error>NEJ (BUG)</error>'));

		// 3. Commit mot stubben → verifierat kvitto.
		$kvitto = $this->arendeService->commit($arende->getHubsCaseId(), ['typ' => 'skyddsbedomning']);
		$output->writeln('<info>[3] commit (stub) OK</info>');
		$output->writeln('    verifierad       = ' . (($kvitto['verifierad'] ?? false) ? 'JA' : '<error>NEJ</error>'));
		$output->writeln('    dnr              = ' . ($kvitto['dnr'] ?? '-'));
		$output->writeln('    committedAt      = ' . ($kvitto['committedAt'] ?? '-'));
		$output->writeln('    gallrasDatum     = ' . ($kvitto['gallrasDatum'] ?? '-'));

		// 4. Provenans-flip + retention bunden till verifierad callback (GAP-007).
		$after = $this->arendeService->show($arende->getHubsCaseId());
		$output->writeln('<info>[4] provenans efter commit</info> = ' . $after->getProvenanceState() . ' (förväntat: registrerad)');
		$output->writeln('    retention_state  = ' . $after->getRetentionState() . ' (förväntat: gallras_efter_commit)');
		$output->writeln('    dnr i registret  = ' . ($after->getDnr() ?? '-'));
		$g = $after->getGallrasDatum();
		$output->writeln('    gallras_datum    = ' . ($g instanceof \DateTime ? $g->format('Y-m-d') : '-') . ' (L2: persisterat ur kvittot)');

		// 5. Säkerhetsskydds-avvisning (fail-closed) — strukturerat fält, deterministiskt.
		$output->writeln('<info>[5] säkerhetsskydds-grind</info>');
		try {
			$this->arendeService->createCase([
				'arendeTyp' => 'orosanmalan',
				'conversationId' => 'sek-' . bin2hex(random_bytes(3)),
				'objektRef' => 'TEST',
				'sdkFields' => ['sakerhetsklass' => 'hemlig'],
			]);
			$output->writeln('    <error>INTE avvisad — BUG (säkerhetsskyddsklassat slapp igenom)</error>');
			return 1;
		} catch (AvvisadException $e) {
			$output->writeln('    avvisad korrekt: ' . $e->getMessage());
		}

		// 6. Idempotent commit (H3) — en andra commit på en redan registrerad rad ska
		//    returnera samma dnr UTAN att anropa commit-porten igen (ingen dubbel-registrering).
		$kvitto2 = $this->arendeService->commit($arende->getHubsCaseId(), ['typ' => 'skyddsbedomning']);
		$idemCommit = ($kvitto2['dnr'] ?? null) === ($kvitto['dnr'] ?? null);
		$output->writeln('<info>[6] idempotent commit (H3)</info>   = ' . ($idemCommit ? 'JA (samma dnr ' . ($kvitto2['dnr'] ?? '-') . ', ingen dubbel-registrering)' : '<error>NEJ (BUG)</error>'));
		if (!$idemCommit) {
			return 1;
		}

		// 7. M1 — objektRef som ser ut som personnummer ska avvisas FÖRE sagan (rent
		//    \InvalidArgumentException → 400, ej saga-wrap → 500). Pseudonym-invarianten.
		$output->writeln('<info>[7] objektRef PII-validering (M1)</info>');
		try {
			$this->arendeService->createCase([
				'arendeTyp' => 'orosanmalan',
				'conversationId' => 'pii-' . bin2hex(random_bytes(3)),
				'objektRef' => '19850101-1234',
			]);
			$output->writeln('    <error>INTE avvisad — BUG (personnummer som objektRef slapp igenom)</error>');
			return 1;
		} catch (\InvalidArgumentException $e) {
			$output->writeln('    avvisad korrekt: ' . $e->getMessage());
		}

		// 8. Lifecycle steg-transition. Case-skapande typer FÖDS i 'forhandsbedomning'
		//    (gap23), så den första transitionen är förhandsbedömning→utredning.
		//    ORO-1: orosanmälan har pliktGrind=true ⇒ utredning kräver en KVITTERAD
		//    skyddsbedömning (explicit kontext-signal), annars blockerar fas-spärren.
		$eft8 = $this->lifecycleService->transitionera(
			$arende->getHubsCaseId(),
			'utredning',
			['skyddsbedomningKvitterad' => true],
		);
		$output->writeln('<info>[8] lifecycle-transition</info>     = steg nu "' . $eft8->getSteg() . '" (förväntat: utredning)');
		if ($eft8->getSteg() !== 'utredning') {
			return 1;
		}

		// 8b. HELA RESAN till avslutat (forts. från utredning): utredning→beslut→
		//     uppfoljning→avslutat. Bevisar att livscykeln går att slutföra HELA
		//     vägen — frontendens "Avsluta ärende"-åtgärd kör exakt samma
		//     transitionera($ref, 'avslutat'). Ett avslut är en ren steg-övergång,
		//     INGEN ny facksystem-registrering (akten är redan registrerad).
		$stegNu = $eft8->getSteg();
		foreach (['beslut', 'uppfoljning', 'avslutat'] as $mal) {
			$stegNu = $this->lifecycleService->transitionera($arende->getHubsCaseId(), $mal)->getSteg();
			if ($stegNu !== $mal) {
				$output->writeln('    <error>[8b] livscykeln fastnade vid ' . $mal . ' (steg=' . $stegNu . ')</error>');
				return 1;
			}
		}
		$output->writeln('<info>[8b] hela resan</info>             = utredning→beslut→uppfoljning→avslutat OK (steg nu "' . $stegNu . '")');

		// 9. GDPR-gallring (art. 5.1.e) — purge av en registrerad+gallras_efter_commit-rad
		//    vars gallras_datum passerats. Kör med now=+100d så kvittots +90d-deadline är förbi.
		//    tillatStub=true: smoke-testet kör MEDVETET mot stubbarna (F10-spärren gäller
		//    bara det schemalagda produktions-svepet, som kräver live-läge).
		$res9 = $this->gallringService->gallra(new \DateTime('+100 days'), true);
		$gallrad = false;
		try {
			$this->arendeService->show($arende->getHubsCaseId());
		} catch (DoesNotExistException $e) {
			$gallrad = true;
		}
		$output->writeln('<info>[9] GDPR-gallring</info>            = ' . ($gallrad ? 'JA (rad purgad, antal=' . ($res9['antal'] ?? '?') . ')' : '<error>NEJ (BUG — raden finns kvar)</error>'));
		if (!$gallrad) {
			return 1;
		}

		// ============================================================== //
		//  PER-TYP: socialförvaltningsflödet — alla 8 ärendetyper.
		// ============================================================== //
		// Bevisar att motorn kör createCase→(hook)→commit→livscykel för ALLA åtta
		// innehållstyper (ej bara orosanmälan): config-härledd commit_destination,
		// kat6:s pre-saga-diarieföring (föds 'registrerad'), kat8:s post-commit-
		// yttrande, samt fail-closed-commit för ärv-modul-typer (MODUL-FAILCLOSED).
		// Förväntningarna är härledda ur ArendeTypRegistry::defaultRows().
		$output->writeln('');
		$output->writeln('<info>=== PER-TYP: socialförvaltningsflödet (8 ärendetyper) ===</info>');

		$forvantat = [
			//                     dest          bornReg inheritModul postHook
			'orosanmalan'     => ['facksystem', false,  false,       false],
			'ansokan_bistand' => ['facksystem', false,  false,       false],
			'ekonomi'         => ['facksystem', false,  false,       false],
			'komplettering'   => ['facksystem', false,  true,        false], // frendsModul=null ⇒ commit fail-closed
			'vard_samverkan'  => ['facksystem', false,  false,       false],
			'rattsligt_tvang' => ['diarium',    true,   false,       false], // preSagaHook diariefor_direkt
			'verkstallighet'  => ['facksystem', false,  true,        false], // frendsModul=null ⇒ commit fail-closed
			'familjeratt'     => ['facksystem', false,  false,       true],  // postCommitHook familjeratt_yttrande
		];

		foreach ($forvantat as $typId => [$dest, $bornReg, $inheritModul, $postHook]) {
			$cid = 'smoke-' . $typId . '-' . bin2hex(random_bytes(3));
			$c = $this->arendeService->createCase([
				'arendeTyp' => $typId,
				'conversationId' => $cid,
				'objektRef' => 'obj-' . substr($cid, -6),
				'inkomDatum' => date('Y-m-d'),
			]);
			$line = sprintf(
				'[%-16s] dest=%-10s steg=%-16s prov=%-13s',
				$typId, $c->getCommitDestination(), $c->getSteg(), $c->getProvenanceState(),
			);

			if ($c->getCommitDestination() !== $dest) {
				$output->writeln('    <error>' . $line . ' — fel commit_destination (förväntat ' . $dest . ')</error>');
				return 1;
			}

			// kat6 'diariefor_direkt': FÖDS 'registrerad' + dnr satt + diarieförd FÖRE commit.
			$arRegistrerad = $c->getProvenanceState() === 'registrerad';
			if ($bornReg) {
				$diariumN = $this->diariumCount($c->getHubsCaseId());
				if (!$arRegistrerad || (string)($c->getDnr() ?? '') === '' || $diariumN < 1) {
					$output->writeln('    <error>' . $line . ' — kat6 diariefor_direkt FEL (förväntat föds-registrerad + dnr + diarieförd handling)</error>');
					return 1;
				}
				$line .= sprintf(' dnr@birth=%s diarium=%d', $c->getDnr(), $diariumN);
			} elseif ($arRegistrerad) {
				$output->writeln('    <error>' . $line . ' — oväntat föds-registrerad (endast kat6 ska)</error>');
				return 1;
			}

			// commit:
			if ($inheritModul) {
				// frendsModul=null ⇒ ärv-modul; commit ska FAIL-CLOSED (ingen gissad modul).
				try {
					$this->arendeService->commit($c->getHubsCaseId(), ['typ' => 'handling']);
					$output->writeln('    <error>' . $line . ' — commit borde fail-closat (ärv-modul saknas)</error>');
					return 1;
				} catch (IntegrationException) {
					$line .= ' commit=FAIL-CLOSED(ärv-modul)';
				}
			} else {
				$kv = $this->arendeService->commit($c->getHubsCaseId(), ['typ' => 'handling']);
				if (($kv['verifierad'] ?? false) !== true) {
					$output->writeln('    <error>' . $line . ' — commit ej verifierad</error>');
					return 1;
				}
				$line .= ' commit=verifierad';
				// kat6: idempotent — commit får EJ skapa en andra diarieförd handling.
				if ($bornReg && $this->diariumCount($c->getHubsCaseId()) !== 1) {
					$output->writeln('    <error>' . $line . ' — kat6 commit dubbel-diarieförde</error>');
					return 1;
				}
				// kat8: post-commit-hook ska ha registrerat ett yttrande.
				if ($postHook) {
					$diariumN = $this->diariumCount($c->getHubsCaseId());
					if ($diariumN < 1) {
						$output->writeln('    <error>' . $line . ' — kat8 familjeratt_yttrande post-hook körde inte</error>');
						return 1;
					}
					$line .= sprintf(' postHook=yttrande(diarium=%d)', $diariumN);
				}
			}
			$output->writeln('<info>    ' . $line . '</info>');
		}

		// pliktGrind fas-spärr (ORO-1): orosanmälan (pliktGrind=true) får INTE gå
		// förhandsbedömning→utredning utan kvittens; MED kvittens tillåts den.
		$po = $this->arendeService->createCase([
			'arendeTyp' => 'orosanmalan',
			'conversationId' => 'smoke-plikt-' . bin2hex(random_bytes(3)),
			'objektRef' => 'obj-plikt',
			'inkomDatum' => date('Y-m-d'),
		]);
		$blockerad = false;
		try {
			$this->lifecycleService->transitionera($po->getHubsCaseId(), 'utredning');
		} catch (\InvalidArgumentException) {
			$blockerad = true;
		}
		$eftPlikt = $this->lifecycleService->transitionera(
			$po->getHubsCaseId(),
			'utredning',
			['skyddsbedomningKvitterad' => true],
		);
		$output->writeln('<info>[plikt-grind ORO-1]</info> blockerad-utan-kvittens=' . ($blockerad ? 'JA' : '<error>NEJ</error>')
			. ', tillåten-med-kvittens=' . ($eftPlikt->getSteg() === 'utredning' ? 'JA' : '<error>NEJ</error>'));
		if (!$blockerad || $eftPlikt->getSteg() !== 'utredning') {
			return 1;
		}

		$output->writeln('');
		$output->writeln('<info>=== SMOKE OK — motorn kör end-to-end mot stubbarna (alla 8 ärendetyper + hooks + fas-spärr) ===</info>');
		$output->writeln('<comment>OBS: per-typ-steget lämnar syntetiska rader i registret (rensa vid behov: occ hubs_arende:seed-demo --purge).</comment>');
		return 0;
	}

	/**
	 * Antal diarieförda handlingar för ett ärende i e-diarium-stubben (introspektion;
	 * 0 om porten inte är stubben). Bevisar att en hook faktiskt diarieförde.
	 */
	private function diariumCount(string $hubsCaseId): int {
		return $this->ediariumPort instanceof EdiariumStub
			? count($this->ediariumPort->getDiarium($hubsCaseId))
			: 0;
	}
}
