<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Unit;

use OCA\ShareAuditDashboard\Controller\AdminController;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every endpoint under an AdminController subclass must be consciously
 * classified as either admin-only (the default: SecurityMiddleware already
 * blocks a non-admin, and the method calls requireAdmin() as a second,
 * app-level check) or read-only-for-viewers (carries #[NoAdminRequired] —
 * because that attribute drops Nextcloud's own admin check, the method's
 * OWN call to requireViewer() becomes the only thing standing between an
 * auditor's request and every user's share data. See issue #16 / the plan
 * for its implementation.
 *
 * This mirrors ControllerLimitRangeTest's own reasoning: a new route with no
 * entry in VIEWER_ROUTES defaults to "admin-only, must call requireAdmin()"
 * and fails loudly if that is not what the code does, so a route can never
 * silently end up unguarded. Deliberately source-text based, like that test
 * — it is what actually catches "attribute present, guard call forgotten"
 * or the reverse, not just that *a* guard method exists somewhere in the class.
 */
class ControllerAccessTest extends TestCase {

    /**
     * 'controllerName#method' (as routes.php spells it) for every endpoint
     * that is open to admins AND auditors — i.e. carries #[NoAdminRequired]
     * and must call requireViewer(). Every other method on an
     * AdminController subclass is admin-only and must call requireAdmin().
     */
    private const VIEWER_ROUTES = [
        'shareApi#stats',
        'shareApi#index',
        'shareApi#alerts',
        'shareApi#export',
        'orphanShare#index',
        'orphanShare#fileMoves',
        'exposure#overview',
        'recipient#search',
        'recipient#shares',
        'softDelete#index',
    ];

    /**
     * @return iterable<string, array{0: string, 1: \ReflectionMethod}>
     */
    public static function adminControllerRoutes(): iterable {
        $routes = (require __DIR__ . '/../../appinfo/routes.php')['routes'];
        foreach ($routes as $route) {
            [$controllerName, $methodName] = explode('#', $route['name']);
            $class = 'OCA\\ShareAuditDashboard\\Controller\\' . ucfirst($controllerName) . 'Controller';
            if (!is_subclass_of($class, AdminController::class)) {
                // PageController and PersonalController don't extend
                // AdminController — they guard themselves differently (see
                // PageController::index() and PersonalController's own
                // per-request uid checks) and aren't this test's concern.
                continue;
            }
            $key = $controllerName . '#' . $methodName;
            yield $key => [$key, new \ReflectionMethod($class, $methodName)];
        }
    }

    public function testThereAreRoutesToCheck(): void {
        $this->assertNotEmpty(iterator_to_array(self::adminControllerRoutes()));
    }

    #[DataProvider('adminControllerRoutes')]
    public function testEveryAdminControllerRouteIsConsciouslyClassifiedAndGuarded(string $key, \ReflectionMethod $method): void {
        $isViewerRoute = in_array($key, self::VIEWER_ROUTES, true);
        $hasNoAdminRequired = $method->getAttributes(NoAdminRequired::class) !== [];

        $this->assertSame(
            $isViewerRoute,
            $hasNoAdminRequired,
            $isViewerRoute
                ? "$key is listed in VIEWER_ROUTES but its method carries no #[NoAdminRequired] — Nextcloud's own SecurityMiddleware would block every auditor before the controller runs."
                : "$key carries #[NoAdminRequired] but is not listed in VIEWER_ROUTES — that drops Nextcloud's own admin check, opening it to every logged-in account unless requireViewer() (or requireAdmin()) is what actually guards it. Classify it deliberately: add it to VIEWER_ROUTES if that is intended, or drop the attribute if not.",
        );

        $body = $this->methodSource($method);
        if ($isViewerRoute) {
            $this->assertStringContainsString(
                'requireViewer()',
                $body,
                "$key carries #[NoAdminRequired] but its body never calls requireViewer() — nothing would stop a regular logged-in account from reaching it.",
            );
        } else {
            $this->assertStringContainsString(
                'requireAdmin()',
                $body,
                "$key has no #[NoAdminRequired] (relying on Nextcloud's own admin check), but as belt-and-braces every method here is expected to also call requireAdmin() — see AdminController's docblock.",
            );
        }
    }

    private function methodSource(\ReflectionMethod $method): string {
        $file = $method->getFileName();
        $lines = file($file);
        $start = $method->getStartLine() - 1;
        $length = $method->getEndLine() - $start;
        return implode('', array_slice($lines, $start, $length));
    }
}
