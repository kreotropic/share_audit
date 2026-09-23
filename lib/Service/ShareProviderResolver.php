<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Service;

use OCP\Share\IShare;

/**
 * Single source of truth for which IShareManager provider id serves a given
 * raw share_type, matching OC\Share20\ProviderFactory — used everywhere this
 * app builds a "<provider>:<id>" string for IShareManager::getShareById().
 *
 * Several services in this app instead hardcode the "ocinternal" provider
 * directly (ShareRemediationService, AckService, OrphanTransferService).
 * That happens to be correct today: every share type those services act on
 * (link, user, group) is served by ocinternal, per the map below — but
 * nothing enforced that assumption stays true, so it is spelled out as
 * self::OCINTERNAL here instead of a bare string repeated in four places,
 * and any of them can switch to providerFor()/shareId() the moment they
 * need to act on a share type that is not.
 */
class ShareProviderResolver {

    public const OCINTERNAL = 'ocinternal';

    /** Provider id per raw share_type, matching OC\Share20\ProviderFactory. */
    private const PROVIDER_BY_TYPE = [
        IShare::TYPE_USER => self::OCINTERNAL,
        IShare::TYPE_GROUP => self::OCINTERNAL,
        IShare::TYPE_LINK => self::OCINTERNAL,
        IShare::TYPE_EMAIL => 'ocMailShare',
        IShare::TYPE_REMOTE => 'ocFederatedSharing',
        IShare::TYPE_REMOTE_GROUP => 'ocFederatedSharing',
        IShare::TYPE_ROOM => 'ocRoomShare',
        IShare::TYPE_CIRCLE => 'ocCircleShare',
    ];

    /**
     * An unrecognized share_type (one added in a future Nextcloud version)
     * falls back to ocinternal, the default provider — matching
     * ShareDeletionService's pre-existing fallback for the same case.
     */
    public function providerFor(int $shareType): string {
        return self::PROVIDER_BY_TYPE[$shareType] ?? self::OCINTERNAL;
    }

    public function shareId(int $shareType, int|string $id): string {
        return $this->providerFor($shareType) . ':' . $id;
    }
}
