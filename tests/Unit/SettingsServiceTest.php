<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Unit;

use OCA\ShareAuditDashboard\Service\ShareAuditLogger;
use OCA\ShareAuditDashboard\Service\SettingsService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * saveSettings() must land an audit-log entry whenever anything actually
 * changed — in particular the auditor-groups list, since that decides who
 * gets read-only access to every user's shares on the instance.
 *
 * IAppConfig is faked with a real in-memory array rather than a plain
 * PHPUnit stub: saveSettings() itself calls getSettings() both before and
 * after persisting, so the fake must actually remember what was set, not
 * just return a fixed value every time.
 */
class SettingsServiceTest extends TestCase {

    private IAppConfig&MockObject $config;
    private ShareAuditLogger&MockObject $auditLogger;
    private SettingsService $service;

    /** @var array<string, mixed> */
    private array $store = [];

    protected function setUp(): void {
        $this->store = [];
        $this->config = $this->createMock(IAppConfig::class);
        $this->config->method('getValueString')->willReturnCallback(
            fn (string $app, string $key, string $default) => $this->store[$key] ?? $default,
        );
        $this->config->method('setValueString')->willReturnCallback(
            function (string $app, string $key, string $value) {
                $this->store[$key] = $value;
                return true;
            },
        );
        $this->config->method('getValueInt')->willReturnCallback(
            fn (string $app, string $key, int $default) => $this->store[$key] ?? $default,
        );
        $this->config->method('setValueInt')->willReturnCallback(
            function (string $app, string $key, int $value) {
                $this->store[$key] = $value;
                return true;
            },
        );
        $this->config->method('getValueArray')->willReturnCallback(
            fn (string $app, string $key, array $default) => $this->store[$key] ?? $default,
        );
        $this->config->method('setValueArray')->willReturnCallback(
            function (string $app, string $key, array $value) {
                $this->store[$key] = $value;
                return true;
            },
        );

        $this->auditLogger = $this->createMock(ShareAuditLogger::class);
        $this->service = new SettingsService($this->config, $this->auditLogger);
    }

    public function testChangingGroupShareMinMembersIsReflectedInTheLoggedAfterState(): void {
        $this->auditLogger->expects($this->once())->method('logSettingsChanged')
            ->with(
                $this->callback(static fn (array $before) => $before['groupShareMinMembers'] === 20),
                $this->callback(static fn (array $after) => $after['groupShareMinMembers'] === 50),
            );

        $this->service->saveSettings('xlsx', ['no_password' => true], true, 50);
    }

    public function testAuditorGroupsChangeIsReflectedInTheLoggedAfterState(): void {
        $this->auditLogger->expects($this->once())->method('logSettingsChanged')
            ->with(
                $this->callback(static fn (array $before) => $before['auditorGroups'] === []),
                $this->callback(static fn (array $after) => $after['auditorGroups'] === ['auditors']),
            );

        $this->service->saveSettings('xlsx', ['no_password' => true], true, 20, 30, ['auditors']);
    }

    public function testSavingWithAuditorGroupsLeftNullDoesNotClearThem(): void {
        $this->store['auditor_groups'] = ['auditors'];
        $this->config->expects($this->never())->method('setValueArray');

        $this->auditLogger->expects($this->once())->method('logSettingsChanged')
            ->with(
                $this->callback(static fn (array $before) => $before['auditorGroups'] === ['auditors']),
                $this->callback(static fn (array $after) => $after['auditorGroups'] === ['auditors']),
            );

        // $auditorGroups left at its null default: must leave the stored
        // list untouched even though something else (groupShareMinMembers)
        // did change and does get logged.
        $this->service->saveSettings('xlsx', ['no_password' => true], true, 50);
    }

    /**
     * saveSettings() always hands both snapshots to the audit logger — even
     * for a no-op save (the admin opens Settings and hits save without
     * changing anything) — and leaves the "is this actually worth an audit
     * entry" decision to ShareAuditLogger::logSettingsChanged() itself (see
     * ShareAuditLoggerTest), which this test does not duplicate.
     */
    public function testSavingWithNothingActuallyDifferentStillHandsBothSnapshotsToTheLogger(): void {
        $this->auditLogger->expects($this->once())->method('logSettingsChanged')
            ->with(
                $this->callback(static fn (array $before) => $before['groupShareMinMembers'] === 20),
                $this->callback(static fn (array $after) => $after['groupShareMinMembers'] === 20),
            );

        $this->service->saveSettings('xlsx,xls,docx,doc,pdf,csv,sql,bak,pptx,ppt', ['no_password' => true, 'no_expiration' => true, 'sensitive_file' => true, 'group_share_editable' => true, 'public_upload' => true], true, 20, 30, []);
    }
}
