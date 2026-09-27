<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Unit;

use OCA\ShareAuditDashboard\Db\ShareMapper;
use OCA\ShareAuditDashboard\Service\DisplayNameResolver;
use OCA\ShareAuditDashboard\Service\RecipientDetailsResolver;
use OCA\ShareAuditDashboard\Service\RecipientLookupService;
use OCA\ShareAuditDashboard\Service\ShareCollectorService;
use OCA\ShareAuditDashboard\Service\ShareDeletionService;
use OCP\IDBConnection;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ICrypto;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The access lookup lists the shares made to an account; a user also reaches
 * what was shared with their groups, which no share names them for. That part
 * is reported next to the list (`viaGroups`), so the lookup is not read as
 * "no access left" when there is some.
 */
class RecipientGroupAccessTest extends TestCase {

    private ShareMapper&MockObject $mapper;
    private IUserManager&MockObject $users;
    private IGroupManager&MockObject $groups;
    private RecipientLookupService $service;

    protected function setUp(): void {
        $this->mapper = $this->createMock(ShareMapper::class);
        $this->users = $this->createMock(IUserManager::class);
        $this->groups = $this->createMock(IGroupManager::class);

        $collector = $this->createMock(ShareCollectorService::class);
        $collector->method('normalizeRow')->willReturnCallback(static fn (array $row) => ['id' => (int)$row['id'], 'owner' => (string)$row['uid_owner']]);
        $displayNames = $this->createMock(DisplayNameResolver::class);
        $displayNames->method('resolveMany')->willReturn([]);

        $this->service = new RecipientLookupService(
            $this->createMock(IDBConnection::class),
            $this->users,
            $this->groups,
            $this->mapper,
            $collector,
            $this->createMock(ShareDeletionService::class),
            $displayNames,
            $this->createMock(RecipientDetailsResolver::class),
            $this->createMock(ICrypto::class),
        );
    }

    private function group(string $name): IGroup&MockObject {
        $group = $this->createMock(IGroup::class);
        $group->method('getDisplayName')->willReturn($name);
        return $group;
    }

    public function testAUserOnlyInGroupsHasNoDirectSharesButTheGroupAccessIsReported(): void {
        $erin = $this->createMock(IUser::class);
        $this->users->method('get')->with('erin')->willReturn($erin);
        $this->groups->method('getUserGroupIds')->with($erin)->willReturn(['finance', 'everyone', 'empty']);
        $this->groups->method('get')->willReturnMap([['finance', $this->group('Finance')], ['everyone', $this->group('Everyone')]]);
        $this->mapper->method('countShares')->willReturn(0);
        $this->mapper->method('findShares')->willReturn([]);
        $this->mapper->expects($this->once())->method('countGroupSharesByGroup')
            ->with(['finance', 'everyone', 'empty'])
            ->willReturn(['finance' => 3, 'everyone' => 5]);

        $result = $this->service->getShares('erin', IShare::TYPE_USER);

        $this->assertSame(0, $result['total']);
        $this->assertSame([
            ['shareWith' => 'everyone', 'label' => 'Everyone', 'count' => 5],
            ['shareWith' => 'finance', 'label' => 'Finance', 'count' => 3],
        ], $result['viaGroups']);
    }

    public function testAGroupHasNoGroupAccessSectionOfItsOwn(): void {
        $this->mapper->method('findShares')->willReturn([]);
        $this->mapper->expects($this->never())->method('countGroupSharesByGroup');

        $result = $this->service->getShares('finance', IShare::TYPE_GROUP);

        $this->assertSame([], $result['viaGroups']);
    }

    public function testADeletedAccountHasNoGroupsToReport(): void {
        $this->users->method('get')->willReturn(null);
        $this->mapper->method('findShares')->willReturn([]);
        $this->mapper->expects($this->never())->method('countGroupSharesByGroup');

        $this->assertSame([], $this->service->getShares('gone', IShare::TYPE_USER)['viaGroups']);
    }
}
