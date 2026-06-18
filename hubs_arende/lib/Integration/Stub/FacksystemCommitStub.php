<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Integration\Stub;

use OCA\HubsArende\Integration\Port\Exception\CallbackVerificationException;
use OCA\HubsArende\Integration\Port\Exception\CommitFailedException;
use OCA\HubsArende\Integration\Port\Exception\CommitTimeoutException;
use OCA\HubsArende\Integration\Port\FacksystemCommitPort;

/**
 * 🔌 SEAM[treserva] / SEAM[treserva.commit] / SEAM[treserva.skapa]
 *
 * STATEFUL in-memory-stub mot facksystemet — PHP-migrering av mönstret i
 * hubs_start/src/services/demo/treserva.js (`commitHandling`, `REGISTER`,
 * `RECEIPTS`, `dnrSeq`). Håller ett litet "Treserva" i processen så att en hel
 * ärende-livscykel (skapa → committa → verifiera → gallra) hänger ihop utan
 * nätverkstrafik och utan riktiga integrationer.
 *
 * INVARIANTER (bärs ordagrant ur treserva.js):
 *  - Deterministisk syntetisk dnr: '2026-IFO-NNNN' ur en sekvens som startar på 500.
 *  - Retention startar ENBART på den VERIFIERADE callbacken (GAP-007). `commit()`
 *    returnerar ett preliminärt kvitto (verifierad=false, gallrasDatum=null);
 *    {@see verifyCallback()} sätter gallrasDatum (+90 dagar) och verifierad=true.
 *  - Fel-/timeout-injektion via konfig så att sagans kompensering kan testas
 *    deterministiskt utan riktiga fel.
 *
 * Konstruktorn tar ren config (inga OCP-deps) så att stubben kan användas både
 * från DI (FacksystemCommitService) och från enhetstester direkt.
 *
 * @phpstan-type Kvitto array{ok:bool,dnr:?string,committedAt:string,gallrasDatum:?string,verifierad:bool,hubsCaseId:string,modul:string,receipt:array<string,mixed>}
 */
class FacksystemCommitStub implements FacksystemCommitPort {
    /**
     * Registerposter per hubsCaseId (speglar Tables-registret hubs_arenden i demon).
     *
     * @var array<string, array<string,mixed>>
     */
    private array $register = [];

    /**
     * Väntande, ännu ej verifierade commits per callback-token.
     *
     * @var array<string, array<string,mixed>>
     */
    private array $pending = [];

    /**
     * Verifierade kvittenser (driver kvittens-/retention-ytan, à la RECEIPTS).
     *
     * @var array<int, array<string,mixed>>
     */
    private array $receipts = [];

    /** Deterministisk dnr-sekvens (Treserva delar ut dnr vid registrering). */
    private int $dnrSeq = 500;

    /**
     * @param bool   $synchronousCallback true = kör callbacken in-process direkt i
     *               commit() (synkron demo); false = async, verifyCallback() krävs.
     * @param int    $retentionDays Antal dagar till gallrasDatum efter verifierad commit.
     * @param string $failHubsCaseIds Kommaseparerade hubsCaseId som ska kasta
     *               CommitFailedException (fel-injektion).
     * @param string $timeoutHubsCaseIds Kommaseparerade hubsCaseId som ska kasta
     *               CommitTimeoutException (timeout-injektion).
     * @param int    $dnrSeqStart Startvärde för dnr-sekvensen (default 500, som demon).
     */
    public function __construct(
        private bool $synchronousCallback = true,
        private int $retentionDays = 90,
        private string $failHubsCaseIds = '',
        private string $timeoutHubsCaseIds = '',
        int $dnrSeqStart = 500,
    ) {
        $this->dnrSeq = $dnrSeqStart;
    }

