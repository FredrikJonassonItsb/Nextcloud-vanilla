<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\AppInfo;

use OCP\User\Events\PostLoginEvent;
use OCA\Mail\Events\MessageSentEvent;
use OCA\SdkMc\Event\FetchEmailEvent;
use OCA\SdkMc\Event\FetchThreadEvent;
use OCA\SdkMc\Event\DeleteDraftEvent;
use OCA\SdkMc\Event\SaveOrUpdateDraftEvent;
use OCA\SdkMc\Event\ScheduleEmailSendEvent;
use OCA\SdkMc\Event\SendEmailEvent;
use OCA\SdkMc\Event\SerializeLocalMessageEvent;
use OCA\SdkMc\Event\SerializeMailMessageEvent;
use OCA\SdkMc\Event\DraftSentEvent;
use OCA\SdkMc\Listener\FetchEmailListener;
use OCA\SdkMc\Listener\FetchThreadListener;
use OCA\SdkMc\Listener\SaveOrUpdateDraftListener;
use OCA\SdkMc\Listener\ScheduleEmailSendListener;
use OCA\SdkMc\Listener\MessageSentListener;
use OCA\SdkMc\Listener\SendEmailListener;
use OCA\SdkMc\Listener\DeleteDraftListener;
use OCA\SdkMc\Listener\SerializeLocalMessageListener;
use OCA\SdkMc\Listener\SerializeMailMessageListener;
use OCA\SdkMc\Listener\PostLoginListener;
use OCA\SdkMc\Listener\LoadAdditionalScriptsListener;
use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\SdkMc\Middleware\InternalAPIMiddleware;
use OCA\SdkMc\Middleware\SignatureSyncMiddleware;
use OCA\SdkMc\Middleware\WopiTokenMiddleware;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\WorkflowEngine\Events\RegisterChecksEvent;
use OCP\WorkflowEngine\Events\RegisterOperationsEvent;
use OCA\SdkMc\Listener\RegisterChecksListener;
use OCA\SdkMc\Listener\RegisterOperationsListener;
use OCA\SdkMc\Event\GuestLogoutEvent;
use OCA\SdkMc\Event\PublishInitialStateEventForGuests;
use OCA\SdkMc\Listener\SessionCleanupListener;
use OCA\Talk\Events\BeforeTurnServersGetEvent;
use OCA\SdkMc\Listener\SmsNotifyListener;
use OCA\SdkMc\Listener\CalendarAssetListener;
use OCA\SdkMc\Listener\LoginStyleListener;
use OCA\SdkMc\Listener\MailTagPermissionListener;
use OCP\AppFramework\Http\Events\BeforeLoginTemplateRenderedEvent;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCA\SdkMc\Interface\ISmsService;
use OCA\SdkMc\Service\SmsService;
use OCA\Mail\Events\NewMessagesSynchronized;
use OCA\SdkMc\Listener\NewMessagesSynchronizedListener;
use OCA\Mail\Events\BeforeMessageDeletedEvent;
use OCA\SdkMc\Listener\BeforeMessageDeletedListener;
use OCP\Group\Events\UserAddedEvent;
use OCP\Group\Events\UserRemovedEvent;
use OCA\SdkMc\Listener\GroupMembershipListener;
use OCA\Mail\Events\DraftMessageCreatedEvent;
use OCA\SdkMc\Listener\DraftMessageCreatedListener;
use OCP\User\Events\OutOfOfficeScheduledEvent;
use OCP\User\Events\OutOfOfficeChangedEvent;
use OCP\User\Events\OutOfOfficeClearedEvent;
use OCP\User\Events\OutOfOfficeEndedEvent;
use OCA\SdkMc\Listener\OutOfOfficeListener;
use OCP\Group\Events\GroupDeletedEvent;
use OCP\User\Events\UserDeletedEvent;
use OCA\SdkMc\Listener\GroupLifecycleListener;
use OCA\SdkMc\Listener\UserLifecycleListener;
use OCA\SdkMc\Event\MessageImportantClassifiedEvent;
use OCA\SdkMc\Listener\MessageImportantClassifiedListener;

