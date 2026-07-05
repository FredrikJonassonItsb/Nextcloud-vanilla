<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Controller;

use OCA\AgentEngine\AppInfo\Application;
use OCA\AgentEngine\Exception\NotEligibleException;
use OCA\AgentEngine\Service\QueueService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * GET /api/v1/queue/{agentCode} (CONTRACTS §3): next eligible card (oldest
 * first) + open BLOCKED/HOLD/Review resumables — server-side filtering,
 * because Deck has none.
 */
class QueueController extends OCSController {
    public function __construct(
        IRequest $request,
        private QueueService $queueService,
        private BotGuard $guard,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function queue(string $agentCode): DataResponse {
        if (!$this->guard->isBotCaller() || !$this->guard->mayActFor($agentCode)) {
            return new DataResponse(['error' => 'bot_only'], Http::STATUS_FORBIDDEN);
        }
        try {
            return new DataResponse($this->queueService->queue($agentCode), Http::STATUS_OK);
        } catch (NotEligibleException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            $this->logger->error('agent_engine: queue endpoint failed', [
                'app' => 'agent_engine', 'agentCode' => $agentCode, 'exception' => $e,
            ]);
            return new DataResponse(['error' => 'queue_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
