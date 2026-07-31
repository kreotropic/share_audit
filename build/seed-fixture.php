<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Seeds a deterministic set of shares, so the identical fixture can be created
 * on instances backed by different databases and this app's output diffed
 * between them. Everything is fixed — accounts, files, share types, passwords,
 * expirations and creation times — because anything random makes the diff
 * meaningless.
 *
 * The fixture deliberately covers what the queries are sensitive to:
 *  - every share type the app reports on (user, group, public link, email);
 *  - links with and without a password, with and without an expiration, so the
 *    security rules and the "has password"/"has expiration" filters all have
 *    both cases to sort and count;
 *  - a `share_with` that is NULL (public links) alongside real recipients,
 *    which is where MySQL and PostgreSQL disagree about sort order;
 *  - a sensitive file extension and an editable group share, which are what
 *    two of the alert rules fire on;
 *  - creation times spread across a year, so the 12-month trend has shape.
 *
 * Idempotent: re-running it wipes the fixture accounts' shares and rebuilds
 * them, so you can re-seed both instances before a diff without tearing
 * anything down.
 *
 * Run it inside the container, then compare:
 *
 *   docker exec -u www-data <app-container> \
 *       php /var/www/html/custom_apps/share_audit_dashboard/build/seed-fixture.php
 *
 * Usage: seed-fixture.php [prefix]   (default prefix: sa_fixture)
 */

require_once '/var/www/html/lib/base.php';
\OC_App::loadApps();

use OCP\Share\IShare;

$prefix = $argv[1] ?? 'sa_fixture';

/** Fixed reference point, so backdated share times never move. */
const BASE_TIME = 1785000000;
const ACCOUNT_PASSWORD = 'SadFixture#2026';

$userManager = \OCP\Server::get(\OCP\IUserManager::class);
$groupManager = \OCP\Server::get(\OCP\IGroupManager::class);
$rootFolder = \OCP\Server::get(\OCP\Files\IRootFolder::class);
$shareManager = \OCP\Server::get(\OCP\Share\IManager::class);
$db = \OCP\Server::get(\OCP\IDBConnection::class);

$users = ["{$prefix}_ana", "{$prefix}_bruno", "{$prefix}_carla"];
$groups = ["{$prefix}_marketing", "{$prefix}_eng"];

foreach ($groups as $group) {
    if (!$groupManager->groupExists($group)) {
        $groupManager->createGroup($group);
    }
}
foreach ($users as $i => $uid) {
    if (!$userManager->userExists($uid)) {
        $userManager->createUser($uid, ACCOUNT_PASSWORD);
    }
    $groupManager->get($groups[$i % 2])->addUser($userManager->get($uid));
}

// Start from a clean slate so re-running produces the same result.
$db->executeStatement(
    'DELETE FROM *PREFIX*share WHERE uid_owner IN (?, ?, ?)',
    $users,
);

$fileNames = ['Proposta.docx', 'dados_clientes.csv', 'backup.sql', 'foto.png'];
$nodes = [];
foreach ($users as $uid) {
    \OC_Util::setupFS($uid);
    $folder = $rootFolder->getUserFolder($uid);
    foreach ($fileNames as $name) {
        if ($folder->nodeExists($name)) {
            $folder->get($name)->delete();
        }
        $nodes["$uid/$name"] = $folder->newFile($name, str_repeat('x', 1024));
    }
}

/**
 * @param ?string $with     null for a public link
 * @param ?string $expires  a strtotime() expression, or null for no expiration
 * @return int the new share's id
 */
function makeShare(
    \OCP\Share\IManager $shareManager,
    \OCP\Files\Node $node,
    string $owner,
    int $type,
    ?string $with,
    int $permissions,
    ?string $password,
    ?string $expires,
): int {
    $share = $shareManager->newShare();
    $share->setNode($node)
        ->setShareType($type)
        ->setSharedBy($owner)
        ->setPermissions($permissions);
    if ($with !== null) {
        $share->setSharedWith($with);
    }
    if ($password !== null) {
        $share->setPassword($password);
    }
    if ($expires !== null) {
        $share->setExpirationDate(new \DateTime($expires));
    }
    return (int)$shareManager->createShare($share)->getId();
}

// A file share cannot carry create/delete permissions — 19 is read+update,
// 17 is read only. Using 31 here would be rejected.
$read = 17;
$readWrite = 19;

[$ana, $bruno, $carla] = $users;
[$marketing, $eng] = $groups;

$created = [];
// Internal shares with real recipients.
$created[] = makeShare($shareManager, $nodes["$ana/Proposta.docx"], $ana, IShare::TYPE_USER, $bruno, $readWrite, null, null);
$created[] = makeShare($shareManager, $nodes["$bruno/foto.png"], $bruno, IShare::TYPE_USER, $carla, $read, null, null);
// Editable group share — trips the group_share_editable rule.
$created[] = makeShare($shareManager, $nodes["$ana/backup.sql"], $ana, IShare::TYPE_GROUP, $eng, $readWrite, null, null);
// Public links: the security-alert material, one per combination.
$created[] = makeShare($shareManager, $nodes["$ana/dados_clientes.csv"], $ana, IShare::TYPE_LINK, null, $read, null, null);
$created[] = makeShare($shareManager, $nodes["$bruno/Proposta.docx"], $bruno, IShare::TYPE_LINK, null, $read, null, '+30 days');
$created[] = makeShare($shareManager, $nodes["$carla/backup.sql"], $carla, IShare::TYPE_LINK, null, $read, 'LinkPass#2026', null);
$created[] = makeShare($shareManager, $nodes["$carla/foto.png"], $carla, IShare::TYPE_LINK, null, $read, 'LinkPass#2026', '+15 days');
// External.
$created[] = makeShare($shareManager, $nodes["$bruno/dados_clientes.csv"], $bruno, IShare::TYPE_EMAIL, 'externo@example.org', $read, null, null);

// Backdate creation times with a raw UPDATE: IShare has no setter for stime,
// and the dashboard's 12-month trend groups by it.
$daysAgo = [400, 330, 250, 180, 120, 60, 20, 5];
foreach ($created as $i => $id) {
    $db->executeStatement(
        'UPDATE *PREFIX*share SET stime = ? WHERE id = ?',
        [BASE_TIME - ($daysAgo[$i] * 86400), $id],
    );
}

echo 'seeded ' . count($created) . ' shares across ' . count($users)
    . ' accounts (prefix "' . $prefix . "\")\n";
echo "note: expirations are relative to now, so compare instances seeded the same day\n";
