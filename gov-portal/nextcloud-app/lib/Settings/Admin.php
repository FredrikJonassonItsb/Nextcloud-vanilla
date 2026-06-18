<?php

declare(strict_types=1);

namespace OCA\GovPortal\Settings;

use OCA\GovPortal\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;

/**
 * Admin settings page for GovPortal
 */
class Admin implements ISettings
{
    private IConfig $config;

    public function __construct(IConfig $config)
    {
        $this->config = $config;
    }

    /**
     * Get the admin settings form
     *
     * @return TemplateResponse
     */
    public function getForm(): TemplateResponse
    {
        $parameters = [
            'setAsDefault' => $this->config->getAppValue(
                Application::APP_ID,
                'setAsDefault',
                'false'
            ) === 'true',
            'allowedGroups' => json_decode(
                $this->config->getAppValue(Application::APP_ID, 'allowedGroups', '[]'),
                true
            ),
        ];

        return new TemplateResponse(Application::APP_ID, 'admin', $parameters);
    }

    /**
     * Get the section ID for the settings page
     *
     * @return string
     */
    public function getSection(): string
    {
        return 'additional';
    }

    /**
     * Get the priority of the settings page
     *
     * @return int
     */
    public function getPriority(): int
    {
        return 50;
    }
}