    public function commit(string $hubsCaseId, string $modul, array $payload): array {
        // --- fel-/timeout-injektion (deterministisk, för saga-/kompenseringstester) ---
        if ($this->isListed($hubsCaseId, $this->timeoutHubsCaseIds)) {
            throw new CommitTimeoutException(
                'Stub: simulerad timeout mot facksystemet för ' . $hubsCaseId
            );
        }
        if ($this->isListed($hubsCaseId, $this->failHubsCaseIds)) {
            throw new CommitFailedException(
                'Stub: simulerat avvisat commit mot ' . $modul . ' för ' . $hubsCaseId
            );
        }

        $committedAt = $this->isoNow();
        $entry = $this->register[$hubsCaseId] ?? [
            'hubsCaseId'      => $hubsCaseId,
            'dnr'             => null,
            'modul'           => $modul,
            'provenanceState' => 'ej_registrerad',
            'retentionState'  => 'ej_startad',
            'gallrasDatum'    => null,
            'handlingar'      => [],
        ];

        // Treserva delar ut dnr vid första registreringen om det saknas (deterministiskt).
        $dnr = $entry['dnr'] ?? null;
        if ($dnr === null || $dnr === '') {
            $dnr = $this->mintDnr();
        }

        // Handlingen REGISTRERAS, men retention/provenans flippas INTE här —
        // det sker först på den verifierade callbacken (GAP-007-mönstret).
        $entry['modul'] = $modul;
        $entry['handlingar'][] = [
            'typ'        => (string)($payload['typ'] ?? 'handling'),
            'tid'        => $committedAt,
            'dnr'        => $dnr,
            'arendetyp'  => (string)($payload['arendetyp'] ?? ''),
            'verifierad' => false,
        ];
        $this->register[$hubsCaseId] = $entry;

        $correlationId = (string)($payload['correlationId'] ?? ($hubsCaseId . ':' . $committedAt));
        $callbackToken = $this->registerCallback($hubsCaseId, $correlationId);
        $this->pending[$callbackToken] = [
            'hubsCaseId'  => $hubsCaseId,
            'modul'       => $modul,
            'dnr'         => $dnr,
            'committedAt' => $committedAt,
            'typ'         => (string)($payload['typ'] ?? 'handling'),
            'arendetyp'   => (string)($payload['arendetyp'] ?? ''),
        ];

        // Synkron demo: kör den verifierade callbacken direkt så att den röda tråden
        // hänger ihop i ett anrop (motsvarar treserva.js `commitHandling` som
        // returnerar ett redan verifierat kvitto).
        if ($this->synchronousCallback) {
            return $this->verifyCallback($callbackToken, ['hubsCaseId' => $hubsCaseId, 'dnr' => $dnr]);
        }

        // Async: PRELIMINÄRT kvitto. verifierad=false, gallrasDatum=null tills callback.
        return [
            'ok'            => true,
            'dnr'           => $dnr,
            'committedAt'   => $committedAt,
            'gallrasDatum'  => null,
            'verifierad'    => false,
            'hubsCaseId'    => $hubsCaseId,
            'modul'         => $modul,
            'callbackToken' => $callbackToken,
            'receipt'       => [],
        ];
    }

    public function registerCallback(string $hubsCaseId, string $correlationId): string {
        // Deterministisk men unik token; idempotent på (hubsCaseId, correlationId).
        return 'cb-' . substr(hash('sha256', $hubsCaseId . '|' . $correlationId), 0, 24);
    }

