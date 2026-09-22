<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

script('share_audit_dashboard', 'share_audit_dashboard-main');
style('share_audit_dashboard', 'admin');
?>

<div id="share-audit-dashboard" data-role="<?php p($_['role']); ?>">
    <!-- The Vue 3 app is mounted here — same bundle as templates/admin.php.
         data-role tells src/main.js which capabilities to expose (see
         AccessScope): a viewer never gets the write actions or Settings tab.
         Nextcloud's own layout.user.php already wraps this in
         <div id="content" class="app-share_audit_dashboard"> (see
         core/templates/layout.user.php), so no #content wrapper is added here. -->
</div>
