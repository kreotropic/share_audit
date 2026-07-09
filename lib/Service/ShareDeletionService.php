<?php

declare(strict_types=1);

namespace OCA\ShareAuditDashboard\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
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
    ) {
    }

    /**
     * @param array<int, array{id: int|string, share_type: int|string, uid_owner?: string}> $rows
     * @return int number of shares deleted
     */
    public function deleteRows(array $rows): int {
        $deleted = 0;
        $fallbackIds = [];
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

        return $deleted;
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
}
