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
 * Falls back to a direct DB delete only when the provider itself can't be
 * loaded (its app is disabled), logging it since OCM/events/cleanup are
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
    ) {
    }

    /**
     * @param array<int, array{id: int|string, share_type: int|string}> $rows
     * @return int number of shares deleted
     */
    public function deleteRows(array $rows): int {
        $deleted = 0;
        $fallbackIds = [];

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
            } catch (ShareNotFound) {
                // Provider app disabled, or the row is gone already.
                $fallbackIds[] = $id;
            }
        }

        if ($fallbackIds !== []) {
            $this->logger->warning(
                'Share revoke fell back to a direct DB delete for {count} share(s); federated ' .
                'unshare, ShareDeletedEvent and provider cleanup were skipped for ids: {ids}',
                ['count' => count($fallbackIds), 'ids' => implode(',', $fallbackIds)],
            );
            $deleted += $this->deleteDirect($fallbackIds);
        }

        return $deleted;
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
