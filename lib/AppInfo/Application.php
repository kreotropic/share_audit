<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\AppInfo;

use OCA\ShareAuditDashboard\Dashboard\MyAlertsWidget;
use OCA\ShareAuditDashboard\Listener\SoftDeleteListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Share\Events\BeforeShareDeletedEvent;

/**
 * Bootstrap for the Share Audit Dashboard app.
 *
 * The mapper, services and controllers rely on constructor autowiring
 * (all their dependencies are core Nextcloud interfaces), so no explicit
 * service registration is required here. The admin script is loaded from
 * templates/admin.php, only on the settings page.
 */
class Application extends App implements IBootstrap {
    public const APP_ID = 'share_audit_dashboard';

    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }

    public function register(IRegistrationContext $context): void {
        $context->registerDashboardWidget(MyAlertsWidget::class);
        $context->registerEventListener(BeforeShareDeletedEvent::class, SoftDeleteListener::class);
    }

    public function boot(IBootContext $context): void {
    }
}
