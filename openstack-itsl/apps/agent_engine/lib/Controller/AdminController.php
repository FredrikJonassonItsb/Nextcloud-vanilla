<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Controller;

use OCA\AgentEngine\AppInfo\Application;
use OCA\AgentEngine\Db\EnrolledBoardMapper;
use OCA\AgentEngine\Integration\BotServiceAuth;
use OCA\AgentEngine\Protocol;
use OCA\AgentEngine\Service\EngineConfig;
use OCA\AgentEngine\Service\PiiFirewall;
use OCA\AgentEngine\Service\PushService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Admin surface (CONTRACTS §3): enrollment administration + push fan-out
 * test. No NoAdminRequired attribute ⇒ NC enforces an admin session.
 */
class AdminController extends OCSController {
    public function __construct(
        IRequest $request,
        private EnrolledBoardMapper $boardMapper,
        private EngineConfig $config,
        private BotServiceAuth $auth,
        private PiiFirewall $firewall,
        private PushService $push,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /** GET /api/v1/takeover/config — the takeover machinery's health view. */
    #[NoCSRFRequired]
    public function config(): DataResponse {
        return new DataResponse([
            'engineBoardId' => $this->config->engineBoardId(),
            'ledgerCardId' => $this->config->ledgerCardId(),
            'runnerBase' => $this->config->runnerBase(),
            'pushSecretConfigured' => $this->config->pushSecret() !== '',
            'botCredentialConfigured' => $this->auth->isConfigured(),
            'piiPatternCount' => $this->firewall->patternCount(),
            'bots' => Protocol::botUids(),
            'enrolledBoards' => array_map(
                static fn ($board) => $board->jsonSerialize(),
                $this->boardMapper->findAll(),
            ),
        ], Http::STATUS_OK);
    }

    /**
     * PUT /api/v1/boards/{boardId}/enroll — idempotent enrollment upsert.
     * The Deck-side ACL + label provisioning is enroll-board.mjs's job
     * (INTERAKTIONSDESIGN §2.10); this records the authorization decision
     * (incl. pii_reviewed_by — §2.11 control 1) and flips the takeover gate.
     */
    #[NoCSRFRequired]
    public function enroll(
        int $boardId,
        bool $enabled = true,
        string $onDone = 'comment_only',
        bool $conservative = false,
        string $piiReviewedBy = '',
    ): DataResponse {
        if ($boardId <= 0) {
            return new DataResponse(['error' => 'invalid_board'], Http::STATUS_BAD_REQUEST);
        }
        if ($boardId === $this->config->engineBoardId()) {
            return new DataResponse(['error' => 'engine_board_not_enrollable'], Http::STATUS_UNPROCESSABLE_ENTITY);
        }
        if ($onDone !== 'comment_only' && preg_match('/^move_to_stack:\d+$/', $onDone) !== 1) {
            return new DataResponse(['error' => 'invalid_on_done'], Http::STATUS_BAD_REQUEST);
        }
        $enrolledBy = $this->userSession->getUser()?->getUID() ?? '';
        $board = $this->boardMapper->upsert($boardId, $enabled, $onDone, $conservative, $piiReviewedBy, $enrolledBy);
        $this->logger->info('agent_engine: board enrollment updated', [
            'app' => 'agent_engine',
            'boardId' => $boardId,
            'enabled' => $enabled,
            'by' => $enrolledBy,
        ]);
        return new DataResponse($board->jsonSerialize(), Http::STATUS_OK);
    }

    /** POST /api/v1/push-test — HMAC fan-out test to the runner listener. */
    #[NoCSRFRequired]
    public function pushTest(string $agentCode = ''): DataResponse {
        $codes = $agentCode !== ''
            ? [$agentCode]
            : array_values(array_filter(array_map(
                static fn (string $bot): ?string => Protocol::agentCodeForBot($bot),
                array_keys(Protocol::IDENTITIES),
            )));
        $results = [];
        foreach ($codes as $code) {
            $results[$code] = $this->push->wake($code);
        }
        return new DataResponse(['results' => $results], Http::STATUS_OK);
    }
}
