<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Nextcloud 34 and newer reject any controller parameter called `limit` that
 * falls outside 1..500 (Dispatcher::ensureParameterValueSatisfiesRange(),
 * "Parameter limit must be between 1 and 500" — a 500 on 34.0.x, a 400 on 35),
 * unless the method declares its own range in its docblock. Our views send
 * `limit=0` for the "All" page size, so every endpoint that supports it must
 * declare one — otherwise choosing "All" fails on 34/35 while working on the
 * older versions this app is developed against. A test is the only thing that
 * will notice a new paginated endpoint that forgot to.
 */
class ControllerLimitRangeTest extends TestCase {

    /**
     * Endpoints that deliberately keep Nextcloud's default 1..500 rule: they
     * offer no "All" page size (the collector clamps their limit to >= 1).
     */
    private const KEEPS_DEFAULT_RANGE = [
        'ShareApiController::index',
        'PersonalController::shares',
    ];

    /**
     * Same pattern Nextcloud's ControllerMethodReflector uses to read a
     * range out of a docblock.
     */
    private const RANGE_ANNOTATION = '/@(?:psalm-)?param\h+(\?)?(?P<type>\w+)<(?P<min>(-?\d+|min)),\h*(?P<max>(-?\d+|max))>(\|null)?\h+\$limit\b/';

    /**
     * @return iterable<string, array{0: \ReflectionMethod}>
     */
    public static function methodsTakingALimit(): iterable {
        foreach (glob(__DIR__ . '/../../lib/Controller/*Controller.php') ?: [] as $file) {
            $short = basename($file, '.php');
            $reflection = new \ReflectionClass('OCA\\ShareAuditDashboard\\Controller\\' . $short);
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $reflection->getName()) {
                    continue;
                }
                foreach ($method->getParameters() as $parameter) {
                    if ($parameter->getName() === 'limit') {
                        yield $short . '::' . $method->getName() => [$method];
                    }
                }
            }
        }
    }

    public function testThereAreEndpointsToCheck(): void {
        $this->assertNotEmpty(iterator_to_array(self::methodsTakingALimit()));
    }

    #[DataProvider('methodsTakingALimit')]
    public function testEveryLimitParameterMakesAConsciousChoiceOfRange(\ReflectionMethod $method): void {
        $name = $method->getDeclaringClass()->getShortName() . '::' . $method->getName();
        if (in_array($name, self::KEEPS_DEFAULT_RANGE, true)) {
            $this->assertSame(
                0,
                preg_match(self::RANGE_ANNOTATION, (string)$method->getDocComment()),
                "$name is listed as keeping the default range but declares one; drop it from KEEPS_DEFAULT_RANGE.",
            );
            return;
        }

        $this->assertSame(
            1,
            preg_match(self::RANGE_ANNOTATION, (string)$method->getDocComment(), $found),
            "$name takes a `limit` but declares no range, so Nextcloud 34+ rejects limit=0 (the \"All\" page size). "
            . 'Add `@param int<0, 500> $limit` to its docblock — or, if it truly has no "All" option, list it in KEEPS_DEFAULT_RANGE.',
        );
        $this->assertSame('0', $found['min'], "$name must allow limit=0.");
        $this->assertLessThanOrEqual(500, (int)$found['max'], "$name promises more rows per page than the services return.");
    }
}
