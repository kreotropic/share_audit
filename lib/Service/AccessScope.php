<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Service;

/**
 * What a logged-in account is allowed to see on the read-only endpoints
 * (AdminController::requireViewer()). Immutable, built only by AccessService.
 *
 * `owners`:
 *  - null  => every owner on the instance (admin, auditor)
 *  - array => only shares whose uid_owner is in this list (manager)
 *  - []    => no owner qualifies (a manager with zero direct reports) — the
 *             caller must still deny access explicitly; nothing here does an
 *             "empty means unfiltered" collapse (see ShareMapper::applyOwnerScope()).
 */
final class AccessScope {

    public const ROLE_ADMIN = 'admin';
    public const ROLE_AUDITOR = 'auditor';
    public const ROLE_MANAGER = 'manager';

    /**
     * @param string[]|null $owners
     */
    private function __construct(
        public readonly string $role,
        public readonly ?array $owners,
    ) {
    }

    public static function admin(): self {
        return new self(self::ROLE_ADMIN, null);
    }

    public static function auditor(): self {
        return new self(self::ROLE_AUDITOR, null);
    }

    /**
     * @param string[] $owners direct reports' uids (may be empty)
     */
    public static function manager(array $owners): self {
        return new self(self::ROLE_MANAGER, $owners);
    }

    public function isGlobal(): bool {
        return $this->owners === null;
    }

    /**
     * Public-link tokens are bare credentials. Only an admin — who can
     * already act on every share — is handed them back.
     */
    public function canSeeTokens(): bool {
        return $this->role === self::ROLE_ADMIN;
    }

    public function canManage(): bool {
        return $this->role === self::ROLE_ADMIN;
    }
}
