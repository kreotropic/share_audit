<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Service;

use OCP\Share\IManager;
use OCP\Share\IShare;

/**
 * The instance's own expiration policy for shares (Administration settings →
 * Sharing), so the "Set expiry" actions use what the organisation chose
 * instead of a number of their own.
 *
 * Nextcloud keeps three independent policies — public links, internal shares
 * (users, groups, ...) and remote ("server") shares — each with an on/off
 * "default expiration", a number of days, and an "enforce" switch. When it is
 * enforced that number is also the longest lifetime a share may have, and
 * IShareManager rejects anything beyond it.
 */
class ExpiryDefaultsService {

    /** Lifetime used when the instance defines no default expiration. */
    public const FALLBACK_DAYS = 30;

    public function __construct(
        private IManager $shareManager,
    ) {
    }

    /**
     * Policy for public links — what the alerts list acts on.
     *
     * @return array{days: int, maxDays: ?int}
     */
    public function forLinks(): array {
        return $this->forShareType(IShare::TYPE_LINK);
    }

    /**
     * Policy that applies to a share of the given IShare::TYPE_*.
     *
     * `days` is the lifetime to offer by default: the instance's configured
     * number when a default expiration is switched on, FALLBACK_DAYS
     * otherwise (never more than `maxDays`). `maxDays` is null unless the
     * instance enforces expiration, in which case it is the longest lifetime
     * that will be accepted.
     *
     * @return array{days: int, maxDays: ?int}
     */
    public function forShareType(int $shareType): array {
        [$enabled, $enforced, $configured] = match ($shareType) {
            // E-mail shares go through the same expiration rules as links.
            IShare::TYPE_LINK, IShare::TYPE_EMAIL => [
                $this->shareManager->shareApiLinkDefaultExpireDate(),
                $this->shareManager->shareApiLinkDefaultExpireDateEnforced(),
                $this->shareManager->shareApiLinkDefaultExpireDays(),
            ],
            IShare::TYPE_REMOTE, IShare::TYPE_REMOTE_GROUP => [
                $this->shareManager->shareApiRemoteDefaultExpireDate(),
                $this->shareManager->shareApiRemoteDefaultExpireDateEnforced(),
                $this->shareManager->shareApiRemoteDefaultExpireDays(),
            ],
            default => [
                $this->shareManager->shareApiInternalDefaultExpireDate(),
                $this->shareManager->shareApiInternalDefaultExpireDateEnforced(),
                $this->shareManager->shareApiInternalDefaultExpireDays(),
            ],
        };

        $configured = max(1, $configured);
        $maxDays = $enforced ? $configured : null;
        $days = $enabled ? $configured : self::FALLBACK_DAYS;

        return ['days' => $maxDays !== null ? min($days, $maxDays) : $days, 'maxDays' => $maxDays];
    }
}
