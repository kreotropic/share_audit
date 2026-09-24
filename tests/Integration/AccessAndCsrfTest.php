<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Integration;

use OCA\ShareAuditDashboard\Tests\Unit\ControllerAccessTest;
use OCP\Server;
use OCP\Share\IManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Who may call what, and with which proof — through the real web server and the
 * real App Framework, so the SecurityMiddleware's own login, admin and CSRF
 * checks are in play as well as the controllers' guards. The unit tests call
 * controller methods directly and cannot see either.
 *
 * Every route in routes.php is exercised as an anonymous visitor, a regular
 * account, an auditor and an admin. What it should answer is decided by the
 * classification ControllerAccessTest already keeps honest against the code:
 * VIEWER_ROUTES are open to auditors, the personal routes to any account, and
 * every other route is for admins alone.
 */
final class AccessAndCsrfTest extends TestCase {

    /** An id that does not exist: anything sent to it changes nothing. */
    private const NOBODY = '999999999';

    /** @var array<string, HttpSession> */
    private static array $sessions = [];

    public static function setUpBeforeClass(): void {
        Fixtures::boot();
        foreach ([null, Fixtures::USER, Fixtures::AUDITOR, Fixtures::ADMIN] as $who) {
            self::$sessions[(string)$who] = Fixtures::session($who);
        }
    }

    protected function setUp(): void {
        Fixtures::purgeOwnerShares();
    }

    public static function tearDownAfterClass(): void {
        Fixtures::purgeOwnerShares();
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: string}> name, verb, url, kind (viewer | personal | admin)
     */
    public static function routes(): iterable {
        $viewerRoutes = (new \ReflectionClassConstant(ControllerAccessTest::class, 'VIEWER_ROUTES'))->getValue();
        foreach ((require __DIR__ . '/../../appinfo/routes.php')['routes'] as $route) {
            [$controller] = explode('#', $route['name']);
            if ($route['name'] === 'page#index') {
                continue;   // a page, not an API route: testThePageIsForAuditorsAndSendsAdminsToSettings()
            }
            $kind = match (true) {
                $controller === 'personal' => 'personal',
                in_array($route['name'], $viewerRoutes, true) => 'viewer',
                default => 'admin',
            };
            yield $route['verb'] . ' ' . $route['url'] => [
                $route['name'], $route['verb'], str_replace('{id}', self::NOBODY, $route['url']), $kind,
            ];
        }
    }

    /**
     * A body that changes nothing wherever it lands.
     *
     * @return array<string, mixed>
     */
    private static function harmlessBody(string $name): array {
        return match ($name) {
            'shareApi#saveSettings' => ['retentionDays' => 30],
            'shareAction#setExpiration', 'personal#setExpiration' => ['days' => 7],
            default => [],
        };
    }

    /**
     * @return array{status: int, body: string, json: mixed, headers: array<string, string>}
     */
    private function call(?string $who, string $verb, string $url, string $name, bool $withToken = true): array {
        $session = self::$sessions[(string)$who];
        return match ($verb) {
            'GET' => $session->get($url, [], $withToken),
            'DELETE' => $session->delete($url, $withToken),
            default => $session->post($url, self::harmlessBody($name), $withToken),
        };
    }

    // -------------------------------------------------------------------
    // Login and role
    // -------------------------------------------------------------------

    #[DataProvider('routes')]
    public function testAnAnonymousVisitorGetsNothingFromAnyApiRoute(string $name, string $verb, string $url, string $kind): void {

        $response = $this->call(null, $verb, $url, $name);

        $this->assertSame(401, $response['status'], "$verb $url as an anonymous visitor");
    }

    #[DataProvider('routes')]
    public function testARegularAccountIsRefusedEverythingButItsOwnPersonalRoutes(string $name, string $verb, string $url, string $kind): void {

        $response = $this->call(Fixtures::USER, $verb, $url, $name);

        if ($kind === 'personal') {
            $this->assertNotContains($response['status'], [401, 403, 412], "$verb $url is the account's own");
        } else {
            $this->assertSame(403, $response['status'], "$verb $url as a regular account ($kind route)");
        }
    }

    #[DataProvider('routes')]
    public function testAnAuditorReadsWhatIsForViewersAndNothingElse(string $name, string $verb, string $url, string $kind): void {

        $response = $this->call(Fixtures::AUDITOR, $verb, $url, $name);

        match ($kind) {
            'viewer' => $this->assertSame(200, $response['status'], "$verb $url as an auditor"),
            'personal' => $this->assertNotContains($response['status'], [401, 403, 412], "$verb $url is the account's own"),
            default => $this->assertSame(403, $response['status'], "$verb $url as an auditor (admin route)"),
        };
    }

    #[DataProvider('routes')]
    public function testAnAdminGetsPastEveryGuard(string $name, string $verb, string $url, string $kind): void {

        $response = $this->call(Fixtures::ADMIN, $verb, $url, $name);

        $this->assertNotContains($response['status'], [401, 403, 412], "$verb $url as an admin");
    }

    public function testThePageIsForAuditorsAndSendsAdminsToSettings(): void {
        $page = fn (?string $who) => self::$sessions[(string)$who]->get('/', [], false);

        $this->assertSame(401, $page(null)['status'], 'anonymous');
        $this->assertSame(403, $page(Fixtures::USER)['status'], 'a regular account has no access to grant a page');
        $this->assertSame(200, $page(Fixtures::AUDITOR)['status'], 'an auditor');
        $this->assertContains($page(Fixtures::ADMIN)['status'], [302, 303], 'an admin is sent on to Settings');
        $this->assertStringContainsString('/settings/admin/share_audit_dashboard', $page(Fixtures::ADMIN)['headers']['location'] ?? '');
    }

