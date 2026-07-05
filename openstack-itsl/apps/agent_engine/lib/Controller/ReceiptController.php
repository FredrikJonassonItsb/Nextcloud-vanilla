<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Controller;

use OCA\AgentEngine\AppInfo\Application;
use OCA\AgentEngine\Exception\NotEligibleException;
use OCA\AgentEngine\Service\ReceiptService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * POST /api/v1/receipt/{engineCardId} (CONTRACTS §3): receipt comment +
 * optional stack move (move: needs_input|review|done|working). Token must be
 * ∈ the §2 vocabulary — 422 otherwise.
 */
class ReceiptController extends OCSController {
    public function __construct(
        IRequest $request,
        private ReceiptService $receiptService,
        private BotGuard $guard,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function post(int $engineCardId, string $token = '', string $text = '', ?string $move = null): DataResponse {
        if (!$this->guard->isBotCaller()) {
            return new DataResponse(['error' => 'bot_only'], Http::STATUS_FORBIDDEN);
        }
        try {
            $result = $this->receiptService->post($engineCardId, $token, $text, $move);
            return new DataResponse($result, Http::STATUS_OK);
        } catch (NotEligibleException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            $this->logger->error('agent_engine: receipt endpoint failed', [
                'app' => 'agent_engine', 'cardId' => $engineCardId, 'exception' => $e,
            ]);
            return new DataResponse(['error' => 'receipt_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
