<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Service;

use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUserSession;
use OCP\Log\Audit\CriticalActionPerformedEvent;

/**
 * Records share revocations and ownership transfers to Nextcloud's audit log
 * (admin_audit app, if enabled) so "who revoked what" survives even for bulk
 * actions — a "Revoke all access" on a large group is otherwise irreversible
 * *and* silent. Not a replacement for the soft-delete recycle bin, just the
 * minimal safety net that outlives it.
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

        [$ids, $types, $owners] = $this->summarize($rows);

        // %s placeholders are filled in the order of $parameters' keys — see
        // admin_audit's Action::log(), which this event is routed through.
        $this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent(
            'Share Audit Dashboard: "%s" revoked %s share(s) (ids: %s; types: %s; original owner(s): %s)',
            [
                'actor' => $this->actor(),
                'count' => (string)count($rows),
                'ids' => $ids,
                'types' => $types,
                'owners' => $owners,
            ],
        ));
    }

    /**
     * Records orphan shares handed to another owner, so "who took over whose
     * shares" is on the record: nothing else does, and the previous owner's
     * account is gone or disabled by then.
     *
     * @param array<int, array{id: int|string, share_type: int|string, uid_owner?: string}> $rows
     */
    public function logTransfer(array $rows, string $newOwner): void {
        if ($rows === []) {
            return;
        }

        [$ids, $types, $owners] = $this->summarize($rows);

        $this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent(
            'Share Audit Dashboard: "%s" transferred %s share(s) to "%s" (ids: %s; types: %s; original owner(s): %s)',
            [
                'actor' => $this->actor(),
                'count' => (string)count($rows),
                'newOwner' => $newOwner,
                'ids' => $ids,
                'types' => $types,
                'owners' => $owners,
            ],
        ));
    }

    private function actor(): string {
        return $this->userSession->getUser()?->getUID() ?? 'unknown';
    }

    /**
     * @param array<int, array{id: int|string, share_type: int|string, uid_owner?: string}> $rows
     * @return array{0: string, 1: string, 2: string} comma-separated ids, types and owners
     */
    private function summarize(array $rows): array {
        return [
            implode(',', array_map(static fn (array $r) => (string)$r['id'], $rows)),
            implode(',', array_unique(array_map(static fn (array $r) => (string)$r['share_type'], $rows))),
            implode(',', array_unique(array_map(
                static fn (array $r) => (string)($r['uid_owner'] ?? '?'),
                $rows,
            ))),
        ];
    }
}
