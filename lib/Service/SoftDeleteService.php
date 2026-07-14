<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Service;

use OCA\ShareAuditDashboard\Db\DeletedShare;
use OCA\ShareAuditDashboard\Db\DeletedShareMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Node;
use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\Share\IManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Soft delete (recycling) for shares — ROADMAP.md #1. A share revoked
 * through this app, or through native Nextcloud sharing UI, is captured
 * here (see SoftDeleteListener / ShareDeletionService::deleteDirect())
 * BEFORE the real oc_share row disappears, kept for a configurable
 * retention window, and can be restored or purged.
 *
 * Access is still cut immediately — capturing a copy doesn't stop the real
 * deletion, it just means the data survives it.
 */
class SoftDeleteService {

    /** Same 'circle' → 'group' / federated bucketing as ShareCollectorService::CATEGORY_BY_TYPE. */
    private const CATEGORY_BY_TYPE = [
        IShare::TYPE_USER => 'user',
        IShare::TYPE_GROUP => 'group',
        IShare::TYPE_CIRCLE => 'group',
        IShare::TYPE_LINK => 'link',
        IShare::TYPE_EMAIL => 'email',
        IShare::TYPE_REMOTE => 'federated',
        IShare::TYPE_REMOTE_GROUP => 'federated',
        IShare::TYPE_ROOM => 'talk',
    ];

    public function __construct(
        private DeletedShareMapper $mapper,
        private IManager $shareManager,
        private FileNodeResolver $nodeResolver,
        private IDBConnection $db,
        private ITimeFactory $time,
        private SettingsService $settings,
        private IUserSession $userSession,
        private DisplayNameResolver $displayNames,
        private SecurityAnalyzerService $analyzer,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Capture a share from a live IShare instance — the BeforeShareDeletedEvent
     * path, which covers both this app's revokes (they all go through
     * IShareManager::deleteShare()) and native Nextcloud unshares.
     */
    public function captureShare(IShare $share): void {
        $entity = new DeletedShare();
        $entity->setOriginalShareId((int)$share->getId());
        $entity->setShareType((int)$share->getShareType());
        $entity->setShareWith($share->getSharedWith() !== '' ? $share->getSharedWith() : null);
        $entity->setUidOwner((string)$share->getShareOwner());
        $entity->setUidInitiator($share->getSharedBy() ?: null);
        $entity->setItemType($share->getNodeType() ?: 'file');
        $entity->setFileSource($share->getNodeId());
        $entity->setFileTarget($share->getTarget() ?: null);
        $entity->setPermissions((int)$share->getPermissions());
        $entity->setToken($share->getToken() ?: null);
        $entity->setPassword($share->getPassword() ?: null);
        $entity->setShareName($share->getLabel() ?: null);
        $entity->setExpiration($share->getExpirationDate()?->format('Y-m-d H:i:s'));
        $entity->setStime($share->getShareTime()?->getTimestamp());
        $this->finishCapture($entity);
    }

    /**
     * Capture a share from a raw oc_share row (same shape ShareMapper
     * selects) — the ShareDeletionService::deleteDirect() fallback path,
     * which bypasses IShareManager (and so BeforeShareDeletedEvent) entirely.
     *
     * @param array<string, mixed> $row
     */
    public function captureRow(array $row): void {
        $entity = new DeletedShare();
        $entity->setOriginalShareId((int)$row['id']);
        $entity->setShareType((int)$row['share_type']);
        $entity->setShareWith((($row['share_with'] ?? '') !== '') ? (string)$row['share_with'] : null);
        $entity->setUidOwner((string)($row['uid_owner'] ?? ''));
        $entity->setUidInitiator((($row['uid_initiator'] ?? '') !== '') ? (string)$row['uid_initiator'] : null);
        $entity->setItemType((string)($row['item_type'] ?? 'file'));
        $entity->setFileSource(isset($row['file_source']) ? (int)$row['file_source'] : null);
        $entity->setFileTarget($row['file_target'] ?? null);
        $entity->setPermissions((int)($row['permissions'] ?? 0));
        $entity->setToken($row['token'] ?? null);
        $entity->setPassword($row['password'] ?? null);
        $entity->setShareName($row['share_name'] ?? null);
        $entity->setExpiration($row['expiration'] ?? null);
        $entity->setStime(isset($row['stime']) ? (int)$row['stime'] : null);
        $this->finishCapture($entity);
    }

    private function finishCapture(DeletedShare $entity): void {
        $now = $this->time->getTime();
        $entity->setDeletedAt($now);
        $entity->setDeletedBy($this->userSession->getUser()?->getUID());
        $entity->setPurgeAfter($now + $this->settings->getRetentionDays() * 86400);
        $this->mapper->insert($entity);
    }

    /**
     * Total recycle-bin entries — used for the "Deleted shares" tab badge.
     */
    public function count(): int {
        return $this->mapper->count();
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, limit: int}
     */
    public function list(int $page, int $limit): array {
        $page = max(1, $page);
        $limit = max(0, min(500, $limit));
        $offset = ($page - 1) * $limit;

        $items = array_map([$this, 'normalize'], $this->mapper->findPage($limit, $offset));

        $names = $this->displayNames->resolveMany(array_merge(
            array_column($items, 'owner'),
            array_column($items, 'deletedBy'),
        ));
        foreach ($items as &$item) {
            $item['ownerDisplayName'] = $names[$item['owner']] ?? $item['owner'];
            if ($item['deletedBy'] !== null) {
                $item['deletedByDisplayName'] = $names[$item['deletedBy']] ?? $item['deletedBy'];
            }
        }
        unset($item);

        return ['items' => $items, 'total' => $this->mapper->count(), 'page' => $page, 'limit' => $limit];
    }

