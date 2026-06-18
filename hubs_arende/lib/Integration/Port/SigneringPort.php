<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Integration\Port;

/**
 * 🔌 SEAM[signering]
 *
 * Port mot Inera Underskriftstjänst (e-legitimation/underskrift, PAdES-mål).
 *
 * Samma kontrakt mot stub ({@see \OCA\HubsArende\Integration\Stub\SigneringStub})
 * och skarp Inera-koppling; `FacksystemCommitService`/ärende-motorn väljer
 * implementation via INTEGRATION_MODE (IAppConfig: hubs_arende.integration.signering).
 *
 * Modell: ärende-motorn begär en signatur ({@see requestSignature()}), pollar
 * status tills den är klar ({@see pollStatus()}), och hämtar sedan det signerade
 * (PAdES-LTV) dokumentet ({@see fetchSignedDocument()}). Blockerare GAP-034/035/037/033
 * (AES/LTV) antas lösta bakom denna port; stubben simulerar en färdig PAdES-status.
 */
interface SigneringPort {
    /**
     * Begär en underskrift av ett dokument hos Inera Underskriftstjänst.
     *
     * @param string $hubsCaseId Kanonisk ärende-token (för spårbarhet/case:-tagg på begäran).
     * @param array<string,mixed> $document Dokument-referens + metadata, t.ex.
     *        ['ref' => string, 'filename' => string, 'mimeType' => string,
     *         'hash' => string, 'handlingstyp' => string].
     * @param array<int,array<string,mixed>> $signers Påskrivare, t.ex.
     *        [['uid' => string, 'role' => 'beslutsfattare'|'foredragande', 'loa' => 'LOA3']].
     *
     * @return array<string,mixed> Begäran-kvitto:
     *         ['signRequestId' => string, 'status' => 'pending', 'createdAt' => string(ISO),
     *          'expiresAt' => string(ISO)].
     *
     * @throws \OCA\HubsArende\Integration\Port\Exception\SigningRequestException vid avvisad begäran.
     */
    public function requestSignature(string $hubsCaseId, array $document, array $signers): array;

    /**
     * Poll:a status för en pågående underskriftsbegäran.
     *
     * @param string $signRequestId Id ur {@see requestSignature()}.
     *
     * @return array<string,mixed> Statusobjekt:
     *         ['signRequestId' => string,
     *          'status' => 'pending'|'partially_signed'|'signed'|'rejected'|'expired',
     *          'signedBy' => array<int,string>, 'updatedAt' => string(ISO),
     *          'padesLevel' => string|null].
     */
    public function pollStatus(string $signRequestId): array;

    /**
     * Hämta det färdigsignerade PAdES-dokumentet.
     *
     * Får ENBART returnera ett dokument när status='signed' (annars kastas).
     *
     * @param string $signRequestId Id ur {@see requestSignature()}.
     *
     * @return array<string,mixed> Signerat dokument:
     *         ['signRequestId' => string, 'filename' => string, 'mimeType' => 'application/pdf',
     *          'padesLevel' => string, 'content' => string (base64), 'signedAt' => string(ISO),
     *          'verified' => bool].
     *
     * @throws \OCA\HubsArende\Integration\Port\Exception\SigningNotReadyException om status !== 'signed'.
     */
    public function fetchSignedDocument(string $signRequestId): array;
}
