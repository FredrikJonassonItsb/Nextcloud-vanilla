<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Integration\Stub;

use OCA\HubsArende\Integration\Port\Exception\SigningNotReadyException;
use OCA\HubsArende\Integration\Port\Exception\SigningRequestException;
use OCA\HubsArende\Integration\Port\SigneringPort;
use OCP\AppFramework\Services\IAppConfig;

/**
 * 🔌 SEAM[signering]
 *
 * STATEFUL stub mot Inera Underskriftstjänst. Simulerar en färdig
 * PAdES-underskrift utan riktig e-legitimation: en begäran skapas (status=pending),
 * pollas (går till signed efter konfigurerbart antal poll), och det signerade
 * PAdES-dokumentet kan sedan hämtas. Deterministisk syntetisk PDF-data.
 *
 * Konfig styr beteende för saga-/demotester:
 *  - $instantSign: status='signed' redan vid requestSignature() (1-stegs-demo).
 *  - $pollsUntilSigned: antal pollStatus()-anrop innan signed (async-känsla).
 *    Under vägen rapporteras 'partially_signed' med per-poll-progression i
 *    signedBy (en undertecknare i taget, i ordning — flerpåskrivar-demo, U4).
 *  - $rejectDocumentRefs: dokument-ref:er som ska kasta SigningRequestException.
 *  - $appConfig (valfri): PERSISTERAR stub-statet i app-config
 *    (`signering_stub_state`) så att poll N+1 i en NY PHP-request minns
 *    begäran från request N — utan den är stubben rent in-memory (tester/
 *    engångs-demo i en process). Demo-/testinfrastruktur, ALDRIG live-vägen;
 *    stubben ser enbart NEUTRALISERAD metadata (U6) så statet bär ingen PII.
 */
class SigneringStub implements SigneringPort {
    /** App-config-nyckeln för det persisterade stub-statet (endast demo/dev). */
    public const CONFIG_STATE_KEY = 'signering_stub_state';

    /**
     * Signeringsbegäranden per signRequestId.
     *
     * @var array<string, array<string,mixed>>
     */
    private array $requests = [];

    private int $seq = 0;

    /**
     * @param bool   $instantSign Om true: begäran är 'signed' direkt.
     * @param int    $pollsUntilSigned Antal poll innan status flippar till 'signed'.
     * @param string $rejectDocumentRefs Kommaseparerade dokument-ref som avvisas.
     * @param string $padesLevel PAdES-nivå som rapporteras (LTV-mål: 'PAdES-B-LTA').
     * @param IAppConfig|null $appConfig Valfri state-persistens över request-gränser (demo).
     */
    public function __construct(
        private bool $instantSign = true,
        private int $pollsUntilSigned = 1,
        private string $rejectDocumentRefs = '',
        private string $padesLevel = 'PAdES-B-LTA',
        private ?IAppConfig $appConfig = null,
    ) {
        $this->loadState();
    }

    public function requestSignature(string $hubsCaseId, array $document, array $signers): array {
        $ref = (string)($document['ref'] ?? '');
        if ($ref !== '' && $this->isListed($ref, $this->rejectDocumentRefs)) {
            throw new SigningRequestException('Stub: avvisad signeringsbegäran för dokument ' . $ref);
        }

        $signRequestId = 'sig-' . str_pad((string)(++$this->seq), 4, '0', STR_PAD_LEFT)
            . '-' . substr(hash('sha256', $hubsCaseId . $ref), 0, 8);
        $createdAt = $this->isoNow();

        $this->requests[$signRequestId] = [
            'signRequestId' => $signRequestId,
            'hubsCaseId'    => $hubsCaseId,
            'document'      => $document,
            'signers'       => $signers,
            'status'        => $this->instantSign ? 'signed' : 'pending',
            'signedBy'      => $this->instantSign ? $this->allSignerUids($signers) : [],
            'createdAt'     => $createdAt,
            'updatedAt'     => $createdAt,
            'polls'         => 0,
        ];
        $this->saveState();

        return [
            'signRequestId' => $signRequestId,
            'status'        => $this->requests[$signRequestId]['status'],
            'createdAt'     => $createdAt,
            'expiresAt'     => $this->addDays($createdAt, 7),
        ];
    }

