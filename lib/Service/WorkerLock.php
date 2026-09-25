<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Service;

use OCP\IConfig;

/**
 * Tells whether the worker process that is moving files is still alive — the
 * one thing a move's database row cannot say.
 *
 * A move marked "running" holds its receiving account (see FileMoveMapper::
 * claim()), so if its worker dies (a fatal error, `kill -9`, the container
 * restarted) the row stays "running" and nothing else can move files into that
 * account. Freeing it by *age* is not safe: no time limit tells a dead worker
 * from one that is merely moving a very large folder, and letting a second move
 * in while the first still runs is the data loss the lock exists to prevent.
 *
 * So a worker holds an exclusive `flock()` on a small file of its own for as
 * long as it works on a move. The operating system drops that lock the instant
 * the process ends, by whatever means, so:
 *  - the lock can be taken by somebody else  =>  the worker is gone (DEAD);
 *  - it cannot                                =>  the worker is alive;
 *  - there is no file to look at              =>  nothing can be said (UNKNOWN).
 * No PID or host name is involved, so a reused PID cannot fool it and a cron
 * container next to the web container (which share the data directory, not
 * the process table) is covered.
 *
 * It needs every worker to see the same directory — the data directory, which
 * the Files app requires them to share anyway. Where that is not true, or the
 * directory cannot be written, the answer is UNKNOWN and the move is left
 * alone: it can still be freed explicitly by an admin.
 */
class WorkerLock {

    public const ALIVE = 'alive';
    public const DEAD = 'dead';
    public const UNKNOWN = 'unknown';

    /** @var array<int, array{0: resource, 1: string}> the locks this process holds, by move id */
    private array $held = [];

    public function __construct(
        private IConfig $config,
    ) {
    }

    /**
     * Start holding the lock for move $id. Take it *before* claiming the move:
     * then every row that says "running" has had one, and a worker that died
     * leaves its file behind, unlocked, as the proof.
     *
     * @return bool false only when somebody else holds this move's lock — the
     *              move is being worked on elsewhere and must not be run here.
     *              True otherwise, also when no lock could be made at all (the
     *              directory is not writable): that only makes the move's
     *              liveness UNKNOWN later, it must not stop the move.
     */
    public function acquire(int $id): bool {
        $path = $this->path($id, true);
        if ($path === null) {
            return true;
        }
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            return true;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
            fclose($handle);
            return !$wouldBlock;
        }
        $this->held[$id] = [$handle, $path];
        return true;
    }

    /**
     * Stop holding the lock, when the move is over. Also deletes the file, which
     * only exists for the duration.
     */
    public function release(int $id): void {
        if (!isset($this->held[$id])) {
            return;
        }
        [$handle, $path] = $this->held[$id];
        unset($this->held[$id]);
        // Delete first: once it is unlocked somebody may look at it and take a
        // vanishing file for a dead worker's.
        @unlink($path);
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * Whether the worker of move $id is alive, dead or cannot be told. Never
     * takes over the lock, and never waits.
     */
    public function state(int $id): string {
        if (isset($this->held[$id])) {
            return self::ALIVE;
        }
        $path = $this->path($id, false);
        if ($path === null || !is_file($path)) {
            return self::UNKNOWN;
        }
        $handle = @fopen($path, 'c');
        if ($handle === false) {
            return self::UNKNOWN;
        }
        if (flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
            flock($handle, LOCK_UN);
            fclose($handle);
            return self::DEAD;
        }
        fclose($handle);
        return $wouldBlock ? self::ALIVE : self::UNKNOWN;
    }

    /**
     * Delete the file a dead worker left behind.
     */
    public function forget(int $id): void {
        $path = $this->path($id, false);
        if ($path !== null) {
            @unlink($path);
        }
    }

    /**
     * @param bool $create make the directory when it is missing
     */
    private function path(int $id, bool $create): ?string {
        $data = $this->config->getSystemValueString('datadirectory', '');
        if ($data === '') {
            return null;
        }
        $dir = rtrim($data, '/') . '/.share_audit_dashboard/locks';
        if (!is_dir($dir)) {
            if (!$create || (!@mkdir($dir, 0770, true) && !is_dir($dir))) {
                return null;
            }
        }
        return $dir . '/move-' . $id . '.lock';
    }
}
