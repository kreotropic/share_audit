<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Service;

use OCA\ShareAuditDashboard\BackgroundJob\OrphanFileMoveJob;
use OCA\ShareAuditDashboard\Db\FileMove;
use OCA\ShareAuditDashboard\Db\FileMoveMapper;
use OCA\ShareAuditDashboard\Db\ShareMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Moves the files of a *disabled* account to another account, which is what
 * lets an orphan share whose file sits in that account's home be handed over
 * at all (OrphanTransferService only moves the ownership of the share, and
 * skips those as `no_access`). It is the same operation as
 * `occ files:transfer-ownership`, done by the Files app's own code: the file
 * or folder goes to the new owner and every share of it follows, keeping its
 * id and link token.
 *
 * Two scopes:
 *  - `path`: the files behind the selected shares, and nothing else of the
 *    account. Selected shares inside a selected folder are covered by the
 *    folder's move.
 *  - `account`: everything the account has.
 *
 * A deleted account cannot be done — its files went with it. The move runs in
 * a background job (a big folder does not fit in a request); this service
 * queues it, the job calls run(), and list() feeds the dashboard's status.
 *
 * What a move takes along is decided by the Files app, not by the selection:
 * every share of a moved file goes with it, and a moved account takes all of
 * its shares.
 */
class OrphanFileMoveService {

    public const SCOPE_PATH = 'path';
    public const SCOPE_ACCOUNT = 'account';

    /** The share is gone, or its owner is an active account again. */
    public const SKIP_NOT_ORPHAN = 'not_orphan';
    /** The owner's account was deleted: its files went with it. */
    public const SKIP_OWNER_DELETED = 'owner_deleted';
    /** The shared file or folder no longer exists. */
    public const SKIP_SOURCE_MISSING = 'source_missing';
    /** The file is not in the owner's home (Team Folder, external storage, ...). */
    public const SKIP_NOT_IN_HOME = 'not_in_home';
    /** A move already queued or running covers it. */
    public const SKIP_ALREADY_QUEUED = 'already_queued';

    /** Why a move failed, when it is not the Files app's own message. */
    public const ERROR_OWNER_MISSING = 'owner_missing';
    public const ERROR_OWNER_ACTIVE = 'owner_not_disabled';
    public const ERROR_TARGET_UNAVAILABLE = 'target_not_enabled';
    public const ERROR_INTERRUPTED = 'interrupted';
    public const ERROR_UNEXPECTED = 'unexpected_error';

    /** release(): the move was freed. */
    public const RELEASE_OK = 'released';
    /** release(): there is no such move. */
    public const RELEASE_NOT_FOUND = 'not_found';
    /** release(): the move is not running, so there is nothing to free. */
    public const RELEASE_NOT_RUNNING = 'not_running';
    /** release(): its worker is alive and still moving files, so it was left alone. */
    public const RELEASE_ALIVE = 'alive';

    /** How long a move that found its account busy waits before trying again. */
    private const RETRY_AFTER = 60;
    private const ERROR_MAX_LENGTH = 1000;

