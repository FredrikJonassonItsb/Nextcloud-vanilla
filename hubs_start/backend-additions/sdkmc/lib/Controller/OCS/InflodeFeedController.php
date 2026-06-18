<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * HUBS-START BACKEND-ADDITION · UPSTREAM-KANDIDAT · Target: lib/Controller/OCS/InflodeFeedController.php
 *
 * NEW FILE for the sdkmc app. OCS surface for the Hubs Start "meddelande-inflöde"
 * feed (the three bands: KorgValjare + the raw inflöde list).
 *
 * SCOPE — sdkmc message-domain only:
 *   GET  /api/v1/inflode-summary  → { korgar:[], inflode:[rader] }
 *        the raw incoming-message feed out of sdkmc's own mailboxes/threads.
 *
 *   POST /api/v1/inflode/{action} → message-level actions on a feed row, but
 *        ONLY the message verbs 'besvara' | 'vidarebefordra'. The ärende verbs
 *        'skapa' (create case) and 'koppla' (link to case) are OWNED BY
 *        hubs_arende and are rejected here with 400 + error
 *        'agas_av_arende_motorn' so the SPA routes them to the ärende-motorn.
 *
 * Classification and ärende-matchning are NOT produced here (see
 * InflodeFeedService). This controller is a thin, graceful projection: any
 * failure of the underlying source degrades to a well-formed empty payload
 * (never a 500, never fabricated PII).
 *
 * Routes (to be appended to sdkmc appinfo/routes.php under 'ocs'):
 *   ['name' => 'OCS\\InflodeFeed#summary', 'url' => '/api/v1/inflode-summary',  'verb' => 'GET'],
 *   ['name' => 'OCS\\InflodeFeed#action',  'url' => '/api/v1/inflode/{action}', 'verb' => 'POST'],
 */

namespace OCA\SdkMc\Controller\OCS;

use OCA\SdkMc\Service\InflodeFeedService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

class InflodeFeedController extends OCSController {

    /**
     * Message-level verbs this controller owns. Everything else (notably the
     * ärende verbs 'skapa'/'koppla') is rejected and routed to hubs_arende.
     */
    private const MESSAGE_ACTIONS = ['besvara', 'vidarebefordra'];

    /** Ärende verbs explicitly owned by the ärende-motorn (hubs_arende). */
    private const ARENDE_ACTIONS = ['skapa', 'koppla'];

    public function __construct(
        string $appName,
        IRequest $request,
        private InflodeFeedService $inflodeFeedService,
        private IUserSession $userSession,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Raw incoming-message feed for the signed-in user.
     *
     * Returns the shape the SPA's `fetchInflodeSummary()` expects:
     *   { korgar: [{ addr, label, scope, otriagerat }],
     *     inflode: [ { id, kind, korg, channel, messageType, avsandare,
     *                  identitet, titel, inkomDatum, messageId, deepLink } ] }
     *
     * On dev15 (no real SDK inflow / mail source) this is honestly empty:
     * { korgar: [], inflode: [] } — never synthetic messages.
     *
     * @return DataResponse<Http::STATUS_OK|Http::STATUS_UNAUTHORIZED, array<string, mixed>, array{}>
     */
    #[NoAdminRequired]
    public function summary(): DataResponse {
        $userId = $this->userSession->getUser()?->getUID();
        if ($userId === null) {
            return new DataResponse(['korgar' => [], 'inflode' => []], Http::STATUS_UNAUTHORIZED);
        }

        $summary = $this->inflodeFeedService->getInflodeSummary($userId);
        return new DataResponse($summary);
    }

    /**
     * Perform a message-level action on a feed row.
     *
     * Only 'besvara' (reply) and 'vidarebefordra' (forward) are handled here —
     * both are pure message operations on an sdkmc thread. The ärende verbs
     * 'skapa' and 'koppla' are owned by hubs_arende and are rejected with
     * 400 + { error: 'agas_av_arende_motorn' } so the SPA calls the ärende-motorn
     * instead. Any unknown verb is a 400 + { error: 'okand_atgard' }.
     *
     * The actual send/forward is performed by the mail/SDK composer the SPA
     * opens via the returned deepLink — this endpoint validates the verb, scopes
     * it to the message domain and returns where to land. It does NOT itself
     * mutate citizen-facing state, so it can never fabricate PII or a delivery.
     *
     * @param string $action  one of 'besvara' | 'vidarebefordra'
     * @param ?string $messageId  the feed row's messageId (the SDK/mail message id)
     * @return DataResponse<Http::STATUS_OK|Http::STATUS_BAD_REQUEST|Http::STATUS_UNAUTHORIZED, array<string, mixed>, array{}>
     */
    #[NoAdminRequired]
    public function action(string $action, ?string $messageId = null): DataResponse {
        $userId = $this->userSession->getUser()?->getUID();
        if ($userId === null) {
            return new DataResponse(['error' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
        }

        $verb = strtolower(trim($action));

        // Ärende verbs are not ours — hand them back to the ärende-motorn.
        if (in_array($verb, self::ARENDE_ACTIONS, true)) {
            return new DataResponse([
                'error' => 'agas_av_arende_motorn',
                'action' => $verb,
                // Hint the SPA to the owning domain without hardcoding its routes.
                'owner' => 'hubs_arende',
            ], Http::STATUS_BAD_REQUEST);
        }

        if (!in_array($verb, self::MESSAGE_ACTIONS, true)) {
            return new DataResponse([
                'error' => 'okand_atgard',
                'action' => $verb,
            ], Http::STATUS_BAD_REQUEST);
        }

        $mid = $messageId !== null ? trim($messageId) : '';
        if ($mid === '') {
            return new DataResponse([
                'error' => 'messageId_saknas',
                'action' => $verb,
            ], Http::STATUS_BAD_REQUEST);
        }

        // Resolve where the composer should land. The service degrades to a safe
        // mailbox landing when the thread cannot be resolved (dev15 / retention).
        $deepLink = $this->inflodeFeedService->deepLinkForMessage($mid);

        return new DataResponse([
            'ok' => true,
            'action' => $verb,
            'messageId' => $mid,
            'deepLink' => $deepLink,
        ]);
    }
}
