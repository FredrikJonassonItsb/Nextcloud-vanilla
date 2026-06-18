<?php

declare(strict_types=1);

namespace OCA\GovPortal\Controller;

use OCA\GovPortal\AppInfo\Application;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * API controller for the GovPortal app
 */
class ApiController extends OCSController
{
    private IUserSession $userSession;
    private IConfig $config;

    public function __construct(
        string $appName,
        IRequest $request,
        IUserSession $userSession,
        IConfig $config
    ) {
        parent::__construct($appName, $request);
        $this->userSession = $userSession;
        $this->config = $config;
    }

    /**
     * Get the status of the portal
     *
     * @return DataResponse
     */
    #[NoAdminRequired]
    public function getStatus(): DataResponse
    {
        $user = $this->userSession->getUser();

        if ($user === null) {
            return new DataResponse([
                'status' => 'error',
                'message' => 'Not authenticated',
            ], Http::STATUS_UNAUTHORIZED);
        }

        return new DataResponse([
            'status' => 'ok',
            'version' => '1.0.0',
            'user' => [
                'id' => $user->getUID(),
                'displayName' => $user->getDisplayName(),
            ],
        ]);
    }

    /**
     * Get user settings for the portal
     *
     * @return DataResponse
     */
    #[NoAdminRequired]
    public function getSettings(): DataResponse
    {
        $user = $this->userSession->getUser();

        if ($user === null) {
            return new DataResponse([
                'status' => 'error',
                'message' => 'Not authenticated',
            ], Http::STATUS_UNAUTHORIZED);
        }

        $userId = $user->getUID();

        // Get user preferences
        $settings = [
            'theme' => $this->config->getUserValue($userId, Application::APP_ID, 'theme', 'light'),
            'language' => $this->config->getUserValue($userId, Application::APP_ID, 'language', 'sv'),
            'widgetOrder' => json_decode(
                $this->config->getUserValue(
                    $userId,
                    Application::APP_ID,
                    'widgetOrder',
                    '["secureMessages","bookMeeting","documents","chat"]'
                ),
                true
            ),
            'hiddenWidgets' => json_decode(
                $this->config->getUserValue($userId, Application::APP_ID, 'hiddenWidgets', '[]'),
                true
            ),
            'refreshInterval' => (int) $this->config->getUserValue(
                $userId,
                Application::APP_ID,
                'refreshInterval',
                '30'
            ),
        ];

        return new DataResponse($settings);
    }

    /**
     * Update user settings for the portal
     *
     * @param string|null $theme
     * @param string|null $language
     * @param array|null $widgetOrder
     * @param array|null $hiddenWidgets
     * @param int|null $refreshInterval
     * @return DataResponse
     */
    #[NoAdminRequired]
    public function updateSettings(
        ?string $theme = null,
        ?string $language = null,
        ?array $widgetOrder = null,
        ?array $hiddenWidgets = null,
        ?int $refreshInterval = null
    ): DataResponse {
        $user = $this->userSession->getUser();

        if ($user === null) {
            return new DataResponse([
                'status' => 'error',
                'message' => 'Not authenticated',
            ], Http::STATUS_UNAUTHORIZED);
        }

        $userId = $user->getUID();

        // Update settings
        if ($theme !== null && in_array($theme, ['light', 'dark', 'system'])) {
            $this->config->setUserValue($userId, Application::APP_ID, 'theme', $theme);
        }

        if ($language !== null && in_array($language, ['sv', 'en'])) {
            $this->config->setUserValue($userId, Application::APP_ID, 'language', $language);
        }

        if ($widgetOrder !== null) {
            $this->config->setUserValue(
                $userId,
                Application::APP_ID,
                'widgetOrder',
                json_encode($widgetOrder)
            );
        }

        if ($hiddenWidgets !== null) {
            $this->config->setUserValue(
                $userId,
                Application::APP_ID,
                'hiddenWidgets',
                json_encode($hiddenWidgets)
            );
        }

        if ($refreshInterval !== null && $refreshInterval >= 10 && $refreshInterval <= 300) {
            $this->config->setUserValue(
                $userId,
                Application::APP_ID,
                'refreshInterval',
                (string) $refreshInterval
            );
        }

        return new DataResponse(['status' => 'ok']);
    }
}