/**
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
class Application extends App implements IBootstrap {
    public function __construct() {
        parent::__construct('sdkmc');
    }

    public function register(IRegistrationContext $context): void {
        $context->registerMiddleware(InternalAPIMiddleware::class);

        $context->registerMiddleware(WopiTokenMiddleware::class, true);

        $context->registerMiddleware(SignatureSyncMiddleware::class, true);

        $context->registerServiceAlias(ISmsService::class, SmsService::class);

        $context->registerEventListener(
            DraftMessageCreatedEvent::class,
            DraftMessageCreatedListener::class
        );

        $context->registerEventListener(
            BeforeMessageDeletedEvent::class,
            BeforeMessageDeletedListener::class
        );

        $context->registerEventListener(
            MessageSentEvent::class,
            MessageSentListener::class
        );

        $context->registerEventListener(
            DraftSentEvent::class,
            MessageSentListener::class
        );

        $context->registerEventListener(
            ScheduleEmailSendEvent::class,
            ScheduleEmailSendListener::class
        );

        $context->registerEventListener(
            NewMessagesSynchronized::class,
            NewMessagesSynchronizedListener::class
        );

        $context->registerEventListener(
            RegisterChecksEvent::class,
            RegisterChecksListener::class
        );
        $context->registerEventListener(
            RegisterOperationsEvent::class,
            RegisterOperationsListener::class
        );

        $context->registerEventListener(
            LoadAdditionalScriptsEvent::class,
            LoadAdditionalScriptsListener::class
        );
        $context->registerEventListener(
            PostLoginEvent::class,
            PostLoginListener::class
        );
        $context->registerEventListener(
            SaveOrUpdateDraftEvent::class,
            SaveOrUpdateDraftListener::class
        );
        $context->registerEventListener(
            FetchEmailEvent::class,
            FetchEmailListener::class
        );
        $context->registerEventListener(
            DeleteDraftEvent::class,
            DeleteDraftListener::class
        );
        $context->registerEventListener(
            SerializeLocalMessageEvent::class,
            SerializeLocalMessageListener::class
        );
        $context->registerEventListener(
            SerializeMailMessageEvent::class,
            SerializeMailMessageListener::class
        );
        $context->registerEventListener(
            SendEmailEvent::class,
            SendEmailListener::class
        );
        $context->registerEventListener(
            FetchThreadEvent::class,
            FetchThreadListener::class
        );

        // Group membership events for mailbox consolidation
        $context->registerEventListener(
            UserAddedEvent::class,
            GroupMembershipListener::class
        );
        $context->registerEventListener(
            UserRemovedEvent::class,
            GroupMembershipListener::class
        );

        // Out-of-office events for temporary mailbox access
        $context->registerEventListener(
            OutOfOfficeScheduledEvent::class,
            OutOfOfficeListener::class
        );
        $context->registerEventListener(
            OutOfOfficeChangedEvent::class,
            OutOfOfficeListener::class
        );
        $context->registerEventListener(
            OutOfOfficeClearedEvent::class,
            OutOfOfficeListener::class
        );
        $context->registerEventListener(
            OutOfOfficeEndedEvent::class,
            OutOfOfficeListener::class
        );

        // Group and user lifecycle events for mailbox cleanup
        $context->registerEventListener(
            GroupDeletedEvent::class,
            GroupLifecycleListener::class
        );
        $context->registerEventListener(
            UserDeletedEvent::class,
            UserLifecycleListener::class
        );

        // public user bankId session clearing
        $context->registerEventListener(
            PublishInitialStateEventForGuests::class,
            SessionCleanupListener::class
        );
        $context->registerEventListener(
            BeforeTurnServersGetEvent::class,
            SessionCleanupListener::class
        );
        $context->registerEventListener(
            GuestLogoutEvent::class,
            SessionCleanupListener::class
        );

        $this->registerCalendarEventListeners($context);

        $context->registerEventListener(
            BeforeLoginTemplateRenderedEvent::class,
            LoginStyleListener::class
        );

        $context->registerEventListener(
            BeforeTemplateRenderedEvent::class,
            CalendarAssetListener::class
        );

        $context->registerEventListener(
            BeforeTemplateRenderedEvent::class,
            MailTagPermissionListener::class
        );

        // Important message classification - handles DB storage in sdkmc tables
        $context->registerEventListener(
            MessageImportantClassifiedEvent::class,
            MessageImportantClassifiedListener::class
        );
    }

    private function registerCalendarEventListeners(IRegistrationContext $context): void {
        // Register calendar event listeners only if DAV classes exist
        if (class_exists('OCA\\DAV\\Events\\CalendarObjectCreatedEvent')) {
            /** @phpstan-ignore-next-line */
            $context->registerEventListener(
                'OCA\\DAV\\Events\\CalendarObjectCreatedEvent',
                SmsNotifyListener::class
            );
        }

        if (class_exists('OCA\\DAV\\Events\\CalendarObjectUpdatedEvent')) {
            /** @phpstan-ignore-next-line */
            $context->registerEventListener(
                'OCA\\DAV\\Events\\CalendarObjectUpdatedEvent',
                SmsNotifyListener::class
            );
        }
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function boot(IBootContext $context): void {
    }
}
