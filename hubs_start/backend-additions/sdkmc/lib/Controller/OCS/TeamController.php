<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * HUBS-START BACKEND-ADDITION · UPSTREAM-KANDIDAT · Target: lib/Controller/OCS/TeamController.php
 *
 * NEW FILE for the sdkmc app. Thin OCS surface over TeamService for the Hubs
 * Start "Enhetschatt"/team side panel (EnhetschattPanel). All resolution logic
 * lives in OCA\SdkMc\Service\TeamService so the controller never depends on a
 * method that doesn't exist. The JSON shape is the contract for `fetchTeam`
 * (see hubs_start/src/services/demo/socialsekreterare.js → `team`).
 *
 * Route (appended to sdkmc appinfo/routes.php under 'ocs'):
 *   ['name' => 'OCS\\Team#index', 'url' => '/api/v1/team', 'verb' => 'GET'],
 */

namespace OCA\SdkMc\Controller\OCS;

use OCA\SdkMc\Service\TeamService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class TeamController extends OCSController {

	public function __construct(
		string $appName,
		IRequest $request,
		private TeamService $teamService,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * The signed-in user's enhet/team membership and members, for the
	 * EnhetschattPanel side panel.
	 *
	 * Returns a list of teams (NC groups the user belongs to), each with its
	 * members resolved to uid + display name and honest-neutral presence fields.
	 * See TeamService::getTeamsForUser() for the exact shape.
	 *
	 * Graceful: no signed-in user → UNAUTHORIZED with an empty list; no groups or
	 * any backend failure → an empty (but valid) list, never a 500.
	 *
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_UNAUTHORIZED, list<array<string, mixed>>, array{}>
	 */
	#[NoAdminRequired]
	public function index(): DataResponse {
		$userId = $this->userSession->getUser()?->getUID();
		if ($userId === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$teams = $this->teamService->getTeamsForUser($userId);
		} catch (\Throwable $e) {
			$this->logger->error('[hubs-start] team listing failed: ' . $e->getMessage(), [
				'exception' => $e,
				'userId' => $userId,
			]);
			// Never break the side panel: return an empty-but-valid list so the
			// frontend renders its empty state instead of erroring.
			$teams = [];
		}

		return new DataResponse($teams);
	}
}