    public function verifyCallback(string $callbackToken, array $callbackData): array {
        $pending = $this->pending[$callbackToken] ?? null;
        if ($pending === null) {
            // Idempotens: om token redan förbrukats, returnera det verifierade kvittot igen.
            foreach ($this->receipts as $r) {
                if (($r['callbackToken'] ?? null) === $callbackToken) {
                    return $this->kvittoFromReceipt($r);
                }
            }
            throw new CallbackVerificationException(
                'Stub: okänd eller redan förbrukad callback-token ' . $callbackToken
            );
        }

        $hubsCaseId = (string)$pending['hubsCaseId'];
        $modul = (string)$pending['modul'];
        $dnr = (string)($callbackData['dnr'] ?? $pending['dnr']);
        $committedAt = (string)$pending['committedAt'];
        $gallrasDatum = $this->addDays($committedAt, $this->retentionDays);

        // FÖRST HÄR: provenans-flip + retention-start (på den verifierade callbacken).
        $entry = $this->register[$hubsCaseId] ?? [];
        $entry['hubsCaseId'] = $hubsCaseId;
        $entry['dnr'] = $dnr;
        $entry['modul'] = $modul;
        $entry['provenanceState'] = 'registrerad';
        $entry['retentionState'] = 'gallras_efter_commit';
        $entry['gallrasDatum'] = $gallrasDatum;
        // Markera senast registrerade handling som verifierad.
        if (!empty($entry['handlingar']) && is_array($entry['handlingar'])) {
            $lastIdx = array_key_last($entry['handlingar']);
            $entry['handlingar'][$lastIdx]['verifierad'] = true;
            $entry['handlingar'][$lastIdx]['dnr'] = $dnr;
        }
        $this->register[$hubsCaseId] = $entry;

        $receipt = [
            'id'            => 'kv-' . (count($this->receipts) + 1),
            'hubsCaseId'    => $hubsCaseId,
            'dnr'           => $dnr,
            'modul'         => $modul,
            'typ'           => (string)$pending['typ'],
            'arendetyp'     => (string)$pending['arendetyp'],
            'committedAt'   => $committedAt,
            'gallrasDatum'  => $gallrasDatum,
            'verifierad'    => true,
            'kalla'         => 'Frends → facksystem (stub)',
            'callbackToken' => $callbackToken,
        ];
        array_unshift($this->receipts, $receipt);

        // Token förbrukad (idempotens).
        unset($this->pending[$callbackToken]);

        return $this->kvittoFromReceipt($receipt);
    }

    // ------------------------------------------------------------------ //
    //  Introspektion för demo/tester (motsvarar _dumpRegister/listReceipts)
    // ------------------------------------------------------------------ //

    /**
     * Hämta en registerpost (pekar-uppslag).
     *
     * @return array<string,mixed>|null
     */
    public function getEntry(string $hubsCaseId): ?array {
        return $this->register[$hubsCaseId] ?? null;
    }

    /**
     * Lista verifierade kvittenser (driver kvittens-/retention-ytan).
     *
     * @return array<int, array<string,mixed>>
     */
    public function listReceipts(): array {
        return array_map(static fn (array $r): array => $r, $this->receipts);
    }

    /**
     * Dumpa hela registret (dev-introspektion / tester).
     *
     * @return array<int, array<string,mixed>>
     */
    public function dumpRegister(): array {
        return array_values($this->register);
    }

    // ------------------------------------------------------------------ //
    //  Helpers (medvetet fristående, à la treserva.js pad4/isoNow/addDays)
    // ------------------------------------------------------------------ //

    /**
     * @param array<string,mixed> $receipt
     * @return array<string,mixed>
     */
    private function kvittoFromReceipt(array $receipt): array {
        return [
            'ok'           => true,
            'dnr'          => $receipt['dnr'],
            'committedAt'  => $receipt['committedAt'],
            'gallrasDatum' => $receipt['gallrasDatum'],
            'verifierad'   => true,
            'hubsCaseId'   => $receipt['hubsCaseId'],
            'modul'        => $receipt['modul'],
            'receipt'      => $receipt,
        ];
    }

    private function mintDnr(): string {
        return '2026-IFO-' . str_pad((string)(++$this->dnrSeq), 4, '0', STR_PAD_LEFT);
    }

    private function isListed(string $hubsCaseId, string $csv): bool {
        if ($csv === '') {
            return false;
        }
        $list = array_map('trim', explode(',', $csv));
        return in_array($hubsCaseId, $list, true);
    }

    private function isoNow(): string {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }

    private function addDays(string $iso, int $days): string {
        $d = new \DateTimeImmutable($iso);
        return $d->add(new \DateInterval('P' . $days . 'D'))->format('Y-m-d');
    }
}
