<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Unit;

use OCA\ShareAuditDashboard\Service\ShareAuditLogger;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Log\Audit\CriticalActionPerformedEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ShareAuditLoggerTest extends TestCase {

    private IEventDispatcher&MockObject $dispatcher;
    private IUserSession&MockObject $userSession;
    private ShareAuditLogger $logger;

    protected function setUp(): void {
        $this->dispatcher = $this->createMock(IEventDispatcher::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->logger = new ShareAuditLogger($this->dispatcher, $this->userSession);
    }

    private function stubActor(string $uid): void {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
    }

    // -------------------------------------------------------------------
    // logSettingsChanged() — the one method callers (SettingsService) hand
    // both snapshots to unconditionally, relying on this method itself to
    // decide whether anything actually happened.
    // -------------------------------------------------------------------

    public function testSettingsChangedDoesNothingWhenBeforeAndAfterAreIdentical(): void {
        $this->dispatcher->expects($this->never())->method('dispatchTyped');

        $settings = ['groupShareMinMembers' => 20, 'auditorGroups' => ['x']];
        $this->logger->logSettingsChanged($settings, $settings);
    }

    public function testSettingsChangedDispatchesWhenSomethingDiffers(): void {
        $this->stubActor('admin1');
        $this->dispatcher->expects($this->once())->method('dispatchTyped')
            ->with($this->isInstanceOf(CriticalActionPerformedEvent::class));

        $this->logger->logSettingsChanged(
            ['groupShareMinMembers' => 20, 'auditorGroups' => []],
            ['groupShareMinMembers' => 20, 'auditorGroups' => ['auditors']],
        );
    }

    public function testSettingsChangedMessageNamesTheCurrentAuditorGroups(): void {
        $this->stubActor('admin1');
        $captured = null;
        $this->dispatcher->method('dispatchTyped')->willReturnCallback(function ($event) use (&$captured) {
            $captured = $event;
        });

        $this->logger->logSettingsChanged(
            ['auditorGroups' => []],
            ['auditorGroups' => ['auditors', 'security-team']],
        );

        $this->assertInstanceOf(CriticalActionPerformedEvent::class, $captured);
        $this->assertSame('admin1', $captured->getParameters()['actor']);
        $this->assertStringContainsString('auditors, security-team', $captured->getParameters()['auditorGroups']);
    }

    // -------------------------------------------------------------------
    // logRestore() / logPurge() / logAcknowledge()
    // -------------------------------------------------------------------

    public function testRestoreNamesTheActorAndTheOriginalAndNewShareIds(): void {
        $this->stubActor('admin1');
        $captured = null;
        $this->dispatcher->method('dispatchTyped')->willReturnCallback(function ($event) use (&$captured) {
            $captured = $event;
        });

        $this->logger->logRestore(777, 42, 3, 'bob');

        $params = $captured->getParameters();
        $this->assertSame('admin1', $params['actor']);
        $this->assertSame('42', $params['originalId']);
        $this->assertSame('777', $params['newId']);
        $this->assertSame('bob', $params['owner']);
    }

    public function testPurgeDoesNothingForAnEmptyList(): void {
        $this->dispatcher->expects($this->never())->method('dispatchTyped');
        $this->logger->logPurge([]);
    }

    public function testPurgeNamesEveryOriginalShareId(): void {
        $this->stubActor('admin1');
        $captured = null;
        $this->dispatcher->method('dispatchTyped')->willReturnCallback(function ($event) use (&$captured) {
            $captured = $event;
        });

        $this->logger->logPurge([42, 43]);

        $this->assertSame('42,43', $captured->getParameters()['ids']);
        $this->assertSame('2', $captured->getParameters()['count']);
    }

    public function testAcknowledgeDoesNothingForAnEmptyRuleCodeList(): void {
        $this->dispatcher->expects($this->never())->method('dispatchTyped');
        $this->logger->logAcknowledge(1, [], false);
    }

    public function testAcknowledgeIncludesTheNoteOnlyWhenNotUndone(): void {
        $this->stubActor('admin1');
        $captured = null;
        $this->dispatcher->method('dispatchTyped')->willReturnCallback(function ($event) use (&$captured) {
            $captured = $event;
        });

        $this->logger->logAcknowledge(1, ['no_password'], false, 'accepted for now');
        $this->assertSame('accepted for now', $captured->getParameters()['note']);

        $this->logger->logAcknowledge(1, ['no_password'], true);
        $this->assertArrayNotHasKey('note', $captured->getParameters());
    }
}
