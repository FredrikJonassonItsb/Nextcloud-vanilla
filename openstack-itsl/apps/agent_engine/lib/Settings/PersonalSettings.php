<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Settings;

use OCA\AgentEngine\AppInfo\Application;
use OCA\AgentEngine\Db\EnrolledBoardMapper;
use OCA\AgentEngine\Integration\Client\DeckApiClient;
use OCA\AgentEngine\Protocol;
use OCA\AgentEngine\Service\EngineConfig;
use OCA\AgentEngine\Service\LedgerService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IUserSession;
use OCP\Settings\ISettings;
use Psr\Log\LoggerInterface;

/**
 * Personal "Min agent" settings (read-only + instructional). Shows the logged-in
 * user their agent connection and which of their Deck boards are active. The
 * actual activation is the Deck-share auto-enroll (DeckAclListener) — this page
 * only explains and reflects it, so there is no write endpoint here.
 */
class PersonalSettings implements ISettings {
    public function __construct(
        private IUserSession $userSession,
        private EngineConfig $config,
        private EnrolledBoardMapper $boardMapper,
        private DeckApiClient $deck,
        private LedgerService $ledger,
        private IL10N $l,
        private LoggerInterface $logger,
    ) {
    }

    public function getForm(): TemplateResponse {
        $uid = $this->userSession->getUser()?->getUID() ?? '';

        // Agent connection(s) for this user (usually exactly one).
        $agents = [];
        foreach ($this->config->agentInfoForOwner($uid) as $info) {
            $agentCode = $info['agentCode'];
            $agent = $info['agent'];
            $presence = $this->presenceFor($agentCode);
            $agents[] = [
                'agentCode' => $agentCode,
                'agent' => $agent,
                'bot' => $info['bot'],
                'brain' => $this->brainName($agentCode),
                'talkRoom' => $this->talkRoomName($agent),
                'presence' => $presence,
            ];
        }

        // The user's own Deck boards (owner/manage) + activation status. We read
        // boards visible to bot-engine (the engine can see every board it has
        // been shared with) and keep those this user owns or manages.
        $boards = $this->boardsForUser($uid);

        $params = [
            'hasAgent' => $agents !== [],
            'agents' => $agents,
            'boards' => $boards,
            'engineBotName' => 'Agent Engine',
            'engineBotUid' => Protocol::ENGINE_BOT,
        ];
        return new TemplateResponse(Application::APP_ID, 'personal-settings', $params);
    }

    public function getSection(): string {
        return 'agent-engine';
    }

    public function getPriority(): int {
        return 40;
    }

    /** @return array{label:string,online:bool,stale:bool,paused:bool} */
    private function presenceFor(string $agentCode): array {
        try {
            $p = $this->ledger->presence($agentCode);
        } catch (\Throwable) {
            return ['label' => $this->l->t('okänd'), 'online' => false, 'stale' => true, 'paused' => false];
        }
        $heartbeat = $p['heartbeat'] ?? null;
        $paused = (bool)($p['paused'] ?? false);
        $staleAfter = $this->config->heartbeatStaleMinutes() * 60;
        $stale = $heartbeat === null || (time() - $heartbeat) > $staleAfter;
        $online = !$paused && !$stale;
        if ($paused) {
            $label = $this->l->t('pausad');
        } elseif ($online) {
            $label = $this->l->t('online');
        } else {
            $label = $this->l->t('inaktiv');
        }
        return ['label' => $label, 'online' => $online, 'stale' => $stale && !$paused, 'paused' => $paused];
    }

    /** Brain routing stem, derived from the agent code (reb-claude → reb). */
    private function brainName(string $agentCode): string {
        $stem = str_replace('-claude', '', $agentCode);
        return $stem !== '' ? $stem : $agentCode;
    }

    /** The agent's personal Talk memory room name (occ-provision: "<Agent> minne"). */
    private function talkRoomName(string $agentDisplay): string {
        // The display name may already carry "(agent)"; take the leading word.
        $name = trim(preg_replace('/\s*\(agent\)\s*/u', '', $agentDisplay) ?? $agentDisplay);
        $name = $name !== '' ? $name : $agentDisplay;
        return $name . ' minne';
    }

    /**
     * The boards this user owns or manages, with enrollment status. Best-effort
     * (Deck may be unreachable) — returns [] then and the template says so.
     *
     * @return array<int,array{id:int,title:string,enrolled:bool}>
     */
    private function boardsForUser(string $uid): array {
        try {
            $out = [];
            foreach ($this->deck->listBoards() as $board) {
                $boardId = (int)($board['id'] ?? 0);
                if ($boardId <= 0 || $boardId === $this->config->engineBoardId()) {
                    continue;
                }
                if (!$this->userOwnsOrManages($board, $uid)) {
                    continue;
                }
                $out[] = [
                    'id' => $boardId,
                    'title' => (string)($board['title'] ?? ('#' . $boardId)),
                    'enrolled' => $this->boardMapper->isEnrolled($boardId),
                ];
            }
            usort($out, static fn (array $a, array $b): int => strcasecmp($a['title'], $b['title']));
            return $out;
        } catch (\Throwable $e) {
            $this->logger->warning('agent_engine: personal boards lookup failed', [
                'app' => Protocol::ENGINE_BOT,
                'exception' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /** True when $uid is the board owner or holds a manage ACL. */
    private function userOwnsOrManages(array $board, string $uid): bool {
        $owner = $board['owner'] ?? null;
        $ownerUid = is_array($owner) ? (string)($owner['uid'] ?? '') : (string)$owner;
        if ($ownerUid === $uid) {
            return true;
        }
        foreach ((array)($board['acl'] ?? []) as $acl) {
            if (!is_array($acl) || empty($acl['permissionManage'])) {
                continue;
            }
            $participant = $acl['participant'] ?? null;
            $pUid = is_array($participant)
                ? (string)($participant['uid'] ?? $participant['primaryKey'] ?? '')
                : (string)$participant;
            if ($pUid === $uid) {
                return true;
            }
        }
        return false;
    }
}
