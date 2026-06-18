<?php

declare(strict_types=1);

namespace OCA\GovPortal\Controller;

use OCA\GovPortal\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Util;

/**
 * Page controller for serving the React SPA
 */
class PageController extends Controller
{
    private IUserSession $userSession;

    public function __construct(
        string $appName,
        IRequest $request,
        IUserSession $userSession
    ) {
        parent::__construct($appName, $request);
        $this->userSession = $userSession;
    }

    /**
     * Main entry point - serves the React application
     *
     * @param string $path Optional path for React Router
     * @return TemplateResponse
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(string $path = ''): TemplateResponse
    {
        // Add styles (script is loaded as module in template)
        Util::addStyle(Application::APP_ID, 'style');
        Util::addStyle(Application::APP_ID, 'govportal');

        // Get current user info to pass to the frontend
        $user = $this->userSession->getUser();
        $userInfo = null;

        if ($user !== null) {
            $userInfo = [
                'id' => $user->getUID(),
                'displayName' => $user->getDisplayName(),
                'email' => $user->getEMailAddress(),
            ];
        }

        // Provide initial state to the frontend
        $initialState = [
            'user' => $userInfo,
            'requestToken' => \OC::$server->getCsrfTokenManager()->getToken()->getEncryptedValue(),
        ];

        // Use initial state service if available (NC 20+)
        $initialStateService = \OC::$server->get(\OCP\IInitialStateService::class);
        $initialStateService->provideInitialState(Application::APP_ID, 'config', $initialState);

        return new TemplateResponse(
            Application::APP_ID,
            'index',
            [
                'appId' => Application::APP_ID,
            ]
        );
    }

    /**
     * Catch-all route for React Router paths
     *
     * @param string $path The path requested
     * @return TemplateResponse
     */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function catchAll(string $path): TemplateResponse
    {
        return $this->index($path);
    }
}
