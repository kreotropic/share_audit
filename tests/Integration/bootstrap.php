<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Boots the real Nextcloud this suite runs inside of (see phpunit.integration.xml).
 *
 * Refuses to run unless told the instance is disposable: the tests create
 * accounts, shares and (when Talk is not installed) tables, and delete rows.
 */

if (getenv('SHARE_AUDIT_INTEGRATION') !== 'disposable-instance') {
    fwrite(STDERR, "Refusing to run: these tests write to the instance's database.\n"
        . "Run them on a disposable one (build/run-integration.sh does), which sets\n"
        . "SHARE_AUDIT_INTEGRATION=disposable-instance.\n");
    exit(2);
}

// PHPUnit was started through the app's own vendor/autoload.php, whose class map
// carries stub copies of the OCP interfaces (what the unit tests mock). Here the
// real ones must win, or the real classes would implement the stubs.
foreach (\Composer\Autoload\ClassLoader::getRegisteredLoaders() as $loader) {
    $classMap = new \ReflectionProperty($loader, 'classMap');
    $stubs = array_filter($classMap->getValue($loader), static fn (string $class) => str_starts_with($class, 'OCP\\'), ARRAY_FILTER_USE_KEY);
    $classMap->setValue($loader, array_diff_key($classMap->getValue($loader), $stubs));
}

$root = getenv('NEXTCLOUD_ROOT') ?: '/var/www/html';
define('OC_CONSOLE', 1);
require_once $root . '/lib/base.php';
\OC_App::loadApps();

if (\OC::$server->get(\OCP\IConfig::class)->getSystemValueBool('installed', false) === false) {
    fwrite(STDERR, "Nextcloud is not installed here.\n");
    exit(2);
}
