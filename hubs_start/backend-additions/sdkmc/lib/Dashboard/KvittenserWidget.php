<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * NEW FILE for the sdkmc app. Target: lib/Dashboard/KvittenserWidget.php
 *
 * Mirrors the Hubs Start "Skickat — kvittenser" widget (delivery receipts for
 * outgoing secure messages) into the standard Nextcloud dashboard. Receipts come
 * from SummaryService, which exposes the REAL middleware state (replacing the
 * legacy 10-minute PENDING→REJECTED frontend heuristic). Items in the `problem`
 * state are surfaced first so failures are not buried.
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

class KvittenserWidget implements IAPIWidget, IIconWidget, IButtonWidget, IConditionalWidget, IReloadableWidget {

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
		return 'hubs_kvittenser';
	}

	/**
	 * @inheritDoc
	 */
	public function getTitle(): string {
		return $this->l10n->t('Skickat — kvittenser');
	}

	/**
	 * @inheritDoc
	 */
	public function getOrder(): int {
		return 11;
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
	 * @return list<WidgetItem>
	 */
	public function getItems(string $userId, ?string $since = null, int $limit = 7): array {
		return $this->getItemsV2($userId, $since, $limit)->getItems();
	}

	/**
	 * @inheritDoc
	 */
	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		$receipts = $this->summaryService->buildReceipts($userId, 'all', $limit);

		// Problem receipts first; otherwise newest update first.
		usort($receipts, static function (array $a, array $b): int {
			$pa = ($a['state'] ?? '') === 'problem' ? 0 : 1;
			$pb = ($b['state'] ?? '') === 'problem' ? 0 : 1;
			if ($pa !== $pb) {
				return $pa <=> $pb;
			}
			return ($b['updatedAt'] ?? '') <=> ($a['updatedAt'] ?? '');
		});

		$receipts = array_slice($receipts, 0, $limit);

		$widgetItems = [];
		foreach ($receipts as $receipt) {
			$widgetItems[] = $this->toWidgetItem($receipt);
		}

		return new WidgetItems(
			$widgetItems,
			empty($widgetItems) ? $this->l10n->t('Inga utgående meddelanden att kvittera') : '',
		);
	}

	/**
	 * Map a receipt to a dashboard WidgetItem.
	 *
	 * @param array $receipt { messageId, recipient, channel, state, updatedAt, deepLink }
	 */
	private function toWidgetItem(array $receipt): WidgetItem {
		$channel = $receipt['channel'] ?? [];
		$subtitleParts = [];
		if (!empty($channel['channelLabel'])) {
			$subtitleParts[] = $channel['channelLabel'];
		}
		$subtitleParts[] = $this->stateLabel((string)($receipt['state'] ?? ''));

		return new WidgetItem(
			(string)($receipt['recipient'] ?? ''),
			implode(' · ', $subtitleParts),
			$this->resolveDeepLink($receipt['deepLink'] ?? null),
			'',
			(string)($receipt['updatedAt'] ?? ''),
		);
	}

	/**
	 * Localise the 4-step receipt state (mirrors KvittensWidget.vue pill).
	 */
	private function stateLabel(string $state): string {
		switch ($state) {
			case 'skickat':
				return $this->l10n->t('Skickat');
			case 'levererat':
				return $this->l10n->t('Levererat');
			case 'last':
				return $this->l10n->t('Läst');
			case 'besvarat':
				return $this->l10n->t('Besvarat');
			case 'problem':
				return $this->l10n->t('Problem');
			default:
				return $state;
		}
	}

	/**
	 * Resolve a receipt deepLink descriptor ({ app, params }) to an absolute URL.
	 * Mirrors src/services/deepLinks.js#resolve.
	 */
	private function resolveDeepLink(?array $deepLink): string {
		$fallback = $this->url->linkToRouteAbsolute('hubs_start.Page.index');
		if ($deepLink === null || empty($deepLink['app'])) {
			return $fallback;
		}

		$params = $deepLink['params'] ?? [];
		switch ($deepLink['app']) {
			case 'thread':
				$base = $this->url->linkToRouteAbsolute('sdkmc.mail_notification.redirect', [
					'itslMailboxId' => $params['itslMailboxId'] ?? 0,
				]);
				if (!empty($params['mid'])) {
					$base .= '?mid=' . rawurlencode((string)$params['mid']);
				}
				return $base;
			case 'mailbox':
				return $this->url->getAbsoluteURL('/apps/mail/box/' . rawurlencode((string)($params['mailboxId'] ?? '')));
			case 'composer':
				$url = $this->url->getAbsoluteURL('/apps/mail/new') . '?type=' . rawurlencode((string)($params['messageType'] ?? ''));
				if (!empty($params['to'])) {
					$url .= '&to=' . rawurlencode((string)$params['to']);
				}
				return $url;
			default:
				return $fallback;
		}
	}
}
