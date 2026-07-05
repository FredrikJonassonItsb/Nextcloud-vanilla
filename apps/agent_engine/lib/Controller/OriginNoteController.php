<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Controller;

use OCA\AgentEngine\AppInfo\Application;
use OCA\AgentEngine\Exception\NotEligibleException;
use OCA\AgentEngine\Exception\PiiRejectedException;
use OCA\AgentEngine\Service\OriginNoteService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * POST /api/v1/origin-note/{engineCardId} (CONTRACTS §3): the relay — the
 * ONLY LLM→human-board path. Deterministic PHP writes the ⇄ note through the
 * shared mirror module; firewalled, rate-limited (1 per link state).
 */
class OriginNoteController extends OCSController {
    public function __construct(
        IRequest $request,
        private OriginNoteService $originNoteService,
        private BotGuard $guard,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function post(int $engineCardId, string $text = ''): DataResponse {
        if (!$this->guard->isBotCaller()) {
            return new DataResponse(['error' => 'bot_only'], Http::STATUS_FORBIDDEN);
        }
        try {
            $result = $this->originNoteService->post($engineCardId, $text);
            return new DataResponse($result, Http::STATUS_OK);
        } catch (PiiRejectedException $e) {
            return new DataResponse([
                'error' => 'pii_blocked',
                'message' => $e->getMessage(),
            ], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (NotEligibleException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            $this->logger->error('agent_engine: origin-note endpoint failed', [
                'app' => 'agent_engine', 'cardId' => $engineCardId, 'exception' => $e,
            ]);
            return new DataResponse(['error' => 'origin_note_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
