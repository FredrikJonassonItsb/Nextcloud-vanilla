<?php

/*
 * SPDX-FileCopyrightText: 2025 ITSL <info@itsl.se>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\SdkMc\Service;

use OCP\AppFramework\Services\IAppConfig;
use OCP\IURLGenerator;
use OCP\ISession;
use OCP\IUserSession;
use Exception;
use OCP\AppFramework\Http\Attribute\UseSession;

class UpgradeLoginService {
    public function __construct(
        private IAppConfig $appConfig,
        private IURLGenerator $urlGenerator,
        private ISession $session,
        private IUserSession $userSession,
        private ItslAccountService $service,
    ) {
    }

    public function getLoginProvider(): string {
        return $this->appConfig->getAppValueString('loginProvider', 'sociallogin');
    }

    public function getLoa3AuthProvider(): string {
        return $this->appConfig->getAppValueString('loa3Auth', 'custom_oidc/bankid-test');
    }

    /**
     * @SuppressWarnings("PHPMD.Superglobals")
     */
    public function getRequestURL(): string {
        if (!array_key_exists('REQUEST_URI', $_SERVER)) {
            return '/';
        }
        if (!is_string($_SERVER['REQUEST_URI'])) {
            return '/';
        }
        return $_SERVER['REQUEST_URI'];
    }

    public function setLoginStrengthDuringLogin(): void {
        $loginProvider = $this->getLoginProvider();
        $loa3AuthProvider = $this->getLoa3AuthProvider();

        // todo: some auth providers include a parameter in the response
        // that signals if were LOA-2 or LOA-3
        $isLoa3Auth = str_starts_with($this->getRequestURL(), "/apps/$loginProvider/$loa3AuthProvider");

        $this->setLoginStrength($isLoa3Auth ? 'LOA-3' : 'LOA-2');
    }

    #[UseSession]
    private function setLoginStrength(string $loginStrenth): void {
        $this->session->set('LoginStrength', $loginStrenth);
    }

    public function getLoginStrength(): string {
        $loginStrenth = $this->session->get('LoginStrength');
        if (!is_string($loginStrenth)) {
            $loginStrenth = 'LOA-2';
        }
        return $loginStrenth;
    }

    public function getLoginUrl(): string {
        return $this->urlGenerator->getAbsoluteURL('/apps/' . $this->getLoginProvider() . '/' . $this->getLoa3AuthProvider());
    }

    public function requiresUpgrade(): bool {
        if (!$this->appConfig->getAppValueBool('loa3Enabled')) {
            return false;
        }
        if ($this->getLoginStrength() === 'LOA-3') {
            return false;
        }

        // todo: make this optional and make sure that the mail app doesnt show any
        // mails from sdk boxes if youre not LOA-3
        $boxes = $this->service->getAllAccounts();
        if (!array_key_exists('sdk', $boxes)) {
            return false;
        }
        $sdk = $boxes['sdk'];
        foreach ($sdk as $box) {
            if (!is_array($box) || !array_key_exists('users', $box) || !is_array($box['users'])) {
                continue;
            }

            $user = $this->userSession->getUser();
            if (is_null($user)) {
                throw new Exception('No logged in user');
            }
            if (in_array($user->getUID(), $box['users'], true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @SuppressWarnings("PHPMD.ExitExpression")
     */
    public function upgradeLogin(?string $redirectUrl = null): void {
        $this->userSession->logout();

        $separator = str_contains($this->getLoginUrl(), '?') ? '&' : '?';
        $url = $this->getLoginUrl() . $separator . http_build_query(['login_redirect_url' => $redirectUrl ?? $this->getRequestURL()]);

        header('Location: ' . $url);
        die();
    }

    /**
     * @SuppressWarnings("PHPMD.ExitExpression")
     */
    public function upgradeDuringLogin(): void {
        $this->userSession->logout();

        header('Location: /apps/sdkmc/redirectToLoa3');
        die();
    }
}
