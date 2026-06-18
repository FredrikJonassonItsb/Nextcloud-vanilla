<?php

declare(strict_types=1);

namespace OCA\GovPortal\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Application class for GovPortal
 *
 * This class bootstraps the application and registers services
 */
class Application extends App implements IBootstrap
{
    public const APP_ID = 'govportal';

    public function __construct(array $urlParams = [])
    {
        parent::__construct(self::APP_ID, $urlParams);
    }

    /**
     * Register services and event listeners
     */
    public function register(IRegistrationContext $context): void
    {
        // Register services here if needed
    }

    /**
     * Boot the application
     */
    public function boot(IBootContext $context): void
    {
        // Boot logic here if needed
    }
}
