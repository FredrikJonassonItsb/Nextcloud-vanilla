<?php

/*
 * SPDX-FileCopyrightText: ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Listener;

use OCP\EventDispatcher\Event;
use OCP\User\Events\PostLoginEvent;
use OCP\EventDispatcher\IEventListener;
use OC\Authentication\Token\IProvider;
use OCA\SdkMc\Service\UpgradeLoginService;
use OCP\Authentication\Exceptions\InvalidTokenException;
use OCP\Authentication\Token\IToken;
use OCP\IGroupManager;
use OCP\ISession;
use OCP\IUserManager;
use OCP\Session\Exceptions\SessionNotAvailableException;
use Psr\Log\LoggerInterface;
use Exception;

/**
 * @implements IEventListener<Event>
 */
class PostLoginListener implements IEventListener {
    public function __construct(
        private UpgradeLoginService $service,
        private IGroupManager $groupManager,
        private IUserManager $userManager,
        private ISession $session,
        private IProvider $tokenProvider,
        private LoggerInterface $logger,
        private string $userId,
    ) {
    }

    public function handle(Event $event): void {
        if (!($event instanceof PostLoginEvent)) {
            return;
        }

        if (PHP_SAPI === 'cli') {
            return;
        }

        if (!$this->groupManager->groupExists('hubs-kunskapsbank')) {
            $this->groupManager->createGroup('hubs-kunskapsbank');
            $group = $this->groupManager->get('hubs-kunskapsbank');
            if (is_null($group)) {
                throw new Exception('Newly created group doesnt exist. This cannot happen.');
            }
            $group->setDisplayName('Hubs Kunskapsbank');
        }
        if (!$this->groupManager->isInGroup($this->userId, 'hubs-kunskapsbank')) {
            $group = $this->groupManager->get('hubs-kunskapsbank');
            $user = $this->userManager->get($this->userId);
            if (is_null($group)) {
                throw new Exception('Newly created group doesnt exist. This cannot happen.');
            }
            if (is_null($user)) {
                throw new Exception('User that just logged in doesnt exist. This cannot happen.');
            }
            $group->addUser($user);
        }
        $this->service->setLoginStrengthDuringLogin();

        if ($this->service->requiresUpgrade()) {
            $this->service->upgradeDuringLogin();
        }

        $this->setSkipPasswordValidation();
    }

    private function setSkipPasswordValidation(): void {
        try {
            $token = $this->tokenProvider->getToken($this->session->getId());
        } catch (SessionNotAvailableException|InvalidTokenException $e) {
            $this->logger->warning('Could not set skip password validation scope for user {userId}: {message}', [
                'userId' => $this->userId,
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
            return;
        }

        $scope = $token->getScopeAsArray();
        if ($scope[IToken::SCOPE_SKIP_PASSWORD_VALIDATION] ?? false) {
            return;
        }

        $scope[IToken::SCOPE_SKIP_PASSWORD_VALIDATION] = true;
        $token->setScope($scope);
        $this->tokenProvider->updateToken($token);
    }
}
