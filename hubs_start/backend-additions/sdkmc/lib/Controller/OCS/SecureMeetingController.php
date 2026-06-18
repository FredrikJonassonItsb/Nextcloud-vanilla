<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * NEW FILE for the sdkmc app. Target: lib/Controller/OCS/SecureMeetingController.php
 */

namespace OCA\SdkMc\Controller\OCS;

use OCA\SdkMc\Service\SecureMeetingService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * OCS endpoint behind api.createSecureMeeting().
 *
 *   POST /ocs/v2.php/apps/sdkmc/api/v1/secure-meeting
 *
 * Reads the SecureMeetingRequest JSON body and delegates the whole
 * Talk-room + CalDAV + intents orchestration to SecureMeetingService, returning
 * the exact contract shape { token, eventUid, start, end, smsStatus, protection }.
 */
class SecureMeetingController extends OCSController {

    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $userSession,
        private LoggerInterface $logger,
        private SecureMeetingService $secureMeetingService,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Create a secure meeting in one server-side operation.
     *
     * Body params (SecureMeetingRequest, see docs/CONTRACTS.md):
     *   citizen{ name, ssn?, mobile?, secureEmail? }, colleagueUserId?, start,
     *   end, title, dnr?, requireBankId, sendSms, sendSecureEmailInvite,
     *   fromMailboxId?
     *
     * @return DataResponse<Http::STATUS_OK, array{token: string, eventUid: string, start: string, end: string, smsStatus: string, protection: array{bankId: bool, sms: bool, secureEmail: bool}}, array{}>|DataResponse<Http::STATUS_UNAUTHORIZED, array{error: string}, array{}>|DataResponse<Http::STATUS_INTERNAL_SERVER_ERROR, array{error: string}, array{}>
     *
     * 200: The created meeting
     * 401: No authenticated user
     * 500: Orchestration failed (e.g. Talk room could not be created)
     */
    #[NoAdminRequired]
    public function create(
        ?array $citizen = null,
        ?string $colleagueUserId = null,
        ?string $start = null,
        ?string $end = null,
        ?string $title = null,
        ?string $dnr = null,
        bool $requireBankId = true,
        bool $sendSms = false,
        bool $sendSecureEmailInvite = false,
        ?int $fromMailboxId = null,
    ): DataResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new DataResponse(['error' => 'Authentication required'], Http::STATUS_UNAUTHORIZED);
        }

        $request = [
            'citizen' => is_array($citizen) ? $citizen : [],
            'colleagueUserId' => $colleagueUserId,
            'start' => $start ?? '',
            'end' => $end ?? '',
            'title' => $title ?? '',
            'dnr' => $dnr,
            'requireBankId' => $requireBankId,
            'sendSms' => $sendSms,
            'sendSecureEmailInvite' => $sendSecureEmailInvite,
            'fromMailboxId' => $fromMailboxId,
        ];

        try {
            $result = $this->secureMeetingService->create($user->getUID(), $request);
            return new DataResponse($result);
        } catch (\Throwable $e) {
            $this->logger->error('[HUBS-START] Secure meeting creation failed: ' . $e->getMessage(), [
                'exception' => $e,
            ]);
            return new DataResponse(
                ['error' => 'Could not create secure meeting'],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }
    }
}
