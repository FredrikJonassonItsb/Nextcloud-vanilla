<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * NEW FILE for the sdkmc app. Target: lib/Dashboard/AttHanteraWidget.php
 *
 * Mirrors the Hubs Start "Att hantera" triage queue into the standard Nextcloud
 * dashboard (and the mobile clients that read the dashboard API), so the same
 * ownerless / action-required cases are visible without opening Hubs Start. The
 * widget is a thin projection over SummaryService — it does NOT re-derive
 * channels or sections; everything is already resolved server-side.
 *
 * Interface set + method shapes deliberately mirror spreed-itsl's TalkWidget.
 */

namespace OCA\SdkMc\Dashboard;

use OCA\SdkMc\Service\SummaryService;
use OCP\App\IAppManager;
use OCP\Dashboard\IAPIWidget;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IConditionalWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IReloadableWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;

class AttHanteraWidget implements IAPIWidget, IIconWidget, IButtonWidget, IConditionalWidget, IReloadableWidget {

	public function __construct(
		protected IUserSession $userSession,
		protected IURLGenerator $url,
		protected IL10N $l10n,
		protected IAppManager $appManager,
		protected SummaryService $summaryService,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function getId(): string {
		return 'hubs_att_hantera';
	}

	/**
	 * @inheritDoc
	 */
	public function getTitle(): string {
		return $this->l10n->t('Att hantera');
	}

	/**
	 * @inheritDoc
	 */
	public function getOrder(): int {
		return 10;
	}

	/**
	 * @inheritDoc
	 */
	public function getIconClass(): string {
		return 'icon-sdkmc';
	}

	/**
	 * @inheritDoc
	 */
	public function getIconUrl(): string {
		// sdkmc ships no dedicated dashboard glyph; fall back to the app icon the
		// theming engine already resolves for the app.
		return $this->url->getAbsoluteURL($this->url->imagePath('sdkmc', 'app.svg'));
	}

	/**
	 * @inheritDoc
	 */
	public function getUrl(): ?string {
		return $this->url->linkToRouteAbsolute('hubs_start.Page.index');
	}

	/**
	 * @inheritDoc
	 */
	public function isEnabled(): bool {
		$user = $this->userSession->getUser();
		if (!($user instanceof IUser)) {
			return false;
		}
		return $this->appManager->isEnabledForUser('sdkmc', $user);
	}

	/**
	 * @inheritDoc
	 */
	public function getReloadInterval(): int {
		return 30;
	}

	/**
	 * @inheritDoc
	 * @return list<WidgetButton>
	 */
	public function getWidgetButtons(string $userId): array {
		return [
			new WidgetButton(
				WidgetButton::TYPE_MORE,
				$this->url->linkToRouteAbsolute('hubs_start.Page.index'),
				$this->l10n->t('Öppna Hubs Start'),
			),
		];
	}

	/**
	 * @inheritDoc
	 */
	public function load(): void {
	}

	/**
	 * Legacy (V1) items accessor — kept for API parity with the dashboard
	 * contract; delegates to the V2 implementation.
	 *
	 * @return list<WidgetItem>
	 */
	public function getItems(string $userId, ?string $since = null, int $limit = 7): array {
		return $this->getItemsV2($userId, $since, $limit)->getItems();
	}

	/**
	 * @inheritDoc
	 */
	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		$summary = $this->summaryService->getSummary($userId, $since);
		$items = $summary['items'] ?? [];

		// Surface the cases that genuinely demand attention on the dashboard:
		// action-required first, then ownerless cases. Other sections live in the
		// full Hubs Start view.
		$priority = ['kraver_atgard' => 0, 'otilldelat' => 1];
		$items = array_values(array_filter($items, static function (array $item) use ($priority): bool {
			return isset($priority[$item['section'] ?? '']);
		}));

		usort($items, static function (array $a, array $b) use ($priority): int {
			$pa = $priority[$a['section'] ?? ''] ?? 99;
			$pb = $priority[$b['section'] ?? ''] ?? 99;
			if ($pa !== $pb) {
				return $pa <=> $pb;
			}
			// Newest first within the same section.
			return ($b['since'] ?? '') <=> ($a['since'] ?? '');
		});

		$items = array_slice($items, 0, $limit);

		$widgetItems = [];
		foreach ($items as $item) {
			$widgetItems[] = $this->toWidgetItem($item);
		}

		return new WidgetItems(
			$widgetItems,
			// Whole-queue empty message (mirrors AttHanteraQueue.vue).
			empty($widgetItems) ? $this->l10n->t('Allt hanterat — inga ägarlösa ärenden') : '',
		);
	}

	/**
	 * Map a server-resolved QueueItem to a dashboard WidgetItem.
	 *
	 * @param array $item QueueItem shape from SummaryService (see api.js typedef)
	 */
	private function toWidgetItem(array $item): WidgetItem {
		$channel = $item['channel'] ?? [];
		$subtitleParts = [];
		if (!empty($channel['channelLabel'])) {
			$subtitleParts[] = $channel['channelLabel'];
		}
		if (!empty($item['mailbox'])) {
			$subtitleParts[] = $item['mailbox'];
		}
		if (!empty($item['dnr'])) {
			$subtitleParts[] = $item['dnr'];
		}

		return new WidgetItem(
			(string)($item['title'] ?? ''),
			implode(' · ', $subtitleParts),
			$this->resolveDeepLink($item['deepLink'] ?? null),
			'',
			(string)($item['since'] ?? ''),
		);
	}

	/**
	 * Resolve a QueueItem.deepLink descriptor ({ app, params }) to an absolute URL.
	 * Mirrors src/services/deepLinks.js#resolve so the dashboard and SPA land in
	 * the same place.
	 */
	private function resolveDeepLink(?array $deepLink): string {
		$fallback = $this->url->linkToRouteAbsolute('hubs_start.Page.index');
		if ($deepLink === null || empty($deepLink['app'])) {
			return $fallback;
		}

		$params = $deepLink['params'] ?? [];
		switch ($deepLink['app']) {
			case 'thread':
				// sdkmc's verified redirect: /apps/sdkmc/mailbox-link/{itslMailboxId}?mid=...
				$base = $this->url->linkToRouteAbsolute('sdkmc.mail_notification.redirect', [
					'itslMailboxId' => $params['itslMailboxId'] ?? 0,
				]);
				if (!empty($params['mid'])) {
					$base .= '?mid=' . rawurlencode((string)$params['mid']);
				}
				return $base;
			case 'composer':
				$url = $this->url->getAbsoluteURL($this->url->linkToRoute('mail.page.index')) . 'new?type=' . rawurlencode((string)($params['messageType'] ?? ''));
				if (!empty($params['to'])) {
					$url .= '&to=' . rawurlencode((string)$params['to']);
				}
				return $url;
			case 'mailbox':
				return $this->url->getAbsoluteURL('/apps/mail/box/' . rawurlencode((string)($params['mailboxId'] ?? '')));
			case 'call':
				return $this->url->getAbsoluteURL('/call/' . rawurlencode((string)($params['token'] ?? '')));
			default:
				return $fallback;
		}
	}
}
