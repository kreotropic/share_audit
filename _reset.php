<?php
/**
 * Dev-only: deletes every user except the instance admin (cascades to their
 * files and shares). Not part of the app — excluded from packaging via
 * .nextcloudignore.
 *
 * Usage (inside the nextcloud-app container):
 *   php occ.php  # n/a — run directly with php, not through occ:
 *   php _reset.php --yes
 *
 * From the host:
 *   docker exec -u www-data nextcloud-app php \
 *     /var/www/html/custom_apps/share_audit_dashboard/_reset.php --yes
 */

require_once '/var/www/html/lib/base.php';

use OCP\IGroupManager;
use OCP\IUserManager;

function out(string $msg): void {
    fwrite(STDOUT, $msg . "\n");
}

if (!in_array('--yes', $argv, true)) {
    out('This deletes every user except instance admins, and everything they own');
    out('(files, shares). Re-run with --yes to confirm:');
    out('  php _reset.php --yes');
    exit(1);
}

$userManager = \OC::$server->get(IUserManager::class);
$groupManager = \OC::$server->get(IGroupManager::class);

$deleted = 0;
foreach ($userManager->search('') as $user) {
    if ($groupManager->isAdmin($user->getUID())) {
        out('  keeping admin: ' . $user->getUID());
        continue;
    }
    out('  deleting ' . $user->getUID());
    $user->delete();
    $deleted++;
}

out("Done. Deleted $deleted user(s).");
