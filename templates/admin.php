<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

script('share_audit_dashboard', 'share_audit_dashboard-main');
style('share_audit_dashboard', 'admin');
?>

<div id="share-audit-dashboard" class="section" data-role="admin">
    <!-- The Vue 3 app is mounted here. data-role is redundant for the admin
         (src/main.js defaults to 'admin' when the attribute is absent) but
         kept explicit for symmetry with templates/viewer.php. -->
</div>