    // -------------------------------------------------------------------
    // CSRF: a valid, logged-in session is not enough
    // -------------------------------------------------------------------

    /**
     * The session is real and the account is allowed; what is missing is the
     * token only the app's own page knows. Nextcloud must refuse before any
     * controller runs — for reads as well as writes.
     */
    #[DataProvider('routes')]
    public function testAnAdminSessionWithoutTheCsrfTokenIsRefused(string $name, string $verb, string $url, string $kind): void {

        $response = $this->call(Fixtures::ADMIN, $verb, $url, $name, false);

        $this->assertSame(412, $response['status'], "$verb $url without a CSRF token");
    }

    /**
     * The refusal is not just a status: nothing happened. A link revoked from
     * a forged request would still be gone, so check it is still there — and
     * that the very same request WITH the token does revoke it.
     */
    public function testARevokeWithoutTheCsrfTokenDoesNotRevoke(): void {
        $share = Fixtures::link(Fixtures::ownerFile('csrf-revoke.txt'));
        $id = (int)$share->getId();

        $refused = self::$sessions[Fixtures::ADMIN]->delete('/api/shares/' . $id, false);
        $this->assertSame(412, $refused['status']);
        $this->assertNotNull(Fixtures::shareRow($id), 'the link is untouched');

        $allowed = self::$sessions[Fixtures::ADMIN]->delete('/api/shares/' . $id);
        $this->assertSame(200, $allowed['status']);
        $this->assertNull(Fixtures::shareRow($id), 'and gone once the request is a genuine one');
    }

    public function testAForgedSettingsChangeChangesNothing(): void {
        $settings = fn () => self::$sessions[Fixtures::ADMIN]->get('/api/settings')['json'];
        $before = $settings()['retentionDays'];
        $forged = $before === 91 ? 92 : 91;

        $refused = self::$sessions[Fixtures::ADMIN]->post('/api/settings', ['retentionDays' => $forged], false);
        $this->assertSame(412, $refused['status']);
        $this->assertSame($before, $settings()['retentionDays']);

        $genuine = self::$sessions[Fixtures::ADMIN]->post('/api/settings', ['retentionDays' => $forged]);
        $this->assertSame(200, $genuine['status']);
        $this->assertSame($forged, $settings()['retentionDays']);

        self::$sessions[Fixtures::ADMIN]->post('/api/settings', ['retentionDays' => $before]);
    }

    // -------------------------------------------------------------------
    // Role again, by effect: what an account may not do, it cannot do
    // -------------------------------------------------------------------

    public function testAnAuditorCannotRevokeAShareAndAnAdminCan(): void {
        $id = (int)Fixtures::link(Fixtures::ownerFile('auditor-revoke.txt'))->getId();

        $refused = self::$sessions[Fixtures::AUDITOR]->delete('/api/shares/' . $id);
        $this->assertSame(403, $refused['status']);
        $this->assertNotNull(Fixtures::shareRow($id), 'still there');

        $this->assertSame(200, self::$sessions[Fixtures::ADMIN]->delete('/api/shares/' . $id)['status']);
        $this->assertNull(Fixtures::shareRow($id));
    }

    public function testAnAuditorCannotRestoreOrPurgeARecycledShare(): void {
        $id = (int)Fixtures::link(Fixtures::ownerFile('auditor-bin.txt'))->getId();
        $this->assertSame(200, self::$sessions[Fixtures::ADMIN]->delete('/api/shares/' . $id)['status']);
        $binId = Fixtures::binIdOf($id);
        $this->assertNotNull($binId, 'the revoke went through the bin');

        $this->assertSame(403, self::$sessions[Fixtures::AUDITOR]->post("/api/deleted/$binId/restore")['status']);
        $this->assertSame(403, self::$sessions[Fixtures::AUDITOR]->delete("/api/deleted/$binId")['status']);
        $this->assertSame(1, Fixtures::countBinEntries($id), 'still in the bin, untouched');
        $this->assertNull(Fixtures::shareRow($id), 'and not restored');
    }

    public function testARegularAccountCannotRevokeSomebodyElsesShareThroughItsPersonalRoute(): void {
        $id = (int)Fixtures::link(Fixtures::ownerFile('not-yours.txt'))->getId();

        $response = self::$sessions[Fixtures::USER]->delete('/api/my/shares/' . $id);

        $this->assertContains($response['status'], [403, 404]);
        $this->assertNotNull(Fixtures::shareRow($id), "somebody else's link is untouched");
    }

    public function testAnAuditorsExportNeverCarriesALinkTokenEvenWhenAskedFor(): void {
        $share = Fixtures::link(Fixtures::ownerFile('token-export.txt'));
        $token = $share->getToken();

        $auditor = self::$sessions[Fixtures::AUDITOR]->get('/api/export', ['includeTokens' => 'true']);
        $admin = self::$sessions[Fixtures::ADMIN]->get('/api/export', ['includeTokens' => 'true']);

        $this->assertSame(200, $auditor['status']);
        $this->assertStringNotContainsString($token, $auditor['body']);
        $this->assertStringContainsString($token, $admin['body'], 'an admin may ask for it');
    }
}
