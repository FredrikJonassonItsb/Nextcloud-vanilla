<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Controller;

use OCA\AgentEngine\AppInfo\Application;
use OCA\AgentEngine\Exception\ClaimConflictException;
use OCA\AgentEngine\Exception\NotEligibleException;
use OCA\AgentEngine\Service\ClaimService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * POST /api/v1/claim/{engineCardId} (CONTRACTS §3): atomic claim in ONE
 * transaction — one winner (200 {cardId, reread}), loser 409 {claimedBy},
 * protocol precondition failures 422. Thin transport; ClaimService owns the
 * mutex and the Deck ops.
 */
class ClaimController extends OCSController {
    public function __construct(
        IRequest $request,
        private ClaimService $claimService,
        private BotGuard $guard,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function claim(int $engineCardId): DataResponse {
        // The claiming agent IS the caller — the agent code is derived from
        // the authenticated bot uid, never from the payload (identity is auth).
        $agentCode = $this->guard->callerAgentCode();
        if ($agentCode === null) {
            return new DataResponse(['error' => 'bot_only'], Http::STATUS_FORBIDDEN);
        }
        try {
            $result = $this->claimService->claim($engineCardId, $agentCode);
            return new DataResponse($result, Http::STATUS_OK);
        } catch (ClaimConflictException $e) {
            return new DataResponse(['claimedBy' => $e->getClaimedBy()], Http::STATUS_CONFLICT);
        } catch (NotEligibleException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            $this->logger->error('agent_engine: claim endpoint failed', [
                'app' => 'agent_engine', 'cardId' => $engineCardId, 'exception' => $e,
            ]);
            return new DataResponse(['error' => 'claim_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
