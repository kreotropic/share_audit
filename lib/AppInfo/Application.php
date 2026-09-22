<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\AppInfo;

use OCA\ShareAuditDashboard\Dashboard\MyAlertsWidget;
use OCA\ShareAuditDashboard\Listener\SoftDeleteListener;
use OCA\ShareAuditDashboard\Service\AccessScope;
use OCA\ShareAuditDashboard\Service\AccessService;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\INavigationManager;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
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
        $context->injectFn(\Closure::fromCallable([$this, 'registerNavigation']));
    }

    /**
     * A top-level app icon for a read-only viewer (auditor, and later a
     * manager — see AccessService): PageController::index() is the only way
     * for them to reach this app, since Settings → Administration is closed
     * to them. Not added for an admin (already has it there) or anyone
     * without a scope at all (nothing to show).
     */
    private function registerNavigation(
        INavigationManager $navigationManager,
        AccessService $access,
        IURLGenerator $urlGenerator,
        IFactory $l10nFactory,
    ): void {
        $scope = $access->getScope();
        if ($scope === null || $scope->role === AccessScope::ROLE_ADMIN) {
            return;
        }
        $navigationManager->add([
            'id' => self::APP_ID,
            'order' => 80,
            'href' => $urlGenerator->linkToRoute(self::APP_ID . '.page.index'),
            'icon' => $urlGenerator->imagePath(self::APP_ID, 'app.svg'),
            'name' => $l10nFactory->get(self::APP_ID)->t('Share Audit Dashboard'),
        ]);
    }
}
