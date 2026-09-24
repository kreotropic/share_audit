<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Service;

use OCA\ShareAuditDashboard\Db\DeletedShare;
use OCA\ShareAuditDashboard\Db\DeletedShareMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
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
        private RecipientDetailsResolver $recipientDetails,
        private ShareAuditLogger $auditLogger,
        private PasswordGeneratorService $passwords,
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
        $entity->setSourceExistsAtDeletion($this->nodeStillExists($share));
        $this->finishCapture($entity);
    }

    /**
     * Whether $share's file/folder still resolves right now — see issue
     * #21: the owner being gone (which is why this share exists in the
     * recycle bin at all here) is a different problem from the file itself
     * also being gone, and restoring a DB row can only ever fix the first.
     * Only a definite NotFoundException counts as "gone" — anything else
     * (storage momentarily unreachable, ...) is not evidence of that and
     * defaults to "assume it still exists" rather than mislabel it.
     */
    private function nodeStillExists(IShare $share): bool {
        try {
            $share->getNode();
            return true;
        } catch (NotFoundException) {
            return false;
        } catch (\Throwable) {
            return true;
        }
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
        // The raw oc_share row: the label is in `label` (`share_name` is a
        // legacy column that is always NULL), and shareaudit_deleted.share_name
        // is where the label is kept — same as captureShare() does via getLabel().
        $entity->setShareName(($row['label'] ?? '') !== '' ? (string)$row['label'] : null);
        $entity->setExpiration($row['expiration'] ?? null);
        $entity->setStime(isset($row['stime']) ? (int)$row['stime'] : null);
        $entity->setSourceExistsAtDeletion(
            $this->fileExistsInCache(isset($row['file_source']) ? (int)$row['file_source'] : null),
        );
        $this->finishCapture($entity);
    }

    /**
     * captureRow()'s raw-row path has no live IShare/Node to ask (see its
     * own docblock) — a direct, cheap oc_filecache lookup, same signal as
     * ShareMapper::findShares()'s source_exists column.
     */
    private function fileExistsInCache(?int $fileId): bool {
        if ($fileId === null || $fileId <= 0) {
            return false;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('fileid')
            ->from('filecache')
            ->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $exists = $result->fetchOne() !== false;
        $result->closeCursor();
        return $exists;
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
     * $canSeeTokens follows AccessScope::canSeeTokens() — see
     * RecipientDetailsResolver::redactRoomTokens() for why a Talk
     * conversation's bare token is redacted the same way a public link's
     * would be.
     *
     * @return array{items: array<int, array<string, mixed>>, total: int, page: int, limit: int}
     */
    public function list(int $page, int $limit, bool $canSeeTokens = true): array {
        $page = max(1, $page);
        $limit = max(0, min(500, $limit));
        $offset = ($page - 1) * $limit;

        $items = $this->recipientDetails->decorate(array_map([$this, 'normalize'], $this->mapper->findPage($limit, $offset)));

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

        if (!$canSeeTokens) {
            $items = $this->recipientDetails->redactRoomTokens($items);
        }

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
     * already the original hash/token, passing them would double-hash the
     * password and always mint a new link URL. So instead the share is
     * created with a fresh token and then those two columns are overwritten
     * with a raw UPDATE.
     *
     * A share that HAD a password is never allowed to exist without one, not
     * even for the moment between the creation and that UPDATE: it is created
     * with a strong random temporary password (only ever held in memory, the
     * provider hashes it) and the UPDATE swaps the original hash back in. So
     * an interruption in between, or a failed clean-up after it, leaves a link
     * nobody can open — never an open one — and the instance's "passwords are
     * enforced" rule, which refuses a creation with no password, does not
     * turn a restore of a protected link into a failure either.
     *
     * If the original token can no longer be kept — another share has taken
     * it while this one sat in the bin (see restoreRawColumns()) — and the
     * original share had no password, the share still exists, just with a
     * fresh token — reported back as `tokenChanged` so the caller can warn the
     * owner: a new link URL is a minor inconvenience, not a security
     * regression. But if a password WAS set, the share would come back with a
     * password nobody knows instead of the one it had — so this is treated as
     * a full failure (`reason: 'password_lost'`): the just-created share is
     * undone and the original retention entry is kept untouched.
     *
     * $reason on failure is a stable code (not the human-readable $message,
     * which is English-only and meant for logs) — 'not_found' | 'file_missing'
     * | 'create_failed' | 'password_lost' — so the frontend can show its own
     * translated, specific message instead of one generic string regardless
     * of cause.
     *
     * @return array{success: bool, id?: int, tokenChanged?: bool, expirationCleared?: bool, reason?: string, message?: string}
     */
    public function restore(int $id): array {
        try {
            $entity = $this->mapper->find($id);
        } catch (DoesNotExistException) {
            return ['success' => false, 'reason' => 'not_found', 'message' => 'Not found.'];
        }

        $node = $this->resolveNode($entity);
        if ($node === null) {
            return ['success' => false, 'reason' => 'file_missing', 'message' => 'The original file no longer exists.'];
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
        // See the docblock: protected from the first instant, then the
        // original hash goes back in restoreRawColumns().
        if ($entity->getPassword() !== null) {
            $share->setPassword($this->passwords->generate());
        }

        // A stored expiration already in the past — easily possible: the
        // retention window (30 days by default) can outlast a short original
        // expiration, and a share flagged "already expired" by the security
        // alerts before it was revoked is exactly this case — is rejected
        // outright by IShareManager::createShare() ("the expiration date is
        // in the past"), which used to fail the *whole* restore over a date
        // that no longer protects anything anyway. Restore without one
        // instead; the link naturally resurfaces as a "no expiration" alert
        // if that rule is enabled, same as an unparsable stored date always
        // already did silently — both are now reported via
        // $expirationCleared so the caller can tell the admin.
        $expirationCleared = false;
        if ($entity->getExpiration() !== null) {
            $expirationDate = $this->parseStoredExpiration($entity->getExpiration());
            if ($expirationDate !== null && $expirationDate > new \DateTime()) {
                $share->setExpirationDate($expirationDate);
            } else {
                $expirationCleared = true;
            }
        }

        try {
            $created = $this->shareManager->createShare($share);
        } catch (\Throwable $e) {
            $this->logger->warning('Soft-delete restore failed for retention id {id}: {exception}', [
                'id' => $id, 'exception' => $e,
            ]);
            return ['success' => false, 'reason' => 'create_failed', 'message' => 'Could not recreate the share (recipient or permissions no longer valid?).'];
        }

        $tokenRestored = $this->restoreRawColumns((int)$created->getId(), $entity);
        if (!$tokenRestored && $entity->getPassword() !== null) {
            // The share exists with the temporary password, not the one it
            // had before revocation (most likely cause: its original token
            // was reused by a different link created while this one sat in
            // the bin, and the token and password go back in one statement,
            // so either both landed or neither did). Handing that back, a
            // link whose password its owner does not know, is worse than the
            // restore simply failing — undo the
            // share we just created (a raw delete: IShareManager::
            // deleteShare() would re-capture it right back into the bin
            // we're restoring FROM) and keep the original retention entry so
            // the admin can retry once the token conflict clears.
            $this->undoRestoredShare((int)$created->getId());
            return [
                'success' => false,
                'reason' => 'password_lost',
                'message' => 'Could not restore the share\'s password protection (its original link token was likely reused by another share). Nothing was changed — retry once the conflicting share is gone.',
            ];
        }
        $this->mapper->delete($entity);
        $this->auditLogger->logRestore(
            (int)$created->getId(),
            $entity->getOriginalShareId(),
            $entity->getShareType(),
            $entity->getUidOwner(),
        );
        // The restored share is exactly as risky as it was before revocation
        // (no password/expiration carry no less risk back) — without this,
        // the alerts list/badge stays stale for up to CACHE_TTL seconds.
        $this->analyzer->invalidate($entity->getUidOwner(), $entity->getUidInitiator());

        return [
            'success' => true,
            'id' => (int)$created->getId(),
            'tokenChanged' => !$tokenRestored,
            'expirationCleared' => $expirationCleared,
        ];
    }

    private function parseStoredExpiration(string $raw): ?\DateTime {
        try {
            return new \DateTime($raw);
        } catch (\Exception) {
            return null;
        }
    }

    private function resolveNode(DeletedShare $entity): ?Node {
        if ($entity->getFileSource() === null) {
            return null;
        }
        return $this->nodeResolver->resolve($entity->getUidOwner(), $entity->getFileSource());
    }

    /**
     * Puts the original token and password hash back on the share restore() just
     * created. False when they could not be put back.
     *
     * The token is checked for being in use by another share BEFORE writing,
     * rather than trusting the database to refuse a duplicate: oc_share.token
     * has a plain, non-unique index (verified on Nextcloud 33 with MariaDB), so
     * the UPDATE would go through and leave two links answering to one URL —
     * the older one's file served under the restored one's password, or the
     * reverse. Nothing is written when it is taken. A unique index, where an
     * installation has added one, is still caught below.
     */
    private function restoreRawColumns(int $newId, DeletedShare $entity): bool {
        if ($entity->getToken() === null && $entity->getPassword() === null) {
            return true;
        }
        // An empty token (a Talk or Deck row has one) is not a URL another share can be on.
        if ($entity->getToken() !== null && $entity->getToken() !== '' && $this->tokenInUse($entity->getToken(), $newId)) {
            $this->logger->info('The original token of restored share {id} is now used by another share, keeping a new one', [
                'id' => $newId,
            ]);
            return false;
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
     * Whether a share other than $exceptId already answers to $token.
     */
    private function tokenInUse(string $token, int $exceptId): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from('share')
            ->where($qb->expr()->eq('token', $qb->createNamedParameter($token)))
            ->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($exceptId, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);
        $result = $qb->executeQuery();
        $inUse = $result->fetchOne() !== false;
        $result->closeCursor();
        return $inUse;
    }

    /**
     * Undo a share creation that restore() must not keep — a raw DELETE
     * rather than IShareManager::deleteShare(), which would fire
     * BeforeShareDeletedEvent and hand this exact row right back to
     * SoftDeleteListener, creating a second, redundant recycle-bin entry for
     * a share that never really existed from the caller's point of view.
     * Safe here specifically because the share was *just* created moments
     * ago in this same request and nothing has had a chance to depend on it
     * yet — this is not a general-purpose delete.
     *
     * If even this fails the share is still there, but with the temporary
     * password it was created with: closed to everybody, and logged so the
     * admin can find and remove it. Nothing here may throw, or the caller would
     * report a crash instead of the failure it is already reporting.
     */
    private function undoRestoredShare(int $id): void {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->delete('share')
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
            $qb->executeStatement();
        } catch (\Throwable $e) {
            $this->logger->error('Could not undo the half-restored share {id}; it stays behind protected by a temporary password nobody knows and should be deleted: {exception}', [
                'id' => $id, 'exception' => $e,
            ]);
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
        // Once this runs, no copy of the share is left anywhere in the app
        // (unlike a revoke, still recoverable from this same bin) — the
        // actual point of no return, and the one this app can't undo.
        $this->auditLogger->logPurge([$entity->getOriginalShareId()]);
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
            // null for an entry captured before this field existed — the
            // frontend treats that the same as true (see issue #21):
            // "unknown" is not evidence the file is gone, and restore()
            // still re-checks for real regardless.
            'sourceExistsAtDeletion' => $e->getSourceExistsAtDeletion(),
        ];
    }
}