    /**
     * Recreate a retained share via IShareManager — runs it back through the
     * normal creation path (permission checks, provider bootstrap, mount
     * points) rather than re-inserting the old row directly.
     *
     * Token and password need special handling: IShare::setPassword()/
     * setToken() feed a *new* share creation as if the value were a fresh
     * plain-text password / requested token — the provider hashes it (or
     * generates a random token) as normal. Since our stored values are
     * already the original hash/token, that would double-hash the password
     * and always mint a new link URL. So instead: create the share with
     * neither set, then overwrite just those two columns with a raw UPDATE.
     * If that fails (most likely: the original token got reused by a
     * different link created while this one was in the bin — token is
     * UNIQUE), the share still exists, just with a fresh token/no password —
     * reported back as `tokenChanged` so the caller can warn the owner.
     *
     * @return array{success: bool, id?: int, tokenChanged?: bool, message?: string}
     */
    public function restore(int $id): array {
        try {
            $entity = $this->mapper->find($id);
        } catch (DoesNotExistException) {
            return ['success' => false, 'message' => 'Not found.'];
        }

        $node = $this->resolveNode($entity);
        if ($node === null) {
            return ['success' => false, 'message' => 'The original file no longer exists.'];
        }

        $share = $this->shareManager->newShare();
        $share->setShareType($entity->getShareType());
        $share->setNode($node);
        $share->setShareOwner($entity->getUidOwner());
        $share->setSharedBy($entity->getUidInitiator() ?: $entity->getUidOwner());
        $share->setPermissions($entity->getPermissions());
        if ($entity->getShareWith() !== null) {
            $share->setSharedWith($entity->getShareWith());
        }
        if ($entity->getShareName() !== null) {
            $share->setLabel($entity->getShareName());
        }
        if ($entity->getExpiration() !== null) {
            try {
                $share->setExpirationDate(new \DateTime($entity->getExpiration()));
            } catch (\Exception) {
                // Unparsable stored date — restore without one rather than fail outright.
            }
        }

        try {
            $created = $this->shareManager->createShare($share);
        } catch (\Throwable $e) {
            $this->logger->warning('Soft-delete restore failed for retention id {id}: {exception}', [
                'id' => $id, 'exception' => $e,
            ]);
            return ['success' => false, 'message' => 'Could not recreate the share (recipient or permissions no longer valid?).'];
        }

        $tokenRestored = $this->restoreRawColumns((int)$created->getId(), $entity);
        $this->mapper->delete($entity);
        // The restored share is exactly as risky as it was before revocation
        // (no password/expiration carry no less risk back) — without this,
        // the alerts list/badge stays stale for up to CACHE_TTL seconds.
        $this->analyzer->invalidate($entity->getUidOwner(), $entity->getUidInitiator());

        return ['success' => true, 'id' => (int)$created->getId(), 'tokenChanged' => !$tokenRestored];
    }

    private function resolveNode(DeletedShare $entity): ?Node {
        if ($entity->getFileSource() === null) {
            return null;
        }
        return $this->nodeResolver->resolve($entity->getUidOwner(), $entity->getFileSource());
    }

    private function restoreRawColumns(int $newId, DeletedShare $entity): bool {
        if ($entity->getToken() === null && $entity->getPassword() === null) {
            return true;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->update('share');
        if ($entity->getToken() !== null) {
            $qb->set('token', $qb->createNamedParameter($entity->getToken()));
        }
        if ($entity->getPassword() !== null) {
            $qb->set('password', $qb->createNamedParameter($entity->getPassword()));
        }
        $qb->where($qb->expr()->eq('id', $qb->createNamedParameter($newId, IQueryBuilder::PARAM_INT)));
        try {
            $qb->executeStatement();
            return true;
        } catch (\Throwable $e) {
            $this->logger->info('Could not restore the original token/password for share {id} (likely a reused token): {exception}', [
                'id' => $newId, 'exception' => $e,
            ]);
            return false;
        }
    }

    /**
     * Permanently delete one retained entry. Returns false if it was already gone.
     */
    public function purge(int $id): bool {
        try {
            $entity = $this->mapper->find($id);
        } catch (DoesNotExistException) {
            return false;
        }
        $this->mapper->delete($entity);
        return true;
    }

    /**
     * @param int[] $ids
     */
    public function purgeMany(array $ids): int {
        $count = 0;
        foreach ($ids as $id) {
            if ($this->purge((int)$id)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Delete every entry whose retention window has passed — called daily by
     * PurgeDeletedSharesJob.
     */
    public function purgeExpired(): int {
        $count = 0;
        foreach ($this->mapper->findExpired($this->time->getTime()) as $entity) {
            $this->mapper->delete($entity);
            $count++;
        }
        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(DeletedShare $e): array {
        $type = $e->getShareType();
        return [
            'id' => $e->getId(),
            'originalShareId' => $e->getOriginalShareId(),
            'type' => $type,
            'category' => self::CATEGORY_BY_TYPE[$type] ?? 'other',
            'owner' => $e->getUidOwner(),
            'recipient' => $e->getShareWith() ?? '',
            'path' => $e->getFileTarget(),
            'permissions' => $e->getPermissions(),
            'created' => $e->getStime(),
            'expiration' => $e->getExpiration(),
            'hasPassword' => !empty($e->getPassword()),
            'deletedAt' => $e->getDeletedAt(),
            'deletedBy' => $e->getDeletedBy(),
            'purgeAfter' => $e->getPurgeAfter(),
        ];
    }
}
