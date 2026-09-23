<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Tests\Unit;

use OCA\ShareAuditDashboard\Service\ShareProviderResolver;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

/**
 * The single source of truth for "<provider>:<id>" strings — several
 * services (ShareRemediationService, AckService, OrphanTransferService)
 * assume "ocinternal" directly instead of resolving it, which only stays
 * correct as long as it agrees with this map.
 */
class ShareProviderResolverTest extends TestCase {

    public function testUserGroupAndLinkSharesUseTheDefaultProvider(): void {
        $resolver = new ShareProviderResolver();
        $this->assertSame(ShareProviderResolver::OCINTERNAL, $resolver->providerFor(IShare::TYPE_USER));
        $this->assertSame(ShareProviderResolver::OCINTERNAL, $resolver->providerFor(IShare::TYPE_GROUP));
        $this->assertSame(ShareProviderResolver::OCINTERNAL, $resolver->providerFor(IShare::TYPE_LINK));
    }

    public function testEachNonDefaultShareTypeResolvesToItsOwnProvider(): void {
        $resolver = new ShareProviderResolver();
        $this->assertSame('ocMailShare', $resolver->providerFor(IShare::TYPE_EMAIL));
        $this->assertSame('ocFederatedSharing', $resolver->providerFor(IShare::TYPE_REMOTE));
        $this->assertSame('ocFederatedSharing', $resolver->providerFor(IShare::TYPE_REMOTE_GROUP));
        $this->assertSame('ocRoomShare', $resolver->providerFor(IShare::TYPE_ROOM));
        $this->assertSame('ocCircleShare', $resolver->providerFor(IShare::TYPE_CIRCLE));
    }

    public function testAnUnrecognizedShareTypeFallsBackToTheDefaultProvider(): void {
        $resolver = new ShareProviderResolver();
        $this->assertSame(ShareProviderResolver::OCINTERNAL, $resolver->providerFor(9999));
    }

    public function testShareIdConcatenatesTheResolvedProviderAndId(): void {
        $resolver = new ShareProviderResolver();
        $this->assertSame('ocinternal:42', $resolver->shareId(IShare::TYPE_LINK, 42));
        $this->assertSame('ocRoomShare:7', $resolver->shareId(IShare::TYPE_ROOM, 7));
    }
}
