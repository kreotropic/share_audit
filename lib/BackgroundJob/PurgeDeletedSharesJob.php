<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ShareAuditDashboard\BackgroundJob;

use OCA\ShareAuditDashboard\Service\SoftDeleteService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily sweep that permanently removes recycle-bin entries (see
 * SoftDeleteService) past their retention window. Registered in info.xml
 * under <background-jobs> — Nextcloud's cron.php run schedules it.
 */
class PurgeDeletedSharesJob extends TimedJob {

    public function __construct(
        ITimeFactory $time,
        private SoftDeleteService $softDelete,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
        $this->setInterval(86400);
    }

    protected function run(mixed $argument): void {
        $count = $this->softDelete->purgeExpired();
        if ($count > 0) {
            $this->logger->info('Permanently purged {count} expired recycle-bin share(s).', ['count' => $count]);
        }
    }
}
