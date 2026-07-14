<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\StorageNotAvailableException;
use OCP\IDBConnection;
use OCP\Lock\LockedException;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Deletes shares by id through IShareManager, resolving the correct provider
 * per share_type first. This makes sure federated unshare (OCM), the
 * ShareDeletedEvent (Activity, notifications, other apps' hooks) and
 * provider-specific cleanup (e.g. sharebymail's auxiliary tables) all run,
 * instead of leaving those side effects to a raw SQL DELETE.
 *
 * Falls back to a direct DB delete when the provider can't handle the row —
 * its app is disabled, the row is already gone, or (notably for orphan
 * shares) a post-delete step inside IShareManager itself throws because the
 * owner account no longer exists — logging it since OCM/events/cleanup are
 * necessarily skipped in that case.
 *
 * A retryable failure (a lock held on the underlying file, a storage backend
 * that's momentarily unreachable) is different: the row is untouched, so
 * falling back to a direct delete would bypass OCM/events for a share that
 * was never actually broken — it's reported as failed instead, and the
 * caller can retry.
 */
class ShareDeletionService {

    /** Provider id per raw share_type, matching OC\Share20\ProviderFactory. */
    private const PROVIDER_BY_TYPE = [
        IShare::TYPE_USER => 'ocinternal',
        IShare::TYPE_GROUP => 'ocinternal',
        IShare::TYPE_LINK => 'ocinternal',
        IShare::TYPE_EMAIL => 'ocMailShare',
        IShare::TYPE_REMOTE => 'ocFederatedSharing',
        IShare::TYPE_REMOTE_GROUP => 'ocFederatedSharing',
        IShare::TYPE_ROOM => 'ocRoomShare',
        IShare::TYPE_CIRCLE => 'ocCircleShare',
    ];

    public function __construct(
        private IDBConnection $db,
        private IManager $shareManager,
        private LoggerInterface $logger,
        private ShareAuditLogger $auditLogger,
        private SecurityAnalyzerService $analyzer,
        private SoftDeleteService $softDelete,
    ) {
    }

    /**
     * @param array<int, array{id: int|string, share_type: int|string, uid_owner?: string}> $rows
     * @return array{deleted: int, failed: int[]} failed ids hit a retryable
     *         error (lock held, storage unreachable) and were left untouched
     *         — safe for the caller to retry.
     */
    public function deleteRows(array $rows): array {
        $deleted = 0;
        $fallbackIds = [];
        $failedIds = [];
        $auditRows = [];

        foreach ($rows as $row) {
            $id = (int)$row['id'];
            $provider = self::PROVIDER_BY_TYPE[(int)$row['share_type']] ?? 'ocinternal';
            try {
                // onlyValid=false: orphan/disabled-owner shares are exactly what
                // we delete here, and the manager would otherwise reject them
                // as "not found" (see Share20\Manager::checkShare()).
                $share = $this->shareManager->getShareById($provider . ':' . $id, null, false);
                $this->shareManager->deleteShare($share);
                $deleted++;
                $auditRows[] = $row;
            } catch (ShareNotFound) {
                // Provider app disabled, or the row is gone already.
                $fallbackIds[] = $id;
            } catch (LockedException|StorageNotAvailableException $e) {
                // Transient: a lock on the underlying file, or the storage
                // backend being momentarily unreachable. The row was never
                // touched, so it must NOT go through the fallback delete —
                // that would permanently bypass OCM/events for a share that
                // isn't actually broken. Report it as failed; the caller can
                // retry.
                $this->logger->warning(
                    'Share {id} could not be revoked (retryable): {exception}',
                    ['id' => $id, 'exception' => $e],
                );
                $failedIds[] = $id;
            } catch (\Throwable $e) {
                // The manager's own deleteShare() deletes the row *before*
                // its post-delete steps (e.g. promoteReshares(), which
                // resolves the owner's user folder and can throw for a share
                // whose owner account no longer exists at all — exactly the
                // "deleted" orphan-share case this feature targets). So a
                // throw here doesn't necessarily mean the row survived;
                // check for real rather than assuming either way.
                if ($this->shareRowExists($id)) {
                    $fallbackIds[] = $id;
                } else {
                    $this->logger->warning(
                        'Share {id} was deleted, but a post-delete step failed (owner account likely gone)',
                        ['id' => $id, 'exception' => $e],
                    );
                    $deleted++;
                    $auditRows[] = $row;
                }
            }
        }

        if ($fallbackIds !== []) {
            $this->logger->warning(
                'Share revoke fell back to a direct DB delete for {count} share(s); federated ' .
                'unshare, ShareDeletedEvent and provider cleanup were skipped for ids: {ids}',
                ['count' => count($fallbackIds), 'ids' => implode(',', $fallbackIds)],
            );
            $deleted += $this->deleteDirect($fallbackIds);
            foreach ($rows as $row) {
                if (in_array((int)$row['id'], $fallbackIds, true)) {
                    $auditRows[] = $row;
                }
            }
        }

        $this->auditLogger->logRevoke($auditRows);

        $owners = array_unique(array_map(
            static fn (array $row) => (string)($row['uid_owner'] ?? ''),
            $auditRows,
        ));
        $this->analyzer->invalidate(...$owners);

        return ['deleted' => $deleted, 'failed' => $failedIds];
    }

    private function shareRowExists(int $id): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from('share')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        $result = $qb->executeQuery();
        $exists = $result->fetchOne() !== false;
        $result->closeCursor();
        return $exists;
    }

    /**
     * @param int[] $ids
     */
    private function deleteDirect(array $ids): int {
        // This is the one deletion path that never touches IShareManager, so
        // BeforeShareDeletedEvent (SoftDeleteListener) never fires for it —
        // capture explicitly before the row is gone. The rows given to this
        // whole call chain (see deleteRows()'s docblock) may only carry
        // id/share_type/uid_owner, so re-select full rows here rather than
        // relying on what the caller happened to pass in.
        $this->captureBeforeRawDelete($ids);

        $children = $this->db->getQueryBuilder();
        $children->delete('share')
            ->where($children->expr()->in('parent',
                $children->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
        $children->executeStatement();

        $qb = $this->db->getQueryBuilder();
        $qb->delete('share')
            ->where($qb->expr()->in('id',
                $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
        return $qb->executeStatement();
    }

    /**
     * @param int[] $ids
     */
    private function captureBeforeRawDelete(array $ids): void {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')->from('share')
            ->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        foreach ($rows as $row) {
            try {
                $this->softDelete->captureRow($row);
            } catch (\Throwable $e) {
                // Same rule as SoftDeleteListener: never let a capture
                // failure block the deletion the caller asked for.
                $this->logger->error('Soft-delete capture failed for share {id} (direct-delete fallback): {exception}', [
                    'id' => $row['id'] ?? '?', 'exception' => $e,
                ]);
            }
        }
    }
}