    public function __construct(
        private FileMoveMapper $moves,
        private ShareMapper $mapper,
        private OrphanShareService $orphans,
        private IUserManager $userManager,
        private IUserSession $userSession,
        private FileNodeResolver $nodes,
        private OwnershipTransferGateway $gateway,
        private DisplayNameResolver $displayNames,
        private IJobList $jobList,
        private ITimeFactory $time,
        private ShareAuditLogger $auditLogger,
        private SecurityAnalyzerService $analyzer,
        private WorkerLock $workerLock,
        private BackgroundJobsStatus $jobsStatus,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Queue the file moves the selected orphan shares call for.
     *
     * Every share is checked here, against a fresh (not cached) orphan set,
     * whatever the client thought it was selecting: a share that cannot be
     * served by a move comes back in `skipped` with the reason, and the rest
     * are queued.
     *
     * @param int[] $ids
     * @return array{
     *     queued: array<int, array{id: int, owner: string, path: ?string, scope: string, shares: int}>,
     *     skipped: array<int, array{id: int, reason: string}>
     * }
     * @throws \InvalidArgumentException for an unknown scope or a new owner that is not an enabled account
     */
    public function enqueue(array $ids, string $newOwnerId, string $scope): array {
        if (!in_array($scope, [self::SCOPE_PATH, self::SCOPE_ACCOUNT], true)) {
            throw new \InvalidArgumentException('Unknown scope.');
        }
        $target = $this->userManager->get($newOwnerId);
        if ($target === null || !$target->isEnabled()) {
            throw new \InvalidArgumentException('The new owner must be an existing, enabled account.');
        }
        $newOwner = $target->getUID();

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn ($v) => $v > 0)));
        if ($ids === []) {
            return ['queued' => [], 'skipped' => []];
        }

        $orphanOwners = $this->orphans->getOrphanOwners(true);
        $rows = [];
        foreach ($this->mapper->findTransferCandidates($ids) as $row) {
            $rows[(int)$row['id']] = $row;
        }
        $owners = array_values(array_unique(array_map(
            static fn (array $r) => (string)$r['uid_owner'],
            $rows,
        )));
        $active = [];
        foreach ($this->moves->findActiveForSources($owners) as $move) {
            $active[(string)$move->getSourceUid()][] = $move;
        }

        $skipped = [];
        // owner => path (or '' for the whole account) => share ids
        $wanted = [];
        foreach ($ids as $id) {
            $row = $rows[$id] ?? null;
            $owner = (string)($row['uid_owner'] ?? '');
            if ($row === null || !isset($orphanOwners[$owner])) {
                $skipped[] = ['id' => $id, 'reason' => self::SKIP_NOT_ORPHAN];
                continue;
            }
            if ($orphanOwners[$owner] !== 'disabled') {
                $skipped[] = ['id' => $id, 'reason' => self::SKIP_OWNER_DELETED];
                continue;
            }

            if ($scope === self::SCOPE_ACCOUNT) {
                if (isset($active[$owner])) {
                    $skipped[] = ['id' => $id, 'reason' => self::SKIP_ALREADY_QUEUED];
                    continue;
                }
                $wanted[$owner][''][] = $id;
                continue;
            }

            if (isset($row['source_exists']) && (int)$row['source_exists'] === 0) {
                $skipped[] = ['id' => $id, 'reason' => self::SKIP_SOURCE_MISSING];
                continue;
            }
            $path = $this->nodes->homeRelativePath($owner, (int)($row['file_source'] ?? 0));
            if ($path === null) {
                $skipped[] = ['id' => $id, 'reason' => self::SKIP_NOT_IN_HOME];
                continue;
            }
            if ($this->coveredByActiveMove($active[$owner] ?? [], $path)) {
                $skipped[] = ['id' => $id, 'reason' => self::SKIP_ALREADY_QUEUED];
                continue;
            }
            $wanted[$owner][$path][] = $id;
        }

        $queued = [];
        foreach ($wanted as $owner => $paths) {
            $owner = (string)$owner;
            foreach ($this->foldNestedPaths($paths) as $path => $shareIds) {
                $path = (string)$path;
                $shares = $scope === self::SCOPE_ACCOUNT
                    ? $this->mapper->countShares(['owners' => [$owner]])
                    : count($shareIds);
                $move = $this->queue($owner, $newOwner, $scope, $path === '' ? null : $path, $shares);
                $queued[] = [
                    'id' => (int)$move->getId(),
                    'owner' => $owner,
                    'path' => $move->getPath(),
                    'scope' => $scope,
                    'shares' => $shares,
                ];
            }
        }

        if ($queued !== []) {
            // What is queued still leaves the shares listed as orphans until
            // it runs, so nothing about them changes yet.
            $this->analyzer->invalidate($newOwner);
        }

        return ['queued' => $queued, 'skipped' => $skipped];
    }

    /**
     * Carry out one queued move. Called by OrphanFileMoveJob.
     *
     * The conditions are checked again here, not trusted from when the move
     * was queued: the account may have been re-enabled since, and moving the
     * files of somebody who is active again would be exactly the wrong thing.
     *
     * Only one move to a given account runs at a time, whatever the number of
     * background workers: see FileMoveMapper::claim(). A move that finds its
     * account busy puts itself back in the queue for a little later instead of
     * waiting here, and the worker goes on to other jobs. The move in its way
     * is only ever given up on when its worker is known to be dead — see
     * WorkerLock — never because it has been running for a long time.
     */
    public function run(int $id): void {
        try {
            $move = $this->moves->find($id);
        } catch (DoesNotExistException) {
            return;
        }

        // Held for as long as this worker works on the move, and taken before
        // claiming it, so that a "running" row always has one to look at.
        if (!$this->workerLock->acquire($id)) {
            return;
        }
        try {
            $this->carryOut($move);
        } finally {
            // After the row says how it ended: a lock that disappears first would
            // look like nothing at all, not like a worker that finished.
            $this->workerLock->release($id);
        }
    }

    private function carryOut(FileMove $move): void {
        $id = (int)$move->getId();
        $from = (string)$move->getSourceUid();
        $to = (string)$move->getTargetUid();
        $path = $move->getPath();

        $claim = $this->claim($id, $to);
        if ($claim === FileMoveMapper::CLAIM_BUSY) {
            $this->jobList->scheduleAfter(
                OrphanFileMoveJob::class,
                $this->time->getTime() + self::RETRY_AFTER,
                ['id' => $id],
            );
            return;
        }
        if ($claim !== FileMoveMapper::CLAIM_OK) {
            return;
        }

        $source = $this->userManager->get($from);
        $target = $this->userManager->get($to);
        $error = match (true) {
            $source === null => self::ERROR_OWNER_MISSING,
            $source->isEnabled() => self::ERROR_OWNER_ACTIVE,
            $target === null || !$target->isEnabled() => self::ERROR_TARGET_UNAVAILABLE,
            default => null,
        };

        if ($error === null) {
            try {
                $this->gateway->move($source, $target, $path ?? '');
            } catch (\Throwable $e) {
                $this->logger->warning(
                    'Moving the files of "{from}" to "{to}" failed: {exception}',
                    ['from' => $from, 'to' => $to, 'exception' => $e],
                );
                // The Files app's own refusals ("not enough space", "encrypted
                // files") say what to fix. Anything else can carry internals,
                // and is in the log.
                $error = $e instanceof \OCA\Files\Exception\TransferOwnershipException
                    ? mb_substr($e->getMessage(), 0, self::ERROR_MAX_LENGTH)
                    : self::ERROR_UNEXPECTED;
            }
        }

        $this->moves->finish(
            $id,
            $error === null ? FileMoveMapper::STATUS_DONE : FileMoveMapper::STATUS_FAILED,
            $error,
            $this->time->getTime(),
        );
        $this->auditLogger->logFileMoveFinished((string)$move->getRequestedBy(), $from, $to, $path, $error);

        if ($error === null) {
            $this->analyzer->invalidate($from, $to);
            $this->orphans->flushOwnerCache();
        }
    }

    /**
     * Free a move that is marked as running, on an administrator's word — for
     * the cases the automatic recovery cannot settle: a worker on another
     * machine that does not share the data directory, a data directory that
     * cannot be written to.
     *
     * Refused while its worker is *known* to be alive: freeing that would let a
     * second move into the account while the first still writes to it.
     *
     * @return string one of the RELEASE_* constants
     */
    public function release(int $id): string {
        try {
            $move = $this->moves->find($id);
        } catch (DoesNotExistException) {
            return self::RELEASE_NOT_FOUND;
        }
        if ($move->getStatus() !== FileMoveMapper::STATUS_RUNNING) {
            return self::RELEASE_NOT_RUNNING;
        }
        if ($this->workerLock->state($id) === WorkerLock::ALIVE) {
            return self::RELEASE_ALIVE;
        }
        if ($this->moves->abandon($id, self::ERROR_INTERRUPTED, $this->time->getTime())) {
            $this->workerLock->forget($id);
            $this->auditLogger->logFileMoveReleased(
                (string)$move->getSourceUid(),
                (string)$move->getTargetUid(),
                $move->getPath(),
            );
        }
        return self::RELEASE_OK;
    }

    /**
     * The newest moves, for the dashboard.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(int $limit = 50): array {
        $moves = $this->moves->findRecent(max(1, min(200, $limit)));
        $uids = [];
        foreach ($moves as $move) {
            $uids[] = $move->getSourceUid();
            $uids[] = $move->getTargetUid();
        }
        $names = $this->displayNames->resolveMany($uids);

        return array_map(function (FileMove $move) use ($names): array {
            $status = (string)$move->getStatus();
            $error = $move->getError();
            $from = (string)$move->getSourceUid();
            $to = (string)$move->getTargetUid();
            return [
                'id' => (int)$move->getId(),
                'sourceUid' => $from,
                'sourceDisplayName' => $names[$from] ?? $from,
                'targetUid' => $to,
                'targetDisplayName' => $names[$to] ?? $to,
                'scope' => (string)$move->getScope(),
                'path' => $move->getPath(),
                'status' => $status,
                'error' => $error,
                'shares' => (int)$move->getShareCount(),
                'requestedBy' => $move->getRequestedBy(),
                'createdAt' => (int)$move->getCreatedAt(),
                'startedAt' => $move->getStartedAt(),
                'finishedAt' => $move->getFinishedAt(),
                // Only for a move that is running: whether its worker is alive,
                // dead, or cannot be told — see WorkerLock. Decides whether the
                // dashboard offers to free it.
                'workerState' => $status === FileMoveMapper::STATUS_RUNNING
                    ? $this->workerLock->state((int)$move->getId())
                    : null,
            ];
        }, $moves);
    }

    /**
     * What the dashboard shows: the newest moves, and whether Nextcloud's
     * background jobs are running for them at all (see BackgroundJobsStatus).
     *
     * @return array{items: array<int, array<string, mixed>>, backgroundJobs: array{mode: string, lastRun: ?int, stalled: bool}}
     */
    public function overview(int $limit = 50): array {
        $items = $this->list($limit);
        $waiting = array_filter(
            $items,
            static fn (array $m) => in_array($m['status'], [FileMoveMapper::STATUS_QUEUED, FileMoveMapper::STATUS_RUNNING], true),
        );
        return ['items' => $items, 'backgroundJobs' => $this->jobsStatus->describe($waiting !== [])];
    }

    /**
     * Claim move $id for the account $to. If another move is in the way and its
     * worker is provably dead, that one is given up on and the claim tried again.
     */
    private function claim(int $id, string $to): string {
        $claim = $this->moves->claim($id, $to, $this->time->getTime());
        if ($claim !== FileMoveMapper::CLAIM_BUSY || !$this->reclaimAbandoned($to)) {
            return $claim;
        }
        return $this->moves->claim($id, $to, $this->time->getTime());
    }

    /**
     * Free the account $target of the move running into it, but only when that
     * move's worker is known to be dead (see WorkerLock). A worker that is
     * alive, or one nothing can be said about, is left alone however long it has
     * been running: the account then simply stays busy, and an administrator can
     * free it with release() if the worker is really gone.
     *
     * @return bool whether the account is free to try again
     */
    private function reclaimAbandoned(string $target): bool {
        $holder = $this->moves->findRunningFor($target);
        if ($holder === null) {
            // It finished between the refusal and now.
            return true;
        }
        $id = (int)$holder->getId();
        if ($this->workerLock->state($id) !== WorkerLock::DEAD) {
            return false;
        }
        if ($this->moves->abandon($id, self::ERROR_INTERRUPTED, $this->time->getTime())) {
            $this->workerLock->forget($id);
            $this->auditLogger->logFileMoveFinished(
                (string)$holder->getRequestedBy(),
                (string)$holder->getSourceUid(),
                (string)$holder->getTargetUid(),
                $holder->getPath(),
                self::ERROR_INTERRUPTED,
            );
        }
        return true;
    }

    private function queue(string $owner, string $newOwner, string $scope, ?string $path, int $shares): FileMove {
        $move = new FileMove();
        $move->setSourceUid($owner);
        $move->setTargetUid($newOwner);
        $move->setScope($scope);
        $move->setPath($path);
        $move->setStatus(FileMoveMapper::STATUS_QUEUED);
        $move->setShareCount($shares);
        $move->setRequestedBy($this->userSession->getUser()?->getUID());
        $move->setCreatedAt($this->time->getTime());
        $move = $this->moves->insert($move);

        $this->jobList->add(OrphanFileMoveJob::class, ['id' => (int)$move->getId()]);
        $this->auditLogger->logFileMoveQueued($owner, $newOwner, $path, $shares);

        return $move;
    }

    /**
     * Whether a move of the same owner already queued or running takes $path
     * along: it is the whole account, or the same folder or one above it.
     *
     * @param FileMove[] $active
     */
    private function coveredByActiveMove(array $active, string $path): bool {
        foreach ($active as $move) {
            $movePath = $move->getPath();
            if ($move->getScope() === self::SCOPE_ACCOUNT
                || ($movePath !== null && self::isWithin($path, $movePath))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Merge the paths that lie inside another selected path into it: moving
     * the folder moves what is in it, and moving both would try to move a
     * file that is no longer there.
     *
     * @param array<int|string, int[]> $paths path => share ids
     * @return array<int|string, int[]> the outermost paths => the ids they cover
     */
    private function foldNestedPaths(array $paths): array {
        // PHP turns a path like "2024" into an integer key.
        $keys = array_map('strval', array_keys($paths));
        // An outer path is always shorter than one inside it.
        usort($keys, static fn (string $a, string $b) => strlen($a) <=> strlen($b));

        $folded = [];
        foreach ($keys as $path) {
            $ids = $paths[$path] ?? [];
            $outer = null;
            foreach (array_keys($folded) as $candidate) {
                if (self::isWithin($path, (string)$candidate)) {
                    $outer = $candidate;
                    break;
                }
            }
            if ($outer === null) {
                $folded[$path] = $ids;
            } else {
                $folded[$outer] = array_merge($folded[$outer], $ids);
            }
        }
        return $folded;
    }

    /** $path is $ancestor itself or lies inside it. */
    private static function isWithin(string $path, string $ancestor): bool {
        return $ancestor === '' || $path === $ancestor || str_starts_with($path, $ancestor . '/');
    }
}
