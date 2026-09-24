<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Integration;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Server;
use OCP\Share\IManager;
use OCP\Share\IShare;

/**
 * What the integration tests stand on: four accounts (an admin, an auditor, a
 * regular user, and the owner of every share a test makes), the auditor group,
 * and — when Talk is not installed on the instance — the two Talk tables the
 * app reads a conversation's name from. Everything is created once, and undone
 * when the run ends.
 */
final class Fixtures {

    public const PASSWORD = 'Sai-Integration#2026-x';
    public const ADMIN = 'sai_admin';
    public const AUDITOR = 'sai_auditor';
    public const USER = 'sai_user';
    public const OWNER = 'sai_owner';
    public const AUDITOR_GROUP = 'sai_auditors';
    public const BASE_URL = 'http://localhost/index.php/apps/share_audit_dashboard';

    /** The alphabet Talk draws its conversation tokens from. */
    private const TOKEN_ALPHABET = '23456789abcdefghijkmnpqrstuvwxyz';

    private static bool $ready = false;
    /** @var string[]|null */
    private static ?array $previousAuditorGroups = null;
    private static bool $createdTalkTables = false;

    public static function boot(): void {
        if (self::$ready) {
            return;
        }
        self::$ready = true;

        $users = Server::get(IUserManager::class);
        $groups = Server::get(IGroupManager::class);
        foreach ([self::ADMIN, self::AUDITOR, self::USER, self::OWNER] as $uid) {
            if (!$users->userExists($uid)) {
                $users->createUser($uid, self::PASSWORD);
            }
        }
        foreach ([self::AUDITOR_GROUP] as $gid) {
            if (!$groups->groupExists($gid)) {
                $groups->createGroup($gid);
            }
        }
        $groups->get('admin')->addUser($users->get(self::ADMIN));
        $groups->get(self::AUDITOR_GROUP)->addUser($users->get(self::AUDITOR));

        $config = Server::get(IAppConfig::class);
        self::$previousAuditorGroups = $config->getValueArray('share_audit_dashboard', 'auditor_groups', []);
        $config->setValueArray('share_audit_dashboard', 'auditor_groups', [self::AUDITOR_GROUP]);

        self::ensureTalkTables();
        register_shutdown_function([self::class, 'shutdown']);
    }

    public static function shutdown(): void {
        if (!self::$ready) {
            return;
        }
        self::$ready = false;
        self::purgeOwnerShares();
        self::purgeRooms();
        Server::get(IAppConfig::class)->setValueArray('share_audit_dashboard', 'auditor_groups', self::$previousAuditorGroups ?? []);
        if (self::$createdTalkTables) {
            $db = Server::get(IDBConnection::class);
            $db->executeStatement('DROP TABLE *PREFIX*talk_attendees');
            $db->executeStatement('DROP TABLE *PREFIX*talk_rooms');
        }
    }

    public static function session(?string $who): HttpSession {
        return new HttpSession(self::BASE_URL, $who, $who === null ? null : self::PASSWORD);
    }

    // -------------------------------------------------------------------
    // Files and links (the real share manager)
    // -------------------------------------------------------------------

    public static function ownerFile(string $name): File {
        \OC_Util::setupFS(self::OWNER);
        $folder = Server::get(IRootFolder::class)->getUserFolder(self::OWNER);
        if ($folder->nodeExists($name)) {
            $folder->get($name)->delete();
        }
        return $folder->newFile($name, 'integration ' . $name);
    }

    public static function link(File $file, ?string $password = null): IShare {
        $manager = Server::get(IManager::class);
        $share = $manager->newShare();
        $share->setNode($file)
            ->setShareType(IShare::TYPE_LINK)
            ->setSharedBy(self::OWNER)
            ->setPermissions(17);
        if ($password !== null) {
            $share->setPassword($password);
        }
        return $manager->createShare($share);
    }

    /**
     * @return array<string, mixed>|null the oc_share row, or null once it is gone
     */
    public static function shareRow(int $id): ?array {
        $db = Server::get(IDBConnection::class);
        $qb = $db->getQueryBuilder();
        $result = $qb->select('*')->from('share')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();
        return $row ?: null;
    }

    public static function countSharesWithToken(string $token): int {
        $db = Server::get(IDBConnection::class);
        $qb = $db->getQueryBuilder();
        $result = $qb->select($qb->func()->count('*'))->from('share')
            ->where($qb->expr()->eq('token', $qb->createNamedParameter($token)))
            ->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();
        return $count;
    }

    public static function countBinEntries(int $originalShareId): int {
        $db = Server::get(IDBConnection::class);
        $qb = $db->getQueryBuilder();
        $result = $qb->select($qb->func()->count('*'))->from('shareaudit_deleted')
            ->where($qb->expr()->eq('original_share_id', $qb->createNamedParameter($originalShareId, IQueryBuilder::PARAM_INT)))
            ->executeQuery();
        $count = (int)$result->fetchOne();
        $result->closeCursor();
        return $count;
    }

