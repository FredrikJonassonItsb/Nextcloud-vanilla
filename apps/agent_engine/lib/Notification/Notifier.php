<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AgentEngine\Notification;

use OCA\AgentEngine\AppInfo\Application;
use OCA\AgentEngine\Service\NotificationService;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Renders the engine's bell notifications. Swedish, human-first, and never a
 * protocol token in sight — those live on the engine board only.
 *
 * Params carry only coordination values (agent code, card id, hours). Links
 * land on the ORIGIN card when known (the human's own card is the remote
 * control), else on the Deck board overview.
 */
class Notifier implements INotifier {
    public function __construct(
        private IFactory $l10nFactory,
        private IURLGenerator $urlGenerator,
    ) {
    }

    public function getID(): string {
        return Application::APP_ID;
    }

    public function getName(): string {
        return 'Agent Engine';
    }

    public function prepare(INotification $notification, string $languageCode): INotification {
        if ($notification->getApp() !== Application::APP_ID) {
            throw new UnknownNotificationException();
        }
        $l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
        $p = $notification->getSubjectParameters();
        $agent = (string)($p['agentCode'] ?? 'agenten');

        switch ($notification->getSubject()) {
            case NotificationService::SUBJECT_REFUSED:
                $notification->setParsedSubject(
                    $l->t('Agenten tog INTE kortet — innehållet matchar PII/secret-mönster. Rensa kortet eller behåll det själv.'),
                );
                break;
            case NotificationService::SUBJECT_NOT_ENROLLED:
                $notification->setParsedSubject(
                    $l->t('Tavlan är inte enrollad för agenter — be Fredrik enrolla den, eller använd !queue i Talk.'),
                );
                break;
            case NotificationService::SUBJECT_PRESENCE_STALE:
                $notification->setParsedSubject(
                    $l->t('Obs: %1$s har inte kört på ett tag — kortet väntar i kö.', [$agent]),
                );
                break;
            case NotificationService::SUBJECT_RECALLED:
                $notification->setParsedSubject(
                    $l->t('Uppgiften är tillbakadragen från %1$s. Tilldela boten igen om du ångrar dig.', [$agent]),
                );
                break;
            case NotificationService::SUBJECT_QUESTION:
                $notification->setParsedSubject(
                    $l->t('%1$s har en fråga på ditt kort — svara i en kommentar där.', [$agent]),
                );
                break;
            case NotificationService::SUBJECT_REVIEW_READY:
                $notification->setParsedSubject(
                    $l->t('%1$s är klar — resultatet väntar på din granskning på ditt kort.', [$agent]),
                );
                break;
            case NotificationService::SUBJECT_FAILED:
                $notification->setParsedSubject(
                    $l->t('%1$s misslyckades med uppgiften — ägaren tittar på det.', [$agent]),
                );
                break;
            case NotificationService::SUBJECT_PRECLAIM_STALL:
                $hours = (string)($p['hours'] ?? '');
                $notification->setParsedSubject(
                    $hours !== ''
                        ? $l->t('Kortet ligger i kö hos %1$s men har inte plockats på %2$s h.', [$agent, $hours])
                        : $l->t('Kortet ligger i kö hos %1$s men har inte plockats ännu.', [$agent]),
                );
                break;
            default:
                throw new UnknownNotificationException();
        }

        $boardId = (string)($p['originBoard'] ?? '');
        $cardId = (string)($p['originCard'] ?? '');
        if ($boardId !== '' && $cardId !== '' && $boardId !== '0' && $cardId !== '0') {
            $notification->setLink(
                $this->urlGenerator->getAbsoluteURL('/apps/deck/board/' . $boardId . '/card/' . $cardId),
            );
        } else {
            $notification->setLink($this->urlGenerator->getAbsoluteURL('/apps/deck/'));
        }
        return $notification;
    }
}
