<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

script('share_audit_dashboard', 'share_audit_dashboard-main');
style('share_audit_dashboard', 'admin');
?>

<div id="share-audit-personal"
    class="section"
    data-enabled="<?php p($_['enabled'] ? '1' : '0'); ?>">
    <!-- The personal Vue 3 app is mounted here. -->
</div>
