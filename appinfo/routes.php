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
        // Remediation actions on shares (individual + bulk).
        ['name' => 'shareAction#setPassword', 'url' => '/api/shares/{id}/password', 'verb' => 'POST'],
        ['name' => 'shareAction#setExpiration', 'url' => '/api/shares/{id}/expiration', 'verb' => 'POST'],
        ['name' => 'shareAction#revoke', 'url' => '/api/shares/{id}', 'verb' => 'DELETE'],
        ['name' => 'shareAction#bulk', 'url' => '/api/shares/bulk', 'verb' => 'POST'],
        // Orphan shares (owner disabled/deleted).
        ['name' => 'orphanShare#index', 'url' => '/api/orphans', 'verb' => 'GET'],
        ['name' => 'orphanShare#revoke', 'url' => '/api/orphans/revoke', 'verb' => 'POST'],
        // Exposure map (internal / external / public + score).
        ['name' => 'exposure#overview', 'url' => '/api/exposure', 'verb' => 'GET'],
        // Personal (per-user) view: audit and fix your own shares.
        ['name' => 'personal#summary', 'url' => '/api/my/summary', 'verb' => 'GET'],
        ['name' => 'personal#shares', 'url' => '/api/my/shares', 'verb' => 'GET'],
        ['name' => 'personal#alerts', 'url' => '/api/my/alerts', 'verb' => 'GET'],
        ['name' => 'personal#setPassword', 'url' => '/api/my/shares/{id}/password', 'verb' => 'POST'],
        ['name' => 'personal#setExpiration', 'url' => '/api/my/shares/{id}/expiration', 'verb' => 'POST'],
        ['name' => 'personal#revoke', 'url' => '/api/my/shares/{id}', 'verb' => 'DELETE'],
        // Reverse drill-down by recipient.
        ['name' => 'recipient#search', 'url' => '/api/recipients/search', 'verb' => 'GET'],
        ['name' => 'recipient#shares', 'url' => '/api/recipients/shares', 'verb' => 'GET'],
        ['name' => 'recipient#revokeAll', 'url' => '/api/recipients/revoke-all', 'verb' => 'POST'],
        // Recycle bin of revoked shares (soft delete).
        ['name' => 'softDelete#index', 'url' => '/api/deleted', 'verb' => 'GET'],
        ['name' => 'softDelete#restore', 'url' => '/api/deleted/{id}/restore', 'verb' => 'POST'],
        ['name' => 'softDelete#purge', 'url' => '/api/deleted/{id}', 'verb' => 'DELETE'],
        ['name' => 'softDelete#purgeMany', 'url' => '/api/deleted/purge', 'verb' => 'POST'],
    ],
];
