<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Unit;

use OCA\ShareAuditDashboard\Service\WorkerLock;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * WorkerLock against a real directory and real flock(): a worker holds a lock on
 * a file for as long as it works, and anybody else can tell whether it is still
 * there. A second WorkerLock in the same process stands in for another worker —
 * flock() locks belong to the open file, not to the process — and a file that
 * merely exists, unlocked, is exactly what a worker that died leaves behind.
 * That the lock really disappears when a process is killed is checked with real
 * processes in tests/Integration/OrphanFileMoveTest.
 */
class WorkerLockTest extends TestCase {

    private string $dir;

    protected function setUp(): void {
        $this->dir = sys_get_temp_dir() . '/sai-workerlock-' . bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void {
        $this->rmTree($this->dir);
    }

    private function rmTree(string $path): void {
        if (!file_exists($path)) {
            return;
        }
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->rmTree($path . '/' . $entry);
                }
            }
            rmdir($path);
            return;
        }
        unlink($path);
    }

    private function lock(?string $dataDir = null): WorkerLock {
        $config = $this->createMock(IConfig::class);
        $config->method('getSystemValueString')->with('datadirectory')->willReturn($dataDir ?? $this->dir);
        return new WorkerLock($config);
    }

    private function lockFile(int $id): string {
        return $this->dir . '/.share_audit_dashboard/locks/move-' . $id . '.lock';
    }

    public function testAWorkerHoldingItsLockIsAliveToEverybodyElse(): void {
        $worker = $this->lock();
        $other = $this->lock();

        $this->assertTrue($worker->acquire(7));

        $this->assertSame(WorkerLock::ALIVE, $other->state(7));
        $this->assertSame(WorkerLock::ALIVE, $worker->state(7));
    }

    public function testAFileNobodyHoldsIsWhatADeadWorkerLeavesBehind(): void {
        $this->lock()->acquire(7);   // creates the directory
        // A worker that died: its file is there, and nobody holds a lock on it.
        $dead = $this->lockFile(8);
        touch($dead);

        $this->assertSame(WorkerLock::DEAD, $this->lock()->state(8));
        $this->assertFileExists($dead, 'looking does not remove the evidence');
    }

    public function testWithNoFileNothingCanBeSaid(): void {
        $this->lock()->acquire(1);

        $this->assertSame(WorkerLock::UNKNOWN, $this->lock()->state(99));
    }

    public function testBeforeAnyWorkerHasEverRunThereIsNoDirectoryToLookIn(): void {
        $this->assertSame(WorkerLock::UNKNOWN, $this->lock()->state(1));
        $this->assertDirectoryDoesNotExist($this->dir . '/.share_audit_dashboard', 'looking does not create it');
    }

    public function testTheSameMoveCannotBeWorkedOnByTwoWorkers(): void {
        $first = $this->lock();
        $second = $this->lock();

        $this->assertTrue($first->acquire(7));
        $this->assertFalse($second->acquire(7), 'somebody else is already on it');
        $this->assertTrue($second->acquire(8), 'another move is another lock');
    }

    public function testFinishingRemovesTheFileAndFreesTheMove(): void {
        $worker = $this->lock();
        $worker->acquire(7);
        $this->assertFileExists($this->lockFile(7));

        $worker->release(7);

        $this->assertFileDoesNotExist($this->lockFile(7));
        $this->assertTrue($this->lock()->acquire(7), 'it can be taken again');
        // A move that is over says nothing at all: not "dead".
        $this->assertSame(WorkerLock::UNKNOWN, $this->lock()->state(6));
    }

    public function testReleasingWhatWasNeverHeldDoesNothing(): void {
        $this->lock()->release(7);

        $this->assertDirectoryDoesNotExist($this->dir . '/.share_audit_dashboard');
    }

    public function testForgettingRemovesTheFileADeadWorkerLeft(): void {
        $this->lock()->acquire(1);
        touch($this->lockFile(8));

        $this->lock()->forget(8);

        $this->assertFileDoesNotExist($this->lockFile(8));
        $this->assertSame(WorkerLock::UNKNOWN, $this->lock()->state(8));
    }

    /**
     * Not being able to make a lock must not stop the move: exclusion is the
     * database's job, this only decides whether a stuck move can be recovered by
     * itself. The move's liveness is then simply unknown.
     */
    public function testAnUnwritableDirectoryDegradesToUnknownInsteadOfBlockingTheMove(): void {
        $file = $this->dir . '/not-a-directory';
        touch($file);
        $worker = $this->lock($file);

        $this->assertTrue($worker->acquire(7));
        $this->assertSame(WorkerLock::UNKNOWN, $worker->state(7));
    }

    public function testWithoutADataDirectoryNothingCanBeSaid(): void {
        $worker = $this->lock('');

        $this->assertTrue($worker->acquire(7));
        $this->assertSame(WorkerLock::UNKNOWN, $worker->state(7));
    }
}
