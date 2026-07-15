<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Service;

use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUserSession;
use OCP\Log\Audit\CriticalActionPerformedEvent;

/**
 * Records share revocations to Nextcloud's audit log (admin_audit app, if
 * enabled) so "who revoked what" survives even for bulk actions — a "Revoke
 * all access" on a large group is otherwise irreversible *and* silent. Not a
 * replacement for the soft-delete roadmap item, just the minimal safety net
 * until that exists.
 */
class ShareAuditLogger {

    public function __construct(
        private IEventDispatcher $eventDispatcher,
        private IUserSession $userSession,
    ) {
    }

    /**
     * @param array<int, array{id: int|string, share_type: int|string, uid_owner?: string}> $rows
     */
    public function logRevoke(array $rows): void {
        if ($rows === []) {
            return;
        }

        $actor = $this->userSession->getUser()?->getUID() ?? 'unknown';
        $ids = implode(',', array_map(static fn (array $r) => (string)$r['id'], $rows));
        $types = implode(',', array_unique(array_map(static fn (array $r) => (string)$r['share_type'], $rows)));
        $owners = implode(',', array_unique(array_map(
            static fn (array $r) => (string)($r['uid_owner'] ?? '?'),
            $rows,
        )));

        // %s placeholders are filled in the order of $parameters' keys — see
        // admin_audit's Action::log(), which this event is routed through.
        $this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent(
            'Share Audit Dashboard: "%s" revoked %s share(s) (ids: %s; types: %s; original owner(s): %s)',
            [
                'actor' => $actor,
                'count' => (string)count($rows),
                'ids' => $ids,
                'types' => $types,
                'owners' => $owners,
            ],
        ));
    }
}
