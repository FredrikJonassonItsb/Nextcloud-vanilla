<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * HUBS-START BACKEND-ADDITION · UPSTREAM-KANDIDAT · Target: lib/Controller/OCS/ArendeEnrichmentController.php
 *
 * NEW FILE for the sdkmc app. Thin OCS surface over ArendeEnrichmentService for
 * the ärende-kort "diskussion"-enrichment (#3/#15/#16). All read logic lives in
 * OCA\SdkMc\Service\ArendeEnrichmentService so the controller never depends on a
 * method that doesn't exist. The JSON shape is the contract the card consumes.
 *
 * Route (appended to sdkmc appinfo/routes.php under 'ocs'):
 *   ['name' => 'OCS\\ArendeEnrichment#show', 'url' => '/api/v1/arende-enrichment', 'verb' => 'GET'],
 *
 * (frontend calls GET /api/v1/arende-enrichment?talkToken=…)
 */

namespace OCA\SdkMc\Controller\OCS;

use OCA\SdkMc\Service\ArendeEnrichmentService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class ArendeEnrichmentController extends OCSController {

	public function __construct(
		string $appName,
		IRequest $request,
		private ArendeEnrichmentService $enrichmentService,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * PII-free diskussion-enrichment for one ärenderum, keyed by its
	 * diskussions-token.
	 *
	 * Returns the #16 summary shape (see ArendeEnrichmentService::diskussionForToken):
	 *   { diskussion: { olasta, omnamnandeTillMig, deltagare[], meddelanden[] },
	 *     meddelanden: [], moten: [] }
	 *
	 * Graceful: no signed-in user → UNAUTHORIZED with an empty list; spreed
	 * unavailable, empty/unknown token, or any backend failure → the honest-empty
	 * enrichment shape, never a 500.
	 *
	 * @param string $talkToken The ärenderum's diskussions-token (wire field).
	 * @return DataResponse<Http::STATUS_OK|Http::STATUS_UNAUTHORIZED, array<string, mixed>, array{}>
	 */
	#[NoAdminRequired]
	public function show(string $talkToken = ''): DataResponse {
		$userId = $this->userSession->getUser()?->getUID();
		if ($userId === null) {
			return new DataResponse([], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$enrichment = $this->enrichmentService->diskussionForToken($talkToken);
		} catch (\Throwable $e) {
			$this->logger->error('[hubs-start] arende-enrichment failed: ' . $e->getMessage(), [
				'exception' => $e,
				'userId' => $userId,
			]);
			// Never break the ärende-kort: return an empty-but-valid enrichment so
			// the frontend renders its empty state instead of erroring.
			$enrichment = $this->emptyEnrichment();
		}

		return new DataResponse($enrichment);
	}

	/**
	 * A valid, honest-empty enrichment payload (safe fallback so the card always
	 * renders). Mirrors ArendeEnrichmentService's empty shape.
	 *
	 * @return array<string, mixed>
	 */
	private function emptyEnrichment(): array {
		return [
			'diskussion' => [
				'olasta' => 0,
				'omnamnandeTillMig' => false,
				'deltagare' => [],
				'meddelanden' => [],
			],
			'meddelanden' => [],
			'moten' => [],
		];
	}
}
