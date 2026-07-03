<?php

declare(strict_types=1);

/**
 * Routes for the Share Audit Dashboard admin API.
 *
 * All endpoints are served from the app's own index.php route space and are
 * restricted to administrators inside the controller.
 */
return [
    'routes' => [
        // Dashboard counters / stats (totals per type, trend, top users).
        ['name' => 'shareApi#stats', 'url' => '/api/stats', 'verb' => 'GET'],
        // Paginated, filterable list of all shares on the instance.
        ['name' => 'shareApi#index', 'url' => '/api/shares', 'verb' => 'GET'],
        // Security alerts (links without password/expiration, oversharing, sensitive files).
        ['name' => 'shareApi#alerts', 'url' => '/api/alerts', 'verb' => 'GET'],
        // CSV export of the filtered share list.
        ['name' => 'shareApi#export', 'url' => '/api/export', 'verb' => 'GET'],
        // Configurable security-alert rules.
        ['name' => 'shareApi#getSettings', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'shareApi#saveSettings', 'url' => '/api/settings', 'verb' => 'POST'],
    ],
];
