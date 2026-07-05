<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Settings;

use OCA\AgentEngine\AppInfo\Application;
use OCA\AgentEngine\Db\EnrolledBoardMapper;
use OCA\AgentEngine\Integration\BotServiceAuth;
use OCA\AgentEngine\Integration\Client\DeckApiClient;
use OCA\AgentEngine\Protocol;
use OCA\AgentEngine\Service\EngineConfig;
use OCA\AgentEngine\Service\PiiFirewall;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use Psr\Log\LoggerInterface;

/**
 * Admin "Agent Engine" settings — a read-only health + configuration view
 * (v1). Surfaces the routing map (human ↔ agent), the enrolled boards with
 * their Deck titles, the bot users and engine health (same data as
 * AdminController::config()). The routing map itself is still set via
 * provision/occ-provision.sh + HUMAN_UID_* — noted in the template.
 */
class AdminSettings implements ISettings {
    public function __construct(
        private EngineConfig $config,
        private EnrolledBoardMapper $boardMapper,
        private BotServiceAuth $auth,
        private PiiFirewall $firewall,
        private DeckApiClient $deck,
        private LoggerInterface $logger,
    ) {
    }

    public function getForm(): TemplateResponse {
        $enrolled = [];
        foreach ($this->boardMapper->findAllEnabled() as $board) {
            $enrolled[] = [
                'boardId' => $board->getBoardId(),
                'title' => $this->boardTitle($board->getBoardId()),
                'onDone' => $board->getOnDone(),
                'enrolledBy' => $board->getEnrolledBy(),
                'piiReviewedBy' => $board->getPiiReviewedBy(),
            ];
        }

        $params = [
            'routingRows' => $this->config->routingRows(),
            'enrolledBoards' => $enrolled,
            'bots' => Protocol::botUids(),
            'engineBotUid' => Protocol::ENGINE_BOT,
            'health' => [
                'engineBoardId' => $this->config->engineBoardId(),
                'ledgerCardId' => $this->config->ledgerCardId(),
                'runnerBase' => $this->config->runnerBase(),
                'pushSecretConfigured' => $this->config->pushSecret() !== '',
                'botCredentialConfigured' => $this->auth->isConfigured(),
                'piiPatternCount' => $this->firewall->patternCount(),
            ],
        ];
        return new TemplateResponse(Application::APP_ID, 'admin-settings', $params);
    }

    public function getSection(): string {
        return 'agent-engine';
    }

    public function getPriority(): int {
        return 40;
    }

    /** Best-effort Deck board title (bot-engine read); '#<id>' when unreachable. */
    private function boardTitle(int $boardId): string {
        try {
            $board = $this->deck->getBoard($boardId);
            $title = is_array($board) ? (string)($board['title'] ?? '') : '';
            return $title !== '' ? $title : ('#' . $boardId);
        } catch (\Throwable $e) {
            $this->logger->debug('agent_engine: board title lookup failed', [
                'app' => Protocol::ENGINE_BOT,
                'boardId' => $boardId,
                'exception' => $e->getMessage(),
            ]);
            return '#' . $boardId;
        }
    }
}
