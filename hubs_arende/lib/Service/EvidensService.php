<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Service;

use OCA\HubsArende\Db\Handelse;
use OCA\HubsArende\Db\HandelseMapper;
use Psr\Log\LoggerInterface;

/**
 * EvidensService — läser ärendets JOURNAL baklänges för att avgöra om ett
 * lagstadgat moment (t.ex. skyddsbedömningen) faktiskt PRODUCERATS som artefakt.
 *
 * Detta bryter den cirkulära plikt-härledningen (GAP-U1): tidigare "ansågs"
 * skyddsbedömningen gjord så snart steget flyttats förbi förhandsbedömning —
 * beviset var alltså just den flytt grinden skulle skydda. Nu är beviset en
 * VERKLIG handling ur mall (`Handelse::TYP_HANDLING` vars `detalj.mall` bär
 * mall-id:t, som redan skrivs av HandlingService) eller en journalförd kvittens.
 *
 * NYCKELORDSMATCHNING (skiftlägesokänslig substring) mot mall-id — robust utan
 * ett exakt mall-register och SPEGLAR frontendens `harledStatus` i arendeFlow.js,
 * så backend-grind och stepper-avläsning aldrig divergerar.
 *
 * Ingen ny lagring: signalerna finns redan i journalen, de avläses bara.
 */
class EvidensService {
    /**
     * Semantisk artefakt-klass → nyckelord som identifierar den i ett mall-id.
     * Håll i synk med arendeFlow.js STEG_INNEHALL[*].delmoment[*].klarNar.match.
     *
     * @var array<string, list<string>>
     */
    private const KLASS_NYCKELORD = [
        'skyddsbedomning' => ['skyddsbedom'],
        'forhandsbedomning' => ['forhandsbedom', 'förhandsbedöm'],
        'utredningsplan' => ['utredningsplan'],
        'bbic-utredning' => ['bbic'],
        'barnsamtal' => ['barnsamtal'],
        'kommunicering' => ['kommunicer'],
        'genomforandeplan' => ['genomforande', 'genomförande'],
        'avslutsanteckning' => ['avslut'],
    ];

    public function __construct(
        private readonly HandelseMapper $handelseMapper,
        private readonly ?LoggerInterface $logger = null,
        // KANONISKA dokumenttyps-registret (T4-rotfix). När det finns matchar
        // grinden på den STÄMPLADE dokumenttypen; nyckelords-listan nedan används
        // bara som legacy-fallback för äldre journalrader. Null i test-harness.
        private readonly ?DokumenttypRegistry $dokumenttypRegistry = null,
    ) {
    }

    /**
     * Finns en artefakt av given klass i ärendets journal (handling ur mall)?
     * Fail-open på läsfel: en journal-läsning som kraschar får ALDRIG låsa en
     * handläggare ute (grinden degraderar då till sitt icke-tvingande beteende).
     */
    public function harArtefakt(string $hubsCaseId, string $klass): bool {
        $klassLower = strtolower($klass);
        // Legacy-nyckelord: från det kanoniska registret om det finns, annars den
        // lokala fallback-tabellen (test-harness utan registry).
        $nyckelord = $this->dokumenttypRegistry?->nyckelordForKlass($klass)
            ?? (self::KLASS_NYCKELORD[$klass] ?? [$klass]);
        try {
            foreach ($this->handelseMapper->findByCaseId($hubsCaseId, 500) as $h) {
                if ($h->getTyp() !== Handelse::TYP_HANDLING) {
                    continue;
                }
                $detalj = $this->detalj($h);
                // KANONISK (T4-rotfix): en stämplad dokumenttyp är auktoritativ för
                // just den handlingen — matchar den klassen exakt räknas den, annars
                // hoppas den (ingen skör mall-substring-gissning på en stämplad rad).
                $dokumenttyp = strtolower((string)($detalj['dokumenttyp'] ?? ''));
                if ($dokumenttyp !== '') {
                    if ($dokumenttyp === $klassLower) {
                        return true;
                    }
                    continue;
                }
                // LEGACY-rad (ingen stämpel): fall tillbaka på nyckelord mot mall.
                $mall = strtolower((string)($detalj['mall'] ?? ''));
                if ($mall === '') {
                    continue;
                }
                foreach ($nyckelord as $kw) {
                    if (str_contains($mall, strtolower($kw))) {
                        return true;
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger?->warning('hubs_arende: EvidensService.harArtefakt läsfel (fail-open)', [
                'app' => 'hubs_arende', 'hubsCaseId' => $hubsCaseId, 'klass' => $klass,
                'exception' => $e->getMessage(),
            ]);
            return true; // fail-open: lås aldrig ute vid läsfel
        }
        return false;
    }

    /**
     * Finns en journalförd kvittens för ett moment (t.ex. skyddsbedömningen)?
     * Godtar både en direkt kvittens (TYP_KVITTENS) och ett grindval (godkand/
     * override) för samma moment.
     */
    public function harKvittens(string $hubsCaseId, string $moment): bool {
        try {
            foreach ($this->handelseMapper->findByCaseId($hubsCaseId, 500) as $h) {
                $d = $this->detalj($h);
                if ($h->getTyp() === Handelse::TYP_KVITTENS
                    && (string)($d['moment'] ?? '') === $moment) {
                    return true;
                }
                if ($h->getTyp() === Handelse::TYP_GRINDVAL
                    && (string)($d['grind'] ?? '') === $moment) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            $this->logger?->warning('hubs_arende: EvidensService.harKvittens läsfel (fail-open)', [
                'app' => 'hubs_arende', 'hubsCaseId' => $hubsCaseId, 'moment' => $moment,
                'exception' => $e->getMessage(),
            ]);
            return true;
        }
        return false;
    }

    /** @return array<string,mixed> */
    private function detalj(Handelse $h): array {
        $raw = $h->getDetalj();
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
