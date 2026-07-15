<?php
/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Dev-only: creates demo users (demoNN) with files and shares (user/group/
 * link/email) spread randomly across the last 365 days, for manually
 * exercising the dashboard, trend chart, alerts and orphan detection. Not
 * part of the app — excluded from packaging via .nextcloudignore.
 *
 * Safe to re-run: picks up numbering after the highest existing demoNN user,
 * so it adds more instead of colliding. Combine with _reset.php for a clean
 * slate first.
 *
 * Usage (inside the nextcloud-app container):
 *   php _seed.php [count]      # default count: 50
 *
 * From the host:
 *   docker exec -u www-data nextcloud-app php \
 *     /var/www/html/custom_apps/share_audit_dashboard/_seed.php 50
 *
 * Full reset + reseed:
 *   docker exec -u www-data nextcloud-app php \
 *     /var/www/html/custom_apps/share_audit_dashboard/_reset.php --yes
 *   docker exec -u www-data nextcloud-app php \
 *     /var/www/html/custom_apps/share_audit_dashboard/_seed.php 50
 */

require_once '/var/www/html/lib/base.php';

use OCP\Constants;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;

function out(string $msg): void {
    fwrite(STDOUT, $msg . "\n");
}

$count = isset($argv[1]) ? max(1, (int)$argv[1]) : 50;

$server = \OC::$server;
$userManager = $server->get(IUserManager::class);
$groupManager = $server->get(IGroupManager::class);
$shareManager = $server->get(IShareManager::class);
$rootFolder = $server->get(IRootFolder::class);
$db = $server->get(IDBConnection::class);

$pwdChars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%';
function randomPassword(string $chars, int $len = 16): string {
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
}

$groupIds = ['engineering', 'marketing', 'sales', 'finance', 'support', 'legal'];
foreach ($groupIds as $gid) {
    if (!$groupManager->groupExists($gid)) {
        $groupManager->createGroup($gid);
    }
}

$firstNames = ['Ana', 'Bruno', 'Carla', 'Diogo', 'Elsa', 'Filipe', 'Gina', 'Hugo', 'Ines', 'Joao',
    'Karla', 'Luis', 'Marta', 'Nuno', 'Olga', 'Pedro', 'Rita', 'Sergio', 'Teresa', 'Vasco',
    'Wanda', 'Xavier', 'Yara', 'Zeca', 'Alma', 'Beto', 'Clara', 'Duarte', 'Eva', 'Fabio',
    'Gil', 'Helena', 'Ivo', 'Julia', 'Kiko', 'Lara', 'Mario', 'Nadia', 'Otelo', 'Paula',
    'Ruben', 'Sofia', 'Tiago', 'Ursula', 'Vitor', 'Xana', 'Yuri', 'Zara', 'Artur', 'Bia'];
$lastNames = ['Silva', 'Costa', 'Ferreira', 'Pereira', 'Santos', 'Marques', 'Oliveira', 'Rodrigues', 'Sousa', 'Alves'];

// Pick up numbering after the highest existing demoNN user, so repeated runs
// add to the dataset instead of colliding.
$existing = array_map(fn ($u) => $u->getUID(), $userManager->search(''));
$startAt = 1;
while (in_array(sprintf('demo%02d', $startAt), $existing, true)) {
    $startAt++;
}

out("Creating $count users starting at demo" . sprintf('%02d', $startAt) . '...');
$users = [];
for ($i = 0; $i < $count; $i++) {
    $n = $startAt + $i;
    $uid = sprintf('demo%02d', $n);
    $display = $firstNames[($n - 1) % count($firstNames)] . ' ' . $lastNames[($n - 1) % count($lastNames)];
    $password = randomPassword($pwdChars);
    try {
        $user = $userManager->createUser($uid, $password);
    } catch (\Throwable $e) {
        out('  FAILED creating ' . $uid . ': ' . $e->getMessage());
        continue;
    }
    $user->setDisplayName($display);
    $user->setEMailAddress(strtolower($uid) . '@example.test');

    $g1 = $groupIds[array_rand($groupIds)];
    $groupManager->get($g1)->addUser($user);
    if (random_int(0, 2) === 0) {
        $g2 = $groupIds[array_rand($groupIds)];
        if ($g2 !== $g1) {
            $groupManager->get($g2)->addUser($user);
        }
    }

    // Disable roughly 1 in 10 users, so orphan-share testing has persistent data.
    if ($n % 10 === 0) {
        $user->setEnabled(false);
    }

    $users[] = ['uid' => $uid, 'display' => $display, 'group' => $g1];
    out("  created $uid ($display)" . ($n % 10 === 0 ? ' [disabled]' : ''));
}