    public function pollStatus(string $signRequestId): array {
        $req = $this->requireRequest($signRequestId);

        // Progressionen fortsätter från BÅDE pending och partially_signed —
        // tidigare gick en flerpolls-begäran (pollsUntilSigned >= 2) i baklås
        // på partially_signed för evigt (poll-räknaren tickade bara i pending).
        if (in_array($req['status'], ['pending', 'partially_signed'], true)) {
            $req['polls'] = (int)$req['polls'] + 1;
            if ($req['polls'] >= $this->pollsUntilSigned) {
                $req['status'] = 'signed';
                $req['signedBy'] = $this->allSignerUids($req['signers']);
            } else {
                $req['status'] = 'partially_signed';
                // Per-part-progression (U4/flerpåskrivar-demo): en undertecknare
                // i taget, i ordning — den sista signeras först vid 'signed'.
                $uids = $this->allSignerUids($req['signers']);
                $antalSignerade = max(0, min(count($uids) - 1, (int)$req['polls']));
                $req['signedBy'] = array_slice($uids, 0, $antalSignerade);
            }
            $req['updatedAt'] = $this->isoNow();
            $this->requests[$signRequestId] = $req;
            $this->saveState();
        }

        return [
            'signRequestId' => $signRequestId,
            'status'        => $req['status'],
            'signedBy'      => $req['signedBy'],
            'updatedAt'     => $req['updatedAt'],
            'padesLevel'    => $req['status'] === 'signed' ? $this->padesLevel : null,
        ];
    }

    public function fetchSignedDocument(string $signRequestId): array {
        $req = $this->requireRequest($signRequestId);
        if ($req['status'] !== 'signed') {
            throw new SigningNotReadyException(
                'Stub: dokument ej klart (status=' . $req['status'] . ') för ' . $signRequestId
            );
        }

        $filename = (string)($req['document']['filename'] ?? ($signRequestId . '.pdf'));
        // Deterministisk syntetisk "PAdES-PDF" (minimal giltig PDF-header + signatur-markör).
        $synthetic = "%PDF-1.7\n% stub-pades " . $this->padesLevel . ' ' . $signRequestId . "\n";

        return [
            'signRequestId' => $signRequestId,
            'filename'      => $filename,
            'mimeType'      => 'application/pdf',
            'padesLevel'    => $this->padesLevel,
            'content'       => base64_encode($synthetic),
            'signedAt'      => (string)$req['updatedAt'],
            'verified'      => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function requireRequest(string $signRequestId): array {
        $req = $this->requests[$signRequestId] ?? null;
        if ($req === null) {
            throw new SigningNotReadyException('Stub: okänd signRequestId ' . $signRequestId);
        }
        return $req;
    }

    /**
     * @param array<int,array<string,mixed>> $signers
     * @return array<int,string>
     */
    private function allSignerUids(array $signers): array {
        $uids = [];
        foreach ($signers as $s) {
            if (isset($s['uid'])) {
                $uids[] = (string)$s['uid'];
            }
        }
        return $uids;
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

    private function addDays(string $iso, int $days): string {
        return (new \DateTimeImmutable($iso))->add(new \DateInterval('P' . $days . 'D'))
            ->format('Y-m-d\TH:i:s\Z');
    }

    // --- Valfri state-persistens över request-gränser (demo/dev; se klassdoc) ---

    private function loadState(): void {
        if ($this->appConfig === null) {
            return;
        }
        $raw = $this->appConfig->getAppValueString(self::CONFIG_STATE_KEY, '');
        if ($raw === '') {
            return;
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && is_array($decoded['requests'] ?? null)) {
            $this->requests = $decoded['requests'];
            $this->seq = (int)($decoded['seq'] ?? count($this->requests));
        }
    }

    private function saveState(): void {
        if ($this->appConfig === null) {
            return;
        }
        try {
            $this->appConfig->setAppValueString(self::CONFIG_STATE_KEY, (string)json_encode([
                'requests' => $this->requests,
                'seq' => $this->seq,
            ]));
        } catch (\Throwable) {
            // State-persistensen är demo-bekvämlighet — får aldrig fälla flödet.
        }
    }
}
