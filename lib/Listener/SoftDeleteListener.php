<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\Listener;

use OCA\ShareAuditDashboard\Service\SoftDeleteService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Share\Events\BeforeShareDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<Event>
 *
 * Fires for EVERY share deletion instance-wide — this app's own revoke
 * actions (they all go through IShareManager::deleteShare(), which is what
 * dispatches this event) AND native Nextcloud unshares (Files app, other
 * apps, occ). That's the whole point of hooking this event rather than each
 * of this app's own services: a soft-delete safety net that isn't limited to
 * revocations made through this dashboard. See ROADMAP.md #1 and
 * ShareDeletionService's docblock for the one path that bypasses this event
 * (deleteDirect()) and needs its own explicit capture call instead.
 */
class SoftDeleteListener implements IEventListener {

    public function __construct(
        private SoftDeleteService $softDelete,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if (!$event instanceof BeforeShareDeletedEvent) {
            return;
        }
        try {
            $this->softDelete->captureShare($event->getShare());
        } catch (\Throwable $e) {
            // Never let a capture failure block the real deletion the user
            // asked for — the recycle bin is a safety net, not a gate.
            $this->logger->error('Soft-delete capture failed, the share will still be deleted: {exception}', [
                'exception' => $e,
            ]);
        }
    }
}
