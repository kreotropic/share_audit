<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Unit;

use OCA\ShareAuditDashboard\Db\ShareMapper;
use OCA\ShareAuditDashboard\Service\DisplayNameResolver;
use OCA\ShareAuditDashboard\Service\OrphanShareService;
use OCA\ShareAuditDashboard\Service\RecipientDetailsResolver;
use OCA\ShareAuditDashboard\Service\ShareCollectorService;
use OCA\ShareAuditDashboard\Service\ShareDeletionService;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers getOrphanShares()'s canTransfer/orphanReason derivation (issue #21):
 * an orphan share's owner being gone is always true by construction of this
 * list, but the file it points at can ALSO be gone — a separate, more
 * actionable condition that must not be confused with the first (revoking is
 * fine either way; transferring only makes sense when there is a file left
 * to hand over).
 */
class OrphanShareServiceTest extends TestCase {

    private ShareMapper&MockObject $mapper;
    private ShareCollectorService&MockObject $collector;
    private ArrayCache $cache;
    private OrphanShareService $service;

    protected function setUp(): void {
        $this->mapper = $this->createMock(ShareMapper::class);
        $this->collector = $this->createMock(ShareCollectorService::class);
        $this->collector->method('redactRoomTokens')->willReturnArgument(0);
        $displayNames = $this->createMock(DisplayNameResolver::class);
        $displayNames->method('resolveMany')->willReturn([]);
        $recipientDetails = $this->createMock(RecipientDetailsResolver::class);
        $recipientDetails->method('decorate')->willReturnArgument(0);

        $cacheFactory = $this->createMock(ICacheFactory::class);
        $this->cache = new ArrayCache();
        $cacheFactory->method('createDistributed')->willReturn($this->cache);

        $this->service = new OrphanShareService(
            $this->createMock(IDBConnection::class),
            $this->createMock(IUserManager::class),
            $this->mapper,
            $this->collector,
            $this->createMock(ShareDeletionService::class),
            $displayNames,
            $recipientDetails,
            $cacheFactory,
        );

        // Bypasses computeOrphanOwners()'s own DB queries entirely — this
        // test is about what happens to a row once its owner is already
        // known orphaned, not about how that set gets computed.
        $this->cache->set('orphan-owners', ['gone' => 'deleted'], 90);
        $this->mapper->method('countShares')->willReturn(1);
    }

    private function normalizedRow(bool $sourceExists): array {
        return [
            'id' => 1, 'owner' => 'gone', 'type' => 3, 'category' => 'link',
            'sourceExists' => $sourceExists,
        ];
    }

    public function testAnOrphanWithAnExistingSourceCanBeTransferred(): void {
        $this->mapper->method('findShares')->willReturn([['id' => 1, 'uid_owner' => 'gone']]);
        $this->collector->method('normalizeRow')->willReturn($this->normalizedRow(true));

        $item = $this->service->getOrphanShares(1, 25)['items'][0];

        $this->assertTrue($item['canTransfer']);
        $this->assertSame('owner_deleted', $item['orphanReason']);
    }

    public function testAnOrphanWhoseSourceIsGoneCannotBeTransferred(): void {
        $this->mapper->method('findShares')->willReturn([['id' => 1, 'uid_owner' => 'gone']]);
        $this->collector->method('normalizeRow')->willReturn($this->normalizedRow(false));

        $item = $this->service->getOrphanShares(1, 25)['items'][0];

        $this->assertFalse($item['canTransfer']);
        $this->assertSame('source_missing', $item['orphanReason']);
    }
}
