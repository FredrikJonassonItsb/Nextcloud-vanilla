<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * NEW FILE for the sdkmc app. Target: lib/Controller/OCS/NoteToSelfController.php
 */

namespace OCA\SdkMc\Controller\OCS;

use OCA\SdkMc\Service\NoteToSelfWrapperService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * "Egna anteckningar" — the caseworker's own private notes, read/written
 * through spreed's per-user note-to-self conversation but exposed to the Hubs
 * Start dashboard as a plain {notes}/{note} contract (the frontend never talks
 * to spreed directly).
 *
 * GET  /api/v1/note-to-self → { notes: [{id,text,createdAt}, …] }  (newest-first)
 * POST /api/v1/note-to-self → { note: {id,text,createdAt} }        (param: text)
 *
 * Routes (appended to the LIVE app appinfo/routes.php under 'ocs' — the lead
 * wires these on dev15 at deploy; this file does NOT touch routes.php):
 *   ['name' => 'OCS\\NoteToSelf#index',  'url' => '/api/v1/note-to-self', 'verb' => 'GET'],
 *   ['name' => 'OCS\\NoteToSelf#create', 'url' => '/api/v1/note-to-self', 'verb' => 'POST'],
 */
class NoteToSelfController extends OCSController {

    public function __construct(
        string $appName,
        IRequest $request,
        private NoteToSelfWrapperService $notes,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * List the signed-in caseworker's own notes, newest-first.
     *
     * Honest-empty on any failure so the dashboard widget renders its empty
     * state instead of erroring (and an empty list when spreed is absent).
     *
     * @return DataResponse<Http::STATUS_OK, array{notes: list<array{id: string, text: string, createdAt: string}>}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array<empty>, array{}>
     *
     * 200: { notes }
     * 401: No authenticated user
     */
    #[NoAdminRequired]
    public function index(): DataResponse {
        $userId = $this->userSession->getUser()?->getUID();
        if ($userId === null) {
            return new DataResponse([], Http::STATUS_UNAUTHORIZED);
        }

        try {
            $notes = $this->notes->list($userId, 50);
        } catch (\Throwable $e) {
            $this->logger->error('[hubs-start] note-to-self list failed: ' . $e->getMessage(), [
                'exception' => $e,
                'userId' => $userId,
            ]);
            $notes = [];
        }

        return new DataResponse(['notes' => $notes]);
    }

    /**
     * Append a note for the signed-in caseworker.
     *
     * @param string $text The note body (param name is 'text', NOT 'message').
     *
     * @return DataResponse<Http::STATUS_OK, array{note: array{id: string, text: string, createdAt: string}}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array<empty>, array{}>|DataResponse<Http::STATUS_BAD_REQUEST|Http::STATUS_SERVICE_UNAVAILABLE, array{error: string}, array{}>
     *
     * 200: { note }
     * 400: Empty text
     * 401: No authenticated user
     * 503: Notes backend unavailable (spreed absent / write failed)
     */
    #[NoAdminRequired]
    public function create(string $text = ''): DataResponse {
        $userId = $this->userSession->getUser()?->getUID();
        if ($userId === null) {
            return new DataResponse([], Http::STATUS_UNAUTHORIZED);
        }

        $text = trim($text);
        if ($text === '') {
            return new DataResponse(['error' => 'text'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $note = $this->notes->add($userId, $text);
        } catch (\Throwable $e) {
            $this->logger->error('[hubs-start] note-to-self add failed: ' . $e->getMessage(), [
                'exception' => $e,
                'userId' => $userId,
            ]);
            return new DataResponse(['error' => 'unavailable'], Http::STATUS_SERVICE_UNAVAILABLE);
        }

        return new DataResponse(['note' => $note]);
    }
}
