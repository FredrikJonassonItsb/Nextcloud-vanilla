<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Controller;

use OCA\AgentEngine\AppInfo\Application;
use OCA\AgentEngine\Exception\NotEligibleException;
use OCA\AgentEngine\Service\LedgerService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * PUT /api/v1/ledger/{agentCode} (CONTRACTS §3): upsert the agent's single
 * AGENT STATUS comment on the ledger card, IN PLACE (KARTLAGGNING §4.8
 * format). Body = the status fields.
 */
class LedgerController extends OCSController {
    public function __construct(
        IRequest $request,
        private LedgerService $ledgerService,
        private BotGuard $guard,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /** KARTLAGGNING §4.8 status fields, read from the FLAT JSON body. */
    private const BODY_FIELDS = [
        'human', 'runtime', 'automation', 'automationState',
        'lastHeartbeat', 'lastQueueResult', 'lastSuccessfulRun',
        'localContext', 'optionalSkills', 'notes',
    ];

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function update(string $agentCode): DataResponse {
        if (!$this->guard->isBotCaller() || !$this->guard->mayActFor($agentCode)) {
            return new DataResponse(['error' => 'bot_only'], Http::STATUS_FORBIDDEN);
        }
        // The body is a FLAT object of §4.8 fields (CONTRACTS §3). NC does not
        // bind a flat body to an array method-param, so read each known field
        // from the merged request params; a wrapped {"fields":{…}} body is also
        // tolerated for forward-compat.
        $fields = [];
        $wrapped = $this->request->getParam('fields');
        if (is_array($wrapped)) {
            $fields = $wrapped;
        }
        foreach (self::BODY_FIELDS as $key) {
            $value = $this->request->getParam($key);
            if ($value !== null) {
                $fields[$key] = $value;
            }
        }
        try {
            $result = $this->ledgerService->upsert($agentCode, $fields);
            return new DataResponse($result, Http::STATUS_OK);
        } catch (NotEligibleException $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            $this->logger->error('agent_engine: ledger endpoint failed', [
                'app' => 'agent_engine', 'agentCode' => $agentCode, 'exception' => $e,
            ]);
            return new DataResponse(['error' => 'ledger_failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}
