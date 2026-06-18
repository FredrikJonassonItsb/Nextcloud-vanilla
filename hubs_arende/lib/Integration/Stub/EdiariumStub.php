<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Integration\Stub;

use OCA\HubsArende\Integration\Port\Exception\ArkivException;
use OCA\HubsArende\Integration\Port\Exception\DiariumException;
use OCA\HubsArende\Integration\Port\EdiariumPort;

/**
 * 🔌 SEAM[ediarium]
 *
 * STATEFUL in-memory-stub mot e-diarium / e-arkiv (FGS). Simulerar
 * diarieföring (utdelat diarienummer, provenans-flip till 'registrerad') och
 * arkivpaketering (SIP-id). Deterministisk syntetisk data; ingen nätverkstrafik.
 *
 * Används bl.a. av kat 6 (rättsligt/tvång) som diarieför DIREKT
 * (preSagaHook='diariefor_direkt') — registreringen sker före ärenderummet.
 */
class EdiariumStub implements EdiariumPort {
    /**
     * Diarieförda handlingar per hubsCaseId.
     *
     * @var array<string, array<int, array<string,mixed>>>
     */
    private array $diarium = [];

    /**
     * Arkivpaket per paketId.
     *
     * @var array<string, array<string,mixed>>
     */
    private array $arkiv = [];

    private int $diarieSeq = 100;
    private int $paketSeq = 0;

    /**
     * @param string $rejectHandlingstyper Kommaseparerade handlingstyper som avvisas (fel-injektion).
     * @param string $arkivbildare Default arkivbildare i kvitton.
     * @param int    $diarieSeqStart Startvärde för diarienummer-sekvensen.
     */
    public function __construct(
        private string $rejectHandlingstyper = '',
        private string $arkivbildare = 'Socialnämnden',
        int $diarieSeqStart = 100,
    ) {
        $this->diarieSeq = $diarieSeqStart;
    }

    public function registrera(string $hubsCaseId, array $handling): array {
        $handlingstyp = (string)($handling['handlingstyp'] ?? '');
        if ($handlingstyp !== '' && $this->isListed($handlingstyp, $this->rejectHandlingstyper)) {
            throw new DiariumException(
                'Stub: e-diariet avvisade handlingstyp ' . $handlingstyp . ' för ' . $hubsCaseId
            );
        }

        $registreradAt = $this->isoNow();
        $diarienummer = $this->mintDiarienummer();
        $handlingId = 'h-' . substr(hash('sha256', $hubsCaseId . $diarienummer . $registreradAt), 0, 12);

        $this->diarium[$hubsCaseId][] = [
            'handlingId'    => $handlingId,
            'diarienummer'  => $diarienummer,
            'handlingstyp'  => $handlingstyp,
            'titel'         => (string)($handling['titel'] ?? ''),
            'riktning'      => (string)($handling['riktning'] ?? 'inkommande'),
            'sekretess'     => $handling['sekretess'] ?? null,
            'inkomDatum'    => (string)($handling['inkomDatum'] ?? $registreradAt),
            'arendetyp'     => (string)($handling['arendetyp'] ?? ''),
            'registreradAt' => $registreradAt,
        ];

        return [
            'ok'              => true,
            'diarienummer'    => $diarienummer,
            'registreradAt'   => $registreradAt,
            'provenanceState' => 'registrerad',
            'handlingId'      => $handlingId,
        ];
    }

    public function arkivera(string $hubsCaseId, array $paket): array {
        $handlingar = $paket['handlingar'] ?? [];
        if (!is_array($handlingar) || count($handlingar) === 0) {
            throw new ArkivException('Stub: tomt SIP-paket för ' . $hubsCaseId . ' (inga handlingar).');
        }

        $arkiveradAt = $this->isoNow();
        $paketId = 'sip-' . str_pad((string)(++$this->paketSeq), 5, '0', STR_PAD_LEFT)
            . '-' . substr(hash('sha256', $hubsCaseId . $arkiveradAt), 0, 8);

        $kvitto = [
            'ok'             => true,
            'paketId'        => $paketId,
            'hubsCaseId'     => $hubsCaseId,
            'arkiveradAt'    => $arkiveradAt,
            'retentionState' => 'arkiverad',
            'verifierad'     => true,
            'arkivbildare'   => (string)($paket['arkivbildare'] ?? $this->arkivbildare),
            'klassificering' => (string)($paket['klassificering'] ?? ''),
            'gallrasDatum'   => $paket['gallrasDatum'] ?? null,
            'bevarasDatum'   => $paket['bevarasDatum'] ?? null,
            'antalHandlingar' => count($handlingar),
        ];
        $this->arkiv[$paketId] = $kvitto;

        return $kvitto;
    }

    /**
     * Introspektion: diarieförda handlingar för ett ärende (dev/tester).
     *
     * @return array<int, array<string,mixed>>
     */
    public function getDiarium(string $hubsCaseId): array {
        return $this->diarium[$hubsCaseId] ?? [];
    }

    private function mintDiarienummer(): string {
        return 'SN-2026-' . str_pad((string)(++$this->diarieSeq), 4, '0', STR_PAD_LEFT);
    }

    private function isListed(string $needle, string $csv): bool {
        if ($csv === '') {
            return false;
        }
        return in_array($needle, array_map('trim', explode(',', $csv)), true);
    }

    private function isoNow(): string {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }
}
