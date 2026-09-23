<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Unit;

use OCA\ShareAuditDashboard\Db\Ack;
use OCA\ShareAuditDashboard\Db\AckMapper;
use OCA\ShareAuditDashboard\Service\AckService;
use OCA\ShareAuditDashboard\Service\SecurityAnalyzerService;
use OCA\ShareAuditDashboard\Service\ShareAuditLogger;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers AckService::acknowledge()/unacknowledge(): the idempotent
 * insert-vs-update choice against the unique (share_id, rule_code) index,
 * rule-code validation against SecurityAnalyzerService::ISSUE_CODES, and
 * that every mutation invalidates the analyzer cache for the affected
 * share's owner + initiator (see SecurityAnalyzerServiceTest for why that
 * matters — this is the same R2-class staleness the rest of the app already
 * guards against on every other remediation path).
 */
class AckServiceTest extends TestCase {

    private AckMapper&MockObject $mapper;
    private SecurityAnalyzerService&MockObject $analyzer;
    private IManager&MockObject $shareManager;
    private IUserSession&MockObject $userSession;
    private ShareAuditLogger&MockObject $auditLogger;
    private AckService $service;

    protected function setUp(): void {
        $this->mapper = $this->createMock(AckMapper::class);
        $this->analyzer = $this->createMock(SecurityAnalyzerService::class);
        $this->shareManager = $this->createMock(IManager::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->auditLogger = $this->createMock(ShareAuditLogger::class);

        $this->service = new AckService(
            $this->mapper,
            $this->analyzer,
            $this->shareManager,
            $this->userSession,
            $this->auditLogger,
        );
    }

    private function share(string $owner = 'alice', string $initiator = 'alice'): IShare&MockObject {
        $share = $this->createMock(IShare::class);
        $share->method('getShareOwner')->willReturn($owner);
        $share->method('getSharedBy')->willReturn($initiator);
        return $share;
    }

    private function stubCurrentUser(string $uid): void {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $this->userSession->method('getUser')->willReturn($user);
    }

    // -------------------------------------------------------------------
    // acknowledge()
    // -------------------------------------------------------------------

    public function testAcknowledgeInsertsANewRowPerRuleCodeWhenNoneExistYet(): void {
        $this->stubCurrentUser('admin1');
        $this->shareManager->method('getShareById')->with('ocinternal:42')->willReturn($this->share());
        $this->mapper->method('findOneByShareAndRule')->willReturn(null);

        $inserted = [];
        $this->mapper->expects($this->exactly(2))->method('insert')
            ->willReturnCallback(function (Ack $ack) use (&$inserted) {
                $inserted[] = $ack;
                return $ack;
            });
        $this->mapper->expects($this->never())->method('update');

        $this->service->acknowledge(42, ['no_password', 'sensitive_file'], 'newsletter link, intentional');

        $this->assertCount(2, $inserted);
        $this->assertSame(42, $inserted[0]->getShareId());
        $this->assertSame('no_password', $inserted[0]->getRuleCode());
        $this->assertSame('admin1', $inserted[0]->getAcknowledgedBy());
        $this->assertSame('newsletter link, intentional', $inserted[0]->getNote());
        $this->assertSame('sensitive_file', $inserted[1]->getRuleCode());
    }

    public function testAcknowledgeUpdatesRatherThanDuplicatesAnExistingRow(): void {
        $this->stubCurrentUser('admin1');
        $this->shareManager->method('getShareById')->willReturn($this->share());

        $existing = new Ack();
        $existing->setId(7);
        $existing->setShareId(42);
        $existing->setRuleCode('no_password');
        $existing->setAcknowledgedBy('admin0');
        $existing->setAcknowledgedAt(1000);
        $this->mapper->method('findOneByShareAndRule')->willReturn($existing);

        $this->mapper->expects($this->never())->method('insert');
        $this->mapper->expects($this->once())->method('update')
            ->with($this->callback(function (Ack $ack) {
                return $ack->getId() === 7 && $ack->getAcknowledgedBy() === 'admin1';
            }));

        $this->service->acknowledge(42, ['no_password']);
    }

    public function testAcknowledgeInvalidatesTheAnalyzerCacheForOwnerAndInitiator(): void {
        $this->stubCurrentUser('admin1');
        $this->shareManager->method('getShareById')->willReturn($this->share('alice', 'bob'));
        $this->mapper->method('findOneByShareAndRule')->willReturn(null);

        $this->analyzer->expects($this->once())->method('invalidate')->with('alice', 'bob');

        $this->service->acknowledge(42, ['no_password']);
    }

    public function testAcknowledgeRecordsAnAuditLogEntry(): void {
        $this->stubCurrentUser('admin1');
        $this->shareManager->method('getShareById')->willReturn($this->share('alice', 'bob'));
        $this->mapper->method('findOneByShareAndRule')->willReturn(null);

        $this->auditLogger->expects($this->once())->method('logAcknowledge')
            ->with(42, ['no_password'], false, 'seen it, fine');

        $this->service->acknowledge(42, ['no_password'], 'seen it, fine');
    }

    public function testAcknowledgeRejectsAnEmptyRuleCodeList(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->acknowledge(42, []);
    }

    public function testAcknowledgeRejectsAnUnknownRuleCode(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->acknowledge(42, ['not_a_real_rule']);
    }

    public function testAcknowledgeRejectsAnUnknownRuleCodeBeforeTouchingTheDatabase(): void {
        $this->mapper->expects($this->never())->method('findOneByShareAndRule');
        $this->mapper->expects($this->never())->method('insert');
        try {
            $this->service->acknowledge(42, ['no_password', 'bogus']);
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            // expected
        }
    }

    public function testAcknowledgePropagatesShareNotFound(): void {
        $this->shareManager->method('getShareById')->willThrowException(new ShareNotFound());
        $this->expectException(ShareNotFound::class);
        $this->service->acknowledge(42, ['no_password']);
    }

    // -------------------------------------------------------------------
    // unacknowledge()
    // -------------------------------------------------------------------

    public function testUnacknowledgeDeletesEveryGivenRuleCode(): void {
        $this->shareManager->method('getShareById')->willReturn($this->share());

        $deleted = [];
        $this->mapper->expects($this->exactly(2))->method('deleteByShareAndRule')
            ->willReturnCallback(function (int $shareId, string $ruleCode) use (&$deleted) {
                $deleted[] = [$shareId, $ruleCode];
            });

        $this->service->unacknowledge(42, ['no_password', 'sensitive_file']);

        $this->assertSame([[42, 'no_password'], [42, 'sensitive_file']], $deleted);
    }

    public function testUnacknowledgeRecordsAnAuditLogEntry(): void {
        $this->shareManager->method('getShareById')->willReturn($this->share());

        $this->auditLogger->expects($this->once())->method('logAcknowledge')
            ->with(42, ['no_password'], true);

        $this->service->unacknowledge(42, ['no_password']);
    }

    public function testUnacknowledgeInvalidatesTheAnalyzerCache(): void {
        $this->shareManager->method('getShareById')->willReturn($this->share('alice', 'bob'));
        $this->analyzer->expects($this->once())->method('invalidate')->with('alice', 'bob');

        $this->service->unacknowledge(42, ['no_password']);
    }

    public function testUnacknowledgeRejectsAnEmptyRuleCodeList(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->unacknowledge(42, []);
    }
}
