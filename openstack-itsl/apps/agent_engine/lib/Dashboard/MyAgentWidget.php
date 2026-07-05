<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Dashboard;

use OCA\AgentEngine\AppInfo\Application;
use OCA\AgentEngine\Service\DashboardService;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IOptionWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\Dashboard\Model\WidgetOptions;
use OCP\IL10N;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * "Min agent" — the single per-person overview surface (INTERAKTIONSDESIGN
 * §2.9). Answers "vad väntar på MIG / vad gör min agent" in one glance, on the
 * user's own dashboard, with every row deep-linking to the specific Deck card.
 *
 * IAPIWidgetV2 returns WidgetItems as DATA — Nextcloud renders them with its own
 * built-in dashboard template (no Vue/JS build; this app has no frontend
 * pipeline). The whole surface is a ROUTER, never a workspace: no protocol
 * tokens, no actions, just markers + relative age + a link back to the card.
 *
 * Failure discipline: a throw here fatals the entire dashboard, so the widget
 * NEVER lets one out — DashboardService is defensive and getItemsV2() wraps it
 * in a final catch-all that degrades to one graceful row.
 */
class MyAgentWidget implements IAPIWidgetV2, IIconWidget, IButtonWidget, IOptionWidget {
    public const WIDGET_ID = 'agent_engine-my-agent';

    public function __construct(
        private DashboardService $dashboard,
        private IL10N $l10n,
        private IURLGenerator $urlGenerator,
        private LoggerInterface $logger,
    ) {
    }

    public function getId(): string {
        return self::WIDGET_ID;
    }

    public function getTitle(): string {
        return $this->l10n->t('Min agent');
    }

    public function getOrder(): int {
        return 20;
    }

    public function getIconClass(): string {
        // Core icon that ships black/monochrome (auto-inverted in dark mode).
        return 'icon-category-monitoring';
    }

    public function getIconUrl(): string {
        return $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->imagePath(Application::APP_ID, 'app.svg'),
        );
    }

    /** The widget's "own view" = the Agent Engine Deck board. */
    public function getUrl(): ?string {
        return $this->dashboard->engineBoardUrl();
    }

    public function load(): void {
        // No scripts/styles — NC renders the WidgetItems with its own template.
    }

    /**
     * @return list<WidgetButton>
     */
    public function getWidgetButtons(string $userId): array {
        $boardUrl = $this->dashboard->engineBoardUrl();
        if ($boardUrl === '') {
            return [];
        }
        return [
            new WidgetButton(WidgetButton::TYPE_MORE, $boardUrl, $this->l10n->t('Öppna Agent Engine-tavlan')),
        ];
    }

    public function getWidgetOptions(): WidgetOptions {
        // Bot avatars are square; keep item icons square too.
        return new WidgetOptions(false);
    }

    public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
        try {
            $data = $this->dashboard->overview($userId, $limit);

            if (!$data['hasAgent']) {
                return new WidgetItems(
                    [],
                    $this->l10n->t('Ingen agent kopplad till dig ännu.'),
                );
            }

            if (!$data['boardReachable']) {
                // Deck down / board not configured — one honest row, never a crash.
                $item = new WidgetItem(
                    $this->l10n->t('Kan inte nå agenttavlan just nu'),
                    $this->l10n->t('Statusen visas igen när Deck svarar.'),
                    (string)($data['boardUrl'] ?? ''),
                );
                return new WidgetItems([$item], '', $this->headerSubtitle($data));
            }

            $items = [];
            foreach ($data['rows'] as $row) {
                $items[] = new WidgetItem(
                    (string)$row['title'],
                    (string)$row['subtitle'],
                    (string)$row['link'],
                    '',
                    (string)$row['sinceId'],
                );
            }

            // Empty content message when the human has an agent but nothing is
            // pending — presence still tells them the agent is alive/idle.
            $emptyMessage = $this->l10n->t('Inget väntar på dig — %s.', [$this->headerSubtitle($data)]);
            return new WidgetItems($items, $items === [] ? $emptyMessage : '', $this->headerSubtitle($data));
        } catch (\Throwable $e) {
            // The dashboard must survive any failure in here.
            $this->logger->error('agent_engine: MyAgent widget failed', [
                'app' => Application::APP_ID, 'exception' => $e,
            ]);
            return new WidgetItems(
                [],
                $this->l10n->t('Agentöversikten kunde inte laddas just nu.'),
            );
        }
    }

    /**
     * Header/presence line for the empty-content messages.
     *
     * @param array{agentCodes:string[],presence:array{label:string}} $data
     */
    private function headerSubtitle(array $data): string {
        $codes = $data['agentCodes'] ?? [];
        $agent = is_array($codes) && $codes !== [] ? (string)$codes[0] : '';
        $presence = (string)($data['presence']['label'] ?? '');
        if ($agent !== '' && $presence !== '') {
            return $this->l10n->t('%1$s: %2$s', [$agent, $presence]);
        }
        return $presence;
    }
}