if ($users === []) {
    out('No users created, stopping.');
    exit(1);
}

$fileNames = ['budget.xlsx', 'report.pdf', 'notes.txt', 'roadmap.docx', 'contract.pdf',
    'invoice.pdf', 'presentation.pptx', 'photo.jpg', 'backup.zip', 'passwords.txt',
    'salary.xlsx', 'plan.md', 'diagram.png', 'summary.docx', 'data.csv'];

out('Creating files...');
foreach ($users as &$u) {
    \OC_Util::setupFS($u['uid']);
    $folder = $rootFolder->getUserFolder($u['uid']);
    $n = random_int(2, 5);
    $picked = (array)array_rand(array_flip($fileNames), $n);
    $nodes = [];
    foreach ($picked as $fname) {
        try {
            $nodes[] = $folder->newFile($fname, 'demo content for ' . $fname);
        } catch (\Throwable $e) {
            out('  file failed for ' . $u['uid'] . '/' . $fname . ': ' . $e->getMessage());
        }
    }
    $u['files'] = $nodes;
}
unset($u);

$typeWeights = ['user' => 40, 'group' => 25, 'link' => 25, 'email' => 10];
function pickWeighted(array $weights) {
    $r = random_int(1, array_sum($weights));
    foreach ($weights as $k => $w) {
        if ($r <= $w) {
            return $k;
        }
        $r -= $w;
    }
    return array_key_first($weights);
}

$externalEmails = ['partner@example.test', 'client@example.test', 'auditor@example.test', 'vendor@example.test'];
$now = time();
$createdShareIds = [];

out('Creating shares...');
foreach ($users as $u) {
    if (empty($u['files'])) {
        continue;
    }
    $shareCount = random_int(1, 5);
    for ($s = 0; $s < $shareCount; $s++) {
        $node = $u['files'][array_rand($u['files'])];
        $type = pickWeighted($typeWeights);
        try {
            $share = $shareManager->newShare();
            $share->setNode($node)
                ->setSharedBy($u['uid'])
                ->setPermissions(Constants::PERMISSION_READ | Constants::PERMISSION_SHARE);

            if ($type === 'user') {
                $other = $users[array_rand($users)];
                if ($other['uid'] === $u['uid']) {
                    continue;
                }
                $share->setShareType(IShare::TYPE_USER)->setSharedWith($other['uid']);
            } elseif ($type === 'group') {
                $share->setShareType(IShare::TYPE_GROUP)->setSharedWith($u['group']);
            } elseif ($type === 'email') {
                $share->setShareType(IShare::TYPE_EMAIL)
                    ->setSharedWith($externalEmails[array_rand($externalEmails)]);
            } else {
                $share->setShareType(IShare::TYPE_LINK);
                // Roughly half insecure (no password/expiration), to populate alerts.
                if (random_int(0, 1) === 0) {
                    $share->setPassword(randomPassword($pwdChars, 12));
                    $share->setExpirationDate((new \DateTime())->modify('+' . random_int(10, 90) . ' days'));
                }
            }

            $created = $shareManager->createShare($share);
            $createdShareIds[] = $created->getId();
        } catch (\Throwable $e) {
            out('  share failed for ' . $u['uid'] . ': ' . $e->getMessage());
        }
    }
}
out('Created ' . count($createdShareIds) . ' shares.');

out('Backdating creation timestamps across the last 365 days...');
foreach ($createdShareIds as $id) {
    $daysAgo = random_int(0, 364);
    $ts = $now - ($daysAgo * 86400) - random_int(0, 86399);
    $qb = $db->getQueryBuilder();
    $qb->update('share')
        ->set('stime', $qb->createNamedParameter($ts, IQueryBuilder::PARAM_INT))
        ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
    $qb->executeStatement();
}

out('Done. Users: ' . count($users) . ', shares: ' . count($createdShareIds));