    /**
     * The id of the recycle-bin entry a revoked share was captured as.
     */
    public static function binIdOf(int $originalShareId): ?int {
        $db = Server::get(IDBConnection::class);
        $qb = $db->getQueryBuilder();
        $result = $qb->select('id')->from('shareaudit_deleted')
            ->where($qb->expr()->eq('original_share_id', $qb->createNamedParameter($originalShareId, IQueryBuilder::PARAM_INT)))
            ->executeQuery();
        $id = $result->fetchOne();
        $result->closeCursor();
        return $id === false ? null : (int)$id;
    }

    public static function purgeOwnerShares(): void {
        $db = Server::get(IDBConnection::class);
        $db->executeStatement('DELETE FROM *PREFIX*share WHERE uid_owner = ?', [self::OWNER]);
        $db->executeStatement('DELETE FROM *PREFIX*shareaudit_deleted WHERE uid_owner = ?', [self::OWNER]);
    }

    // -------------------------------------------------------------------
    // Talk conversations. Talk is another app: without it installed, the two
    // tables the app reads are made here, holding only the columns it reads.
    // A room's share is a plain oc_share row of type 10, which is all Talk
    // itself ever writes there.
    // -------------------------------------------------------------------

    private static function ensureTalkTables(): void {
        $db = Server::get(IDBConnection::class);
        if ($db->tableExists('talk_rooms')) {
            return;
        }
        $db->executeStatement('CREATE TABLE *PREFIX*talk_rooms (id BIGINT NOT NULL PRIMARY KEY, token VARCHAR(32) NOT NULL, name VARCHAR(255) NOT NULL, type SMALLINT NOT NULL, listable SMALLINT NOT NULL)');
        $db->executeStatement('CREATE TABLE *PREFIX*talk_attendees (id BIGINT NOT NULL PRIMARY KEY, room_id BIGINT NOT NULL, actor_type VARCHAR(32) NOT NULL)');
        self::$createdTalkTables = true;
    }

    /**
     * @return string the new conversation's token
     */
    public static function createRoom(string $name, int $type = 2, ?string $token = null): string {
        $token ??= self::randomToken();
        $db = Server::get(IDBConnection::class);
        $result = $db->executeQuery('SELECT COALESCE(MAX(id), 0) + 1 FROM *PREFIX*talk_rooms');
        $id = (int)$result->fetchOne();
        $result->closeCursor();
        $db->executeStatement(
            'INSERT INTO *PREFIX*talk_rooms (id, token, name, type, listable) VALUES (?, ?, ?, ?, 0)',
            [$id, $token, $name, $type],
        );
        return $token;
    }

    /**
     * Share $file into the conversation $token, the way Talk stores it.
     *
     * @return int the oc_share row's id
     */
    public static function shareIntoRoom(string $token, File $file): int {
        $db = Server::get(IDBConnection::class);
        $qb = $db->getQueryBuilder();
        $qb->insert('share')->values([
            'share_type' => $qb->createNamedParameter(IShare::TYPE_ROOM, IQueryBuilder::PARAM_INT),
            'share_with' => $qb->createNamedParameter($token),
            'uid_owner' => $qb->createNamedParameter(self::OWNER),
            'uid_initiator' => $qb->createNamedParameter(self::OWNER),
            'item_type' => $qb->createNamedParameter('file'),
            'item_source' => $qb->createNamedParameter((string)$file->getId()),
            'file_source' => $qb->createNamedParameter($file->getId(), IQueryBuilder::PARAM_INT),
            'file_target' => $qb->createNamedParameter('/' . $file->getName()),
            'permissions' => $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT),
            'stime' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
        ])->executeStatement();
        return $qb->getLastInsertId();
    }

    /**
     * Give a conversation another token — same name, same shares, same
     * everything a caller may see; only the credential differs.
     */
    public static function retokenRoom(string $old, string $new): void {
        $db = Server::get(IDBConnection::class);
        $db->executeStatement('UPDATE *PREFIX*talk_rooms SET token = ? WHERE token = ?', [$new, $old]);
        $db->executeStatement('UPDATE *PREFIX*share SET share_with = ? WHERE share_type = ? AND share_with = ?', [$new, IShare::TYPE_ROOM, $old]);
    }

    public static function purgeRooms(): void {
        $db = Server::get(IDBConnection::class);
        $db->executeStatement('DELETE FROM *PREFIX*share WHERE share_type = ? AND uid_owner = ?', [IShare::TYPE_ROOM, self::OWNER]);
        if ($db->tableExists('talk_rooms')) {
            $db->executeStatement("DELETE FROM *PREFIX*talk_rooms WHERE name LIKE 'sai-%'");
        }
    }

    public static function randomToken(): string {
        $token = '';
        for ($i = 0; $i < 8; $i++) {
            $token .= self::TOKEN_ALPHABET[random_int(0, strlen(self::TOKEN_ALPHABET) - 1)];
        }
        return $token;
    }
}
