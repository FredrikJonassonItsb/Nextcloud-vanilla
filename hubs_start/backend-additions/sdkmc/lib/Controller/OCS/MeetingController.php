<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * NEW FILE for the sdkmc app. Target: lib/Controller/OCS/MeetingController.php
 *
 * Thin OCS surface over MeetingService (the shared aggregation used by both this
 * controller and the standard-dashboard DagensMotenWidget). All merge logic lives
 * in OCA\SdkMc\Service\MeetingService so the widget never depends on a method that
 * doesn't exist. The exact JSON shapes are the contract for
 * api.fetchTodaysMeetings / api.fetchLobbyStatus — see hubs_start/src/services/api.js.
 */

namespace OCA\SdkMc\Controller\OCS;

use OCA\SdkMc\Service\MeetingService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

class MeetingController extends OCSController {

    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $userSession,
        private MeetingService $meetingService,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Today's secure meetings, merged from CalDAV + secure-room state + BankID auth.
     * Each element matches DagensMoten.vue's expected shape.
     *
     * @return DataResponse<array<int, array<string, mixed>>>
     */
    #[NoAdminRequired]
    public function today(): DataResponse {
        $user = $this->userSession->getUser();
        if (!$user instanceof IUser) {
            return new DataResponse([]);
        }
        return new DataResponse($this->meetingService->getTodaysMeetings($user->getUID()));
    }

    /**
     * Live lobby state for one meeting: who is verified and waiting.
     *
     * @return DataResponse<array{waiting: list<array{actorId: string, displayName: string, verified: bool}>, verifiedCount: int}>
     */
    #[NoAdminRequired]
    public function lobby(string $token): DataResponse {
        $user = $this->userSession->getUser();
        if (!$user instanceof IUser) {
            return new DataResponse(['waiting' => [], 'verifiedCount' => 0]);
        }
        return new DataResponse($this->meetingService->getLobby($token));
    }

    // >>> HUBS-START-ADD (upstream-kandidat) ─ möten per ärende ──────────────
    /**
     * Ärendets bokade möten (kommande + genomförda), matchade på bokningens
     * dnr-märkning (X-HUBS-DNR / CATEGORIES hubs-dnr-*). Route:
     *   ['name' => 'OCS\\Meeting#forCase', 'url' => '/api/v1/arende-meetings', 'verb' => 'GET'],
     * (frontend: GET /api/v1/arende-meetings?refs=dnr,hubsCaseId)
     *
     * @param string $refs Kommaseparerade ärendereferenser (dnr + hubsCaseId).
     * @return DataResponse<array{kommande: list<array<string,mixed>>, genomforda: list<array<string,mixed>>}>
     */
    #[NoAdminRequired]
    public function forCase(string $refs = ''): DataResponse {
        $user = $this->userSession->getUser();
        if (!$user instanceof IUser) {
            return new DataResponse(['kommande' => [], 'genomforda' => []]);
        }
        $refList = array_values(array_filter(array_map('trim', explode(',', $refs))));
        return new DataResponse($this->meetingService->getCaseMeetings($user->getUID(), $refList));
    }
    // <<< HUBS-START-ADD ─────────────────────────────────────────────────────
}
