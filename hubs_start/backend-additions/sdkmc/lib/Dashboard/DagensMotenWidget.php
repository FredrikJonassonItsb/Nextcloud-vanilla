<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * NEW FILE for the sdkmc app. Target: lib/Dashboard/DagensMotenWidget.php
 *
 * Mirrors the Hubs Start "Dagens säkra möten" widget into the standard Nextcloud
 * dashboard. Today's secure meetings are merged server-side (CalDAV + secure-room
 * state) and exposed via SummaryService; this widget is a thin projection that
 * surfaces the time, BankID verification level and a one-click join link.
 *
 * Interface set + method shapes deliberately mirror spreed-itsl's TalkWidget.
 */

namespace OCA\SdkMc\Dashboard;

use OCA\SdkMc\Service\MeetingService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
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

class DagensMotenWidget implements IAPIWidget, IIconWidget, IButtonWidget, IConditionalWidget, IReloadableWidget {

	public function __construct(
		protected IUserSession $userSession,
		protected IURLGenerator $url,
		protected IL10N $l10n,
		protected IAppManager $appManager,
		protected ITimeFactory $timeFactory,
		protected MeetingService $meetingService,
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function getId(): string {
		return 'hubs_moten';
	}

	/**
	 * @inheritDoc
	 */
	public function getTitle(): string {
		return $this->l10n->t('Dagens säkra möten');
	}

	/**
	 * @inheritDoc
	 */
	public function getOrder(): int {
		return 12;
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
		// Today's meetings require the secure-meeting (Säkert möte) backend; only
		// show the widget when sdkmc is usable for this user.
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
		$meetings = $this->meetingService->getTodaysMeetings($userId);

		// Soonest first.
		usort($meetings, static function (array $a, array $b): int {
			return ($a['start'] ?? '') <=> ($b['start'] ?? '');
		});

		$meetings = array_slice($meetings, 0, $limit);

		$widgetItems = [];
		foreach ($meetings as $meeting) {
			$widgetItems[] = $this->toWidgetItem($meeting);
		}

		return new WidgetItems(
			$widgetItems,
			empty($widgetItems) ? $this->l10n->t('Inga möten idag') : '',
		);
	}

	/**
	 * Map a meeting to a dashboard WidgetItem.
	 *
	 * @param array $meeting { token, title, start, end, participants,
	 *                          bankIdRequired, verificationBadge ('green'|'purple'|null),
	 *                          lobbyState, hasCall }
	 */
	private function toWidgetItem(array $meeting): WidgetItem {
		$subtitleParts = [];

		$time = $this->formatTime($meeting['start'] ?? null);
		if ($time !== '') {
			$subtitleParts[] = $time;
		}

		$badge = $this->verificationLabel($meeting['verificationBadge'] ?? null);
		if ($badge !== '') {
			$subtitleParts[] = $badge;
		}

		if (!empty($meeting['hasCall'])) {
			$subtitleParts[] = $this->l10n->t('Pågår');
		}

		return new WidgetItem(
			(string)($meeting['title'] ?? $this->l10n->t('Säkert möte')),
			implode(' · ', $subtitleParts),
			$this->joinUrl((string)($meeting['token'] ?? '')),
			'',
			(string)($meeting['start'] ?? ''),
		);
	}

	/**
	 * Label the BankID verification badge.
	 *   green  → BankID + personnummer (identity bound)
	 *   purple → enbart BankID (signature only)
	 */
	private function verificationLabel(?string $badge): string {
		switch ($badge) {
			case 'green':
				return $this->l10n->t('BankID + personnummer');
			case 'purple':
				return $this->l10n->t('Enbart BankID');
			default:
				return '';
		}
	}

	/**
	 * Render the meeting start time in the user's locale (HH:mm).
	 */
	private function formatTime(?string $start): string {
		if ($start === null || $start === '') {
			return '';
		}
		try {
			$dt = new \DateTimeImmutable($start);
		} catch (\Exception) {
			return '';
		}
		return (string)$this->l10n->l('time', $dt, ['width' => 'short']);
	}

	/**
	 * Join link for a secure meeting (mirrors deepLinks.callLink → /call/{token}).
	 */
	private function joinUrl(string $token): string {
		if ($token === '') {
			return $this->url->linkToRouteAbsolute('hubs_start.Page.index');
		}
		return $this->url->getAbsoluteURL('/call/' . rawurlencode($token));
	}
}
