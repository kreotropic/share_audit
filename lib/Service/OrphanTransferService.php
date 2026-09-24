<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Service;

use OCA\ShareAuditDashboard\Db\ShareMapper;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUserManager;
use OCP\Share\Events\ShareTransferredEvent;
use OCP\Share\IManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Hands orphan shares (owner disabled or deleted) to another account instead of
 * revoking them — for when someone leaves and a colleague takes their work over.
 *
 * Only the ownership moves; the file does not. A share owned by somebody is
 * resolved through *that* person's file tree, so it keeps working only if the
 * new owner already reaches the same file there: a Team Folder they belong to,
 * an external storage they can see. A file that lives in the departed user's own
 * home is out of their reach — those shares are skipped, with the reason. For a
 * disabled owner, OrphanFileMoveService moves those files (and the shares with
 * them); for a deleted one they are gone.
 *
 * The checks are the ones IShareManager applies when a share is created
 * (reachable, shareable, no wider permissions than the owner has), and the
 * update follows `occ files:transfer-ownership`: the creator only changes when
 * it was the departed owner. It is a direct oc_share update rather than
 * IShareManager::updateShare(), which takes a share with a disabled owner only
 * when told `onlyValid: false` — a parameter confirmed on Nextcloud 33, while
 * the app supports 31 to 35.
 */
class OrphanTransferService {

    /**
     * Types owned by the default share provider, whose owner column is the
     * whole story. The rest keep state elsewhere (a federated share's remote
     * server, a Talk room, a mail token) that a local update would not reach.
     */
    private const SUPPORTED_TYPES = [IShare::TYPE_USER, IShare::TYPE_GROUP, IShare::TYPE_LINK];

    /** The share is gone, or its owner is an active account again. */
    public const SKIP_NOT_ORPHAN = 'not_orphan';
    /** The shared file or folder no longer exists — see issue #21. */
    public const SKIP_SOURCE_MISSING = 'source_missing';
    /** Federated, Talk, mail, ... shares. */
    public const SKIP_UNSUPPORTED_TYPE = 'unsupported_type';
    /** The new owner is the person the share is for. */
    public const SKIP_RECIPIENT = 'recipient_is_new_owner';
    /** The new owner's file tree does not contain the file. */
    public const SKIP_NO_ACCESS = 'no_access';
    /** They can see the file but are not allowed to share it. */
    public const SKIP_NOT_SHAREABLE = 'not_shareable';
    /** The share grants more than they may. */
    public const SKIP_PERMISSIONS = 'insufficient_permissions';

