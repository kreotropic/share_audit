<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Routes for the Share Audit Dashboard API and its standalone page.
 *
 * All endpoints are served from the app's own index.php route space. Most
 * are restricted to administrators inside the controller (AdminController::
 * requireAdmin()); the read-only ones (marked below) are open to admins and
 * auditors instead (AdminController::requireViewer()) — see AccessService.
 */
return [
    'routes' => [
        // Standalone page for a non-admin viewer (auditor/manager): the
        // admin's own dashboard lives in Settings → Administration instead,
        // which they cannot reach.
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        // Dashboard counters / stats (totals per type, trend, top users). Read-only.
        ['name' => 'shareApi#stats', 'url' => '/api/stats', 'verb' => 'GET'],
        // Paginated, filterable list of all shares on the instance. Read-only.
        ['name' => 'shareApi#index', 'url' => '/api/shares', 'verb' => 'GET'],
        // Security alerts (links without password/expiration, oversharing, sensitive files). Read-only.
        ['name' => 'shareApi#alerts', 'url' => '/api/alerts', 'verb' => 'GET'],
        // CSV export of the filtered share list. Read-only.
        ['name' => 'shareApi#export', 'url' => '/api/export', 'verb' => 'GET'],
        // Configurable security-alert rules, incl. the auditor group list. Admin-only.
        ['name' => 'shareApi#getSettings', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'shareApi#saveSettings', 'url' => '/api/settings', 'verb' => 'POST'],
        // Group search for the "Auditor groups" picker in Settings. Admin-only.
        ['name' => 'shareApi#groups', 'url' => '/api/settings/groups', 'verb' => 'GET'],
        // Acknowledge (accept as an exception) or undo one on a security alert.
        ['name' => 'ack#acknowledge', 'url' => '/api/alerts/{id}/ack', 'verb' => 'POST'],
        ['name' => 'ack#unacknowledge', 'url' => '/api/alerts/{id}/ack', 'verb' => 'DELETE'],
        ['name' => 'ack#bulkAcknowledge', 'url' => '/api/alerts/bulk-ack', 'verb' => 'POST'],
        // Remediation actions on shares (individual + bulk).
        ['name' => 'shareAction#setPassword', 'url' => '/api/shares/{id}/password', 'verb' => 'POST'],
        ['name' => 'shareAction#setExpiration', 'url' => '/api/shares/{id}/expiration', 'verb' => 'POST'],
        ['name' => 'shareAction#revoke', 'url' => '/api/shares/{id}', 'verb' => 'DELETE'],
        ['name' => 'shareAction#bulk', 'url' => '/api/shares/bulk', 'verb' => 'POST'],
        // Orphan shares (owner disabled/deleted). Listing is read-only; revoke/transfer are admin-only.
        ['name' => 'orphanShare#index', 'url' => '/api/orphans', 'verb' => 'GET'],
        ['name' => 'orphanShare#revoke', 'url' => '/api/orphans/revoke', 'verb' => 'POST'],
        ['name' => 'orphanShare#transfer', 'url' => '/api/orphans/transfer', 'verb' => 'POST'],
        ['name' => 'orphanShare#transferTargets', 'url' => '/api/orphans/transfer-targets', 'verb' => 'GET'],
        // Move the files of a disabled owner too (background job). Queuing is admin-only; the status list is read-only.
        ['name' => 'orphanShare#moveFiles', 'url' => '/api/orphans/move-files', 'verb' => 'POST'],
        ['name' => 'orphanShare#fileMoves', 'url' => '/api/orphans/file-moves', 'verb' => 'GET'],
        // Exposure map (internal / external / public + score). Read-only.
        ['name' => 'exposure#overview', 'url' => '/api/exposure', 'verb' => 'GET'],
        // Personal (per-user) view: audit and fix your own shares.
        ['name' => 'personal#summary', 'url' => '/api/my/summary', 'verb' => 'GET'],
        ['name' => 'personal#shares', 'url' => '/api/my/shares', 'verb' => 'GET'],
        ['name' => 'personal#alerts', 'url' => '/api/my/alerts', 'verb' => 'GET'],
        ['name' => 'personal#setPassword', 'url' => '/api/my/shares/{id}/password', 'verb' => 'POST'],
        ['name' => 'personal#setExpiration', 'url' => '/api/my/shares/{id}/expiration', 'verb' => 'POST'],
        ['name' => 'personal#revoke', 'url' => '/api/my/shares/{id}', 'verb' => 'DELETE'],
        // Reverse drill-down by recipient. search()/shares() are read-only; revokeAll() is admin-only.
        ['name' => 'recipient#search', 'url' => '/api/recipients/search', 'verb' => 'GET'],
        ['name' => 'recipient#shares', 'url' => '/api/recipients/shares', 'verb' => 'GET'],
        ['name' => 'recipient#revokeAll', 'url' => '/api/recipients/revoke-all', 'verb' => 'POST'],
        // Recycle bin of revoked shares (soft delete). Listing is read-only; restore/purge are admin-only.
        ['name' => 'softDelete#index', 'url' => '/api/deleted', 'verb' => 'GET'],
        ['name' => 'softDelete#restore', 'url' => '/api/deleted/{id}/restore', 'verb' => 'POST'],
        ['name' => 'softDelete#purge', 'url' => '/api/deleted/{id}', 'verb' => 'DELETE'],
        ['name' => 'softDelete#purgeMany', 'url' => '/api/deleted/purge', 'verb' => 'POST'],
    ],
];
