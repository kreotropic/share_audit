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

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
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
$failed = [];
foreach ($userManager->search('') as $user) {
    if ($groupManager->isAdmin($user->getUID())) {
        out('  keeping admin: ' . $user->getUID());
        continue;
    }
    out('  deleting ' . $user->getUID());
    try {
        // NC33's files_sharing tries to rename group-share mount points to
        // avoid name collisions when a user leaves a group (part of
        // delete()'s own group-removal step) and can throw
        // InvalidArgumentException("Invalid share recipient") doing so —
        // uncaught, that would abort this whole loop after the first hit.
        // One broken user's leftover group shares shouldn't block wiping
        // everyone else, so isolate and continue.
        $user->delete();
        $deleted++;
    } catch (\Throwable $e) {
        out('  FAILED deleting ' . $user->getUID() . ': ' . get_class($e) . ': ' . $e->getMessage());
        $failed[] = $user->getUID();
    }
}

out("Done. Deleted $deleted user(s).");
if ($failed !== []) {
    out('Failed to delete ' . count($failed) . ': ' . implode(', ', $failed));
}

// The files_sharing bug above aborts delete() partway through for affected
// users: their oc_users/oc_accounts rows end up gone (userExists() is false)
// but their shares, home storage, filecache and on-disk files are left
// behind — a "reset" that silently isn't one. Sweep anything still
// referencing a uid that no longer exists, regardless of whether it showed
// up in $failed above (belt and braces). Never touches uids that still
// exist, so kept admins are untouched by construction.
out('Sweeping residual data for accounts that no longer exist...');

$db = \OC::$server->get(IDBConnection::class);

// oc_storages' "home::<uid>" rows are the most complete record of every uid
// that ever had a personal folder provisioned — unlike scanning oc_share
// (misses a gone account with zero remaining shares) or the data directory
// directly (can't safely tell a user folder from appdata_*/other system
// dirs without guessing). A uid can only get a home:: row through normal
// account creation, so this is safe to treat as "once-real accounts".
$qbHomes = $db->getQueryBuilder();
$qbHomes->select('id')->from('storages')
    ->where($qbHomes->expr()->like('id', $qbHomes->createNamedParameter('home::%')));
$homeIds = array_column($qbHomes->executeQuery()->fetchAll(), 'id');

$goneUids = array_values(array_unique(array_filter(
    array_map(static fn (string $id) => substr($id, strlen('home::')), $homeIds),
    static fn (string $uid) => $uid !== '' && !$userManager->userExists($uid),
)));

$sharesDeleted = 0;
foreach ($goneUids as $uid) {
    $qb = $db->getQueryBuilder();
    $qb->delete('share')->where($qb->expr()->orX(
        $qb->expr()->eq('uid_owner', $qb->createNamedParameter($uid)),
        $qb->expr()->eq('uid_initiator', $qb->createNamedParameter($uid)),
    ));
    $sharesDeleted += $qb->executeStatement();
}
out("  removed $sharesDeleted residual share row(s) for " . count($goneUids) . ' gone account(s)');

$dataDir = \OC::$server->get(IConfig::class)->getSystemValue('datadirectory', \OC::$SERVERROOT . '/data');
$storagesDeleted = 0;
$filecacheDeleted = 0;
foreach ($goneUids as $uid) {
    $qb = $db->getQueryBuilder();
    $qb->select('numeric_id')->from('storages')
        ->where($qb->expr()->eq('id', $qb->createNamedParameter('home::' . $uid)));
    $row = $qb->executeQuery()->fetch();
    if ($row) {
        $numericId = (int)$row['numeric_id'];

        $qbf = $db->getQueryBuilder();
        $qbf->delete('filecache')->where($qbf->expr()->eq('storage',
            $qbf->createNamedParameter($numericId, IQueryBuilder::PARAM_INT)));
        $filecacheDeleted += $qbf->executeStatement();

        $qbs = $db->getQueryBuilder();
        $qbs->delete('storages')->where($qbs->expr()->eq('numeric_id',
            $qbs->createNamedParameter($numericId, IQueryBuilder::PARAM_INT)));
        $storagesDeleted += $qbs->executeStatement();
    }

    // basename/realpath guard: never touch anything outside the data dir.
    $home = rtrim($dataDir, '/') . '/' . $uid;
    $realHome = realpath($home);
    if (is_dir($home) && basename($home) === $uid && $realHome !== false
        && str_starts_with($realHome, realpath($dataDir) . '/')) {
        exec('rm -rf ' . escapeshellarg($realHome));
    }
}
out("  removed $storagesDeleted home storage row(s), $filecacheDeleted filecache row(s), and on-disk home folder(s)");
