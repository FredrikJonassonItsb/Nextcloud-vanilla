<?php

declare(strict_types=1);

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Listener;

use OC\Settings\AuthorizedGroupMapper;
use OCA\SdkMc\Settings\SdkMcVueTagSettingsAdmin;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Provides canManageTags initial state when the Mail app loads.
 *
 * @implements IEventListener<Event>
 */
class MailTagPermissionListener implements IEventListener {
    public function __construct(
        private IRequest $request,
        private IInitialState $initialState,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private AuthorizedGroupMapper $groupAuthorizationMapper,
    ) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof BeforeTemplateRenderedEvent) {
            return;
        }

        $uri = $this->request->getPathInfo();
        if ($uri === false || !str_starts_with($uri, '/apps/mail')) {
            return;
        }

        // Check if user can manage tags (create/edit/delete)
        $user = $this->userSession->getUser();
        $canManageTags = false;
        if ($user !== null) {
            $userId = $user->getUID();
            $canManageTags = $this->groupManager->isAdmin($userId);
            if (!$canManageTags) {
                $authorizedClasses = $this->groupAuthorizationMapper->findAllClassesForUser($user);
                $canManageTags = in_array(SdkMcVueTagSettingsAdmin::class, $authorizedClasses, true);
            }
        }
        $this->initialState->provideInitialState('canManageTags', $canManageTags);
    }
}
