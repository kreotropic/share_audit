<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Service;

use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;

/**
 * Single place that decides what the logged-in account may see on the
 * Share Audit Dashboard: full admin, read-only auditor (member of one of the
 * groups the admin picked in Settings), or nothing.
 *
 * The manager role (a person seeing only their direct reports' shares) is a
 * second increment on top of this — see ROADMAP.md and the plan for issue
 * #16 — and is deliberately not implemented here yet: getScope() returns
 * null for a non-admin, non-auditor account regardless of the manager
 * relationship, and AdminController::requireViewer() denies accordingly.
 */
class AccessService {

    public function __construct(
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private SettingsService $settings,
    ) {
    }

    public function isAdmin(): bool {
        $user = $this->userSession->getUser();
        return $user !== null && $this->groupManager->isAdmin($user->getUID());
    }

    /**
     * @return AccessScope|null null when the account has no standing to use
     *         any part of this app (not logged in, not an admin, not in an
     *         auditor group).
     */
    public function getScope(): ?AccessScope {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return null;
        }
        if ($this->groupManager->isAdmin($user->getUID())) {
            return AccessScope::admin();
        }
        if ($this->isInAuditorGroup($user)) {
            return AccessScope::auditor();
        }
        return null;
    }

    private function isInAuditorGroup(IUser $user): bool {
        foreach ($this->settings->getAuditorGroups() as $groupId) {
            if ($this->groupManager->isInGroup($user->getUID(), $groupId)) {
                return true;
            }
        }
        return false;
    }
}
