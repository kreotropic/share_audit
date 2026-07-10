<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Load Nextcloud's autoloaders so OCP interfaces and Doctrine DBAL are available.
// We search from the app directory upward, then fall back to the Docker default path.
$ncRoot = null;
foreach ([
    __DIR__ . '/../../../../',  // custom_apps layout: html/custom_apps/app/tests
    __DIR__ . '/../../../',     // apps layout: html/apps/app/tests
    '/var/www/html/',           // Docker default
] as $candidate) {
    if (file_exists($candidate . 'lib/composer/autoload.php')) {
        $ncRoot = realpath($candidate);
        break;
    }
}

if ($ncRoot !== null) {
    require_once $ncRoot . '/lib/composer/autoload.php';  // OCP interfaces
    require_once $ncRoot . '/3rdparty/autoload.php';      // Doctrine DBAL etc.
}
