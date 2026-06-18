<?php

declare(strict_types=1);

/**
 * GovPortal - Routes configuration
 *
 * Define all routes for the application
 */

return [
    'routes' => [
        // Main page route - serves the React SPA
        [
            'name' => 'page#index',
            'url' => '/',
            'verb' => 'GET',
        ],
        // Catch-all for React Router (handles /callback, etc.)
        [
            'name' => 'page#catchAll',
            'url' => '/{path}',
            'verb' => 'GET',
            'requirements' => [
                'path' => '.*',
            ],
        ],
    ],
    'ocs' => [
        // API routes for the portal (if needed)
        [
            'name' => 'api#getStatus',
            'url' => '/api/v1/status',
            'verb' => 'GET',
        ],
        [
            'name' => 'api#getSettings',
            'url' => '/api/v1/settings',
            'verb' => 'GET',
        ],
        [
            'name' => 'api#updateSettings',
            'url' => '/api/v1/settings',
            'verb' => 'PUT',
        ],
    ],
];
