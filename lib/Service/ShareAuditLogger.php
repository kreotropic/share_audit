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

    /**
     * Records a request to move a disabled account's files to another account.
     * Files leaving one person's storage for another's is a data-governance
     * step with no undo in this app, so the request belongs on the record
     * with who made it, before anything moves.
     *
     * @param string|null $path null for the whole account
     */
    public function logFileMoveQueued(string $from, string $to, ?string $path, int $shares): void {
        $this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent(
            'Share Audit Dashboard: "%s" queued moving %s of "%s" to "%s" (%s share(s) covered)',
            [
                'actor' => $this->actor(),
                'what' => $path === null ? 'all files' : 'the files at "' . $path . '"',
                'from' => $from,
                'to' => $to,
                'shares' => (string)$shares,
            ],
        ));
    }

    /**
     * Records how a queued file move ended. Runs in a background job, where
     * there is no logged-in user, so who asked for it is passed in.
     *
     * @param string|null $path null for the whole account
     * @param string|null $error null when the move succeeded
     */
    public function logFileMoveFinished(string $requestedBy, string $from, string $to, ?string $path, ?string $error): void {
        $what = $path === null ? 'all files' : 'the files at "' . $path . '"';
        if ($error === null) {
            $this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent(
                'Share Audit Dashboard: moved %s of "%s" to "%s", as requested by "%s"',
                ['what' => $what, 'from' => $from, 'to' => $to, 'actor' => $requestedBy],
            ));
            return;
        }
        $this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent(
            'Share Audit Dashboard: could not move %s of "%s" to "%s", as requested by "%s": %s',
            ['what' => $what, 'from' => $from, 'to' => $to, 'actor' => $requestedBy, 'error' => $error],
        ));
    }

    /**
     * Records an administrator freeing a file move that was still marked as
     * running. It lets a second move into the receiving account, so if the
     * first was in fact still writing there, the files of both are at risk —
     * who said it was gone belongs on the record.
     *
     * @param string|null $path null for the whole account
     */
    public function logFileMoveReleased(string $from, string $to, ?string $path): void {
        $this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent(
            'Share Audit Dashboard: "%s" marked as interrupted the move of %s of "%s" to "%s", which was still running',
            [
                'actor' => $this->actor(),
                'what' => $path === null ? 'all files' : 'the files at "' . $path . '"',
                'from' => $from,
                'to' => $to,
            ],
        ));
    }

    /**
     * Records a CSV export by a read-only viewer (auditor, or later a
     * manager — see AccessService): unlike an admin, who already has
     * unaudited access to everything, this is new-since-#16 visibility into
     * every user's shares handed to a non-admin account, so who took a copy
     * of it and how many rows belongs on the record. Not called for an
     * admin's own export — nothing new is being exposed there.
     */
    /**
     * Records a settings change — in particular the auditor-groups list,
     * since that decides who gets read-only access to every user's shares
     * on the instance; a change to it belongs on the record as much as a
     * revoke or transfer does. A no-op save (nothing actually differs from
     * $before) is not logged.
     *
     * @param array<string, mixed> $before SettingsService::getSettings() before the save
     * @param array<string, mixed> $after SettingsService::getSettings() after the save
     */
    public function logSettingsChanged(array $before, array $after): void {
        $changed = array_values(array_filter(
            array_keys($after),
            static fn (string $key) => ($before[$key] ?? null) !== $after[$key],
        ));
        if ($changed === []) {
            return;
        }

        $auditorGroups = $after['auditorGroups'] ?? [];
        $this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent(
            'Share Audit Dashboard: "%s" changed settings (%s); auditor groups are now: %s',
            [
                'actor' => $this->actor(),
                'changed' => implode(', ', $changed),
                'auditorGroups' => $auditorGroups === [] ? '(none)' : implode(', ', $auditorGroups),
            ],
        ));
    }

    /**
     * Records restoring a share from the recycle bin — the flip side of
     * logRevoke(): a revoke that turns out to be a mistake is undone by
     * this, and "who brought back what, for whom" belongs on the record
     * just as much as who removed it.
     */
    public function logRestore(int $newId, int $originalShareId, int $shareType, string $owner): void {
        $this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent(
            'Share Audit Dashboard: "%s" restored share %s (new id: %s; type: %s; owner: %s) from the recycle bin',
            [
                'actor' => $this->actor(),
                'originalId' => (string)$originalShareId,
                'newId' => (string)$newId,
                'type' => (string)$shareType,
                'owner' => $owner,
            ],
        ));
    }

    /**
     * Records permanently purging one or more recycle-bin entries: once
     * this runs there is no copy of the share left anywhere in the app, so
     * unlike a revoke (still recoverable from the bin) this is the actual
     * point of no return and belongs on the record.
     *
     * @param int[] $originalShareIds the purged entries' original oc_share ids
     */
    public function logPurge(array $originalShareIds): void {
        if ($originalShareIds === []) {
            return;
        }
        $this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent(
            'Share Audit Dashboard: "%s" permanently purged %s recycle-bin entry(ies) (original share ids: %s)',
            [
                'actor' => $this->actor(),
                'count' => (string)count($originalShareIds),
                'ids' => implode(',', $originalShareIds),
            ],
        ));
    }

    /**
     * Records accepting or undoing a security-alert exception: from then on
     * (or, for unacknowledge, from here on again) the alert stops surfacing
     * for that share/rule pair without the underlying risk having changed,
     * so who decided that and when belongs on the record.
     *
     * @param string[] $ruleCodes
     */
    public function logAcknowledge(int $shareId, array $ruleCodes, bool $undone, ?string $note = null): void {
        if ($ruleCodes === []) {
            return;
        }
        $params = [
            'actor' => $this->actor(),
            'shareId' => (string)$shareId,
            'ruleCodes' => implode(', ', $ruleCodes),
        ];
        if ($undone) {
            $this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent(
                'Share Audit Dashboard: "%s" undid the exception on share %s for: %s',
                $params,
            ));
            return;
        }
        $params['note'] = $note ?? '(none)';
        $this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent(
            'Share Audit Dashboard: "%s" accepted an exception on share %s for: %s (note: %s)',
            $params,
        ));
    }

    public function logExport(int $rowCount, string $role): void {
        $this->eventDispatcher->dispatchTyped(new CriticalActionPerformedEvent(
            'Share Audit Dashboard: "%s" (%s) exported %s share(s) to CSV',
            [
                'actor' => $this->actor(),
                'role' => $role,
                'count' => (string)$rowCount,
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
