<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * HUBS-START BACKEND-ADDITION · UPSTREAM-KANDIDAT · Target: lib/Controller/OCS/CaseMessagesController.php
 *
 * NEW FILE for the sdkmc app. Thin OCS surface over CaseMessagesService for the
 * ärende-kort "Meddelanden"-flik: alla meddelanden kopplade till ett ärende via
 * case:-taggen, ACL-filtrerade till anroparens korgar.
 *
 * Route (appended to sdkmc appinfo/routes.php under 'ocs'):
 *   ['name' => 'OCS\\CaseMessages#index', 'url' => '/api/v1/case-messages', 'verb' => 'GET'],
 *
 * (frontend calls GET /api/v1/case-messages?ref={hubsCaseId})
 */

namespace OCA\SdkMc\Controller\OCS;

use OCA\SdkMc\Service\CaseMessagesService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class CaseMessagesController extends OCSController {

	public function __construct(
		string $appName,
		IRequest $request,
		private CaseMessagesService $caseMessagesService,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Alla meddelanden kopplade till ärendet (case:-taggen), nyast först,
	 * begränsade till anroparens korgar (mailbox-ACL).
	 *
	 * Graceful: ingen inloggad användare → 401; alla andra fel → ärligt tom
	 * lista (kortet renderar sin tomvy), aldrig 500.
	 *
	 * @param string $ref Ärendets hubsCaseId (case:-taggens suffix).
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_UNAUTHORIZED, array<string, mixed>, array{}>
	 */
	#[NoAdminRequired]
	public function index(string $ref = ''): DataResponse {
		$userId = $this->userSession->getUser()?->getUID();
		if ($userId === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$meddelanden = $this->caseMessagesService->getCaseMessages($userId, $ref);
		} catch (\Throwable $e) {
			$this->logger->error('[hubs-start] case-messages failed: ' . $e->getMessage(), [
				'exception' => $e,
				'userId' => $userId,
			]);
			$meddelanden = [];
		}

		return new DataResponse(['meddelanden' => $meddelanden]);
	}
}
