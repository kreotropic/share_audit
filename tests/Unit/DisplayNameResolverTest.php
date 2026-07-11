<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Unit;

use OCA\ShareAuditDashboard\Service\DisplayNameResolver;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DisplayNameResolverTest extends TestCase {

    private IUserManager&MockObject $userManager;
    private IGroupManager&MockObject $groupManager;
    private DisplayNameResolver $resolver;

    protected function setUp(): void {
        $this->userManager = $this->createMock(IUserManager::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->resolver = new DisplayNameResolver($this->userManager, $this->groupManager);
    }

    private function user(string $displayName): IUser&MockObject {
        $user = $this->createMock(IUser::class);
        $user->method('getDisplayName')->willReturn($displayName);
        return $user;
    }

    private function group(string $displayName): IGroup&MockObject {
        $group = $this->createMock(IGroup::class);
        $group->method('getDisplayName')->willReturn($displayName);
        return $group;
    }

    public function testResolvesToDisplayName(): void {
        $this->userManager->method('get')->with('alice')->willReturn($this->user('Alice Silva'));
        $names = $this->resolver->resolveMany(['alice']);
        $this->assertSame(['alice' => 'Alice Silva'], $names);
    }

    public function testFallsBackToUidWhenAccountIsGone(): void {
        $this->userManager->method('get')->with('ghost')->willReturn(null);
        $names = $this->resolver->resolveMany(['ghost']);
        $this->assertSame(['ghost' => 'ghost'], $names);
    }

    public function testFallsBackToUidWhenDisplayNameIsEmpty(): void {
        // Some backends can return an empty display name string.
        $this->userManager->method('get')->with('bob')->willReturn($this->user(''));
        $names = $this->resolver->resolveMany(['bob']);
        $this->assertSame(['bob' => 'bob'], $names);
    }

    public function testIgnoresNullAndEmptyUids(): void {
        $this->userManager->expects($this->never())->method('get');
        $names = $this->resolver->resolveMany([null, '', null]);
        $this->assertSame([], $names);
    }

    /**
     * The whole point of the resolver: a uid repeated across many rows
     * (e.g. one owner with many shares on a page) must only cost one
     * IUserManager::get() call, not one per row — see the class docblock.
     */
    public function testResolvesEachUniqueUidOnlyOnce(): void {
        $this->userManager->expects($this->once())->method('get')
            ->with('alice')->willReturn($this->user('Alice Silva'));

        $names = $this->resolver->resolveMany(['alice', 'alice', 'alice']);
        $this->assertSame(['alice' => 'Alice Silva'], $names);
    }

    public function testResolvesMultipleDistinctUids(): void {
        $this->userManager->method('get')->willReturnMap([
            ['alice', $this->user('Alice Silva')],
            ['bob', $this->user('Bob Santos')],
        ]);

        $names = $this->resolver->resolveMany(['alice', 'bob', 'alice']);
        $this->assertSame(['alice' => 'Alice Silva', 'bob' => 'Bob Santos'], $names);
    }

    public function testResolvesGroupsToDisplayNames(): void {
        $this->groupManager->method('get')->willReturnMap([
            ['finance-guid', $this->group('Finance')],
        ]);

        $names = $this->resolver->resolveManyGroups(['finance-guid', 'finance-guid']);
        $this->assertSame(['finance-guid' => 'Finance'], $names);
    }

    public function testGroupResolutionFallsBackToGidWhenGroupIsGone(): void {
        // A deleted group — or a Teams/circle id, which is not a group at
        // all — must fall back to the raw id, never null.
        $this->groupManager->method('get')->with('ghost-team')->willReturn(null);
        $names = $this->resolver->resolveManyGroups(['ghost-team']);
        $this->assertSame(['ghost-team' => 'ghost-team'], $names);
    }
}