    public function __construct(
        private ShareMapper $mapper,
        private OrphanShareService $orphans,
        private IUserManager $userManager,
        private FileNodeResolver $nodes,
        private ShareAuditLogger $auditLogger,
        private SecurityAnalyzerService $analyzer,
        private IManager $shareManager,
        private IEventDispatcher $eventDispatcher,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Enabled accounts matching $search by user id or display name, for the
     * new-owner picker. A disabled account is left out: handing shares to one
     * would just create the next orphan.
     *
     * @return array<int, array{uid: string, displayName: string}>
     */
    public function searchTargets(string $search, int $limit = 20): array {
        $limit = max(1, min(50, $limit));

        $users = [];
        foreach ([
            $this->userManager->searchDisplayName($search, $limit),
            $this->userManager->search($search, $limit),
        ] as $found) {
            foreach ($found as $user) {
                $users[$user->getUID()] ??= $user;
            }
        }

        $items = [];
        foreach ($users as $uid => $user) {
            if ($user->isEnabled()) {
                $items[] = ['uid' => (string)$uid, 'displayName' => $user->getDisplayName()];
            }
        }
        usort($items, static fn (array $a, array $b) => strcasecmp($a['displayName'], $b['displayName']));

        return array_slice($items, 0, $limit);
    }

    /**
     * Give the selected shares to $newOwnerId — but only those *currently*
     * owned by an orphaned account, checked against a fresh (not cached)
     * orphan set exactly as revoke() does, so a stale selection can never move
     * a share of an active user.
     *
     * Each share stands on its own: one that cannot move is reported with the
     * reason and the others carry on.
     *
     * @param int[] $ids
     * @return array{transferred: int, skipped: array<int, array{id: int, reason: string}>, failed: int[]}
     *         failed ids hit an unexpected error and were left as they were
     * @throws \InvalidArgumentException when $newOwnerId is not an enabled account
     */
    public function transfer(array $ids, string $newOwnerId): array {
        $target = $this->userManager->get($newOwnerId);
        if ($target === null || !$target->isEnabled()) {
            throw new \InvalidArgumentException('The new owner must be an existing, enabled account.');
        }
        $newOwner = $target->getUID();

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($v) => $v > 0)));
        if ($ids === []) {
            return ['transferred' => 0, 'skipped' => [], 'failed' => []];
        }

        $orphanOwners = $this->orphans->getOrphanOwners(true);
        $rows = [];
        foreach ($this->mapper->findTransferCandidates($ids) as $row) {
            $rows[(int)$row['id']] = $row;
        }

        $moved = [];
        $skipped = [];
        $failed = [];
        foreach ($ids as $id) {
            $row = $rows[$id] ?? null;
            if ($row === null || !isset($orphanOwners[(string)$row['uid_owner']])) {
                $skipped[] = ['id' => $id, 'reason' => self::SKIP_NOT_ORPHAN];
                continue;
            }

            try {
                $reason = $this->blockedReason($row, $newOwner);
                if ($reason !== null) {
                    $skipped[] = ['id' => $id, 'reason' => $reason];
                } elseif ($this->mapper->reassignOwner($id, (string)$row['uid_owner'], $newOwner)) {
                    $moved[] = $row;
                    $this->announce($id);
                } else {
                    // Its owner changed between the check and the update.
                    $skipped[] = ['id' => $id, 'reason' => self::SKIP_NOT_ORPHAN];
                }
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Share {id} could not be transferred: {exception}',
                    ['id' => $id, 'exception' => $e],
                );
                $failed[] = $id;
            }
        }

        if ($moved !== []) {
            $this->auditLogger->logTransfer($moved, $newOwner);
            $this->analyzer->invalidate(...array_values(array_unique(array_merge(
                [$newOwner],
                array_map(static fn (array $r) => (string)$r['uid_owner'], $moved),
                array_map(static fn (array $r) => (string)($r['uid_initiator'] ?? ''), $moved),
            ))));
        }

        return ['transferred' => count($moved), 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * Why $row cannot go to $newOwner, or null when it can.
     *
     * The UI is expected to already hide "Transfer" for a row whose
     * `sourceExists` is false (see OrphanShareService::getOrphanShares()),
     * but that is a hint, not a gate: this re-checks the same fact — via the
     * cheap `source_exists` join (see ShareMapper::findTransferCandidates()),
     * not by trying to resolve the file against $newOwner's tree first — so
     * a direct API call, or a stale client, can never end up transferring a
     * share whose file is simply gone. Checked before SKIP_NO_ACCESS, which
     * is specifically about the *new* owner's reach and would otherwise be a
     * misleading reason for a file nobody can reach any more.
     *
     * @param array<string, mixed> $row
     */
    private function blockedReason(array $row, string $newOwner): ?string {
        if (isset($row['source_exists']) && (int)$row['source_exists'] === 0) {
            return self::SKIP_SOURCE_MISSING;
        }

        $type = (int)$row['share_type'];
        if (!in_array($type, self::SUPPORTED_TYPES, true)) {
            return self::SKIP_UNSUPPORTED_TYPE;
        }
        if ($type === IShare::TYPE_USER && (string)($row['share_with'] ?? '') === $newOwner) {
            return self::SKIP_RECIPIENT;
        }

        $fileId = (int)($row['file_source'] ?? 0);
        $node = $fileId > 0 ? $this->nodes->resolve($newOwner, $fileId) : null;
        if ($node === null) {
            return self::SKIP_NO_ACCESS;
        }
        if (!$node->isShareable()) {
            return self::SKIP_NOT_SHAREABLE;
        }
        if (((int)$row['permissions'] & ~$node->getPermissions()) !== 0) {
            return self::SKIP_PERMISSIONS;
        }
        return null;
    }

    /**
     * Tell the rest of Nextcloud (Nextcloud 33+, where the event exists) that
     * the share changed hands, as `occ files:transfer-ownership` does — the
     * Files sharing app refreshes its recipients' mounts from it. Best effort:
     * the transfer is already done, and a listener's failure must not turn it
     * into an error.
     */
    private function announce(int $id): void {
        if (!class_exists(ShareTransferredEvent::class)) {
            return;
        }
        try {
            // onlyValid=false, as in ShareDeletionService: the manager would
            // otherwise refuse a share whose file it cannot resolve yet.
            // Transfer only ever supports user/group/link shares (see
            // transfer()), all served by the default provider — see
            // ShareProviderResolver::OCINTERNAL.
            $share = $this->shareManager->getShareById(ShareProviderResolver::OCINTERNAL . ':' . $id, null, false);
            $this->eventDispatcher->dispatchTyped(new ShareTransferredEvent($share));
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Share {id} was transferred, but announcing it failed: {exception}',
                ['id' => $id, 'exception' => $e],
            );
        }
    }
}
