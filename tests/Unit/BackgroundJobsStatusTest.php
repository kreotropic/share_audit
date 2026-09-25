<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Unit;

use OCA\ShareAuditDashboard\Service\BackgroundJobsStatus;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * A queued file move only starts when Nextcloud's job runner does. This decides
 * when the dashboard says that runner has stopped: at the same point Nextcloud's
 * own admin overview does (last run more than an hour ago), and only when there
 * is something waiting for it.
 */
class BackgroundJobsStatusTest extends TestCase {

    private const NOW = 1_800_000_000;

    private function statusAt(int|string $lastRun, string $mode = 'cron'): BackgroundJobsStatus {
        $config = $this->createMock(IAppConfig::class);
        $config->method('getValueInt')->with('core', 'lastcron', 0)->willReturn((int)$lastRun);
        $config->method('getValueString')->with('core', 'backgroundjobs_mode', 'ajax')->willReturn($mode);
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);
        return new BackgroundJobsStatus($config, $time);
    }

    public function testJobsThatRanRecentlyAreNotStalled(): void {
        $result = $this->statusAt(self::NOW - 300)->describe(true);

        $this->assertFalse($result['stalled']);
        $this->assertSame(self::NOW - 300, $result['lastRun']);
        $this->assertSame('cron', $result['mode']);
    }

    public function testJobsThatLastRanMoreThanAnHourAgoAreStalledWhenSomethingWaits(): void {
        $this->assertTrue($this->statusAt(self::NOW - 3601)->describe(true)['stalled']);
        $this->assertFalse($this->statusAt(self::NOW - 3600)->describe(true)['stalled'], 'exactly an hour is still fine, as in Nextcloud');
    }

    public function testNothingWaitingIsNothingToWarnAbout(): void {
        $this->assertFalse($this->statusAt(self::NOW - 10 * 86400)->describe(false)['stalled']);
    }

    public function testJobsThatNeverRanAreStalledAndHaveNoLastRun(): void {
        $result = $this->statusAt(0)->describe(true);

        $this->assertTrue($result['stalled']);
        $this->assertNull($result['lastRun']);
    }

    public function testAValueOfTheWrongTypeRaisesNoAlarm(): void {
        $config = $this->createMock(IAppConfig::class);
        $config->method('getValueInt')->willThrowException(new \RuntimeException('type conflict'));
        $time = $this->createMock(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);

        $result = (new BackgroundJobsStatus($config, $time))->describe(true);

        $this->assertFalse($result['stalled']);
    }
}
