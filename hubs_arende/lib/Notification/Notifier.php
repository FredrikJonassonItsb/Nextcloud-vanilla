<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\HubsArende\Notification;

use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Renderar motorns NC-notiser (klockan): tilldelning, medlemskap och
 * frist-varsel. Utan notiser måste handläggaren polla dashboarden med ögonen —
 * detta är varsel-lagret som gör "Kräver åtgärd nu" proaktivt.
 *
 * PII-invariant: subject-parametrarna bär ENDAST pseudonym referens (dnr eller
 * hubsCaseId) + koordinationsvärden (datum) — aldrig innehåll/personuppgifter
 * om ärendets parter. Länken landar på Hubs Start (arbetsytan), aldrig djupare.
 *
 * Brand-regel: aldrig 'Nextcloud'/'Talk'/'Circles' i strängarna.
 */
class Notifier implements INotifier {
    public const APP_ID = 'hubs_arende';

    public const SUBJECT_TILLDELAD = 'tilldelad';
    public const SUBJECT_MEDLEM = 'medlem';
    public const SUBJECT_FRIST = 'frist';

    public function __construct(
        private IFactory $l10nFactory,
        private IURLGenerator $urlGenerator,
    ) {
    }

    public function getID(): string {
        return self::APP_ID;
    }

    public function getName(): string {
        return 'Hubs ärenden';
    }

    public function prepare(INotification $notification, string $languageCode): INotification {
        if ($notification->getApp() !== self::APP_ID) {
            throw new UnknownNotificationException();
        }
        $l = $this->l10nFactory->get(self::APP_ID, $languageCode);
        $params = $notification->getSubjectParameters();
        $ref = (string)($params['ref'] ?? '');

        switch ($notification->getSubject()) {
            case self::SUBJECT_TILLDELAD:
                $notification->setParsedSubject(
                    $l->t('Du har tilldelats ärende %s', [$ref]),
                );
                break;
            case self::SUBJECT_MEDLEM:
                $notification->setParsedSubject(
                    $l->t('Du har lagts till i ärende %s', [$ref]),
                );
                break;
            case self::SUBJECT_FRIST:
                $datum = (string)($params['datum'] ?? '');
                $notification->setParsedSubject(
                    $datum !== ''
                        ? $l->t('Fristen för ärende %1$s går ut %2$s', [$ref, $datum])
                        : $l->t('Fristen för ärende %s har passerats', [$ref]),
                );
                break;
            default:
                throw new UnknownNotificationException();
        }

        // Landa alltid på arbetsytan (Min dag) — kortet är arbetsplatsen.
        // Cross-app-guard: linkToRouteAbsolute KASTAR om hubs_start är avstängd
        // (PUNCHLIST-gotchan) — degradera till instansroten hellre än en krasch.
        try {
            $notification->setLink($this->urlGenerator->linkToRouteAbsolute('hubs_start.page.index'));
        } catch (\Throwable $e) {
            $notification->setLink($this->urlGenerator->getAbsoluteURL('/'));
        }
        return $notification;
    }
}
