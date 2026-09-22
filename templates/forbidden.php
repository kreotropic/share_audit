<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

style('share_audit_dashboard', 'admin');
?>

<div id="share-audit-forbidden" class="emptycontent">
    <h2><?php p($l->t('You don\'t have access to the Share Audit Dashboard')); ?></h2>
    <p><?php p($l->t('Ask an administrator to add you to one of its auditor groups.')); ?></p>
</div>
